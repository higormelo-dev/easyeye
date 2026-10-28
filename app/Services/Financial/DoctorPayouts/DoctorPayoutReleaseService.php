<?php

declare(strict_types=1);

namespace App\Services\Financial\DoctorPayouts;

use App\DTOs\DoctorPayout\{PayoutItemData, ReceiptTraceData};
use App\Enums\DoctorPayout\{DoctorPayoutBaseSource, DoctorPayoutBasis, DoctorPayoutBeneficiaryRole, DoctorPayoutCalculation, DoctorPayoutServiceType, DoctorPayoutSourceType, DoctorPayoutStatus, DoctorPayoutWarning};
use App\Models\DoctorPayoutRule;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Regime por RECEBIMENTO (decisões de 2026-09-29): o repasse de cada ato é
 * liberado em parcelas, POR BENEFICIÁRIO, sobre o que a clínica recebeu.
 *
 *   líquido(D) = recebido acumulado do ato até D − deduções (cartão, imposto,
 *                taxa administrativa — sobre o bruto, taxa da data do recebimento)
 *   grupo(D)   = % da regra sobre o líquido; a clínica fica com o restante
 *   parte(D)   = divisão do grupo entre os participantes (DoctorPayoutSplit):
 *                executor (médico do item) e médicos fixos (ex.: líder)
 *   parcela    = parte(D) do beneficiário − já liberado a ele no ato
 *
 *  - regra de valor fixo: só o executor, proporcional ao recebido ÷ líquido
 *    esperado do atendimento (faturado − glosa), sem deduções;
 *  - acumulado sempre: as parcelas somam exatamente o devido final;
 *  - complemento = parcela nova; estorno/dedução maior/ato que deixou de valer
 *    = parcela NEGATIVA no próximo fechamento do beneficiário;
 *  - regra e divisão CONGELADAS por ato no 1º fechamento válido de qualquer
 *    beneficiário (todos dividem o mesmo grupo); sem fechamento, a regra
 *    vigente na data do atendimento;
 *  - procedimentos iguais pareados (OD/OE) dividem o recebido do atendimento
 *    (Money::allocate, ordem executed_at, id);
 *  - já liberado é por ATO E BENEFICIÁRIO: parcela de ato que deixou de valer
 *    para ele volta como estorno; nunca o mesmo recebido duas vezes.
 *
 * Beneficiário: os atos do próprio médico (executor) + atos de outros médicos
 * cuja regra (vigente ou congelada) o inclui como participante fixo.
 * Ato do médico sem recebimento fica "aguardando" (previsão, não fecha).
 * Janela: atos realizados nos LOOKBACK_MONTHS meses até o fim do período.
 */
final class DoctorPayoutReleaseService
{
    public const LOOKBACK_MONTHS = 24;

    private const CHUNK = 1000;

    public function __construct(
        private readonly DoctorPayoutProductionService $production,
        private readonly DoctorPayoutReceiptTracer $tracer,
        private readonly DoctorPayoutRuleService $rules,
        private readonly DoctorPayoutRuleResolver $resolver,
        private readonly DoctorPayoutDeductionService $deductions,
        private readonly DoctorPayoutOptions $options,
    ) {
    }

