<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Models\RecallClinicProtocol;
use App\Domains\Recall\Support\ProtocolContentSchema;
use App\Support\ClinicalSafety\TermNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Os pacientes de uma clínica que PODEM ter retorno a calcular ou a encerrar: quem tem condição aberta
 * (sugerida/ativa), quem já tem linha de retorno (para atualizar ou encerrar) e quem teve atendimento recente
 * com retorno pedido pelo médico. É o conjunto da conciliação noturna e da reavaliação da clínica inteira
 * (mudou adoção, mapeamento ou configuração) — nunca a tabela de pacientes inteira.
 *
 * Paciente removido entra se tiver linha (a avaliação a encerra); o atendimento recente só vale para quem
 * continua cadastrado.
 */
final class RecallCandidates
{
    /** Linhas encerradas há mais que isto não voltam a ser reavaliadas pela conciliação (o evento as reabre). */
    public const CLOSED_ROW_DAYS = 90;

    /** Atendimento mais antigo que isto não gera retorno pedido pelo médico novo (pedido + perdido de vista cabem). */
    public const DOCTOR_ORDER_HORIZON_DAYS = 600;

    /**
     * Query de `patients as p` com a coluna `p.id` — quem chama ordena, pagina e seleciona.
     *
     * `$tracked` (de `tracked()`) acrescenta quem tem, no prontuário ou na medicação, algo que os protocolos
     * ADOTADOS reconhecem — é como o paciente que ainda não tem condição entra na fila quando a clínica ativa um
     * protocolo. Quem chama várias vezes (a conciliação, em blocos) calcula uma vez e repassa.
     *
     * @param array{cid: list<string>, meds: list<string>}|null $tracked
     */
    public static function patients(string $entityId, ?CarbonImmutable $today = null, ?array $tracked = null): Builder
    {
        $today ??= CarbonImmutable::now();
        $tracked ??= self::tracked($entityId);

        return DB::table('patients as p')
            ->where('p.entity_id', $entityId)
            ->where(static function (Builder $query) use ($entityId, $today, $tracked): void {
                $query
                    ->whereExists(static function (Builder $sub) use ($entityId): void {
                        $sub->from('patient_conditions as c')
                            ->whereColumn('c.patient_id', 'p.id')
                            ->where('c.entity_id', $entityId)
                            ->whereIn('c.status', ['suggested', 'active']);
                    })
                    ->orWhereExists(static function (Builder $sub) use ($entityId): void {
                        // Tratamento com injeções em andamento: a próxima aplicação é retorno a calcular.
                        $sub->from('intravitreal_courses as ic')
                            ->whereColumn('ic.patient_id', 'p.id')
                            ->where('ic.entity_id', $entityId)
                            ->whereNull('ic.deleted_at')
                            ->whereIn('ic.status', ['active', 'paused']);
                    })
                    ->orWhereExists(static function (Builder $sub) use ($entityId, $today): void {
                        $sub->from('patient_recalls as r')
                            ->whereColumn('r.patient_id', 'p.id')
                            ->where('r.entity_id', $entityId)
                            ->where(static function (Builder $open) use ($today): void {
                                $open->where('r.lifecycle', '!=', 'closed')
                                    ->orWhere('r.updated_at', '>=', $today->subDays(self::CLOSED_ROW_DAYS));
                            });
                    })
                    ->when($tracked['cid'] !== [], static function (Builder $query) use ($entityId, $tracked): void {
                        $query->orWhereExists(static function (Builder $sub) use ($entityId, $tracked): void {
                            // `diagnosis_cids` é json livre: só se olham as que SÃO lista (json_array_elements recusa o resto).
                            $sub->from('medical_records as m')
                                ->whereColumn('m.patient_id', 'p.id')
                                ->where('m.entity_id', $entityId)
                                ->whereNull('m.deleted_at')
                                ->whereNotNull('m.diagnosis_cids')
                                ->whereRaw(
                                    "CASE WHEN json_typeof(m.diagnosis_cids) = 'array' THEN EXISTS ("
                                    . 'SELECT 1 FROM json_array_elements(m.diagnosis_cids) AS d(item) '
                                    . "WHERE regexp_replace(upper(COALESCE(d.item->>'code', '')), '[^A-Z0-9]', '', 'g') LIKE ANY (?::text[])"
                                    . ') ELSE false END',
                                    [self::pgArray($tracked['cid'])],
                                );
                        });
                    })
                    ->when($tracked['meds'] !== [], static function (Builder $query) use ($entityId, $tracked): void {
                        $query->orWhereExists(static function (Builder $sub) use ($entityId, $tracked): void {
                            $sub->from('patient_medications as pm')
                                ->whereColumn('pm.patient_id', 'p.id')
                                ->where('pm.entity_id', $entityId)
                                ->whereNull('pm.deleted_at')
                                ->where('pm.status', 'active')
                                ->whereRaw("unaccent(lower(COALESCE(pm.active_ingredient, '') || ' ' || pm.name)) LIKE ANY (?::text[])", [self::pgArray($tracked['meds'])]);
                        });
                    })
                    ->orWhere(static function (Builder $recent) use ($entityId, $today): void {
                        $recent->whereNull('p.deleted_at')
                            ->whereExists(static function (Builder $sub) use ($entityId, $today): void {
                                $sub->from('medical_records as m')
                                    ->whereColumn('m.patient_id', 'p.id')
                                    ->where('m.entity_id', $entityId)
                                    ->whereNull('m.deleted_at')
                                    ->where('m.follow_up_days', '>', 0)
                                    ->where('m.created_at', '>=', $today->subDays(self::DOCTOR_ORDER_HORIZON_DAYS));
                            });
                    });
            });
    }

    /**
     * O que os protocolos ADOTADOS (em uso, íntegros) reconhecem: prefixos de CID (forte e fraco — o excluído é
     * decidido pelo detector) e os princípios ativos das medicações, já como padrões de LIKE. Vazio = a clínica não
     * tem protocolo a detectar.
     *
     * @return array{cid: list<string>, meds: list<string>}
     */
    public static function tracked(string $entityId): array
    {
        $cid  = [];
        $meds = [];

        $adoptions = RecallClinicProtocol::query()->withoutGlobalScopes()->where('entity_id', $entityId)->whereNull('deactivated_at')->with('version')->get();

        foreach ($adoptions as $adoption) {
            $version = $adoption->version;

            if (! $version->state->isInUse() || ! $version->integrityOk() || $version->content_hash !== $adoption->adopted_content_hash) {
                continue;
            }

            $content = ProtocolContentSchema::normalize($version->contentArray());

            foreach (['strong', 'weak'] as $list) {
                foreach ((array) ($content['entry']['cid'][$list] ?? []) as $prefix) {
                    $cid[] = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $prefix)) . '%';
                }
            }

            foreach ((array) ($content['entry']['medications'] ?? []) as $medicationKey) {
                foreach ((array) config("recall.medications.{$medicationKey}.ingredients", []) as $ingredient) {
                    $term = TermNormalizer::core((string) $ingredient);

                    if ($term !== '') {
                        $meds[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
                    }
                }
            }
        }

        return ['cid' => array_values(array_unique($cid)), 'meds' => array_values(array_unique($meds))];
    }

    /** Lista de textos como literal de array do PostgreSQL (`{"a","b"}`), para `LIKE ANY (?::text[])`. */
    private static function pgArray(array $values): string
    {
        return '{' . implode(',', array_map(static fn (string $value): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"', $values)) . '}';
    }
}
