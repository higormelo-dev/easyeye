<?php

declare(strict_types=1);

namespace App\Services\Financial\DoctorPayouts;

use App\DTOs\DoctorPayout\PayoutItemData;
use App\Enums\{CashEntryReferenceType, FinancialEntryType, MedicalRecordProcedureStatus, ScheduleSituation};
use App\Enums\DoctorPayout\{DoctorPayoutBaseSource, DoctorPayoutBasis, DoctorPayoutServiceType, DoctorPayoutSourceType, DoctorPayoutWarning};
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Atos realizados por um ou mais médicos num período — a produção sobre a
 * qual o regime por RECEBIMENTO calcula parcelas (DoctorPayoutReleaseService).
 * Vários médicos saem numa consulta por fonte (participante fixo de regra vê
 * os atos dos demais sem uma consulta por médico). Ato já
 * liberado continua aparecendo (pode receber complemento ou estorno); só sai
 * o ato fechado no regime anterior (basis = production), que é final.
 *
 * Fontes:
 *  1. Agendamentos atendidos (médico do agendamento — o mesmo do faturamento
 *     e do relatório de produção). Classificação pelo procedimento do tipo de
 *     atendimento: tratamento 3 = exame, 4 = procedimento, resto = consulta.
 *  2. Procedimentos executados no prontuário com tratamento 4 (cirúrgico/
 *     intervencionista), para o médico que executou (fallback: médico do
 *     procedimento). Tratamentos 2 (consulta/parecer) e 3 (exame) não entram:
 *     consulta já vem da agenda e exame, dos equipamentos.
 *  3. Exames de equipamento (patient_exams, uma linha por IMAGEM): um item por
 *     paciente + tipo de exame + dia local. Importação externa não é produção
 *     da clínica e fica de fora.
 *
 * Um ato = um item: agendamento de procedimento cujo procedimento foi
 * executado no prontuário conta só pelo prontuário (com o valor cobrado do
 * agendamento como base); exame de equipamento vinculado a agendamento de
 * exame não conta de novo. Se o agendamento já tem parcela liberada, ele
 * continua sendo o ato (o procedimento marcado depois não vira outro ato).
 * Procedimentos iguais pareados com o mesmo agendamento (ex.: OD e OE)
 * dividem o valor cobrado nele (previsão) — mesma divisão do recebido.
 *
 * Isolamento: query builder com filtro EXPLÍCITO de clínica em todas as
 * fontes — o EntityScope também devolve linhas sem entity_id (prontuários
 * legados) e Doctor/PatientExam nem têm a coluna.
 *
 * Valor base ("valor cobrado", PREVISÃO do que será recebido) em centavos:
 *  - caixa: lançamentos de receita não cancelados do agendamento;
 *  - guia: rascunho/enviada/paga (paga → valor recebido; demais → valor − glosa);
 *  - tabela de preços só quando NUNCA houve lançamento nem guia (sem convênio
 *    → convênio global PARTICULAR); cobrança toda cancelada/negada → 0 + alerta;
 *  - exame de equipamento não tem preço: base 0 (regra fixa por exame).
 */
final class DoctorPayoutProductionService
{
    private const TREATMENT_EXAM = 3;

    private const TREATMENT_PROCEDURE = 4;

    /** Guias que representam cobrança válida (as demais foram canceladas/negadas). */
    private const CLAIM_ACTIVE_STATUSES = "'draft', 'submitted', 'paid'";

    private const EXTERNAL_EXAM_SOURCE = 'external_import';

    /** Médico que executou o procedimento (inclui quem saiu da clínica); sem vínculo, o do procedimento. */
    private const PROCEDURE_EXECUTOR = <<<'SQL'
        COALESCE(
            (SELECT d.id FROM doctors d WHERE d.entity_user_id = mrp.executed_by ORDER BY d.created_at LIMIT 1),
            mrp.doctor_id
        )
        SQL;

    /**
     * @return Collection<int, PayoutItemData> ordenado por data do ato
     */
    public function acts(string $entityId, string $doctorId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->actsOf($entityId, [$doctorId], $from, $to);
    }

