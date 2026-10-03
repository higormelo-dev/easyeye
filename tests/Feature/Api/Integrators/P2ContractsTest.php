<?php
use App\Jobs\GenerateExamDerivatives;
use App\Models\{ClinicResource, DataAccessLog, EntityIntegratorEquipment, IntegratorCommand, Patient, People};
use App\Models\EntityActivation;
use App\Models\{ExamType, PatientExam};
use App\Services\{EmrReport, IntegratorTokenPolicy, IntegratorUpdatePublisher};
use App\Services\IntegratorUpdateManifest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('s3');
    $this->ctx = setupIntegrator();
});
it('makes legacy hardware configuration changes visible to operational compare-and-swap', function () {
    $equipment = EntityIntegratorEquipment::factory()->create(['integrator_id' => $this->ctx['integrator']->id, 'mac' => '00:11:22:33:44:55']);
    $equipment->delete();
    $resource = ClinicResource::create(['name' => 'Legacy resource', 'active' => true, 'entity_id' => $this->ctx['entity']->id, 'type' => 'equipment']);
    $this->postJson('/api/integrators/v1/equipments', ['name' => 'LEGACY CHANGED', 'mac' => $equipment->mac, 'clinic_resource_id' => $resource->id], $this->ctx['headers'])
        ->assertOk()->assertJsonPath('data.id', $equipment->id)->assertJsonPath('data.attributes.config_generation', 2);
    $id = (string) Str::uuid();
    $this->putJson('/api/integrators/v1/equipments/' . $equipment->id, ['operation_id' => $id, 'installation_id' => (string) Str::uuid(), 'expected_config_generation' => 1, 'name' => 'STALE OVERWRITE', 'clinic_resource_id' => null], [...$this->ctx['headers'], 'Idempotency-Key' => $id])
        ->assertConflict()->assertJsonPath('code', 'equipment_config_generation_conflict');
    expect($equipment->refresh()->name)->toBe('LEGACY CHANGED')->and($equipment->clinic_resource_id)->toBe($resource->id)->and((int) $equipment->config_generation)->toBe(2);
    expect(DB::table('integrator_equipment_operations')->where('operation_id', $id)->count())->toBe(0);
});
it('does not serialize documents addresses contacts or unreviewed relation fields', function () {
    $person   = People::factory()->create(['national_registry' => '12345678900', 'address' => 'PRIVATE SYNTHETIC ADDRESS']);
    $patient  = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id, 'person_id' => $person->id, 'active' => true]);
    $response = $this->getJson('/api/integrators/v1/patients/' . $patient->id, $this->ctx['headers'])->assertOk();
    expect($response->getContent())->not->toContain('PRIVATE SYNTHETIC ADDRESS')->not->toContain('12345678900');
    $log = DataAccessLog::latest('accessed_at')->first();
    expect($log->actor_type)->toBe('integrator')->and($log->integrator_id)->toBe($this->ctx['integrator']->id)->and($log->entity_user_integrator_id)->toBe($this->ctx['integratorUser']->id)->and($log->token_id)->not->toBeNull();
    expect(json_encode($log))->not->toContain($this->ctx['token']);
});
it('audits lists once with counts and safe filters without search content', function () {
    $this->getJson('/api/integrators/v1/patients?search=PRIVATESEARCH', $this->ctx['headers'])->assertOk();
    $log = DataAccessLog::where('resource_type', 'patients')->first();
    expect($log->access_summary['count'])->toBe(0)->and(json_encode($log))->not->toContain('PRIVATESEARCH');
});
it('rejects token arrays and checks inactivity before renewal', function () {
    $this->postJson('/api/integrators/check-token', ['token' => ['wrong']])->assertUnprocessable();
    $this->ctx['integratorUser']->tokens()->update(['last_used_at' => now()->subDays(8)]);
    $this->postJson('/api/integrators/check-token', ['token' => $this->ctx['token']])->assertUnauthorized()->assertJsonPath('valid', false);
    expect($this->ctx['integratorUser']->tokens()->count())->toBe(0);
});
it('authorizes purposes from the server profile and never elevates from signin input', function () {
    $this->ctx['integrator']->update(['token_profile' => 'support']);
    $token   = $this->ctx['integratorUser']->createToken('purpose', app(IntegratorTokenPolicy::class)->abilities($this->ctx['integrator']), now()->addDays(7));
    $headers = ['Authorization' => 'Bearer ' . $token->plainTextToken];
    $this->getJson('/api/integrators/v1/patients', $headers)->assertForbidden()->assertJsonPath('code', 'token_purpose_insufficient');
    $this->getJson('/api/integrators/v1/commands', $headers)->assertOk();
});
it('keeps operational recovery available without granting clinical access when plan is unavailable', function () {
    DB::table('subscriptions')->where('entity_id', $this->ctx['entity']->id)->delete();
    $this->getJson('/api/integrators/v1/patients', $this->ctx['headers'])->assertForbidden();
    $this->getJson('/api/integrators/v1/commands', $this->ctx['headers'])->assertOk();
    $this->getJson('/api/integrators/v1/updates?platform=windows&arch=x86&os_version=6.1.7601', $this->ctx['headers'])->assertOk();
    $this->putJson('/api/integrators/v1/queue-health', ['pending_count' => 0, 'failed_count' => 0, 'blocked_count' => 0, 'sent_last_24h_count' => 0, 'problems' => []], $this->ctx['headers'])->assertNoContent();
});
it('rejects extra and oversized telemetry before persistence', function () {
    $body = ['pending_count' => 0, 'failed_count' => 1, 'blocked_count' => 0, 'sent_last_24h_count' => 0, 'problems' => [['id' => 1, 'file_name' => 'safe', 'status' => 'failed', 'attempts' => 1, 'updated_at' => now()->toIso8601String(), 'extra' => ['patient_name' => 'SYNTHETIC']]]];
    $this->putJson('/api/integrators/v1/queue-health', $body, $this->ctx['headers'])->assertUnprocessable();
    $body['problems'] = [];
    $body['padding']  = str_repeat('x', 65537);
    $this->putJson('/api/integrators/v1/queue-health', $body, $this->ctx['headers'])->assertUnprocessable();
    expect(DB::table('integrator_queue_health')->count())->toBe(0);
});
it('expires overdue commands and never changes terminal state or arbitrary result', function () {
    $command = IntegratorCommand::create(['integrator_id' => $this->ctx['integrator']->id, 'type' => 'run_diagnostics', 'payload' => [], 'status' => 'pending', 'expires_at' => now()->subSecond()]);
    $this->postJson('/api/integrators/v1/commands/' . $command->id . '/ack', ['status' => 'completed', 'result' => ['pending' => 1]], $this->ctx['headers'])->assertNoContent();
    expect($command->refresh()->status)->toBe('expired');
    $next = IntegratorCommand::create(['integrator_id' => $this->ctx['integrator']->id, 'type' => 'run_diagnostics', 'payload' => [], 'status' => 'pending', 'expires_at' => now()->addMinutes(5)]);
    $this->postJson('/api/integrators/v1/commands/' . $next->id . '/ack', ['status' => 'completed', 'result' => ['patient_name' => 'SYNTHETIC']], $this->ctx['headers'])->assertUnprocessable();
    $this->postJson('/api/integrators/v1/commands/' . $next->id . '/ack', ['status' => 'completed', 'result' => ['pending' => 1]], $this->ctx['headers'])->assertNoContent();
    $this->postJson('/api/integrators/v1/commands/' . $next->id . '/ack', ['status' => 'failed', 'result' => ['error_code' => 'diagnostics_failed']], $this->ctx['headers'])->assertNoContent();
    expect($next->refresh()->result)->toBe(['pending' => 1]);
});
it('replays equipment operations after a day and refuses conflicting payload and stale generations', function () {
    $id        = (string) Str::uuid();
    $body      = ['operation_id' => $id, 'installation_id' => (string) Str::uuid(), 'name' => 'OCULUS SYNTHETIC'];
    $headers   = [...$this->ctx['headers'], 'Idempotency-Key' => $id];
    $response  = $this->postJson('/api/integrators/v1/equipments', $body, $headers)->assertCreated()->assertJsonPath('meta.operation_id', $id)->assertJsonPath('data.attributes.config_generation', 1);
    $equipment = $response->json('data.id');
    DB::table('integrator_equipment_operations')->update(['created_at' => now()->subDays(2)]);
    $this->postJson('/api/integrators/v1/equipments', $body, $headers)->assertCreated()->assertJsonPath('data.id', $equipment)->assertHeader('Idempotency-Replayed', 'true');
    expect(EntityIntegratorEquipment::count())->toBe(1);
    $this->postJson('/api/integrators/v1/equipments', [...$body, 'name' => 'DIFFERENT'], $headers)->assertConflict()->assertJsonPath('code', 'equipment_operation_payload_conflict');
    $this->getJson('/api/integrators/v1/equipment-operations/' . $id, $this->ctx['headers'])->assertOk()->assertJsonPath('data.id', $equipment);
    $update = [...$body, 'operation_id' => (string) Str::uuid(), 'expected_config_generation' => 2];
    $this->putJson('/api/integrators/v1/equipments/' . $equipment, $update, [...$this->ctx['headers'], 'Idempotency-Key' => $update['operation_id']])->assertConflict()->assertJsonPath('code', 'equipment_config_generation_conflict');
});
it('returns a complete tenant resource snapshot with referenced patients and finite expiry', function () {
    $resource = ClinicResource::create(['name' => 'Synthetic device', 'active' => true, 'entity_id' => $this->ctx['entity']->id, 'type' => 'equipment']);
    $patient  = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id, 'active' => true]);
    $schedule = createScheduleForEntity($this->ctx['entity'], ['patient_id' => $patient->id])['schedule'];
    $schedule->resources()->attach($resource->id, ['id' => (string) Str::uuid()]);
    $response = $this->getJson('/api/integrators/v1/offline-snapshot?clinic_resource_id=' . $resource->id . '&date_from=' . now()->toDateString() . '&date_to=' . now()->toDateString(), $this->ctx['headers'])->assertOk()->assertJsonPath('complete', true)->assertJsonPath('truncated', false)->assertJsonPath('entity_id', $this->ctx['entity']->id)->assertJsonCount(1, 'patients')->assertJsonCount(1, 'schedules');
    expect(strtotime($response->json('expires_at')) - strtotime($response->json('generated_at')))->toBe(86400);
    p2WriteArtifact('snapshot-contract.json', $response->getContent());
});
it('accepts a real textual OCULUS multipart EMR on both flat and nested endpoints and preserves literal original', function () {
    $patient  = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id, 'active' => true]);
    $schedule = createScheduleForEntity($this->ctx['entity'], ['patient_id' => $patient->id])['schedule'];
    $raw      = "Last Name: Synthetic\r\nFirst Name: Pilot\r\nPatient ID: 1234567\r\nExam Eye: Right\r\nExam Date: 01.10.2026\r\nExam Time: 14:15:16\r\nDisplay: Topo 4-Maps\r\n";

    foreach (['/api/integrators/v1/exams', '/api/integrators/v1/patients/' . $patient->id . '/exams'] as $route) {
        $file     = UploadedFile::fake()->createWithContent('oculus.emr', $raw);
        $capture  = (string) Str::uuid();
        $response = $this->post($route, ['archive' => $file, 'name' => 'Synthetic textual report', 'patient_identifier' => $patient->id, 'schedule_identifier' => $schedule->id, 'capture_id' => $capture, 'content_sha256' => hash('sha256', $raw), 'exam_identifier' => ExamType::factory()->create(['entity_id' => $this->ctx['entity']->id])->id], [...$this->ctx['headers'], 'Accept' => 'application/json', 'Idempotency-Key' => $capture])->assertCreated();
        $exam     = PatientExam::find($response->json('data.id'));
        expect(Storage::disk('s3')->get($exam->archive))->toBe($raw);
        app(GenerateExamDerivatives::class, ['patientExamId' => $exam->id, 'expectedArchive' => $exam->archive])->handle();
        expect($exam->refresh()->derivative_status)->toBe('ready');
        expect(Storage::disk('s3')->get($exam->display_archive))->toContain('Patient ID: 1234567');
    }
});
it('rejects binary and arbitrary EMR and duplicate labels instead of accepting octet stream by extension', function () {
    foreach (["\0random", 'plain arbitrary text', "Patient ID: 1\nPatient ID: 2\n", "Patient ID: 1\nUnknown: value\n"] as $bad) {
        expect(fn () => app(EmrReport::class)->parse($bad))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => app(EmrReport::class)->parse("Patient ID: 1\nDisplay: <script>alert(1)</script>\n"))->toThrow(InvalidArgumentException::class);
    $parsed = ['fields' => ['Display' => '<script>alert(1)</script>']];
    expect(app(EmrReport::class)->svg($parsed))->not->toContain('<script>')->toContain('&lt;script&gt;');
});
it('requires signed metadata and publishes verified immutable bytes for distinct targets', function () {
    $kp     = sodium_crypto_sign_seed_keypair(str_repeat('P', 32));
    $secret = sodium_crypto_sign_secretkey($kp);
    config(['services.integrator_updates.public_key' => bin2hex(sodium_crypto_sign_publickey($kp))]);
    $file = tempnam(sys_get_temp_dir(), 'p2-msi-');
    file_put_contents($file, 'synthetic installer bytes');
    $fixture = p2ManifestFixture($file, '1.0.0', 'windows', 'x86', $secret);
    $service = app(IntegratorUpdatePublisher::class);
    expect(fn () => $service->publish($file, 'setup.msi', '1.0.0', 'windows', 'x86', $fixture['metadata']['asset_signature']))->toThrow(InvalidArgumentException::class);
    $update = $service->publish($file, 'setup.msi', '1.0.0', 'windows', 'x86', $fixture['metadata']['asset_signature'], false, $fixture['metadata'], $fixture['manifestSignature']);
    expect($update->active)->toBeTrue()->and($update->archive)->toContain('/windows/x86/1.0.0/');
    $tampered           = $fixture['metadata'];
    $tampered['cohort'] = 'untrusted';
    expect(fn () => $service->publish($file, 'setup.msi', '1.0.0', 'windows', 'x86', $fixture['metadata']['asset_signature'], false, $tampered, $fixture['manifestSignature']))->toThrow(InvalidArgumentException::class);
    $this->getJson('/api/integrators/v1/updates?platform=windows&arch=x86&os_version=6.1.7601', $this->ctx['headers'])->assertOk()->assertJsonPath('data.metadata_verified', true);
    $this->getJson('/api/integrators/v1/updates?platform=windows&arch=x86&os_version=6.1', $this->ctx['headers'])->assertOk()->assertJsonPath('data', null);
    p2WriteArtifact('update-v2-fixture.json', json_encode(['metadata' => $fixture['metadata'], 'manifest_signature' => $fixture['manifestSignature'], 'public_key' => bin2hex(sodium_crypto_sign_publickey($kp)), 'canonical_base64' => base64_encode(app(IntegratorUpdateManifest::class)->canonical($fixture['metadata']))]));
    @unlink($file);
});

