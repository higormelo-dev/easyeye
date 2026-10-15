<?php

declare(strict_types=1);

/**
 * Agenda cirúrgica com DUAS conexões PostgreSQL de verdade (ADR 0003, D3/§5):
 * duas secretárias disputando a MESMA sala/horário terminam com exatamente UMA
 * cirurgia — sem deadlock (40P01), sem as duas passarem. O filho agenda pelo
 * SurgeryScheduleService segurando a transação depois de tomar todos os locks
 * (recursos em ordem crescente + linha do caso); o principal agenda pelo HTTP
 * de verdade. Fica em tests/Integration (DatabaseMigrations, dados comitados) e
 * roda por caminho explícito.
 */

use App\Enums\{ClientRule, FeatureKey, ScheduleSituation, SubscriptionStatus};
use App\Enums\Surgery\SurgicalCaseStatus;
use App\Models\{Covenant, Entity, Patient, Plan, PlanFeature, Subscription, User};
use App\Models\Schedule;
use App\Models\Surgery\{SurgeryFacility, SurgeryRoom, SurgicalCase};
use App\Services\Surgery\{SurgeryAuthorizationService, SurgeryScheduleService, SurgicalCaseService};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function ssxChildEnv(): array
{
    $db = config('database.connections.' . config('database.default'));

    return [
        'APP_ENV'          => 'testing',
        'CACHE_STORE'      => 'array',
        'SESSION_DRIVER'   => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'DB_CONNECTION'    => config('database.default'),
        'DB_HOST'          => (string) ($db['host'] ?? ''),
        'DB_PORT'          => (string) ($db['port'] ?? ''),
        'DB_DATABASE'      => (string) ($db['database'] ?? ''),
        'DB_USERNAME'      => (string) ($db['username'] ?? ''),
        'DB_PASSWORD'      => (string) ($db['password'] ?? ''),
    ];
}

/**
 * Clínica com o módulo, uma secretária, hospital com 2 salas e 4 casos
 * autorizados (cirurgiões e pacientes distintos) prontos para agendar.
 *
 * @return array<string, mixed>
 */
function ssxScenario(): array
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    $plan = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasSurgery)->for($plan)->create();
    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    $secretary = createEntityUser($entity, User::factory()->create(), ClientRule::Secretary->value);
    $covenant  = Covenant::factory()->create(['entity_id' => null, 'name' => 'PARTICULAR', 'ans_registry' => null, 'active' => true]);

    $facility = SurgeryFacility::query()->create(['entity_id' => $entity->id, 'name' => 'Hospital', 'type' => 'hospital', 'active' => true]);
    $room1    = SurgeryRoom::query()->create(['entity_id' => $entity->id, 'surgery_facility_id' => $facility->id, 'name' => 'Sala 1', 'active' => true]);
    $room2    = SurgeryRoom::query()->create(['entity_id' => $entity->id, 'surgery_facility_id' => $facility->id, 'name' => 'Sala 2', 'active' => true]);

    $cases = [];

    foreach (['A', 'B', 'C', 'D'] as $label) {
        $surgeon = createDoctorForEntity($entity);
        $patient = Patient::factory()->create(['entity_id' => $entity->id, 'covenant_id' => $covenant->id, 'card_number' => null, 'active' => true]);

        $case = app(SurgicalCaseService::class)->create((string) $entity->id, [
            'patient_id' => (string) $patient->id, 'surgeon_doctor_id' => (string) $surgeon->id, 'title' => "Caso {$label}", 'eye' => 'od',
            'procedures' => [['description' => 'Facoemulsificação']],
        ]);
        app(SurgeryAuthorizationService::class)->request($case);
        app(SurgeryAuthorizationService::class)->record($case, ['type' => 'waived', 'note' => 'Particular']);

        $cases[$label] = $case->refresh();
    }

    return compact('entity', 'secretary', 'facility', 'room1', 'room2', 'cases');
}

