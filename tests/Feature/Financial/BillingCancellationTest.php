<?php

declare(strict_types=1);

use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus};
use App\Domains\Tiss\Models\{TissBatch, TissBatchGuide, TissGuide, TissStatusHistory};
use App\Enums\{BillingBatchStatus, BillingClaimStatus};
use App\Models\{AuditLog, BillingBatch, BillingClaim, Covenant, Entity, Schedule};
use App\Services\Financial\BillingService;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

/*
 * Fase 4 — "Cancelar guia" e "Cancelar lote" (só rascunho, motivo obrigatório).
 * Cancelar tira a guia do lote TISS ainda não enviado, cancela a guia TISS e
 * libera o atendimento para refaturar; o lote tem os totais recalculados. O
 * servidor decide (guards + allowed_actions); status errado, lote enviado,
 * lote legado, outra clínica, sem permissão e cancelar de novo não viram 500.
 * Concorrência/ordem de locks: BillingCancelLockTest.
 */

const BCX_REASON = 'Atendimento lançado no convênio errado';

beforeEach(function (): void {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->entityUser = actingAsFinancialEntityUser($this->entity);
});

function bcxIndividual(Schedule $schedule, array $overrides = []): BillingClaim
{
    return app(BillingService::class)->createIndividual(array_merge([
        'schedule_id'         => $schedule->id,
        'unit_price'          => 150,
        'clinical_indication' => 'H40.1',
    ], $overrides));
}

/** Lote com todos os elegíveis do convênio do agendamento (atendidos hoje). */
function bcxBatch(Schedule $schedule, array $overrides = []): BillingBatch
{
    return app(BillingService::class)->createBatch(array_merge([
        'covenant_id'         => $schedule->covenant_id,
        'date_from'           => now()->subDay()->toDateString(),
        'date_until'          => now()->toDateString(),
        'unit_price'          => 150,
        'clinical_indication' => 'H40.1',
    ], $overrides));
}

function bcxClaimOf(Schedule $schedule): BillingClaim
{
    return BillingClaim::query()->where('schedule_id', $schedule->id)->latest('created_at')->firstOrFail();
}

function bcxCancelClaim(BillingClaim $claim, ?string $reason = BCX_REASON): TestResponse
{
    return test()->post(route('panel.financial.billing.claims.cancel', $claim->id), $reason === null ? [] : ['reason' => $reason]);
}

function bcxCancelBatch(BillingBatch $batch, ?string $reason = BCX_REASON): TestResponse
{
    return test()->post(route('panel.financial.billing.batches.cancel', $batch->id), $reason === null ? [] : ['reason' => $reason]);
}

