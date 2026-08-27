<?php

/**
 * Verificação de WhatsApp do responsável no registro (/register).
 *
 * Cobre:
 *  - captura: company_phone agora é validado e persistido (users.phone +
 *    entities.cellphone) — antes era coletado na UI e descartado;
 *  - envio: código OTP disparado pós-registro quando a instância global
 *    Z-API está operacional (job na fila; hash sha256 no banco, nunca o
 *    código em claro);
 *  - confirmação: código correto marca phone_verified_at; errado consome
 *    tentativas (5 invalidam); expirado nunca valida;
 *  - rotas: throttle e autenticação.
 */

use App\Jobs\WhatsApp\SendPhoneVerificationCodeJob;
use App\Models\Entity;
use App\Models\{Plan, User};
use App\Models\WhatsApp\WhatsAppSetting;
use App\Services\Auth\PhoneVerificationService;
use Illuminate\Support\Facades\Queue;

function operationalGlobalWhatsApp(): WhatsAppSetting
{
    return WhatsAppSetting::create([
        'entity_id'     => null,
        'active'        => true,
        'webhook_token' => WhatsAppSetting::generateWebhookToken(),
        'credentials'   => [
            'instance_id'    => 'test-instance',
            'instance_token' => 'test-token',
            'client_token'   => 'test-client',
        ],
    ]);
}

function userWithPhone(array $overrides = []): User
{
    return User::factory()->create(array_merge(['phone' => '11988887777'], $overrides));
}

// ── Registro captura e valida o WhatsApp ─────────────────────────────────────

test('registro exige company_phone válido', function () {
    $payload = [
        'name'                  => 'Responsável Teste',
        'email'                 => 'resp@empresa-teste.com',
        'password'              => 'SenhaForte@123',
        'password_confirmation' => 'SenhaForte@123',
        'company_name'          => 'Clínica Nova Visão',
        'company_phone'         => '123',
    ];

    $this->postJson('/register', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['company_phone']);
});

test('registro persiste o WhatsApp no user e na entity e dispara o código', function () {
    Queue::fake();
    operationalGlobalWhatsApp();
    Plan::factory()->create(['sort_order' => 1]);

    $payload = [
        'name'                  => 'Responsável Teste',
        'email'                 => 'resp@empresa-teste.com',
        'password'              => 'SenhaForte@123',
        'password_confirmation' => 'SenhaForte@123',
        'company_name'          => 'Clínica Nova Visão',
        'company_phone'         => '(11) 98888-7777',
    ];

    $this->postJson('/register', $payload)->assertOk();

    $user = User::where('email', 'resp@empresa-teste.com')->firstOrFail();

    expect($user->phone)->toBe('11988887777');
    expect($user->phone_verified_at)->toBeNull();
    // Hash do código gravado — nunca o código em claro (64 hex = sha256).
    expect($user->phone_verification_code)->toMatch('/^[0-9a-f]{64}$/');
    expect($user->phone_verification_expires_at)->not->toBeNull();

    expect(Entity::where('email', 'resp@empresa-teste.com')->firstOrFail()->cellphone)
        ->toBe('11988887777');

    Queue::assertPushed(SendPhoneVerificationCodeJob::class, function (SendPhoneVerificationCodeJob $job) use ($user) {
        return $job->userId === $user->id
            && $job->phone === '5511988887777'
            && preg_match('/^\d{6}$/', $job->code) === 1;
    });
});

test('registro NÃO quebra quando instância global de WhatsApp não existe', function () {
    Queue::fake();
    Plan::factory()->create(['sort_order' => 1]);

    $payload = [
        'name'                  => 'Responsável Teste',
        'email'                 => 'resp2@empresa-teste.com',
        'password'              => 'SenhaForte@123',
        'password_confirmation' => 'SenhaForte@123',
        'company_name'          => 'Clínica Sem Zap',
        'company_phone'         => '11988887777',
    ];

    $this->postJson('/register', $payload)->assertOk();

    Queue::assertNotPushed(SendPhoneVerificationCodeJob::class);
    expect(User::where('email', 'resp2@empresa-teste.com')->firstOrFail()->phone)->toBe('11988887777');
});

// ── Serviço: confirmação do código ───────────────────────────────────────────

test('código correto confirma o telefone e limpa o estado', function () {
    Queue::fake();
    operationalGlobalWhatsApp();

    $user = userWithPhone();
    app(PhoneVerificationService::class)->sendCode($user);

    $code = null;
    Queue::assertPushed(SendPhoneVerificationCodeJob::class, function ($job) use (&$code) {
        $code = $job->code;

        return true;
    });

    expect(app(PhoneVerificationService::class)->verify($user, $code))->toBeTrue();

    $user->refresh();
    expect($user->phone_verified_at)->not->toBeNull();
    expect($user->phone_verification_code)->toBeNull();
});