    /**
     * Atos de vários médicos (cada item com o próprio médico executor).
     *
     * @param list<string> $doctorIds
     *
     * @return Collection<int, PayoutItemData> ordenado por data do ato
     */
    public function actsOf(string $entityId, array $doctorIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $doctorIds = array_values(array_unique($doctorIds));

        if ($doctorIds === []) {
            return collect();
        }

        $start = $from->startOfDay()->format('Y-m-d H:i:s');
        $end   = $to->endOfDay()->format('Y-m-d H:i:s');

        $schedules  = $this->scheduleRows($entityId, $doctorIds, $start, $end);
        $procedures = $this->procedureRows($entityId, $doctorIds, $start, $end);
        $exams      = $this->examRows($entityId, $doctorIds, $start, $end);

        $covenants    = $this->covenants($this->covenantIds($schedules, $procedures, $exams));
        $particularId = $this->globalParticularCovenantId();
        $prices       = $this->prices($entityId, $schedules, $procedures, $particularId);

        $items = collect()
            ->merge($schedules->map(fn (object $row) => $this->scheduleItem($row, $covenants, $prices, $particularId)))
            ->merge($procedures->map(fn (object $row) => $this->procedureItem($row, $covenants, $prices, $particularId)))
            ->merge($this->examItems($entityId, $exams, $covenants));

        return $items
            ->sortBy([
                fn (PayoutItemData $a, PayoutItemData $b) => $a->performedAt <=> $b->performedAt,
                fn (PayoutItemData $a, PayoutItemData $b) => strcmp($a->key(), $b->key()),
            ])
            ->values();
    }

    // ---------------------------------------------------------------------
    // Fonte 1 — agendamentos atendidos
    // ---------------------------------------------------------------------

    /** @param list<string> $doctorIds */
    private function scheduleRows(string $entityId, array $doctorIds, string $start, string $end): Collection
    {
        $procedureDone      = MedicalRecordProcedureStatus::Done->value;
        $procedureCancelled = MedicalRecordProcedureStatus::Cancelled->value;
        $scheduleType       = DoctorPayoutSourceType::Schedule->value;

        return DB::table('schedules as s')
            ->leftJoin('visit_types as vt', 'vt.id', '=', 's.visit_id')
            ->leftJoin('procedures as vp', 'vp.id', '=', 'vt.procedure_id')
            ->leftJoinSub($this->cashTotals($entityId), 'ce', 'ce.schedule_id', '=', 's.id')
            ->leftJoinSub($this->claimTotals($entityId), 'bc', 'bc.schedule_id', '=', 's.id')
            // Exames de equipamento do agendamento (agendamento de exame conta
            // como o ato; o tipo do exame capturado é o que a regra "por tipo
            // de exame" — ex.: OCT — precisa enxergar).
            ->leftJoinLateral($this->linkedExamTypes(), 'lx')
            ->where('s.entity_id', $entityId)
            ->whereIn('s.doctor_id', $doctorIds)
            ->whereNull('s.deleted_at')
            ->where('s.situation', ScheduleSituation::Attended->value)
            // Repete o predicado do índice parcial (doctor_id, date_time): o
            // planner só usa o índice quando a consulta implica a condição dele.
            ->whereNotIn('s.situation', [ScheduleSituation::NoShow->value, ScheduleSituation::Cancelled->value])
            ->whereBetween('s.date_time', [$start, $end])
            ->whereNotExists(fn (Builder $q) => $this->legacyPayoutItem($q, $entityId, DoctorPayoutSourceType::Schedule, 's.id'))
            // Um ato = um item: procedimento agendado e executado no prontuário conta
            // pelo prontuário — salvo se o agendamento já tem parcela liberada.
            ->whereRaw(<<<SQL
                NOT (COALESCE(vp.treatment, 0) = ? AND EXISTS (
                    SELECT 1
                    FROM medical_record_procedures mrp
                    JOIN medical_records mr ON mr.id = mrp.medical_record_id
                    WHERE mr.schedule_id = s.id
                      AND mr.deleted_at IS NULL
                      AND mrp.deleted_at IS NULL
                      AND mrp.status = '{$procedureDone}'
                      AND mrp.procedure_id = vt.procedure_id
                ) AND NOT EXISTS (
                    SELECT 1
                    FROM doctor_payout_items dpi_s
                    WHERE dpi_s.entity_id = s.entity_id
                      AND dpi_s.source_type = '{$scheduleType}'
                      AND dpi_s.source_id = s.id::text
                      AND dpi_s.voided_at IS NULL
                ))
                SQL, [self::TREATMENT_PROCEDURE])
            ->select([
                's.id',
                's.doctor_id',
                's.date_time',
                's.patient_id',
                's.covenant_id',
                's.visit_id',
                'vt.name as visit_type_name',
                'vt.procedure_id as visit_procedure_id',
                'vp.name as visit_procedure_name',
                'vp.treatment as visit_treatment',
                'ce.active_amount as cash_active_amount',
                'ce.active_count as cash_active_count',
                'ce.total_count as cash_total_count',
                'bc.active_amount as claim_active_amount',
                'bc.active_count as claim_active_count',
                'bc.total_count as claim_total_count',
                'lx.exam_type_count as linked_exam_type_count',
                'lx.exam_type_id as linked_exam_type_id',
                'lx.exam_type_name as linked_exam_type_name',
            ])
            ->selectRaw(<<<'SQL'
                (SELECT mr.doctor_id
                 FROM medical_records mr
                 WHERE mr.schedule_id = s.id AND mr.deleted_at IS NULL
                 ORDER BY mr.created_at
                 LIMIT 1) AS record_doctor_id
                SQL)
            ->selectRaw(<<<SQL
                EXISTS (
                    SELECT 1
                    FROM medical_record_procedures mrp2
                    JOIN medical_records mr2 ON mr2.id = mrp2.medical_record_id
                    JOIN procedures p2 ON p2.id = mrp2.procedure_id
                    WHERE mr2.schedule_id = s.id
                      AND mr2.deleted_at IS NULL
                      AND mrp2.deleted_at IS NULL
                      AND mrp2.status = '{$procedureDone}'
                      AND p2.treatment = ?
                      AND mrp2.procedure_id IS DISTINCT FROM vt.procedure_id
                ) AS has_other_procedure
                SQL, [self::TREATMENT_PROCEDURE])
            // Procedimento do agendamento registrado no prontuário só como
            // cancelado (nenhum executado): o agendamento "atendido" continua
            // contando (fallback de quem não registra a execução), com alerta.
            ->selectRaw(<<<SQL
                (COALESCE(vp.treatment, 0) = ? AND EXISTS (
                    SELECT 1
                    FROM medical_record_procedures mrp3
                    JOIN medical_records mr3 ON mr3.id = mrp3.medical_record_id
                    WHERE mr3.schedule_id = s.id
                      AND mr3.deleted_at IS NULL
                      AND mrp3.deleted_at IS NULL
                      AND mrp3.status = '{$procedureCancelled}'
                      AND mrp3.procedure_id = vt.procedure_id
                ) AND NOT EXISTS (
                    SELECT 1
                    FROM medical_record_procedures mrp4
                    JOIN medical_records mr4 ON mr4.id = mrp4.medical_record_id
                    WHERE mr4.schedule_id = s.id
                      AND mr4.deleted_at IS NULL
                      AND mrp4.deleted_at IS NULL
                      AND mrp4.status = '{$procedureDone}'
                      AND mrp4.procedure_id = vt.procedure_id
                )) AS procedure_cancelled
                SQL, [self::TREATMENT_PROCEDURE])
            ->orderBy('s.date_time')
            ->get();
    }

