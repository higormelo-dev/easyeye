<?php

use App\Enums\FeatureKey;
use App\Jobs\GenerateExamDerivatives;
use App\Models\{EntityIntegratorEquipment, ExamType, IntegratorCommand, Patient, PatientExam};
use App\Services\UsageMeterService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

it('serializes mixed legacy hardware writes and operational compare-and-swap', function () {
    $ctx       = setupIntegrator();
    $equipment = EntityIntegratorEquipment::factory()->create(['integrator_id' => $ctx['integrator']->id, 'mac' => '00:11:22:33:44:55']);
    $equipment->delete();
    $operation = (string) Str::uuid();
    $results   = p2Concurrent([
        fn () => $this->postJson('/api/integrators/v1/equipments', ['name' => 'LEGACY FINAL', 'mac' => $equipment->mac], $ctx['headers'])->status(),
        fn () => $this->putJson('/api/integrators/v1/equipments/' . $equipment->id, ['name' => 'OPERATIONAL FIRST', 'operation_id' => $operation, 'installation_id' => (string) Str::uuid(), 'expected_config_generation' => 1], [...$ctx['headers'], 'Idempotency-Key' => $operation])->status(),
    ]);
    // A PUT that reaches the row before restore sees no live equipment; after
    // restore it must reject the stale generation. Neither outcome writes.
    expect($results[0])->toBe(200)->and($results[1])->toBeIn([404, 409]);
    expect($equipment->refresh()->name)->toBe('LEGACY FINAL')->and((int) $equipment->config_generation)->toBe(2)->and($equipment->trashed())->toBeFalse();
    expect(DB::table('integrator_equipment_operations')->where('operation_id', $operation)->count())->toBe(0);
});

/** Separate processes and PDO connections start together, with no outer test transaction. */
function p2Concurrent(array $jobs): array
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl required');
    }
    $dir = sys_get_temp_dir() . '/p2-concurrency-' . Str::uuid();
    mkdir($dir, 0700);
    DB::disconnect();
    $pids = [];

    try {
        foreach ($jobs as $index => $job) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('fork failed');
            }

            if ($pid === 0) {
                try {
                    DB::purge();
                    DB::connection()->getPdo();
                    file_put_contents($dir . '/' . $index . '.ready', 'ready');
                    $deadline = microtime(true) + 10;

                    while (! is_file($dir . '/go')) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('barrier timeout');
                        }
                        usleep(10000);
                    }
                    $result = $job();
                    file_put_contents($dir . '/' . $index . '.json', json_encode(['result' => $result], JSON_THROW_ON_ERROR));
                    DB::disconnect();

                    exit(0);
                } catch (Throwable $error) {
                    file_put_contents($dir . '/' . $index . '.json', json_encode(['error' => get_class($error) . ': ' . $error->getMessage()]));

                    exit(1);
                }
            }
            $pids[$index] = $pid;
        }
        $deadline = microtime(true) + 10;

        while (count(glob($dir . '/*.ready')) !== count($jobs)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('ready timeout');
            }
            usleep(10000);
        }
        file_put_contents($dir . '/go', 'go');
        $results = [];

        foreach ($pids as $index => $pid) {
            pcntl_waitpid($pid, $status);
            $data = json_decode(file_get_contents($dir . '/' . $index . '.json'), true, flags: JSON_THROW_ON_ERROR);

            if (isset($data['error'])) {
                throw new RuntimeException($data['error']);
            }
            $results[] = $data['result'];
        }

        return $results;
    } finally {
        foreach ($pids as $pid) {
            @posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status, WNOHANG);
        }

        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
        DB::purge();
    }
}

it('keeps the first terminal ACK across two committed HTTP requests', function () {
    $ctx     = setupIntegrator();
    $command = IntegratorCommand::create(['integrator_id' => $ctx['integrator']->id, 'type' => 'run_diagnostics', 'status' => 'pending', 'payload' => [], 'expires_at' => now()->addMinutes(5)]);
    $results = p2Concurrent(array_map(fn ($pending) => fn () => [
        'status'  => $this->postJson('/api/integrators/v1/commands/' . $command->id . '/ack', ['status' => 'completed', 'result' => ['pending' => $pending]], $ctx['headers'])->status(),
        'pending' => $pending,
    ], [3, 8]));
    expect(array_column($results, 'status'))->toBe([204, 204]);
    $saved = $command->refresh()->result;
    expect($saved['pending'])->toBeIn([3, 8]);
    $this->postJson('/api/integrators/v1/commands/' . $command->id . '/ack', ['status' => 'failed', 'result' => ['error_code' => 'command_failed']], $ctx['headers'])->assertNoContent();
    expect($command->refresh()->result)->toBe($saved)->and($command->status)->toBe('completed');
});

