<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Doctor, Entity, Patient, PatientDocumentShare, PatientExam, People, User};
use Illuminate\Support\Str;

/**
 * Ações rápidas por imagem do menu de contexto (EyeImageExamActionsController) —
 * benchmark contra concorrente (Ger Exames), 09/09/2026.
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

    $this->adminUserModel  = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->adminUserModel, ClientRule::Admin->value);

    $this->secretaryUserModel  = User::factory()->create();
    $this->secretaryEntityUser = createEntityUser($this->entity, $this->secretaryUserModel, ClientRule::Secretary->value);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->exam    = PatientExam::factory()->create([
        'patient_id' => $this->patient->id,
        'laterality' => 1,
        'active'     => true,
    ]);
    // Segundo exame do MESMO paciente — pra testar merge/split entre grupos.
    $this->exam2 = PatientExam::factory()->create([
        'patient_id' => $this->patient->id,
        'laterality' => 2,
        'active'     => true,
    ]);

    // Segunda clínica, pra provar isolamento de tenant (IDOR).
    $this->otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->otherPatient = Patient::factory()->create(['entity_id' => $this->otherEntity->id]);
    $this->otherExam    = PatientExam::factory()->create(['patient_id' => $this->otherPatient->id]);

    // Outro paciente, MESMA clínica — pra provar que merge/split nunca cruza
    // paciente (bug de integridade clínica, não IDOR comum).
    $this->otherPatientSameEntity = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->examOtherPatient       = PatientExam::factory()->create(['patient_id' => $this->otherPatientSameEntity->id]);
});

function actingAsDoctorEic($test)
{
    return $test->actingAs($test->doctorUserModel)->withSession(panelSession($test->doctorEntityUser));
}

function actingAsAdminEic($test)
{
    return $test->actingAs($test->adminUserModel)->withSession(panelSession($test->adminEntityUser));
}

function actingAsSecretaryEic($test)
{
    return $test->actingAs($test->secretaryUserModel)->withSession(panelSession($test->secretaryEntityUser));
}

describe('updateLaterality()', function () {
    it('médico troca a lateralidade', function () {
        $response = actingAsDoctorEic($this)->putJson(
            route('panel.eye-images.exams.laterality.update', $this->exam),
            ['laterality' => 2],
        );

        $response->assertOk()->assertJson(['laterality' => 2]);
        expect($this->exam->refresh()->laterality)->toBe(2);
    });

    it('null vira Binocular/AO', function () {
        $response = actingAsDoctorEic($this)->putJson(
            route('panel.eye-images.exams.laterality.update', $this->exam),
            ['laterality' => null],
        );

        $response->assertOk();
        expect($this->exam->refresh()->laterality)->toBeNull();
    });

    it('admin recebe 403 (Gate::IssueReport é doctor-only)', function () {
        actingAsAdminEic($this)->putJson(
            route('panel.eye-images.exams.laterality.update', $this->exam),
            ['laterality' => 1],
        )->assertForbidden();
    });

    it('secretária nem chega no controller (middleware entity.role)', function () {
        actingAsSecretaryEic($this)->putJson(
            route('panel.eye-images.exams.laterality.update', $this->exam),
            ['laterality' => 1],
        )->assertForbidden();
    });

    it('exame de outra clínica: 403 — nunca 200/404 que revele posse', function () {
        actingAsDoctorEic($this)->putJson(
            route('panel.eye-images.exams.laterality.update', $this->otherExam),
            ['laterality' => 1],
        )->assertForbidden();
    });

    it('valor fora de 1/2 é rejeitado (422)', function () {
        actingAsDoctorEic($this)->putJson(
            route('panel.eye-images.exams.laterality.update', $this->exam),
            ['laterality' => 9],
        )->assertStatus(422);
    });
});

describe('updateQualityRating()', function () {
    it('médico avalia a qualidade da imagem', function () {
        $response = actingAsDoctorEic($this)->putJson(
            route('panel.eye-images.exams.quality-rating.update', $this->exam),
            ['quality_rating' => 4],
        );

        $response->assertOk()->assertJson(['quality_rating' => 4]);
        expect($this->exam->refresh()->quality_rating)->toBe(4);
    });

    it('0 cancela a avaliação (vira null, não fica 0 salvo)', function () {
        $this->exam->update(['quality_rating' => 3]);

        actingAsDoctorEic($this)->putJson(
            route('panel.eye-images.exams.quality-rating.update', $this->exam),
            ['quality_rating' => 0],
        )->assertOk()->assertJson(['quality_rating' => null]);

        expect($this->exam->refresh()->quality_rating)->toBeNull();
    });

    it('valor acima de 5 é rejeitado (422)', function () {
        actingAsDoctorEic($this)->putJson(
            route('panel.eye-images.exams.quality-rating.update', $this->exam),
            ['quality_rating' => 6],
        )->assertStatus(422);
    });

    it('exame de outra clínica: 403', function () {
        actingAsDoctorEic($this)->putJson(
            route('panel.eye-images.exams.quality-rating.update', $this->otherExam),
            ['quality_rating' => 5],
        )->assertForbidden();
    });
});

describe('toggleActive()', function () {
    it('médico desabilita a imagem', function () {
        $response = actingAsDoctorEic($this)->putJson(
            route('panel.eye-images.exams.active.update', $this->exam),
            ['active' => false],
        );

        $response->assertOk()->assertJson(['active' => false]);
        expect($this->exam->refresh()->active)->toBeFalse();
    });

    it('desabilitar revoga automaticamente o compartilhamento ativo com o paciente', function () {
        $share = PatientDocumentShare::create([
            'entity_id'      => $this->entity->id,
            'patient_id'     => $this->patient->id,
            'shareable_type' => PatientExam::class,
            'shareable_id'   => $this->exam->id,
            'granted_by'     => $this->doctorEntityUser->id,
            'granted_at'     => now(),
        ]);

        actingAsDoctorEic($this)->putJson(
            route('panel.eye-images.exams.active.update', $this->exam),
            ['active' => false],
        )->assertOk();

        expect($share->refresh()->revoked_at)->not->toBeNull();
    });

    it('habilitar de novo não precisa reverter nada (idempotente, sem share pra tocar)', function () {
        $this->exam->update(['active' => false]);

        actingAsDoctorEic($this)->putJson(
            route('panel.eye-images.exams.active.update', $this->exam),
            ['active' => true],
        )->assertOk()->assertJson(['active' => true]);
    });

    it('exame de outra clínica: 403', function () {
        actingAsDoctorEic($this)->putJson(
            route('panel.eye-images.exams.active.update', $this->otherExam),
            ['active' => false],
        )->assertForbidden();
    });
});

describe('mergeExams()', function () {
    it('mescla 2 exames do mesmo paciente sob um exam_session_id comum', function () {
        $response = actingAsDoctorEic($this)->postJson(route('panel.eye-images.exams.merge'), [
            'exam_ids' => [$this->exam->id, $this->exam2->id],
        ]);

        $response->assertOk();
        $sessionId = $response->json('exam_session_id');
        expect($sessionId)->not->toBeNull();
        expect($this->exam->refresh()->exam_session_id)->toBe($sessionId);
        expect($this->exam2->refresh()->exam_session_id)->toBe($sessionId);
    });

    it('reaproveita um exam_session_id já existente em vez de gerar outro', function () {
        $this->exam->update(['exam_session_id' => $existing = (string) Str::uuid()]);

        $response = actingAsDoctorEic($this)->postJson(route('panel.eye-images.exams.merge'), [
            'exam_ids' => [$this->exam->id, $this->exam2->id],
        ]);

        $response->assertOk()->assertJson(['exam_session_id' => $existing]);
        expect($this->exam2->refresh()->exam_session_id)->toBe($existing);
    });

    it('exige pelo menos 2 imagens (422)', function () {
        actingAsDoctorEic($this)->postJson(route('panel.eye-images.exams.merge'), [
            'exam_ids' => [$this->exam->id],
        ])->assertStatus(422);
    });

    it('[SEGURANÇA] nunca mescla exames de pacientes diferentes, mesma clínica', function () {
        actingAsDoctorEic($this)->postJson(route('panel.eye-images.exams.merge'), [
            'exam_ids' => [$this->exam->id, $this->examOtherPatient->id],
        ])->assertStatus(422);

        expect($this->exam->refresh()->exam_session_id)->toBeNull();
    });

    it('[SEGURANÇA] exame de outra clínica: 403', function () {
        actingAsDoctorEic($this)->postJson(route('panel.eye-images.exams.merge'), [
            'exam_ids' => [$this->exam->id, $this->otherExam->id],
        ])->assertForbidden();
    });

    it('secretária nem chega no controller', function () {
        actingAsSecretaryEic($this)->postJson(route('panel.eye-images.exams.merge'), [
            'exam_ids' => [$this->exam->id, $this->exam2->id],
        ])->assertForbidden();
    });
});

describe('splitExams()', function () {
    it('separa uma imagem do grupo dando um exam_session_id novo', function () {
        $shared = (string) Str::uuid();
        $this->exam->update(['exam_session_id' => $shared]);
        $this->exam2->update(['exam_session_id' => $shared]);

        $response = actingAsDoctorEic($this)->postJson(route('panel.eye-images.exams.split'), [
            'exam_ids' => [$this->exam->id],
        ]);

        $response->assertOk();
        $newSessionId = $response->json('exam_session_id');
        expect($newSessionId)->not->toBe($shared);
        expect($this->exam->refresh()->exam_session_id)->toBe($newSessionId);
        // exam2 não foi selecionado — mantém o session_id do grupo original.
        expect($this->exam2->refresh()->exam_session_id)->toBe($shared);
    });

    it('[SEGURANÇA] nunca divide exames de pacientes diferentes, mesma clínica', function () {
        actingAsDoctorEic($this)->postJson(route('panel.eye-images.exams.split'), [
            'exam_ids' => [$this->exam->id, $this->examOtherPatient->id],
        ])->assertStatus(422);
    });

    it('[SEGURANÇA] exame de outra clínica: 403', function () {
        actingAsDoctorEic($this)->postJson(route('panel.eye-images.exams.split'), [
            'exam_ids' => [$this->otherExam->id],
        ])->assertForbidden();
    });
});

describe('ungroupExams()', function () {
    it('limpa exam_session_id, voltando ao agrupamento derivado', function () {
        $this->exam->update(['exam_session_id' => (string) Str::uuid()]);

        actingAsDoctorEic($this)->postJson(route('panel.eye-images.exams.ungroup'), [
            'exam_ids' => [$this->exam->id],
        ])->assertOk();

        expect($this->exam->refresh()->exam_session_id)->toBeNull();
    });

    it('[SEGURANÇA] exame de outra clínica: 403', function () {
        actingAsDoctorEic($this)->postJson(route('panel.eye-images.exams.ungroup'), [
            'exam_ids' => [$this->otherExam->id],
        ])->assertForbidden();
    });
});
