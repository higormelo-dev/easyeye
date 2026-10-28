<?php

use App\Models\ClinicResource;

describe('GET /api/integrators/v1/clinic-resources', function () {
    beforeEach(function () {
        $this->ctx = setupIntegrator();
    });

    it('lists only type=equipment resources belonging to the integrator\'s entity', function () {
        ClinicResource::create([
            'entity_id' => $this->ctx['entity']->id,
            'name'      => 'Retinógrafo Sala 1',
            'type'      => 'equipment',
        ]);
        ClinicResource::create([
            'entity_id' => $this->ctx['entity']->id,
            'name'      => 'Sala De Exame 1',
            'type'      => 'room',
        ]);

        $response = $this->getJson('/api/integrators/v1/clinic-resources', $this->ctx['headers'])
            ->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.attributes.name'))->toBe('Retinógrafo Sala 1')
            ->and($response->json('data.0.attributes.type'))->toBe('equipment');
    });

    it('does not return clinic resources from other entities', function () {
        $other = setupIntegrator();
        ClinicResource::create([
            'entity_id' => $other['entity']->id,
            'name'      => 'Recurso De Outra Clínica',
            'type'      => 'equipment',
        ]);
        ClinicResource::create([
            'entity_id' => $this->ctx['entity']->id,
            'name'      => 'Recurso Da Própria Clínica',
            'type'      => 'equipment',
        ]);

        $response = $this->getJson('/api/integrators/v1/clinic-resources', $this->ctx['headers'])
            ->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.attributes.name'))->toBe('Recurso Da Própria Clínica');
    });

    it('filters by search term on name', function () {
        ClinicResource::create([
            'entity_id' => $this->ctx['entity']->id,
            'name'      => 'Retinógrafo Sala 1',
            'type'      => 'equipment',
        ]);
        ClinicResource::create([
            'entity_id' => $this->ctx['entity']->id,
            'name'      => 'Tonômetro Sala 2',
            'type'      => 'equipment',
        ]);

        $response = $this->getJson('/api/integrators/v1/clinic-resources?search=Retin', $this->ctx['headers'])
            ->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('returns 401 without authentication', function () {
        $this->getJson('/api/integrators/v1/clinic-resources')
            ->assertUnauthorized();
    });
});
