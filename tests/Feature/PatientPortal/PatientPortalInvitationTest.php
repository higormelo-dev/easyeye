<?php

/**
 * Convite do Portal do Paciente (item 7 do escopo) — cobre o risco de
 * segurança "Convite só pode ser disparado por staff autenticado com ACL na
 * entidade do paciente".
 *
 * CORREÇÃO (achado de segurança desta auditoria): o route model binding de
 * {patient} NÃO é escopado pela entity ativa da sessão — `SubstituteBindings`
 * roda ANTES de `tenant.bind` na ordem de middleware do Laravel, então o
 * EntityScope global ainda está inerte quando {patient} é resolvido. O 404
 * abaixo para staff de outra entity vem da checagem EXPLÍCITA
 * `abort_unless(entity_id === selected_entity_id)` em
 * PatientPortalInvitationsController::store() — não do binding em si. Esse
 * mesmo padrão de binding "não protegido" existe em outras rotas pré-existentes
 * do grupo `panel.` (ex.: PatientsController::editData(), corrigido à parte
 * nesta mesma auditoria) — nunca assumir que {patient}/{model} bindado já
 * vem filtrado por tenant sem checagem explícita.
 */

use App\Models\{Entity, Patient, PatientAccount, People, User};
use App\Notifications\PatientPortalInvitation;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->entityA = Entity::factory()->create(['is_client' => true]);
    $this->entityB = Entity::factory()->create(['is_client' => true]);

    $this->person = People::factory()->create(['email' => 'paciente@example.com']);

    $this->patientInA = Patient::factory()->create([
        'entity_id' => $this->entityA->id,
        'person_id' => $this->person->id,
    ]);
});

test('admin da entity correta consegue disparar o convite', function () {
    Notification::fake();

    $staff      = User::factory()->create();
    $entityUser = createEntityUser($this->entityA, $staff, 'admin');

    $this->actingAs($staff)
        ->withSession(panelSession($entityUser))
        ->post(route('panel.patients.portal-invitation.store', $this->patientInA))
        ->assertRedirect();

    Notification::assertSentTo(
        $this->person,
        PatientPortalInvitation::class,
    );

    // O convite dispara a notificação mas NÃO cria a conta — só o paciente,
    // ao aceitar, cria a PatientAccount (ver PatientPortalInvitationAcceptTest).
    $this->assertDatabaseMissing('patient_accounts', ['person_id' => $this->person->id]);
});

test('staff de OUTRA entity nao consegue convidar paciente que nao pertence a ela (404)', function () {
    Notification::fake();

    $staffB      = User::factory()->create();
    $entityUserB = createEntityUser($this->entityB, $staffB, 'admin');

    $this->actingAs($staffB)
        ->withSession(panelSession($entityUserB))
        ->post(route('panel.patients.portal-invitation.store', $this->patientInA))
        ->assertNotFound();

    Notification::assertNothingSent();
});

test('staff sem role/permissao adequada na entity correta recebe 403', function () {
    Notification::fake();

    $staff = User::factory()->create();
    // 'user' não está na allowlist de fallback do middleware
    // permission:patients.manage,admin,financial,doctor,secretary
    $entityUser = createEntityUser($this->entityA, $staff, 'user');

    $this->actingAs($staff)
        ->withSession(panelSession($entityUser))
        ->post(route('panel.patients.portal-invitation.store', $this->patientInA))
        ->assertForbidden();

    Notification::assertNothingSent();
});

test('convite recusado com 422 quando o paciente nao tem e-mail cadastrado', function () {
    Notification::fake();

    $personSemEmail  = People::factory()->create(['email' => null]);
    $patientSemEmail = Patient::factory()->create([
        'entity_id' => $this->entityA->id,
        'person_id' => $personSemEmail->id,
    ]);

    $staff      = User::factory()->create();
    $entityUser = createEntityUser($this->entityA, $staff, 'admin');

    $this->actingAs($staff)
        ->withSession(panelSession($entityUser))
        ->post(route('panel.patients.portal-invitation.store', $patientSemEmail))
        ->assertStatus(422);

    Notification::assertNothingSent();
});

test('convite recusado (sem duplicar) quando o paciente ja possui conta no portal', function () {
    Notification::fake();

    PatientAccount::factory()->create(['person_id' => $this->person->id]);

    $staff      = User::factory()->create();
    $entityUser = createEntityUser($this->entityA, $staff, 'admin');

    $this->actingAs($staff)
        ->withSession(panelSession($entityUser))
        ->post(route('panel.patients.portal-invitation.store', $this->patientInA))
        ->assertRedirect();

    expect(PatientAccount::where('person_id', $this->person->id)->count())->toBe(1);
    Notification::assertNothingSent();
});
