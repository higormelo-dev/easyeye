<?php

/**
 * Aceite de convite via link assinado (item 6 + risco de segurança "Link de
 * convite assinado com expiracao de 3 dias, nao reutilizavel apos uso").
 */

use App\Models\{PatientAccount, People};
use Illuminate\Support\Facades\{Auth, URL};

test('link assinado valido cria a conta, loga no guard patient e redireciona ao dashboard', function () {
    $person = People::factory()->create(['email' => 'novo@teste.com', 'full_name' => 'Novo Paciente']);
    $url    = URL::temporarySignedRoute(
        'patient-portal.invitation.accept',
        now()->addDays(3),
        ['person_id' => $person->id],
    );

    // inertiaHeaders(): resposta Inertia pura em JSON — evita depender de
    // `npm run build` neste ambiente (ver PatientPortalDashboardTest.php).
    $this->get($url, inertiaHeaders())
        ->assertOk()
        ->assertJsonPath('component', 'PatientPortal/Auth/AcceptInvitation')
        ->assertJsonPath('props.personId', $person->id);

    $response = $this->post($url, [
        'password'              => 'senha-super-segura-1',
        'password_confirmation' => 'senha-super-segura-1',
    ]);

    $response->assertRedirect(route('patient-portal.dashboard'));

    $this->assertDatabaseCount('patient_accounts', 1);
    $account = PatientAccount::where('person_id', $person->id)->firstOrFail();
    expect($account->email)->toBe('novo@teste.com');
    expect($account->email_verified_at)->not->toBeNull();
    expect(Auth::guard('patient')->id())->toBe($account->id);
});

test('reutilizar o mesmo link apos a conta criada nao duplica e manda para o login', function () {
    $person = People::factory()->create(['email' => 'novo2@teste.com']);
    $url    = URL::temporarySignedRoute(
        'patient-portal.invitation.accept',
        now()->addDays(3),
        ['person_id' => $person->id],
    );

    $this->post($url, [
        'password'              => 'senha-super-segura-1',
        'password_confirmation' => 'senha-super-segura-1',
    ])->assertRedirect(route('patient-portal.dashboard'));

    $this->assertDatabaseCount('patient_accounts', 1);

    // Segunda tentativa com o MESMO link assinado: nem GET nem POST duplicam.
    $this->get($url)->assertRedirect(route('patient-portal.login'));

    $this->post($url, [
        'password'              => 'outra-senha-valida-2',
        'password_confirmation' => 'outra-senha-valida-2',
    ])->assertRedirect(route('patient-portal.login'));

    $this->assertDatabaseCount('patient_accounts', 1);
});

test('link expirado (assinatura vencida) e rejeitado pelo middleware signed', function () {
    $person = People::factory()->create();
    $url    = URL::temporarySignedRoute(
        'patient-portal.invitation.accept',
        now()->subDay(),
        ['person_id' => $person->id],
    );

    $this->get($url)->assertForbidden();
});

test('adulterar o person_id da querystring invalida a assinatura', function () {
    $person      = People::factory()->create();
    $outroPerson = People::factory()->create();
    $url         = URL::temporarySignedRoute(
        'patient-portal.invitation.accept',
        now()->addDays(3),
        ['person_id' => $person->id],
    );

    $tampered = str_replace('person_id=' . $person->id, 'person_id=' . $outroPerson->id, $url);

    $this->get($tampered)->assertForbidden();
});