it('requires a nonzero expected generation before any operational update effect', function () {
    $equipment = EntityIntegratorEquipment::factory()->create(['integrator_id' => $this->ctx['integrator']->id]);

    foreach ([null, 0, 'absent'] as $value) {
        $id   = (string) Str::uuid();
        $body = ['operation_id' => $id, 'installation_id' => (string) Str::uuid(), 'name' => 'Updated name'];

        if ($value !== 'absent') {
            $body['expected_config_generation'] = $value;
        }
        $this->putJson('/api/integrators/v1/equipments/' . $equipment->id, $body, [...$this->ctx['headers'], 'Idempotency-Key' => $id])->assertUnprocessable();
        expect(DB::table('integrator_equipment_operations')->count())->toBe(0)->and((int) $equipment->refresh()->config_generation)->toBe(1);
    }
});

it('records registration login observed capture and confirmed receipt as separate milestones', function () {
    $entity = $this->ctx['entity']->id;
    $steps  = fn () => EntityActivation::where('entity_id', $entity)->pluck('step')->map(fn ($step) => $step->value)->all();
    expect($steps())->toContain('integrator_registered')->not->toContain('integrator_connected', 'integrator_capture_observed', 'integrator_receipt_confirmed');
    $this->ctx['integratorUser']->update(['password' => Hash::make('SyntheticPassword123!')]);
    $this->postJson('/api/integrators/signin', ['email' => $this->ctx['integratorUser']->email, 'password' => 'SyntheticPassword123!', 'code' => $this->ctx['integrator']->code])->assertOk();
    expect($steps())->toContain('integrator_connected')->not->toContain('integrator_capture_observed', 'integrator_receipt_confirmed');
    $health = ['pending_count' => 0, 'failed_count' => 0, 'blocked_count' => 0, 'sent_last_24h_count' => 0, 'problems' => [], 'operational' => ['version' => '1.0.0', 'scope' => str_repeat('a', 64), 'ingest_pending' => 0, 'quarantined' => 0, 'acquisition_rejected' => 0, 'devices' => [['equipment_id' => 1, 'rejected_count' => 0, 'last_accepted_at' => now()->toIso8601String()]], 'next_action' => 'verificar gravação do spool; journal preservado']];
    $this->putJson('/api/integrators/v1/queue-health', $health, $this->ctx['headers'])->assertNoContent();
    expect($steps())->toContain('integrator_capture_observed')->not->toContain('integrator_receipt_confirmed');
    $patient = Patient::factory()->create(['entity_id' => $entity, 'active' => true]);
    $type    = ExamType::factory()->create(['entity_id' => null]);
    $file    = UploadedFile::fake()->image('synthetic-milestone.jpg');
    $capture = (string) Str::uuid();
    $body    = ['capture_id' => $capture, 'content_sha256' => hash_file('sha256', $file->getRealPath()), 'patient_identifier' => $patient->id, 'exam_identifier' => $type->id, 'archive' => $file, 'name' => 'Synthetic capture'];
    $this->postJson('/api/integrators/v1/exams', $body, $this->ctx['headers'] + ['Idempotency-Key' => $capture])->assertCreated();
    expect($steps())->toContain('integrator_receipt_confirmed');
    $this->postJson('/api/integrators/v1/exams', $body, $this->ctx['headers'] + ['Idempotency-Key' => $capture])->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect(EntityActivation::where('entity_id', $entity)->where('step', 'integrator_receipt_confirmed')->count())->toBe(1);
});

