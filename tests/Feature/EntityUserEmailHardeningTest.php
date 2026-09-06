<?php

declare(strict_types=1);

/**
 * Hardening de follow-up ao achado de account takeover no DoctorService:
 * EntityUserService::findOrCreateUser() tinha o mesmo padrão (busca User por
 * e-mail e sobrescreve nome/senha/verificação). EntityUserRequest já valida
 * unicidade em users.email (fix primário já existia aqui), então isto é
 * defesa em profundidade — comprova que mesmo se esse ramo for alcançado,
 * nenhuma credencial de conta existente é mutada.
 */

use App\Enums\ClientRule;
use App\Models\{Entity, User};
use Illuminate\Support\Facades\Hash;

test('cadastro de staff com email de login de User de OUTRA entity e rejeitado (422) e nao altera a senha da vitima', function () {
    $entityA = Entity::factory()->create(['is_client' => true]);
    $entityB = Entity::factory()->create(['is_client' => true]);

    $admin      = User::factory()->create();
    $entityUser = createEntityUser($entityA, $admin, ClientRule::Admin->value);

    $victim = User::factory()->create(['email' => 'vitima@clinicab.com', 'password' => 'SenhaOriginal123!']);
    createEntityUser($entityB, $victim, ClientRule::Secretary->value);
    $originalHash = $victim->fresh()->password;

    $this->actingAs($admin)
        ->withSession(panelSession($entityUser))
        ->postJson(route('panel.accesscontrol.users.store'), [
            'name'     => 'Novo Staff',
            'email'    => 'vitima@clinicab.com',
            'rule'     => ClientRule::Secretary->value,
            'password' => 'SenhaDoAtacante123!',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    $victim->refresh();
    expect($victim->password)->toBe($originalHash);
    expect(Hash::check('SenhaOriginal123!', $victim->password))->toBeTrue();
    expect(Hash::check('SenhaDoAtacante123!', $victim->password))->toBeFalse();
});

test('cadastro de staff com email novo (nao usado) continua funcionando normalmente', function () {
    $entity     = Entity::factory()->create(['is_client' => true]);
    $admin      = User::factory()->create();
    $entityUser = createEntityUser($entity, $admin, ClientRule::Admin->value);

    $email = 'staff-novo-' . uniqid() . '@clinicateste.com';

    $this->actingAs($admin)
        ->withSession(panelSession($entityUser))
        ->postJson(route('panel.accesscontrol.users.store'), [
            'name'                  => 'Staff Novo',
            'email'                 => $email,
            'rule'                  => ClientRule::Secretary->value,
            'password'              => 'SenhaValida123!',
            'password_confirmation' => 'SenhaValida123!',
        ])
        ->assertOk();

    $created = User::where('email', $email)->first();
    expect($created)->not->toBeNull();
    expect(Hash::check('SenhaValida123!', $created->password))->toBeTrue();
});
