<?php

declare(strict_types=1);

use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

/**
 * Save do Inertia com a sessão expirada (CSRF → 419): antes, o handler
 * respondia redirect()->back(); o XHR seguia até o login e trocava a página,
 * descartando o que estava digitado (ex.: prontuário novo). Agora a resposta
 * é não-Inertia (diálogo do Inertia) e a tela continua montada.
 */
beforeEach(function () {
    Route::middleware('web')->post('/_test/session-expired', fn () => throw new TokenMismatchException());
});

it('sessão expirada em visita Inertia responde 419 com a mensagem, sem redirecionar', function () {
    $response = $this->withHeaders(['X-Inertia' => 'true'])->post('/_test/session-expired');

    $response->assertStatus(419);
    expect($response->headers->has('Location'))->toBeFalse()
        ->and($response->headers->has('X-Inertia'))->toBeFalse()
        ->and($response->getContent())->toContain(e(__('auth.session_expired_unsaved')));
});

it('mensagem de sessão expirada existe em pt_BR e en', function () {
    expect(trans('auth.session_expired_unsaved', [], 'pt_BR'))->not->toBe('auth.session_expired_unsaved')
        ->and(trans('auth.session_expired_unsaved', [], 'en'))->not->toBe('auth.session_expired_unsaved');
});
