<?php

/*
 * Repasse médico — telas administrativas (HTTP): permissões (admin/financeiro
 * × secretária/médico; reabrir/estornar/visibilidade só admin), isolamento
 * entre clínicas (404), validação, apuração, exportação com paciente em
 * iniciais + auditoria, fechamento/ajuste/pagamento pela API e PDF.
 */

use App\Enums\{ClientRule, ScheduleSituation};
use App\Enums\DoctorPayout\DoctorPayoutStatus;
use App\Models\{Covenant, DoctorPayout, DoctorPayoutPayment, DoctorPayoutRule, Entity, FinancialCashEntry, MedicalRecord, MedicalRecordProcedure, Patient, Schedule, User, VisitType};
use App\Models\{ExamType, PatientExam, Procedure};
use App\Services\Financial\DoctorPayouts\{DoctorPayoutCalculator, DoctorPayoutPresenter};
use Barryvdh\Snappy\Facades\SnappyPdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

beforeEach(function () {
    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->adminEu = actingAsFinancialEntityUser($this->entity);
    $this->doctor  = createDoctorForEntity($this->entity);

    $this->covenant = Covenant::factory()->create([
        'entity_id' => $this->entity->id, 'active' => true, 'ans_registry' => '326305', 'name' => 'OPERADORA HTTP',
    ]);
    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id, 'covenant_id' => $this->covenant->id]);
    $this->patient->person?->update(['full_name' => 'MARIA DA SILVA']);
});

function payoutHttpActAs($test, string $rule): User
{
    $user = User::factory()->create();
    $eu   = createEntityUser($test->entity, $user, $rule);

    $test->actingAs($user)->withSession(panelSession($eu));

    return $user;
}

function payoutHttpActAsAdmin($test): void
{
    $test->actingAs(User::query()->findOrFail($test->adminEu->user_id))->withSession(panelSession($test->adminEu));
}

function payoutHttpRule($test): DoctorPayoutRule
{
    return DoctorPayoutRule::query()->create([
        'entity_id'   => $test->entity->id, 'service_type' => 'consultation', 'payer_scope' => 'any',
        'calculation' => 'percentage', 'percentage' => '60.00', 'active' => true,
    ]);
}

function payoutHttpAttended($test, string $dateTime = '2026-06-10 09:00:00', float $cash = 200.00): Schedule
{
    $schedule = Schedule::create([
        'entity_id'   => $test->entity->id, 'doctor_id' => $test->doctor->id, 'patient_id' => $test->patient->id,
        'covenant_id' => $test->covenant->id, 'full_name' => 'Paciente', 'date_time' => $dateTime,
        'situation'   => ScheduleSituation::Attended->value, 'active' => true,
    ]);

    FinancialCashEntry::query()->create([
        'entity_id'    => $test->entity->id, 'entry_date' => substr($dateTime, 0, 10), 'description' => 'Recebimento',
        'type'         => 'income', 'status' => 'paid', 'amount' => $cash, 'reference_type' => 'schedule',
        'reference_id' => $schedule->id, 'active' => true,
    ]);

    return $schedule;
}

/** Payload de fechamento com a prévia calculada (o que a tela envia). */
function payoutHttpClosePayload($test, string $from = '2026-06-01', string $to = '2026-06-30'): array
{
    $totals = DoctorPayoutCalculator::totals(app(DoctorPayoutCalculator::class)->pending(
        $test->entity->id,
        $test->doctor->id,
        CarbonImmutable::parse($from),
        CarbonImmutable::parse($to),
    ));

    return [
        'doctor_id'              => $test->doctor->id,
        'period_start'           => $from,
        'period_end'             => $to,
        'expected_count'         => $totals['count'],
        'expected_charged_cents' => $totals['charged_cents'],
        'expected_payout_cents'  => $totals['payout_cents'],
    ];
}

function payoutHttpForeignPayout(): DoctorPayout
{
    $other  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $doctor = createDoctorForEntity($other);

    return DoctorPayout::query()->create([
        'entity_id'  => $other->id, 'doctor_id' => $doctor->id, 'doctor_name' => 'Outro', 'period_start' => '2026-06-01',
        'period_end' => '2026-06-30', 'status' => 'closed', 'closed_at' => now(),
    ]);
}

