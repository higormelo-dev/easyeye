<?php

use App\Domains\AI\Models\AiRun;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\ClientRule;
use App\Models\{Entity, Patient, PatientExam, User};
use Illuminate\Support\Facades\DB;

/**
 * Run misto (foto + vídeo) aprovado: a foto foi DE FATO analisada
 * (image_deidentification preenchido), o vídeo nunca ganha outcome (GD não
 * decodifica) — mesmo assim o run inteiro fica Approved pela foto.
 * EyeImagesController::examAiReport() não pode rotular o vídeo como
 * "aprovado" nem abrir o conteúdo/PDF da foto no exame errado (achado via
 * revisão adversarial Codex).
 */
beforeEach(function () {
    $this->entity         = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->authUser       = User::factory()->create();
    $this->authEntityUser = createEntityUser($this->entity, $this->authUser, ClientRule::Doctor->value);
    $this->patient        = Patient::factory()->create(['entity_id' => $this->entity->id]);
});

function searchEyeImagesForMixedOutcome($test, array $query = [])
{
    return $test->actingAs($test->authUser)
        ->withSession(panelSession($test->authEntityUser))
        ->getJson(route('panel.eye-images.search', $query));
}

it('[GAP] run misto aprovado: foto mostra aprovado/conteúdo/PDF; vídeo (nunca analisado) não mostra nada disso — achado via revisão adversarial Codex', function () {
    $photo = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'archive' => 'exams/foto.jpg']);
    $video = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'archive' => 'exams/video.webm']);

    $run = AiRun::query()->create([
        'entity_id'    => $this->entity->id,
        'patient_id'   => $this->patient->id,
        'requested_by' => $this->authUser->id,
        'workflow'     => 'eye_image_analysis',
        'mode'         => AiRunMode::Economy->value,
        'risk_level'   => AiRiskLevel::Medium->value,
        'status'       => AiRunStatus::Approved->value,
        'final_output' => 'Achado normal na foto.',
    ]);

    DB::table('ai_run_patient_exam')->insert([
        [
            'ai_run_id'              => $run->id, 'patient_exam_id' => $photo->id, 'entity_id' => $this->entity->id,
            'image_deidentification' => 'oculus_pentacam_panel_a_pt', 'created_at' => now(),
        ],
        [
            // Vídeo selecionado junto, nunca chega a ter outcome — exatamente
            // o estado real do GenerateExamDerivatives/resolveImages() pra
            // um .webm (sem decode GD).
            'ai_run_id'              => $run->id, 'patient_exam_id' => $video->id, 'entity_id' => $this->entity->id,
            'image_deidentification' => null, 'created_at' => now(),
        ],
    ]);

    $response = searchEyeImagesForMixedOutcome($this);
    $response->assertOk();

    $exams       = collect($response->json('patients.0.exams'))->keyBy('id');
    $photoReport = $exams->get((string) $photo->id)['ai_report'];
    $videoReport = $exams->get((string) $video->id)['ai_report'];

    expect($photoReport['approved'])->toBeTrue()
        ->and($photoReport['content'])->toBe('Achado normal na foto.')
        ->and($photoReport['pdf_url'])->toBeNull(); // sem documentation vinculada neste teste, mas aprovado/conteúdo corretos

    // O vídeo nunca foi analisado nesta execução — mesmo o run estando
    // Approved (pela foto), o vídeo não pode aparecer como aprovado nem
    // herdar o conteúdo/PDF da foto.
    expect($videoReport['approved'])->toBeFalse()
        ->and($videoReport['content'])->toBeNull()
        ->and($videoReport['pdf_url'])->toBeNull();
});