    /**
     * Tipos de exame capturados pelo equipamento para o agendamento (lateral,
     * pelo índice de schedule_id): quantidade, e o tipo/nome quando é um só.
     * Mesmos filtros da fonte de exames (ativo, sem importação externa).
     */
    private function linkedExamTypes(): Builder
    {
        return DB::table('patient_exams as lpe')
            ->join('exam_types as let', 'let.id', '=', 'lpe.exam_id')
            ->whereColumn('lpe.schedule_id', 's.id')
            ->where('lpe.active', true)
            ->where(fn (Builder $q) => $q->whereNull('lpe.source')->orWhere('lpe.source', '<>', self::EXTERNAL_EXAM_SOURCE))
            ->selectRaw('COUNT(DISTINCT lpe.exam_id) AS exam_type_count')
            ->selectRaw('MIN(lpe.exam_id::text) AS exam_type_id')
            ->selectRaw('MIN(let.name) AS exam_type_name');
    }

    /**
     * @param array<string, object> $covenants
     * @param array<string, int>    $prices
     */
    private function scheduleItem(object $row, array $covenants, array $prices, ?string $particularId): PayoutItemData
    {
        $doctorId    = (string) $row->doctor_id;
        $serviceType = match ((int) ($row->visit_treatment ?? 0)) {
            self::TREATMENT_EXAM      => DoctorPayoutServiceType::Exam,
            self::TREATMENT_PROCEDURE => DoctorPayoutServiceType::Procedure,
            default                   => DoctorPayoutServiceType::Consultation,
        };

        $description = $serviceType === DoctorPayoutServiceType::Consultation
            ? ($row->visit_type_name ?? __('financial_doctor_payouts.descriptions.consultation'))
            : ($row->visit_procedure_name ?? $row->visit_type_name ?? $serviceType->label());

        // Agendamento de exame com UM tipo de exame capturado: o ato leva esse
        // tipo (a regra por tipo de exame vale; a por tipo de atendimento
        // continua vencendo pela especificidade) e o nome do exame feito.
        $examTypes  = $serviceType === DoctorPayoutServiceType::Exam ? (int) ($row->linked_exam_type_count ?? 0) : 0;
        $examTypeId = $examTypes === 1 ? $row->linked_exam_type_id : null;

        if ($examTypeId !== null) {
            $description = (string) $row->linked_exam_type_name;
        }

        [$baseCents, $baseSource, $warnings] = $this->chargedBase(
            $row,
            $this->priceFor($prices, $row->covenant_id ?? $particularId, $row->visit_procedure_id),
        );

        if ($row->record_doctor_id !== null && $row->record_doctor_id !== $doctorId) {
            $warnings[] = DoctorPayoutWarning::DoctorMismatch;
        }

        if ((bool) $row->has_other_procedure && $baseSource === DoctorPayoutBaseSource::Charged) {
            $warnings[] = DoctorPayoutWarning::SharedCharge;
        }

        if ($examTypes > 1) {
            $warnings[] = DoctorPayoutWarning::MultipleExamTypes;
        }

        if ((bool) $row->procedure_cancelled) {
            $warnings[] = DoctorPayoutWarning::ProcedureCancelled;
        }

        [$covenantName, $isParticular] = $this->payer($covenants, $row->covenant_id);

        return new PayoutItemData(
            sourceType: DoctorPayoutSourceType::Schedule,
            sourceId: (string) $row->id,
            serviceType: $serviceType,
            performedAt: CarbonImmutable::parse($row->date_time),
            doctorId: $doctorId,
            patientId: $row->patient_id,
            covenantId: $row->covenant_id,
            covenantName: $covenantName,
            isParticular: $isParticular,
            description: (string) $description,
            visitTypeId: $row->visit_id,
            procedureId: $row->visit_procedure_id,
            examTypeId: $examTypeId,
            baseCents: $baseCents,
            baseSource: $baseSource,
            warnings: $warnings,
        );
    }