it('keeps clinic calendar dates and UTC instants correct across midnight', function () {
    $oldTimezone = date_default_timezone_get();
    config(['app.timezone' => 'America/Sao_Paulo']);
    date_default_timezone_set('America/Sao_Paulo');

    try {
        $resource = ClinicResource::create(['name' => 'Synthetic calendar resource', 'active' => true, 'entity_id' => $this->ctx['entity']->id, 'type' => 'equipment']);
        $patient  = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);

        foreach (['09:00:00', '23:30:00'] as $time) {
            $schedule = createScheduleForEntity($this->ctx['entity'], ['patient_id' => $patient->id, 'date_time' => '2026-10-02 ' . $time])['schedule'];
            $schedule->resources()->attach($resource->id, ['id' => (string) Str::uuid()]);
        }
        $response = $this->getJson('/api/integrators/v1/offline-snapshot?clinic_resource_id=' . $resource->id . '&date_from=2026-10-02&date_to=2026-10-02', $this->ctx['headers'])->assertOk()->assertJsonCount(2, 'schedules')->assertJsonPath('clinic_timezone', 'America/Sao_Paulo');
        expect($response->json('schedules.0.attributes.date_time'))->toStartWith('2026-10-02T12:00:00')
            ->and($response->json('schedules.1.attributes.date_time'))->toStartWith('2026-10-03T02:30:00')
            ->and($response->json('schedules.1.attributes.clinic_date'))->toBe('2026-10-02')
            ->and($response->json('schedules.1.attributes.local_date_time'))->toBe('2026-10-02T23:30:00-03:00');
        p2WriteArtifact('snapshot-clinic-timezone.json', $response->getContent());
    } finally {
        date_default_timezone_set($oldTimezone);
    }
});

