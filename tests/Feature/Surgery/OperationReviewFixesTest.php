<?php

declare(strict_types=1);

require_once __DIR__ . '/OperationFixtures.php';

use App\Enums\Surgery\{AuthorizationStatus, SurgicalCaseStatus};
use App\Events\Surgery\SurgeryPerformed;
use App\Exceptions\ExpiredLotException;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\{EntityProduct, StockLot};
use App\Models\Surgery\{SurgeryDocumentIssue, SurgicalDescription, SurgicalImplant, SurgicalOperation};
use App\Services\Stock\StockService;
use App\Services\Surgery\{SurgeryImplantDocumentService, SurgeryScheduleService};
use Carbon\Carbon;

/*
 * Fase 4 / M4 — correções da revisão adversarial: implante preso fora do
 * Agendado (C1), fuso das datas digitadas (C2), evento só com o id (C5), registro
 * de implante anulado não servido e emissão relida sob a trava (C10), validade
 * conferida na data do uso (C11) e imutabilidade do ato e do implante anulado (C14).
 */
beforeEach(function () {
    $this->w = surgeryWorld();
});

// ── C1 — implante vigente prende a saída do Agendado ───────────────────────

it('[C1] cancelar, suspender e liberar a vaga são recusados com implante vigente; anulado, voltam a valer', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);
    $id              = sopStore($this, $case, sopImplantPayload($product, $lot))->json('implant_id');

    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.cancel', $case), ['reason' => 'Paciente desistiu'])->assertStatus(409);
    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.suspend', $case), ['reason' => 'Paciente com febre'])->assertStatus(409);
    expect(fn () => app(SurgeryScheduleService::class)->release($case, 'liberar'))->toThrow(SurgeryRuleException::class);

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Scheduled);

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.implants.void', [$case, $id]), ['reason' => 'Cirurgia adiada', 'return_to_stock' => true])->assertOk();
    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.cancel', $case), ['reason' => 'Paciente desistiu'])->assertRedirect();

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Cancelled)->and((float) $lot->fresh()->qty_on_hand)->toBe(5.0);
});

it('[C1] implante de caso que já saiu do Agendado (dado antigo) ainda pode ser anulado, menos depois da alta', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);
    $id              = sopStore($this, $case, sopImplantPayload($product, $lot))->json('implant_id');

    $case->forceFill(['status' => SurgicalCaseStatus::Suspended])->save();
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.implants.void', [$case, $id]), ['reason' => 'Sobra', 'return_to_stock' => true])->assertOk();

    expect((float) $lot->fresh()->qty_on_hand)->toBe(5.0);

    $other      = sopStore($this, sopCase($this->w, ['title' => 'B']), sopImplantPayload($product, $lot, ['serial_number' => 'SN-D']))->json('implant_id');
    $discharged = SurgicalImplant::query()->findOrFail($other)->surgicalCase;
    $discharged->forceFill(['status' => SurgicalCaseStatus::Discharged])->save();
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.implants.void', [$discharged, $other]), ['reason' => 'Tarde'])->assertStatus(409);
});

// ── C2 — horários no fuso da aplicação ──────────────────────────────────────

it('[C2] horário "...Z" digitado grava o relógio LOCAL (12:30 local = 15:30Z) no implante, na cirurgia e na descrição', function () {
    config(['app.timezone' => 'America/Sao_Paulo']);
    date_default_timezone_set('America/Sao_Paulo');
    $this->travelTo(Carbon::parse('2026-10-15 18:00:00', 'America/Sao_Paulo'));

    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);

    $id = sopStore($this, $case, sopImplantPayload($product, $lot, ['implanted_at' => '2026-10-15T15:30:00.000Z']))->assertCreated()->json('implant_id');
    expect(SurgicalImplant::query()->findOrFail($id)->implanted_at->format('Y-m-d H:i'))->toBe('2026-10-15 12:30');

    surgeryAs($this->w, 'doctor')->putJson(route('panel.surgery.operation.description.save', $case), [
        'performed_start_at' => '2026-10-15T15:10:00.000Z', 'performed_end_at' => '2026-10-15T15:40:00.000Z',
    ])->assertOk();
    $d = SurgicalDescription::query()->firstOrFail();
    expect($d->performed_start_at->format('H:i'))->toBe('12:10')->and($d->performed_end_at->format('H:i'))->toBe('12:40');

    config(['surgery.perform_guards' => []]);
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.perform', $case), [
        'team_confirmed' => true, 'performed_at' => '2026-10-15T15:30:00.000Z', 'no_implant_reason' => 'n/a',
    ])->assertOk();

    expect($case->refresh()->performed_at->format('H:i'))->toBe('12:30')
        ->and(SurgicalOperation::query()->firstOrFail()->performed_at->format('H:i'))->toBe('12:30');
});

