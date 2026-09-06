<?php

declare(strict_types=1);

/**
 * Regressão de segurança — takeover de conta cross-tenant via colisão de
 * email em DoctorService::findOrCreateUser().
 *
 * Causa raiz: DoctorRequest validava unicidade de email contra "people",
 * mas DoctorService::findOrCreateUser() busca/atualiza por "users" (tabela
 * de login). Staff sem registro em "people" (secretary/admin/financial) não
 * tinha email ali, então um admin de UMA clínica conseguia cadastrar um
 * "médico" usando o email de login de um User de OUTRA clínica: a validação
 * passava, e o service sobrescrevia nome/senha do User existente — o
 * atacante então logava como a vítima.
 */

use App\Enums\ClientRule;
use App\Models\{Entity, User};
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->attackerEntity = Entity::factory()->create(['is_client' => true]);
    $this->victimEntity   = Entity::factory()->create(['is_client' => true]);

    $this->attacker = User::factory()->create();
    createEntityUser($this->attackerEntity, $this->attacker, ClientRule::Admin->value, true, true);

    $this->victim = User::factory()->create([
        'email'    => 'vitima@outraclinica.com',
        'password' => 'SenhaAntigaForte123!',
    ]);
    createEntityUser($this->victimEntity, $this->victim, ClientRule::Secretary->value);
});

function doctorStorePayload(array $overrides = []): array
{
    return array_merge([
        'name'                  => 'Dr Teste',
        'nickname'              => 'Dr Teste',
        'national_registry'     => (string) random_int(10000000000, 99999999999),
        'record'                => 'CRM-' . random_int(10000, 99999),
        'record_specialty'      => 'Oftalmologia',
        'color'                 => '#123456',
        'email'                 => 'novo.medico.' . uniqid() . '@example.com',
        'cellphone'             => '68931467577',
        'whatsapp'              => false,
        'partner'               => false,
        'password'              => 'NovaSenhaForte123!',
        'password_confirmation' => 'NovaSenhaForte123!',
    ], $overrides);
}

test('cadastro de medico com email de login de User de OUTRA entity e rejeitado (422) e nao altera a senha da vitima', function () {
    $originalHash = $this->victim->password;

    $response = $this->actingAs($this->attacker)
        ->withSession(panelSession($this->attackerEntity->entityUsers()->first()))
        ->postJson(route('panel.doctors.store'), doctorStorePayload([
            'email' => $this->victim->email,
        ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('email');

    $this->victim->refresh();

    expect($this->victim->password)->toBe($originalHash);
    expect($this->victim->name)->not->toBe('Dr Teste');
    expect(Hash::check('SenhaAntigaForte123!', $this->victim->password))->toBeTrue();
    expect(Hash::check('NovaSenhaForte123!', $this->victim->password))->toBeFalse();
});

test('cadastro de medico com email novo (nao usado) continua funcionando normalmente', function () {
    $email = 'medico.novo.' . uniqid() . '@example.com';

    $response = $this->actingAs($this->attacker)
        ->withSession(panelSession($this->attackerEntity->entityUsers()->first()))
        ->postJson(route('panel.doctors.store'), doctorStorePayload([
            'email' => $email,
        ]));

    $response->assertOk();

    $createdUser = User::query()->where('email', $email)->first();

    expect($createdUser)->not->toBeNull();
    expect(Hash::check('NovaSenhaForte123!', $createdUser->password))->toBeTrue();
});
