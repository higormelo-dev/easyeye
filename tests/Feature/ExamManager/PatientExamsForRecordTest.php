<?php

use App\Enums\ClientRule;
use App\Models\Entity;
use App\Models\{Patient, PatientExam, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Storage};

uses(RefreshDatabase::class);

/**
 * Endpoint consumido pelo painel "Exames de imagem" do PRONTUÁRIO:
 * metadados + URLs por exame, tenant-scoped e com leitura logada (LGPD).
 */
beforeEach(function () {
    Storage::fake('s3');

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->doctor           = User::factory()->create();
    $this->doctorEntityUser = createEntityUser($this->entity, $this->doctor, ClientRule::Doctor->value);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
});

it('devolve exames com metadados e urls para o paciente', function () {
    PatientExam::factory()->create([
        'patient_id' => $this->patient->id,
        'archive'    => 'exams/demo.jpg',
        'laterality' => 1,
    ]);
    $res = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEntityUser))
        ->getJson(route('panel.exam-manager.patient-exams', $this->patient));

    $res->assertOk();
    $exams = $res->json('exams');
    expect($exams)->toHaveCount(1)
        ->and($exams[0]['laterality'])->toBe('OD')
        ->and($exams[0])->toHaveKeys(['exam_type', 'performed_at', 'diagnosis', 'is_pdf', 'thumb_url', 'display_url', 'original_url', 'active']);
});

it('[GAP] expõe active=false pra exame desabilitado — rascunho de laudo (Fase 5) usa isto pra revalidar exam_ids ao reabrir', function () {
    $activeExam = PatientExam::factory()->create([
        'patient_id' => $this->patient->id,
        'archive'    => 'exams/ativo.jpg',
        'active'     => true,
    ]);
    $inactiveExam = PatientExam::factory()->create([
        'patient_id' => $this->patient->id,
        'archive'    => 'exams/inativo.jpg',
        'active'     => false,
    ]);

    $res = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEntityUser))
        ->getJson(route('panel.exam-manager.patient-exams', $this->patient));

    $res->assertOk();
    $exams = collect($res->json('exams'))->keyBy('id');

    expect($exams[(string) $activeExam->id]['active'])->toBeTrue()
        ->and($exams[(string) $inactiveExam->id]['active'])->toBeFalse();
});

it('[GAP] expõe is_video=true pra vídeo capturado (.webm/.mp4) — laudo de imagem usa isto pra nunca oferecer vídeo como candidato de "imagem"', function () {
    $photo = PatientExam::factory()->create([
        'patient_id' => $this->patient->id,
        'archive'    => 'exams/foto.jpg',
    ]);
    $video = PatientExam::factory()->create([
        'patient_id' => $this->patient->id,
        'archive'    => 'exams/captura.webm',
    ]);
    $videoUpperExt = PatientExam::factory()->create([
        'patient_id' => $this->patient->id,
        'archive'    => 'exams/captura.MP4',
    ]);

    $res = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEntityUser))
        ->getJson(route('panel.exam-manager.patient-exams', $this->patient));

    $res->assertOk();
    $exams = collect($res->json('exams'))->keyBy('id');

    expect($exams[(string) $photo->id]['is_video'])->toBeFalse()
        ->and($exams[(string) $video->id]['is_video'])->toBeTrue()
        ->and($exams[(string) $videoUpperExt->id]['is_video'])->toBeTrue();
});

it('registra o acesso de leitura (LGPD art. 37)', function () {
    PatientExam::factory()->create(['patient_id' => $this->patient->id, 'archive' => 'exams/demo.jpg']);

    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEntityUser))
        ->getJson(route('panel.exam-manager.patient-exams', $this->patient))
        ->assertOk();

    expect(DB::table('data_access_logs')
        ->where('patient_id', $this->patient->id)
        ->where('user_id', $this->doctor->id)
        ->exists())->toBeTrue();
});

it('nega paciente de outra clínica (403)', function () {
    $other        = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherPatient = Patient::factory()->create(['entity_id' => $other->id]);

    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEntityUser))
        ->getJson(route('panel.exam-manager.patient-exams', $otherPatient))
        ->assertForbidden();
});

it('prontuário expõe as urls do painel de exames (create e edit)', function () {
    $res = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEntityUser))
        ->get(route('panel.patients.medicalrecords.create', $this->patient), inertiaHeaders());

    $res->assertOk();
    expect($res->json('props.urls.eye_exams'))
        ->toContain('/panel/exam-manager/patient-exams/' . $this->patient->id)
        ->and($res->json('props.urls.eye_images_module'))->toContain('/panel/exam-manager');
});
