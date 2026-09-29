<?php

/*
 * Repasse médico — telas administrativas (HTTP): permissões (admin/financeiro
 * × secretária/médico; reabrir/estornar/visibilidade só admin), isolamento
 * entre clínicas (404), validação, apuração, exportação com paciente em
 * iniciais + auditoria, fechamento/ajuste/pagamento pela API e PDF.
 */

use App\Enums\{ClientRule, ScheduleSituation};
use App\Enums\DoctorPayout\DoctorPayoutStatus;
use App\Models\{Covenant, DoctorPayout, DoctorPayoutRule, Entity, FinancialCashEntry, Patient, Schedule, User};
use App\Models\Procedure;
use App\Services\Financial\DoctorPayouts\{DoctorPayoutCalculator, DoctorPayoutPresenter};
use Barryvdh\Snappy\Facades\SnappyPdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
        $this->postJson(route('panel.financial.doctor-payouts.closings.payment.store', $foreign->id), [
            'paid_at' => '2026-07-01', 'payment_method' => 'transfer',
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
                ->where('kpis.charged', 200)
                ->where('kpis.pending', 120)
                ->where('kpis.no_rule', 0)
                ->where('close_preview.count', 1)
                ->where('close_preview.payout_cents', 12000)
                ->where('close_preview.can_close', true)
                ->where('items.data.0.patient_name', 'MARIA DA SILVA')
                ->where('items.data.0.status', 'pending')
                ->where('items.data.0.rule.percentage', 60));
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
    it('financeiro fecha, ajusta e paga; estornar e reabrir exigem admin', function () {
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

        $this->postJson(route('panel.financial.doctor-payouts.closings.payment.store', $payout), [
            'paid_at' => '2026-07-05', 'payment_method' => 'transfer',
        ])->assertOk()->assertJsonPath('message', __('financial_doctor_payouts.flash.paid'));
        expect($payout->fresh()->status)->toBe(DoctorPayoutStatus::Paid);

        $this->deleteJson(route('panel.financial.doctor-payouts.closings.payment.destroy', $payout), ['reason' => 'Motivo suficiente'])
            ->assertForbidden();

        payoutHttpActAsAdmin($this);

        $this->deleteJson(route('panel.financial.doctor-payouts.closings.payment.destroy', $payout), ['reason' => 'curto'])
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->deleteJson(route('panel.financial.doctor-payouts.closings.payment.destroy', $payout), ['reason' => 'Pagamento duplicado no banco'])
            ->assertOk();
        $this->deleteJson(route('panel.financial.doctor-payouts.closings.destroy', $payout), ['reason' => 'Refazer com a regra nova'])
            ->assertOk();

        expect($payout->fresh()->status)->toBe(DoctorPayoutStatus::Cancelled);
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