    /**
     * Parcelas a liberar até o fim do período e atos aguardando recebimento.
     *
     * @return array{releases: Collection<int, PayoutItemData>, awaiting: Collection<int, PayoutItemData>}
     */
    public function compute(string $entityId, string $doctorId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $windowFrom = $to->subMonthsNoOverflow(self::LOOKBACK_MONTHS)->startOfDay();

        [$acts, $participantsByRule] = $this->candidateActs($entityId, $doctorId, $windowFrom, $to);

        $keys       = array_keys($acts);
        $traces     = $this->tracer->trace($entityId, $keys, $to, withReceipts: true);
        $frozen     = $this->frozenSplits($entityId, $keys);
        $released   = $this->released($entityId, $doctorId, $keys);
        $tranches   = $this->lastTranches($entityId, $doctorId, $keys);
        $rates      = $this->deductions->rates($entityId);
        $closedTill = $this->lastClosedUntil($entityId, $doctorId);

        $releases = [];
        $awaiting = [];

        foreach ($acts as $key => $act) {
            $trace      = $traces[$key] ?? ReceiptTraceData::notLinked();
            $prior      = $released[$key] ?? null;
            $isExecutor = $act->doctorId === $doctorId;
            $split      = $frozen[$key] ?? $this->currentSplit($act, $participantsByRule);

            $receivedUnit = $trace->isLinked() ? $trace->receivedCents : 0;
            $expectedUnit = $trace->isLinked() ? $trace->expectedCents() : 0;
            $received     = $this->share($receivedUnit, $trace) + $trace->actManualCents;

            [$netUnit, $netAct, $breakdown] = $this->net($trace, $rates);
            $net                            = $this->share($netUnit, $trace) + $netAct;

            [$due, $role, $sharePercentage, $group] = $this->dueFor($split, $doctorId, $isExecutor, $net, $receivedUnit, $expectedUnit);

            if ($role === null && $prior === null) {
                continue; // ato de outro médico em que este não participa
            }

            $payout = $due - ($prior['payout'] ?? 0);
            $base   = $received - ($prior['base'] ?? 0);

            // Líquido, deduções e divisão só existem na regra percentual (a de
            // valor fixo não desconta taxas): o retrato não sugere o contrário.
            $isPercentage = $split['rule']['calculation'] === DoctorPayoutCalculation::Percentage;

            if ($payout !== 0 || $base !== 0) {
                $releases[] = $act
                    ->withoutWarnings(DoctorPayoutWarning::NoRule, DoctorPayoutWarning::NoBaseValue)
                    ->withRelease(
                        tranche: ($tranches[$key] ?? 0) + 1,
                        baseCents: $base,
                        payoutCents: $payout,
                        receivedCents: $received,
                        expectedCents: $this->share($expectedUnit, $trace),
                        releasedBeforeCents: $prior['payout'] ?? 0,
                        receiptsUntil: $to->toDateString(),
                        receipts: $trace->receipts,
                        rule: $split['rule'],
                        extraWarnings: $this->releaseWarnings($split['rule'], $trace, $payout, $base, $prior['base'] ?? 0, $closedTill, $isExecutor),
                    )
                    ->withSplit(
                        $role ?? ($isExecutor ? DoctorPayoutBeneficiaryRole::Executor : DoctorPayoutBeneficiaryRole::Doctor),
                        $sharePercentage,
                        $isPercentage ? $net : null,
                        $isPercentage ? $received - $net : 0,
                        $isPercentage ? [
                            'group_percentage' => $split['rule']['percentage'],
                            'participants'     => $split['participants'],
                            'group_cents'      => $group,
                            'net_cents'        => $net,
                            'deductions'       => $breakdown,
                        ] : [],
                    );

                continue;
            }

            if ($isExecutor && $prior === null && $received === 0 && $this->awaits($act, $trace, $from, $to)) {
                $awaiting[] = $act->asAwaiting($this->share($expectedUnit, $trace));
            }
        }

        $releases = [...$releases, ...$this->removedActReversals($entityId, $doctorId, $windowFrom, $to, $keys)];

        return [
            'releases' => collect($releases)->sortBy([
                fn (PayoutItemData $a, PayoutItemData $b) => $a->performedAt <=> $b->performedAt,
                fn (PayoutItemData $a, PayoutItemData $b) => strcmp($a->key(), $b->key()),
            ])->values(),
            'awaiting' => collect($awaiting),
        ];
    }

    /**
     * Fim do último fechamento válido (fechado/pago) do médico no regime por
     * recebimento — as parcelas são acumuladas até ele.
     */
    public function lastClosedUntil(string $entityId, string $doctorId): ?string
    {
        $last = DB::table('doctor_payouts')
            ->where('entity_id', $entityId)
            ->where('doctor_id', $doctorId)
            ->where('basis', DoctorPayoutBasis::Receipt->value)
            ->whereIn('status', DoctorPayoutStatus::valid())
            ->max('period_end');

        return $last === null ? null : substr((string) $last, 0, 10);
    }