it('[C2] a virada de dia usa o fuso da aplicação: 23:30 local de hoje ainda vale a validade de hoje (autorização e lote)', function () {
    config(['app.timezone' => 'America/Sao_Paulo', 'surgery.perform_guards' => []]);
    date_default_timezone_set('America/Sao_Paulo');
    $this->travelTo(Carbon::parse('2026-10-15 23:45:00', 'America/Sao_Paulo'));

    [$product] = sopIol($this->w, 'L-HOJE', '2026-10-15');
    $lot       = StockLot::query()->where('lot_number', 'L-HOJE')->firstOrFail();
    $case      = sopCase($this->w, state: ['authorization_status' => AuthorizationStatus::Authorized, 'authorization_valid_until' => '2026-10-15']);
    $lateNight = '2026-10-16T02:30:00.000Z'; // 23:30 de 15/10 em São Paulo (já é 16/10 em UTC)

    sopStore($this, $case, sopImplantPayload($product, $lot, ['implanted_at' => $lateNight]))->assertCreated();

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.perform', $case), [
        'team_confirmed' => true, 'performed_at' => $lateNight,
    ])->assertOk(); // sem justificativa: a autorização de 15/10 vale em 15/10 local

    expect(SurgicalOperation::query()->firstOrFail()->override_reasons)->toBe([]);
});

// ── C5 — evento só com o id ─────────────────────────────────────────────────

it('[C5] SurgeryPerformed carrega só o id: o payload serializado não leva o model nem a senha da operadora', function () {
    $case = sopCase($this->w, state: ['authorization_password' => 'SENHA-SECRETA-123']);

    $event = new SurgeryPerformed((string) $case->id);

    expect($event->caseId)->toBe((string) $case->id)
        ->and(array_keys(get_object_vars($event)))->toBe(['caseId'])
        ->and(serialize($event))->not->toContain('SENHA-SECRETA-123')->not->toContain('PACIENTE')
        ->and($event->case()->is($case))->toBeTrue();
});

// ── C10 — registro de implante anulado ──────────────────────────────────────

it('[C10] registro vigente de implante anulado deixa de ser servido; emissão com instância velha relê sob a trava', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);
    $id              = sopStore($this, $case, sopImplantPayload($product, $lot))->json('implant_id');

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.implants.record', [$case, $id]))->assertOk();
    $stale = SurgicalImplant::query()->findOrFail($id); // instância "ativa" carregada ANTES da anulação

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.implants.void', [$case, $id]), ['reason' => 'Erro', 'return_to_stock' => true])->assertOk();

    surgeryAs($this->w, 'secretary')->getJson(route('panel.surgery.operation.implants.record-pdf', [$case, $id]))->assertStatus(409);
    expect(app(SurgeryImplantDocumentService::class)->currentRecord($stale->fresh()))->toBeNull();

    expect(fn () => app(SurgeryImplantDocumentService::class)->issueRecord($stale))->toThrow(SurgeryRuleException::class);
    // O documento emitido antes continua no histórico (imutável), só não é servido.
    expect(SurgeryDocumentIssue::query()->count())->toBe(1);

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $case))
        ->assertInertia(fn ($page) => $page->where('sections.operation.implants.0.record', null));
});

// ── C11 — validade na data do uso ───────────────────────────────────────────

it('[C11] registro tardio: lote que venceu ontem vale para o uso de anteontem e não para o de hoje', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $stock   = app(StockService::class);
    $product = EntityProduct::create(['entity_id' => $this->w->entity->id, 'name' => 'OPM', 'unit' => 'un', 'requires_lot' => true, 'active' => true]);
    $lot     = $stock->findOrCreateLot($product, 'L-ONTEM', Carbon::parse('2026-10-14'));
    $stock->manualIn($product, 5, 10.0, lot: $lot);

    expect($stock->consumptionOut($product, 1, occurredAt: Carbon::parse('2026-10-13 09:00:00'), lot: $lot)->exists)->toBeTrue();
    expect(fn () => $stock->consumptionOut($product, 1, lot: $lot))->toThrow(ExpiredLotException::class);
    expect(fn () => $stock->consumptionOut($product, 1, occurredAt: Carbon::parse('2026-10-15 09:00:00'), lot: $lot))->toThrow(ExpiredLotException::class);
});

it('[C11] registro tardio do implante: cirurgia de anteontem com lote válido anteontem é aceita', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    [$product] = sopIol($this->w, 'L-ONTEM', '2026-10-14');
    $lot       = StockLot::query()->where('lot_number', 'L-ONTEM')->firstOrFail();
    $case      = sopCase($this->w, state: ['scheduled_start' => '2026-10-13 08:00:00', 'scheduled_end' => '2026-10-13 09:00:00']);

    sopStore($this, $case, sopImplantPayload($product, $lot, ['implanted_at' => '2026-10-13T08:30:00-03:00']))->assertCreated();
    sopStore($this, $case, sopImplantPayload($product, $lot, ['implanted_at' => '2026-10-15T08:30:00-03:00', 'serial_number' => 'SN-HOJE']))->assertStatus(422);
});

// ── C14 — imutabilidade ─────────────────────────────────────────────────────

