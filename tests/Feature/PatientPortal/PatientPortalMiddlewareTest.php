<?php

/**
 * EnsurePatientAuthenticated (alias "patient.auth") — cobre o risco de
 * segurança/verificação explícita: "teste que rota patient.auth sem sessao
 * retorna 401/redirect".
 *
 * As duas primeiras cobrem a rota real do dashboard. As demais usam uma rota
 * de teste temporária protegida só por `patient.auth` (mesmo padrão de
 * tests/Feature/ACL/AclMiddlewareTest.php) — isola o middleware do resto do
 * pipeline Inertia (HandleInertiaRequests::share()/Vite), que não é o que
 * este arquivo quer testar.
 */

use App\Models\{PatientAccount, People};
use Illuminate\Support\Facades\{Auth, Route};

beforeEach(function () {
    Route::middleware(['web', 'patient.auth'])
        ->get('/_test/patient-only', fn () => response()->json(['ok' => true]));
});

test('rota protegida por patient.auth retorna 401 JSON para chamada fetch/Inertia sem sessao', function () {
    $this->getJson(route('patient-portal.dashboard'))
        ->assertUnauthorized()
        ->assertJsonStructure(['message']);
});

test('rota protegida por patient.auth redireciona para o login em navegacao normal sem sessao', function () {
    $this->get(route('patient-portal.dashboard'))
        ->assertRedirect(route('patient-portal.login'));
});

test('patient.auth permite acesso com sessao valida no guard patient', function () {
    $account = PatientAccount::factory()->create(['person_id' => People::factory()->create()->id]);

    $this->actingAs($account, 'patient')
        ->getJson('/_test/patient-only')
        ->assertOk();
});

test('patient.auth nega com 401 JSON sem sessao', function () {
    $this->getJson('/_test/patient-only')
        ->assertUnauthorized()
        ->assertJsonStructure(['message']);
});

test('kill-switch: conta desativada durante a sessao e deslogada em tempo real na proxima requisicao', function () {
    $account = PatientAccount::factory()->create([
        'person_id' => People::factory()->create()->id,
        'active'    => true,
    ]);

    $this->actingAs($account, 'patient');

    $this->getJson('/_test/patient-only')->assertOk();

    // Suporte desativa a conta enquanto a sessão (em tese) ainda está viva.
    // Atribuição direta (não update()/fill()): "active" fica de fora de
    // $fillable de propósito (nunca mass-assignable pelo próprio paciente),
    // então uma ferramenta de suporte/CLI alternaria assim. $account é a
    // MESMA instância que o guard mantém em cache durante o teste — a
    // mutação reflete no próximo guard->user() do middleware.
    $account->active = false;
    $account->save();

    // EnsurePatientAuthenticated verifica ->active a cada request e desloga
    // imediatamente, sem esperar o próximo login.
    $this->getJson('/_test/patient-only')->assertUnauthorized();

    expect(Auth::guard('patient')->check())->toBeFalse();
});

test('login efetuado no guard patient nunca autentica no guard web', function () {
    $account = PatientAccount::factory()->create(['person_id' => People::factory()->create()->id]);

    $this->actingAs($account, 'patient')->getJson('/_test/patient-only');

    expect(Auth::guard('web')->check())->toBeFalse();
});
