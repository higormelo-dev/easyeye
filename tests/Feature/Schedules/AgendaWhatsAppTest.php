<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{Entity, Patient, People, Schedule, User};
use App\Models\WhatsApp\WhatsAppSetting;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\PatientAccess;
use Carbon\Carbon;
use Illuminate\Support\Facades\{Cache, DB};
use Inertia\Testing\AssertableInertia;

/*
 * Fase 1 / A6 — WhatsApp na linha da agenda: ícone que abre a conversa
 * (https://wa.me/55…, número normalizado) quando há celular; desabilitado
 * com o motivo quando o paciente respondeu SAIR ou não tem celular. Só para
 * perfis com acesso a Pacientes (o servidor nem manda o campo aos demais).
 */
beforeEach(function () {
    config(['whatsapp.driver' => 'mock']);
    Cache::flush();
    $this->travelTo(Carbon::parse('2026-10-06 08:00:00'));

    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->setting = WhatsAppSetting::create([
        'entity_id'      => $this->entity->id, 'app_id' => 'app-awa', 'webhook_token' => WhatsAppSetting::generateWebhookToken(),
        'webhook_secret' => 'segredo-awa', 'active' => true, 'confirmation_enabled' => true, 'confirmation_hours_before' => 24,
        'survey_enabled' => false, 'survey_delay_hours' => 2,
    ]);
    $this->doctor    = createDoctorForEntity($this->entity);
    $this->secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);
});

function awaBook(object $test, string $time, array $extra = []): Schedule
{
    return Schedule::create(array_merge([
        'entity_id' => $test->entity->id,
        'doctor_id' => $test->doctor->id,
        'full_name' => 'PACIENTE ' . $time,
        'date_time' => Carbon::parse("2026-10-06 {$time}"),
        'situation' => ScheduleSituation::Scheduled->value,
        'active'    => true,
    ], $extra));
}

/** @return array<string, array<string, mixed>> linhas da agenda por id */
function awaRows(object $test, $entityUser): array
{
    $rows = [];

    $test->actingAs($entityUser->user)->withSession(panelSession($entityUser))
        ->get(route('panel.schedules.index', ['date' => '2026-10-06']))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$rows) {
            $rows = collect($page->toArray()['props']['scheduleItems'])->keyBy('id')->all();
        });

    return $rows;
}

it('celular do agendamento (ou do cadastro) abre a conversa; SAIR e sem celular ficam desabilitados', function () {
    $ok        = awaBook($this, '09:00', ['cellphone' => '(61) 99999-8888']);
    $oldMobile = awaBook($this, '09:15', ['cellphone' => '6199998877']); // sem o 9º dígito
    $fromCard  = awaBook($this, '09:30', ['patient_id' => Patient::factory()->create([
        'entity_id' => $this->entity->id,
        'person_id' => People::factory()->create(['cellphone' => '11987654321', 'telephone' => null])->id,
    ])->id]);
    $optedOut = awaBook($this, '09:45', ['cellphone' => '61988887777']);
    $landline = awaBook($this, '10:00', ['cellphone' => null, 'telephone' => '6133334444']);
    $nothing  = awaBook($this, '10:15', ['cellphone' => null]);

    app(WhatsAppService::class)->optOut($this->setting, '5561988887777');

    $rows = awaRows($this, $this->secretary);

    expect($rows[$ok->id])->toMatchArray(['whatsapp_state' => 'ok', 'whatsapp_url' => 'https://wa.me/5561999998888'])
        ->and($rows[$oldMobile->id])->toMatchArray(['whatsapp_state' => 'ok', 'whatsapp_url' => 'https://wa.me/5561999998877'])
        ->and($rows[$fromCard->id])->toMatchArray(['whatsapp_state' => 'ok', 'whatsapp_url' => 'https://wa.me/5511987654321'])
        ->and($rows[$optedOut->id])->toMatchArray(['whatsapp_state' => 'opted_out', 'whatsapp_url' => null])
        ->and($rows[$landline->id])->toMatchArray(['whatsapp_state' => 'no_number', 'whatsapp_url' => null])
        ->and($rows[$nothing->id])->toMatchArray(['whatsapp_state' => 'no_number', 'whatsapp_url' => null]);
});

it('opt-out de outra clínica (outro número de envio) não desabilita aqui', function () {
    $other        = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherSetting = WhatsAppSetting::create([
        'entity_id'      => $other->id, 'app_id' => 'app-outra', 'webhook_token' => WhatsAppSetting::generateWebhookToken(),
        'webhook_secret' => 'segredo-outra', 'active' => true, 'confirmation_enabled' => true, 'confirmation_hours_before' => 24,
        'survey_enabled' => false, 'survey_delay_hours' => 2,
    ]);
    app(WhatsAppService::class)->optOut($otherSetting, '5561999998888');

    $schedule = awaBook($this, '09:00', ['cellphone' => '61999998888']);

    expect(awaRows($this, $this->secretary)[$schedule->id]['whatsapp_state'])->toBe('ok');
});

it('médico (tem acesso a Pacientes) também vê; perfil sem acesso a Pacientes não recebe nada do WhatsApp', function () {
    $schedule = awaBook($this, '09:00', ['cellphone' => '61999998888']);

    expect(awaRows($this, $this->doctor->entityUser)[$schedule->id]['whatsapp_state'])->toBe('ok');

    $this->app->instance(PatientAccess::class, new class() extends PatientAccess {
        public function current(): bool
        {
            return false;
        }
    });

    $row = awaRows($this, $this->secretary)[$schedule->id];

    expect($row)->not->toHaveKey('whatsapp_state')
        ->and($row)->not->toHaveKey('whatsapp_url');

    $this->actingAs($this->secretary->user)->withSession(panelSession($this->secretary))
        ->getJson(route('panel.schedules.show', $schedule))
        ->assertOk()
        ->assertJsonMissingPath('data.whatsapp_url')
        ->assertJsonMissingPath('data.whatsapp_state');
});

it('detalhe do agendamento traz o mesmo link/estado da linha', function () {
    $schedule = awaBook($this, '09:00', ['cellphone' => '61999998888']);

    $show = fn () => $this->actingAs($this->secretary->user)->withSession(panelSession($this->secretary))
        ->getJson(route('panel.schedules.show', $schedule))->assertOk();

    $show()->assertJsonPath('data.whatsapp_state', 'ok')->assertJsonPath('data.whatsapp_url', 'https://wa.me/5561999998888');

    app(WhatsAppService::class)->optOut($this->setting, '5561999998888');

    $show()->assertJsonPath('data.whatsapp_state', 'opted_out')->assertJsonPath('data.whatsapp_url', null);
});

it('uma consulta de descadastro para a agenda inteira (sem N+1)', function () {
    foreach (['09:00', '09:15', '09:30', '09:45', '10:00', '10:15'] as $i => $time) {
        awaBook($this, $time, ['cellphone' => '6199999880' . $i]);
    }

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'whatsapp_opt_outs')) {
            $queries++;
        }
    });

    awaRows($this, $this->secretary);

    expect($queries)->toBe(1);
});
