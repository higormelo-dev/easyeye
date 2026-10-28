<?php

declare(strict_types=1);

use App\Enums\{ClientRule, MedicalSpecialty, ScheduleSituation};
use App\Models\{Doctor, Entity, People, Schedule, User};
use Carbon\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 * Fase 1 / A4 — calendário de semana e mês: a consulta fica limitada ao
 * período visível (semana de domingo a sábado, mês inteiro), com os mesmos
 * filtros e escopo da lista e a linha compacta (cor por situação, encaixe,
 * sessão) — o clique abre o detalhe.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00')); // quarta

    $this->entity                              = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->secretary                           = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);
    [$this->anaUser, $this->anaEu, $this->ana] = acrDoctor($this->entity, 'DRA. ANA');
    [, , $this->bruno]                         = acrDoctor($this->entity, 'DR. BRUNO');
});

function acrDoctor(Entity $entity, string $name): array
{
    $user = User::factory()->create(['name' => $name]);
    $eu   = createEntityUser($entity, $user, ClientRule::Doctor->value);

    return [$user, $eu, Doctor::create(['entity_user_id' => $eu->id, 'person_id' => People::factory()->create()->id, 'active' => true])];
}

function acrBook(Doctor $doctor, string $at, array $extra = []): Schedule
{
    return Schedule::create(array_merge([
        'entity_id' => $doctor->entityUser->entity_id,
        'doctor_id' => $doctor->id,
        'full_name' => 'PACIENTE ' . $at,
        'date_time' => Carbon::parse($at),
        'situation' => ScheduleSituation::Scheduled->value,
        'active'    => true,
    ], $extra));
}

function acrProps(object $test, $entityUser, array $query): array
{
    $props = null;

    $test->actingAs($entityUser->user)->withSession(panelSession($entityUser))
        ->get(route('panel.schedules.index', $query))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    return $props;
}

it('semana: só de domingo a sábado da data escolhida, com o período e a contagem do período', function () {
    acrBook($this->ana, '2026-10-10 09:00'); // sábado anterior
    $sun = acrBook($this->ana, '2026-10-11 09:00');
    $wed = acrBook($this->bruno, '2026-10-14 15:00');
    $sat = acrBook($this->ana, '2026-10-17 18:30');
    acrBook($this->ana, '2026-10-18 08:00'); // domingo seguinte

    $props = acrProps($this, $this->secretary, ['date' => '2026-10-14', 'range' => 'week']);

    expect(collect($props['scheduleItems'])->pluck('id')->sort()->values()->all())->toBe(collect([$sun->id, $wed->id, $sat->id])->sort()->values()->all())
        ->and($props['filters'])->toMatchArray(['range' => 'week', 'from' => '2026-10-11', 'to' => '2026-10-17'])
        ->and($props['counters']['total'])->toBe(3);
});

it('mês: o mês inteiro da data, linha compacta com cor, encaixe e sessão', function () {
    acrBook($this->ana, '2026-09-30 09:00');
    $first = acrBook($this->ana, '2026-10-01 08:00', ['is_fit_in' => true, 'series_id' => (string) Str::uuid(), 'session_number' => 2, 'session_total' => 5]);
    $last  = acrBook($this->bruno, '2026-10-31 17:00', ['situation' => ScheduleSituation::Confirmed->value]);
    acrBook($this->ana, '2026-11-01 08:00');

    $props = acrProps($this, $this->secretary, ['date' => '2026-10-14', 'range' => 'month']);
    $rows  = collect($props['scheduleItems']);

    expect($rows->pluck('id')->all())->toBe([$first->id, $last->id])
        ->and($props['filters'])->toMatchArray(['from' => '2026-10-01', 'to' => '2026-10-31'])
        ->and($rows->first())->toMatchArray([
            'type'           => 'schedule',
            'date'           => '2026-10-01',
            'time'           => '08:00',
            'is_fit_in'      => true,
            'session_number' => 2,
            'session_total'  => 5,
            'doctor_name'    => 'DRA. ANA',
        ])
        ->and($rows->last()['badge'])->toBe(ScheduleSituation::Confirmed->badgeClass())
        // Linha compacta: nada de caixa/URLs/telefone por linha no mês.
        ->and($rows->first())->not->toHaveKeys(['cash_entry_url', 'whatsapp_url', 'notes']);
});

it('semana e mês usam os mesmos filtros (chip, especialidade) e o escopo do médico', function () {
    $anaGlaucoma = acrBook($this->ana, '2026-10-12 09:00', ['specialty_area' => MedicalSpecialty::Glaucoma->value]);
    $anaRetina   = acrBook($this->ana, '2026-10-13 09:00', ['specialty_area' => MedicalSpecialty::RetinaVitreous->value, 'situation' => ScheduleSituation::Confirmed->value]);
    acrBook($this->bruno, '2026-10-13 10:00', ['specialty_area' => MedicalSpecialty::Glaucoma->value]);

    $glaucoma = acrProps($this, $this->secretary, ['date' => '2026-10-14', 'range' => 'month', 'specialty' => MedicalSpecialty::Glaucoma->value]);
    expect(collect($glaucoma['scheduleItems'])->pluck('doctor_name')->sort()->values()->all())->toBe(['DR. BRUNO', 'DRA. ANA']);

    $confirmed = acrProps($this, $this->secretary, ['date' => '2026-10-14', 'range' => 'week', 'status' => 'confirmed']);
    expect(collect($confirmed['scheduleItems'])->pluck('id')->all())->toBe([$anaRetina->id]);

    // Médico: só o dele, mesmo pedindo o colega na URL.
    $doctorWeek = acrProps($this, $this->anaEu, ['date' => '2026-10-14', 'range' => 'week', 'doctor' => $this->bruno->id]);
    expect(collect($doctorWeek['scheduleItems'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$anaGlaucoma->id, $anaRetina->id])->sort()->values()->all());
});

it('período desconhecido na URL vira "dia"', function () {
    acrBook($this->ana, '2026-10-14 09:00');
    acrBook($this->ana, '2026-10-15 09:00');

    $props = acrProps($this, $this->secretary, ['date' => '2026-10-14', 'range' => 'year']);

    expect($props['filters']['range'])->toBe('day')
        ->and($props['scheduleItems'])->toHaveCount(1)
        // Lista do dia: linha completa (caixa/URLs).
        ->and($props['scheduleItems'][0])->toHaveKeys(['show_url', 'situation_url', 'is_fit_in', 'resource_names']);
});
