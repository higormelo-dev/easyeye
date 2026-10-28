<?php

declare(strict_types=1);

/*
 * `t_ui` (HandleInertiaRequests): textos dos componentes compartilhados do
 * painel — CenteredModal/OffcanvasPanel (fechar, carregando) e Cid10Picker
 * (busca CID-10) — no idioma do usuário, em toda página.
 */

use App\Models\Entity;

beforeEach(function (): void {
    actingAsFinancialEntityUser(Entity::factory()->create(['is_client' => true, 'active' => true]));
});

it('compartilha os textos do Cid10Picker em inglês para quem usa inglês', function (): void {
    $this->withSession(['locale' => 'en'])
        ->get(route('panel.financial.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('t_ui.close', 'Close')
            ->where('t_ui.cid10.placeholder', 'Search by code or diagnosis (e.g. H40.1, glaucoma)…')
            ->where('t_ui.cid10.remove', 'Remove :item'));
});

it('e em português por padrão', function (): void {
    $this->get(route('panel.financial.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('t_ui.close', 'Fechar')
            ->where('t_ui.cid10.most_used', 'Mais usados'));
});
