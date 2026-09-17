<?php

use App\Enums\ScheduleSituation;
use App\Models\{ClinicResource, Doctor, People, Schedule, User, VisitType};
use Illuminate\Support\Str;

/**
 * schedule_resources.id é uuid primary key sem default no banco (gerado só
 * pelo HasUuids do model no `creating()`, que BelongsToMany::sync()/attach()
 * não dispara — o insert do pivot é feito via query builder cru). Achado ao
 * escrever estes testes: Schedule::resources()->sync() quebraria com
 * "null value in column id" na primeira vez que fosse chamado com uma lista
 * não vazia — o que nunca aconteceu em produção porque nenhuma clínica real
 * usa reserva de recursos ainda (clinic_resources está vazia hoje). Pré-
 * existente e fora do escopo desta mudança (não mexe no controller web de
 * agendamento); os testes aqui geram o id do pivot explicitamente para não
 * depender desse caminho.
 */
function linkScheduleToResource(Schedule $schedule, ClinicResource $resource): void
{
    $schedule->resources()->attach($resource->id, ['id' => (string) Str::uuid()]);
}

/**
 * Creates the minimal dependencies to produce a Schedule for the given entity.
 */
function makeSchedule(array $ctx, array $overrides = []): Schedule
{
    $user       = User::factory()->create();
    $entityUser = createEntityUser($ctx['entity'], $user, 'doctor');
    $person     = People::factory()->create();

    $doctor = Doctor::create([
        'entity_user_id' => $entityUser->id,
        'person_id'      => $person->id,
        'active'         => true,
    ]);

    // BUGFIX (achado ao rodar a suite completa pela 1a vez): sem entity_id,
    // cai no catálogo GLOBAL de visit_types, que tem unique parcial em name
    // (visit_types_global_name_unique, migration 2026_08_23) — colidia com o
    // 'CONSULTA' já semeado pela própria migração. Escopado por entidade,
    // igual ao padrão já usado em PanelCrossTenantIdorRound2Test.php.
    $visitType = VisitType::create([
        'entity_id' => $ctx['entity']->id,
        'name'      => 'Consulta',
        'active'    => true,
    ]);

    return Schedule::create(array_merge([
        'entity_id'          => $ctx['entity']->id,
        'doctor_id'          => $doctor->id,
        'visit_id'           => $visitType->id,
        'full_name'          => 'PATIENT TEST',
        'date_time'          => now(),
        'cellphone'          => '11999990000',
        'cellphone_whatsapp' => false,
        'situation'          => ScheduleSituation::Scheduled->value,
        'active'             => true,
    ], $overrides));
}

