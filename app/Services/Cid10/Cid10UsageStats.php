<?php

declare(strict_types=1);

namespace App\Services\Cid10;

use App\Models\Cid10Code;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Cache, DB};

/**
 * Uso da CID-10 pelas clínicas (Manager → CID-10) — SÓ contagens, nunca
 * dado de paciente.
 *
 * - Agregado (tabela cid10_usage_stats): UMA consulta sobre prontuários +
 *   exames (jsonb_array_elements), recalculada no máximo a cada 15 min
 *   (ensureFresh) — a tela junta a tabela na listagem (coluna/ordenação) sem
 *   varrer prontuários por página ou por linha.
 * - Ao vivo (liveCount): contagem exata de UM código, para as travas de
 *   excluir/renomear (o agregado pode estar até 15 min atrasado).
 */
class Cid10UsageStats
{
    public const TTL_SECONDS = 900;

    private const REFRESHED_KEY = 'cid10:usage:refreshed_at';

    private const LOCK_KEY = 'cid10:usage:refresh';

    /** Recalcula se a última apuração passou de 15 min. Devolve quando foi apurado. */
    public function ensureFresh(): ?CarbonImmutable
    {
        if ($this->refreshedAt() === null) {
            // Duas telas abertas juntas não recalculam em dobro.
            Cache::lock(self::LOCK_KEY, 120)->get(fn () => $this->refreshedAt() ?? $this->refresh());
        }

        return $this->refreshedAt();
    }

    public function refreshedAt(): ?CarbonImmutable
    {
        $value = Cache::get(self::REFRESHED_KEY);

        return $value ? CarbonImmutable::parse($value) : null;
    }

    /** Recalcula o agregado inteiro (substitui a tabela numa transação). */
    public function refresh(): CarbonImmutable
    {
        $rows  = $this->aggregate();
        $links = $this->links();

        foreach ($links as $code => $count) {
            $rows[$code] ??= ['records' => 0, 'exams' => 0, 'clinics' => 0];
            $rows[$code]['links'] = $count;
        }

        $records = collect($rows)->map(fn (array $r, string $code) => [
            'code'          => mb_substr($code, 0, 10),
            'records_count' => $r['records'],
            'exams_count'   => $r['exams'],
            'clinics_count' => $r['clinics'],
            'links_count'   => $r['links'] ?? 0,
            'total_count'   => $r['records'] + $r['exams'],
        ])->unique('code')->values();

        DB::transaction(function () use ($records): void {
            DB::table('cid10_usage_stats')->delete();

            foreach ($records->chunk(1000) as $chunk) {
                DB::table('cid10_usage_stats')->insert($chunk->all());
            }
        });

        $now = CarbonImmutable::now();
        Cache::put(self::REFRESHED_KEY, $now->toIso8601String(), self::TTL_SECONDS);

        return $now;
    }

    /** Descarta a apuração atual (a próxima tela recalcula). */
    public function forget(): void
    {
        Cache::forget(self::REFRESHED_KEY);
    }

    /**
     * Uso exato de um código, agora: prontuários (inclusive excluídos — o
     * registro continua guardado), exames e vínculos de clínica (mais
     * usados do diagnóstico de exame / diagnóstico próprio da clínica).
     *
     * @return array{records: int, exams: int, links: int, total: int}
     */
    public function liveCount(Cid10Code $code): array
    {
        $needle = [['code' => $code->code]];

        $records = DB::table('medical_records')->whereJsonContains('diagnosis_cids', $needle)->count();
        $exams   = DB::table('patient_exams')->whereJsonContains('diagnosis_cids', $needle)->count();
        $links   = DB::table('entity_diagnosis_usages')->where('cid10_code_id', $code->id)->count()
            + DB::table('entity_custom_diagnoses')->where('cid10_code_id', $code->id)->whereNull('deleted_at')->count();

        return ['records' => $records, 'exams' => $exams, 'links' => $links, 'total' => $records + $exams + $links];
    }

    /**
     * Prontuários (não excluídos) e exames por código, e em quantas clínicas.
     * diagnosis_cids = [{code, description}]; JSON que não é lista é ignorado.
     *
     * @return array<string, array{records: int, exams: int, clinics: int}>
     */
    private function aggregate(): array
    {
        $elements = fn (string $column) => "jsonb_array_elements(CASE WHEN jsonb_typeof({$column}::jsonb) = 'array' THEN {$column}::jsonb ELSE '[]'::jsonb END)";

        $sql = "
            SELECT u.code,
                   COUNT(DISTINCT CASE WHEN u.kind = 'r' THEN u.row_id END) AS records,
                   COUNT(DISTINCT CASE WHEN u.kind = 'e' THEN u.row_id END) AS exams,
                   COUNT(DISTINCT u.entity_id) AS clinics
              FROM (
                    SELECT UPPER(TRIM(el.value ->> 'code')) AS code, mr.id::text AS row_id, mr.entity_id::text AS entity_id, 'r' AS kind
                      FROM medical_records mr
                     CROSS JOIN LATERAL {$elements('mr.diagnosis_cids')} AS el(value)
                     WHERE mr.deleted_at IS NULL AND jsonb_typeof(el.value) = 'object'
                    UNION ALL
                    SELECT UPPER(TRIM(el.value ->> 'code')), pe.id::text, p.entity_id::text, 'e'
                      FROM patient_exams pe
                      JOIN patients p ON p.id = pe.patient_id
                     CROSS JOIN LATERAL {$elements('pe.diagnosis_cids')} AS el(value)
                     WHERE jsonb_typeof(el.value) = 'object'
                   ) u
             WHERE u.code IS NOT NULL AND u.code <> ''
             GROUP BY u.code";

        $rows = [];

        foreach (DB::select($sql) as $row) {
            $rows[(string) $row->code] = ['records' => (int) $row->records, 'exams' => (int) $row->exams, 'clinics' => (int) $row->clinics];
        }

        return $rows;
    }

    /** @return array<string, int> código => vínculos de clínica */
    private function links(): array
    {
        $usages = DB::table('entity_diagnosis_usages as u')
            ->join('cid10_codes as c', 'c.id', '=', 'u.cid10_code_id')
            ->groupBy('c.code')
            ->selectRaw('c.code, COUNT(*) AS total')
            ->pluck('total', 'code');

        $custom = DB::table('entity_custom_diagnoses as d')
            ->join('cid10_codes as c', 'c.id', '=', 'd.cid10_code_id')
            ->whereNull('d.deleted_at')
            ->groupBy('c.code')
            ->selectRaw('c.code, COUNT(*) AS total')
            ->pluck('total', 'code');

        $links = [];

        foreach ([$usages, $custom] as $source) {
            foreach ($source as $code => $total) {
                $links[(string) $code] = ($links[(string) $code] ?? 0) + (int) $total;
            }
        }

        return $links;
    }
}
