<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->withoutVite()->get('/reset-password/' . $notification->token);

        $response->assertStatus(200);

        return true;
    });
});

test('esqueci-senha do staff: e-mail existente e inexistente devolvem a MESMA mensagem generica (sem enumeracao de conta)', function () {
    // BUGFIX (revisão de segurança, achada na área do paciente e replicada
    // aqui do mesmo padrão): antes, e-mail sem conta devolvia "Não existe
    // nenhum usuário com o e-mail indicado." — mensagem de erro distinta da
    // de sucesso, permitindo confirmar em 1 requisição se um e-mail tem
    // conta de staff. Agora a resposta é idêntica nos dois casos.
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Se este e-mail estiver cadastrado, você vai receber um link de redefinição de senha.');

    $this->post('/forgot-password', ['email' => 'ninguem-' . uniqid() . '@teste.com'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Se este e-mail estiver cadastrado, você vai receber um link de redefinição de senha.');
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post('/reset-password', [
            'token'                 => $notification->token,
            'email'                 => $user->email,
            'password'              => 'Password1',
            'password_confirmation' => 'Password1',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});
