<?php

/*
 * "Meus repasses" (médico) + menu + paridade de traduções do módulo.
 *
 * O médico só vê os PRÓPRIOS fechamentos (fechados e pagos) e só se a clínica
 * ligou entities.doctor_payouts_visible; o resto é 404 — inclusive fechamento
 * de outro médico ou cancelado. O item de menu segue a mesma regra.
 */

use App\Enums\ClientRule;
use App\Models\{DoctorPayout, Entity, User};
use App\Support\PanelNavigation;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Support\Arr;

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
    expect(myPayoutsNavKeys(PanelNavigation::build()))->toContain('my-payouts');
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
