<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\{Covenant, ExamKit, ExamKitItem, Procedure};
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kits de exames por convênio (Fase 2 / B2).
 *
 * Regras num lugar só (Configurações › Kits de exames, manager e o pedido de
 * exames do prontuário):
 *  - kit GLOBAL (entity_id nulo): padrão do EasyEye, toda clínica vê; some da
 *    lista da clínica que já tem uma cópia dele (a cópia é a versão dela);
 *  - kit da CLÍNICA: só ela vê; convênios vinculados (nenhum = todos) e só
 *    convênios ATIVOS entram em vínculo novo;
 *  - item = código TUSS + nome; no pedido é resolvido no catálogo de
 *    procedimentos da clínica (dela ou global, ativo) pelo `procedure_id` do
 *    item ou pelo código. Sem correspondência, sai como item de texto e a
 *    tela avisa.
 */
class ExamKitService
{
    /** Exames por kit (o maior kit padrão tem 5; folga para os da clínica). */
    public const MAX_ITEMS = 20;

    public function __construct(private readonly TenantContext $tenant)
    {
    }

    /**
     * Kits que o pedido de exames oferece para o convênio do atendimento:
     * os da clínica (ativos, sem convênio ou com este convênio) + os globais
     * ativos que a clínica não copiou. Itens já resolvidos no catálogo.
     *
     * @return list<array<string, mixed>>
     */
    public function forRequest(string $entityId, ?string $covenantId): array
    {
        $clinic = ExamKit::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->ownedBy($entityId)
            ->active()
            ->where(fn ($q) => $q->whereDoesntHave('covenants')
                ->when($covenantId, fn ($q2) => $q2->orWhereHas('covenants', fn ($c) => $c->where('covenants.id', $covenantId))))
            ->with('items', 'covenants:id,name')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $copied = ExamKit::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->ownedBy($entityId)
            ->whereNotNull('source_kit_id')
            ->pluck('source_kit_id')
            ->all();

        $global = ExamKit::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->global()
            ->active()
            ->whereNotIn('id', $copied)
            ->with('items')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $catalog = $this->catalog($entityId, [...$clinic, ...$global]);

        return [
            ...$clinic->map(fn (ExamKit $kit) => $this->serializeForRequest($kit, 'clinic', $catalog, $entityId)),
            ...$global->map(fn (ExamKit $kit) => $this->serializeForRequest($kit, 'global', $catalog, $entityId)),
        ];
    }

    /**
     * Item do kit resolvido no catálogo da clínica.
     *
     * @param array{by_id: Collection<string, Procedure>, by_code: Collection<string, Procedure>} $catalog
     *
     * @return array{procedure_id: ?string, code: ?string, name: string, in_catalog: bool}
     */
    public function resolveItem(ExamKitItem $item, array $catalog, string $entityId): array
    {
        $procedure = null;

        // procedure_id só vale em kit DESTA clínica (nunca de outra).
        if ($item->procedure_id !== null && $item->kit?->entity_id === $entityId) {
            $procedure = $catalog['by_id']->get((string) $item->procedure_id);
        }

        $code = $item->tuss_code !== null ? trim($item->tuss_code) : null;
        $procedure ??= $code !== null && $code !== '' ? $catalog['by_code']->get($code) : null;

        return [
            'procedure_id' => $procedure ? (string) $procedure->id : null,
            'code'         => $procedure?->code ?: ($code !== '' ? $code : null),
            'name'         => $procedure?->name ?: $item->name,
            'in_catalog'   => $procedure !== null,
        ];
    }

    /**
     * Catálogo de procedimentos ATIVOS da clínica (dela + globais) indexado
     * por id e por código — a clínica vem antes do global no mesmo código.
     *
     * @param iterable<ExamKit> $kits
     *
     * @return array{by_id: Collection<string, Procedure>, by_code: Collection<string, Procedure>}
     */
    public function catalog(string $entityId, iterable $kits): array
    {
        $codes = [];
        $ids   = [];

        foreach ($kits as $kit) {
            foreach ($kit->items as $item) {
                if (filled($item->tuss_code)) {
                    $codes[] = trim((string) $item->tuss_code);
                }

                if ($item->procedure_id !== null) {
                    $ids[] = (string) $item->procedure_id;
                }
            }
        }

        if ($codes === [] && $ids === []) {
            return ['by_id' => collect(), 'by_code' => collect()];
        }

        $procedures = Procedure::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('active', true)
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where(fn ($q) => $q->whereIn('code', array_values(array_unique($codes)))->orWhereIn('id', array_values(array_unique($ids))))
            ->orderByRaw('CASE WHEN entity_id IS NULL THEN 1 ELSE 0 END')
            ->get(['id', 'entity_id', 'code', 'name']);

        return [
            'by_id'   => $procedures->keyBy(fn (Procedure $p) => (string) $p->id),
            'by_code' => $procedures->filter(fn (Procedure $p) => filled($p->code))
                ->unique('code')
                ->keyBy(fn (Procedure $p) => trim((string) $p->code)),
        ];
    }