test('código errado consome tentativas e 5 erros invalidam até o correto', function () {
    Queue::fake();
    operationalGlobalWhatsApp();

    $user = userWithPhone();
    app(PhoneVerificationService::class)->sendCode($user);

    $code = null;
    Queue::assertPushed(SendPhoneVerificationCodeJob::class, function ($job) use (&$code) {
        $code = $job->code;

        return true;
    });

    $service = app(PhoneVerificationService::class);

    foreach (range(1, 5) as $i) {
        expect($service->verify($user, '000000'))->toBeFalse();
    }

    // Estourou MAX_ATTEMPTS: nem o código correto passa mais.
    expect($service->verify($user->refresh(), $code))->toBeFalse();
    expect($user->refresh()->phone_verified_at)->toBeNull();
});

test('código expirado nunca valida', function () {
    $user = userWithPhone([
        'phone_verification_code'       => hash('sha256', '123456'),
        'phone_verification_expires_at' => now()->subMinute(),
    ]);

    expect(app(PhoneVerificationService::class)->verify($user, '123456'))->toBeFalse();
});

// ── Rotas HTTP ───────────────────────────────────────────────────────────────

test('confirmação via rota marca verificado', function () {
    $user = userWithPhone([
        'phone_verification_code'       => hash('sha256', '654321'),
        'phone_verification_expires_at' => now()->addMinutes(5),
    ]);

    $this->actingAs($user)
        ->postJson(route('phone.verification.confirm'), ['code' => '654321'])
        ->assertOk()
        ->assertJson(['verified' => true]);

    expect($user->refresh()->phone_verified_at)->not->toBeNull();
});

test('código inválido na rota retorna 422', function () {
    $user = userWithPhone([
        'phone_verification_code'       => hash('sha256', '654321'),
        'phone_verification_expires_at' => now()->addMinutes(5),
    ]);

    $this->actingAs($user)
        ->postJson(route('phone.verification.confirm'), ['code' => '111111'])
        ->assertStatus(422);
});

test('reenvio sem instância global responde 503 sem quebrar', function () {
    $user = userWithPhone();

    $this->actingAs($user)
        ->postJson(route('phone.verification.send'))
        ->assertStatus(503)
        ->assertJson(['sent' => false]);
});

test('rotas de verificação exigem autenticação', function () {
    $this->postJson(route('phone.verification.send'))->assertUnauthorized();
    $this->postJson(route('phone.verification.confirm'), ['code' => '123456'])->assertUnauthorized();
});

// ── Gate phone.verified: onboarding e-mail → WhatsApp → painel ──────────────

function clinicSessionFor(User $user): array
{
    $entity = Entity::factory()->create(['is_client' => true]);
    createEntityUser($entity, $user, 'admin');

    return [
        'selected_entity_id'        => $entity->id,
        'selected_entity_is_client' => true,
        'selected_entity_user_rule' => 'admin',
    ];
}

test('painel redireciona para verify-phone enquanto WhatsApp não confirmado', function () {
    operationalGlobalWhatsApp();
    $user = userWithPhone();

    $this->actingAs($user)
        ->withSession(clinicSessionFor($user))
        ->get(route('panel.dashboard'))
        ->assertRedirect(route('phone.verification.notice'));
});

test('painel abre com WhatsApp confirmado', function () {
    operationalGlobalWhatsApp();
    $user = userWithPhone(['phone_verified_at' => now()]);

    $this->actingAs($user)
        ->withSession(clinicSessionFor($user))
        ->get(route('panel.dashboard'))
        ->assertOk();
});

test('painel abre para conta sem telefone cadastrado (legado)', function () {
    operationalGlobalWhatsApp();
    $user = User::factory()->create(['phone' => null]);

    $this->actingAs($user)
        ->withSession(clinicSessionFor($user))
        ->get(route('panel.dashboard'))
        ->assertOk();
});

test('gate libera quando instância global de WhatsApp está indisponível', function () {
    // Sem WhatsAppSetting global: exigir código que não pode ser entregue
    // trancaria o onboarding inteiro — fail-open deliberado.
    $user = userWithPhone();

    $this->actingAs($user)
        ->withSession(clinicSessionFor($user))
        ->get(route('panel.dashboard'))
        ->assertOk();
});

test('tela verify-phone renderiza com número mascarado', function () {
    operationalGlobalWhatsApp();
    $user = userWithPhone();

    $response = $this->actingAs($user)->get(route('phone.verification.notice'));

    $response->assertOk();
    $props = $response->viewData('page')['props'];
    expect($props['maskedPhone'])->toBe('(11) *****-**77');
});

test('tela verify-phone redireciona para o painel quando já confirmado', function () {
    $user = userWithPhone(['phone_verified_at' => now()]);

    $this->actingAs($user)
        ->get(route('phone.verification.notice'))
        ->assertRedirect(route('panel.dashboard', absolute: false));
});