    /**
     * Atos em que o médico pode ter parte: os dele (executor) e, se ele é
     * participante fixo de alguma regra (vigente) ou já recebeu parte de ato
     * de outro médico, os atos dos demais médicos (a regra de cada ato diz se
     * ele participa). Regras carregadas com os participantes. Uma consulta
     * por fonte para todos os executores — nunca uma por médico da clínica.
     *
     * @return array{0: array<string, PayoutItemData>, 1: array<string, list<array{role: string, doctor_id: ?string, percentage: string}>>}
     */
    private function candidateActs(string $entityId, string $doctorId, CarbonImmutable $windowFrom, CarbonImmutable $to): array
    {
        $participantsByRule = [];
        $acts               = [];

        $doctors = [$doctorId];

        if ($this->participatesAnywhere($entityId, $doctorId)) {
            foreach ($this->options->doctors($entityId) as $doctor) {
                if ($doctor['id'] !== $doctorId) {
                    $doctors[] = $doctor['id'];
                }
            }
        }

        $rules = $this->rules->activeRulesForDoctors($entityId, $doctors)->load('participants');

        foreach ($rules as $rule) {
            $participantsByRule[(string) $rule->id] = $this->participantsOf($rule);
        }

        // Os atos do próprio médico entram primeiro: chave repetida entre
        // médicos (exame do mesmo paciente/tipo/dia) fica com a dele.
        [$own, $others] = $this->resolver
            ->resolve($rules, $this->production->actsOf($entityId, $doctors, $windowFrom, $to))
            ->partition(fn (PayoutItemData $act) => $act->doctorId === $doctorId);

        foreach ([...$own, ...$others] as $act) {
            $acts[$act->key()] ??= $act;
        }

        return [$acts, $participantsByRule];
    }

    /** Participante fixo de regra ativa, ou já recebeu parte de ato de outro médico. */
    private function participatesAnywhere(string $entityId, string $doctorId): bool
    {
        $inRules = DB::table('doctor_payout_rule_participants as p')
            ->join('doctor_payout_rules as r', 'r.id', '=', 'p.doctor_payout_rule_id')
            ->where('p.entity_id', $entityId)
            ->where('r.entity_id', $entityId)
            ->where('p.role', 'doctor')
            ->where('p.doctor_id', $doctorId)
            ->whereNull('r.deleted_at')
            ->where('r.active', true)
            ->exists();

        return $inRules || DB::table('doctor_payout_items')
            ->where('entity_id', $entityId)
            ->where('doctor_id', $doctorId)
            ->whereIn('beneficiary_role', [DoctorPayoutBeneficiaryRole::Doctor->value, DoctorPayoutBeneficiaryRole::Both->value])
            ->whereNull('voided_at')
            ->exists();
    }

    /** @return list<array{role: string, doctor_id: ?string, percentage: string}> */
    private function participantsOf(DoctorPayoutRule $rule): array
    {
        return $rule->participants
            ->map(fn ($participant) => [
                'role'       => (string) $participant->role,
                'doctor_id'  => $participant->doctor_id === null ? null : (string) $participant->doctor_id,
                'percentage' => (string) $participant->percentage,
            ])
            ->values()
            ->all();
    }

    /**
     * Regra + divisão vigentes do ato (sem fechamento): a resolvida na data do
     * atendimento, com os participantes dela (sem participantes = executor).
     *
     * @param array<string, list<array{role: string, doctor_id: ?string, percentage: string}>> $participantsByRule
     *
     * @return array{rule: array{id: ?string, calculation: ?DoctorPayoutCalculation, percentage: ?string, fixed_cents: ?int}, participants: list<array{role: string, doctor_id: ?string, percentage: string}>}
     */
    private function currentSplit(PayoutItemData $act, array $participantsByRule): array
    {
        return [
            'rule' => [
                'id'          => $act->ruleId,
                'calculation' => $act->ruleCalculation,
                'percentage'  => $act->rulePercentage,
                'fixed_cents' => $act->ruleFixedCents,
            ],
            'participants' => $participantsByRule[(string) $act->ruleId] ?? [],
        ];
    }

