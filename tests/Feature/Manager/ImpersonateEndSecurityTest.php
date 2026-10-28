<?php

/**
 * Regressão de segurança: encerrar impersonação deve
 *  (a) gravar o audit log de 'impersonation.end' com o ADMIN REAL como ator
 *      (não o usuário impersonado, que troca Auth::user() durante a sessão), e
 *  (b) não bloquear o admin no middleware 'verified' por causa do
 *      email_verified_at do usuário impersonado (que pode ser null).
 *
 * Ver app/Http/Middleware/HandleImpersonation.php e
 * app/Services/Audit/AuditLogger.php::recordImpersonationEnd().
 */

use App\Enums\SaasRule;
use App\Models\{AuditLog, Entity, EntityUser, User};

beforeEach(function () {
    $this->saasEntity   = Entity::factory()->create(['is_client' => false]);
    $this->clientEntity = Entity::factory()->create(['is_client' => true]);

    $this->admin     = User::factory()->create(); // email_verified_at preenchido (factory default)
    $this->adminLink = createEntityUser($this->saasEntity, $this->admin, SaasRule::Admin->value);

    // Staff da clínica ainda não verificou o e-mail (fluxo real do EntityUserService).
    $this->staff     = User::factory()->unverified()->create();
    $this->staffLink = createEntityUser($this->clientEntity, $this->staff, 'admin');
});

function saasAdminSession(EntityUser $adminLink): array
{
    return [
        'selected_entity_id'        => $adminLink->entity_id,
        'selected_entity_user_id'   => $adminLink->id,
        'selected_entity_user_rule' => $adminLink->rule,
        'selected_entity_is_client' => false,
        'user_rule'                 => $adminLink->rule,
    ];
}

function startImpersonation(): void
{
    test()->actingAs(test()->admin)
        ->withSession(saasAdminSession(test()->adminLink))
        ->post(route('manager.entities.impersonate', [
            'entity'     => test()->clientEntity,
            'entityUser' => test()->staffLink,
        ]))
        ->assertRedirect(route('panel.dashboard'));

    expect(session('impersonating.entity_user_id'))->not->toBeNull();
}

test('encerrar impersonação grava o audit log com o admin real como ator, não o usuário impersonado', function () {
    // Arrange: inicia impersonação (grava sessão 'impersonating.*' com o admin real).
    startImpersonation();

    // Act: encerra a impersonação usando a MESMA sessão do browser real
    // (a sessão de impersonação já contém tudo que o destroy() precisa).
    $this->from(route('panel.dashboard'))
        ->delete(route('manager.impersonate.destroy'))
        ->assertRedirect(route('manager.entities.index'));

    // Assert: o audit log de fim de impersonação aponta para o admin real.
    $log = AuditLog::where('event', 'impersonation.end')->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and((string) $log->user_id)->toBe((string) $this->admin->id)
        ->and((string) $log->user_id)->not->toBe((string) $this->staff->id);
});

test('admin consegue encerrar impersonação de staff sem e-mail verificado sem ser bloqueado pelo middleware verified', function () {
    // Arrange
    startImpersonation();

    expect($this->staff->email_verified_at)->toBeNull();

    // Act
    $response = $this->delete(route('manager.impersonate.destroy'));

    // Assert: não deve ser redirecionado para verify-email (bloqueio do middleware 'verified')
    $response->assertRedirect(route('manager.entities.index'));
    $response->assertSessionMissing('impersonating');
});