/** Sobe o filho, espera ele segurar as travas e devolve o processo. */
function ssxStartChild(SurgicalCase $case, string $action, string $startsAt, SurgeryFacility $facility, ?SurgeryRoom $room, int $duration, string $marker): object
{
    return Process::path(base_path())
        ->env(ssxChildEnv())
        ->timeout(90)
        ->start([
            PHP_BINARY, 'tests/Integration/Support/surgery_schedule_child.php',
            (string) $case->id, $action, $startsAt, (string) $facility->id, $room?->id ?? '-', (string) $duration, $marker, '2.5',
        ]);
}

function ssxWaitHolding(object $child, string $marker): void
{
    $deadline = microtime(true) + 40;

    while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
        usleep(50_000);
    }

    expect(is_file($marker))->toBeTrue("o filho não chegou à pausa — stdout: {$child->output()} | stderr: {$child->errorOutput()}");
}

function ssxPayload(SurgeryFacility $facility, SurgeryRoom $room, string $startsAt, int $duration = 60): array
{
    return [
        'starts_at'           => $startsAt, 'duration_minutes' => $duration,
        'surgery_facility_id' => (string) $facility->id, 'surgery_room_id' => (string) $room->id,
    ];
}

beforeEach(function () {
    $this->day = Carbon::now()->addDays(3)->format('Y-m-d');
});

it('[CONCORRÊNCIA] duas secretárias na MESMA sala e horário: exatamente uma vence, a outra recebe 409 de sala', function () {
    ['secretary' => $secretary, 'facility' => $facility, 'room1' => $room1, 'cases' => $cases] = ssxScenario();

    $marker = sys_get_temp_dir() . '/ssx-' . Str::uuid();
    $child  = ssxStartChild($cases['A'], 'schedule', "{$this->day}T09:00", $facility, $room1, 60, $marker);

    try {
        ssxWaitHolding($child, $marker);

        DB::statement("set statement_timeout = '25s'");

        // Sobrepõe a janela do filho (que ainda não comitou) na mesma sala.
        $response = $this->actingAs($secretary->user)->withSession(panelSession($secretary))
            ->postJson(route('panel.surgery.schedule.store', $cases['B']), ssxPayload($facility, $room1, "{$this->day}T09:30"));

        $result = $child->wait();
    } finally {
        @unlink($marker);
    }

    expect($result->errorOutput())->not->toContain('40P01')
        ->and($result->successful())->toBeTrue("filho falhou — stderr: {$result->errorOutput()}")
        ->and(trim($result->output()))->toBe('OK')
        ->and($response->getStatusCode())->toBe(409)
        ->and($response->json('context.conflicts.0.kind'))->toBe('room');

    expect(SurgicalCase::query()->withoutGlobalScopes()->where('status', SurgicalCaseStatus::Scheduled->value)->count())->toBe(1)
        ->and($cases['A']->fresh()->status)->toBe(SurgicalCaseStatus::Scheduled)
        ->and($cases['B']->fresh()->status)->toBe(SurgicalCaseStatus::Authorized);
});

it('[CONCORRÊNCIA] mesma sala em horários que não se sobrepõem: as duas passam, uma depois da outra, sem deadlock', function () {
    ['secretary' => $secretary, 'facility' => $facility, 'room1' => $room1, 'cases' => $cases] = ssxScenario();

    $marker = sys_get_temp_dir() . '/ssx-' . Str::uuid();
    $child  = ssxStartChild($cases['A'], 'schedule', "{$this->day}T09:00", $facility, $room1, 60, $marker);

    try {
        ssxWaitHolding($child, $marker);

        DB::statement("set statement_timeout = '25s'");

        // 10:00 encosta no fim da janela do filho: livre. Espera a trava da sala e passa.
        $response = $this->actingAs($secretary->user)->withSession(panelSession($secretary))
            ->postJson(route('panel.surgery.schedule.store', $cases['B']), ssxPayload($facility, $room1, "{$this->day}T10:00"));

        $result = $child->wait();
    } finally {
        @unlink($marker);
    }

    expect($result->errorOutput())->not->toContain('40P01')
        ->and(trim($result->output()))->toBe('OK')
        ->and($response->getStatusCode())->toBe(200);

    expect(SurgicalCase::query()->withoutGlobalScopes()->where('status', SurgicalCaseStatus::Scheduled->value)->count())->toBe(2);
});

