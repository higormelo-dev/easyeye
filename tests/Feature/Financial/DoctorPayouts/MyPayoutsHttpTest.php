<?php

/*
 * "Meus repasses" (médico) + menu + paridade de traduções do módulo.
 *
 * O médico só vê os PRÓPRIOS fechamentos (fechados e pagos) e só se a clínica
 * ligou entities.doctor_payouts_visible; o resto é 404 — inclusive fechamento
 * de outro médico ou cancelado. O item de menu segue a mesma regra.
 */

use App\Enums\ClientRule;
use App\Models\{DoctorPayout, DoctorPayoutPayment, Entity, User};
use App\Models\{DoctorPayoutItem, Patient};
use App\Services\Financial\CovenantReportService;
use App\Services\Financial\DoctorPayouts\DoctorPayoutPresenter;
use App\Support\PanelNavigation;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Support\{Arr, Str};
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctor = createDoctorForEntity($this->entity);
    $this->other  = createDoctorForEntity($this->entity);

    $this->actingAs(User::query()->findOrFail($this->doctor->entityUser->user_id))
        ->withSession(panelSession($this->doctor->entityUser) + ['user_rule' => ClientRule::Doctor->value]);
});

function myPayoutsCreate($test, $doctor, string $status, string $periodEnd = '2026-06-30'): DoctorPayout
{
    return DoctorPayout::query()->create([
        'entity_id'    => $test->entity->id, 'doctor_id' => $doctor->id, 'doctor_name' => 'Dr. Teste',
        'period_start' => substr($periodEnd, 0, 8) . '01', 'period_end' => $periodEnd, 'status' => $status, 'closed_at' => now(),
        'items_count'  => 3, 'gross_amount' => 500, 'items_amount' => 300, 'total_amount' => 300,
    ]);
}

function myPayoutsNavKeys(array $nav): array
{
    return collect($nav)->flatMap(fn (array $item) => [$item['key'] ?? null, ...array_column($item['children'] ?? [], 'route')])
        ->filter()
        ->values()
        ->all();
}

it('com a opção desligada devolve 404 e não mostra o menu', function () {
    $payout = myPayoutsCreate($this, $this->doctor, 'paid');

    $this->get(route('panel.my-payouts.index'))->assertNotFound();
    $this->get(route('panel.my-payouts.show', $payout->id))->assertNotFound();

    session(panelSession($this->doctor->entityUser));
    expect(myPayoutsNavKeys(PanelNavigation::build()))->not->toContain('my-payouts');
});

