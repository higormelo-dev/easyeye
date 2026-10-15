<?php

declare(strict_types=1);

use App\Enums\Surgery\{PostOpReturnStatus, SurgicalCaseStatus};
use App\Models\{DoctorWorkSchedule, Schedule};
use App\Models\Surgery\{SurgicalCase, SurgicalPostopEvaluation, SurgicalPostopReturn};
use App\Services\{ScheduleSeriesService, ScheduleService};
use App\Services\Surgery\PostOp\PostOpReturnService;
use App\Services\Surgery\{SurgeryIndicatorsService, SurgicalCaseService};
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Log};

/*
 * Fase 4 / M5 — correções da revisão adversarial: fuso (UTC da tela), escritas
 * condicionadas à reivindicação, retorno urgente que não derruba a finalização
 * e suspensões contadas pelo histórico do caso.
 */

function srfCase(object $w): SurgicalCase
{
    $case = app(SurgicalCaseService::class)->create((string) $w->entity->id, [
        'patient_id' => (string) $w->patient->id, 'surgeon_doctor_id' => (string) $w->doctor->id,
        'title'      => 'Facoemulsificação', 'eye' => 'od', 'procedures' => [['description' => 'Faco']],
    ]);
    $case->forceFill(['status' => SurgicalCaseStatus::Performed, 'performed_at' => now()])->save();

    return $case->refresh();
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $this->w = surgeryWorld();

    foreach ([1, 2, 3, 4, 5] as $day) {
        DoctorWorkSchedule::query()->create(['doctor_id' => $this->w->doctor->id, 'day_of_week' => $day, 'starts_at' => '08:00:00', 'ends_at' => '10:00:00']);
    }

    $this->w->doctor->update(['schedule_interval' => 30]);
    $this->case = srfCase($this->w);
});

it('data/hora ISO em UTC vinda da tela é gravada no fuso da aplicação (não 3 h adiantada)', function () {
    expect(config('app.timezone'))->toBe('America/Sao_Paulo');

    // 09:30 em São Paulo = 12:30Z; "agora" é 10:00 local (13:00Z) → NÃO é futuro.
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.evaluations.store', $this->case), [
        'milestone' => 'd1', 'eye' => 'od', 'evaluated_at' => '2026-10-15T12:30:00.000Z', 'iop_mmhg' => 14,
    ])->assertCreated();

    expect(DB::table('surgical_postop_evaluations')->value('evaluated_at'))->toStartWith('2026-10-15 09:30:00');

    // 13:30Z = 10:30 local, 30 min à frente (> 5 min de tolerância): recusado como futuro.
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.evaluations.store', $this->case), [
        'milestone' => 'd7', 'eye' => 'od', 'evaluated_at' => '2026-10-15T13:30:00.000Z', 'iop_mmhg' => 14,
    ])->assertStatus(422);
});

it('falha de escrita depois da reivindicação: worker que perdeu o claim não sobrescreve nada', function () {
    DoctorWorkSchedule::query()->delete(); // sem horário → o worker terminaria em "failed/no_slot"
    SurgicalPostopReturn::query()->create([
        'entity_id'   => $this->case->entity_id, 'surgical_case_id' => $this->case->id, 'milestone' => 'd1',
        'offset_days' => 1, 'target_date' => '2026-10-16', 'status' => 'pending',
    ]);

    // Outro worker (lease vencido) assume o retorno logo depois da reivindicação deste.
    $taken = false;
    DB::listen(function ($query) use (&$taken): void {
        if (! $taken && str_contains($query->sql, 'update "surgical_postop_returns"') && str_contains($query->sql, '"claim_token"')) {
            $taken = true;
            DB::table('surgical_postop_returns')->update(['claim_token' => 'outro-worker']);
        }
    });

    app(PostOpReturnService::class)->plan((string) $this->case->id);

    $row = SurgicalPostopReturn::query()->where('milestone', 'd1')->firstOrFail();

    expect($taken)->toBeTrue()
        ->and($row->status)->toBe(PostOpReturnStatus::Scheduling)
        ->and($row->claim_token)->toBe('outro-worker')
        ->and($row->failure_code)->toBeNull();
});

