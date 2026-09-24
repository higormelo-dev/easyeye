<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Doctor, DoctorReportPhrase, Entity, People, User};
use App\Services\DoctorReportPhraseService;

/**
 * Frases rápidas do médico pro laudo do Gerenciador de Imagens (benchmark
 * 18/09/2026 — DoctorReportPhrasesController). Mesmo desenho de testes de
 * tests/Feature/AI/AiDoctorPromptsTest.php (o par mais próximo no código).
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->doctorUserModel  = User::factory()->create();
    $this->doctorEntityUser = createEntityUser($this->entity, $this->doctorUserModel, ClientRule::Doctor->value);
    $this->doctor           = Doctor::query()->create([
        'entity_user_id' => $this->doctorEntityUser->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);
});

function actingAsPhraseDoctor($test)
{
    return $test->actingAs($test->doctorUserModel)->withSession(panelSession($test->doctorEntityUser));
}

it('lista vazio para o médico que ainda não salvou nenhuma frase', function () {
    actingAsPhraseDoctor($this)
        ->getJson(route('panel.eye-images.report-phrases.index'))
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('cria uma frase para o próprio médico', function () {
    actingAsPhraseDoctor($this)
        ->postJson(route('panel.eye-images.report-phrases.store'), [
            'label'   => 'Fundo de olho normal',
            'content' => '<p>Fundo de olho sem alterações.</p>',
        ])
        ->assertStatus(201);

    expect(DoctorReportPhrase::query()->count())->toBe(1);
    $created = DoctorReportPhrase::query()->first();
    expect((string) $created->doctor_id)->toBe((string) $this->doctor->id)
        ->and((string) $created->entity_id)->toBe((string) $this->entity->id)
        ->and($created->label)->toBe('Fundo de olho normal');
});

it('bloqueia criação além do limite', function () {
    for ($i = 1; $i <= DoctorReportPhraseService::MAX_PHRASES_PER_DOCTOR; $i++) {
        DoctorReportPhrase::query()->create([
            'doctor_id' => $this->doctor->id,
            'entity_id' => $this->entity->id,
            'label'     => "Frase {$i}",
            'content'   => "Texto {$i}",
            'position'  => $i - 1,
        ]);
    }

    actingAsPhraseDoctor($this)
        ->postJson(route('panel.eye-images.report-phrases.store'), [
            'label' => 'Além do limite', 'content' => 'x',
        ])
        ->assertStatus(422);

    expect(DoctorReportPhrase::query()->count())->toBe(DoctorReportPhraseService::MAX_PHRASES_PER_DOCTOR);
});

it('atualiza uma frase do próprio médico', function () {
    $phrase = DoctorReportPhrase::query()->create([
        'doctor_id' => $this->doctor->id, 'entity_id' => $this->entity->id,
        'label'     => 'Original', 'content' => 'Texto original', 'position' => 0,
    ]);

    actingAsPhraseDoctor($this)
        ->putJson(route('panel.eye-images.report-phrases.update', $phrase), [
            'label' => 'Atualizada', 'content' => 'Texto atualizado',
        ])
        ->assertOk();

    expect($phrase->fresh()->label)->toBe('Atualizada');
});

it('[SEGURANÇA] bloqueia edição de frase de OUTRO médico (mesma clínica)', function () {
    $otherDoctorUser = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    $otherDoctor     = Doctor::query()->create([
        'entity_user_id' => $otherDoctorUser->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

    $phrase = DoctorReportPhrase::query()->create([
        'doctor_id' => $otherDoctor->id, 'entity_id' => $this->entity->id,
        'label'     => 'Do outro médico', 'content' => 'x', 'position' => 0,
    ]);

    actingAsPhraseDoctor($this)
        ->putJson(route('panel.eye-images.report-phrases.update', $phrase), [
            'label' => 'tentativa', 'content' => 'tentativa',
        ])
        ->assertStatus(403);

    expect($phrase->fresh()->label)->toBe('Do outro médico');
});

it('[SEGURANÇA] bloqueia edição cross-tenant', function () {
    $otherEntity     = Entity::factory()->create(['is_client' => true]);
    $otherEntityUser = createEntityUser($otherEntity, User::factory()->create(), ClientRule::Doctor->value);
    $otherDoctor     = Doctor::query()->create([
        'entity_user_id' => $otherEntityUser->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

    $phrase = DoctorReportPhrase::query()->create([
        'doctor_id' => $otherDoctor->id, 'entity_id' => $otherEntity->id,
        'label'     => 'Outra clínica', 'content' => 'x', 'position' => 0,
    ]);

    actingAsPhraseDoctor($this)
        ->putJson(route('panel.eye-images.report-phrases.update', $phrase), [
            'label' => 'tentativa', 'content' => 'tentativa',
        ])
        ->assertStatus(403);
});

it('exclui uma frase do próprio médico e reorganiza posições', function () {
    $p1 = DoctorReportPhrase::query()->create(['doctor_id' => $this->doctor->id, 'entity_id' => $this->entity->id, 'label' => 'A', 'content' => 'a', 'position' => 0]);
    $p2 = DoctorReportPhrase::query()->create(['doctor_id' => $this->doctor->id, 'entity_id' => $this->entity->id, 'label' => 'B', 'content' => 'b', 'position' => 1]);
    $p3 = DoctorReportPhrase::query()->create(['doctor_id' => $this->doctor->id, 'entity_id' => $this->entity->id, 'label' => 'C', 'content' => 'c', 'position' => 2]);

    actingAsPhraseDoctor($this)
        ->deleteJson(route('panel.eye-images.report-phrases.destroy', $p2))
        ->assertOk();

    expect($p1->fresh()->position)->toBe(0)
        ->and($p3->fresh()->position)->toBe(1)
        ->and(DoctorReportPhrase::query()->count())->toBe(2);
});

it('[SEGURANÇA] bloqueia acesso de admin sem perfil médico', function () {
    $admin   = User::factory()->create();
    $adminEU = createEntityUser($this->entity, $admin, ClientRule::Admin->value);

    $this->actingAs($admin)
        ->withSession(panelSession($adminEU))
        ->postJson(route('panel.eye-images.report-phrases.store'), [
            'label' => 'tentativa', 'content' => 'admin não tem perfil médico',
        ])
        ->assertStatus(403);
});

it('[SEGURANÇA] secretária não acessa (gate de middleware)', function () {
    $secretaryUser = User::factory()->create();
    $secretaryEU   = createEntityUser($this->entity, $secretaryUser, ClientRule::Secretary->value);

    $this->actingAs($secretaryUser)
        ->withSession(panelSession($secretaryEU))
        ->getJson(route('panel.eye-images.report-phrases.index'))
        ->assertForbidden();
});
