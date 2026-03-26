<?php

use App\Models\ExamType;

describe('GET /api/integrators/v1/examtypes', function () {
    beforeEach(function () {
        $this->ctx = setupIntegrator();
    });

    it('lists global exam types', function () {
        ExamType::factory(3)->create(['entity_id' => null]);

        $this->getJson('/api/integrators/v1/examtypes', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    });

    it('lists entity-specific exam types alongside global ones', function () {
        ExamType::factory(2)->create(['entity_id' => null]);
        ExamType::factory(1)->create(['entity_id' => $this->ctx['entity']->id]);

        $this->getJson('/api/integrators/v1/examtypes', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    });

    it('does not list exam types from another entity', function () {
        $other = setupIntegrator();

        ExamType::factory(2)->create(['entity_id' => null]);
        ExamType::factory(3)->create(['entity_id' => $other['entity']->id]);

        $this->getJson('/api/integrators/v1/examtypes', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    });

    it('searches exam types by name', function () {
        ExamType::factory()->create(['entity_id' => null, 'name' => 'Fundoscopia Digital']);
        ExamType::factory()->create(['entity_id' => null, 'name' => 'Retinografia']);

        $response = $this->getJson(
            '/api/integrators/v1/examtypes?search=FUND',
            $this->ctx['headers']
        )->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('searches exam types by category number', function () {
        ExamType::factory(2)->create(['entity_id' => null, 'category' => 1]);
        ExamType::factory(3)->create(['entity_id' => null, 'category' => 5]);

        $response = $this->getJson(
            '/api/integrators/v1/examtypes?search=1',
            $this->ctx['headers']
        )->assertOk();

        expect($response->json('meta.total'))->toBe(2);
    });

    it('caps per_page at plan limit', function () {
        ExamType::factory(5)->create(['entity_id' => null]);

        $response = $this->getJson(
            '/api/integrators/v1/examtypes?per_page=999',
            $this->ctx['headers']
        )->assertOk();

        expect($response->json('meta.per_page'))->toBeLessThanOrEqual(100);
    });

    it('returns 401 without authentication', function () {
        $this->getJson('/api/integrators/v1/examtypes')
            ->assertUnauthorized();
    });
});

describe('GET /api/integrators/v1/examtypes/{id}', function () {
    beforeEach(function () {
        $this->ctx      = setupIntegrator();
        $this->examType = ExamType::factory()->create(['entity_id' => null]);
    });

    it('shows exam type by UUID', function () {
        $this->getJson(
            "/api/integrators/v1/examtypes/{$this->examType->id}",
            $this->ctx['headers']
        )->assertOk()
            ->assertJsonFragment(['id' => $this->examType->id]);
    });

    it('shows exam type by code', function () {
        $this->getJson(
            "/api/integrators/v1/examtypes/{$this->examType->code}",
            $this->ctx['headers']
        )->assertOk()
            ->assertJsonFragment(['id' => $this->examType->id]);
    });

    it('shows global exam type by integer number', function () {
        $numericPart = (int) substr($this->examType->code, strrpos($this->examType->code, '-') + 1);

        $this->getJson(
            "/api/integrators/v1/examtypes/{$numericPart}",
            $this->ctx['headers']
        )->assertOk()
            ->assertJsonFragment(['id' => $this->examType->id]);
    });

    it('shows entity-specific exam type', function () {
        $entityExam = ExamType::factory()->create(['entity_id' => $this->ctx['entity']->id]);

        $this->getJson(
            "/api/integrators/v1/examtypes/{$entityExam->id}",
            $this->ctx['headers']
        )->assertOk()
            ->assertJsonFragment(['id' => $entityExam->id]);
    });

    it('returns 404 for non-existent exam type', function () {
        $this->getJson(
            '/api/integrators/v1/examtypes/ETP-NAOEXISTE',
            $this->ctx['headers']
        )->assertNotFound();
    });

    it('returns 404 for exam type from another entity', function () {
        $other    = setupIntegrator();
        $examType = ExamType::factory()->create(['entity_id' => $other['entity']->id]);

        $this->getJson(
            "/api/integrators/v1/examtypes/{$examType->id}",
            $this->ctx['headers']
        )->assertNotFound();
    });

    it('returns 401 without authentication', function () {
        $this->getJson("/api/integrators/v1/examtypes/{$this->examType->id}")
            ->assertUnauthorized();
    });
});
