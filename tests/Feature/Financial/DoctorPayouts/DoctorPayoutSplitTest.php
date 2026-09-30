<?php

/*
 * Repasse médico E4 — deduções antes de dividir e divisão clínica → grupo →
 * participantes. Exemplo do sócio: recebido 1.000 → clínica 40% = 400 →
 * grupo 600 → líder 60% = 360, executor 40% = 240 (um fechamento por
 * médico). Deduções: cada uma sobre o bruto, sem cascata, taxa da data do
 * recebimento; cartão só na parte paga com cartão no balcão.
 */

use App\DTOs\DoctorPayout\PayoutItemData;
use App\Enums\DoctorPayout\DoctorPayoutBeneficiaryRole;
use App\Enums\ScheduleSituation;
use App\Models\{Covenant, Doctor, DoctorPayout, DoctorPayoutDeductionRate, DoctorPayoutItem, DoctorPayoutRule, DoctorPayoutRuleParticipant, Entity, FinancialCashEntry, Patient, Schedule};
use App\Services\Financial\DoctorPayouts\{DoctorPayoutCalculator, DoctorPayoutClosingService, DoctorPayoutPresenter, DoctorPayoutSplit};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->adminEu  = actingAsFinancialEntityUser($this->entity);
    $this->executor = createDoctorForEntity($this->entity);
    $this->leader   = createDoctorForEntity($this->entity);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'ans_registry' => '326305']);
    $this->patient  = Patient::factory()->create(['entity_id' => $this->entity->id, 'covenant_id' => $this->covenant->id]);

    // Regra percentual: 60% do líquido para o grupo (clínica fica com 40%);
    // grupo dividido entre o líder (60%) e o executor (40%).
    $this->rule = DoctorPayoutRule::query()->create([
        'entity_id'   => $this->entity->id, 'service_type' => 'consultation', 'payer_scope' => 'any',
        'calculation' => 'percentage', 'percentage' => '60.00', 'active' => true,
    ]);
    splitParticipants($this, [['doctor', $this->leader->id, '60.00'], ['executor', null, '40.00']]);

    $this->calculator = app(DoctorPayoutCalculator::class);
    $this->closing    = app(DoctorPayoutClosingService::class);
});

/** @param list<array{0: string, 1: ?string, 2: string}> $participants */
function splitParticipants($test, array $participants): void
{
    DoctorPayoutRuleParticipant::query()->where('doctor_payout_rule_id', $test->rule->id)->delete();

    foreach ($participants as $order => [$role, $doctorId, $percentage]) {
        DoctorPayoutRuleParticipant::query()->create([
            'entity_id' => $test->entity->id, 'doctor_payout_rule_id' => $test->rule->id, 'role' => $role,
            'doctor_id' => $doctorId, 'percentage' => $percentage, 'sort_order' => $order,
        ]);
    }
}

function splitSchedule($test, float $amount, string $date = '2026-06-10', array $entry = [], ?Doctor $doctor = null): Schedule
{
    static $slot = 0;
    $slot++;

    $schedule = Schedule::create([
        'entity_id'   => $test->entity->id, 'doctor_id' => ($doctor ?? $test->executor)->id, 'patient_id' => $test->patient->id,
        'covenant_id' => $test->covenant->id, 'full_name' => 'Paciente', 'situation' => ScheduleSituation::Attended->value,
        'date_time'   => sprintf('2026-06-10 %02d:%02d:00', 7 + intdiv($slot % 600, 60), $slot % 60), 'active' => true,
    ]);

    FinancialCashEntry::query()->create(array_merge([
        'entity_id'      => $test->entity->id, 'entry_date' => $date, 'description' => 'Balcão', 'type' => 'income',
        'status'         => 'paid', 'amount' => $amount, 'reference_type' => 'schedule', 'reference_id' => $schedule->id,
        'payment_method' => 'transfer', 'active' => true,
    ], $entry));

    return $schedule;
}

function splitRelease($test, Doctor $doctor, Schedule $schedule, string $from = '2026-06-01', string $to = '2026-06-30'): ?PayoutItemData
{
    return $test->calculator->pending($test->entity->id, $doctor->id, CarbonImmutable::parse($from), CarbonImmutable::parse($to))
        ->first(fn (PayoutItemData $item) => $item->key() === 'schedule:' . $schedule->id);
}

