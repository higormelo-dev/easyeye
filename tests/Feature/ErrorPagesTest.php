<?php

/**
 * Páginas de erro com o visual do sistema (respond() em bootstrap/app.php):
 *  - navegação FULL-PAGE em erro HTTP renderiza a página Inertia `Error`
 *    (shell panel-app em /panel/*), preservando o status code;
 *  - navegação SPA (X-Inertia) mantém o redirect-back com flash;
 *  - JSON/API mantêm os handlers JSON.
 */

use App\Models\{Entity, User};

function errorPageSession(Entity $clinic): array
{
    return [
        'selected_entity_id'        => $clinic->id,
        'selected_entity_is_client' => true,
        'selected_entity_user_rule' => 'secretary',
    ];
}

beforeEach(function () {
    $this->clinic = Entity::factory()->create(['is_client' => true]);
    $this->user   = User::factory()->create();
    createEntityUser($this->clinic, $this->user, 'secretary');
});

test('403 full-page renderiza a página Error do sistema com o status', function () {
    $response = $this->actingAs($this->user)
        ->withSession(errorPageSession($this->clinic))
        ->get('/panel/financial/cash-flow');

    $response->assertForbidden();
    $page = $response->viewData('page');
    expect($page['component'])->toBe('Error');
    expect($page['props']['status'])->toBe(403);
});

test('404 em /panel/* renderiza a página Error 404', function () {
    $response = $this->actingAs($this->user)
        ->withSession(errorPageSession($this->clinic))
        ->get('/panel/rota-que-nao-existe-xyz');

    $response->assertNotFound();
    $page = $response->viewData('page');
    expect($page['component'])->toBe('Error');
    expect($page['props']['status'])->toBe(404);
});

test('navegação SPA (X-Inertia) NÃO cai na página Error (fluxo Inertia preservado)', function () {
    // Sem X-Inertia-Version válida o Inertia responde 409 (conflito de
    // versão) antes de qualquer handler; com versão válida, o handler de
    // HttpException devolve redirect-back com flash. Nos dois casos, o
    // respond() das páginas de erro deve IGNORAR requests X-Inertia.
    $response = $this->actingAs($this->user)
        ->withSession(errorPageSession($this->clinic))
        ->from('/panel/dashboard')
        ->get('/panel/financial/cash-flow', ['X-Inertia' => 'true']);

    expect($response->getStatusCode())->toBeIn([302, 303, 409]);
    expect((string) $response->getContent())->not->toContain('"component":"Error"');
});

test('requests JSON mantêm resposta JSON de erro', function () {
    $this->actingAs($this->user)
        ->withSession(errorPageSession($this->clinic))
        ->getJson('/panel/financial/cash-flow')
        ->assertForbidden()
        ->assertJsonStructure(['message']);
});
