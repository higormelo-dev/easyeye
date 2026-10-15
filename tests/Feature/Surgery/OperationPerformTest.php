<?php

declare(strict_types=1);

require_once __DIR__ . '/OperationFixtures.php';

use App\Enums\Surgery\{AuthorizationStatus, SurgicalCaseStatus};
use App\Events\Surgery\SurgeryPerformed;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Doctor;
use App\Models\Surgery\{SurgicalCase, SurgicalOperation};
use App\Services\Surgery\Guards\{ChecklistTimeOutGuard, SurgeryPerformGuard};
use App\Services\Surgery\SurgicalCaseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Event};

/*
 * Fase 4 / M4 — registrar a cirurgia como REALIZADA: estado relido sob a
 * trava, condições (autorização + as registradas em perform_guards), implante
 * da LIO planejada, evento só depois do commit e idempotência.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $this->w = surgeryWorld();
    config(['surgery.perform_guards' => []]);
});

function sopPerform(object $t, $case, array $body = [], string $who = 'doctor')
{
    return surgeryAs($t->w, $who)->postJson(route('panel.surgery.operation.perform', $case), array_merge(['team_confirmed' => true], $body));
}

class SopListener
{
    public static array $levels = [];
}

it('registra a cirurgia: Realizada, ato gravado e evento depois do commit', function () {
    $case = sopCase($this->w);

    SopListener::$levels = [];
    Event::listen(SurgeryPerformed::class, function (SurgeryPerformed $e) {
        SopListener::$levels[] = [DB::transactionLevel(), $e->caseId, $e->case()->status->value];
    });

    $baseLevel = DB::transactionLevel();

    sopPerform($this, $case, ['complications' => 'Sem intercorrências relevantes'])->assertOk();

    $case->refresh();
    $op = SurgicalOperation::query()->where('surgical_case_id', $case->id)->firstOrFail();

    expect($case->status)->toBe(SurgicalCaseStatus::Performed)
        ->and($case->performed_at)->not->toBeNull()
        ->and($op->complications)->toBe('Sem intercorrências relevantes')
        ->and($op->team_confirmed_at)->not->toBeNull()
        ->and((string) $op->performed_by_doctor_id)->toBe((string) $this->w->doctor->id)
        ->and($case->status_history)->toHaveCount(count($case->status_history));

    // Despachado UMA vez, já fora da transação do ato (nível da transação do teste), com o caso Realizado.
    expect(SopListener::$levels)->toBe([[$baseLevel, (string) $case->id, 'performed']]);
});

it('é idempotente: a segunda chamada é 409 e não grava nem despacha de novo', function () {
    $case = sopCase($this->w);

    Event::fake([SurgeryPerformed::class]);

    sopPerform($this, $case)->assertOk();
    sopPerform($this, $case)->assertStatus(409);

    expect(SurgicalOperation::query()->count())->toBe(1);
    Event::assertDispatchedTimes(SurgeryPerformed::class, 1);
});

it('exige a confirmação da equipe', function () {
    $case = sopCase($this->w);

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.perform', $case), ['team_confirmed' => false])->assertStatus(422);
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.perform', $case), [])->assertStatus(422);

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Scheduled);
});

it('só uma cirurgia agendada pode ser registrada', function () {
    foreach ([SurgicalCaseStatus::Authorized, SurgicalCaseStatus::Draft, SurgicalCaseStatus::Suspended, SurgicalCaseStatus::Cancelled] as $state) {
        $case = sopCase($this->w, state: ['status' => $state]);
        sopPerform($this, $case)->assertStatus(409);
        expect($case->refresh()->status)->toBe($state);
    }

    expect(SurgicalOperation::query()->count())->toBe(0);
});

it('perfil: só o cirurgião do caso registra; secretária, financeiro e outro médico não', function () {
    $case = sopCase($this->w);

    sopPerform($this, $case, who: 'secretary')->assertForbidden();
    sopPerform($this, $case, who: 'financial')->assertForbidden();
    sopPerform($this, $case, who: 'admin')->assertForbidden();

    $other = createDoctorForEntity($this->w->entity);
    test()->actingAs($other->entityUser->user)->withSession(panelSession($other->entityUser))
        ->postJson(route('panel.surgery.operation.perform', $case), ['team_confirmed' => true])->assertForbidden();

    // Auxiliar da equipe participa, mas não é o cirurgião: o ato continua sendo do cirurgião.
    $assistant = createDoctorForEntity($this->w->entity);
    $case->team()->create(['entity_id' => $this->w->entity->id, 'role' => 'assistant', 'doctor_id' => $assistant->id, 'name' => 'AUX']);
    test()->actingAs($assistant->entityUser->user)->withSession(panelSession($assistant->entityUser))
        ->postJson(route('panel.surgery.operation.perform', $case), ['team_confirmed' => true])->assertStatus(403);

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Scheduled);
});

it('cirurgião da equipe (papel cirurgião) também registra', function () {
    $case = sopCase($this->w);
    $co   = createDoctorForEntity($this->w->entity);
    $case->team()->create(['entity_id' => $this->w->entity->id, 'role' => 'surgeon', 'doctor_id' => $co->id, 'name' => 'CO']);

    test()->actingAs($co->entityUser->user)->withSession(panelSession($co->entityUser))
        ->postJson(route('panel.surgery.operation.perform', $case), ['team_confirmed' => true])->assertOk();

    expect((string) SurgicalOperation::query()->firstOrFail()->performed_by_doctor_id)->toBe((string) $co->id);
});

it('revisão velha é 409 e nada é gravado', function () {
    $case = sopCase($this->w);

    sopPerform($this, $case, ['expected_revision' => $case->revision + 9])->assertStatus(409);

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Scheduled)->and(SurgicalOperation::query()->count())->toBe(0);
});

it('a data da cirurgia não pode ser futura nem anterior ao dia agendado', function () {
    $case = sopCase($this->w);

    sopPerform($this, $case, ['performed_at' => now()->addDay()->toIso8601String()])->assertStatus(422);
    sopPerform($this, $case, ['performed_at' => now()->subDays(3)->toIso8601String()])->assertStatus(422);
    sopPerform($this, $case, ['performed_at' => now()->setTime(8, 30)->toIso8601String()])->assertOk();

    expect($case->refresh()->performed_at->format('H:i'))->toBe('08:30');
});

// ── Autorização ─────────────────────────────────────────────────────────────

it('autorização vencida só passa com a justificativa do cirurgião, que fica gravada', function () {
    $case = sopCase($this->w, state: [
        'authorization_status'      => AuthorizationStatus::Authorized,
        'authorization_valid_until' => '2026-10-10',
    ]);

    sopPerform($this, $case)->assertStatus(409);
    sopPerform($this, $case, ['authorization_override_reason' => 'curto'])->assertStatus(409);
    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Scheduled);

    sopPerform($this, $case, ['authorization_override_reason' => 'Operadora confirmou a renovação por telefone'])->assertOk();

    expect(SurgicalOperation::query()->firstOrFail()->override_reasons)->toBe(['authorization' => 'Operadora confirmou a renovação por telefone']);
});

it('autorização com status vencido (job) também exige a justificativa; válida não registra justificativa', function () {
    $expired = sopCase($this->w, state: ['authorization_status' => AuthorizationStatus::Expired]);
    sopPerform($this, $expired)->assertStatus(409);

    $valid = sopCase($this->w, ['title' => 'B'], ['authorization_status' => AuthorizationStatus::Authorized, 'authorization_valid_until' => '2026-12-01']);
    sopPerform($this, $valid, ['authorization_override_reason' => 'Justificativa desnecessária aqui'])->assertOk();

    expect(SurgicalOperation::query()->where('surgical_case_id', $valid->id)->firstOrFail()->override_reasons)->toBe([]);
});

it('pedido sem autorização (pendente/negada) nunca passa, nem com justificativa', function () {
    foreach ([AuthorizationStatus::Pending, AuthorizationStatus::Denied, AuthorizationStatus::NotRequested] as $status) {
        $case = sopCase($this->w, state: ['authorization_status' => $status]);
        sopPerform($this, $case, ['authorization_override_reason' => 'Quero operar mesmo assim, é urgente'])->assertStatus(409);
        expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Scheduled);
    }
});

// ── LIO planejada ───────────────────────────────────────────────────────────

function sopPlanIol(object $t, $case, string $eye = 'od'): void
{
    $case->iolPlans()->create([
        'entity_id' => $t->w->entity->id, 'eye' => $eye, 'model_label' => 'LIO X', 'power' => 21.5,
    ]);
}

it('com LIO planejada exige o implante registrado ou a justificativa de ausência', function () {
    $case = sopCase($this->w);
    sopPlanIol($this, $case);

    sopPerform($this, $case)->assertStatus(409);
    sopPerform($this, $case, ['no_implant_reason' => 'curto'])->assertStatus(409);

    sopPerform($this, $case, ['no_implant_reason' => 'Catarata muito densa: LIO adiada para segundo tempo'])->assertOk();

    expect(SurgicalOperation::query()->firstOrFail()->no_implant_reason)->toBe('Catarata muito densa: LIO adiada para segundo tempo');
});

it('com o implante da LIO registrado no olho planejado, passa sem justificativa', function () {
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);
    sopPlanIol($this, $case);

    sopStore($this, $case, sopImplantPayload($product, $lot))->assertCreated();

    sopPerform($this, $case)->assertOk();

    expect(SurgicalOperation::query()->firstOrFail()->no_implant_reason)->toBeNull();
});

it('implante anulado ou de outro olho não vale para a LIO planejada', function () {
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w, ['eye' => 'ao']);
    sopPlanIol($this, $case, 'od');
    sopPlanIol($this, $case, 'oe');

    $od = sopStore($this, $case, sopImplantPayload($product, $lot, ['eye' => 'od', 'serial_number' => 'S-OD']))->json('implant_id');

    // Só o OD: falta o OE.
    sopPerform($this, $case)->assertStatus(409);

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.implants.void', [$case, $od]), ['reason' => 'Erro', 'return_to_stock' => true])->assertOk();
    sopStore($this, $case, sopImplantPayload($product, $lot, ['eye' => 'oe', 'serial_number' => 'S-OE']))->assertCreated();

    sopPerform($this, $case)->assertStatus(409); // OD anulado, só o OE vigente

    sopStore($this, $case, sopImplantPayload($product, $lot, ['eye' => 'od', 'serial_number' => 'S-OD2']))->assertCreated();
    sopPerform($this, $case)->assertOk();
});

// ── Condições registradas por outras etapas ────────────────────────────────

class SopRefusingGuard implements SurgeryPerformGuard
{
    public static int $calls = 0;

    public function assert(SurgicalCase $locked, array $input, Doctor $performer): void
    {
        self::$calls++;

        throw SurgeryRuleException::state('surgery_operation.errors.already_performed');
    }
}

class SopPassingGuard implements SurgeryPerformGuard
{
    public static array $seen = [];

    public function assert(SurgicalCase $locked, array $input, Doctor $performer): void
    {
        self::$seen[] = [$locked->status->value, (string) $performer->id, $input['time_out_override_reason'] ?? null];
    }
}

it('uma condição registrada recusa: nada é gravado e o evento não sai', function () {
    config(['surgery.perform_guards' => [SopRefusingGuard::class]]);
    SopRefusingGuard::$calls = 0;
    Event::fake([SurgeryPerformed::class]);

    $case = sopCase($this->w);

    sopPerform($this, $case)->assertStatus(409);

    expect(SopRefusingGuard::$calls)->toBe(1)
        ->and($case->refresh()->status)->toBe(SurgicalCaseStatus::Scheduled)
        ->and(SurgicalOperation::query()->count())->toBe(0);
    Event::assertNotDispatched(SurgeryPerformed::class);
});

it('uma condição registrada que passa recebe o caso travado, o cirurgião e a justificativa enviada', function () {
    config(['surgery.perform_guards' => [SopPassingGuard::class]]);
    SopPassingGuard::$seen = [];

    $case = sopCase($this->w);

    sopPerform($this, $case, ['time_out_override_reason' => 'Urgência: paciente já anestesiado'])->assertOk();

    expect(SopPassingGuard::$seen)->toBe([['scheduled', (string) $this->w->doctor->id, 'Urgência: paciente já anestesiado']]);
});

it('com a Pausa Cirúrgica registrada em config: sem fechar recusa; com a justificativa do cirurgião passa e a aba mostra a condição', function () {
    config(['surgery.perform_guards' => [ChecklistTimeOutGuard::class]]);

    $case = sopCase($this->w);

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $case))
        ->assertInertia(fn ($page) => $page
            ->where('sections.operation.requirements.1.key', 'time_out')
            ->where('sections.operation.requirements.1.ok', false)
            ->where('sections.operation.requirements.1.override', 'time_out_override_reason'));

    sopPerform($this, $case)->assertStatus(409);
    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Scheduled);

    sopPerform($this, $case, ['time_out_override_reason' => 'Urgência: paciente já anestesiado na sala'])->assertOk();

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Performed);
});

it('o service recusa fora de transação travada (lock exige transação)', function () {
    $case = sopCase($this->w);

    expect(fn () => app(SurgicalCaseService::class)->lock((string) $case->id))->not->toThrow(LogicException::class);
});

it('a aba Cirurgia só existe para agendada, realizada ou com alta e lista as condições', function () {
    $draft = sopCase($this->w, state: ['status' => SurgicalCaseStatus::Authorized]);

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $draft))
        ->assertInertia(fn ($page) => $page->where('tabs', fn ($tabs) => ! collect($tabs)->contains('key', 'operation')));

    $case = sopCase($this->w, ['title' => 'Agendada'], ['authorization_status' => AuthorizationStatus::Expired]);
    sopPlanIol($this, $case);

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $case))
        ->assertInertia(fn ($page) => $page
            ->where('tabs', fn ($tabs) => collect($tabs)->contains('key', 'operation'))
            ->where('sections.operation.performed', false)
            ->where('sections.operation.state.can_perform', true)
            ->where('sections.operation.requirements.0.key', 'authorization')
            ->where('sections.operation.requirements.0.ok', false)
            ->where('sections.operation.requirements.0.override', 'authorization_override_reason')
            ->where('sections.operation.requirements.1.key', 'implant')
            ->where('sections.operation.requirements.1.ok', false));

    // Secretária vê a aba, mas sem ações do ato médico.
    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.show', $case))
        ->assertInertia(fn ($page) => $page
            ->where('sections.operation.state.can_perform', false)
            ->where('sections.operation.state.can_register', false)
            ->where('sections.operation.state.can_print', true));
});
