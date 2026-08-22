<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{Covenant, Entity, MedicalRecord, Patient, PatientCall, People, User};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Agenda do médico — fluxo de atendimento:
 *  - "Iniciar atendimento" (attend): abre o prontuário DESTE agendamento
 *    (create vinculado ou edit do existente);
 *  - "Chamar paciente" (call): painel/TV da sala de espera, opcional por
 *    clínica, com feed público por token.
 */
beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Admin->value);

    ['schedule' => $this->schedule, 'doctor' => $this->doctor] = createScheduleForEntity($this->entity, [
        'situation' => ScheduleSituation::Waiting->value,
    ]);

    $covenant      = Covenant::factory()->create();
    $person        = People::factory()->create();
    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => $person->id,
        'covenant_id' => $covenant->id,
        'active'      => true,
    ]);
    $this->schedule->updateQuietly(['patient_id' => $this->patient->id]);
});

describe('iniciar atendimento (attend)', function () {
    it('sem prontuário existente redireciona pro create já vinculado ao agendamento', function () {
        $response = $this->actingAs($this->user)
            ->withSession(panelSession($this->entityUser))
            ->get(route('panel.schedules.attend', $this->schedule));

        $response->assertRedirect(route('panel.patients.medicalrecords.create', [
            'patient'     => $this->patient->id,
            'schedule_id' => $this->schedule->id,
        ]));
    });

    it('com prontuário do agendamento redireciona pro edit do MESMO prontuário', function () {
        $record = MedicalRecord::create([
            'entity_id'   => $this->entity->id,
            'patient_id'  => $this->patient->id,
            'doctor_id'   => $this->doctor->id,
            'schedule_id' => $this->schedule->id,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(panelSession($this->entityUser))
            ->get(route('panel.schedules.attend', $this->schedule));

        $response->assertRedirect(route('panel.patients.medicalrecords.edit', [$this->patient->id, $record->id]));
    });

    it('agendamento sem paciente vinculado retorna 404', function () {
        $this->schedule->updateQuietly(['patient_id' => null]);

        $this->actingAs($this->user)
            ->withSession(panelSession($this->entityUser))
            ->get(route('panel.schedules.attend', $this->schedule))
            ->assertNotFound();
    });
});

describe('chamar paciente (painel/TV)', function () {
    it('com painel habilitado cria a chamada com snapshot de nomes', function () {
        $this->entity->forceFill(['call_panel_enabled' => true, 'call_panel_token' => str_repeat('a', 48)])->save();

        $this->actingAs($this->user)
            ->withSession(panelSession($this->entityUser))
            ->postJson(route('panel.schedules.call', $this->schedule))
            ->assertOk();

        $call = PatientCall::query()->where('entity_id', $this->entity->id)->first();
        expect($call)->not->toBeNull()
            ->and($call->patient_name)->toBe('PACIENTE TESTE')
            ->and($call->schedule_id)->toBe($this->schedule->id);
    });

    it('com painel DESABILITADO retorna 404 e não cria chamada', function () {
        $this->actingAs($this->user)
            ->withSession(panelSession($this->entityUser))
            ->postJson(route('panel.schedules.call', $this->schedule))
            ->assertNotFound();

        expect(PatientCall::count())->toBe(0);
    });

    it('feed público responde pelo token da clínica e só com chamadas DELA', function () {
        $this->entity->forceFill(['call_panel_enabled' => true, 'call_panel_token' => str_repeat('a', 48)])->save();

        PatientCall::create([
            'entity_id'    => $this->entity->id,
            'schedule_id'  => $this->schedule->id,
            'patient_name' => 'JOAO DA TV',
            'doctor_name'  => 'DRA TESTE',
            'created_at'   => now(),
        ]);

        $other = Entity::factory()->create(['is_client' => true]);
        PatientCall::create([
            'entity_id'    => $other->id,
            'patient_name' => 'PACIENTE DE OUTRA CLINICA',
            'created_at'   => now(),
        ]);

        $response = $this->getJson(route('call-panel.feed', str_repeat('a', 48)));
        $response->assertOk();

        $names = array_column($response->json('data'), 'patient');
        expect($names)->toContain('JOAO DA TV')
            ->and($names)->not->toContain('PACIENTE DE OUTRA CLINICA');
    });

    it('[SEGURANÇA] token inválido ou painel desativado → 404 no painel público', function () {
        $this->getJson(route('call-panel.feed', 'token-inexistente'))->assertNotFound();

        // Token certo mas recurso desligado também é 404 (anti-enumeração).
        $this->entity->forceFill(['call_panel_enabled' => false, 'call_panel_token' => str_repeat('b', 48)])->save();
        $this->getJson(route('call-panel.feed', str_repeat('b', 48)))->assertNotFound();
    });
});

describe('configuração do painel (settings.manage)', function () {
    it('admin ativa o painel e o token público é gerado', function () {
        $response = $this->actingAs($this->user)
            ->withSession(panelSession($this->entityUser))
            ->patchJson(route('panel.setting.call-panel.update'), ['enabled' => true]);

        $response->assertOk();
        $this->entity->refresh();

        expect($this->entity->call_panel_enabled)->toBeTrue()
            ->and($this->entity->call_panel_token)->not->toBeNull()
            ->and($response->json('panel_url'))->toContain($this->entity->call_panel_token);
    });

    it('[SEGURANÇA] secretária (sem settings.manage) não configura o painel', function () {
        $secretary   = User::factory()->create();
        $secretaryEu = createEntityUser($this->entity, $secretary, ClientRule::Secretary->value);

        $this->actingAs($secretary)
            ->withSession(panelSession($secretaryEu))
            ->patchJson(route('panel.setting.call-panel.update'), ['enabled' => true])
            ->assertForbidden();
    });
});
