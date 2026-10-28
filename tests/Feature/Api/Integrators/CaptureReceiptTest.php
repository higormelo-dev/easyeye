<?php

use App\Enums\{FeatureKey, ScheduleSituation};
use App\Models\{ClinicResource, EntityIntegratorEquipment, ExamType, Patient, PatientExam};
use App\Services\FeatureGateService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Cache, DB, Storage};
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('s3');
    $this->ctx      = setupIntegrator([FeatureKey::ApiMonthlyExamSends->value => '20']);
    $this->patient  = Patient::factory()->create(['active' => true, 'entity_id' => $this->ctx['entity']->id, 'id' => '11111111-1111-4111-8111-111111111111']);
    $this->type     = ExamType::factory()->create(['entity_id' => null, 'id' => '22222222-2222-4222-8222-222222222222']);
    $this->schedule = createScheduleForEntity($this->ctx['entity'], ['patient_id' => $this->patient->id, 'id' => '33333333-3333-4333-8333-333333333333'])['schedule'];
    $this->schedule->forceFill(['id' => '33333333-3333-4333-8333-333333333333'])->save();
    $resource        = ClinicResource::create(['entity_id' => $this->ctx['entity']->id, 'name' => 'Synthetic resource', 'active' => true, 'type' => 'equipment']);
    $this->equipment = EntityIntegratorEquipment::factory()->create(['integrator_id' => $this->ctx['integrator']->id, 'clinic_resource_id' => $resource->id, 'id' => '44444444-4444-4444-8444-444444444444']);
    $this->schedule->resources()->attach($resource->id, ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
    $this->file    = UploadedFile::fake()->image('capture.jpg');
    $this->capture = '55555555-5555-4555-8555-555555555555';
    $this->payload = [
        'capture_id'           => $this->capture,
        'content_sha256'       => hash_file('sha256', $this->file->getRealPath()),
        'exam_identifier'      => $this->type->id,
        'patient_identifier'   => $this->patient->id,
        'schedule_identifier'  => $this->schedule->id,
        'equipment_identifier' => $this->equipment->id,
        'name'                 => 'Synthetic capture contract',
        'archive'              => $this->file,
        'laterality'           => 1,
        'exam_performed_at'    => '2026-02-12T09:32:25-03:00',
    ];
    $this->headers = $this->ctx['headers'] + ['Idempotency-Key' => $this->capture];
});