it('com a opção ligada, o médico vê só os próprios fechados/pagos', function () {
    $this->entity->forceFill(['doctor_payouts_visible' => true])->save();

    $paid      = myPayoutsCreate($this, $this->doctor, 'paid', '2026-06-30');
    $closed    = myPayoutsCreate($this, $this->doctor, 'closed', '2026-07-31');
    $cancelled = myPayoutsCreate($this, $this->doctor, 'cancelled', '2026-05-31');
    $others    = myPayoutsCreate($this, $this->other, 'paid');

    $this->get(route('panel.my-payouts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Panel/MyPayouts/Index')
            ->has('payouts.data', 2)
            ->where('payouts.data.0.id', $closed->id)
            ->where('payouts.data.1.id', $paid->id));

    $this->get(route('panel.my-payouts.show', $paid->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Panel/MyPayouts/Show')->where('statement.payout.id', $paid->id));

    $this->get(route('panel.my-payouts.show', $others->id))->assertNotFound();
    $this->get(route('panel.my-payouts.show', $cancelled->id))->assertNotFound();
    $this->get(route('panel.my-payouts.pdf', $others->id))->assertNotFound();

    session(panelSession($this->doctor->entityUser));
    expect(myPayoutsNavKeys(PanelNavigation::build()))->toContain('my-payouts')
        // Último item do menu lateral (pedido do usuário).
        ->and(collect(PanelNavigation::build())->last()['key'])->toBe('my-payouts');
});

it('pago em parte aparece; no demonstrativo o médico vê só os pagamentos válidos, sem observações nem nomes', function () {
    $this->entity->forceFill(['doctor_payouts_visible' => true])->save();

    $partial = myPayoutsCreate($this, $this->doctor, 'partially_paid');
    $partial->forceFill(['paid_at' => '2026-07-05', 'paid_amount' => 100])->save();

    $valid = DoctorPayoutPayment::query()->create([
        'entity_id'      => $this->entity->id, 'doctor_payout_id' => $partial->id, 'amount' => 100, 'paid_at' => '2026-07-05',
        'payment_method' => 'transfer', 'notes' => 'Conta pessoal do médico',
    ]);
    DoctorPayoutPayment::query()->create([
        'entity_id'   => $this->entity->id, 'doctor_payout_id' => $partial->id, 'amount' => 50, 'paid_at' => '2026-07-04',
        'reversed_at' => now(), 'reversal_reason' => 'Lançado errado',
    ]);

    $this->get(route('panel.my-payouts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('payouts.data.0.status', 'partially_paid')
            ->where('payouts.data.0.paid_amount', 100));

    $this->get(route('panel.my-payouts.show', $partial->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('statement.payments', 1)
            ->where('statement.payments.0', ['id' => $valid->id, 'paid_at' => '2026-07-05', 'amount' => 100, 'payment_method' => 'transfer'])
            ->where('statement.payout.remaining_amount', 200));
});

it('estorno do regime anterior (quem estornou e motivo interno) não chega ao médico, nem na tela nem no PDF', function () {
    $this->entity->forceFill(['doctor_payouts_visible' => true])->save();

    $admin  = User::factory()->create(['name' => 'Admin Financeiro']);
    $closed = myPayoutsCreate($this, $this->doctor, 'closed');
    $closed->forceFill([
        'payment_reversal_reason' => 'Conta bancária errada', 'payment_reversed_at' => now(), 'payment_reversed_by' => $admin->id,
    ])->save();
    DoctorPayoutPayment::query()->create([
        'entity_id'      => $this->entity->id, 'doctor_payout_id' => $closed->id, 'amount' => 300, 'paid_at' => '2026-07-05',
        'payment_method' => 'transfer', 'notes' => 'Dados bancários do médico', 'reversed_at' => now(), 'reversal_reason' => 'Conta errada',
    ]);

    $this->get(route('panel.my-payouts.show', $closed->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('statement.payout.payment_reversal_reason', null)
            ->where('statement.payout.payment_reversed_by_name', null)
            ->where('statement.payout.payment_reversed_at', null)
            ->has('statement.payments', 0));

    $pdf = Mockery::mock();
    $pdf->shouldReceive('setPaper', 'setOrientation', 'setOption')->andReturnSelf();
    $pdf->shouldReceive('download')->andReturn(response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']));
    SnappyPdf::shouldReceive('loadView')->once()->withArgs(function (string $view, array $data) {
        $json = json_encode($data['statement']);

        return $data['statement']['payout']['payment_reversal_reason'] === null
            && $data['statement']['payments'] === []
            && ! str_contains($json, 'Admin Financeiro')
            && ! str_contains($json, 'Dados bancários');
    })->andReturn($pdf);

    $this->get(route('panel.my-payouts.pdf', $closed->id))->assertOk();
});

it('o médico baixa o PDF do próprio demonstrativo', function () {
    $this->entity->forceFill(['doctor_payouts_visible' => true])->save();
    $paid = myPayoutsCreate($this, $this->doctor, 'paid');

    $pdf = Mockery::mock();
    $pdf->shouldReceive('setPaper', 'setOrientation', 'setOption')->andReturnSelf();
    $pdf->shouldReceive('download')->andReturn(response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']));
    SnappyPdf::shouldReceive('loadView')->once()->andReturn($pdf);

    $this->get(route('panel.my-payouts.pdf', $paid->id))->assertOk();
});

it('usuário que não é médico não acessa "Meus repasses"', function () {
    $this->entity->forceFill(['doctor_payouts_visible' => true])->save();

    $user = User::factory()->create();
    $eu   = createEntityUser($this->entity, $user, ClientRule::Secretary->value);

    $this->actingAs($user)->withSession(panelSession($eu))
        ->get(route('panel.my-payouts.index'))
        ->assertForbidden();
});

it('o menu Financeiro do admin inclui "Repasse médico" e todas as rotas do menu resolvem', function () {
    $user = User::factory()->create();
    $eu   = createEntityUser($this->entity, $user, ClientRule::Admin->value);
    session(panelSession($eu));

    $nav       = PanelNavigation::build();
    $financial = collect($nav)->firstWhere('key', 'financial');

    expect(array_column($financial['children'], 'route'))->toContain('panel.financial.doctor-payouts.index');

    $routes = collect($nav)->flatMap(fn (array $item) => [$item['route'] ?? null, ...array_column($item['children'] ?? [], 'route')])
        ->filter()
        ->all();

    foreach ($routes as $route) {
        expect(fn () => route($route))->not->toThrow(Throwable::class);
    }
});

it('pt_BR e en têm as mesmas chaves de tradução do módulo', function () {
    $pt = array_keys(Arr::dot(require lang_path('pt_BR/financial_doctor_payouts.php')));
    $en = array_keys(Arr::dot(require lang_path('en/financial_doctor_payouts.php')));

    sort($pt);
    sort($en);

    expect($pt)->toBe($en);
});

it('minimização: o médico não vê nomes da equipe (quem fechou, quem lançou ajuste); a clínica vê', function () {
    $this->entity->forceFill(['doctor_payouts_visible' => true])->save();

    $staff  = User::factory()->create(['name' => 'Fulana do Financeiro']);
    $closed = myPayoutsCreate($this, $this->doctor, 'closed');
    $closed->forceFill(['closed_by' => $staff->id])->save();
    DB::table('doctor_payout_adjustments')->insert([
        'id'          => (string) Str::uuid(), 'entity_id' => $this->entity->id, 'doctor_payout_id' => $closed->id,
        'description' => 'Adiantamento', 'amount' => -10, 'created_by' => $staff->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->get(route('panel.my-payouts.show', $closed->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('statement.payout.closed_by_name', null)
            ->where('statement.adjustments.0.description', 'Adiantamento')
            ->where('statement.adjustments.0.created_by_name', null));

    $clinic = app(DoctorPayoutPresenter::class)->statement($closed->fresh());

    expect($clinic['payout']['closed_by_name'])->toBe($staff->name)
        ->and($clinic['adjustments'][0]['created_by_name'])->toBe($staff->name);
});

it('lista traz o regime (produção recebida × cobrada) e abrir o demonstrativo fica na trilha', function () {
    $this->entity->forceFill(['doctor_payouts_visible' => true])->save();
    $paid = myPayoutsCreate($this, $this->doctor, 'paid');
    $paid->forceFill(['basis' => 'receipt'])->save();

    $this->get(route('panel.my-payouts.index'))
        ->assertInertia(fn ($page) => $page->where('payouts.data.0.basis', 'receipt'));

    $this->get(route('panel.my-payouts.show', $paid->id))->assertOk();

    $log = DB::table('audit_logs')->where('event', 'financial.report.view')->sole();
    expect(json_decode((string) $log->new_values, true)['viewer'])->toBe('doctor');
});

it('isolamento: fechamento do mesmo médico em OUTRA clínica = 404 (tela e PDF); PDF com a opção desligada = 404', function () {
    $this->entity->forceFill(['doctor_payouts_visible' => true])->save();

    $other   = Entity::factory()->create(['is_client' => true, 'active' => true, 'doctor_payouts_visible' => true]);
    $foreign = DoctorPayout::query()->create([
        'entity_id'    => $other->id, 'doctor_id' => $this->doctor->id, 'doctor_name' => 'Dr. Teste',
        'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => 'paid', 'closed_at' => now(),
        'items_count'  => 1, 'gross_amount' => 100, 'items_amount' => 60, 'total_amount' => 60,
    ]);

    $this->get(route('panel.my-payouts.index'))->assertInertia(fn ($page) => $page->has('payouts.data', 0));
    $this->get(route('panel.my-payouts.show', $foreign->id))->assertNotFound();
    $this->get(route('panel.my-payouts.pdf', $foreign->id))->assertNotFound();

    $own = myPayoutsCreate($this, $this->doctor, 'paid');
    $this->entity->forceFill(['doctor_payouts_visible' => false])->save();

    $this->get(route('panel.my-payouts.pdf', $own->id))->assertNotFound();
});

it('ato em que o médico só participa da divisão (não atendeu): paciente por iniciais + código; nos próprios, o nome', function () {
    $this->entity->forceFill(['doctor_payouts_visible' => true])->save();

    $closed  = myPayoutsCreate($this, $this->doctor, 'closed');
    $patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $patient->person?->update(['full_name' => 'JOANA PEREIRA LIMA']);
    $item = fn (string $role, string $source) => DoctorPayoutItem::query()->create([
        'entity_id'        => $this->entity->id, 'doctor_payout_id' => $closed->id, 'doctor_id' => $this->doctor->id,
        'source_type'      => 'schedule', 'source_id' => $source, 'service_type' => 'procedure', 'performed_at' => '2026-06-10 09:00:00',
        'patient_id'       => $patient->id, 'description' => 'FACECTOMIA', 'base_source' => 'received', 'basis' => 'receipt',
        'beneficiary_role' => $role, 'share_percentage' => 40, 'base_amount' => 1000, 'payout_amount' => 240,
    ]);
    $item('doctor', (string) Str::uuid());
    $item('executor', (string) Str::uuid());

    $this->get(route('panel.my-payouts.show', $closed->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('statement.groups.0.items', function ($items) use ($patient) {
            $byRole = collect($items)->keyBy('beneficiary_role');

            return $byRole['doctor']['patient_name'] === app(CovenantReportService::class)->initials('JOANA PEREIRA LIMA')
                && $byRole['doctor']['patient_code'] === $patient->code
                && $byRole['executor']['patient_name'] === 'JOANA PEREIRA LIMA';
        }));

    // A clínica continua vendo o nome em todas as linhas.
    $clinic = app(DoctorPayoutPresenter::class)->statement($closed->fresh());
    expect(collect($clinic['groups'][0]['items'])->pluck('patient_name')->unique()->all())->toBe(['JOANA PEREIRA LIMA']);
});
