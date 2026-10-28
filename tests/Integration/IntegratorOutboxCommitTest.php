<?php

use App\Enums\FeatureKey;
use App\Models\{ExamType, Patient, PatientExam};
use App\Services\FeatureGateService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\{Cache, DB, Queue, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

// This directory is deliberately outside Feature: no RefreshDatabase wrapper
// may hide an after-commit failure or an uncommitted receipt.
uses(TestCase::class, DatabaseMigrations::class);

it('commits HTTP receipt, quota, record and original before broker failure, then recovers the durable intent', function () {
    Storage::fake('s3');
    $ctx      = setupIntegrator([FeatureKey::ApiMonthlyExamSends->value => '10']);
    $patient  = Patient::factory()->create(['active' => true, 'entity_id' => $ctx['entity']->id]);
    $type     = ExamType::factory()->create(['entity_id' => null]);
    $schedule = createScheduleForEntity($ctx['entity'], ['patient_id' => $patient->id])['schedule'];
    $file     = UploadedFile::fake()->image('synthetic-postcommit.jpg');
    $capture  = (string) Str::uuid();
    $payload  = ['capture_id' => $capture, 'content_sha256' => hash_file('sha256', $file->getRealPath()),
        'exam_identifier'     => $type->id, 'patient_identifier' => $patient->id, 'schedule_identifier' => $schedule->id,
        'archive'             => $file, 'name' => 'Synthetic durable outbox'];
    $headers = $ctx['headers'] + ['Idempotency-Key' => $capture];

    $invalid                    = $payload;
    $invalid['exam_identifier'] = (string) Str::uuid();
    $this->postJson('/api/integrators/v1/exams', $invalid, $headers)->assertUnprocessable()
        ->assertHeader('Idempotency-Outcome', 'rolled-back')->assertHeader('Idempotency-Key', $capture)
        ->assertHeader('Integrator-Id', $ctx['integrator']->id);
    expect(DB::transactionLevel())->toBe(0)->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
    $config         = DB::connection()->getConfig();
    $beforeObserver = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
    expect((int) $beforeObserver->query('SELECT count(*) FROM patient_exams')->fetchColumn())->toBe(0)
        ->and((int) $beforeObserver->query('SELECT count(*) FROM integrator_api_receipts')->fetchColumn())->toBe(0)
        ->and((int) $beforeObserver->query('SELECT count(*) FROM integrator_exam_outbox')->fetchColumn())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBeEmpty();
    $response = $this->postJson('/api/integrators/v1/exams', $payload, $headers)->assertCreated()->assertHeaderMissing('Idempotency-Outcome');
    expect(DB::transactionLevel())->toBe(0)->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
    $record    = PatientExam::findOrFail($response->json('data.id'));
    $config    = DB::connection()->getConfig();
    $observer  = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
    $statement = $observer->prepare('SELECT archive FROM patient_exams WHERE id = ?');
    $statement->execute([$record->id]);
    expect($statement->fetchColumn())->toBe($record->archive);
    $statement = $observer->prepare('SELECT used FROM feature_usages WHERE entity_id = ? AND feature = ?');
    $statement->execute([$ctx['entity']->id, FeatureKey::ApiMonthlyExamSends->value]);
    expect((int) $statement->fetchColumn())->toBe(1)
        ->and((int) $observer->query('SELECT count(*) FROM integrator_api_receipts WHERE status = 201')->fetchColumn())->toBe(1)
        ->and((int) $observer->query('SELECT count(*) FROM integrator_exam_outbox')->fetchColumn())->toBe(1);
    Storage::disk('s3')->assertExists($record->archive);

    $manager = Queue::getFacadeRoot();
    $failing = Mockery::mock(DatabaseQueue::class, [DB::connection(), 'jobs'])->makePartial()->shouldAllowMockingProtectedMethods();
    $failing->setContainer(app());
    $failing->shouldReceive('pushToDatabase')->once()->andThrow(new RuntimeException('Synthetic broker unavailable after HTTP commit'));
    Queue::partialMock()->shouldReceive('connection')->andReturn($failing);
    $this->artisan('integrator-outbox:publish')->assertExitCode(1);
    Queue::swap($manager);
    expect(DB::transactionLevel())->toBe(0)
        ->and(PatientExam::findOrFail($record->id)->archive)->toBe($record->archive)
        ->and(DB::table('integrator_exam_outbox')->count())->toBe(1)
        ->and(app(FeatureGateService::class)->status($ctx['entity']->id, FeatureKey::ApiMonthlyExamSends)->used)->toBe(1);
    Storage::disk('s3')->assertExists($record->archive);

    $healthy = new DatabaseQueue(DB::connection(), 'jobs');
    $healthy->setContainer(app());
    Queue::partialMock()->shouldReceive('connection')->andReturn($healthy);
    $this->artisan('integrator-outbox:publish')->assertSuccessful();
    Queue::swap($manager);
    expect(DB::table('integrator_exam_outbox')->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(1);
    Cache::flush();
    $this->travel(2)->days();
    $replay = $this->postJson('/api/integrators/v1/exams', $payload, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertHeaderMissing('Idempotency-Outcome');
    $this->travelBack();
    expect($replay->json())->toBe($response->json())->and(PatientExam::count())->toBe(1)
        ->and(app(FeatureGateService::class)->status($ctx['entity']->id, FeatureKey::ApiMonthlyExamSends)->used)->toBe(1);
});

it('rechecks proof immutability after another committed connection binds a legacy capture during storage upload', function () {
    Storage::fake('s3');
    $ctx      = setupIntegrator([FeatureKey::ApiMonthlyExamSends->value => '10']);
    $patient  = Patient::factory()->create(['active' => true, 'entity_id' => $ctx['entity']->id]);
    $type     = ExamType::factory()->create(['entity_id' => null]);
    $schedule = createScheduleForEntity($ctx['entity'], ['patient_id' => $patient->id])['schedule'];
    $record   = PatientExam::factory()->create(['patient_id' => $patient->id, 'exam_id' => $type->id, 'schedule_id' => $schedule->id,
        'archive'                                            => 'synthetic-legacy.jpg', 'capture_id' => null]);
    $disk = Storage::disk('s3');
    $disk->put($record->archive, 'synthetic old bytes');
    $config   = DB::connection()->getConfig();
    $observer = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
    $capture  = (string) Str::uuid();
    $proxy    = Mockery::mock($disk)->makePartial();
    $proxy->shouldReceive('putFileAs')->once()->andReturnUsing(function (...$arguments) use ($observer, $record, $ctx, $capture, $disk) {
        $statement = $observer->prepare('UPDATE patient_exams SET capture_id = ?, capture_integrator_id = ? WHERE id = ?');
        $statement->execute([$capture, $ctx['integrator']->id, $record->id]);

        return $disk->putFileAs(...$arguments);
    });
    Storage::shouldReceive('disk')->with('s3')->andReturn($proxy);
    $payload = ['exam_identifier' => $type->id, 'schedule_identifier' => $schedule->id,
        'archive'                 => UploadedFile::fake()->image('new.jpg'), 'name' => 'Synthetic replacement'];
    $this->putJson('/api/integrators/v1/patients/' . $patient->id . '/exams/' . $record->id, $payload, $ctx['headers'])->assertUnprocessable()->assertJsonPath('code', 'capture_immutable');
    expect($record->refresh()->capture_id)->toBe($capture)->and($record->archive)->toBe('synthetic-legacy.jpg')
        ->and($disk->allFiles())->toBe(['synthetic-legacy.jpg'])->and(DB::table('integrator_exam_outbox')->count())->toBe(0);
});

it('rejects a patient deactivated by another committed connection before the persistence lock', function () {
    Storage::fake('s3');
    $ctx      = setupIntegrator([FeatureKey::ApiMonthlyExamSends->value => '10']);
    $patient  = Patient::factory()->create(['active' => true, 'entity_id' => $ctx['entity']->id]);
    $type     = ExamType::factory()->create(['entity_id' => null]);
    $disk     = Storage::disk('s3');
    $config   = DB::connection()->getConfig();
    $observer = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
    $proxy    = Mockery::mock($disk)->makePartial();
    $proxy->shouldReceive('putFileAs')->once()->andReturnUsing(function (...$arguments) use ($observer, $patient, $disk) {
        $statement = $observer->prepare('UPDATE patients SET active = false WHERE id = ?');
        $statement->execute([$patient->id]);

        return $disk->putFileAs(...$arguments);
    });
    Storage::shouldReceive('disk')->with('s3')->andReturn($proxy);
    $file    = UploadedFile::fake()->image('new.jpg');
    $capture = (string) Str::uuid();
    $payload = ['exam_identifier' => $type->id, 'patient_identifier' => $patient->id, 'archive' => $file, 'name' => 'Synthetic capture',
        'capture_id'              => $capture, 'content_sha256' => hash_file('sha256', $file->getRealPath())];
    $this->postJson('/api/integrators/v1/exams', $payload, $ctx['headers'] + ['Idempotency-Key' => $capture])->assertUnprocessable()
        ->assertJsonPath('code', 'patient_unavailable')->assertHeader('Idempotency-Outcome', 'rolled-back');
    expect($patient->refresh()->active)->toBeFalse()->and(PatientExam::count())->toBe(0)->and($disk->allFiles())->toBeEmpty()
        ->and(DB::table('integrator_api_receipts')->count())->toBe(0)->and(DB::table('integrator_exam_outbox')->count())->toBe(0);
});