it('[CONCORRÊNCIA] o mesmo CIRURGIÃO em salas diferentes e no mesmo horário: uma vence só', function () {
    ['secretary' => $secretary, 'facility' => $facility, 'room1' => $room1, 'room2' => $room2, 'cases' => $cases] = ssxScenario();

    // B passa a ter o mesmo cirurgião de A.
    $cases['B']->forceFill(['surgeon_doctor_id' => $cases['A']->surgeon_doctor_id])->save();

    $marker = sys_get_temp_dir() . '/ssx-' . Str::uuid();
    $child  = ssxStartChild($cases['A'], 'schedule', "{$this->day}T09:00", $facility, $room1, 60, $marker);

    try {
        ssxWaitHolding($child, $marker);

        DB::statement("set statement_timeout = '25s'");

        $response = $this->actingAs($secretary->user)->withSession(panelSession($secretary))
            ->postJson(route('panel.surgery.schedule.store', $cases['B']), ssxPayload($facility, $room2, "{$this->day}T09:30"));

        $result = $child->wait();
    } finally {
        @unlink($marker);
    }

    expect($result->errorOutput())->not->toContain('40P01')
        ->and(trim($result->output()))->toBe('OK')
        ->and($response->getStatusCode())->toBe(409)
        ->and($response->json('context.conflicts.0.kind'))->toBe('surgeon');

    expect(SurgicalCase::query()->withoutGlobalScopes()->where('status', SurgicalCaseStatus::Scheduled->value)->count())->toBe(1);
});

it('[CONCORRÊNCIA] reagendar X→Y × Y→X de dois casos: nenhum deadlock; cada lado vê a janela do outro', function () {
    ['secretary' => $secretary, 'facility' => $facility, 'room1' => $room1, 'room2' => $room2, 'cases' => $cases] = ssxScenario();

    // A na sala 1 às 09:00; B na sala 2 às 11:00. Cada um tenta ir para a janela do outro.
    $service = app(SurgeryScheduleService::class);
    $service->schedule($cases['A'], ssxPayload($facility, $room1, "{$this->day}T09:00"));
    $service->schedule($cases['B'], ssxPayload($facility, $room2, "{$this->day}T11:00"));

    $marker = sys_get_temp_dir() . '/ssx-' . Str::uuid();
    $child  = ssxStartChild($cases['A']->fresh(), 'reschedule', "{$this->day}T11:00", $facility, $room2, 60, $marker);

    try {
        ssxWaitHolding($child, $marker);

        DB::statement("set statement_timeout = '25s'");

        $response = $this->actingAs($secretary->user)->withSession(panelSession($secretary))
            ->putJson(route('panel.surgery.schedule.update', $cases['B']->fresh()), ssxPayload($facility, $room1, "{$this->day}T09:00"));

        $result = $child->wait();
    } finally {
        @unlink($marker);
    }

    // Os dois recusam (cada janela de destino ainda está ocupada pelo outro): 409, nunca 500/40P01.
    expect($result->errorOutput())->not->toContain('40P01')
        ->and($result->successful())->toBeTrue("filho falhou — stderr: {$result->errorOutput()}")
        ->and(trim($result->output()))->toStartWith('RULE:409:room')
        ->and($response->getStatusCode())->toBe(409);

    expect($cases['A']->fresh()->scheduled_start->format('H:i'))->toBe('09:00')
        ->and($cases['B']->fresh()->scheduled_start->format('H:i'))->toBe('11:00');
});

