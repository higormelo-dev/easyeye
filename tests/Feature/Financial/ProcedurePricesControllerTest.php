<?php

declare(strict_types=1);

use App\Models\{Covenant, Entity, Procedure, ProcedurePrice};

/**
 * Tela panel/financial/procedure-prices: covenant_id da query nunca chega cru
 * ao PostgreSQL, textos vêm do lang dedicado, flash no padrão do layout e o
 * recadastro de um preço apagado não derruba o lote.
 */
beforeEach(function (): void {
    $this->entity    = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant  = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'AAA Convênio']);
    $this->procedure = Procedure::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

it('ignora covenant_id inválido na query (sem erro 500) e seleciona o primeiro convênio', function (): void {
    $this->get(route('panel.financial.procedure-prices.index', ['covenant_id' => 'abc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Panel/Financial/ProcedurePrices/Index')
            ->where('selectedCovenantId', $this->covenant->id));
});

it('não aceita convênio de outra clínica na query (cai no primeiro convênio disponível)', function (): void {
    $otherEntity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherCovenant = Covenant::factory()->create(['entity_id' => $otherEntity->id, 'active' => true]);

    $this->get(route('panel.financial.procedure-prices.index', ['covenant_id' => $otherCovenant->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('selectedCovenantId', $this->covenant->id));
});

it('devolve o convênio escolhido e os preços dele', function (): void {
    ProcedurePrice::query()->create([
        'entity_id'    => $this->entity->id,
        'covenant_id'  => $this->covenant->id,
        'procedure_id' => $this->procedure->id,
        'price'        => 210.5,
        'charging'     => false,
    ]);

    $this->get(route('panel.financial.procedure-prices.index', ['covenant_id' => $this->covenant->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('selectedCovenantId', $this->covenant->id)
            ->where("prices.{$this->procedure->id}.price", 210.5)
            ->where("prices.{$this->procedure->id}.charging", false));
});

it('envia os textos do lang dedicado (efeito do "Cobrar do convênio" explicado) e breadcrumbs traduzidos', function (): void {
    app()->setLocale('pt_BR');

    $this->get(route('panel.financial.procedure-prices.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('t.charging', __('financial_procedure_prices.charging'))
            ->has('t.charging_help')
            ->where('breadcrumbs.1.label', __('financial_procedure_prices.breadcrumb_financial'))
            ->where('breadcrumbs.2.label', __('financial_procedure_prices.title')));
});

it('salva com flash "success" (exibido pelo layout) e volta para a mesma URL', function (): void {
    $listUrl = route('panel.financial.procedure-prices.index', ['covenant_id' => $this->covenant->id]);

    $this->withHeader('Referer', $listUrl)
        ->post(route('panel.financial.procedure-prices.store'), [
            'covenant_id' => $this->covenant->id,
            'items'       => [['procedure_id' => $this->procedure->id, 'price' => '150,00', 'charging' => true]],
        ])
        ->assertRedirect($listUrl)
        ->assertSessionHas('success', __('financial_procedure_prices.saved'));
});

it('recadastra via HTTP um preço apagado sem erro (restaura a linha)', function (): void {
    $payload = fn (?string $price) => [
        'covenant_id' => $this->covenant->id,
        'items'       => [['procedure_id' => $this->procedure->id, 'price' => $price, 'charging' => true]],
    ];

    $this->post(route('panel.financial.procedure-prices.store'), $payload('100,00'))->assertSessionHasNoErrors();
    $this->post(route('panel.financial.procedure-prices.store'), $payload(null))->assertSessionHasNoErrors();
    $this->post(route('panel.financial.procedure-prices.store'), $payload('120,00'))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(ProcedurePrice::withTrashed()
        ->where('entity_id', $this->entity->id)
        ->where('covenant_id', $this->covenant->id)
        ->where('procedure_id', $this->procedure->id)
        ->count())->toBe(1)
        ->and(ProcedurePrice::query()->where('procedure_id', $this->procedure->id)->value('price'))->toEqual('120.00');
});

it('nomeia o campo nas mensagens de validação por linha (não "items.0.price")', function (): void {
    app()->setLocale('pt_BR');

    $this->post(route('panel.financial.procedure-prices.store'), [
        'covenant_id' => $this->covenant->id,
        'items'       => [['procedure_id' => $this->procedure->id, 'price' => '-5']],
    ])->assertSessionHasErrors('items.0.price');

    $message = session('errors')->first('items.0.price');

    expect($message)->not->toContain('items.0.price')
        ->and($message)->toContain(__('financial_procedure_prices.price_attribute'));
});

it('responde erro de validação (não 500) quando um item do lote não é objeto', function (): void {
    $this->post(route('panel.financial.procedure-prices.store'), [
        'covenant_id' => $this->covenant->id,
        'items'       => ['lixo'],
    ])->assertSessionHasErrors('items.0.procedure_id');
});
