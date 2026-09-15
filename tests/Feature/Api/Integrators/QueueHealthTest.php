<?php

use App\Models\IntegratorQueueHealth;

describe('PUT /api/integrators/v1/queue-health', function () {
    beforeEach(function () {
        $this->ctx = setupIntegrator();
    });

    function validQueueHealthPayload(array $overrides = []): array
    {
        return [
            'pending_count'       => 3,
            'failed_count'        => 1,
            'blocked_count'       => 2,
            'sent_last_24h_count' => 40,
            'problems'            => [
                [
                    'id'                  => 42,
                    'file_name'           => '<file:a1b2c3d4>.jpg',
                    'status'              => 'blocked',
                    'schedule_identifier' => 'SDL-0000000722',
                    'patient_identifier'  => null,
                    'last_error'          => 'file exceeds the 10 MB upload limit',
                    'attempts'            => 0,
                    'api_status'          => null,
                    'updated_at'          => now()->toIso8601String(),
                ],
            ],
            ...$overrides,
        ];
    }

    it('creates a queue health snapshot on first sync', function () {
        $this->putJson('/api/integrators/v1/queue-health', validQueueHealthPayload(), $this->ctx['headers'])
            ->assertNoContent();

        $row = IntegratorQueueHealth::where('integrator_id', $this->ctx['integrator']->id)->first();
        expect($row)->not->toBeNull()
            ->and($row->pending_count)->toBe(3)
            ->and($row->blocked_count)->toBe(2)
            ->and($row->problems)->toHaveCount(1)
            ->and($row->problems[0]['schedule_identifier'])->toBe('SDL-0000000722');
    });

    it('upserts in place instead of accumulating a new row per sync', function () {
        $this->putJson('/api/integrators/v1/queue-health', validQueueHealthPayload(), $this->ctx['headers'])
            ->assertNoContent();
        $this->putJson(
            '/api/integrators/v1/queue-health',
            validQueueHealthPayload(['pending_count' => 9, 'problems' => []]),
            $this->ctx['headers'],
        )->assertNoContent();

        expect(IntegratorQueueHealth::where('integrator_id', $this->ctx['integrator']->id)->count())->toBe(1);
        $row = IntegratorQueueHealth::where('integrator_id', $this->ctx['integrator']->id)->first();
        expect($row->pending_count)->toBe(9)
            ->and($row->problems)->toBe([]);
    });

    it('upserts only the authenticated integrator\'s own row, never another\'s', function () {
        $other = setupIntegrator();
        IntegratorQueueHealth::create([
            'integrator_id'       => $other['integrator']->id,
            'pending_count'       => 999,
            'failed_count'        => 0,
            'blocked_count'       => 0,
            'sent_last_24h_count' => 0,
            'problems'            => [],
            'synced_at'           => now(),
        ]);

        $this->putJson('/api/integrators/v1/queue-health', validQueueHealthPayload(), $this->ctx['headers'])
            ->assertNoContent();

        expect(IntegratorQueueHealth::count())->toBe(2);
        $mine = IntegratorQueueHealth::where('integrator_id', $this->ctx['integrator']->id)->first();
        expect($mine->pending_count)->toBe(3);
        $untouched = IntegratorQueueHealth::where('integrator_id', $other['integrator']->id)->first();
        expect($untouched->pending_count)->toBe(999);
    });

    it('rejects more than 50 problem entries', function () {
        $problems = array_fill(0, 51, [
            'id'                  => 1, 'file_name' => 'x.jpg', 'status' => 'failed',
            'schedule_identifier' => null, 'patient_identifier' => null,
            'last_error'          => null, 'attempts' => 1, 'api_status' => null,
            'updated_at'          => now()->toIso8601String(),
        ]);

        $this->putJson(
            '/api/integrators/v1/queue-health',
            validQueueHealthPayload(['problems' => $problems]),
            $this->ctx['headers'],
        )->assertUnprocessable()->assertJsonValidationErrors(['problems']);
    });

    it('rejects a problem status outside failed/blocked', function () {
        $payload                          = validQueueHealthPayload();
        $payload['problems'][0]['status'] = 'sent';

        $this->putJson('/api/integrators/v1/queue-health', $payload, $this->ctx['headers'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['problems.0.status']);
    });

    it('accepts an empty problems list', function () {
        $this->putJson(
            '/api/integrators/v1/queue-health',
            validQueueHealthPayload(['problems' => []]),
            $this->ctx['headers'],
        )->assertNoContent();
    });

    it('rejects negative counts', function () {
        $this->putJson(
            '/api/integrators/v1/queue-health',
            validQueueHealthPayload(['pending_count' => -1]),
            $this->ctx['headers'],
        )->assertUnprocessable()->assertJsonValidationErrors(['pending_count']);
    });

    it('returns 401 without authentication', function () {
        $this->putJson('/api/integrators/v1/queue-health', validQueueHealthPayload())
            ->assertUnauthorized();
    });
});