it('[CONCORRÊNCIA] reagendar A para uma janela livre enquanto B agenda na antiga: ambos passam em ordem', function () {
    ['secretary' => $secretary, 'facility' => $facility, 'room1' => $room1, 'cases' => $cases] = ssxScenario();

    $service = app(SurgeryScheduleService::class);
    $service->schedule($cases['A'], ssxPayload($facility, $room1, "{$this->day}T09:00"));

    $marker = sys_get_temp_dir() . '/ssx-' . Str::uuid();
    // O filho tira A da janela das 09:00 (vai para 14:00) e segura a transação.
    $child = ssxStartChild($cases['A']->fresh(), 'reschedule', "{$this->day}T14:00", $facility, $room1, 60, $marker);

    try {
        ssxWaitHolding($child, $marker);

        DB::statement("set statement_timeout = '25s'");

        // B pede a janela das 09:00: espera o commit do filho (a sala 1 é a mesma trava) e então a vê livre.
        $response = $this->actingAs($secretary->user)->withSession(panelSession($secretary))
            ->postJson(route('panel.surgery.schedule.store', $cases['B']), ssxPayload($facility, $room1, "{$this->day}T09:00"));

        $result = $child->wait();
    } finally {
        @unlink($marker);
    }

    expect($result->errorOutput())->not->toContain('40P01')
        ->and(trim($result->output()))->toBe('OK')
        ->and($response->getStatusCode())->toBe(200)
        ->and($cases['A']->fresh()->scheduled_start->format('H:i'))->toBe('14:00')
        ->and($cases['B']->fresh()->scheduled_start->format('H:i'))->toBe('09:00');
});

it('[CONCORRÊNCIA] consulta e cirurgia do mesmo médico no mesmo horário: a consulta que passou na validação é barrada sob a trava', function () {
    ['secretary' => $secretary, 'facility' => $facility, 'room1' => $room1, 'cases' => $cases] = ssxScenario();

    $surgeon = $cases['A']->surgeon;
    $surgeon->update(['schedule_interval' => 30]);

    $marker = sys_get_temp_dir() . '/ssx-' . Str::uuid();
    $child  = ssxStartChild($cases['A'], 'schedule', "{$this->day}T09:00", $facility, $room1, 60, $marker);

    try {
        ssxWaitHolding($child, $marker);

        DB::statement("set statement_timeout = '25s'");

        // O filho ainda não comitou: a validação antecipada (fora da transação) não vê a cirurgia e
        // deixa passar; quem barra é a releitura sob a trava da linha do médico, depois do commit dele.
        $response = $this->actingAs($secretary->user)->withSession(panelSession($secretary))
            ->postJson(route('panel.schedules.store'), [
                'doctor_id' => (string) $surgeon->id, 'full_name' => 'PACIENTE CONSULTA', 'date_time' => "{$this->day} 09:30",
            ]);

        $result = $child->wait();
    } finally {
        @unlink($marker);
    }

    expect($result->errorOutput())->not->toContain('40P01')
        ->and(trim($result->output()))->toBe('OK')
        ->and($response->getStatusCode())->toBe(422);

    expect(Schedule::query()->withoutGlobalScopes()->where('doctor_id', $surgeon->id)->count())->toBe(0)
        ->and($cases['A']->fresh()->status)->toBe(SurgicalCaseStatus::Scheduled);
});

it('[CONCORRÊNCIA] reativar consulta cancelada × cirurgia do mesmo médico no mesmo horário: a reativação espera a cirurgia e é barrada', function () {
    ['secretary' => $secretary, 'entity' => $entity, 'facility' => $facility, 'room1' => $room1, 'cases' => $cases] = ssxScenario();

    $surgeon = $cases['A']->surgeon;
    $surgeon->update(['schedule_interval' => 30]);

    // Cancelada antes da cirurgia: o horário estava livre quando a cirurgia foi pedida.
    $consult = Schedule::query()->create([
        'entity_id' => $entity->id, 'doctor_id' => $surgeon->id, 'full_name' => 'PACIENTE CONSULTA',
        'date_time' => "{$this->day} 09:30:00", 'situation' => ScheduleSituation::Cancelled->value, 'active' => true,
    ]);

    $marker = sys_get_temp_dir() . '/ssx-' . Str::uuid();
    $child  = ssxStartChild($cases['A'], 'schedule', "{$this->day}T09:00", $facility, $room1, 60, $marker);

    try {
        ssxWaitHolding($child, $marker);

        DB::statement("set statement_timeout = '25s'");

        // O filho ainda não comitou. Sem a trava das janelas cirúrgicas a reativação passaria
        // agora, antes da cirurgia aparecer; com ela, espera o commit do filho e enxerga a cirurgia.
        $response = $this->actingAs($secretary->user)->withSession(panelSession($secretary))
            ->patchJson(route('panel.schedules.situation', $consult), ['situation' => ScheduleSituation::Scheduled->value]);

        $result = $child->wait();
    } finally {
        @unlink($marker);
    }

    expect($result->errorOutput())->not->toContain('40P01')
        ->and(trim($result->output()))->toBe('OK')
        ->and($response->getStatusCode())->toBe(422)
        ->and($cases['A']->fresh()->status)->toBe(SurgicalCaseStatus::Scheduled);

    expect(Schedule::query()->withoutGlobalScopes()->whereKey($consult->id)->value('situation'))->toBe(ScheduleSituation::Cancelled);
});