it('returns a verified canonical capture receipt and replays without cache, duplicated quota or outbox', function () {
    $first = $this->postJson('/api/integrators/v1/exams', $this->payload, $this->headers)->assertCreated()
        ->assertJsonPath('data.attributes.capture_id', $this->capture)
        ->assertJsonPath('data.attributes.content_sha256', $this->payload['content_sha256'])
        ->assertJsonPath('data.attributes.content_bytes', $this->file->getSize())
        ->assertJsonPath('data.attributes.patient_id', $this->patient->id)
        ->assertJsonPath('data.attributes.schedule_id', $this->schedule->id)
        ->assertJsonPath('data.attributes.exam_id', $this->type->id)
        ->assertJsonPath('data.attributes.entity_integrator_equipment_id', $this->equipment->id)
        ->assertJsonPath('data.attributes.laterality', 1)
        ->assertJsonPath('data.attributes.active', true)
        ->assertJsonPath('data.attributes.availability', 'original_available')
        ->assertJsonPath('data.attributes.review_state', 'unknown')
        ->assertJsonPath('data.attributes.fulfillment_state', 'unknown');
    Cache::flush();
    $second = $this->postJson('/api/integrators/v1/exams', $this->payload, $this->headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect($second->json())->toBe($first->json())
        ->and(PatientExam::count())->toBe(1)
        ->and(DB::table('integrator_api_receipts')->count())->toBe(1)
        ->and(DB::table('integrator_exam_outbox')->count())->toBe(1)
        ->and(app(FeatureGateService::class)->status($this->ctx['entity']->id, FeatureKey::ApiMonthlyExamSends)->used)->toBe(1);

    // Optional artifact used by the Rust consumer contract test. Never production identifiers.
    if ($path = getenv('EASYEYE_P1_RECEIPT_ARTIFACT')) {
        file_put_contents($path, json_encode($first->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
});

it('rejects a changed payload or changed original filename under the original capture key', function () {
    $this->postJson('/api/integrators/v1/exams', $this->payload, $this->headers)->assertCreated();
    $this->postJson('/api/integrators/v1/exams', array_replace($this->payload, ['laterality' => 2]), $this->headers)
        ->assertStatus(409)->assertJsonPath('code', 'idempotency_payload_conflict');
    $renamed = new UploadedFile($this->file->getRealPath(), 'same-content.png', 'image/jpeg', null, true);
    $this->postJson('/api/integrators/v1/exams', array_replace($this->payload, ['archive' => $renamed]), $this->headers)
        ->assertStatus(409)->assertJsonPath('code', 'idempotency_payload_conflict');
    expect(PatientExam::count())->toBe(1);
});

it('does not replay a capture on a different endpoint or duplicate its quota', function () {
    $this->postJson('/api/integrators/v1/exams', $this->payload, $this->headers)->assertCreated();
    $this->postJson("/api/integrators/v1/patients/{$this->patient->id}/exams", $this->payload, $this->headers)->assertUnprocessable();
    expect(PatientExam::count())->toBe(1)
        ->and(DB::table('integrator_api_receipts')->count())->toBe(1)
        ->and(app(FeatureGateService::class)->status($this->ctx['entity']->id, FeatureKey::ApiMonthlyExamSends)->used)->toBe(1);
});

it('keeps acquisitions with identical content and display name distinct', function () {
    $this->postJson('/api/integrators/v1/exams', $this->payload, $this->headers)->assertCreated();
    $capture = (string) Str::uuid();
    $this->postJson('/api/integrators/v1/exams', array_replace($this->payload, ['capture_id' => $capture]), $this->ctx['headers'] + ['Idempotency-Key' => $capture])->assertCreated();
    expect(PatientExam::count())->toBe(2)->and(DB::table('integrator_exam_outbox')->count())->toBe(2);
});

it('rejects contradictory patient and schedule before consuming quota or uploading a blob', function () {
    $other = Patient::factory()->create(['active' => true, 'entity_id' => $this->ctx['entity']->id]);
    $this->postJson('/api/integrators/v1/exams', array_replace($this->payload, ['patient_identifier' => $other->id]), $this->headers)
        ->assertUnprocessable()->assertJsonPath('code', 'patient_schedule_mismatch');
    expect(PatientExam::count())->toBe(0)->and(Storage::disk('s3')->allFiles())->toBeEmpty()
        ->and(app(FeatureGateService::class)->status($this->ctx['entity']->id, FeatureKey::ApiMonthlyExamSends)->used)->toBe(0);
});

it('validates schedule-only cancellation and resource reservation', function () {
    $payload = $this->payload;
    unset($payload['patient_identifier']);
    $this->schedule->update(['situation' => ScheduleSituation::Cancelled]);
    $this->postJson('/api/integrators/v1/exams', $payload, $this->headers)->assertUnprocessable()->assertJsonPath('code', 'schedule_unavailable');
    $this->schedule->update(['situation' => ScheduleSituation::Scheduled]);
    $this->schedule->resources()->detach();
    $this->postJson('/api/integrators/v1/exams', $payload, $this->headers)->assertUnprocessable()->assertJsonPath('code', 'schedule_equipment_mismatch');
    expect(PatientExam::count())->toBe(0)->and(Storage::disk('s3')->allFiles())->toBeEmpty();
});

it('requires an explicit namespace or UUID when import and system codes overlap', function () {
    $other   = Patient::factory()->create(['active' => true, 'entity_id' => $this->ctx['entity']->id, 'import_code' => $this->patient->code]);
    $payload = array_replace($this->payload, ['patient_identifier' => $this->patient->code]);
    $this->postJson('/api/integrators/v1/exams', $payload, $this->headers)->assertUnprocessable()->assertJsonValidationErrors('patient_identifier');
    $this->postJson('/api/integrators/v1/exams', $payload + ['patient_identifier_namespace' => 'system'], $this->headers)->assertCreated()->assertJsonPath('data.attributes.patient_id', $this->patient->id);
});

it('uses the same exact namespace resolution for patient and schedule details', function () {
    $other    = Patient::factory()->create(['active' => true, 'entity_id' => $this->ctx['entity']->id, 'import_code' => $this->patient->code]);
    $schedule = createScheduleForEntity($this->ctx['entity'], ['patient_id' => $other->id, 'import_code' => $this->schedule->code])['schedule'];
    $schedule->forceFill(['import_code' => $this->schedule->code])->save();
    $patientUrl = "/api/integrators/v1/patients/{$this->patient->code}";
    $this->getJson($patientUrl, $this->ctx['headers'])->assertUnprocessable();
    $this->getJson($patientUrl . '?identifier_namespace=system', $this->ctx['headers'])->assertOk()->assertJsonPath('data.id', $this->patient->id);
    $this->getJson($patientUrl . '?identifier_namespace=import', $this->ctx['headers'])->assertOk()->assertJsonPath('data.id', $other->id);
    $scheduleUrl = "/api/integrators/v1/schedules/{$this->schedule->code}";
    $this->getJson($scheduleUrl, $this->ctx['headers'])->assertStatus(409);
    $this->getJson($scheduleUrl . '?identifier_namespace=system', $this->ctx['headers'])->assertOk()->assertJsonPath('data.id', $this->schedule->id);
    $this->getJson($scheduleUrl . '?identifier_namespace=import', $this->ctx['headers'])->assertOk()->assertJsonPath('data.id', $schedule->id);
});

it('proves only a rolled back validation attempt and never a conflict or committed receipt', function () {
    $payload                    = $this->payload;
    $payload['exam_identifier'] = (string) Str::uuid();
    $this->postJson('/api/integrators/v1/exams', $payload, $this->headers)->assertUnprocessable()
        ->assertHeader('Idempotency-Outcome', 'rolled-back')->assertHeader('Idempotency-Key', $this->capture)
        ->assertHeader('Integrator-Id', $this->ctx['integrator']->id);
    expect(DB::table('integrator_api_receipts')->count())->toBe(0)->and(PatientExam::count())->toBe(0)
        ->and(DB::table('integrator_exam_outbox')->count())->toBe(0)->and(Storage::disk('s3')->allFiles())->toBeEmpty();
    $this->postJson('/api/integrators/v1/exams', $this->payload, $this->headers)->assertCreated()->assertHeaderMissing('Idempotency-Outcome');
    $this->postJson('/api/integrators/v1/exams', $this->payload, $this->headers)->assertCreated()->assertHeaderMissing('Idempotency-Outcome');
    $payload         = $this->payload;
    $payload['name'] = 'Different frozen context';
    $this->postJson('/api/integrators/v1/exams', $payload, $this->headers)->assertConflict()->assertHeaderMissing('Idempotency-Outcome');
    $payload['archive'] = ['not a valid UploadedFile'];
    $this->postJson('/api/integrators/v1/exams', $payload, $this->headers)->assertConflict()->assertHeaderMissing('Idempotency-Outcome');
    $payload = $this->payload;
    unset($payload['patient_identifier']);
    $this->postJson('/api/integrators/v1/patients/' . $this->patient->id . '/exams', $payload, $this->headers)->assertUnprocessable()->assertHeaderMissing('Idempotency-Outcome');
    PatientExam::sole()->delete();
    $this->postJson('/api/integrators/v1/patients/' . $this->patient->id . '/exams', $payload, $this->headers)->assertUnprocessable()->assertHeaderMissing('Idempotency-Outcome');
});

it('returns acquisition and agenda instants correctly with America Sao Paulo configured', function () {
    $previous = date_default_timezone_get();
    config(['app.timezone' => 'America/Sao_Paulo']);
    date_default_timezone_set('America/Sao_Paulo');

    try {
        $this->schedule->update(['date_time' => '2026-02-12 09:32:25']);
        $schedule = $this->getJson('/api/integrators/v1/schedules/' . $this->schedule->id, $this->ctx['headers'])->assertOk();
        $schedule->assertJsonPath('data.attributes.local_date_time', '2026-02-12T09:32:25-03:00');
        $schedule->assertJsonPath('data.attributes.date_time', '2026-02-12T12:32:25.000000Z');
        $receipt = $this->postJson('/api/integrators/v1/exams', $this->payload, $this->headers)->assertCreated();
        expect(Carbon::parse($receipt->json('data.attributes.exam_performed_at'))->utc()->toIso8601String())->toBe('2026-02-12T12:32:25+00:00');
    } finally {
        date_default_timezone_set($previous);
    }
});

it('preserves acquisition date and observation on the patient nested capture endpoint', function () {
    $payload                = $this->payload;
    $payload['observation'] = 'Synthetic acquisition observation';
    unset($payload['patient_identifier']);
    $response = $this->postJson('/api/integrators/v1/patients/' . $this->patient->id . '/exams', $payload, $this->headers)->assertCreated();
    expect(Carbon::parse($response->json('data.attributes.exam_performed_at'))->utc()->toIso8601String())->toBe('2026-02-12T12:32:25+00:00');
    $response->assertJsonPath('data.attributes.observation', 'Synthetic acquisition observation');
});

it('preserves the clinical calendar day for date-only metadata in a positive timezone', function () {
    $previous = date_default_timezone_get();
    config(['app.timezone' => 'Asia/Tokyo']);
    date_default_timezone_set('Asia/Tokyo');

    try {
        $payload                      = $this->payload;
        $payload['exam_performed_at'] = '2026-02-12';
        $receipt                      = $this->postJson('/api/integrators/v1/exams', $payload, $this->headers)->assertCreated();
        $receipt->assertJsonPath('data.attributes.exam_performed_at', '2026-02-12T00:00:00+09:00');
        expect(Carbon::parse($receipt->json('data.attributes.exam_performed_at'))->utc()->toIso8601String())->toBe('2026-02-11T15:00:00+00:00');
    } finally {
        date_default_timezone_set($previous);
    }
});

it('never labels an uncertain server or storage failure as a proved validation rollback', function () {
    $disk  = Storage::disk('s3');
    $proxy = Mockery::mock($disk)->makePartial();
    $proxy->shouldReceive('putFileAs')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with('s3')->andReturn($proxy);
    $this->postJson('/api/integrators/v1/exams', $this->payload, $this->headers)->assertServerError()->assertHeaderMissing('Idempotency-Outcome');
    expect(PatientExam::count())->toBe(0)->and(DB::table('integrator_api_receipts')->count())->toBe(0)
        ->and(DB::table('integrator_exam_outbox')->count())->toBe(0)->and($disk->allFiles())->toBeEmpty();
});

it('fails closed for a foreign tenant canonical UUID and invalid structured uploads', function () {
    $other   = setupIntegrator();
    $foreign = Patient::factory()->create(['active' => true, 'entity_id' => $other['entity']->id]);
    $this->postJson('/api/integrators/v1/exams', array_replace($this->payload, ['patient_identifier' => $foreign->id]), $this->headers)->assertUnprocessable();
    $this->postJson('/api/integrators/v1/exams', array_replace($this->payload, ['patient_identifier' => ['bad']]), $this->headers)->assertUnprocessable();
    $this->postJson('/api/integrators/v1/exams', array_replace($this->payload, ['patient_identifier_namespace' => ['bad']]), $this->headers)->assertUnprocessable();
    $payload = $this->payload;
    unset($payload['capture_id']);
    $payload['archive'] = ['bad'];
    $this->postJson('/api/integrators/v1/exams', $payload, $this->ctx['headers'])->assertUnprocessable();
    expect(PatientExam::count())->toBe(0)->and(Storage::disk('s3')->allFiles())->toBeEmpty();
});

it('updates archive integrity and derivative and cleanup intents atomically', function () {
    $payload = $this->payload;
    unset($payload['capture_id'], $payload['content_sha256']);
    $created    = $this->postJson('/api/integrators/v1/exams', $payload, $this->ctx['headers'])->assertCreated();
    $record     = PatientExam::findOrFail($created->json('data.id'));
    $oldArchive = $record->archive;
    $file       = UploadedFile::fake()->image('replacement.jpg', 60, 50);
    $this->postJson("/api/integrators/v1/patients/{$this->patient->id}/exams/{$record->id}", [
        'exam_identifier'      => $this->type->id, 'schedule_identifier' => $this->schedule->id,
        'equipment_identifier' => $this->equipment->id, 'name' => 'Updated display', 'archive' => $file,
    ], $this->ctx['headers'])->assertOk()->assertJsonPath('data.attributes.content_sha256', hash_file('sha256', $file->getRealPath()))->assertJsonPath('data.attributes.content_bytes', $file->getSize());
    $record->refresh();
    expect($record->archive)->not->toBe($oldArchive)
        ->and(Storage::disk('s3')->exists($oldArchive))->toBeTrue()
        ->and(DB::table('integrator_exam_outbox')->where('operation', 'derivatives')->where('archive', $record->archive)->count())->toBe(1)
        ->and(DB::table('integrator_exam_outbox')->where('operation', 'delete_archive')->where('archive', $oldArchive)->count())->toBe(1);
});

it('preserves a proven capture original, receipt and quota when replacement is attempted', function () {
    $created    = $this->postJson('/api/integrators/v1/exams', $this->payload, $this->headers)->assertCreated();
    $record     = PatientExam::findOrFail($created->json('data.id'));
    $oldArchive = $record->archive;
    $this->postJson("/api/integrators/v1/patients/{$this->patient->id}/exams/{$record->id}", [
        'exam_identifier' => $this->type->id, 'schedule_identifier' => $this->schedule->id,
        'name'            => 'Replacement rejected', 'archive' => UploadedFile::fake()->image('other.jpg', 20, 20),
    ], $this->ctx['headers'])->assertUnprocessable()->assertJsonPath('code', 'capture_immutable');
    expect($record->refresh()->archive)->toBe($oldArchive)
        ->and($record->content_sha256)->toBe($this->payload['content_sha256'])
        ->and(DB::table('integrator_exam_outbox')->count())->toBe(1)
        ->and(app(FeatureGateService::class)->status($this->ctx['entity']->id, FeatureKey::ApiMonthlyExamSends)->used)->toBe(1);
    Storage::disk('s3')->assertExists($oldArchive);
    $this->postJson('/api/integrators/v1/exams', $this->payload, $this->headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $record->id);
});