    // ---------------------------------------------------------------------
    // Fonte 2 — procedimentos executados no prontuário
    // ---------------------------------------------------------------------

    /** @param list<string> $doctorIds */
    private function procedureRows(string $entityId, array $doctorIds, string $start, string $end): Collection
    {
        return DB::table('medical_record_procedures as mrp')
            ->join('medical_records as mr', 'mr.id', '=', 'mrp.medical_record_id')
            ->leftJoin('procedures as p', 'p.id', '=', 'mrp.procedure_id')
            ->leftJoin('schedules as s', 's.id', '=', 'mr.schedule_id')
            ->leftJoin('visit_types as vt', 'vt.id', '=', 's.visit_id')
            ->leftJoin('patients as pat', 'pat.id', '=', 'mrp.patient_id')
            ->leftJoinSub($this->cashTotals($entityId), 'ce', 'ce.schedule_id', '=', 's.id')
            ->leftJoinSub($this->claimTotals($entityId), 'bc', 'bc.schedule_id', '=', 's.id')
            ->where('mrp.entity_id', $entityId)
            ->where('mrp.status', MedicalRecordProcedureStatus::Done->value)
            ->whereNull('mrp.deleted_at')
            ->whereNull('mr.deleted_at')
            ->whereBetween('mrp.executed_at', [$start, $end])
            // Tratamento nulo (procedimento sem classificação) conta como procedimento.
            ->whereRaw('COALESCE(p.treatment, ?) = ?', [self::TREATMENT_PROCEDURE, self::TREATMENT_PROCEDURE])
            ->whereRaw(self::PROCEDURE_EXECUTOR . ' IN (' . implode(', ', array_fill(0, count($doctorIds), '?')) . ')', $doctorIds)
            ->whereNotExists(fn (Builder $q) => $this->legacyPayoutItem($q, $entityId, DoctorPayoutSourceType::MedicalRecordProcedure, 'mrp.id'))
            // Agendamento pareado com parcela/fechamento válido: o ato é o agendamento.
            ->whereNotExists(fn (Builder $q) => $this->activePayoutItem($q, $entityId, DoctorPayoutSourceType::Schedule, 's.id')
                ->whereColumn('vt.procedure_id', 'mrp.procedure_id'))
            ->select([
                'mrp.id',
                'mrp.executed_at',
                'mrp.patient_id',
                'mrp.procedure_id',
                'p.name as procedure_name',
                's.id as schedule_id',
                's.visit_id',
                'ce.active_amount as cash_active_amount',
                'ce.active_count as cash_active_count',
                'ce.total_count as cash_total_count',
                'bc.active_amount as claim_active_amount',
                'bc.active_count as claim_active_count',
                'bc.total_count as claim_total_count',
            ])
            ->selectRaw(self::PROCEDURE_EXECUTOR . ' AS executor_id')
            ->selectRaw('COALESCE(s.covenant_id, pat.covenant_id) AS covenant_id')
            ->selectRaw('(vt.procedure_id IS NOT NULL AND vt.procedure_id = mrp.procedure_id) AS paired')
            ->selectRaw(self::pairedSiblingsSelect())
            ->orderBy('mrp.executed_at')
            ->get();
    }