it('[CONCORRÊNCIA] remarcação em lote × cirurgia do mesmo médico: a consulta que passou na validação é barrada sob a trava', function () {
    ['secretary' => $secretary, 'entity' => $entity, 'facility' => $facility, 'room1' => $room1, 'cases' => $cases] = ssxScenario();

    $surgeon = $cases['A']->surgeon;
    $surgeon->update(['schedule_interval' => 30]);

    $nextWeek = Carbon::parse($this->day)->addDays(7)->format('Y-m-d');
    $consult  = Schedule::query()->create([
        'entity_id' => $entity->id, 'doctor_id' => $surgeon->id, 'full_name' => 'PACIENTE CONSULTA',
        'date_time' => "{$nextWeek} 09:30:00", 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
    ]);

    $marker = sys_get_temp_dir() . '/ssx-' . Str::uuid();
    $child  = ssxStartChild($cases['A'], 'schedule', "{$this->day}T09:00", $facility, $room1, 60, $marker);

    try {
        ssxWaitHolding($child, $marker);

        DB::statement("set statement_timeout = '25s'");

        // A validação (sem trava) não vê a cirurgia do filho; a reconferência dentro da transação, sim.
        $response = $this->actingAs($secretary->user)->withSession(panelSession($secretary))
            ->postJson(route('panel.schedules.bulk-reschedule'), ['ids' => [(string) $consult->id], 'date' => $this->day]);

        $result = $child->wait();
    } finally {
        @unlink($marker);
    }

    expect($result->errorOutput())->not->toContain('40P01')
        ->and(trim($result->output()))->toBe('OK')
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->json('updated'))->toBe(0)
        ->and($response->json('skipped'))->toBe(1)
        ->and($response->json('blocked.0.message'))->toContain('cirurgia agendada')
        ->and($cases['A']->fresh()->status)->toBe(SurgicalCaseStatus::Scheduled);

    expect(Schedule::query()->withoutGlobalScopes()->whereKey($consult->id)->first()->date_time->format('Y-m-d H:i'))->toBe("{$nextWeek} 09:30");
});

it('[CONCORRÊNCIA] duas consultas do mesmo médico não se bloqueiam entre si (trava compartilhada)', function () {
    ['secretary' => $secretary, 'cases' => $cases] = ssxScenario();

    $surgeon = $cases['A']->surgeon;

    DB::beginTransaction();
    app(SurgeryScheduleService::class)->lockDoctorWindows((string) $surgeon->id);

    // Outra sessão pega a mesma trava compartilhada sem esperar.
    $other = Process::path(base_path())->env(ssxChildEnv())->timeout(30)->run([
        PHP_BINARY, '-r',
        'require "vendor/autoload.php"; $a = require "bootstrap/app.php"; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
        . ' Illuminate\\Support\\Facades\\DB::statement("set statement_timeout = 3000");'
        . ' Illuminate\\Support\\Facades\\DB::transaction(fn () => app(App\\Services\\Surgery\\SurgeryScheduleService::class)->lockDoctorWindows("' . $surgeon->id . '"));'
        . ' echo "OK";',
    ]);

    DB::rollBack();

    expect(trim($other->output()))->toBe('OK')->and($other->errorOutput())->not->toContain('canceling statement');
});