function splitClose($test, Doctor $doctor, string $from = '2026-06-01', string $to = '2026-06-30'): DoctorPayout
{
    $totals = DoctorPayoutCalculator::totals($test->calculator->pending($test->entity->id, $doctor->id, CarbonImmutable::parse($from), CarbonImmutable::parse($to)));

    return $test->closing->close($test->entity->id, $doctor->id, [
        'period_start'           => $from, 'period_end' => $to,
        'expected_count'         => $totals['count'],
        'expected_charged_cents' => $totals['charged_cents'],
        'expected_payout_cents'  => $totals['payout_cents'],
    ], null);
}

function splitRate($test, string $kind, string $percentage, string $from = '2026-01-01'): void
{
    DoctorPayoutDeductionRate::query()->create([
        'entity_id' => $test->entity->id, 'kind' => $kind, 'percentage' => $percentage, 'valid_from' => $from,
    ]);
}

describe('divisão clínica → grupo → participantes', function () {
    it('exemplo do sócio: recebido 1.000 → clínica 400, líder 360, executor 240 — um fechamento por médico', function () {
        $schedule = splitSchedule($this, 1000.00);

        $executorPart = splitRelease($this, $this->executor, $schedule);
        $leaderPart   = splitRelease($this, $this->leader, $schedule);

        expect($executorPart->payoutCents)->toBe(24000)
            ->and($executorPart->beneficiaryRole)->toBe(DoctorPayoutBeneficiaryRole::Executor)
            ->and($executorPart->sharePercentage)->toBe('40.00')
            ->and($executorPart->split['group_cents'])->toBe(60000)
            ->and($leaderPart->payoutCents)->toBe(36000)
            ->and($leaderPart->beneficiaryRole)->toBe(DoctorPayoutBeneficiaryRole::Doctor);

        $executorClosing = splitClose($this, $this->executor);
        $leaderClosing   = splitClose($this, $this->leader);

        expect((string) $executorClosing->total_amount)->toBe('240.00')
            ->and((string) $leaderClosing->total_amount)->toBe('360.00');

        $items = DoctorPayoutItem::query()->where('source_id', $schedule->id)->whereNull('voided_at')->get();
        expect($items->pluck('doctor_id')->sort()->values()->all())->toBe(collect([$this->executor->id, $this->leader->id])->sort()->values()->all())
            ->and($items->sum(fn ($item) => (float) $item->payout_amount))->toEqual(600.0); // clínica fica com 400

        // Nada mais a liberar para nenhum dos dois.
        expect(splitRelease($this, $this->executor, $schedule))->toBeNull()
            ->and(splitRelease($this, $this->leader, $schedule))->toBeNull();
    });

    it('resto de centavos vai para a maior parte (empate: a primeira) e a soma fecha com o grupo', function () {
        expect(DoctorPayoutSplit::shares(10001, [
            ['role' => 'executor', 'doctor_id' => null, 'percentage' => '50.00'],
            ['role' => 'doctor', 'doctor_id' => 'x', 'percentage' => '50.00'],
        ]))->toBe([5000, 5001]);

        splitParticipants($this, [['executor', null, '50.00'], ['doctor', $this->leader->id, '50.00']]);
        $schedule = splitSchedule($this, 166.69); // grupo = 60% de 166,69 = 100,01

        $executor = splitRelease($this, $this->executor, $schedule)->payoutCents;
        $leader   = splitRelease($this, $this->leader, $schedule)->payoutCents;

        expect($executor + $leader)->toBe(10001);
    });

    it('divisão congelada no 1º fechamento do ato: mudar os participantes depois não muda a parte do outro', function () {
        $schedule = splitSchedule($this, 1000.00);
        splitClose($this, $this->executor); // congela 60% grupo, líder 60 / executor 40

        splitParticipants($this, [['doctor', $this->leader->id, '50.00'], ['executor', null, '50.00']]);

        expect(splitRelease($this, $this->leader, $schedule)->payoutCents)->toBe(36000);
    });

    it('regra de valor fixo: só o executor, sem deduções', function () {
        $this->rule->update(['calculation' => 'fixed', 'percentage' => null, 'fixed_amount' => '80.00']);
        DoctorPayoutRuleParticipant::query()->delete();
        splitRate($this, 'tax', '10.00');

        $schedule = splitSchedule($this, 500.00);

        expect(splitRelease($this, $this->executor, $schedule)->payoutCents)->toBe(8000)
            ->and(splitRelease($this, $this->leader, $schedule))->toBeNull();

        // Sem líquido/deduções/divisão no retrato: o fixo não desconta taxas.
        $release = splitRelease($this, $this->executor, $schedule);
        expect($release->netCents)->toBeNull()
            ->and($release->deductionsCents)->toBe(0)
            ->and($release->split)->toBe([]);

        splitClose($this, $this->executor);
        $item = DoctorPayoutItem::query()->where('source_id', $schedule->id)->sole();

        expect($item->net_amount)->toBeNull()
            ->and((string) $item->deductions_amount)->toBe('0.00')
            ->and($item->split)->toBeNull()
            ->and((string) $item->payout_amount)->toBe('80.00');
    });
});