describe('permissões', function () {
    it('admin e financeiro acessam as telas; secretária e médico não', function () {
        foreach (['panel.financial.doctor-payouts.index', 'panel.financial.doctor-payouts.closings.index', 'panel.financial.doctor-payouts.rules.index'] as $route) {
            $this->get(route($route))->assertOk();
        }

        payoutHttpActAs($this, ClientRule::Financial->value);
        $this->get(route('panel.financial.doctor-payouts.rules.index'))->assertOk();

        payoutHttpActAs($this, ClientRule::Secretary->value);
        $this->get(route('panel.financial.doctor-payouts.index'))->assertForbidden();

        $this->actingAs(User::query()->findOrFail($this->doctor->entityUser->user_id))
            ->withSession(panelSession($this->doctor->entityUser));
        $this->get(route('panel.financial.doctor-payouts.rules.index'))->assertForbidden();
        $this->postJson(route('panel.financial.doctor-payouts.rules.store'), [])->assertForbidden();
    });

    it('fechamento/regra de outra clínica = 404; id que não é UUID = 404', function () {
        $foreign = payoutHttpForeignPayout();

        $this->get(route('panel.financial.doctor-payouts.closings.show', $foreign->id))->assertNotFound();
        $this->get(route('panel.financial.doctor-payouts.closings.pdf', $foreign->id))->assertNotFound();
        $this->postJson(route('panel.financial.doctor-payouts.closings.payments.store', $foreign->id), [
            'paid_at' => '2026-07-01', 'payment_method' => 'transfer', 'amount' => '10.00', 'expected_paid_cents' => 0,
        ])->assertNotFound();
        $this->get(route('panel.financial.doctor-payouts.closings.show', 'abc'))->assertNotFound();

        $foreignRule = DoctorPayoutRule::query()->create([
            'entity_id'   => $foreign->entity_id, 'service_type' => 'consultation', 'payer_scope' => 'any',
            'calculation' => 'percentage', 'percentage' => '10.00',
        ]);
        $this->deleteJson(route('panel.financial.doctor-payouts.rules.destroy', $foreignRule->id))->assertNotFound();
        expect($foreignRule->fresh()->deleted_at)->toBeNull();
    });
});

