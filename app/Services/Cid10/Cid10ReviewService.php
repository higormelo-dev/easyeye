<?php

declare(strict_types=1);

namespace App\Services\Cid10;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Cache, DB};

/**
 * Levantamento (SÓ LEITURA) dos prontuários e exames que usaram um código
 * CID-10 cuja descrição no catálogo apontava para OUTRA doença (seleção
 * oftalmológica escrita à mão até 10/2026 — ex.: H50.5 aparecia como
 * "Estrabismo paralítico", mas oficialmente é Heteroforia).
 *
 * Usado pelo comando cid10:audit-records e pelo card "Registros a revisar"
 * do Manager → CID-10. Nunca expõe paciente: só a clínica, o código do
 * registro (PMR…/EXM…), o CID e o texto gravado.
 */
class Cid10ReviewService
{
    /** código => descrição errada que o catálogo mostrava (a oficial vem do catálogo) */
    public const AFFECTED = [
        'B00.3' => 'Doença ocular herpética',
        'H18.7' => 'Outras degenerações da córnea',
        'H25.2' => 'Catarata senil polar posterior',
        'H50.4' => 'Heteroforia',
        'H50.5' => 'Estrabismo paralítico',
        'H59.0' => 'Síndrome do vítreo após cirurgia de catarata',
        'Q14.2' => 'Malformação congênita do vítreo',
    ];

    public const KIND_RECORD = 'record';

    public const KIND_EXAM = 'exam';

    private const CACHE_KEY = 'cid10:review:rows';

    /**
     * Registros afetados (agora, sem cache).
     *
     * @return Collection<int, object{entity_id: string, entity: string, kind: string, code: ?string, cid: string, text: string, signed: bool}>
     */
    public function rows(): Collection
    {
        $codes = array_keys(self::AFFECTED);

        return $this->medicalRecords($codes)->concat($this->exams($codes))->values();
    }

    /**
     * Mesma lista, apurada no máximo a cada 15 min (tela do manager — a
     * varredura textual dos prontuários não roda a cada clique).
     *
     * @return list<array{entity_id: string, entity: string, kind: string, code: ?string, cid: string, text: string, signed: bool}>
     */
    public function cachedRows(): array
    {
        return Cache::remember(self::CACHE_KEY, Cid10UsageStats::TTL_SECONDS, fn () => $this->rows()
            ->map(fn (object $row) => (array) $row)
            ->all());
    }

    /**
     * Resumo por clínica para o card.
     *
     * @return array{total: int, clinics: list<array{entity: string, records: int, exams: int, signed: int, total: int}>}
     */
    public function summary(): array
    {
        $rows = collect($this->cachedRows());

        return [
            'total'   => $rows->count(),
            'clinics' => $rows->groupBy('entity_id')
                ->map(fn (Collection $group) => [
                    'entity'  => (string) $group->first()['entity'],
                    'records' => $group->where('kind', self::KIND_RECORD)->count(),
                    'exams'   => $group->where('kind', self::KIND_EXAM)->count(),
                    'signed'  => $group->where('signed', true)->count(),
                    'total'   => $group->count(),
                ])
                ->sortBy('entity')
                ->values()
                ->all(),
        ];
    }

    /** @param list<string> $codes */
    private function medicalRecords(array $codes): Collection
    {
        return DB::table('medical_records as mr')
            ->join('entities as e', 'e.id', '=', 'mr.entity_id')
            ->whereNull('mr.deleted_at')
            ->where(fn ($q) => $this->mentionsAny($q, 'mr.diagnosis_cids', $codes))
            ->select(['e.id as entity_id', 'e.name as entity', 'mr.code', 'mr.signed_at', 'mr.diagnosis_cids'])
            ->orderBy('e.name')
            ->orderBy('mr.code')
            ->get()
            ->flatMap(fn ($row) => $this->matches($row, $codes, self::KIND_RECORD, $row->signed_at !== null));
    }

    /** @param list<string> $codes */
    private function exams(array $codes): Collection
    {
        return DB::table('patient_exams as pe')
            ->join('patients as p', 'p.id', '=', 'pe.patient_id')
            ->join('entities as e', 'e.id', '=', 'p.entity_id')
            ->where(fn ($q) => $this->mentionsAny($q, 'pe.diagnosis_cids', $codes))
            ->select(['e.id as entity_id', 'e.name as entity', 'pe.code', 'pe.diagnosis_cids'])
            ->orderBy('e.name')
            ->orderBy('pe.code')
            ->get()
            ->flatMap(fn ($row) => $this->matches($row, $codes, self::KIND_EXAM, false));
    }

    /**
     * Pré-filtro no banco (texto do JSON contém o código) — o casamento exato
     * por item é feito em matches().
     *
     * @param list<string> $codes
     */
    private function mentionsAny($query, string $column, array $codes): void
    {
        foreach ($codes as $code) {
            $query->orWhereRaw("CAST({$column} AS TEXT) LIKE ?", ['%' . $code . '%']);
        }
    }

    /**
     * @param list<string> $codes
     *
     * @return list<object>
     */
    private function matches(object $row, array $codes, string $kind, bool $signed): array
    {
        $cids = json_decode((string) $row->diagnosis_cids, true);

        if (! is_array($cids)) {
            return [];
        }

        $found = [];

        foreach ($cids as $cid) {
            $code = strtoupper(trim((string) (is_array($cid) ? ($cid['code'] ?? '') : '')));

            if (in_array($code, $codes, true)) {
                $found[] = (object) [
                    'entity_id' => (string) $row->entity_id,
                    'entity'    => (string) $row->entity,
                    'kind'      => $kind,
                    'code'      => $row->code,
                    'cid'       => $code,
                    'text'      => (string) ($cid['description'] ?? ''),
                    'signed'    => $signed,
                ];
            }
        }

        return $found;
    }
}
