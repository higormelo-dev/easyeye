<?php

use App\Enums\{BillingClaimStatus, ClientRule};
use App\Http\Controllers\Financial\FinancialReportsController;
use App\Models\{BillingClaim, Covenant, Entity, User};

beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
});

it('nega acesso a médico (role sem permissão financeira) nas rotas de glosa', function () {
    $doctor     = createDoctorForEntity($this->entity);
    $entityUser = $doctor->entityUser;

    $this->actingAs($entityUser->user)
        ->withSession(panelSession($entityUser))
        ->get(route('panel.financial.tiss.glosas.index'))
        ->assertForbidden();
});

it('sanitiza fórmula em célula de export CSV pra não executar no Excel', function () {
    $controller = app(FinancialReportsController::class);
    $method     = new ReflectionMethod($controller, 'sanitizeCellValue');
    $method->setAccessible(true);

    expect($method->invoke($controller, '=CMD(calc)'))->toBe("'=CMD(calc)")
        ->and($method->invoke($controller, '+1+1'))->toBe("'+1+1")
        ->and($method->invoke($controller, 'PACIENTE NORMAL'))->toBe('PACIENTE NORMAL')
        ->and($method->invoke($controller, 150.5))->toBe(150.5);
});

it('rejeita marcar guia como paga com valor maior que o valor da guia', function () {
    $entity     = $this->entity;
    $user       = User::factory()->create();
    $entityUser = createEntityUser($entity, $user, ClientRule::Admin->value, isOwner: true);

    $claim = BillingClaim::query()->create([
        'entity_id'       => $entity->id,
        'covenant_id'     => $this->covenant->id,
        'status'          => BillingClaimStatus::Submitted->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => 100.00,
        'quantity'        => 1,
        'unit_price'      => 100.00,
    ]);

    $this->actingAs($user)
        ->withSession(panelSession($entityUser))
        ->post(route('panel.financial.billing.claims.paid', $claim->id), ['paid_amount' => 999.99])
        ->assertSessionHasErrors('paid_amount');

    expect($claim->fresh()->status)->toBe(BillingClaimStatus::Submitted);
});

it('resolveRouteBinding aborta quando a sessão não tem entidade selecionada', function () {
    $user  = User::factory()->create();
    $claim = BillingClaim::query()->create([
        'entity_id'       => $this->entity->id,
        'covenant_id'     => $this->covenant->id,
        'status'          => BillingClaimStatus::Draft->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => 100.00,
        'quantity'        => 1,
        'unit_price'      => 100.00,
    ]);

    $this->actingAs($user)
        ->withSession(['selected_entity_id' => null])
        ->post(route('panel.financial.billing.claims.paid', $claim->id), [])
        ->assertForbidden();
});