    /**
     * Devido acumulado do beneficiário no ato.
     *
     * @param array{rule: array{id: ?string, calculation: ?DoctorPayoutCalculation, percentage: ?string, fixed_cents: ?int}, participants: list<array{role: string, doctor_id: ?string, percentage: string}>} $split
     *
     * @return array{0: int, 1: ?DoctorPayoutBeneficiaryRole, 2: ?string, 3: ?int} devido, papel, % do grupo, valor do grupo
     */
    private function dueFor(array $split, string $doctorId, bool $isExecutor, int $net, int $receivedUnit, int $expectedUnit): array
    {
        $rule = $split['rule'];

        if ($rule['calculation'] === null) {
            return [0, $isExecutor ? DoctorPayoutBeneficiaryRole::Executor : null, null, null];
        }

        if ($rule['calculation'] === DoctorPayoutCalculation::Fixed) {
            return $isExecutor
                ? [$this->fixedDue((int) $rule['fixed_cents'], $receivedUnit, $expectedUnit), DoctorPayoutBeneficiaryRole::Executor, '100.00', null]
                : [0, null, null, null];
        }

        $group        = Money::percentageOf($net, (string) $rule['percentage']);
        $participants = $split['participants'] === [] ? DoctorPayoutSplit::EXECUTOR_ONLY : $split['participants'];
        $shares       = DoctorPayoutSplit::shares($group, $participants);

        $due   = 0;
        $cents = 0;
        $roles = [];

        foreach ($participants as $index => $participant) {
            $mine = $participant['role'] === 'executor' ? $isExecutor : $participant['doctor_id'] === $doctorId;

            if ($mine) {
                $due += $shares[$index];
                $cents += Money::toCents($participant['percentage']);
                $roles[$participant['role']] = true;
            }
        }

        $role = match (true) {
            isset($roles['executor'], $roles['doctor']) => DoctorPayoutBeneficiaryRole::Both,
            isset($roles['executor'])                   => DoctorPayoutBeneficiaryRole::Executor,
            isset($roles['doctor'])                     => DoctorPayoutBeneficiaryRole::Doctor,
            default                                     => null,
        };

        return [$due, $role, $role === null ? null : Money::fromCents($cents), $group];
    }

    /**
     * Líquido do atendimento (e do manual próprio do ato) = recebido −
     * deduções de cada recebimento, com o detalhamento das deduções.
     *
     * @param array<string, list<array{from: string, percentage: string}>> $rates
     *
     * @return array{0: int, 1: int, 2: array{card: int, tax: int, admin: int}}
     */
    private function net(ReceiptTraceData $trace, array $rates): array
    {
        $netUnit   = 0;
        $netAct    = 0;
        $breakdown = ['card' => 0, 'tax' => 0, 'admin' => 0];

        foreach ($trace->receipts as $receipt) {
            $deduction = DoctorPayoutDeductionService::forReceipt($rates, $receipt);
            $netCents  = $receipt['amount_cents'] - $deduction['total'];

            if ($receipt['kind'] === 'manual_act') {
                $netAct += $netCents;
            } else {
                $netUnit += $netCents;
            }

            foreach (['card', 'tax', 'admin'] as $part) {
                $breakdown[$part] += $deduction[$part];
            }
        }

        return [$netUnit, $netAct, $breakdown];
    }

    /** Fixo proporcional ao recebido ÷ líquido esperado do atendimento, limitado ao fixo. */
    private function fixedDue(int $fixedCents, int $receivedUnit, int $expectedUnit): int
    {
        if ($receivedUnit <= 0) {
            return 0;
        }

        if ($expectedUnit <= 0 || $receivedUnit >= $expectedUnit) {
            return $fixedCents;
        }

        return Money::proportion($fixedCents, $receivedUnit, $expectedUnit);
    }

    /** Parte do ato no atendimento (procedimentos iguais pareados dividem). */
    private function share(int $unitCents, ReceiptTraceData $trace): int
    {
        return $trace->sharedBy > 1 ? Money::allocate($unitCents, $trace->sharedBy)[$trace->position] : $unitCents;
    }

    /**
     * @param array{id: ?string, calculation: ?DoctorPayoutCalculation, percentage: ?string, fixed_cents: ?int} $rule
     *
     * @return list<DoctorPayoutWarning>
     */
    private function releaseWarnings(array $rule, ReceiptTraceData $trace, int $payout, int $base, int $priorBase, ?string $closedTill, bool $isExecutor): array
    {
        $warnings = [];

        if ($rule['calculation'] === null && $isExecutor) {
            $warnings[] = DoctorPayoutWarning::NoRule;
        }

        if ($payout < 0 || $base < 0) {
            $warnings[] = DoctorPayoutWarning::NegativeAdjustment;
        }

        // Recebido com data dentro de um período já fechado do médico que ainda
        // não tinha sido liberado (lançado ou baixado depois do fechamento).
        if ($closedTill !== null) {
            $inClosedPeriods = array_sum(array_map(
                fn (array $receipt) => $receipt['date'] <= $closedTill ? $receipt['amount_cents'] : 0,
                $trace->receipts,
            ));

            if ($this->share($inClosedPeriods, $trace) > $priorBase) {
                $warnings[] = DoctorPayoutWarning::LateReceipt;
            }
        }

        if ($trace->sharedBy > 1) {
            $warnings[] = DoctorPayoutWarning::SplitCharge;
        }

        return $warnings;
    }