    /**
     * @param array<string, object> $covenants
     * @param array<string, int>    $prices
     */
    private function procedureItem(object $row, array $covenants, array $prices, ?string $particularId): PayoutItemData
    {
        $doctorId   = (string) $row->executor_id;
        $tablePrice = $this->priceFor($prices, $row->covenant_id ?? $particularId, $row->procedure_id);

        // Pareado com o agendamento do mesmo procedimento: o valor cobrado no
        // agendamento é deste procedimento. Sem pareamento, o que foi cobrado no
        // agendamento pertence à consulta — o procedimento usa a tabela.
        [$baseCents, $baseSource, $warnings] = (bool) $row->paired
            ? $this->chargedBase($row, $tablePrice)
            : $this->tableBase($tablePrice);

        // Vários procedimentos iguais pareados com o mesmo agendamento (ex.: OD
        // e OE em linhas separadas) dividem a MESMA cobrança — a mesma divisão
        // (posição por executed_at, id) que o recebido usa nas parcelas.
        $pairedCount = (int) ($row->paired_count ?? 0);

        if ((bool) $row->paired && $baseSource === DoctorPayoutBaseSource::Charged && $pairedCount > 1) {
            $baseCents  = Money::allocate($baseCents, $pairedCount)[min((int) $row->paired_rank, $pairedCount - 1)];
            $warnings[] = DoctorPayoutWarning::SplitCharge;
        }

        [$covenantName, $isParticular] = $this->payer($covenants, $row->covenant_id);

        return new PayoutItemData(
            sourceType: DoctorPayoutSourceType::MedicalRecordProcedure,
            sourceId: (string) $row->id,
            serviceType: DoctorPayoutServiceType::Procedure,
            performedAt: CarbonImmutable::parse($row->executed_at),
            doctorId: $doctorId,
            patientId: $row->patient_id,
            covenantId: $row->covenant_id,
            covenantName: $covenantName,
            isParticular: $isParticular,
            description: (string) ($row->procedure_name ?? __('financial_doctor_payouts.descriptions.procedure')),
            visitTypeId: (bool) $row->paired ? $row->visit_id : null,
            procedureId: $row->procedure_id,
            examTypeId: null,
            baseCents: $baseCents,
            baseSource: $baseSource,
            warnings: $warnings,
        );
    }

    // ---------------------------------------------------------------------
    // Fonte 3 — exames de equipamento
    // ---------------------------------------------------------------------