describe('GET /api/integrators/v1/schedules', function () {
    beforeEach(function () {
        $this->ctx = setupIntegrator();
    });

    it('lists schedules for the entity', function () {
        makeSchedule($this->ctx);
        makeSchedule($this->ctx);

        $this->getJson('/api/integrators/v1/schedules', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    });

    it('does not list schedules from another entity', function () {
        $other = setupIntegrator();

        makeSchedule($this->ctx);
        makeSchedule($other);

        $this->getJson('/api/integrators/v1/schedules', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    });

    it('searches schedules by full_name', function () {
        makeSchedule($this->ctx, ['full_name' => 'JOAO DA SILVA']);
        makeSchedule($this->ctx, ['full_name' => 'MARIA SOUZA']);

        $response = $this->getJson(
            '/api/integrators/v1/schedules?search=joao',
            $this->ctx['headers'],
        )->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('searches schedules by code', function () {
        $schedule = makeSchedule($this->ctx);
        makeSchedule($this->ctx);

        $response = $this->getJson(
            "/api/integrators/v1/schedules?search={$schedule->code}",
            $this->ctx['headers'],
        )->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('searches schedules by bare numeric code (without the SDL- prefix)', function () {
        $schedule    = makeSchedule($this->ctx);
        $numericPart = (int) substr($schedule->code, 4); // remove 'SDL-'
        makeSchedule($this->ctx);

        $response = $this->getJson(
            "/api/integrators/v1/schedules?search={$numericPart}",
            $this->ctx['headers'],
        )->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.id'))->toBe($schedule->id);
    });

    it('searches schedules by shorthand lower-case code (sdl-N without zero-padding)', function () {
        $schedule    = makeSchedule($this->ctx);
        $numericPart = (int) substr($schedule->code, 4); // remove 'SDL-'
        makeSchedule($this->ctx);

        $response = $this->getJson(
            '/api/integrators/v1/schedules?search=' . mb_strtolower("sdl-{$numericPart}"),
            $this->ctx['headers'],
        )->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.id'))->toBe($schedule->id);
    });

    it('searches schedules by code even when schedule date is not today', function () {
        $targetSchedule = makeSchedule($this->ctx, [
            'date_time' => now()->subDays(14),
        ]);
        makeSchedule($this->ctx, ['date_time' => now()]);

        $response = $this->getJson(
            "/api/integrators/v1/schedules?search={$targetSchedule->code}",
            $this->ctx['headers'],
        )->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.id'))->toBe($targetSchedule->id);
    });

    it('searches schedules by doctor name', function () {
        $user       = User::factory()->create();
        $entityUser = createEntityUser($this->ctx['entity'], $user, 'doctor');
        $person     = People::factory()->create(['full_name' => 'DR ANTONIO CARLOS']);
        $doctor     = Doctor::create([
            'entity_user_id' => $entityUser->id,
            'person_id'      => $person->id,
            'active'         => true,
        ]);
        $visitType = VisitType::create(['entity_id' => $this->ctx['entity']->id, 'name' => 'Consulta', 'active' => true]);
        Schedule::create([
            'entity_id'          => $this->ctx['entity']->id,
            'doctor_id'          => $doctor->id,
            'visit_id'           => $visitType->id,
            'full_name'          => 'PACIENTE QUALQUER',
            'date_time'          => now(),
            'cellphone'          => '11999990000',
            'cellphone_whatsapp' => false,
            'situation'          => ScheduleSituation::Scheduled->value,
            'active'             => true,
        ]);

        makeSchedule($this->ctx); // decoy with random doctor name

        $response = $this->getJson(
            '/api/integrators/v1/schedules?search=antonio',
            $this->ctx['headers'],
        )->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('returns only today\'s schedules by default', function () {
        makeSchedule($this->ctx);
        makeSchedule($this->ctx, ['date_time' => now()->addDays(3)]);

        $response = $this->getJson('/api/integrators/v1/schedules', $this->ctx['headers'])
            ->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('filters schedules by a specific date', function () {
        makeSchedule($this->ctx);
        makeSchedule($this->ctx, ['date_time' => now()->addDays(5)]);
        makeSchedule($this->ctx, ['date_time' => now()->addDays(5)]);

        $targetDate = now()->addDays(5)->toDateString();

        $response = $this->getJson(
            "/api/integrators/v1/schedules?date={$targetDate}",
            $this->ctx['headers'],
        )->assertOk();

        expect($response->json('meta.total'))->toBe(2);
    });

    it('caps per_page at plan limit', function () {
        for ($i = 0; $i < 5; $i++) {
            makeSchedule($this->ctx);
        }

        $response = $this->getJson(
            '/api/integrators/v1/schedules?per_page=999',
            $this->ctx['headers'],
        )->assertOk();

        expect($response->json('meta.per_page'))->toBeLessThanOrEqual(100);
    });

    it('returns 401 without authentication', function () {
        $this->getJson('/api/integrators/v1/schedules')
            ->assertUnauthorized();
    });

    // Plumbing pra escopar a Modality Worklist a um aparelho específico via
    // clinic_resources/schedule_resources. Sem adoção real ainda (nenhuma
    // clínica usa reserva de recursos na tela de agendamento hoje), então o
    // filtro só entra em ação quando o param é explicitamente enviado.
    it('filters schedules by clinic_resource_id via schedule_resources', function () {
        $resource = ClinicResource::create([
            'entity_id' => $this->ctx['entity']->id,
            'name'      => 'Retinógrafo Consultório 1',
            'type'      => 'equipment',
        ]);
        $otherResource = ClinicResource::create([
            'entity_id' => $this->ctx['entity']->id,
            'name'      => 'Retinógrafo Consultório 2',
            'type'      => 'equipment',
        ]);

        $linkedSchedule = makeSchedule($this->ctx);
        linkScheduleToResource($linkedSchedule, $resource);

        $otherLinkedSchedule = makeSchedule($this->ctx);
        linkScheduleToResource($otherLinkedSchedule, $otherResource);

        makeSchedule($this->ctx); // no resource linked at all

        $response = $this->getJson(
            "/api/integrators/v1/schedules?clinic_resource_id={$resource->id}",
            $this->ctx['headers'],
        )->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.id'))->toBe($linkedSchedule->id);
    });

    // Regressão: ausência do param deve produzir resultado byte-a-byte igual
    // ao comportamento de antes desta mudança — tanto agendamentos COM
    // recurso vinculado quanto SEM devem continuar aparecendo juntos.
    it('returns identical results to before when clinic_resource_id is absent (regression)', function () {
        $resource = ClinicResource::create([
            'entity_id' => $this->ctx['entity']->id,
            'name'      => 'Retinógrafo Consultório 1',
            'type'      => 'equipment',
        ]);

        $withResource = makeSchedule($this->ctx);
        linkScheduleToResource($withResource, $resource);
        $withoutResource = makeSchedule($this->ctx);

        $response = $this->getJson('/api/integrators/v1/schedules', $this->ctx['headers'])
            ->assertOk();

        expect($response->json('meta.total'))->toBe(2);

        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toContain($withResource->id)
            ->and($ids)->toContain($withoutResource->id);
    });
});

describe('GET /api/integrators/v1/schedules/{id}', function () {
    beforeEach(function () {
        $this->ctx      = setupIntegrator();
        $this->schedule = makeSchedule($this->ctx);
    });

    it('shows schedule by UUID', function () {
        $this->getJson(
            "/api/integrators/v1/schedules/{$this->schedule->id}",
            $this->ctx['headers'],
        )->assertOk()
            ->assertJsonFragment(['id' => $this->schedule->id]);
    });

    it('shows schedule by code', function () {
        $this->getJson(
            "/api/integrators/v1/schedules/{$this->schedule->code}",
            $this->ctx['headers'],
        )->assertOk()
            ->assertJsonFragment(['id' => $this->schedule->id]);
    });

    it('shows schedule by integer number', function () {
        $numericPart = (int) substr($this->schedule->code, 4); // remove 'SDL-'

        $this->getJson(
            "/api/integrators/v1/schedules/{$numericPart}",
            $this->ctx['headers'],
        )->assertOk()
            ->assertJsonFragment(['id' => $this->schedule->id]);
    });

    it('returns 404 for non-existent schedule', function () {
        $this->getJson(
            '/api/integrators/v1/schedules/SDL-NAOEXISTE',
            $this->ctx['headers'],
        )->assertNotFound();
    });

    it('returns 404 for schedule from another entity', function () {
        $other    = setupIntegrator();
        $schedule = makeSchedule($other);

        $this->getJson(
            "/api/integrators/v1/schedules/{$schedule->id}",
            $this->ctx['headers'],
        )->assertNotFound();
    });

    it('returns 401 without authentication', function () {
        $this->getJson("/api/integrators/v1/schedules/{$this->schedule->id}")
            ->assertUnauthorized();
    });
});