it('accepts only the finite trusted HTTPS artifact URL policy', function () {
    $service = app(IntegratorUpdateManifest::class);
    config(['app.url' => 'https://api.synthetic.example']);

    foreach (['https://s3.amazonaws.com/file', 'https://synthetic-only.s3.us-east-1.amazonaws.com/file', 'https://api.synthetic.example/file'] as $url) {
        expect($service->trustedAssetUrl($url))->toBeTrue();
    }

    foreach (['http://api.synthetic.example/file', 'https://evil.example/file', 'https://127.0.0.1/file', 'https://[::1]/file', 'https://api.synthetic.example:444/file', 'https://api.synthetic.example.evil.example/file', 'https://s3.amazonaws.com.evil.example/file', 'https://user@api.synthetic.example/file', 'https://api.synthetic.example/file#fragment'] as $url) {
        expect($service->trustedAssetUrl($url))->toBeFalse();
    }
});

it('expires and prunes stale legacy commands using the consistent terminal deadline', function () {
    $old = IntegratorCommand::forceCreate(['integrator_id' => $this->ctx['integrator']->id, 'type' => 'run_diagnostics', 'payload' => [], 'status' => 'pending', 'created_at' => now()->subDays(40)]);
    $this->getJson('/api/integrators/v1/commands', $this->ctx['headers'])->assertOk()->assertJsonCount(0, 'data');
    expect($old->refresh()->status)->toBe('expired')->and($old->acked_at->equalTo($old->created_at->copy()->addHour()))->toBeTrue();
    $this->postJson('/api/integrators/v1/commands/' . $old->id . '/ack', ['status' => 'completed', 'result' => ['pending' => 1]], $this->ctx['headers'])->assertNoContent();
    expect($old->refresh()->status)->toBe('expired');
    $this->artisan('integrator-commands:prune')->assertSuccessful();
    expect(IntegratorCommand::find($old->id))->toBeNull();
});
