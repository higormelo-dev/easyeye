<?php

/**
 * Situação "Retornando à consulta" (ScheduleSituation::ReturningToDoctor = 10)
 * — equivale ao "EM ESPERA" do sistema de origem: o paciente fez exames/
 * dilatação e aguarda voltar ao médico.
 */

use App\Enums\ScheduleSituation;
use App\Models\Entity;
use App\Services\ScheduleService;
use Illuminate\Support\Facades\App;

it('todo case tem braço em label/badgeClass/icon/circleClass e rótulo traduzido nas duas línguas', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        App::setLocale($locale);

        foreach (ScheduleSituation::cases() as $case) {
            expect($case->label())->not->toStartWith('actions.')
                ->and($case->badgeClass())->not->toBeEmpty()
                ->and($case->icon())->not->toBeEmpty()
                ->and($case->circleClass())->not->toBeEmpty();
        }
    }
});

it('ReturningToDoctor vale 10, não é terminal e aparece entre Em exame e Em consulta', function () {
    expect(ScheduleSituation::ReturningToDoctor->value)->toBe(10)
        ->and(ScheduleSituation::ReturningToDoctor->isTerminal())->toBeFalse();

    $order = array_map(fn ($c) => $c->value, ScheduleSituation::cases());
    expect($order)->toBe([1, 2, 3, 4, 5, 10, 6, 7, 8, 9]);
});

it('ir pra Retornando preenche arrived_at só se estiver vazio (nunca reseta a chegada)', function () {
    $entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $arrived = now()->subHour()->startOfSecond();

    ['schedule' => $withArrival] = createScheduleForEntity($entity, [
        'situation' => ScheduleSituation::Exam->value, 'arrived_at' => $arrived,
    ]);
    ['schedule' => $noArrival] = createScheduleForEntity($entity, [
        'situation' => ScheduleSituation::Scheduled->value, 'date_time' => now()->addMinutes(30),
    ]);

    $service = app(ScheduleService::class);
    $service->changeSituation($withArrival, ScheduleSituation::ReturningToDoctor, null);
    $service->changeSituation($noArrival, ScheduleSituation::ReturningToDoctor, null);

    expect($withArrival->fresh()->situation)->toBe(ScheduleSituation::ReturningToDoctor)
        ->and($withArrival->fresh()->arrived_at->equalTo($arrived))->toBeTrue()
        ->and($noArrival->fresh()->arrived_at)->not->toBeNull();
});
