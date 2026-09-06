<?php

declare(strict_types=1);

use App\Enums\PartnerType;
use App\Models\{Entity, Partner, PartnerCommission, Plan, Subscription};
use App\Services\PartnerService;

/**
 * BUGFIX (revisao de seguranca): SubscriptionObserver::updated() chama generateCommission()
 * a cada transição de status para Active. Sem checagem de idempotência, uma assinatura que
 * oscila (ex.: falha de pagamento seguida de retry bem-sucedido) dentro do mesmo período de
 * competência gerava uma comissão duplicada, inflando o valor devido ao parceiro.
 */
test('nao duplica comissao quando a assinatura oscila de Active para past_due e volta a Active no mesmo periodo', function () {
    // Arrange
    $partner = Partner::create([
        'name'            => 'Parceiro Teste',
        'email'           => 'parceiro@example.com',
        'type'            => PartnerType::Distributor,
        'commission_rate' => 15.0,
        'token'           => 'tok-' . uniqid(),
        'status'          => 'active',
    ]);

    $plan         = Plan::factory()->create(['price' => 100.0]);
    $entity       = Entity::factory()->create(['is_client' => true, 'partner_id' => $partner->id]);
    $subscription = Subscription::factory()->for($entity)->for($plan)->create();

    $service = new PartnerService();

    // Act: simula a assinatura ativando, caindo para past_due e voltando a Active
    // dentro do mesmo período de competência (mesmo mês).
    $first  = $service->generateCommission($subscription);
    $second = $service->generateCommission($subscription);

    // Assert
    expect(PartnerCommission::where('subscription_id', $subscription->id)->count())->toBe(1);
    expect($second->id)->toBe($first->id);
});

test('gera nova comissao para um periodo de competencia genuinamente novo na mesma assinatura', function () {
    // Arrange
    $partner = Partner::create([
        'name'            => 'Parceiro Teste 2',
        'email'           => 'parceiro2@example.com',
        'type'            => PartnerType::Distributor,
        'commission_rate' => 15.0,
        'token'           => 'tok-' . uniqid(),
        'status'          => 'active',
    ]);

    $plan         = Plan::factory()->create(['price' => 100.0]);
    $entity       = Entity::factory()->create(['is_client' => true, 'partner_id' => $partner->id]);
    $subscription = Subscription::factory()->for($entity)->for($plan)->create();

    $service = new PartnerService();

    // Act
    $service->generateCommission($subscription);

    // Cria manualmente uma comissão "do mês passado" apontando para a mesma assinatura
    // e chama generateCommission novamente simulando o mês atual: deve gerar uma nova linha
    // distinta da comissão do período anterior.
    PartnerCommission::where('subscription_id', $subscription->id)->update([
        'period' => now()->subMonthNoOverflow()->format('Y-m'),
    ]);

    $second = $service->generateCommission($subscription);

    // Assert
    expect(PartnerCommission::where('subscription_id', $subscription->id)->count())->toBe(2);
    expect($second->period)->toBe(now()->format('Y-m'));
});
