<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Enums\RecallExamKey;
use App\Domains\Recall\Models\RecallExamMapping;
use App\Models\{ExamType, User};
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * O que conta como cada exame canônico dos protocolos (campimetria, OCT de mácula...) NESTA clínica: quais dos
 * tipos de exame dela (e do catálogo global) o recall lê como "feito". Decisão da clínica — o sistema só sugere
 * (`exam_types.canonical_key`, preenchido nos exames globais inequívocos).
 *
 * Os tipos aceitos são os ATIVOS da própria clínica ou globais: tipo de outra clínica nunca entra. Salvar troca o
 * conjunto inteiro numa transação (o que saiu é removido, o que entrou é criado); cada mudança marca a clínica
 * inteira para reavaliação (RecallServiceProvider).
 */
class ExamMappingService
{
    /**
     * O mapeamento atual: chave canônica → ids dos tipos de exame.
     *
     * @return array<string, list<string>>
     */
    public function current(string $entityId): array
    {
        $map = array_fill_keys(RecallExamKey::values(), []);

        foreach (RecallExamMapping::query()->withoutGlobalScopes()->where('entity_id', $entityId)->orderBy('created_at')->get(['exam_key', 'exam_type_id']) as $row) {
            $map[(string) $row->exam_key][] = (string) $row->exam_type_id;
        }

        return $map;
    }

    /**
     * Os tipos de exame que a clínica pode mapear (ativos e não excluídos, dela ou globais). `withoutGlobalScopes`
     * tira também o filtro de excluídos do model: por isso `deleted_at` é conferido aqui, explicitamente.
     *
     * @return list<array{id: string, name: string, global: bool}>
     */
    public function availableTypes(string $entityId): array
    {
        return ExamType::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'entity_id'])
            ->map(static fn (ExamType $type): array => ['id' => (string) $type->id, 'name' => (string) $type->name, 'global' => $type->entity_id === null])
            ->all();
    }

    /**
     * Tipos já mapeados que SAÍRAM do catálogo (desativados ou excluídos depois do mapeamento). A tela os mostra, com
     * o nome, para a clínica poder tirá-los: sem isto apareceria o id e o salvamento falharia por um item que ela nem
     * enxerga. Continuam valendo no mapeamento até ela tirar (o exame já feito nesse tipo segue contando).
     *
     * @return list<array{id: string, name: string, global: bool}>
     */
    public function retiredMapped(string $entityId): array
    {
        $mapped  = RecallExamMapping::query()->withoutGlobalScopes()->where('entity_id', $entityId)->pluck('exam_type_id')->map(static fn ($id): string => (string) $id)->unique()->all();
        $retired = array_values(array_diff($mapped, array_column($this->availableTypes($entityId), 'id')));

        if ($retired === []) {
            return [];
        }

        return ExamType::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $retired)
            ->where(fn ($query) => $query->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'entity_id'])
            ->map(static fn (ExamType $type): array => ['id' => (string) $type->id, 'name' => (string) $type->name, 'global' => $type->entity_id === null])
            ->all();
    }

    /**
     * O que o sistema SUGERE por chave canônica (só os exames globais marcados): a clínica adiciona com um clique.
     *
     * @return array<string, list<string>>
     */
    public function suggestions(string $entityId): array
    {
        $map = array_fill_keys(RecallExamKey::values(), []);

        foreach (ExamType::query()->withoutGlobalScopes()->whereNull('deleted_at')->whereNull('entity_id')->where('active', true)->whereNotNull('canonical_key')->orderBy('name')->get(['id', 'canonical_key']) as $type) {
            if (isset($map[(string) $type->canonical_key])) {
                $map[(string) $type->canonical_key][] = (string) $type->id;
            }
        }

        return $map;
    }

    /**
     * Troca o mapeamento da clínica pelo conjunto informado (chave canônica → ids). Chave fora da lista fechada ou
     * tipo de exame que a clínica não pode usar é recusado (nada é gravado).
     *
     * @param array<string, mixed> $mappings
     *
     * @throws InvalidArgumentException
     */
    public function sync(string $entityId, array $mappings, ?User $actor = null): void
    {
        // Pode escolher o que está no catálogo agora; o que já estava mapeado e saiu do catálogo pode ficar (ou ser tirado).
        $allowed = [...array_column($this->availableTypes($entityId), 'id'), ...array_column($this->retiredMapped($entityId), 'id')];
        $wanted  = [];

        foreach ($mappings as $key => $ids) {
            if (RecallExamKey::tryFrom((string) $key) === null) {
                throw new InvalidArgumentException("Exame canônico desconhecido: {$key}.");
            }

            foreach ((array) $ids as $id) {
                if (! in_array((string) $id, $allowed, true)) {
                    throw new InvalidArgumentException("Tipo de exame indisponível para esta clínica: {$id}.");
                }

                $wanted[(string) $key][(string) $id] = true;
            }
        }

        DB::transaction(function () use ($entityId, $wanted, $actor): void {
            $existing = RecallExamMapping::query()->withoutGlobalScopes()->where('entity_id', $entityId)->lockForUpdate()->get();

            foreach ($existing as $row) {
                if (! isset($wanted[(string) $row->exam_key][(string) $row->exam_type_id])) {
                    $row->delete();
                }
            }

            $kept = $existing->filter(static fn (RecallExamMapping $row): bool => isset($wanted[(string) $row->exam_key][(string) $row->exam_type_id]))
                ->mapToGroups(static fn (RecallExamMapping $row): array => [(string) $row->exam_key => (string) $row->exam_type_id]);

            foreach ($wanted as $key => $ids) {
                foreach (array_keys($ids) as $id) {
                    if (in_array($id, $kept->get($key, collect())->all(), true)) {
                        continue;
                    }

                    RecallExamMapping::query()->withoutGlobalScopes()->create([
                        'entity_id'    => $entityId,
                        'exam_key'     => $key,
                        'exam_type_id' => $id,
                        'created_by'   => $actor?->getKey(),
                    ]);
                }
            }
        });
    }
}