    /**
     * Quais atos sem recebimento aparecem como "aguardando": os que ainda têm
     * valor a receber (qualquer data da janela) e toda a produção do período
     * (sem cobrança, glosado, sem cobrança própria) — nada some da tela.
     */
    private function awaits(PayoutItemData $act, ReceiptTraceData $trace, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        if ($trace->isLinked() && $trace->openCents > 0) {
            return true;
        }

        return $act->performedAt->betweenIncluded($from->startOfDay(), $to->endOfDay());
    }

    /**
     * Regra + divisão CONGELADAS por ato: as do 1º fechamento válido (regime
     * por recebimento) de QUALQUER beneficiário — todos dividem o mesmo grupo.
     *
     * @param list<string> $keys
     *
     * @return array<string, array{rule: array{id: ?string, calculation: ?DoctorPayoutCalculation, percentage: ?string, fixed_cents: ?int}, participants: list<array{role: string, doctor_id: ?string, percentage: string}>}>
     */
    private function frozenSplits(string $entityId, array $keys): array
    {
        $frozen = [];

        foreach ($this->keysByType($keys) as $type => $ids) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                DB::table('doctor_payout_items')
                    ->where('entity_id', $entityId)
                    ->where('basis', DoctorPayoutBasis::Receipt->value)
                    ->whereNull('voided_at')
                    ->where('source_type', $type)
                    ->whereIn('source_id', $chunk)
                    ->selectRaw('DISTINCT ON (source_id) source_id, doctor_payout_rule_id, rule_calculation, rule_percentage, rule_fixed_amount, split')
                    ->orderBy('source_id')
                    ->orderBy('created_at')
                    ->orderBy('tranche')
                    ->get()
                    ->each(function (object $row) use ($type, &$frozen): void {
                        $split = $row->split === null ? [] : (array) json_decode((string) $row->split, true);

                        $frozen[$type . ':' . $row->source_id] = [
                            'rule'         => $this->frozenRule($row),
                            'participants' => array_values((array) ($split['participants'] ?? [])),
                        ];
                    });
            }
        }

        return $frozen;
    }

    /**
     * Já liberado por ATO a ESTE beneficiário (parcelas válidas do regime por
     * recebimento): somas de repasse e de base.
     *
     * @param list<string> $keys
     *
     * @return array<string, array{payout: int, base: int}>
     */
    private function released(string $entityId, string $doctorId, array $keys): array
    {
        $released = [];

        foreach ($this->keysByType($keys) as $type => $ids) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $this->beneficiaryItems($entityId, $doctorId)
                    ->where('i.source_type', $type)
                    ->whereIn('i.source_id', $chunk)
                    ->groupBy('i.source_id')
                    ->select('i.source_id')
                    ->selectRaw('SUM(i.payout_amount) AS payout, SUM(i.base_amount) AS base')
                    ->get()
                    ->each(function (object $row) use ($type, &$released): void {
                        $released[$type . ':' . $row->source_id] = [
                            'payout' => Money::toCents($row->payout),
                            'base'   => Money::toCents($row->base),
                        ];
                    });
            }
        }

        return $released;
    }

    /**
     * Última parcela válida de cada ato para ESTE beneficiário (qualquer
     * regime) — o número da próxima (índice único ato + beneficiário + parcela).
     *
     * @param list<string> $keys
     *
     * @return array<string, int>
     */
    private function lastTranches(string $entityId, string $doctorId, array $keys): array
    {
        $last = [];

        foreach ($this->keysByType($keys) as $type => $ids) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                DB::table('doctor_payout_items')
                    ->where('entity_id', $entityId)
                    ->where('doctor_id', $doctorId)
                    ->whereNull('voided_at')
                    ->where('source_type', $type)
                    ->whereIn('source_id', $chunk)
                    ->groupBy('source_id')
                    ->select('source_id')
                    ->selectRaw('MAX(tranche) AS tranche')
                    ->get()
                    ->each(function (object $row) use ($type, &$last): void {
                        $last[$type . ':' . $row->source_id] = (int) $row->tranche;
                    });
            }
        }

        return $last;
    }

    /**
     * Estorno do que foi liberado a este beneficiário por atos da janela que
     * não valem mais para ele (não estão entre os candidatos): procedimento
     * cancelado, prontuário excluído, atendimento desmarcado, médico corrigido.
     *
     * @param list<string> $actKeys atos candidatos do médico
     *
     * @return list<PayoutItemData>
     */
    private function removedActReversals(string $entityId, string $doctorId, CarbonImmutable $windowFrom, CarbonImmutable $to, array $actKeys): array
    {
        $current = array_flip($actKeys);

        $groups = $this->beneficiaryItems($entityId, $doctorId)
            ->whereBetween('i.performed_at', [$windowFrom->format('Y-m-d H:i:s'), $to->endOfDay()->format('Y-m-d H:i:s')])
            ->orderBy('i.source_type')
            ->orderBy('i.source_id')
            ->orderBy('i.tranche')
            ->get(['i.*'])
            ->groupBy(fn (object $row) => $row->source_type . ':' . $row->source_id)
            ->reject(fn (Collection $rows, string $key) => isset($current[$key]));

        if ($groups->isEmpty()) {
            return [];
        }

        $tranches = $this->lastTranches($entityId, $doctorId, $groups->keys()->all());

        return $groups->map(function (Collection $rows, string $key) use ($doctorId, $to, $tranches): ?PayoutItemData {
            $payout = (int) $rows->sum(fn (object $row) => Money::toCents($row->payout_amount));
            $base   = (int) $rows->sum(fn (object $row) => Money::toCents($row->base_amount));

            if ($payout === 0 && $base === 0) {
                return null;
            }

            $last = $rows->last();

            return (new PayoutItemData(
                sourceType: DoctorPayoutSourceType::from((string) $last->source_type),
                sourceId: (string) $last->source_id,
                serviceType: DoctorPayoutServiceType::from((string) $last->service_type),
                performedAt: CarbonImmutable::parse($last->performed_at),
                doctorId: $doctorId,
                patientId: $last->patient_id,
                covenantId: $last->covenant_id,
                covenantName: $last->covenant_name,
                isParticular: (bool) $last->is_particular,
                description: (string) $last->description,
                visitTypeId: $last->visit_type_id,
                procedureId: $last->procedure_id,
                examTypeId: $last->exam_type_id,
                baseCents: 0,
                baseSource: DoctorPayoutBaseSource::Received,
            ))->withRelease(
                tranche: ($tranches[$key] ?? 0) + 1,
                baseCents: -$base,
                payoutCents: -$payout,
                receivedCents: 0,
                expectedCents: 0,
                releasedBeforeCents: $payout,
                receiptsUntil: $to->toDateString(),
                receipts: [],
                rule: $this->frozenRule($rows->first()),
                extraWarnings: [DoctorPayoutWarning::ActRemoved],
            )->withSplit(
                DoctorPayoutBeneficiaryRole::tryFrom((string) $last->beneficiary_role) ?? DoctorPayoutBeneficiaryRole::Executor,
                $last->share_percentage === null ? null : (string) $last->share_percentage,
                0,
                0,
                [],
            );
        })->filter()->values()->all();
    }

    /** Parcelas válidas do regime por recebimento DESTE beneficiário. */
    private function beneficiaryItems(string $entityId, string $doctorId): Builder
    {
        return DB::table('doctor_payout_items as i')
            ->where('i.entity_id', $entityId)
            ->where('i.doctor_id', $doctorId)
            ->where('i.basis', DoctorPayoutBasis::Receipt->value)
            ->whereNull('i.voided_at');
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, list<string>> tipo → ids
     */
    private function keysByType(array $keys): array
    {
        $byType = [];

        foreach (array_unique($keys) as $key) {
            [$type, $id]     = explode(':', $key, 2);
            $byType[$type][] = $id;
        }

        return $byType;
    }

    /**
     * @return array{id: ?string, calculation: ?DoctorPayoutCalculation, percentage: ?string, fixed_cents: ?int}
     */
    private function frozenRule(object $row): array
    {
        return [
            'id'          => $row->doctor_payout_rule_id,
            'calculation' => DoctorPayoutCalculation::tryFrom((string) $row->rule_calculation),
            'percentage'  => $row->rule_percentage === null ? null : (string) $row->rule_percentage,
            'fixed_cents' => $row->rule_fixed_amount === null ? null : Money::toCents($row->rule_fixed_amount),
        ];
    }
}
