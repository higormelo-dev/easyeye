<?php

use App\Models\IntegratorCommand;

describe('GET /api/integrators/v1/commands', function () {
    beforeEach(function () {
        $this->ctx = setupIntegrator();
    });

    it('lists only pending commands for the authenticated integrator', function () {
        IntegratorCommand::create([
            'integrator_id' => $this->ctx['integrator']->id,
            'type'          => 'resync_now',
            'payload'       => [],
            'status'        => 'pending',
        ]);
        IntegratorCommand::create([
            'integrator_id' => $this->ctx['integrator']->id,
            'type'          => 'run_diagnostics',
            'payload'       => [],
            'status'        => 'completed',
        ]);

        $response = $this->getJson('/api/integrators/v1/commands', $this->ctx['headers'])
            ->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.type'))->toBe('command')
            ->and($response->json('data.0.attributes.type'))->toBe('resync_now');
    });

    it('never lists another integrator\'s commands', function () {
        $other = setupIntegrator();
        IntegratorCommand::create([
            'integrator_id' => $other['integrator']->id,
            'type'          => 'resync_now',
            'payload'       => [],
            'status'        => 'pending',
        ]);

        $this->getJson('/api/integrators/v1/commands', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('returns 401 without authentication', function () {
        $this->getJson('/api/integrators/v1/commands')->assertUnauthorized();
    });
});

describe('POST /api/integrators/v1/commands/{command}/ack', function () {
    beforeEach(function () {
        $this->ctx = setupIntegrator();
    });

    it('marks a pending command as completed with a result payload', function () {
        $command = IntegratorCommand::create([
            'integrator_id' => $this->ctx['integrator']->id,
            'type'          => 'run_diagnostics',
            'payload'       => [],
            'status'        => 'pending',
        ]);

        $this->postJson(
            "/api/integrators/v1/commands/{$command->id}/ack",
            ['status' => 'completed', 'result' => ['queue_pending' => 3]],
            $this->ctx['headers'],
        )->assertNoContent();

        $command->refresh();
        expect($command->status)->toBe('completed')
            ->and($command->result)->toBe(['queue_pending' => 3])
            ->and($command->acked_at)->not->toBeNull();
    });

    it('marks a command as failed when the client reports failure', function () {
        $command = IntegratorCommand::create([
            'integrator_id' => $this->ctx['integrator']->id,
            'type'          => 'resync_now',
            'payload'       => [],
            'status'        => 'pending',
        ]);

        $this->postJson(
            "/api/integrators/v1/commands/{$command->id}/ack",
            ['status' => 'failed', 'result' => ['error' => 'disk full']],
            $this->ctx['headers'],
        )->assertNoContent();

        expect($command->refresh()->status)->toBe('failed');
    });

    it('is idempotent: a second ack never overwrites the first', function () {
        $command = IntegratorCommand::create([
            'integrator_id' => $this->ctx['integrator']->id,
            'type'          => 'resync_now',
            'payload'       => [],
            'status'        => 'pending',
        ]);

        $this->postJson(
            "/api/integrators/v1/commands/{$command->id}/ack",
            ['status' => 'completed', 'result' => ['run' => 1]],
            $this->ctx['headers'],
        )->assertNoContent();

        // Simulates a retry after a lost response (network timeout on the
        // client's side of the first ack) — must not flip a completed
        // command back to failed, or overwrite its result.
        $this->postJson(
            "/api/integrators/v1/commands/{$command->id}/ack",
            ['status' => 'failed', 'result' => ['run' => 2]],
            $this->ctx['headers'],
        )->assertNoContent();

        $command->refresh();
        expect($command->status)->toBe('completed')
            ->and($command->result)->toBe(['run' => 1]);
    });

    it('rejects an ack for another integrator\'s command', function () {
        $other   = setupIntegrator();
        $command = IntegratorCommand::create([
            'integrator_id' => $other['integrator']->id,
            'type'          => 'resync_now',
            'payload'       => [],
            'status'        => 'pending',
        ]);

        $this->postJson(
            "/api/integrators/v1/commands/{$command->id}/ack",
            ['status' => 'completed'],
            $this->ctx['headers'],
        )->assertNotFound();

        expect($command->fresh()->status)->toBe('pending');
    });

    it('rejects a status outside completed/failed', function () {
        $command = IntegratorCommand::create([
            'integrator_id' => $this->ctx['integrator']->id,
            'type'          => 'resync_now',
            'payload'       => [],
            'status'        => 'pending',
        ]);

        $this->postJson(
            "/api/integrators/v1/commands/{$command->id}/ack",
            ['status' => 'pending'],
            $this->ctx['headers'],
        )->assertUnprocessable()->assertJsonValidationErrors(['status']);
    });

    it('returns 404 for a command id that does not exist', function () {
        $this->postJson(
            '/api/integrators/v1/commands/' . \Illuminate\Support\Str::uuid() . '/ack',
            ['status' => 'completed'],
            $this->ctx['headers'],
        )->assertNotFound();
    });

    it('returns 401 without authentication', function () {
        $command = IntegratorCommand::create([
            'integrator_id' => $this->ctx['integrator']->id,
            'type'          => 'resync_now',
            'payload'       => [],
            'status'        => 'pending',
        ]);

        $this->postJson("/api/integrators/v1/commands/{$command->id}/ack", ['status' => 'completed'])
            ->assertUnauthorized();
    });
});