it('cancelOpen não grava "cancelado" por cima de retorno que acabou de ser agendado (compare-and-set)', function () {
    app(PostOpReturnService::class)->plan((string) $this->case->id);

    $scheduled     = SurgicalPostopReturn::query()->where('milestone', 'd1')->firstOrFail();
    $stale         = $scheduled->replicate()->forceFill(['id' => $scheduled->id, 'status' => PostOpReturnStatus::Pending, 'schedule_id' => null]);
    $stale->exists = true;

    // Leitura velha: o cancelamento achava que ainda estava pendente.
    $method = new ReflectionMethod(PostOpReturnService::class, 'cancelOne');
    $method->setAccessible(true);
    $result = $method->invoke(app(PostOpReturnService::class), $stale, 'Alta dada');

    expect($result)->toBeTrue()
        ->and($scheduled->refresh()->status)->toBe(PostOpReturnStatus::Cancelled)
        // O agendamento criado no meio-tempo também foi cancelado — não ficou aberto com o retorno "cancelado".
        ->and(Schedule::query()->find($scheduled->getOriginal('schedule_id') ?? Schedule::query()->where('surgical_case_id', $this->case->id)->orderBy('date_time')->value('id'))->situation->value)->toBe(9);
});

it('falha ao pedir o retorno urgente não derruba a finalização; "tentar de novo" cria o retorno uma vez', function () {
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.evaluations.store', $this->case), [
        'milestone' => 'd1', 'eye' => 'od', 'evaluated_at' => '2026-10-15 09:30:00', 'iop_mmhg' => 14, 'findings' => ['endophthalmitis_suspected'],
    ])->assertCreated();
    $e = SurgicalPostopEvaluation::query()->firstOrFail();

    Log::spy();
    app()->instance(PostOpReturnService::class, new class(app(ScheduleService::class), app(ScheduleSeriesService::class)) extends PostOpReturnService {
        public function requestUrgent(SurgicalPostopEvaluation $evaluation): ?SurgicalPostopReturn
        {
            throw new RuntimeException('falha simulada');
        }
    });

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.evaluations.finalize', [$this->case, $e]), [
        'milestone' => 'd1', 'eye' => 'od', 'evaluated_at' => '2026-10-15 09:30:00', 'iop_mmhg' => 14, 'findings' => ['endophthalmitis_suspected'],
    ])->assertOk()->assertJsonPath('severe', true);

    Log::shouldHaveReceived('warning')->with('surgery.postop_urgent_return_failed', Mockery::on(fn ($ctx) => ! isset($ctx['message'])))->once();

    expect($e->refresh()->isFinalized())->toBeTrue()
        ->and(SurgicalPostopReturn::query()->where('milestone', 'urgent')->count())->toBe(0);

    // Serviço saudável de novo: o "tentar de novo" da aba pede o urgente, uma única vez.
    app()->forgetInstance(PostOpReturnService::class);
    app()->offsetUnset(PostOpReturnService::class);

    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.postop.returns.retry', $this->case))->assertOk();
    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.postop.returns.retry', $this->case))->assertOk();

    expect(SurgicalPostopReturn::query()->where('milestone', 'urgent')->count())->toBe(1);
});

it('suspensões contam pelo histórico do caso, inclusive as já retomadas', function () {
    $service = app(SurgicalCaseService::class);
    $other   = $service->create((string) $this->w->entity->id, [
        'patient_id' => (string) $this->w->patient->id, 'surgeon_doctor_id' => (string) $this->w->doctor->id,
        'title'      => 'Outra', 'eye' => 'od', 'procedures' => [['description' => 'Faco']],
    ]);

    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.suspend', $other), ['reason' => 'Febre'])->assertRedirect();
    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.resume', $other))->assertRedirect();
    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.suspend', $other), ['reason' => 'Febre'])->assertRedirect();

    expect($other->refresh()->suspended_at)->not->toBeNull();

    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.resume', $other))->assertRedirect();
    expect($other->refresh()->suspended_at)->toBeNull();

    $c = app(SurgeryIndicatorsService::class)->summary((string) $this->w->entity->id, now()->subDays(5), now())['cancellations'];

    expect($c['suspended'])->toBe(2)->and($c)->not->toHaveKeys(['cancel_reasons', 'suspend_reasons']);
});