it('[C14] o ato "realizada" não se reescreve nem se apaga; implante anulado não reescreve motivo, data nem movimentos', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    config(['surgery.perform_guards' => []]);
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);
    $id              = sopStore($this, $case, sopImplantPayload($product, $lot))->json('implant_id');

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.perform', $case), ['team_confirmed' => true])->assertOk();
    $op = SurgicalOperation::query()->firstOrFail();

    expect(fn () => $op->update(['complications' => 'reescrito']))->toThrow(LogicException::class);
    expect(fn () => $op->forceFill(['performed_at' => now()->subYear()])->save())->toThrow(LogicException::class);
    expect(fn () => $op->delete())->toThrow(LogicException::class);

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.implants.void', [$case, $id]), ['reason' => 'Erro original', 'return_to_stock' => true])->assertOk();
    $voided = SurgicalImplant::query()->findOrFail($id);

    expect(fn () => $voided->forceFill(['void_reason' => 'Reescrito'])->save())->toThrow(LogicException::class);
    expect(fn () => $voided->forceFill(['voided_at' => now()->subYear()])->save())->toThrow(LogicException::class);
    expect(fn () => $voided->forceFill(['reversal_movement_id' => null])->save())->toThrow(LogicException::class);
    expect($voided->fresh()->void_reason)->toBe('Erro original');
});

// ── L5 — LIO registrada × LIO planejada ─────────────────────────────────────

it('[L5] LIO com potência diferente da planejada exige justificativa; com ela, grava no implante', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);
    $case->iolPlans()->create(['entity_id' => $this->w->entity->id, 'eye' => 'od', 'model_label' => 'LIO X', 'power' => 21.0]);

    // Dígito trocado: planejada +21,0 × registrada +12,0.
    $response = sopStore($this, $case, sopImplantPayload($product, $lot, ['power' => 12.0]))->assertStatus(422);
    expect($response->json('message'))->toContain('12,00')->toContain('21,00');
    sopStore($this, $case, sopImplantPayload($product, $lot, ['power' => 12.0, 'plan_deviation_reason' => 'curto']))->assertStatus(422);

    expect(SurgicalImplant::query()->count())->toBe(0)->and((float) $lot->fresh()->qty_on_hand)->toBe(5.0);

    $id = sopStore($this, $case, sopImplantPayload($product, $lot, ['power' => 12.0, 'plan_deviation_reason' => 'Biometria refeita no dia: LIO de 12 D confirmada']))->assertCreated()->json('implant_id');

    expect(SurgicalImplant::query()->findOrFail($id)->plan_deviation_reason)->toBe('Biometria refeita no dia: LIO de 12 D confirmada');

    // Igual à planejada: não pede nada e não grava justificativa.
    $case2 = sopCase($this->w, ['title' => 'B']);
    $case2->iolPlans()->create(['entity_id' => $this->w->entity->id, 'eye' => 'od', 'model_label' => 'LIO X', 'power' => 21.5]);
    $ok = sopStore($this, $case2, sopImplantPayload($product, $lot, ['serial_number' => 'SN-OK']))->assertCreated()->json('implant_id');
    expect(SurgicalImplant::query()->findOrFail($ok)->plan_deviation_reason)->toBeNull();
});

it('[L5] plano que aponta outro produto também é divergência, mesmo com a potência igual', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    [$product, $lot] = sopIol($this->w);
    [$planned]       = sopIol($this->w, 'L-PLAN', name: 'LIO Multifocal');
    $case            = sopCase($this->w);
    $case->iolPlans()->create(['entity_id' => $this->w->entity->id, 'eye' => 'od', 'model_label' => 'LIO Multifocal', 'power' => 21.5, 'entity_product_id' => $planned->id]);

    sopStore($this, $case, sopImplantPayload($product, $lot))->assertStatus(422);
    sopStore($this, $case, sopImplantPayload($product, $lot, ['plan_deviation_reason' => 'Multifocal indisponível: trocada por monofocal com o paciente']))->assertCreated();
});

it('[L5] plano alterado DEPOIS do registro: vira requisito na tela e o ato exige a justificativa, gravada', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    config(['surgery.perform_guards' => []]);
    [$product, $lot] = sopIol($this->w);
    $case            = sopCase($this->w);
    $case->iolPlans()->create(['entity_id' => $this->w->entity->id, 'eye' => 'od', 'model_label' => 'LIO X', 'power' => 21.5]);
    sopStore($this, $case, sopImplantPayload($product, $lot))->assertCreated();

    $case->iolPlans()->update(['power' => 18.0]);

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $case))->assertInertia(fn ($page) => $page
        ->where('sections.operation.requirements', fn ($rows) => collect($rows)->contains(fn ($r) => $r['key'] === 'plan' && $r['ok'] === false && $r['override'] === 'plan_deviation_reason')));

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.perform', $case), ['team_confirmed' => true])->assertStatus(409);
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.perform', $case), ['team_confirmed' => true, 'plan_deviation_reason' => 'Plano revisado após a biometria final'])->assertOk();

    expect(SurgicalOperation::query()->firstOrFail()->override_reasons)->toBe(['plan_deviation' => 'Plano revisado após a biometria final']);
});
