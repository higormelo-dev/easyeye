<?php

/**
 * Guard dedicado do Portal do Paciente (item 5 + risco de segurança "Guard e
 * tabela dedicados, NUNCA reusar users/guard web"). Cobre:
 *  - login do paciente autentica SOMENTE no guard "patient";
 *  - uma sessão staff já aberta no guard "web" continua intacta;
 *  - credenciais inválidas não autenticam;
 *  - kill-switch (active=false) impede login mesmo com senha correta.
 */

use App\Models\{PatientAccount, People, User};
use App\Notifications\PatientPasswordReset;
use Illuminate\Support\Facades\{Auth, Notification};

test('login do paciente autentica somente no guard patient, sem afetar sessao web de staff', function () {
    $staff = User::factory()->create();

    $this->post('/login', [
        'email'    => $staff->email,
        'password' => 'password',
    ])->assertRedirect();

    expect(Auth::guard('web')->check())->toBeTrue();
    expect(Auth::guard('web')->id())->toBe($staff->id);
    expect(Auth::guard('patient')->check())->toBeFalse();

    $person  = People::factory()->create();
    $account = PatientAccount::factory()->create([
        'person_id' => $person->id,
        'email'     => 'paciente@teste.com',
    ]);

    $this->post(route('patient-portal.login.store'), [
        'email'    => $account->email,
        'password' => 'password',
    ])->assertRedirect(route('patient-portal.dashboard'));

    expect(Auth::guard('patient')->check())->toBeTrue();
    expect(Auth::guard('patient')->id())->toBe($account->id);

    // A sessão do staff no guard "web" continua intacta — o login do
    // paciente usa Auth::guard('patient') explicitamente, nunca
    // Auth::attempt()/Auth::login() sem guard.
    expect(Auth::guard('web')->check())->toBeTrue();
    expect(Auth::guard('web')->id())->toBe($staff->id);
});

test('login do paciente falha com senha incorreta', function () {
    $person  = People::factory()->create();
    $account = PatientAccount::factory()->create([
        'person_id' => $person->id,
        'email'     => 'paciente@teste.com',
    ]);

    $this->post(route('patient-portal.login.store'), [
        'email'    => $account->email,
        'password' => 'senha-errada',
    ])->assertSessionHasErrors('email');

    expect(Auth::guard('patient')->check())->toBeFalse();
});

test('conta desativada (kill-switch) nao consegue logar mesmo com senha correta', function () {
    $person  = People::factory()->create();
    $account = PatientAccount::factory()->create([
        'person_id' => $person->id,
        'email'     => 'paciente@teste.com',
        'active'    => false,
    ]);

    $this->post(route('patient-portal.login.store'), [
        'email'    => $account->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    expect(Auth::guard('patient')->check())->toBeFalse();
});

test('logout do paciente encerra somente o guard patient', function () {
    $person  = People::factory()->create();
    $account = PatientAccount::factory()->create(['person_id' => $person->id]);

    $this->actingAs($account, 'patient')
        ->post(route('patient-portal.logout'))
        ->assertRedirect(route('patient-portal.login'));

    expect(Auth::guard('patient')->check())->toBeFalse();
});

test('esqueci-senha do paciente envia o link sem quebrar (regressao: PatientAccount precisa de Notifiable)', function () {
    // CanResetPassword::sendPasswordResetNotification() chama $this->notify(),
    // método que só existe via o trait Notifiable — sem ele, este endpoint
    // retornava 500 (achado da revisão de segurança da Fase 1).
    $person  = People::factory()->create();
    $account = PatientAccount::factory()->create([
        'person_id' => $person->id,
        'email'     => 'paciente-reset@teste.com',
    ]);

    $this->post(route('patient-portal.password.email'), [
        'email' => $account->email,
    ])->assertSessionHasNoErrors();
});

test('reset de senha do paciente usa notificacao propria com link pra rota do PORTAL, nao a de staff', function () {
    // BUGFIX (revisão de segurança): PatientAccount sem override de
    // sendPasswordResetNotification() disparava a notificação PADRÃO do
    // Laravel, que monta o link via route('password.reset', ...) — nome que
    // já pertence à tela de reset do STAFF. Todo paciente recebia link pra
    // tela errada (guard "web", broker "users") e o reset falhava sempre.
    Notification::fake();

    $person  = People::factory()->create();
    $account = PatientAccount::factory()->create(['person_id' => $person->id]);

    $this->post(route('patient-portal.password.email'), ['email' => $account->email]);

    Notification::assertSentTo($account, PatientPasswordReset::class, function (PatientPasswordReset $notification) use ($account) {
        $mail = $notification->toMail($account);

        // Rota do PORTAL (patient-portal.password.reset), nunca a de staff
        // (/reset-password/{token}, nome de rota "password.reset" puro).
        expect($mail->actionUrl)->toContain('/portal-paciente/redefinir-senha/');
        expect($mail->actionUrl)->not->toContain('/reset-password/');

        return true;
    });
});

test('esqueci-senha do paciente: e-mail existente devolve so a mensagem generica', function () {
    $account = PatientAccount::factory()->create();

    $this->post(route('patient-portal.password.email'), ['email' => $account->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Se este e-mail estiver cadastrado, você vai receber um link de redefinição de senha.');
});

test('esqueci-senha do paciente: e-mail inexistente devolve a MESMA mensagem generica (sem enumeracao de conta)', function () {
    // BUGFIX (revisão de segurança): antes, e-mail sem conta devolvia "Não
    // existe nenhum usuário com o e-mail indicado." — mensagem de erro
    // distinta da de sucesso, permitindo confirmar em 1 requisição se um
    // e-mail tem conta ativa no portal (dado sensível: "esta pessoa é
    // paciente aqui"). Agora a resposta é idêntica nos dois casos.
    $this->post(route('patient-portal.password.email'), [
        'email' => 'ninguem-' . uniqid() . '@teste.com',
    ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Se este e-mail estiver cadastrado, você vai receber um link de redefinição de senha.');
});