describe('regras', function () {
    it('lista com as opções da clínica e a configuração de visibilidade', function () {
        payoutHttpRule($this);

        $this->get(route('panel.financial.doctor-payouts.rules.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Panel/Financial/DoctorPayouts/Rules')
                ->has('rules.data', 1)
                ->where('rules.data.0.service_type', 'consultation')
                ->where('rules.data.0.percentage', 60)
                ->where('settings.can_manage', true)
                ->where('settings.doctor_payouts_visible', false)
                ->where('t.rules_title', __('financial_doctor_payouts.rules_title'))
                ->has('options.doctors', 1));
    });

    it('cria, valida coerência/valores e recusa referência de outra clínica', function () {
        $base = ['service_type' => 'consultation', 'payer_scope' => 'any', 'calculation' => 'percentage', 'percentage' => '60'];

        $this->postJson(route('panel.financial.doctor-payouts.rules.store'), [...$base, 'service_type' => 'all'])
            ->assertOk()
            ->assertJsonPath('message', __('financial_doctor_payouts.flash.rules_created', ['count' => 3]));

        $procedure = Procedure::factory()->create(['treatment' => 4]);
        $this->postJson(route('panel.financial.doctor-payouts.rules.store'), [...$base, 'procedure_id' => $procedure->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.procedure_id.0', __('financial_doctor_payouts.errors.item_type_mismatch'));

        $this->postJson(route('panel.financial.doctor-payouts.rules.store'), [...$base, 'payer_scope' => 'particular', 'covenant_id' => $this->covenant->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['covenant_id']);

        $foreignCovenant = Covenant::factory()->create(['entity_id' => Entity::factory()->create()->id]);
        $this->postJson(route('panel.financial.doctor-payouts.rules.store'), [...$base, 'payer_scope' => 'covenant', 'covenant_id' => $foreignCovenant->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['covenant_id']);

        $this->postJson(route('panel.financial.doctor-payouts.rules.store'), [...$base, 'percentage' => '150'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['percentage']);

        $this->postJson(route('panel.financial.doctor-payouts.rules.store'), [
            ...$base, 'doctor_id' => $this->doctor->id, 'calculation' => 'fixed', 'percentage' => null, 'fixed_amount' => '1.234,56',
        ])->assertOk()->assertJsonPath('data.0.fixed_amount', 1234.56);
    });
});

describe('apuração', function () {
    it('sem médico não calcula; com médico traz KPIs, resumo, itens e a prévia do fechamento', function () {
        payoutHttpRule($this);
        payoutHttpAttended($this);

        $this->get(route('panel.financial.doctor-payouts.index'))
            ->assertInertia(fn ($page) => $page->component('Panel/Financial/DoctorPayouts/Index')
                ->where('kpis', null)
                ->where('items', null)
                ->where('selected_doctor', null));

        $this->get(route('panel.financial.doctor-payouts.index', ['doctor' => $this->doctor->id, 'from' => '2026-06-01', 'to' => '2026-06-30']))
            ->assertInertia(fn ($page) => $page->component('Panel/Financial/DoctorPayouts/Index')
                ->where('kpis.production_count', 1)
                ->where('kpis.to_release', 120)
                ->where('kpis.release_base', 200)
                ->where('kpis.release_count', 1)
                ->where('kpis.awaiting_count', 0)
                ->where('kpis.no_rule', 0)
                ->where('close_preview.count', 1)
                ->where('close_preview.payout_cents', 12000)
                ->where('close_preview.can_close', true)
                ->where('items.data.0.patient_name', 'MARIA DA SILVA')
                ->where('items.data.0.status', 'pending')
                ->where('items.data.0.rule.percentage', 60)
                ->where('items.data.0.receipt.status', 'received')
                ->where('items.data.0.receipt.received', 200)
                ->where('kpis.receipt.billed', 200)
                ->where('kpis.receipt.received', 200)
                ->where('kpis.receipt.open', 0)
                ->where('filters.receipt', ''));
    });

    it('rastreio de recebimento: separa recebido de a receber, filtra pela situação e exporta as colunas', function () {
        payoutHttpRule($this);
        payoutHttpAttended($this);
        $pending = payoutHttpAttended($this, '2026-06-11 09:00:00', 150.00);
        FinancialCashEntry::query()->where('reference_id', $pending->id)->update(['status' => 'pending']);

        $query = ['doctor' => $this->doctor->id, 'from' => '2026-06-01', 'to' => '2026-06-30'];

        // Regime por recebimento: só o recebido libera; os R$ 150 pendentes no
        // balcão aparecem como previsão (aguardando), fora do fechamento.
        $this->get(route('panel.financial.doctor-payouts.index', [...$query, 'receipt' => 'awaiting']))
            ->assertInertia(fn ($page) => $page
                ->where('filters.receipt', 'awaiting')
                ->where('kpis.to_release', 120)
                ->where('kpis.awaiting_count', 1)
                ->where('kpis.awaiting_forecast', 90)
                ->where('close_preview.payout_cents', 12000)
                ->where('kpis.receipt.received', 200)
                ->where('kpis.receipt.open', 150)
                ->where('kpis.receipt.open_count', 1)
                ->where('items.total', 1)
                ->where('items.data.0.key', 'schedule:' . $pending->id)
                ->where('items.data.0.status', 'awaiting')
                ->where('items.data.0.forecast', 90)
                ->where('items.data.0.receipt.open', 150));

        $this->get(route('panel.financial.doctor-payouts.index', [...$query, 'receipt' => 'nao-existe']))
            ->assertInertia(fn ($page) => $page->where('filters.receipt', '')->where('items.total', 2));

        $csv = $this->get(route('panel.financial.doctor-payouts.export', [...$query, 'format' => 'csv']))
            ->assertOk()
            ->getContent();

        expect($csv)->toContain(__('financial_doctor_payouts.export_columns.received'))
            ->toContain(__('financial_doctor_payouts.export_columns.open'))
            ->toContain(__('financial_doctor_payouts.receipt_statuses.awaiting'))
            ->toContain(__('financial_doctor_payouts.receipt_statuses.received'));
    });

    it('procedimentos pareados (OD e OE): bases somam o cobrado e o recebido do atendimento conta uma vez só', function () {
        $injection = Procedure::factory()->create(['treatment' => 4, 'name' => 'INJECAO INTRAVITREA']);
        $type      = VisitType::create(['entity_id' => $this->entity->id, 'name' => 'INJECAO', 'procedure_id' => $injection->id, 'active' => true]);
        $schedule  = payoutHttpAttended($this, '2026-06-10 09:00:00', 1000.01);
        $schedule->update(['visit_id' => $type->id]);

        $record = MedicalRecord::create([
            'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'schedule_id' => $schedule->id,
        ]);

        foreach (['2026-06-10 10:00:00' => 'OD', '2026-06-10 10:05:00' => 'OE'] as $executedAt => $eye) {
            MedicalRecordProcedure::create([
                'entity_id'    => $this->entity->id, 'patient_id' => $this->patient->id, 'medical_record_id' => $record->id,
                'procedure_id' => $injection->id, 'doctor_id' => $this->doctor->id, 'status' => 'done', 'eye' => $eye,
                'executed_at'  => $executedAt, 'executed_by' => $this->doctor->entity_user_id,
            ]);
        }

        $this->get(route('panel.financial.doctor-payouts.index', ['doctor' => $this->doctor->id, 'from' => '2026-06-01', 'to' => '2026-06-30']))
            ->assertInertia(fn ($page) => $page
                ->where('kpis.production_count', 2)
                ->where('kpis.release_base', 1000.01)
                ->where('kpis.receipt.received', 1000.01)
                ->where('kpis.receipt.billed', 1000.01)
                ->where('items.data.0.charged', 500.01)
                ->where('items.data.1.charged', 500)
                ->where('items.data.0.receipt.received', 1000.01)
                ->where('items.data.0.receipt.shared_by', 2)
                ->where('items.data.0.warnings', fn ($warnings) => collect($warnings)->contains('split_charge')));
    });

    it('parâmetros inválidos caem no padrão sem erro', function () {
        $this->get(route('panel.financial.doctor-payouts.index', [
            'doctor' => 'nao-e-uuid', 'from' => 'abc', 'to' => ['x'], 'status' => ['y'], 'page' => 'z',
        ]))->assertOk()->assertInertia(fn ($page) => $page->where('filters.doctor', '')->where('filters.status', ''));
    });

    it('exporta a planilha com o paciente em iniciais e audita a exportação', function () {
        payoutHttpRule($this);
        payoutHttpAttended($this);

        $response = $this->get(route('panel.financial.doctor-payouts.export', [
            'doctor' => $this->doctor->id, 'from' => '2026-06-01', 'to' => '2026-06-30', 'format' => 'csv',
        ]))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        expect($response->getContent())->toContain('M. S.')->not->toContain('MARIA DA SILVA');

        $log = DB::table('audit_logs')->where('event', 'financial.report.export')->first();
        expect($log)->not->toBeNull()
            ->and(json_decode((string) $log->new_values, true))->toMatchArray(['report' => 'doctor_payouts', 'format' => 'csv', 'rows' => 1]);
    });
});

describe('fechar, ajustar, pagar, estornar, reabrir', function () {
    it('financeiro fecha, ajusta, paga em parcelas e estorna; reabrir exige admin e nenhum pagamento válido', function () {
        payoutHttpRule($this);
        payoutHttpAttended($this);

        payoutHttpActAs($this, ClientRule::Financial->value);

        $closed = $this->postJson(route('panel.financial.doctor-payouts.closings.store'), payoutHttpClosePayload($this))
            ->assertOk()
            ->json('data');
        $payout = DoctorPayout::query()->findOrFail($closed['id']);

        $this->postJson(route('panel.financial.doctor-payouts.closings.adjustments.store', $payout), [
            'description' => 'Imposto retido', 'kind' => 'debit', 'amount' => '20,00',
        ])->assertOk();
        expect((string) $payout->fresh()->total_amount)->toBe('100.00');

        $pay = fn (string $amount, int $expected) => $this->postJson(route('panel.financial.doctor-payouts.closings.payments.store', $payout), [
            'paid_at' => '2026-07-05', 'payment_method' => 'transfer', 'amount' => $amount, 'expected_paid_cents' => $expected,
        ]);

        $pay('40,00', 0)->assertOk()->assertJsonPath('message', __('financial_doctor_payouts.flash.payment_partial', [
            'remaining' => Number::currency(60, 'BRL', app()->getLocale()),
        ]));
        expect($payout->fresh()->status)->toBe(DoctorPayoutStatus::PartiallyPaid);

        // Duplo envio com o mesmo "já pago": recusado.
        $pay('40,00', 0)->assertStatus(422)->assertJsonPath('errors.amount.0', __('financial_doctor_payouts.errors.payment_changed'));
        $pay('60.00', 4000)->assertOk()->assertJsonPath('message', __('financial_doctor_payouts.flash.paid'));
        expect($payout->fresh()->status)->toBe(DoctorPayoutStatus::Paid);

        $first   = DoctorPayoutPayment::query()->where('doctor_payout_id', $payout->id)->orderBy('amount')->first();
        $reverse = fn (DoctorPayoutPayment $payment, string $reason) => $this->deleteJson(
            route('panel.financial.doctor-payouts.closings.payments.destroy', [$payout, $payment]),
            ['reason' => $reason],
        );

        // Financeiro estorna (decisão de 2026-09-29), com motivo.
        $reverse($first, 'curto')->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $reverse($first, 'Parcela lançada em duplicidade')->assertOk()->assertJsonPath('message', __('financial_doctor_payouts.flash.payment_reversed'));
        expect($payout->fresh()->status)->toBe(DoctorPayoutStatus::PartiallyPaid);

        // Reabrir: só admin e só sem pagamento válido.
        $this->deleteJson(route('panel.financial.doctor-payouts.closings.destroy', $payout), ['reason' => 'Refazer com a regra nova'])
            ->assertForbidden();

        payoutHttpActAsAdmin($this);

        $this->deleteJson(route('panel.financial.doctor-payouts.closings.destroy', $payout), ['reason' => 'Refazer com a regra nova'])
            ->assertStatus(422)->assertJsonPath('errors.status.0', __('financial_doctor_payouts.errors.reopen_has_payments'));

        $reverse(DoctorPayoutPayment::query()->where('doctor_payout_id', $payout->id)->whereNull('reversed_at')->sole(), 'Estorno do restante')->assertOk();
        $this->deleteJson(route('panel.financial.doctor-payouts.closings.destroy', $payout), ['reason' => 'Refazer com a regra nova'])
            ->assertOk();

        expect($payout->fresh()->status)->toBe(DoctorPayoutStatus::Cancelled);
    });

    it('pagamento: campos obrigatórios; pagamento de outro fechamento ou id inválido = 404', function () {
        payoutHttpRule($this);
        payoutHttpAttended($this);

        $closed = $this->postJson(route('panel.financial.doctor-payouts.closings.store'), payoutHttpClosePayload($this))->assertOk()->json('data');
        $payout = DoctorPayout::query()->findOrFail($closed['id']);

        $this->postJson(route('panel.financial.doctor-payouts.closings.payments.store', $payout), ['paid_at' => '2026-07-05', 'payment_method' => 'transfer'])
            ->assertStatus(422)->assertJsonValidationErrors(['amount', 'expected_paid_cents']);

        $this->postJson(route('panel.financial.doctor-payouts.closings.payments.store', $payout), [
            'paid_at' => '2026-07-05', 'payment_method' => 'transfer', 'amount' => '10.00', 'expected_paid_cents' => 0,
        ])->assertOk();

        $payment = DoctorPayoutPayment::query()->where('doctor_payout_id', $payout->id)->sole();
        $foreign = payoutHttpForeignPayout();

        $this->deleteJson(route('panel.financial.doctor-payouts.closings.payments.destroy', [$foreign->id, $payment->id]), ['reason' => 'Motivo suficiente'])
            ->assertNotFound();
        $this->deleteJson(route('panel.financial.doctor-payouts.closings.payments.destroy', [$payout->id, 'abc']), ['reason' => 'Motivo suficiente'])
            ->assertNotFound();

        expect($payment->fresh()->reversed_at)->toBeNull();
    });

    it('fechamento com prévia desatualizada volta com o erro de conferência', function () {
        payoutHttpRule($this);
        payoutHttpAttended($this);

        $this->postJson(route('panel.financial.doctor-payouts.closings.store'), [
            ...payoutHttpClosePayload($this), 'expected_count' => 5,
        ])->assertStatus(422)->assertJsonPath('errors.period_start.0', __('financial_doctor_payouts.errors.preview_changed'));

        $this->postJson(route('panel.financial.doctor-payouts.closings.store'), [
            ...payoutHttpClosePayload($this), 'period_end' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['period_end']);
    });

    it('demonstrativo mostra itens agrupados e permissões conforme status e papel', function () {
        payoutHttpRule($this);
        payoutHttpAttended($this);
        $closed = $this->postJson(route('panel.financial.doctor-payouts.closings.store'), payoutHttpClosePayload($this))->json('data');

        $this->get(route('panel.financial.doctor-payouts.closings.show', $closed['id']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Panel/Financial/DoctorPayouts/Show')
                ->where('statement.payout.code', $closed['code'])
                ->where('statement.groups.0.service_type', 'consultation')
                ->where('statement.groups.0.items.0.payout', 120)
                ->where('permissions.can_pay', true)
                ->where('permissions.can_reopen', true)
                ->where('permissions.can_reverse', false));
    });
});

describe('PDF do demonstrativo', function () {
    it('usa o template do demonstrativo e audita o download; falha volta com aviso', function () {
        payoutHttpRule($this);
        payoutHttpAttended($this);
        $closed = $this->postJson(route('panel.financial.doctor-payouts.closings.store'), payoutHttpClosePayload($this))->json('data');

        $pdf = Mockery::mock();
        $pdf->shouldReceive('setPaper')->andReturnSelf();
        $pdf->shouldReceive('setOrientation')->andReturnSelf();
        $pdf->shouldReceive('setOption')->andReturnSelf();
        $pdf->shouldReceive('download')->andReturn(response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']));

        SnappyPdf::shouldReceive('loadView')
            ->once()
            ->withArgs(fn ($view, $data) => $view === 'pdf.doctor_payout_statement' && $data['statement']['payout']['code'] === $closed['code'])
            ->andReturn($pdf);

        $this->get(route('panel.financial.doctor-payouts.closings.pdf', $closed['id']))->assertOk();

        expect(DB::table('audit_logs')->where('event', 'financial.report.export')->count())->toBe(1);
    });

    it('falha do wkhtmltopdf volta com mensagem e não audita', function () {
        payoutHttpRule($this);
        payoutHttpAttended($this);
        $closed = $this->postJson(route('panel.financial.doctor-payouts.closings.store'), payoutHttpClosePayload($this))->json('data');

        SnappyPdf::shouldReceive('loadView')->andThrow(new RuntimeException('wkhtmltopdf ausente'));

        $this->from(route('panel.financial.doctor-payouts.closings.show', $closed['id']))
            ->get(route('panel.financial.doctor-payouts.closings.pdf', $closed['id']))
            ->assertRedirect(route('panel.financial.doctor-payouts.closings.show', $closed['id']))
            ->assertSessionHas('error', __('financial_doctor_payouts.errors.pdf_failed'));

        expect(DB::table('audit_logs')->where('event', 'financial.report.export')->count())->toBe(0);
    });

    it('o template renderiza nos dois idiomas com totais e nome do paciente', function (string $locale) {
        payoutHttpRule($this);
        payoutHttpAttended($this);
        $closed = $this->postJson(route('panel.financial.doctor-payouts.closings.store'), payoutHttpClosePayload($this))->json('data');

        app()->setLocale($locale);

        $html = view('pdf.doctor_payout_statement', [
            'entity'      => $this->entity,
            'statement'   => app(DoctorPayoutPresenter::class)->statement(DoctorPayout::query()->findOrFail($closed['id'])),
            'presenter'   => app(DoctorPayoutPresenter::class),
            'locale'      => $locale,
            'generatedAt' => now(),
        ])->render();

        expect($html)->toContain(__('financial_doctor_payouts.pdf_title'))
            ->toContain($closed['code'])
            ->toContain('MARIA DA SILVA')
            ->toContain(__('financial_doctor_payouts.statement_net_total'));
    })->with(['pt_BR', 'en']);
});

describe('visibilidade para o médico', function () {
    it('só admin altera e a mudança fica auditada', function () {
        payoutHttpActAs($this, ClientRule::Financial->value);
        $this->patchJson(route('panel.financial.doctor-payouts.settings.update'), ['doctor_payouts_visible' => true])->assertForbidden();

        payoutHttpActAsAdmin($this);
        $this->patchJson(route('panel.financial.doctor-payouts.settings.update'), ['doctor_payouts_visible' => true])
            ->assertOk()
            ->assertJsonPath('data.doctor_payouts_visible', true);

        expect($this->entity->fresh()->doctor_payouts_visible)->toBeTrue()
            ->and(DB::table('audit_logs')->where('auditable_type', Entity::class)->where('auditable_id', $this->entity->id)->where('event', 'updated')->exists())->toBeTrue();
    });
});

/*
 * Regressão das lacunas da auditoria do pedido (repasse médico): cada teste
 * reproduz um cenário que falhava antes da correção.
 */
describe('lacunas do pedido (regressão)', function () {
    function payoutGapSchedule($test, string $dateTime, array $receipts): Schedule
    {
        $schedule = Schedule::create([
            'entity_id'   => $test->entity->id, 'doctor_id' => $test->doctor->id, 'patient_id' => $test->patient->id,
            'covenant_id' => $test->covenant->id, 'full_name' => 'Paciente', 'date_time' => $dateTime,
            'situation'   => ScheduleSituation::Attended->value, 'active' => true,
        ]);

        foreach ($receipts as $date => $amount) {
            FinancialCashEntry::query()->create([
                'entity_id'    => $test->entity->id, 'entry_date' => $date, 'description' => 'Recebimento',
                'type'         => 'income', 'status' => 'paid', 'amount' => $amount, 'reference_type' => 'schedule',
                'reference_id' => $schedule->id, 'active' => true,
            ]);
        }

        return $schedule;
    }

    it('período que termina antes do último fechamento: só consulta, sem "a liberar" negativo fantasma', function () {
        payoutHttpRule($this);
        // Consulta de maio recebida em junho (convênio que paga depois) e junho fechado.
        payoutGapSchedule($this, '2026-05-20 09:00:00', ['2026-06-10' => 200.00]);
        $this->postJson(route('panel.financial.doctor-payouts.closings.store'), payoutHttpClosePayload($this))->assertOk();

        foreach ([['2026-05-01', '2026-05-31'], ['2026-06-01', '2026-06-15']] as [$from, $to]) {
            $this->get(route('panel.financial.doctor-payouts.index', ['doctor' => $this->doctor->id, 'from' => $from, 'to' => $to]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('kpis.to_release', 0)
                    ->where('kpis.release_count', 0)
                    ->where('close_preview.historical', true)
                    ->where('close_preview.can_close', false)
                    ->where('close_preview.last_closed_until', '2026-06-30')
                    ->where('items.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['status'] !== 'pending' && $row['payout'] >= 0))
                    ->where('summary', fn ($summary) => collect($summary)->every(fn ($row) => $row['payout'] >= 0)));
        }

        // O período atual continua calculando normalmente.
        $this->get(route('panel.financial.doctor-payouts.index', ['doctor' => $this->doctor->id, 'from' => '2026-06-01', 'to' => '2026-06-30']))
            ->assertInertia(fn ($page) => $page->where('close_preview.historical', false));
    });

    it('atos distintos: a parcela 2 do mesmo atendimento não conta como outro atendimento', function () {
        payoutHttpRule($this);
        payoutGapSchedule($this, '2026-06-10 09:00:00', ['2026-06-10' => 100.00, '2026-07-10' => 100.00]);
        $this->postJson(route('panel.financial.doctor-payouts.closings.store'), payoutHttpClosePayload($this))->assertOk();

        $this->get(route('panel.financial.doctor-payouts.index', ['doctor' => $this->doctor->id, 'from' => '2026-06-01', 'to' => '2026-07-31']))
            ->assertInertia(fn ($page) => $page
                ->where('items.total', 2) // parcela fechada + parcela a liberar
                ->where('kpis.production_count', 1)
                ->where('summary', fn ($summary) => collect($summary)->firstWhere('service_type', 'consultation')['count'] === 1));
    });

    it('total a repassar = a liberar + fechado a pagar; ajustes do fechamento vêm à parte para o resumo bater', function () {
        payoutHttpRule($this);
        payoutGapSchedule($this, '2026-06-10 09:00:00', ['2026-06-10' => 200.00]);
        $closed = $this->postJson(route('panel.financial.doctor-payouts.closings.store'), payoutHttpClosePayload($this))->json('data');
        $payout = DoctorPayout::query()->findOrFail($closed['id']);

        $this->postJson(route('panel.financial.doctor-payouts.closings.adjustments.store', $payout), [
            'description' => 'Adiantamento', 'kind' => 'debit', 'amount' => '20,00',
        ])->assertOk();
        $this->postJson(route('panel.financial.doctor-payouts.closings.payments.store', $payout), [
            'paid_at' => '2026-07-05', 'payment_method' => 'transfer', 'amount' => '40,00', 'expected_paid_cents' => 0,
        ])->assertOk();

        payoutGapSchedule($this, '2026-07-05 09:00:00', ['2026-07-05' => 100.00]);

        $this->get(route('panel.financial.doctor-payouts.index', ['doctor' => $this->doctor->id, 'from' => '2026-06-01', 'to' => '2026-07-31']))
            ->assertInertia(fn ($page) => $page
                ->where('kpis.paid', 40)
                ->where('kpis.to_pay', 60)
                ->where('kpis.to_release', 60)
                ->where('kpis.adjustments', -20)
                ->where('kpis.to_transfer', 120)
                // itens (120 fechado + 60 a liberar) + ajustes (−20) = pago + a pagar + a liberar
                ->where('summary', fn ($summary) => (int) round(collect($summary)->sum('payout') * 100) - 2000 === 4000 + 6000 + 6000));
    });

    it('filtros: "com recebimento" deixa a previsão de fora (bate com o resumo) e "alerta" mostra quem bloqueia o fechamento', function () {
        // Sem regra: o recebido fica pendente com "sem regra"; o não recebido, na previsão.
        payoutGapSchedule($this, '2026-06-10 09:00:00', ['2026-06-10' => 200.00]);
        $open = payoutGapSchedule($this, '2026-06-11 09:00:00', ['2026-06-11' => 150.00]);
        FinancialCashEntry::query()->where('reference_id', $open->id)->update(['status' => 'pending']);

        $query = ['doctor' => $this->doctor->id, 'from' => '2026-06-01', 'to' => '2026-06-30'];

        $this->get(route('panel.financial.doctor-payouts.index', [...$query, 'status' => 'in_payout']))
            ->assertInertia(fn ($page) => $page
                ->where('filters.status', 'in_payout')
                ->where('items.total', 1)
                ->where('items.data.0.status', 'pending'));

        $this->get(route('panel.financial.doctor-payouts.index', [...$query, 'status' => 'pending', 'warning' => 'no_rule']))
            ->assertInertia(fn ($page) => $page
                ->where('filters.warning', 'no_rule')
                ->where('kpis.no_rule', 1)
                ->where('items.total', 1)
                ->where('items.data.0.warnings', fn ($warnings) => collect($warnings)->contains('no_rule')));

        $this->get(route('panel.financial.doctor-payouts.index', [...$query, 'warning' => 'nao-existe']))
            ->assertInertia(fn ($page) => $page->where('filters.warning', '')->where('items.total', 2));
    });

    it('avisa os exames do equipamento sem médico no período (não somem em silêncio)', function () {
        $oct = ExamType::factory()->create(['name' => 'OCT']);
        PatientExam::factory()->create([
            'patient_id'        => $this->patient->id, 'exam_id' => $oct->id, 'doctor_id' => null,
            'exam_performed_at' => '2026-06-10 11:00:00', 'source' => 'integrator', 'active' => true,
        ]);

        $this->get(route('panel.financial.doctor-payouts.index', ['from' => '2026-06-01', 'to' => '2026-06-30']))
            ->assertInertia(fn ($page) => $page->where('unassigned_exams', 1));
    });

    it('cada linha diz QUAL regra foi aplicada (na apuração e no demonstrativo)', function () {
        payoutHttpRule($this);
        payoutHttpAttended($this);

        $scope = implode(' · ', [
            __('financial_doctor_payouts.all_doctors'),
            __('financial_doctor_payouts.service_types.consultation'),
            __('financial_doctor_payouts.payer_scopes.any'),
        ]);

        $this->get(route('panel.financial.doctor-payouts.index', ['doctor' => $this->doctor->id, 'from' => '2026-06-01', 'to' => '2026-06-30']))
            ->assertInertia(fn ($page) => $page->where('items.data.0.rule_scope', $scope));

        $closed = $this->postJson(route('panel.financial.doctor-payouts.closings.store'), payoutHttpClosePayload($this))->json('data');

        $this->get(route('panel.financial.doctor-payouts.closings.show', $closed['id']))
            ->assertInertia(fn ($page) => $page->where('statement.groups.0.items.0.rule_scope', $scope));
    });

    it('abrir o demonstrativo na tela fica na trilha de auditoria (sem dados do paciente)', function () {
        payoutHttpRule($this);
        payoutHttpAttended($this);
        $closed = $this->postJson(route('panel.financial.doctor-payouts.closings.store'), payoutHttpClosePayload($this))->json('data');

        $this->get(route('panel.financial.doctor-payouts.closings.show', $closed['id']))->assertOk();

        $log = DB::table('audit_logs')->where('event', 'financial.report.view')->sole();

        expect($log->auditable_type)->toBe('doctor_payout')
            ->and($log->auditable_id)->toBe($closed['id'])
            ->and(json_decode((string) $log->new_values, true))->toMatchArray(['report' => 'doctor_payout_statement', 'viewer' => 'clinic'])
            ->and((string) $log->new_values)->not->toContain('MARIA');
    });

    it('PDF sai com nome traduzido (planilha e PDF iguais)', function () {
        payoutHttpRule($this);
        payoutHttpAttended($this);
        $closed = $this->postJson(route('panel.financial.doctor-payouts.closings.store'), payoutHttpClosePayload($this))->json('data');

        $pdf = Mockery::mock();
        $pdf->shouldReceive('setPaper')->andReturnSelf();
        $pdf->shouldReceive('setOrientation')->andReturnSelf();
        $pdf->shouldReceive('setOption')->andReturnSelf();
        $pdf->shouldReceive('download')
            ->once()
            ->with(__('financial_doctor_payouts.export_filename') . '_' . $closed['code'] . '.pdf')
            ->andReturn(response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']));
        SnappyPdf::shouldReceive('loadView')->once()->andReturn($pdf);

        $this->get(route('panel.financial.doctor-payouts.closings.pdf', $closed['id']))->assertOk();
    });

    it('nova vigência: a regra atual vale até a véspera e a nova dali em diante (regra pela data do atendimento)', function () {
        $rule = payoutHttpRule($this);
        $rule->update(['valid_from' => '2026-01-01']);
        payoutHttpAttended($this, '2026-06-10 09:00:00', 200.00);
        payoutHttpAttended($this, '2026-07-10 09:00:00', 200.00);

        $payload = [
            'service_type' => 'consultation', 'payer_scope' => 'any', 'calculation' => 'percentage',
            'percentage'   => '70', 'valid_from' => '2026-01-01', 'active' => true,
        ];

        // Fora da faixa: igual ao início da atual; e no cadastro não existe.
        $this->putJson(route('panel.financial.doctor-payouts.rules.update', $rule), [...$payload, 'effective_from' => '2026-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['effective_from']);
        $this->postJson(route('panel.financial.doctor-payouts.rules.store'), [...$payload, 'effective_from' => '2026-07-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['effective_from']);

        $this->putJson(route('panel.financial.doctor-payouts.rules.update', $rule), [...$payload, 'effective_from' => '2026-07-01'])
            ->assertOk();

        $old  = $rule->fresh();
        $next = DoctorPayoutRule::query()->where('entity_id', $this->entity->id)->whereKeyNot($rule->id)->sole();

        expect($old->valid_until->toDateString())->toBe('2026-06-30')
            ->and((string) $old->percentage)->toBe('60.00')
            ->and($next->valid_from->toDateString())->toBe('2026-07-01')
            ->and((string) $next->percentage)->toBe('70.00');

        $this->get(route('panel.financial.doctor-payouts.index', ['doctor' => $this->doctor->id, 'from' => '2026-06-01', 'to' => '2026-07-31']))
            ->assertInertia(fn ($page) => $page
                ->where('items.data.0.payout', 120)   // junho: 60%
                ->where('items.data.1.payout', 140)); // julho: 70%
    });

    it('"nova vigência" sem data é recusada — não vira correção retroativa', function () {
        $rule = payoutHttpRule($this);

        $this->putJson(route('panel.financial.doctor-payouts.rules.update', $rule), [
            'service_type' => 'consultation', 'payer_scope' => 'any', 'calculation' => 'percentage', 'percentage' => '15', 'active' => true,
            'change_mode'  => 'new', 'effective_from' => null,
        ])->assertStatus(422)->assertJsonPath('errors.effective_from.0', __('financial_doctor_payouts.errors.effective_from_required'));

        expect((string) $rule->fresh()->percentage)->toBe('60.00')
            ->and(DoctorPayoutRule::query()->where('entity_id', $this->entity->id)->count())->toBe(1);
    });

    it('regra já usada em fechamento: corrigir valor pode, mudar o escopo (pagador, item, médico) não', function () {
        $rule = payoutHttpRule($this);
        payoutHttpAttended($this);
        $this->postJson(route('panel.financial.doctor-payouts.closings.store'), payoutHttpClosePayload($this))->assertOk();

        $base = ['service_type' => 'consultation', 'payer_scope' => 'any', 'calculation' => 'percentage', 'active' => true];

        $this->putJson(route('panel.financial.doctor-payouts.rules.update', $rule), [...$base, 'percentage' => '65'])->assertOk();
        expect((string) $rule->fresh()->percentage)->toBe('65.00');

        $this->putJson(route('panel.financial.doctor-payouts.rules.update', $rule), [...$base, 'payer_scope' => 'particular', 'percentage' => '65'])
            ->assertStatus(422)
            ->assertJsonPath('errors.payer_scope.0', __('financial_doctor_payouts.errors.rule_scope_locked'));
        expect($rule->fresh()->payer_scope->value)->toBe('any');

        // Nova vigência com outro escopo continua possível: a regra usada fica como estava.
        $this->putJson(route('panel.financial.doctor-payouts.rules.update', $rule), [
            ...$base, 'payer_scope' => 'particular', 'percentage' => '70', 'change_mode' => 'new', 'effective_from' => '2026-07-01',
        ])->assertOk();
        expect($rule->fresh()->payer_scope->value)->toBe('any');
    });

    it('fechar com base negativa (estorno maior que o novo recebido) e repasse ≥ 0 não cai na validação da prévia', function () {
        payoutHttpRule($this);

        $this->postJson(route('panel.financial.doctor-payouts.closings.store'), [
            ...payoutHttpClosePayload($this), 'expected_charged_cents' => -20000,
        ])->assertJsonMissingValidationErrors(['expected_charged_cents']);
    });

    it('mudar só a divisão (participantes) da regra fica na trilha de auditoria', function () {
        $rule  = payoutHttpRule($this);
        $other = createDoctorForEntity($this->entity);

        $this->putJson(route('panel.financial.doctor-payouts.rules.update', $rule), [
            'service_type' => 'consultation', 'payer_scope' => 'any', 'calculation' => 'percentage', 'percentage' => '60', 'active' => true,
            'participants' => [
                ['role' => 'executor', 'percentage' => '50'],
                ['role' => 'doctor', 'doctor_id' => $other->id, 'percentage' => '50'],
            ],
        ])->assertOk();

        $log = DB::table('audit_logs')
            ->where('auditable_type', DoctorPayoutRule::class)
            ->where('auditable_id', $rule->id)
            ->where('event', 'updated')
            ->get()
            ->first(fn ($row) => str_contains((string) $row->new_values, 'participants'));

        expect($log)->not->toBeNull()
            ->and(json_decode((string) $log->old_values, true)['participants'])->toBe([])
            ->and(json_decode((string) $log->new_values, true)['participants'])->toHaveCount(2)
            ->and((string) $log->new_values)->toContain($other->id);
    });
});
