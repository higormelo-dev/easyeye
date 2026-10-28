<?php

use App\Models\{EntityIntegratorEquipment, IntegratorQueueHealth};

beforeEach(function () {
    $this->ctx         = setupIntegrator();
    $this->operational = ['version' => '0.1.0', 'scope' => str_repeat('a', 64), 'ingest_pending' => 2,
        'quarantined'               => 1, 'acquisition_rejected' => 1, 'unconfirmed_sent' => 3,
        'oldest_pending_at'         => '2026-10-01 12:00:00', 'disk_free_bytes' => 1048576,
        'devices'                   => [['equipment_id' => 1, 'last_observed_at' => '2026-10-01 12:00:00', 'last_accepted_at' => null,
            'rejected_count'                            => 1, 'issue' => 'source_or_spool_unavailable']],
        'next_action' => 'verificar gravação do spool; journal preservado'];
    $this->health = ['pending_count' => 4, 'failed_count' => 0, 'blocked_count' => 0, 'sent_last_24h_count' => 0, 'problems' => [], 'operational' => $this->operational];
});

it('persists bounded operational diagnostics only under the authenticated integrator', function () {
    $this->putJson('/api/integrators/v1/queue-health', $this->health, $this->ctx['headers'])->assertNoContent();
    $saved = IntegratorQueueHealth::where('integrator_id', $this->ctx['integrator']->id)->sole();
    expect($saved->operational)->toBe($this->operational);
    $other = setupIntegrator();
    expect(IntegratorQueueHealth::where('integrator_id', $other['integrator']->id)->exists())->toBeFalse();
});

it('accepts a new installation with no observed devices but rejects a malformed empty snapshot', function () {
    $payload                           = $this->health;
    $payload['operational']['devices'] = [];
    $this->putJson('/api/integrators/v1/queue-health', $payload, $this->ctx['headers'])->assertNoContent();
    $payload['operational'] = [];
    $this->putJson('/api/integrators/v1/queue-health', $payload, $this->ctx['headers'])->assertUnprocessable()->assertJsonValidationErrors('operational');
    $payload['operational'] = null;
    $this->putJson('/api/integrators/v1/queue-health', $payload, $this->ctx['headers'])->assertNoContent();
});

it('rejects extra clinical fields, unknown diagnostic messages and unbounded devices', function () {
    $payload                                = $this->health;
    $payload['operational']['patient_name'] = 'Synthetic prohibited identity';
    $this->putJson('/api/integrators/v1/queue-health', $payload, $this->ctx['headers'])->assertUnprocessable()->assertJsonValidationErrors('operational');
    $payload                                       = $this->health;
    $payload['operational']['devices'][0]['issue'] = 'Synthetic patient data';
    $this->putJson('/api/integrators/v1/queue-health', $payload, $this->ctx['headers'])->assertUnprocessable()->assertJsonValidationErrors('operational.devices.0.issue');
    $payload                           = $this->health;
    $payload['operational']['devices'] = array_fill(0, 101, $this->operational['devices'][0]);
    $this->putJson('/api/integrators/v1/queue-health', $payload, $this->ctx['headers'])->assertUnprocessable()->assertJsonValidationErrors('operational.devices');
    expect(IntegratorQueueHealth::count())->toBe(0);
});

it('accepts explicit heartbeat and build capabilities without inventing process state', function () {
    $equipment   = EntityIntegratorEquipment::factory()->create(['integrator_id' => $this->ctx['integrator']->id]);
    $operational = $this->operational + [
        'heartbeat_at'  => '2026-10-02T12:45:00Z',
        'capabilities'  => ['folder_capture' => true, 'dicom_storage' => false, 'dicom_mwl' => false, 'ocr' => true, 'rpa' => false],
        'watcher_state' => 'unknown', 'mwl_state' => 'unknown',
    ];
    $operational['devices'][0]['remote_equipment_id'] = $equipment->id;
    $this->putJson('/api/integrators/v1/queue-health', array_replace($this->health, ['operational' => $operational]), $this->ctx['headers'])->assertNoContent();
    expect(IntegratorQueueHealth::sole()->operational)->toBe($operational);
    $other                                            = setupIntegrator();
    $foreign                                          = EntityIntegratorEquipment::factory()->create(['integrator_id' => $other['integrator']->id]);
    $operational['devices'][0]['remote_equipment_id'] = $foreign->id;
    $this->putJson('/api/integrators/v1/queue-health', array_replace($this->health, ['operational' => $operational]), $this->ctx['headers'])->assertUnprocessable()->assertJsonValidationErrors('operational.devices.0.remote_equipment_id');
});

it('rejects untyped capabilities and unsupported process claims', function () {
    $payload                                = $this->health;
    $payload['operational']['capabilities'] = [];
    $this->putJson('/api/integrators/v1/queue-health', $payload, $this->ctx['headers'])->assertUnprocessable()->assertJsonValidationErrors('operational.capabilities');
    $payload                                 = $this->health;
    $payload['operational']['watcher_state'] = 'apparently healthy';
    $this->putJson('/api/integrators/v1/queue-health', $payload, $this->ctx['headers'])->assertUnprocessable()->assertJsonValidationErrors('operational.watcher_state');
});
