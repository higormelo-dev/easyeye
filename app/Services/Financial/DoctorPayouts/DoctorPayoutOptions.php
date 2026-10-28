<?php

declare(strict_types=1);

namespace App\Services\Financial\DoctorPayouts;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Listas de apoio das telas de repasse (médicos, tipos de atendimento,
 * procedimentos, tipos de exame e convênios), sempre da clínica + catálogos
 * globais — nunca de outra clínica.
 *
 * Médico não tem entity_id: pertence à clínica pelo vínculo (entity_users).
 */
final class DoctorPayoutOptions
{
    private const TREATMENT_EXAM = 3;

    private const TREATMENT_PROCEDURE = 4;

    /**
     * Médicos da clínica — inclusive inativos e quem saiu (médico ou vínculo
     * excluído): o último repasse deles ainda precisa ser fechado e consultado.
     * Ativos primeiro, depois por nome.
     *
     * @return list<array{id: string, name: string, record: ?string, active: bool}>
     */
    public function doctors(string $entityId): array
    {
        return $this->doctorQuery($entityId)
            ->orderByRaw('(d.active AND d.deleted_at IS NULL AND eu.deleted_at IS NULL) DESC')
            ->orderBy('p.full_name')
            ->orderBy('d.id')
            ->get()
            ->map(fn (object $row) => $this->doctorRow($row))
            ->all();
    }

    /**
     * O médico, se pertence à clínica (inclusive quem saiu).
     *
     * @return array{id: string, name: string, record: ?string, active: bool}|null
     */
    public function doctor(string $entityId, string $doctorId): ?array
    {
        $row = $this->doctorQuery($entityId)->where('d.id', $doctorId)->first();

        return $row === null ? null : $this->doctorRow($row);
    }

    /**
     * Tipos de atendimento ativos (clínica + globais) com o tipo de serviço que
     * geram no repasse — o formulário de regra mostra só os do tipo escolhido.
     *
     * @return list<array{id: string, name: string, service_type: string}>
     */
    public function visitTypes(string $entityId): array
    {
        return DB::table('visit_types as vt')
            ->leftJoin('procedures as p', 'p.id', '=', 'vt.procedure_id')
            ->where(fn (Builder $q) => $q->where('vt.entity_id', $entityId)->orWhereNull('vt.entity_id'))
            ->whereNull('vt.deleted_at')
            ->where('vt.active', true)
            ->orderBy('vt.name')
            ->get(['vt.id', 'vt.name', 'p.treatment'])
            ->map(fn (object $row) => [
                'id'           => (string) $row->id,
                'name'         => (string) $row->name,
                'service_type' => self::serviceTypeForTreatment($row->treatment),
            ])
            ->all();
    }

    /**
     * Procedimentos que geram repasse pelo prontuário (tratamento 4 ou sem
     * classificação), clínica + globais.
     *
     * @return list<array{id: string, code: ?string, name: string}>
     */
    public function procedures(string $entityId): array
    {
        return DB::table('procedures')
            ->where(fn (Builder $q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->whereNull('deleted_at')
            ->where('active', true)
            ->where(fn (Builder $q) => $q->where('treatment', self::TREATMENT_PROCEDURE)->orWhereNull('treatment'))
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (object $row) => ['id' => (string) $row->id, 'code' => $row->code, 'name' => (string) $row->name])
            ->all();
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function examTypes(string $entityId): array
    {
        return DB::table('exam_types')
            ->where(fn (Builder $q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->whereNull('deleted_at')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (object $row) => ['id' => (string) $row->id, 'name' => (string) $row->name])
            ->all();
    }

    /**
     * Convênios ativos (clínica + globais); `particular` segue a regra do
     * faturamento (sem registro ANS); `own` = cadastrado pela clínica.
     *
     * @return list<array{id: string, name: string, particular: bool, own: bool}>
     */
    public function covenants(string $entityId): array
    {
        return DB::table('covenants')
            ->where(fn (Builder $q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->whereNull('deleted_at')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'entity_id', 'name', 'ans_registry'])
            ->map(fn (object $row) => [
                'id'         => (string) $row->id,
                'name'       => (string) $row->name,
                'particular' => preg_replace('/\D/', '', (string) ($row->ans_registry ?? '')) === '',
                'own'        => $row->entity_id !== null,
            ])
            ->all();
    }

    public static function serviceTypeForTreatment(mixed $treatment): string
    {
        return match ((int) ($treatment ?? 0)) {
            self::TREATMENT_EXAM      => 'exam',
            self::TREATMENT_PROCEDURE => 'procedure',
            default                   => 'consultation',
        };
    }

    private function doctorQuery(string $entityId): Builder
    {
        return DB::table('doctors as d')
            ->join('entity_users as eu', 'eu.id', '=', 'd.entity_user_id')
            ->leftJoin('people as p', 'p.id', '=', 'd.person_id')
            ->where('eu.entity_id', $entityId)
            ->select([
                'd.id',
                'd.record',
                'd.active',
                'd.deleted_at',
                'p.full_name',
                'eu.deleted_at as membership_deleted_at',
                'eu.active as membership_active',
            ]);
    }

    /** @return array{id: string, name: string, record: ?string, active: bool} */
    private function doctorRow(object $row): array
    {
        return [
            'id'     => (string) $row->id,
            'name'   => (string) ($row->full_name ?? '—'),
            'record' => $row->record,
            'active' => (bool) $row->active
                && $row->deleted_at === null
                && $row->membership_deleted_at === null
                && (bool) $row->membership_active,
        ];
    }
}
