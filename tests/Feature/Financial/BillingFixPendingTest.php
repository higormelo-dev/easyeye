<?php

declare(strict_types=1);

use App\Domains\Tiss\Enums\TissGuideStatus;
use App\Domains\Tiss\Models\{TissBatch, TissBatchGuide, TissGuide, TissGuideItem};
use App\Enums\{BillingBatchStatus, BillingClaimStatus};
use App\Models\{BillingBatch, BillingClaim, Covenant, Entity, Schedule};
use App\Services\Financial\{BillingAdjustmentService, BillingService};
use Illuminate\Testing\TestResponse;

/*
 * Fase 4 — "Corrigir pendência" da guia TISS ainda não enviada (fora do lote
 * TISS: barrada na pré-validação ou individual): CID, carteirinha e nº da
 * autorização, nome do beneficiário completado, pré-validação refeita e — sem
 * erro, com o lote em rascunho — a guia entra no lote TISS. Guia no lote,
 * enviada, paga, glosada, cancelada ou não TISS: 422 traduzido.
 */

beforeEach(function (): void {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

/** Lote do convênio do agendamento (atendidos hoje); sem CID por padrão => guia com pendência. */
function bfpBatch(Schedule $schedule, array $overrides = []): BillingBatch
{
    return app(BillingService::class)->createBatch(array_merge([
        'covenant_id' => $schedule->covenant_id,
        'date_from'   => now()->subDay()->toDateString(),
        'date_until'  => now()->toDateString(),
        'unit_price'  => 150,
    ], $overrides));
}

function bfpClaimOf(Schedule $schedule): BillingClaim
{
    return BillingClaim::query()->where('schedule_id', $schedule->id)->latest('created_at')->firstOrFail();
}

/** @param array<string, mixed> $data */
function bfpFix(BillingClaim $claim, array $data): TestResponse
{
    return test()->postJson(route('panel.financial.billing.claims.fix-pending', $claim->id), $data);
}

/** @return list<string> códigos da pré-validação na resposta */
function bfpCodes(TestResponse $response, string $kind): array
{
    return collect($response->json("validation.{$kind}"))->pluck('code')->all();
}

it('guia barrada no lote (sem CID): corrige, refaz a pré-validação e entra no lote TISS com os totais atualizados', function (): void {
    $schedule = createBillableSchedule($this->entity);
    $batch    = bfpBatch($schedule);
    $claim    = bfpClaimOf($schedule);

    expect($batch->tissBatch->guides_count)->toBe(0)
        ->and(collect($claim->tissGuide->errors)->pluck('code')->all())->toContain('CID_REQUIRED');

    $rowBefore = collect($this->get(route('panel.financial.billing.index', ['tab' => 'claims']))->viewData('page')['props']['claims']['data'])
        ->firstWhere('id', $claim->id);

    expect($rowBefore['has_pending_guide'])->toBeTrue()
        ->and($rowBefore['allowed_actions'])->toContain('fix_pending')
        ->and($rowBefore['fix_pending']['url'])->toBe(route('panel.financial.billing.claims.fix-pending', $claim->id))
        ->and($rowBefore['fix_pending']['beneficiary_card_number'])->toBe('1234567890');

    $response = bfpFix($claim, ['clinical_indication' => ' h40.1 '])
        ->assertOk()
        ->assertJsonPath('attached', true)
        ->assertJsonPath('validation.passes', true)
        ->assertJsonPath('message', __('financial_billing.fix_result.attached', ['code' => $claim->code, 'batch' => $batch->code]));

    expect(bfpCodes($response, 'errors'))->toBe([]);

    $guide = TissGuide::query()->findOrFail($claim->tiss_guide_id);

    // Sem erro (aviso, como TUSS fora da tabela de referência do teste, não barra).
    expect($guide->clinical_indication)->toBe('H40.1')
        ->and(collect($guide->errors ?? [])->where('severity', 'error')->all())->toBe([])
        ->and($guide->status)->toBe(TissGuideStatus::Batched)
        ->and(TissBatchGuide::query()->where('guide_id', $guide->id)->where('batch_id', $batch->tiss_batch_id)->exists())->toBeTrue()
        ->and(TissBatch::query()->findOrFail($batch->tiss_batch_id)->guides_count)->toBe(1)
        ->and((float) $batch->fresh()->total_amount)->toBe(150.0);

    $rowAfter = collect($this->get(route('panel.financial.billing.index', ['tab' => 'claims']))->viewData('page')['props']['claims']['data'])
        ->firstWhere('id', $claim->id);

    expect($rowAfter['has_pending_guide'])->toBeFalse()
        ->and($rowAfter['out_of_batch'])->toBeFalse()
        ->and($rowAfter['fix_pending'])->toBeNull()
        ->and($rowAfter['allowed_actions'])->not->toContain('fix_pending');
});

it('carteirinha e autorização: gravam na guia, nos itens e na guia de faturamento; nome do beneficiário vazio vem do paciente', function (): void {
    $schedule = createBillableSchedule($this->entity);
    $claim    = app(BillingService::class)->createIndividual(['schedule_id' => $schedule->id, 'unit_price' => 150, 'clinical_indication' => 'H40.1']);

    TissGuide::query()->whereKey($claim->tiss_guide_id)->update(['beneficiary_name' => null]);

    bfpFix($claim, ['clinical_indication' => 'H40.1', 'beneficiary_card_number' => ' 998877 ', 'authorization_number' => 'aut-1'])
        ->assertOk()
        ->assertJsonPath('attached', false)
        ->assertJsonPath('message', __('financial_billing.fix_result.saved', ['code' => $claim->code]));

    $guide = TissGuide::query()->findOrFail($claim->tiss_guide_id);

    expect($guide->beneficiary_card_number)->toBe('998877')
        ->and($guide->authorization_number)->toBe('AUT-1')
        ->and($guide->beneficiary_name)->toBe($schedule->patient->person->full_name)
        ->and(TissGuideItem::query()->where('guide_id', $guide->id)->pluck('authorization_number')->unique()->all())->toBe(['AUT-1'])
        ->and($claim->fresh()->authorization_code)->toBe('AUT-1');
});

it('ainda com erro: salva, devolve os erros (422 não) e a guia continua fora do lote', function (): void {
    $schedule = createBillableSchedule($this->entity);
    $batch    = bfpBatch($schedule);
    $claim    = bfpClaimOf($schedule);

    $response = bfpFix($claim, ['beneficiary_card_number' => '555'])
        ->assertOk()
        ->assertJsonPath('attached', false)
        ->assertJsonPath('validation.passes', false)
        ->assertJsonPath('validation.summary', __('financial_billing.pre_validation.errors'))
        ->assertJsonPath('message', __('financial_billing.fix_result.still_pending', ['code' => $claim->code]));

    expect(bfpCodes($response, 'errors'))->toContain('CID_REQUIRED');

    $guide = TissGuide::query()->findOrFail($claim->tiss_guide_id);

    expect($guide->beneficiary_card_number)->toBe('555')
        ->and(collect($guide->errors)->pluck('code')->all())->toContain('CID_REQUIRED')
        ->and(TissBatchGuide::query()->where('guide_id', $guide->id)->exists())->toBeFalse()
        ->and($batch->tissBatch->fresh()->guides_count)->toBe(0);
});

it('campo não enviado não muda; campo vazio limpa', function (): void {
    $schedule = createBillableSchedule($this->entity);
    $claim    = app(BillingService::class)->createIndividual(['schedule_id' => $schedule->id, 'unit_price' => 150, 'clinical_indication' => 'H40.1', 'authorization_code' => 'X1']);

    bfpFix($claim, ['authorization_number' => ''])->assertOk();

    $guide = TissGuide::query()->findOrFail($claim->tiss_guide_id);

    expect($guide->clinical_indication)->toBe('H40.1')
        ->and($guide->beneficiary_card_number)->toBe('1234567890')
        ->and($guide->authorization_number)->toBeNull()
        ->and($claim->fresh()->authorization_code)->toBeNull();
});

it('CID fora do padrão da ANS e campos longos → 422 no campo, nada gravado', function (): void {
    $schedule = createBillableSchedule($this->entity);
    bfpBatch($schedule);
    $claim = bfpClaimOf($schedule);

    bfpFix($claim, ['clinical_indication' => 'glaucoma', 'beneficiary_card_number' => str_repeat('9', 65)])
        ->assertStatus(422)
        ->assertJsonPath('errors.clinical_indication.0', __('financial_billing.validation.cid_format'))
        ->assertJsonPath('errors.beneficiary_card_number.0', __('financial_billing.validation.field_max', ['max' => 64]));

    expect(TissGuide::query()->findOrFail($claim->tiss_guide_id)->beneficiary_card_number)->toBe('1234567890');
});

it('sem pendência corrigível → 422 traduzido: guia já no lote TISS, paga, cancelada ou não TISS', function (): void {
    $service = app(BillingService::class);

    // No lote TISS (lote com CID).
    $inLot = createBillableSchedule($this->entity);
    bfpBatch($inLot, ['clinical_indication' => 'H40.1']);
    $attached = bfpClaimOf($inLot);

    bfpFix($attached, ['authorization_number' => '1'])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', __('financial_billing.errors.fix_pending_not_allowed', ['code' => $attached->code]));

    // Paga.
    $paid = $service->createIndividual(['schedule_id' => createBillableSchedule($this->entity)->id, 'unit_price' => 150, 'clinical_indication' => 'H40.1']);
    $service->markClaimPaid($paid);

    bfpFix($paid, ['authorization_number' => '1'])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', __('financial_billing.errors.fix_pending_not_allowed', ['code' => $paid->code]));

    // Cancelada.
    $cancelled = $service->createIndividual(['schedule_id' => createBillableSchedule($this->entity)->id, 'unit_price' => 150, 'clinical_indication' => 'H40.1']);
    app(BillingAdjustmentService::class)->cancelClaim($cancelled, 'Cancelada para o teste de correção');

    bfpFix($cancelled, ['authorization_number' => '1'])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', __('financial_billing.errors.claim_cancelled', ['code' => $cancelled->code]));

    // Particular (sem guia TISS).
    $particular = $service->createIndividual(['schedule_id' => createParticularSchedule($this->entity)->id, 'unit_price' => 100]);

    bfpFix($particular, ['authorization_number' => '1'])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', __('financial_billing.errors.fix_pending_not_tiss', ['code' => $particular->code]));

    expect(TissGuide::query()->findOrFail($attached->tiss_guide_id)->authorization_number)->toBeNull();
});

it('lote enviado: a guia que ficou de fora ainda é corrigível (continua fora); a guia TISS enviada não', function (): void {
    $sent    = createBillableSchedule($this->entity);
    $pending = createBillableSchedule($this->entity, ['covenant_id' => $sent->covenant_id]);
    // Sem carteirinha: barrada na pré-validação, fica fora do lote TISS.
    $pending->patient->update(['card_number' => null]);

    $batch = bfpBatch($sent, ['clinical_indication' => 'H40.1']);
    app(BillingService::class)->submitBatch($batch->fresh());

    $pendingClaim = bfpClaimOf($pending);
    $sentClaim    = bfpClaimOf($sent);

    // O envio passa as guias em rascunho do lote para Enviado — inclusive a que ficou fora.
    expect($pendingClaim->status)->toBe(BillingClaimStatus::Submitted)
        ->and($pendingClaim->tissGuide->status)->toBe(TissGuideStatus::Draft)
        ->and($sentClaim->tissGuide->status)->toBe(TissGuideStatus::Sent);

    bfpFix($pendingClaim, ['beneficiary_card_number' => '777'])
        ->assertOk()
        ->assertJsonPath('attached', false)
        ->assertJsonPath('validation.passes', true);

    bfpFix($sentClaim, ['beneficiary_card_number' => '888'])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', __('financial_billing.errors.fix_pending_not_allowed', ['code' => $sentClaim->code]));

    expect(TissGuide::query()->findOrFail($pendingClaim->tiss_guide_id)->beneficiary_card_number)->toBe('777')
        ->and(TissGuide::query()->findOrFail($sentClaim->tiss_guide_id)->beneficiary_card_number)->toBe('1234567890');
});

it('outra clínica → 404; perfil sem permissão financeira → 403', function (): void {
    $other    = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $covenant = Covenant::factory()->create(['entity_id' => $other->id, 'active' => true]);
    $theirs   = BillingClaim::query()->create([
        'entity_id'       => $other->id,
        'covenant_id'     => $covenant->id,
        'status'          => BillingClaimStatus::Draft->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => 100,
        'quantity'        => 1,
        'unit_price'      => 100,
    ]);

    bfpFix($theirs, ['authorization_number' => '1'])->assertNotFound();

    $schedule   = createBillableSchedule($this->entity);
    $claim      = app(BillingService::class)->createIndividual(['schedule_id' => $schedule->id, 'unit_price' => 150]);
    $entityUser = createDoctorForEntity($this->entity)->entityUser;

    $this->actingAs($entityUser->user)
        ->withSession(panelSession($entityUser))
        ->postJson(route('panel.financial.billing.claims.fix-pending', $claim->id), ['clinical_indication' => 'H40.1'])
        ->assertForbidden();

    expect(TissGuide::query()->findOrFail($claim->tiss_guide_id)->clinical_indication)->toBeNull();
});

it('lote de faturamento já enviado: a guia corrigida continua fora (não entra num lote que saiu)', function (): void {
    $schedule = createBillableSchedule($this->entity);
    $batch    = bfpBatch($schedule);
    $claim    = bfpClaimOf($schedule);

    $batch->update(['status' => BillingBatchStatus::Submitted->value]);
    $claim->update(['status' => BillingClaimStatus::Submitted->value]);

    bfpFix($claim, ['clinical_indication' => 'H40.1'])
        ->assertOk()
        ->assertJsonPath('attached', false)
        ->assertJsonPath('validation.passes', true);

    expect(TissBatchGuide::query()->where('guide_id', $claim->tiss_guide_id)->exists())->toBeFalse();
});
