<?php

use App\Enums\ClientRule;
use App\Models\{Entity, User};

/**
 * O módulo Eye Images virou Gerenciador de exames (/panel/exam-manager). O caminho legado continua
 * respondendo 301 para favoritos, links de e-mail de rascunho e o `review_url` que integradores já gravaram.
 * O destino é sempre o prefixo fixo do módulo novo: nada vindo da requisição escolhe o host nem o prefixo.
 */
beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->user       = User::factory()->create();
    $this->membership = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
});

it('redirects the legacy index to the Exam manager keeping the query string', function () {
    $this->actingAs($this->user)->withSession(panelSession($this->membership));

    $this->get('/panel/eye-images?patient_id=1&exam_id=2')
        ->assertStatus(301)
        ->assertRedirect(url('/panel/exam-manager?patient_id=1&exam_id=2'));
});

it('redirects the legacy index without a trailing question mark when there is no query string', function () {
    $this->actingAs($this->user)->withSession(panelSession($this->membership));

    $response = $this->get('/panel/eye-images')->assertStatus(301);

    expect($response->headers->get('Location'))->toBe(url('/panel/exam-manager'));
});

it('redirects legacy sub-paths to the same sub-path under the Exam manager', function () {
    $this->actingAs($this->user)->withSession(panelSession($this->membership));
    $draft = '9f1c2d3e-4a5b-4c6d-8e7f-0a1b2c3d4e5f';

    $this->get("/panel/eye-images/report-drafts/{$draft}")
        ->assertStatus(301)
        ->assertRedirect(url("/panel/exam-manager/report-drafts/{$draft}"));
});

it('keeps sub-path and query string together', function () {
    $this->actingAs($this->user)->withSession(panelSession($this->membership));

    $this->get('/panel/eye-images/patient-exams/7?exam_id=3&search=maria%20silva')
        ->assertStatus(301)
        ->assertRedirect(url('/panel/exam-manager/patient-exams/7?exam_id=3&search=maria%20silva'));
});

it('never turns the path into an external redirect', function (string $uri) {
    $this->actingAs($this->user)->withSession(panelSession($this->membership));

    $response = $this->get($uri)->assertStatus(301);
    $location = (string) $response->headers->get('Location');

    expect($location)->toBe(url('/panel/exam-manager'))
        ->and($location)->not->toContain('evil.example');
})->with([
    'double slash'         => '/panel/eye-images//evil.example',
    'encoded double slash' => '/panel/eye-images/%2f%2fevil.example',
    'backslash'            => '/panel/eye-images/%5Cevil.example',
    'dot segments'         => '/panel/eye-images/../../evil.example',
    'scheme'               => '/panel/eye-images/https:%2f%2fevil.example',
]);

it('sends visitors to the login instead of redirecting to the module', function () {
    $this->get('/panel/eye-images?patient_id=1&exam_id=2')->assertRedirect(route('login'));
});

it('serves the Exam manager on its new named route', function () {
    $this->actingAs($this->user)->withSession(panelSession($this->membership));

    expect(route('panel.exam-manager.index', [], false))->toBe('/panel/exam-manager');
    $this->get(route('panel.exam-manager.index'))->assertOk();
});