describe('participante fixo com vários executores', function () {
    it('recebe a parte dos atos de cada executor; cada executor só a própria', function () {
        $other  = createDoctorForEntity($this->entity);
        $mine   = splitSchedule($this, 1000.00);
        $theirs = splitSchedule($this, 500.00, doctor: $other);

        // 500 → grupo 300 → líder 180, executor (outro médico) 120.
        expect(splitRelease($this, $this->leader, $mine)->payoutCents)->toBe(36000)
            ->and(splitRelease($this, $this->leader, $theirs)->payoutCents)->toBe(18000)
            ->and(splitRelease($this, $other, $theirs)->payoutCents)->toBe(12000)
            ->and(splitRelease($this, $other, $mine))->toBeNull()
            ->and(splitRelease($this, $this->executor, $theirs))->toBeNull();
    });

    it('a apuração do participante não faz uma consulta por médico da clínica', function () {
        splitSchedule($this, 1000.00);

        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->calculator->pending($this->entity->id, $this->leader->id, CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30'));
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $withTwoDoctors = $queries();

        foreach (range(1, 5) as $ignored) {
            createDoctorForEntity($this->entity);
        }

        expect($queries())->toBe($withTwoDoctors);
    });
});

describe('demonstrativo e planilha do médico (E6)', function () {
    it('PDF e planilha mostram o papel, a % do grupo, o líquido e as deduções da parcela do médico', function () {
        splitRate($this, 'tax', '10.00');
        $schedule = splitSchedule($this, 1000.00); // líquido 900 → grupo 540 → líder 60% = 324

        $payout    = splitClose($this, $this->leader);
        $presenter = app(DoctorPayoutPresenter::class);
        $statement = $presenter->statement($payout, forDoctor: true);
        $row       = $statement['groups'][0]['items'][0];
        $brl       = fn (float $value) => Number::currency($value, 'BRL', app()->getLocale());

        expect($row['key'])->toBe('schedule:' . $schedule->id)
            ->and($row['payout'])->toBe(324.0)
            ->and($presenter->splitLines($row))->toBe([
                __('financial_doctor_payouts.split_role_line', ['role' => __('financial_doctor_payouts.beneficiary_roles.doctor'), 'value' => '60']),
                __('financial_doctor_payouts.split_net_line', ['net' => $brl(900), 'deductions' => $brl(100)]),
            ]);

        $html = view('pdf.doctor_payout_statement', [
            'entity' => $this->entity, 'statement' => $statement, 'presenter' => $presenter,
            'locale' => app()->getLocale(), 'generatedAt' => now(),
        ])->render();

        foreach ($presenter->splitLines($row) as $line) {
            expect($html)->toContain(e($line));
        }

        [$header, $line] = $presenter->exportRows($statement['groups'][0]['items']);
        $cells           = array_combine($header, $line);
        $column          = fn (string $key) => $cells[__("financial_doctor_payouts.export_columns.{$key}")];

        expect($column('beneficiary_role'))->toBe(__('financial_doctor_payouts.beneficiary_roles.doctor'))
            ->and($column('share_percentage'))->toBe(60.0)
            ->and($column('net'))->toBe(900.0)
            ->and($column('deductions'))->toBe(100.0)
            ->and($column('payout'))->toBe(324.0);
    });

    it('executor com o grupo inteiro e sem dedução: nenhuma linha extra (nada a explicar)', function () {
        DoctorPayoutRuleParticipant::query()->delete();
        splitSchedule($this, 1000.00);

        $presenter = app(DoctorPayoutPresenter::class);
        $row       = $presenter->statement(splitClose($this, $this->executor))['groups'][0]['items'][0];

        expect($row['beneficiary_role'])->toBe('executor')
            ->and($row['share_percentage'])->toBe(100.0)
            ->and($presenter->splitLines($row))->toBe([]);
    });
});

describe('deduções antes de dividir', function () {
    beforeEach(function () {
        DoctorPayoutRuleParticipant::query()->delete(); // executor com o grupo inteiro (60%)
        splitRate($this, 'tax', '10.00');
        splitRate($this, 'admin', '5.00');
        splitRate($this, 'card_credit', '3.00');
        splitRate($this, 'card_debit', '2.00');
    });

    it('cada dedução sobre o bruto (sem cascata); cartão só na parte paga com cartão', function () {
        // 1.000 = 600 no crédito + 400 em dinheiro: cartão 18, imposto 100, adm. 50 → líquido 832
        $schedule = splitSchedule($this, 1000.00, entry: ['payment_method' => 'credit_cash', 'amount_credit' => 600, 'amount_cash' => 400]);

        $release = splitRelease($this, $this->executor, $schedule);

        expect($release->netCents)->toBe(83200)
            ->and($release->deductionsCents)->toBe(16800)
            ->and($release->split['deductions'])->toBe(['card' => 1800, 'tax' => 10000, 'admin' => 5000])
            ->and($release->payoutCents)->toBe(49920); // 60% de 832
    });

    it('taxa vigente na data de cada recebimento', function () {
        splitRate($this, 'tax', '12.00', '2026-07-01');

        $june = splitSchedule($this, 100.00, '2026-06-20');
        $july = splitSchedule($this, 100.00, '2026-07-10');

        // junho: 100 − 10 imposto − 5 adm. = 85 → 51; julho: 100 − 12 − 5 = 83 → 49,80
        expect(splitRelease($this, $this->executor, $june, '2026-06-01', '2026-07-31')->payoutCents)->toBe(5100)
            ->and(splitRelease($this, $this->executor, $july, '2026-06-01', '2026-07-31')->payoutCents)->toBe(4980);
    });
});

describe('cadastro (HTTP)', function () {
    it('participantes: soma 100%, um executor, médico da clínica, só em regra percentual', function (array $payload, string $field) {
        $this->post(route('panel.financial.doctor-payouts.rules.store'), [
            'service_type' => 'procedure', 'payer_scope' => 'any', 'calculation' => 'percentage', 'percentage' => '60',
            ...$payload,
        ])->assertSessionHasErrors($field);
    })->with([
        'soma diferente de 100' => [['participants' => [['role' => 'executor', 'percentage' => '90']]], 'participants'],
        'dois executores'       => [['participants' => [['role' => 'executor', 'percentage' => '50'], ['role' => 'executor', 'percentage' => '50']]], 'participants'],
        'regra de valor fixo'   => [['calculation' => 'fixed', 'fixed_amount' => '10', 'participants' => [['role' => 'executor', 'percentage' => '100']]], 'participants'],
        'médico de fora'        => [['participants' => [['role' => 'doctor', 'doctor_id' => '01a0ed1e-0000-7000-8000-000000000000', 'percentage' => '100']]], 'participants.0.doctor_id'],
    ]);

    it('regra com participantes é gravada na ordem e sai na listagem', function () {
        $this->post(route('panel.financial.doctor-payouts.rules.store'), [
            'service_type' => 'procedure', 'payer_scope' => 'any', 'calculation' => 'percentage', 'percentage' => '60',
            'participants' => [
                ['role' => 'doctor', 'doctor_id' => $this->leader->id, 'percentage' => '60'],
                ['role' => 'executor', 'percentage' => '40'],
            ],
        ])->assertSessionHasNoErrors();

        $rule = DoctorPayoutRule::query()->where('service_type', 'procedure')->sole();

        expect($rule->participants->map(fn ($p) => [$p->role, $p->doctor_id, (string) $p->percentage])->all())
            ->toBe([['doctor', $this->leader->id, '60.00'], ['executor', null, '40.00']]);

        $this->get(route('panel.financial.doctor-payouts.rules.index'))
            ->assertInertia(fn ($page) => $page->where('rules.data', fn ($rows) => collect($rows)->contains(fn ($row) => count($row['participants']) === 2)));
    });

    it('taxas: cria, recusa início repetido e exclui', function () {
        $this->post(route('panel.financial.doctor-payouts.deduction-rates.store'), ['kind' => 'tax', 'percentage' => '10,5', 'valid_from' => '2026-01-01'])
            ->assertSessionHasNoErrors();

        $rate = DoctorPayoutDeductionRate::query()->sole();
        expect((string) $rate->percentage)->toBe('10.50');

        $this->post(route('panel.financial.doctor-payouts.deduction-rates.store'), ['kind' => 'tax', 'percentage' => '11', 'valid_from' => '2026-01-01'])
            ->assertSessionHasErrors('valid_from');

        $this->delete(route('panel.financial.doctor-payouts.deduction-rates.destroy', $rate->id))->assertRedirect();
        expect(DoctorPayoutDeductionRate::query()->count())->toBe(0);

        $this->get(route('panel.financial.doctor-payouts.rules.index'))->assertInertia(fn ($page) => $page->where('deduction_rates', []));
    });
});