it('commits one durable equipment operation and replays concurrent requests', function () {
    $ctx  = setupIntegrator();
    $id   = (string) Str::uuid();
    $body = ['operation_id' => $id, 'installation_id' => (string) Str::uuid(), 'name' => 'Synthetic OCULUS'];
    $job  = function () use ($body, $ctx, $id) {
        $r = $this->postJson('/api/integrators/v1/equipments', $body, $ctx['headers'] + ['Idempotency-Key' => $id]);

        return ['status' => $r->status(), 'body' => $r->json()];
    };
    $results = p2Concurrent([$job, $job]);

    foreach ($results as $result) {
        expect($result['status'])->toBeIn([201, 409]);

        if ($result['status'] === 409) {
            expect($result['body']['code'])->toBe('equipment_operation_in_progress');
        }
    }
    $replay = $this->postJson('/api/integrators/v1/equipments', $body, $ctx['headers'] + ['Idempotency-Key' => $id])->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

    foreach ($results as $result) {
        if ($result['status'] === 201) {
            expect($result['body'])->toBe($replay->json());
        }
    }
    expect(EntityIntegratorEquipment::count())->toBe(1)->and(DB::table('integrator_equipment_operations')->count())->toBe(1);
});

it('allocates unique scoped EXM numbers for concurrent producers', function () {
    $ctx     = setupIntegrator();
    $patient = Patient::factory()->create(['entity_id' => $ctx['entity']->id]);
    $job     = fn () => PatientExam::factory()->create(['patient_id' => $patient->id])->code;
    $codes   = p2Concurrent([$job, $job]);
    sort($codes);
    expect($codes)->toBe(['EXM-0000000001', 'EXM-0000000002'])->and(PatientExam::where('patient_id', $patient->id)->count())->toBe(2);
});

it('reserves only one final monthly quota slot across concurrent committed HTTP uploads', function () {
    Storage::fake('s3');
    $ctx     = setupIntegrator([FeatureKey::ApiMonthlyExamSends->value => '1']);
    $patient = Patient::factory()->create(['entity_id' => $ctx['entity']->id, 'active' => true]);
    $type    = ExamType::factory()->create(['entity_id' => null]);
    $file    = UploadedFile::fake()->image('synthetic-concurrent.jpg');
    $job     = function () use ($ctx, $patient, $type) {
        $file = UploadedFile::fake()->image('synthetic-concurrent.jpg');
        $id   = (string) Str::uuid();
        $r    = $this->postJson('/api/integrators/v1/exams', ['exam_identifier' => $type->id, 'patient_identifier' => $patient->id, 'archive' => $file, 'name' => 'Synthetic exam', 'capture_id' => $id, 'content_sha256' => hash_file('sha256', $file->getRealPath())], $ctx['headers'] + ['Idempotency-Key' => $id]);

        return ['status' => $r->status(), 'body' => $r->json()];
    };
    $results  = p2Concurrent([$job, $job]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe([201, 403])->and(PatientExam::count())->toBe(1)
        ->and(DB::table('integrator_api_receipts')->count())->toBe(1)
        ->and((int) DB::table('feature_usages')->where('entity_id', $ctx['entity']->id)->where('feature', FeatureKey::ApiMonthlyExamSends->value)->value('used'))->toBe(1);
});

it('rolls back the reserved period even when the clock crosses a month boundary', function () {
    $ctx          = setupIntegrator();
    $subscription = $ctx['entity']->subscriptions()->latest()->first();

    try {
        $this->travelTo(now()->startOfMonth()->endOfMonth()->setTime(23, 59, 59));
        $old = now()->format('Y-m');
        expect(fn () => DB::transaction(function () use ($ctx, $subscription) {
            expect(app(UsageMeterService::class)->reserveMonthly($ctx['entity']->id, FeatureKey::ApiMonthlyExamSends, $subscription->id, 1))->toBeTrue();
            $this->travel(2)->seconds();

            throw new RuntimeException('synthetic rollback');
        }))->toThrow(RuntimeException::class);
        expect(DB::table('feature_usages')->where('entity_id', $ctx['entity']->id)->where('period', $old)->count())->toBe(0);
        expect(app(UsageMeterService::class)->reserveMonthly($ctx['entity']->id, FeatureKey::ApiMonthlyExamSends, $subscription->id, 1))->toBeTrue()
            ->and(DB::table('feature_usages')->where('entity_id', $ctx['entity']->id)->value('period'))->not->toBe($old);
    } finally {
        $this->travelBack();
    }
});

it('does not mark a concurrently replaced original failed for a legacy null-expected derivative job', function () {
    Storage::fake('s3');
    $ctx      = setupIntegrator();
    $patient  = Patient::factory()->create(['entity_id' => $ctx['entity']->id]);
    $exam     = PatientExam::factory()->create(['patient_id' => $patient->id, 'archive' => 'old.png']);
    $config   = DB::connection()->getConfig();
    $observer = new PDO('pgsql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database'], $config['username'], $config['password']);
    $disk     = Mockery::mock(Storage::disk('s3'))->makePartial();
    $disk->shouldReceive('readStream')->andReturnUsing(function () use ($observer, $exam) {
        $query = $observer->prepare('UPDATE patient_exams SET archive = ?, derivative_status = ? WHERE id = ?');
        $query->execute(['replacement.png', 'pending', $exam->id]);

        throw new RuntimeException('synthetic old-archive failure');
    });
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
    expect(fn () => (new GenerateExamDerivatives($exam->id))->handle())->toThrow(RuntimeException::class);
    expect($exam->refresh()->archive)->toBe('replacement.png')->and($exam->derivative_status)->toBe('pending');
});