    /** @param list<string> $doctorIds */
    private function examRows(string $entityId, array $doctorIds, string $start, string $end): Collection
    {
        $examDay = 'CAST(COALESCE(pe.exam_performed_at, pe.created_at) AS date)';

        return DB::table('patient_exams as pe')
            ->join('patients as pat', 'pat.id', '=', 'pe.patient_id')
            ->join('exam_types as et', 'et.id', '=', 'pe.exam_id')
            ->leftJoin('schedules as s', 's.id', '=', 'pe.schedule_id')
            ->leftJoin('visit_types as vt', 'vt.id', '=', 's.visit_id')
            ->leftJoin('procedures as vp', 'vp.id', '=', 'vt.procedure_id')
            ->where('pat.entity_id', $entityId)
            ->whereIn('pe.doctor_id', $doctorIds)
            ->where('pe.active', true)
            ->where(fn (Builder $q) => $q->whereNull('pe.source')->orWhere('pe.source', '<>', self::EXTERNAL_EXAM_SOURCE))
            ->whereRaw('COALESCE(pe.exam_performed_at, pe.created_at) BETWEEN ? AND ?', [$start, $end])
            ->groupBy('pe.doctor_id', 'pe.patient_id', 'pe.exam_id', 'et.name')
            ->groupByRaw($examDay)
            ->select(['pe.doctor_id', 'pe.patient_id', 'pe.exam_id', 'et.name as exam_name'])
            ->selectRaw("{$examDay} AS exam_date")
            ->selectRaw('MIN(COALESCE(pe.exam_performed_at, pe.created_at)) AS performed_at')
            ->selectRaw('MIN(s.covenant_id::text) AS schedule_covenant_id')
            ->selectRaw('MIN(pat.covenant_id::text) AS patient_covenant_id')
            ->selectRaw(
                'BOOL_OR(COALESCE(vp.treatment, 0) = ? AND s.situation = ? AND s.deleted_at IS NULL) AS linked_exam_appointment',
                [self::TREATMENT_EXAM, ScheduleSituation::Attended->value],
            )
            ->get()
            ->map(function (object $row): object {
                $row->covenant_id = $row->schedule_covenant_id ?? $row->patient_covenant_id;
                $row->source_id   = self::examKey((string) $row->patient_id, (string) $row->exam_id, (string) $row->exam_date);

                return $row;
            });
    }

    /**
     * @param array<string, object> $covenants
     *
     * @return Collection<int, PayoutItemData>
     */
    private function examItems(string $entityId, Collection $rows, array $covenants): Collection
    {
        // Agrupamento feito em SQL: o "fechado no regime anterior" do exame é
        // filtrado aqui, pela mesma chave gravada no item do fechamento.
        $closed = $rows->isEmpty() ? [] : DB::table('doctor_payout_items')
            ->where('entity_id', $entityId)
            ->where('source_type', DoctorPayoutSourceType::PatientExam->value)
            ->where('basis', DoctorPayoutBasis::Production->value)
            ->whereNull('voided_at')
            ->whereIn('source_id', $rows->pluck('source_id')->all())
            ->pluck('source_id')
            ->flip()
            ->all();

        return $rows
            ->reject(fn (object $row) => (bool) $row->linked_exam_appointment || isset($closed[$row->source_id]))
            ->map(function (object $row) use ($covenants): PayoutItemData {
                [$covenantName, $isParticular] = $this->payer($covenants, $row->covenant_id);

                return new PayoutItemData(
                    sourceType: DoctorPayoutSourceType::PatientExam,
                    sourceId: $row->source_id,
                    serviceType: DoctorPayoutServiceType::Exam,
                    performedAt: CarbonImmutable::parse($row->performed_at),
                    doctorId: (string) $row->doctor_id,
                    patientId: $row->patient_id,
                    covenantId: $row->covenant_id,
                    covenantName: $covenantName,
                    isParticular: $isParticular,
                    description: (string) $row->exam_name,
                    visitTypeId: null,
                    procedureId: null,
                    examTypeId: $row->exam_id,
                    baseCents: 0,
                    baseSource: DoctorPayoutBaseSource::None,
                );
            })
            ->values();
    }