    // ── Telas de cadastro (clínica e manager) ──────────────────────────────

    /**
     * Kits da clínica (ativos e inativos) para Configurações › Kits.
     *
     * @return list<array<string, mixed>>
     */
    public function clinicKits(string $entityId): array
    {
        return ExamKit::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->ownedBy($entityId)
            ->with(['items.procedure:id,name,code', 'covenants:id,name,active'])
            ->orderBy('name')
            ->get()
            ->map(fn (ExamKit $kit) => $this->serializeForSettings($kit))
            ->values()
            ->all();
    }

    /**
     * Kits padrão do EasyEye. Para a clínica (`$entityId`), só os ativos e
     * com a marca "já copiado"; para o manager (null), todos.
     *
     * @return list<array<string, mixed>>
     */
    public function globalKits(?string $entityId = null): array
    {
        $copied = $entityId === null ? [] : ExamKit::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->ownedBy($entityId)
            ->whereNotNull('source_kit_id')
            ->pluck('source_kit_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        return ExamKit::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->global()
            ->when($entityId !== null, fn ($q) => $q->active())
            ->with('items')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ExamKit $kit) => [
                ...$this->serializeForSettings($kit),
                'copied' => in_array((string) $kit->id, $copied, true),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function serializeForSettings(ExamKit $kit): array
    {
        return [
            'id'            => (string) $kit->id,
            'name'          => $kit->name,
            'description'   => $kit->description,
            'active'        => (bool) $kit->active,
            'origin'        => $kit->isGlobal() ? 'global' : 'clinic',
            'source_kit_id' => $kit->source_kit_id ? (string) $kit->source_kit_id : null,
            'items'         => $kit->items->map(fn (ExamKitItem $item) => [
                'id'             => (string) $item->id,
                'name'           => $item->name,
                'tuss_code'      => $item->tuss_code,
                'procedure_id'   => $item->procedure_id ? (string) $item->procedure_id : null,
                'procedure_name' => $item->relationLoaded('procedure') ? $item->procedure?->name : null,
            ])->values()->all(),
            'covenants' => $kit->isGlobal() ? [] : $kit->covenants->map(fn (Covenant $c) => [
                'id'     => (string) $c->id,
                'name'   => $c->name,
                'active' => (bool) $c->active,
            ])->values()->all(),
        ];
    }

    /** Nome único entre os kits da clínica (ou entre os globais). */
    public function assertUniqueName(?string $entityId, string $name, ?string $exceptId = null): void
    {
        $exists = ExamKit::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->when($entityId === null, fn ($q) => $q->whereNull('entity_id'), fn ($q) => $q->where('entity_id', $entityId))
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => __('clinical_documents.kits.duplicate')]);
        }
    }

    // ── Escrita (clínica e manager) ─────────────────────────────────────────

    /**
     * Cria/atualiza um kit (da clínica quando $entityId; global quando null).
     *
     * @param array<string, mixed> $data name, description, active, items[], covenant_ids[]
     */
    public function save(?ExamKit $kit, ?string $entityId, array $data): ExamKit
    {
        $this->assertUniqueName($entityId, (string) $data['name'], $kit?->exists ? (string) $kit->id : null);

        $items       = $this->normalizeItems($entityId, $data['items'] ?? []);
        $covenantIds = $entityId !== null ? $this->validCovenants($entityId, $data['covenant_ids'] ?? [], $kit) : [];

        return $this->tenant->withoutScope(fn () => DB::transaction(function () use ($kit, $entityId, $data, $items, $covenantIds) {
            $kit ??= new ExamKit();
            $kit->entity_id = $entityId;
            $kit->fill([
                'name'        => trim((string) $data['name']),
                'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
                'active'      => (bool) ($data['active'] ?? true),
            ]);
            $kit->save();

            $kit->items()->delete();

            foreach ($items as $index => $item) {
                $kit->items()->create([...$item, 'sort_order' => $index]);
            }

            if ($entityId !== null) {
                $kit->covenants()->sync(collect($covenantIds)->mapWithKeys(fn ($id) => [$id => ['created_at' => now()]])->all());
            }

            return $kit->load(['items.procedure:id,name,code', 'covenants:id,name,active']);
        }));
    }

    /** Copia um kit global para a clínica (cópia sem convênio = todos). */
    public function copyToEntity(ExamKit $global, string $entityId): ExamKit
    {
        abort_unless($global->isGlobal(), 404);

        $name = $global->name;

        if (ExamKit::query()->withoutGlobalScopes()->whereNull('deleted_at')->ownedBy($entityId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            $name = mb_substr(__('clinical_documents.kits.copy_name', ['name' => $name]), 0, 120);
        }

        $kit = $this->save(null, $entityId, [
            'name'        => $name,
            'description' => $global->description,
            'active'      => true,
            'items'       => $global->items->map(fn (ExamKitItem $i) => ['name' => $i->name, 'tuss_code' => $i->tuss_code])->all(),
        ]);

        $this->tenant->withoutScope(fn () => $kit->forceFill(['source_kit_id' => $global->id])->save());

        return $kit;
    }

    /**
     * Itens validados: nome obrigatório; código TUSS opcional; procedure_id
     * só do catálogo ativo DESTA clínica (ou global) — de outra clínica, 422.
     *
     * Vínculo coerente com o código (2ª revisão, P2-D): o vínculo só fica se
     * o código do item for o do procedimento do catálogo (código em branco →
     * recebe o do catálogo). Código diferente = o médico trocou o exame: o
     * vínculo antigo é descartado e o item vale pelo código digitado (a
     * resolução no catálogo da clínica é por código — resolveItem). O nome do
     * catálogo continua valendo para item vinculado ou resolvido por código.
     *
     * @param array<int, mixed> $items
     *
     * @return list<array{name: string, tuss_code: ?string, procedure_id: ?string}>
     */
    private function normalizeItems(?string $entityId, array $items): array
    {
        $out = [];

        foreach (array_values($items) as $index => $item) {
            $procedureId = $entityId !== null && filled($item['procedure_id'] ?? null) ? (string) $item['procedure_id'] : null;
            $code        = filled($item['tuss_code'] ?? null) ? trim((string) $item['tuss_code']) : null;

            if ($procedureId !== null) {
                $procedure = Procedure::query()->withoutGlobalScopes()->whereNull('deleted_at')
                    ->whereKey($procedureId)
                    ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
                    ->first(['id', 'code']);

                if ($procedure === null) {
                    throw ValidationException::withMessages(["items.{$index}.procedure_id" => __('clinical_documents.kits.procedure_invalid')]);
                }

                $catalogCode = filled($procedure->code) ? trim((string) $procedure->code) : null;

                if ($code === null) {
                    $code = $catalogCode;
                } elseif ($catalogCode === null || strcasecmp($code, $catalogCode) !== 0) {
                    $procedureId = null;
                }
            }

            $out[] = [
                'name'         => trim((string) ($item['name'] ?? '')),
                'tuss_code'    => $code,
                'procedure_id' => $procedureId,
            ];
        }

        return $out;
    }

    /**
     * Convênios do vínculo: da clínica ou globais, não excluídos; convênio
     * INATIVO só passa se o kit já o tinha (editar outro campo não trava).
     *
     * @param array<int, mixed> $ids
     *
     * @return list<string>
     */
    private function validCovenants(string $entityId, array $ids, ?ExamKit $kit): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));

        if ($ids === []) {
            return [];
        }

        $current = $kit?->exists ? $kit->covenants()->pluck('covenants.id')->map(fn ($id) => (string) $id)->all() : [];

        $found = Covenant::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->get(['id', 'active']);

        foreach ($ids as $index => $id) {
            $covenant = $found->firstWhere('id', $id);

            if ($covenant === null) {
                throw ValidationException::withMessages(["covenant_ids.{$index}" => __('clinical_documents.kits.covenant_invalid')]);
            }

            if (! $covenant->active && ! in_array($id, $current, true)) {
                throw ValidationException::withMessages(["covenant_ids.{$index}" => __('validation.custom.covenant.inactive')]);
            }
        }

        return $ids;
    }

    /**
     * @param array{by_id: Collection<string, Procedure>, by_code: Collection<string, Procedure>} $catalog
     *
     * @return array<string, mixed>
     */
    private function serializeForRequest(ExamKit $kit, string $origin, array $catalog, string $entityId): array
    {
        return [
            'id'          => (string) $kit->id,
            'name'        => $kit->name,
            'description' => $kit->description,
            'origin'      => $origin,
            'covenants'   => $origin === 'clinic' ? $kit->covenants->pluck('name')->values()->all() : [],
            'items'       => $kit->items->map(fn (ExamKitItem $item) => $this->resolveItem($item->setRelation('kit', $kit), $catalog, $entityId))->values()->all(),
        ];
    }
}
