<?php

use App\Enums\FeatureKey;
use App\Models\EntityIntegrator;
use App\Models\{EntityIntegratorEquipment, ExamType, Patient, PatientExam};
use App\Services\FeatureGateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('s3');
    $this->ctx       = setupIntegrator([FeatureKey::ApiMonthlyExamSends->value => '10']);
    $this->patient   = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
    $this->type      = ExamType::factory()->create(['entity_id' => null]);
    $this->equipment = EntityIntegratorEquipment::factory()->create(['integrator_id' => $this->ctx['integrator']->id]);
    $this->file      = UploadedFile::fake()->image('legacy.jpg');
    $archive         = Storage::disk('s3')->putFileAs('synthetic-legacy', $this->file, 'legacy.jpg', 'private');
    $this->record    = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'exam_id' => $this->type->id,
        'entity_integrator_equipment_id'                            => $this->equipment->id, 'archive' => $archive, 'active' => true,
        'laterality'                                                => 1, 'schedule_id' => null, 'exam_performed_at' => '2026-02-12 12:32:25']);
    $this->capture = (string) Str::uuid();
    $this->payload = ['capture_id' => $this->capture, 'content_sha256' => hash_file('sha256', $this->file->getRealPath()),
        'content_bytes'            => $this->file->getSize(), 'patient_identifier' => $this->patient->id,
        'schedule_identifier'      => null, 'exam_identifier' => $this->type->id,
        'equipment_identifier'     => $this->equipment->id, 'laterality' => 1,
        'exam_performed_at'        => $this->record->exam_performed_at->toIso8601String()];
    $this->headers = $this->ctx['headers'] + ['Idempotency-Key' => $this->capture];
    $this->url     = "/api/integrators/v1/exams/{$this->record->id}/receipt-reconciliation";
});

it('reconciles the actual original and exact clinical context without upload or quota and replays the receipt', function () {
    $response = $this->postJson($this->url, $this->payload, $this->headers)->assertOk()
        ->assertJsonPath('data.id', $this->record->id)->assertJsonPath('data.attributes.capture_id', $this->capture)
        ->assertJsonPath('data.attributes.entity_id', $this->ctx['entity']->id)
        ->assertJsonPath('data.attributes.integrator_id', $this->ctx['integrator']->id)
        ->assertJsonPath('data.attributes.content_bytes', $this->file->getSize())
        ->assertJsonPath('data.attributes.content_sha256', $this->payload['content_sha256']);
    $this->postJson($this->url, $this->payload, $this->headers)->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertExactJson($response->json());
    expect(PatientExam::count())->toBe(1)->and(DB::table('integrator_exam_outbox')->count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toHaveCount(1)
        ->and(app(FeatureGateService::class)->status($this->ctx['entity']->id, FeatureKey::ApiMonthlyExamSends)->used)->toBe(0);
});

it('rejects contradictory clinical evidence, date or original bytes without binding a capture', function ($field, $value, $code) {
    $this->postJson($this->url, array_replace($this->payload, [$field => $value]), $this->headers)->assertStatus(409)->assertJsonPath('code', $code);
    expect($this->record->refresh()->capture_id)->toBeNull()->and(DB::table('integrator_api_receipts')->count())->toBe(0);
})->with([
    ['patient_identifier', '11111111-1111-4111-8111-111111111111', 'receipt_clinical_context_mismatch'],
    ['laterality', 2, 'receipt_clinical_context_mismatch'],
    ['content_sha256', str_repeat('0', 64), 'receipt_original_mismatch'],
    ['content_bytes', 1, 'receipt_original_mismatch'],
    ['exam_performed_at', '2025-01-01', 'receipt_capture_date_mismatch'],
]);

it('refuses evidence from another tenant or another integrator of the same tenant', function () {
    $other = setupIntegrator();
    $this->postJson($this->url, $this->payload, $other['headers'] + ['Idempotency-Key' => $this->capture])->assertNotFound();
    $sameEntity = EntityIntegrator::factory()->create(['entity_user_integrator_id' => $this->ctx['integratorUser']->id]);
    $token      = $this->ctx['integratorUser']->createToken('other-integrator', ['integrator_id:' . $sameEntity->id], now()->addDay());
    $this->postJson($this->url, $this->payload, ['Authorization' => 'Bearer ' . $token->plainTextToken, 'Idempotency-Key' => $this->capture])->assertNotFound();
    expect($this->record->refresh()->capture_id)->toBeNull();
});

it('does not turn a deleted or inactive original into a valid receipt', function () {
    Storage::disk('s3')->delete($this->record->archive);
    $this->postJson($this->url, $this->payload, $this->headers)->assertStatus(409)->assertJsonPath('code', 'receipt_original_unavailable');
    expect($this->record->refresh()->capture_id)->toBeNull();
});