/** @param array<string, mixed> $overrides */
function bcxRawBatch(Entity $entity, Covenant $covenant, array $overrides = []): BillingBatch
{
    return BillingBatch::query()->create(array_merge([
        'entity_id'    => $entity->id,
        'covenant_id'  => $covenant->id,
        'status'       => BillingBatchStatus::Draft->value,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end'   => now()->toDateString(),
        'issued_at'    => now(),
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function bcxRawClaim(Entity $entity, Covenant $covenant, array $overrides = []): BillingClaim
{
    return BillingClaim::query()->create(array_merge([
        'entity_id'       => $entity->id,
        'covenant_id'     => $covenant->id,
        'status'          => BillingClaimStatus::Draft->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => 100.00,
        'quantity'        => 1,
        'unit_price'      => 100.00,
    ], $overrides));
}

/** @return list<string> ids elegíveis (A faturar) hoje */
function bcxEligibleIds(Entity $entity): array
{
    return app(BillingService::class)
        ->eligibleSchedulesQuery((string) $entity->id, now()->subDay()->toDateString(), now()->toDateString())
        ->pluck('schedules.id')
        ->all();
}

/** @return array<string, mixed> linhas da aba (paginator) indexadas por id */
function bcxRows(TestResponse $response, string $prop): array
{
    return collect($response->viewData('page')['props'][$prop]['data'])->keyBy('id')->all();
}

describe('cancelar guia', function (): void {
    it('guia TISS individual em rascunho: cancela a guia e a guia TISS, grava motivo/quem/quando e libera o atendimento', function (): void {
        $schedule = createBillableSchedule($this->entity);
        $claim    = bcxIndividual($schedule);

        bcxCancelClaim($claim)
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('financial_billing.flash.claim_cancelled', ['code' => $claim->code]));

        $claim->refresh();

        expect($claim->status)->toBe(BillingClaimStatus::Cancelled)
            ->and($claim->cancel_reason)->toBe(BCX_REASON)
            ->and($claim->cancelled_by)->toBe($this->entityUser->user_id)
            ->and($claim->cancelled_at)->not->toBeNull()
            ->and($claim->tissGuide->status)->toBe(TissGuideStatus::Cancelled);

        // Histórico TISS aponta a guia de faturamento sem repetir o texto livre digitado.
        $history = TissStatusHistory::query()
            ->where('context_id', $claim->tiss_guide_id)
            ->where('current_status', TissGuideStatus::Cancelled->value)
            ->firstOrFail();

        expect($history->reason)->toBe(__('financial_billing.tiss_history.claim_cancelled', ['code' => $claim->code]))
            ->and($history->reason)->not->toContain(BCX_REASON);

        // Auditoria (Auditable): antes/depois com motivo e autor.
        $audit = AuditLog::query()
            ->where('auditable_type', BillingClaim::class)
            ->where('auditable_id', $claim->id)
            ->where('event', 'updated')
            ->get()
            ->first(fn (AuditLog $log): bool => ($log->new_values['status'] ?? null) === BillingClaimStatus::Cancelled->value);

        expect($audit)->not->toBeNull()
            ->and($audit->new_values['cancel_reason'])->toBe(BCX_REASON)
            ->and($audit->new_values['cancelled_by'])->toBe($this->entityUser->user_id);

        // O atendimento volta para "A faturar" e aceita guia nova (índice parcial ignora cancelada).
        expect(bcxEligibleIds($this->entity))->toContain($schedule->id)
            ->and(bcxIndividual($schedule)->status)->toBe(BillingClaimStatus::Draft);
    });

    it('guia de lote TISS em rascunho: sai do lote TISS (vínculo removido), totais recalculados e XML guardado descartado', function (): void {
        $first  = createBillableSchedule($this->entity);
        $second = createBillableSchedule($this->entity, ['covenant_id' => $first->covenant_id]);
        $batch  = bcxBatch($first);
        $batch->update(['xml_path' => 'private/tiss/velho.xml']);

        $cancelled = bcxClaimOf($first);
        $kept      = bcxClaimOf($second);

        expect($batch->tissBatch->guides_count)->toBe(2)
            ->and((float) $batch->total_amount)->toBe(300.0);

        bcxCancelClaim($cancelled)->assertRedirect()->assertSessionHasNoErrors();

        $batch->refresh();
        $tissBatch = TissBatch::query()->findOrFail($batch->tiss_batch_id);

        expect(TissBatchGuide::query()->withoutGlobalScopes()->where('guide_id', $cancelled->tiss_guide_id)->count())->toBe(0)
            ->and($tissBatch->guides_count)->toBe(1)
            ->and((float) $tissBatch->total_amount)->toBe(150.0)
            ->and($tissBatch->guides()->pluck('tiss_guides.id')->all())->toBe([$kept->tiss_guide_id])
            ->and($batch->status)->toBe(BillingBatchStatus::Draft)
            ->and($batch->total_claims)->toBe(1)
            ->and((float) $batch->total_amount)->toBe(150.0)
            ->and($batch->xml_path)->toBeNull()
            ->and($cancelled->fresh()->batch_id)->toBe($batch->id)
            ->and($cancelled->fresh()->tissGuide->status)->toBe(TissGuideStatus::Cancelled)
            ->and($kept->fresh()->status)->toBe(BillingClaimStatus::Draft)
            ->and($kept->fresh()->tissGuide->status)->toBe(TissGuideStatus::Batched);

        $response = $this->get(route('panel.financial.billing.index', ['tab' => 'batches']))->assertOk();
        $row      = bcxRows($response, 'batches')[$batch->id];

        expect($row['claims_count'])->toBe(1)
            ->and($row['included_count'])->toBe(1)
            ->and($row['pending_count'])->toBe(0);

        // O envio segue só com a guia que ficou.
        expect(app(BillingService::class)->submitBatch($batch->fresh())->status)->toBe(BillingBatchStatus::Submitted)
            ->and($cancelled->fresh()->status)->toBe(BillingClaimStatus::Cancelled)
            ->and($kept->fresh()->status)->toBe(BillingClaimStatus::Submitted);
    });

    it('guia com pendência (fora do lote TISS) de lote em rascunho: cancela sem mexer no lote TISS', function (): void {
        $schedule = createBillableSchedule($this->entity);
        $batch    = bcxBatch($schedule, ['clinical_indication' => null]);
        $claim    = bcxClaimOf($schedule);

        expect($batch->tissBatch->guides_count)->toBe(0);

        bcxCancelClaim($claim)->assertRedirect()->assertSessionHasNoErrors();

        expect($claim->fresh()->status)->toBe(BillingClaimStatus::Cancelled)
            ->and($claim->fresh()->tissGuide->status)->toBe(TissGuideStatus::Cancelled)
            ->and($batch->fresh()->total_claims)->toBe(0)
            ->and($batch->tissBatch->fresh()->status)->toBe(TissBatchStatus::Open);
    });

    it('lote particular: recalcula pela soma das guias ativas; lote só com canceladas não é "cobrado"', function (): void {
        $first  = createParticularSchedule($this->entity);
        $second = createBillableSchedule($this->entity, ['covenant_id' => $first->covenant_id]);
        $batch  = bcxBatch($first, ['unit_price' => 180, 'clinical_indication' => null]);

        expect($batch->tiss_batch_id)->toBeNull()
            ->and((float) $batch->total_amount)->toBe(360.0);

        bcxCancelClaim(bcxClaimOf($first))->assertSessionHasNoErrors();

        expect($batch->fresh()->total_claims)->toBe(1)
            ->and((float) $batch->fresh()->total_amount)->toBe(180.0);

        bcxCancelClaim(bcxClaimOf($second))->assertSessionHasNoErrors();

        expect(fn () => app(BillingService::class)->submitBatch($batch->fresh()))
            ->toThrow(fn (ValidationException $e) => expect($e->errors()['batch'][0])->toBe(__('financial_billing.errors.batch_empty')));

        expect($batch->fresh()->status)->toBe(BillingBatchStatus::Draft);
    });

    it('só rascunho: guia enviada, paga ou glosada é recusada com mensagem traduzida (sem 500)', function (): void {
        $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);

        foreach ([BillingClaimStatus::Submitted, BillingClaimStatus::Paid, BillingClaimStatus::Denied] as $status) {
            $claim = bcxRawClaim($this->entity, $covenant, ['status' => $status->value]);

            bcxCancelClaim($claim)->assertRedirect()->assertSessionHasErrors([
                'status' => __('financial_billing.errors.claim_cancel_not_draft', ['code' => $claim->code, 'status' => mb_strtolower($status->label())]),
            ]);

            expect($claim->fresh()->status)->toBe($status)
                ->and($claim->fresh()->cancel_reason)->toBeNull();
        }
    });

    it('guia em lote já enviado não sai dele', function (): void {
        $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
        $batch    = bcxRawBatch($this->entity, $covenant, ['status' => BillingBatchStatus::Submitted->value]);
        $claim    = bcxRawClaim($this->entity, $covenant, ['batch_id' => $batch->id]);

        bcxCancelClaim($claim)->assertSessionHasErrors([
            'status' => __('financial_billing.errors.claim_cancel_batch_not_draft', ['code' => $claim->code, 'batch' => $batch->code]),
        ]);

        expect($claim->fresh()->status)->toBe(BillingClaimStatus::Draft);
    });

    it('guia de lote legado (TISS sem lote TISS: o XML sai com todas as guias) pede para cancelar o lote inteiro', function (): void {
        $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'ans_registry' => '326305']);
        $batch    = bcxRawBatch($this->entity, $covenant);
        $claim    = bcxRawClaim($this->entity, $covenant, ['batch_id' => $batch->id]);

        bcxCancelClaim($claim)->assertSessionHasErrors([
            'status' => __('financial_billing.errors.claim_cancel_legacy_batch', ['code' => $claim->code, 'batch' => $batch->code]),
        ]);

        $rows = bcxRows($this->get(route('panel.financial.billing.index', ['tab' => 'claims'])), 'claims');

        expect($rows[$claim->id]['allowed_actions'])->not->toContain('cancel')
            ->and($claim->fresh()->status)->toBe(BillingClaimStatus::Draft);

        // O lote inteiro pode.
        bcxCancelBatch($batch)->assertSessionHasNoErrors();
        expect($claim->fresh()->status)->toBe(BillingClaimStatus::Cancelled);
    });

    it('guia TISS já enviada à operadora (lote de faturamento ainda em rascunho) não é cancelada, nem o lote', function (): void {
        $schedule = createBillableSchedule($this->entity);
        $batch    = bcxBatch($schedule);
        $claim    = bcxClaimOf($schedule);

        // Envio TISS concluído, mas a transação final do envio não gravou o lote (queda no meio).
        TissBatch::query()->whereKey($batch->tiss_batch_id)->update(['status' => TissBatchStatus::Sent->value]);
        TissGuide::query()->whereKey($claim->tiss_guide_id)->update(['status' => TissGuideStatus::Sent->value]);

        bcxCancelClaim($claim)->assertSessionHasErrors([
            'status' => __('financial_billing.errors.claim_tiss_already_sent', ['code' => $claim->code]),
        ]);
        bcxCancelBatch($batch)->assertSessionHasErrors([
            'batch' => __('financial_billing.errors.batch_tiss_already_sent', ['code' => $batch->code]),
        ]);

        $response = $this->get(route('panel.financial.billing.index', ['tab' => 'claims']))->assertOk();

        expect(bcxRows($response, 'claims')[$claim->id]['allowed_actions'])->not->toContain('cancel')
            ->and(bcxRows($response, 'batches')[$batch->id]['allowed_actions'])->not->toContain('cancel')
            ->and($claim->fresh()->status)->toBe(BillingClaimStatus::Draft)
            ->and($batch->fresh()->status)->toBe(BillingBatchStatus::Draft);
    });

    it('idempotência: cancelar de novo devolve 422 traduzido (Inertia e JSON), sem 500 e sem regravar o motivo', function (): void {
        $claim = bcxIndividual(createBillableSchedule($this->entity));

        bcxCancelClaim($claim)->assertSessionHasNoErrors();
        $cancelledAt = $claim->fresh()->cancelled_at;

        $this->travel(5)->minutes();

        bcxCancelClaim($claim, 'Segundo clique no mesmo botão')->assertSessionHasErrors([
            'status' => __('financial_billing.errors.claim_already_cancelled', ['code' => $claim->code]),
        ]);

        $this->postJson(route('panel.financial.billing.claims.cancel', $claim->id), ['reason' => 'Retry automático da tela'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', __('financial_billing.errors.claim_already_cancelled', ['code' => $claim->code]));

        expect($claim->fresh()->cancel_reason)->toBe(BCX_REASON)
            ->and($claim->fresh()->cancelled_at->equalTo($cancelledAt))->toBeTrue();
    });

    it('motivo obrigatório: ausente, curto (< 10) ou longo (> 1000) → 422 no campo, nada muda', function (): void {
        $claim = bcxIndividual(createBillableSchedule($this->entity));

        bcxCancelClaim($claim, null)->assertSessionHasErrors(['reason' => __('financial_billing.validation.reason_required')]);
        bcxCancelClaim($claim, '   curto   ')->assertSessionHasErrors(['reason' => __('financial_billing.validation.reason_min', ['min' => 10])]);
        bcxCancelClaim($claim, str_repeat('x', 1001))->assertSessionHasErrors(['reason' => __('financial_billing.validation.reason_max', ['max' => 1000])]);

        expect($claim->fresh()->status)->toBe(BillingClaimStatus::Draft);
    });

    it('guia de outra clínica → 404 (não confirma que existe); nada muda', function (): void {
        $other    = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $covenant = Covenant::factory()->create(['entity_id' => $other->id, 'active' => true]);
        $claim    = bcxRawClaim($other, $covenant);

        bcxCancelClaim($claim)->assertNotFound();

        expect($claim->fresh()->status)->toBe(BillingClaimStatus::Draft);
    });

    it('perfil sem permissão financeira (médico) → 403', function (): void {
        $claim      = bcxIndividual(createBillableSchedule($this->entity));
        $entityUser = createDoctorForEntity($this->entity)->entityUser;

        $this->actingAs($entityUser->user)
            ->withSession(panelSession($entityUser))
            ->post(route('panel.financial.billing.claims.cancel', $claim->id), ['reason' => BCX_REASON])
            ->assertForbidden();

        expect($claim->fresh()->status)->toBe(BillingClaimStatus::Draft);
    });
});

describe('cancelar lote', function (): void {
    it('lote TISS em rascunho: cancela as guias (anexadas e com pendência), as guias TISS e o lote TISS; totais a zero', function (): void {
        $attached = createBillableSchedule($this->entity);
        $pending  = createBillableSchedule($this->entity, ['covenant_id' => $attached->covenant_id]);
        // Sem carteirinha: a pré-validação barra e a guia fica fora do lote TISS.
        $pending->patient->update(['card_number' => null]);

        $batch = bcxBatch($attached);

        expect($batch->tissBatch->guides_count)->toBe(1)
            ->and($batch->total_claims)->toBe(2);

        bcxCancelBatch($batch)
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('financial_billing.flash.batch_cancelled', ['code' => $batch->code]));

        $batch->refresh();

        expect($batch->status)->toBe(BillingBatchStatus::Cancelled)
            ->and($batch->cancel_reason)->toBe(BCX_REASON)
            ->and($batch->cancelled_by)->toBe($this->entityUser->user_id)
            ->and($batch->cancelled_at)->not->toBeNull()
            ->and($batch->total_claims)->toBe(0)
            ->and((float) $batch->total_amount)->toBe(0.0)
            ->and($batch->tissBatch->status)->toBe(TissBatchStatus::Cancelled);

        foreach ([$attached, $pending] as $schedule) {
            $claim = bcxClaimOf($schedule);

            expect($claim->status)->toBe(BillingClaimStatus::Cancelled)
                ->and($claim->cancel_reason)->toBe(BCX_REASON)
                ->and($claim->batch_id)->toBe($batch->id)
                ->and($claim->tissGuide->status)->toBe(TissGuideStatus::Cancelled);
        }

        expect(bcxEligibleIds($this->entity))->toContain($attached->id)->toContain($pending->id);

        $response = $this->get(route('panel.financial.billing.index', ['tab' => 'batches']))->assertOk();
        $row      = bcxRows($response, 'batches')[$batch->id];

        expect($row['allowed_actions'])->toBe([])
            ->and($row['included_count'])->toBe(0)
            ->and($row['pending_count'])->toBe(0)
            ->and($row['cancel_reason'])->toBe(BCX_REASON)
            ->and($row['cancelled_by_name'])->toBe($this->entityUser->user->name);

        // Download do XML de lote cancelado: recusado com aviso (sem gerar nada).
        $this->get(route('panel.financial.billing.batches.xml', $batch->id))
            ->assertRedirect()
            ->assertSessionHas('error', __('financial_billing.errors.batch_xml_cancelled', ['code' => $batch->code]));
    });

    it('lote particular em rascunho também cancela; de novo → 422 traduzido', function (): void {
        $batch = bcxBatch(createParticularSchedule($this->entity), ['clinical_indication' => null]);

        bcxCancelBatch($batch)->assertSessionHasNoErrors();
        expect($batch->fresh()->status)->toBe(BillingBatchStatus::Cancelled);

        bcxCancelBatch($batch, 'Segundo clique no mesmo botão')->assertSessionHasErrors([
            'batch' => __('financial_billing.errors.batch_already_cancelled', ['code' => $batch->code]),
        ]);

        $this->postJson(route('panel.financial.billing.batches.cancel', $batch->id), ['reason' => 'Retry automático da tela'])
            ->assertStatus(422)
            ->assertJsonPath('errors.batch.0', __('financial_billing.errors.batch_already_cancelled', ['code' => $batch->code]));
    });

    it('só rascunho: lote enviado → 422; outra clínica → 404; sem motivo → 422', function (): void {
        $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
        $sent     = bcxRawBatch($this->entity, $covenant, ['status' => BillingBatchStatus::Submitted->value]);

        bcxCancelBatch($sent)->assertSessionHasErrors([
            'batch' => __('financial_billing.errors.batch_cancel_not_draft', ['code' => $sent->code, 'status' => mb_strtolower(BillingBatchStatus::Submitted->label())]),
        ]);

        $other      = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $theirBatch = bcxRawBatch($other, Covenant::factory()->create(['entity_id' => $other->id, 'active' => true]));

        bcxCancelBatch($theirBatch)->assertNotFound();
        bcxCancelBatch(bcxRawBatch($this->entity, $covenant), null)->assertSessionHasErrors('reason');

        expect($sent->fresh()->status)->toBe(BillingBatchStatus::Submitted)
            ->and($theirBatch->fresh()->status)->toBe(BillingBatchStatus::Draft);
    });

    it('lote em rascunho com guia que já andou (dado inconsistente) não é cancelado pela metade', function (): void {
        $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
        $batch    = bcxRawBatch($this->entity, $covenant);
        $draft    = bcxRawClaim($this->entity, $covenant, ['batch_id' => $batch->id]);
        bcxRawClaim($this->entity, $covenant, ['batch_id' => $batch->id, 'status' => BillingClaimStatus::Paid->value]);

        bcxCancelBatch($batch)->assertSessionHasErrors([
            'batch' => __('financial_billing.errors.batch_cancel_has_settled_claims', ['code' => $batch->code]),
        ]);

        expect($batch->fresh()->status)->toBe(BillingBatchStatus::Draft)
            ->and($draft->fresh()->status)->toBe(BillingClaimStatus::Draft);
    });
});

describe('allowed_actions refletem as regras do servidor', function (): void {
    it('cancel só em rascunho (e em lote ainda em rascunho); cancelada não oferece nada', function (): void {
        $covenant  = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
        $draftLot  = bcxRawBatch($this->entity, $covenant);
        $sentLot   = bcxRawBatch($this->entity, $covenant, ['status' => BillingBatchStatus::Submitted->value]);
        $inDraft   = bcxRawClaim($this->entity, $covenant, ['batch_id' => $draftLot->id]);
        $inSent    = bcxRawClaim($this->entity, $covenant, ['batch_id' => $sentLot->id, 'status' => BillingClaimStatus::Submitted->value]);
        $cancelled = bcxRawClaim($this->entity, $covenant, ['status' => BillingClaimStatus::Cancelled->value]);

        $response = $this->get(route('panel.financial.billing.index', ['tab' => 'claims']))->assertOk();
        $claims   = bcxRows($response, 'claims');
        $batches  = bcxRows($response, 'batches');

        expect($claims[$inDraft->id]['allowed_actions'])->toBe(['cancel'])
            ->and($claims[$inSent->id]['allowed_actions'])->toBe(['pay', 'deny'])
            ->and($claims[$cancelled->id]['allowed_actions'])->toBe([])
            ->and($claims[$inDraft->id]['cancel_url'])->toBe(route('panel.financial.billing.claims.cancel', $inDraft->id))
            ->and($batches[$draftLot->id]['allowed_actions'])->toBe(['submit', 'cancel'])
            // Fase 4 (parte 2): lote enviado com guia em aberto oferece só "Registrar recebimento do lote".
            ->and($batches[$sentLot->id]['allowed_actions'])->toBe(['receive'])
            ->and($batches[$draftLot->id]['cancel_url'])->toBe(route('panel.financial.billing.batches.cancel', $draftLot->id));
    });
});