    /**
     * Exames de equipamento do período SEM médico (integrador sem agendamento
     * no dia): não entram no repasse de ninguém — a apuração avisa. Contados
     * como atos (paciente + tipo + dia), com os mesmos filtros da fonte.
     */
    public function unassignedExamCount(string $entityId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) DB::table('patient_exams as pe')
            ->join('patients as pat', 'pat.id', '=', 'pe.patient_id')
            ->where('pat.entity_id', $entityId)
            ->whereNull('pe.doctor_id')
            ->where('pe.active', true)
            ->where(fn (Builder $q) => $q->whereNull('pe.source')->orWhere('pe.source', '<>', self::EXTERNAL_EXAM_SOURCE))
            ->whereRaw('COALESCE(pe.exam_performed_at, pe.created_at) BETWEEN ? AND ?', [
                $from->startOfDay()->format('Y-m-d H:i:s'),
                $to->endOfDay()->format('Y-m-d H:i:s'),
            ])
            ->selectRaw('COUNT(DISTINCT (pe.patient_id, pe.exam_id, CAST(COALESCE(pe.exam_performed_at, pe.created_at) AS date))) AS acts')
            ->value('acts');
    }

    /** Chave do exame de equipamento: paciente|tipo|data local (Y-m-d). */
    public static function examKey(string $patientId, string $examTypeId, string $date): string
    {
        return $patientId . '|' . $examTypeId . '|' . substr($date, 0, 10);
    }

    /**
     * Procedimentos iguais executados pareados com o mesmo agendamento: quantos
     * são (paired_count) e a posição deste por (executed_at, id) (paired_rank)
     * — a ordem do rateio (Money::allocate) da previsão e do recebido. Requer
     * os aliases mrp (medical_record_procedures) e s (schedules).
     */
    public static function pairedSiblingsSelect(): string
    {
        $done = MedicalRecordProcedureStatus::Done->value;

        $siblings = <<<SQL
            FROM medical_record_procedures sib
            JOIN medical_records sib_mr ON sib_mr.id = sib.medical_record_id
            WHERE sib_mr.schedule_id = s.id
              AND sib_mr.deleted_at IS NULL
              AND sib.deleted_at IS NULL
              AND sib.entity_id = mrp.entity_id
              AND sib.status = '{$done}'
              AND sib.procedure_id = mrp.procedure_id
            SQL;

        return <<<SQL
            (SELECT COUNT(*) {$siblings}) AS paired_count,
            (SELECT COUNT(*) {$siblings} AND (sib.executed_at, sib.id) < (mrp.executed_at, mrp.id)) AS paired_rank
            SQL;
    }

    // ---------------------------------------------------------------------
    // Valor base
    // ---------------------------------------------------------------------

    /**
     * Somas de caixa e guia do agendamento; tabela só se nunca houve cobrança.
     *
     * @return array{0: int, 1: DoctorPayoutBaseSource, 2: list<DoctorPayoutWarning>}
     */
    private function chargedBase(object $row, ?int $tablePriceCents): array
    {
        $activeCount = (int) ($row->cash_active_count ?? 0) + (int) ($row->claim_active_count ?? 0);
        $totalCount  = (int) ($row->cash_total_count ?? 0) + (int) ($row->claim_total_count ?? 0);

        if ($activeCount > 0) {
            return [
                Money::toCents($row->cash_active_amount) + Money::toCents($row->claim_active_amount),
                DoctorPayoutBaseSource::Charged,
                [],
            ];
        }

        if ($totalCount > 0) {
            return [0, DoctorPayoutBaseSource::None, [DoctorPayoutWarning::ChargeCancelled]];
        }

        return $this->tableBase($tablePriceCents);
    }

    /**
     * @return array{0: int, 1: DoctorPayoutBaseSource, 2: list<DoctorPayoutWarning>}
     */
    private function tableBase(?int $tablePriceCents): array
    {
        return $tablePriceCents === null
            ? [0, DoctorPayoutBaseSource::None, []]
            : [$tablePriceCents, DoctorPayoutBaseSource::Table, []];
    }

    /** Receitas de caixa por agendamento (não excluídas; canceladas só contam como "existiu"). */
    private function cashTotals(string $entityId): Builder
    {
        return DB::table('financial_cash_entries')
            ->select('reference_id as schedule_id')
            ->selectRaw("COALESCE(SUM(amount) FILTER (WHERE status <> 'cancelled'), 0) AS active_amount")
            ->selectRaw("COUNT(*) FILTER (WHERE status <> 'cancelled') AS active_count")
            ->selectRaw('COUNT(*) AS total_count')
            ->where('entity_id', $entityId)
            ->where('reference_type', CashEntryReferenceType::Schedule->value)
            ->where('type', FinancialEntryType::Income->value)
            ->whereNull('deleted_at')
            ->groupBy('reference_id');
    }

    /** Guias por agendamento: paga → recebido; rascunho/enviada → valor − glosa. */
    private function claimTotals(string $entityId): Builder
    {
        $active = self::CLAIM_ACTIVE_STATUSES;

        return DB::table('billing_claims')
            ->select('schedule_id')
            ->selectRaw(<<<SQL
                COALESCE(SUM(
                    CASE WHEN status = 'paid' THEN paid_amount
                         ELSE GREATEST(amount - glosa_amount, 0) END
                ) FILTER (WHERE status IN ({$active})), 0) AS active_amount
                SQL)
            ->selectRaw("COUNT(*) FILTER (WHERE status IN ({$active})) AS active_count")
            ->selectRaw('COUNT(*) AS total_count')
            ->where('entity_id', $entityId)
            ->whereNotNull('schedule_id')
            ->whereNull('deleted_at')
            ->groupBy('schedule_id');
    }

    /** Subconsulta "o ato foi fechado no regime anterior (produção)" — fechamento final. */
    private function legacyPayoutItem(Builder $query, string $entityId, DoctorPayoutSourceType $type, string $column): Builder
    {
        return $this->activePayoutItem($query, $entityId, $type, $column)
            ->where('dpi.basis', DoctorPayoutBasis::Production->value);
    }

    /** Subconsulta "o ato já está num fechamento válido". */
    private function activePayoutItem(Builder $query, string $entityId, DoctorPayoutSourceType $type, string $column): Builder
    {
        return $query->selectRaw('1')
            ->from('doctor_payout_items as dpi')
            ->where('dpi.entity_id', $entityId)
            ->where('dpi.source_type', $type->value)
            ->whereRaw("dpi.source_id = {$column}::text")
            ->whereNull('dpi.voided_at');
    }

    // ---------------------------------------------------------------------
    // Convênio / pagador / preço
    // ---------------------------------------------------------------------

    /** @return list<string> */
    private function covenantIds(Collection ...$sets): array
    {
        return collect($sets)
            ->flatMap(fn (Collection $rows) => $rows->pluck('covenant_id'))
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Convênios (inclusive excluídos: o atendimento continua com o convênio que tinha).
     *
     * @param list<string> $ids
     *
     * @return array<string, object>
     */
    private function covenants(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('covenants')
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'ans_registry'])
            ->keyBy('id')
            ->all();
    }

    /**
     * Particular = sem convênio ou convênio sem registro ANS (só dígitos contam),
     * a mesma regra do faturamento (ResolveTissOperatorForCovenantAction::isEligible).
     *
     * @param array<string, object> $covenants
     *
     * @return array{0: ?string, 1: bool}
     */
    private function payer(array $covenants, ?string $covenantId): array
    {
        if ($covenantId === null || ! isset($covenants[$covenantId])) {
            return [null, true];
        }

        $covenant = $covenants[$covenantId];
        $ans      = preg_replace('/\D/', '', (string) ($covenant->ans_registry ?? ''));

        return [(string) $covenant->name, $ans === ''];
    }

    private function globalParticularCovenantId(): ?string
    {
        $id = DB::table('covenants')
            ->whereNull('entity_id')
            ->whereNull('deleted_at')
            ->whereRaw('upper(name) = ?', ['PARTICULAR'])
            ->orderBy('created_at')
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Preço de tabela só dos pares convênio × procedimento que aparecem no
     * período (não a tabela inteira); linha da clínica vence a global.
     *
     * @return array<string, int> "covenant:procedure" => centavos
     */
    private function prices(string $entityId, Collection $schedules, Collection $procedures, ?string $particularId): array
    {
        $pairs = $schedules
            ->map(fn (object $row) => [$row->covenant_id ?? $particularId, $row->visit_procedure_id])
            ->merge($procedures->map(fn (object $row) => [$row->covenant_id ?? $particularId, $row->procedure_id]))
            ->filter(fn (array $pair) => $pair[0] !== null && $pair[1] !== null);

        if ($pairs->isEmpty()) {
            return [];
        }

        return DB::table('procedure_prices')
            ->where('active', true)
            ->whereNull('deleted_at')
            ->where(fn (Builder $q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->whereIn('covenant_id', $pairs->pluck(0)->unique()->values()->all())
            ->whereIn('procedure_id', $pairs->pluck(1)->unique()->values()->all())
            ->get(['covenant_id', 'procedure_id', 'price', 'entity_id'])
            ->sortBy(fn (object $row) => $row->entity_id === null ? 0 : 1) // clínica por último → vence
            ->mapWithKeys(fn (object $row) => [$row->covenant_id . ':' . $row->procedure_id => Money::toCents($row->price)])
            ->all();
    }

    /** @param array<string, int> $prices */
    private function priceFor(array $prices, ?string $covenantId, ?string $procedureId): ?int
    {
        if ($covenantId === null || $procedureId === null) {
            return null;
        }

        return $prices[$covenantId . ':' . $procedureId] ?? null;
    }
}
