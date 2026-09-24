<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Doctor, Entity, Patient, PatientExam, People, User};
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Support\Facades\Storage;

/**
 * Extração de texto do PDF nativo do equipamento (benchmark 18/09/2026) —
 * EyeImageReportController::extractPdfText(). PDF de fixture gerado de
 * verdade via Snappy/wkhtmltopdf (mesma dependência já usada pelo laudo em
 * PDF do prontuário) em vez de bytes de PDF escritos à mão — evita depender
 * de um PDF hand-rolled possivelmente malformado.
 */
beforeEach(function () {
    Storage::fake('s3');

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->doctorUserModel  = User::factory()->create();
    $this->doctorEntityUser = createEntityUser($this->entity, $this->doctorUserModel, ClientRule::Doctor->value);
    $this->doctor           = Doctor::query()->create([
        'entity_user_id' => $this->doctorEntityUser->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

    $this->secretaryUser       = User::factory()->create();
    $this->secretaryEntityUser = createEntityUser($this->entity, $this->secretaryUser, ClientRule::Secretary->value);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);

    $pdfBytes = SnappyPdf::loadHTML('<p>Achado de teste: escavacao papilar aumentada.</p>')->output();
    Storage::disk('s3')->put('exams/pentacam-report.pdf', $pdfBytes);

    $this->pdfExam = PatientExam::factory()->create([
        'patient_id' => $this->patient->id,
        'archive'    => 'exams/pentacam-report.pdf',
    ]);
    $this->imageExam = PatientExam::factory()->create([
        'patient_id' => $this->patient->id,
        'archive'    => 'exams/retinografia.jpg',
    ]);
});

function actingAsPdfDoctor($test)
{
    return $test->actingAs($test->doctorUserModel)->withSession(panelSession($test->doctorEntityUser));
}

it('extrai o texto do PDF nativo do equipamento', function () {
    $response = actingAsPdfDoctor($this)
        ->postJson(route('panel.eye-images.reports.extract-pdf-text'), [
            'exam_ids' => [$this->pdfExam->id],
        ]);

    $response->assertOk();
    expect($response->json('text'))->toContain('escavacao papilar aumentada');
});

it('[GAP] exame que não é PDF retorna 422 (sem texto pra extrair)', function () {
    actingAsPdfDoctor($this)
        ->postJson(route('panel.eye-images.reports.extract-pdf-text'), [
            'exam_ids' => [$this->imageExam->id],
        ])
        ->assertStatus(422);
});

it('[SEGURANÇA] exam_id de outra clínica é rejeitado com 403', function () {
    $otherEntity  = Entity::factory()->create(['is_client' => true]);
    $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
    $otherExam    = PatientExam::factory()->create(['patient_id' => $otherPatient->id, 'archive' => 'exams/outro.pdf']);

    actingAsPdfDoctor($this)
        ->postJson(route('panel.eye-images.reports.extract-pdf-text'), [
            'exam_ids' => [$otherExam->id],
        ])
        ->assertForbidden();
});

it('[SEGURANÇA] secretária não extrai texto (Gate IssueReport é doctor-only)', function () {
    $this->actingAs($this->secretaryUser)
        ->withSession(panelSession($this->secretaryEntityUser))
        ->postJson(route('panel.eye-images.reports.extract-pdf-text'), [
            'exam_ids' => [$this->pdfExam->id],
        ])
        ->assertForbidden();
});

it('[SEGURANÇA] imagem desabilitada (active=false) é rejeitada com 422', function () {
    $this->pdfExam->update(['active' => false]);

    actingAsPdfDoctor($this)
        ->postJson(route('panel.eye-images.reports.extract-pdf-text'), [
            'exam_ids' => [$this->pdfExam->id],
        ])
        ->assertStatus(422);
});

it('mistura PDF + imagem no mesmo pedido: extrai só do PDF, sem falhar por causa da imagem', function () {
    $response = actingAsPdfDoctor($this)
        ->postJson(route('panel.eye-images.reports.extract-pdf-text'), [
            'exam_ids' => [$this->pdfExam->id, $this->imageExam->id],
        ]);

    $response->assertOk();
    expect($response->json('text'))->toContain('escavacao papilar aumentada');
});
