<?php

declare(strict_types=1);

use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus};
use App\Domains\Tiss\Models\{TissBatch, TissBatchGuide, TissGuide};
use App\Enums\{BillingBatchStatus, BillingClaimStatus};
use App\Models\{BillingBatch, BillingClaim, Covenant, Entity, Schedule};
use App\Services\Financial\{BillingBatchAttachService, BillingService};
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Fase 4 (parte 2) — guias TISS fora do lote TISS entram num lote de
 * faturamento em RASCUNHO: "Adicionar guias" (lote), "Incluir em lote" (guia)
 * e "Reprocessar pendentes". Cada guia passa pela pré-validação: a que passa
 * entra no lote TISS e muda de lote; a que falha fica onde estava com os
 * erros gravados, sem derrubar as outras. Guia/lote fora da regra: 422 com o
 * código e o motivo, nada gravado. Locks/concorrência: BillingBulkLockTest.
 */

beforeEach(function (): void {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

/** Lote com os atendidos de hoje do convênio do agendamento; sem CID por padrão => guias pendentes. */
function bbaBatch(Schedule $schedule, array $overrides = []): BillingBatch
{
    return app(BillingService::class)->createBatch(array_merge([
        'covenant_id' => $schedule->covenant_id,
        'date_from'   => now()->subDay()->toDateString(),
        'date_until'  => now()->toDateString(),
        'unit_price'  => 150,
    ], $overrides));
}

/** Guia TISS individual (com CID por padrão: passa na pré-validação). */
function bbaIndividual(Schedule $schedule, array $overrides = []): BillingClaim
{
    return app(BillingService::class)->createIndividual(array_merge([
        'schedule_id'         => $schedule->id,
        'unit_price'          => 150,
        'clinical_indication' => 'H40.1',
    ], $overrides));
}

function bbaClaimOf(Schedule $schedule): BillingClaim
{
    return BillingClaim::query()->where('schedule_id', $schedule->id)->latest('created_at')->firstOrFail();
}

/** @param list<string> $ids */
function bbaAttach(BillingBatch $batch, array $ids): TestResponse
{
    return test()->postJson(route('panel.financial.billing.batches.attach-claims', $batch->id), ['claim_ids' => $ids]);
}

/** @return array<string, mixed> linhas da aba (paginator) indexadas por id */
function bbaRows(string $prop): array
{
    $tab = $prop === 'batches' ? 'batches' : 'claims';

    return collect(test()->get(route('panel.financial.billing.index', ['tab' => $tab]))->assertOk()->viewData('page')['props'][$prop]['data'])
        ->keyBy('id')
        ->all();
}

/** CID corrigido direto na guia TISS (a pré-validação passa a aceitar). */
function bbaFixCid(BillingClaim $claim): void
{
    TissGuide::query()->whereKey($claim->tiss_guide_id)->update(['clinical_indication' => 'H40.1']);
}

describe('adicionar guias ao lote', function (): void {
    it('pré-validação por guia: as que passam entram no lote TISS e mudam de lote; a que falha fica de fora com os erros gravados', function (): void {
        $s1   = createBillableSchedule($this->entity);
        $lotA = bbaBatch($s1);                                   // sem CID: c1 pendente em A
        $c1   = bbaClaimOf($s1);
        $s2   = createBillableSchedule($this->entity, ['covenant_id' => $s1->covenant_id]);
        $lotB = bbaBatch($s2, ['clinical_indication' => 'H40.1']); // c2 no lote TISS de B
        $s3   = createBillableSchedule($this->entity, ['covenant_id' => $s1->covenant_id, 'date_time' => now()->subDays(10)]);
        $c3   = bbaIndividual($s3);                               // individual, passa
        $c4   = bbaIndividual(createBillableSchedule($this->entity, ['covenant_id' => $s1->covenant_id]), ['clinical_indication' => null]);

        bbaFixCid($c1);

        $response = bbaAttach($lotB, [$c1->id, $c3->id, $c4->id])
            ->assertOk()
            ->assertJsonPath('attached_count', 2)
            ->assertJsonPath('pending_count', 1)
            ->assertJsonPath('message', __('financial_billing.attach_result.partial', ['attached' => 2, 'pending' => 1, 'batch' => $lotB->code]));

        $results = collect($response->json('results'))->keyBy('claim_id');

        expect($results[$c1->id]['attached'])->toBeTrue()
            ->and($results[$c3->id]['attached'])->toBeTrue()
            ->and($results[$c4->id]['attached'])->toBeFalse()
            ->and($results[$c4->id]['code'])->toBe($c4->code)
            ->and($results[$c4->id]['validation']['passes'])->toBeFalse()
            ->and(collect($results[$c4->id]['validation']['errors'])->pluck('code')->all())->toContain('CID_REQUIRED')
            ->and($results[$c4->id]['validation']['summary'])->toBe(__('financial_billing.pre_validation.errors'));

        // Movidas para B; a que falhou continua individual, com o erro gravado.
        expect($c1->fresh()->batch_id)->toBe($lotB->id)
            ->and($c3->fresh()->batch_id)->toBe($lotB->id)
            ->and($c4->fresh()->batch_id)->toBeNull()
            ->and(collect(TissGuide::query()->findOrFail($c4->tiss_guide_id)->errors)->pluck('code')->all())->toContain('CID_REQUIRED')
            ->and(TissGuide::query()->findOrFail($c1->tiss_guide_id)->status)->toBe(TissGuideStatus::Batched)
            ->and(TissGuide::query()->findOrFail($c4->tiss_guide_id)->status)->toBe(TissGuideStatus::Draft)
            ->and(TissBatchGuide::query()->where('batch_id', $lotB->tiss_batch_id)->count())->toBe(3);

        // Totais: B com 3 guias (R$ 450 no lote TISS) e o período cobrindo o
        // atendimento de 10 dias atrás; A perdeu a guia (sem XML guardado).
        $lotB->refresh();
        $lotA->refresh();

        expect($lotB->total_claims)->toBe(3)
            ->and((float) $lotB->total_amount)->toBe(450.0)
            ->and(TissBatch::query()->findOrFail($lotB->tiss_batch_id)->guides_count)->toBe(3)
            ->and($lotB->period_start->toDateString())->toBe($s3->date_time->toDateString())
            ->and($lotA->total_claims)->toBe(0)
            ->and((float) $lotA->total_amount)->toBe(0.0)
            ->and($lotA->xml_path)->toBeNull();
    });

    it('lista as elegíveis do lote (JSON): individuais, pendentes deste e de outro lote em rascunho — sem outro convênio, outra clínica, guia já no lote TISS ou paga', function (): void {
        $s1           = createBillableSchedule($this->entity);
        $lot          = bbaBatch($s1);                              // c1 pendente neste lote
        $pendingIn    = bbaClaimOf($s1);
        $s2           = createBillableSchedule($this->entity, ['covenant_id' => $s1->covenant_id]);
        $other        = bbaBatch($s2);                              // c2 pendente em outro lote
        $pendingOther = bbaClaimOf($s2);
        $s3           = createBillableSchedule($this->entity, ['covenant_id' => $s1->covenant_id]);
        $inTiss       = bbaBatch($s3, ['clinical_indication' => 'H40.1']);
        $individual   = bbaIndividual(createBillableSchedule($this->entity, ['covenant_id' => $s1->covenant_id]));
        $paid         = bbaIndividual(createBillableSchedule($this->entity, ['covenant_id' => $s1->covenant_id]));
        app(BillingService::class)->markClaimPaid($paid);
        $otherCovenant = bbaIndividual(createBillableSchedule($this->entity));

        $row = bbaRows('batches')[$lot->id];

        expect($row['allowed_actions'])->toContain('add_claims')->toContain('reprocess_pending')
            ->and($row['attachable_claims_url'])->toBe(route('panel.financial.billing.batches.attachable-claims', $lot->id))
            ->and($row['attach_claims_url'])->toBe(route('panel.financial.billing.batches.attach-claims', $lot->id));

        $response = $this->getJson($row['attachable_claims_url'])
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('max', BillingBatchAttachService::MAX_CLAIMS);

        $data = collect($response->json('data'))->keyBy('id');

        expect($data->keys()->all())->toEqualCanonicalizing([$pendingIn->id, $pendingOther->id, $individual->id])
            ->and($data[$pendingIn->id]['origin'])->toBe('this')
            ->and($data[$pendingIn->id]['has_errors'])->toBeTrue()
            ->and($data[$pendingOther->id]['origin'])->toBe('other')
            ->and($data[$pendingOther->id]['origin_batch_code'])->toBe($other->code)
            ->and($data[$individual->id]['origin'])->toBe('individual')
            ->and($data[$individual->id]['patient_name'])->toBe($individual->patient->person->full_name)
            ->and($data->keys()->all())->not->toContain(bbaClaimOf($s3)->id, $paid->id, $otherCovenant->id);

        // Lote com o lote TISS inteiro (sem pendência): só "Adicionar guias".
        expect(bbaRows('batches')[$inTiss->id]['allowed_actions'])->toContain('add_claims')->not->toContain('reprocess_pending');
    });
});

describe('reprocessar pendentes', function (): void {
    it('refaz a pré-validação das pendências do lote e anexa as que passaram; sem pendência → 422 traduzido', function (): void {
        $s1  = createBillableSchedule($this->entity);
        $s2  = createBillableSchedule($this->entity, ['covenant_id' => $s1->covenant_id]);
        $lot = bbaBatch($s1);                                     // sem CID: as duas ficam pendentes
        $c1  = bbaClaimOf($s1);
        $c2  = bbaClaimOf($s2);

        $row = bbaRows('batches')[$lot->id];

        expect($row['pending_count'])->toBe(2)
            ->and($row['reprocess_url'])->toBe(route('panel.financial.billing.batches.reprocess-pending', $lot->id));

        bbaFixCid($c1);

        $this->postJson($row['reprocess_url'])
            ->assertOk()
            ->assertJsonPath('attached_count', 1)
            ->assertJsonPath('pending_count', 1)
            ->assertJsonPath('remaining', 0);

        expect(TissBatch::query()->findOrFail($lot->tiss_batch_id)->guides_count)->toBe(1)
            ->and((float) $lot->fresh()->total_amount)->toBe(150.0)
            ->and($lot->fresh()->total_claims)->toBe(2)
            ->and(TissGuide::query()->findOrFail($c1->tiss_guide_id)->status)->toBe(TissGuideStatus::Batched)
            ->and(collect(TissGuide::query()->findOrFail($c2->tiss_guide_id)->errors)->pluck('code')->all())->toContain('CID_REQUIRED');

        bbaFixCid($c2);

        $this->postJson($row['reprocess_url'])
            ->assertOk()
            ->assertJsonPath('attached_count', 1)
            ->assertJsonPath('message', __('financial_billing.attach_result.all', ['count' => 1, 'batch' => $lot->code]));

        expect(bbaRows('batches')[$lot->id]['allowed_actions'])->not->toContain('reprocess_pending');

        $this->postJson($row['reprocess_url'])
            ->assertStatus(422)
            ->assertJsonPath('errors.batch.0', __('financial_billing.errors.reprocess_nothing_pending', ['code' => $lot->code]));
    });
});

describe('incluir em lote (guia)', function (): void {
    it('guia individual lista os lotes em rascunho do convênio e entra no escolhido; convênio sem lote não oferece a ação', function (): void {
        $s1     = createBillableSchedule($this->entity);
        $lot    = bbaBatch($s1, ['clinical_indication' => 'H40.1']);
        $claim  = bbaIndividual(createBillableSchedule($this->entity, ['covenant_id' => $s1->covenant_id]));
        $lonely = bbaIndividual(createBillableSchedule($this->entity));

        $rows = bbaRows('claims');

        expect($rows[$claim->id]['allowed_actions'])->toContain('attach')
            ->and($rows[$claim->id]['attach_targets_url'])->toBe(route('panel.financial.billing.claims.attach-targets', $claim->id))
            ->and($rows[$lonely->id]['allowed_actions'])->not->toContain('attach')
            ->and($rows[$lonely->id]['attach_targets_url'])->toBeNull();

        $targets = $this->getJson($rows[$claim->id]['attach_targets_url'])->assertOk()->json('data');

        expect(collect($targets)->pluck('id')->all())->toBe([$lot->id])
            ->and($targets[0]['attach_url'])->toBe(route('panel.financial.billing.batches.attach-claims', $lot->id))
            ->and($targets[0]['is_current'])->toBeFalse()
            ->and($targets[0]['claims_count'])->toBe(1);

        bbaAttach($lot, [$claim->id])
            ->assertOk()
            ->assertJsonPath('attached_count', 1)
            ->assertJsonPath('message', __('financial_billing.attach_result.all', ['count' => 1, 'batch' => $lot->code]));

        $after = bbaRows('claims')[$claim->id];

        expect($claim->fresh()->batch_id)->toBe($lot->id)
            ->and($after['allowed_actions'])->not->toContain('attach')
            ->and($after['out_of_batch'])->toBeFalse()
            ->and($after['batch_code'])->toBe($lot->code);
    });

    it('guia que não entra em lote (já no lote TISS) → lotes de destino 422 traduzido', function (): void {
        $s1 = createBillableSchedule($this->entity);
        bbaBatch($s1, ['clinical_indication' => 'H40.1']);
        $attached = bbaClaimOf($s1);

        $this->getJson(route('panel.financial.billing.claims.attach-targets', $attached->id))
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', __('financial_billing.errors.attach_claim_in_tiss_batch', ['code' => $attached->code]));
    });
});

describe('recusas: 422/404/403 e nada gravado', function (): void {
    it('guia de outro convênio no pedido: 422 com código e motivo e nenhuma guia entra (tudo ou nada)', function (): void {
        $s1    = createBillableSchedule($this->entity);
        $lot   = bbaBatch($s1, ['clinical_indication' => 'H40.1']);
        $ok    = bbaIndividual(createBillableSchedule($this->entity, ['covenant_id' => $s1->covenant_id]));
        $other = bbaIndividual(createBillableSchedule($this->entity));

        $errors = bbaAttach($lot, [$ok->id, $other->id])->assertStatus(422)->json('errors');

        expect($errors["claims.{$other->id}"][0])->toBe(__('financial_billing.errors.attach_other_covenant', ['code' => $other->code, 'target' => $lot->code]))
            ->and($errors)->not->toHaveKey("claims.{$ok->id}")
            ->and($ok->fresh()->batch_id)->toBeNull()
            ->and(TissBatchGuide::query()->where('guide_id', $ok->tiss_guide_id)->exists())->toBeFalse()
            ->and(TissGuide::query()->findOrFail($ok->tiss_guide_id)->status)->toBe(TissGuideStatus::Draft);
    });

    it('guia fora da regra: já no lote TISS, paga (não rascunho) ou cancelada → 422 por guia', function (): void {
        $s1       = createBillableSchedule($this->entity);
        $lot      = bbaBatch($s1, ['clinical_indication' => 'H40.1']);
        $attached = bbaClaimOf($s1);
        $paid     = bbaIndividual(createBillableSchedule($this->entity, ['covenant_id' => $s1->covenant_id]));
        app(BillingService::class)->markClaimPaid($paid);
        $cancelled = bbaIndividual(createBillableSchedule($this->entity, ['covenant_id' => $s1->covenant_id]));
        $cancelled->update(['status' => BillingClaimStatus::Cancelled->value]);

        $errors = bbaAttach($lot, [$attached->id, $paid->id, $cancelled->id])->assertStatus(422)->json('errors');

        expect($errors["claims.{$attached->id}"][0])->toBe(__('financial_billing.errors.attach_claim_in_tiss_batch', ['code' => $attached->code]))
            ->and($errors["claims.{$paid->id}"][0])->toBe(__('financial_billing.errors.attach_claim_not_draft', ['code' => $paid->code, 'status' => mb_strtolower(BillingClaimStatus::Paid->label())]))
            ->and($errors["claims.{$cancelled->id}"][0])->toBe(__('financial_billing.errors.claim_cancelled', ['code' => $cancelled->code]))
            ->and($paid->fresh()->batch_id)->toBeNull();
    });

    it('lote que não recebe guia: enviado, particular ou com o lote TISS já enviado → 422 traduzido', function (): void {
        $s1   = createBillableSchedule($this->entity);
        $sent = bbaBatch($s1, ['clinical_indication' => 'H40.1']);
        $sent->update(['status' => BillingBatchStatus::Submitted->value]);
        $claim = bbaIndividual(createBillableSchedule($this->entity, ['covenant_id' => $s1->covenant_id]));

        bbaAttach($sent, [$claim->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.batch.0', __('financial_billing.errors.attach_batch_not_draft', ['code' => $sent->code, 'status' => mb_strtolower(BillingBatchStatus::Submitted->label())]));

        $s2       = createBillableSchedule($this->entity, ['covenant_id' => $s1->covenant_id]);
        $tissSent = bbaBatch($s2, ['clinical_indication' => 'H40.1']);
        TissBatch::query()->whereKey($tissSent->tiss_batch_id)->update(['status' => TissBatchStatus::Sent->value]);

        bbaAttach($tissSent, [$claim->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.batch.0', __('financial_billing.errors.attach_batch_tiss_sent', ['code' => $tissSent->code]));

        $particular = bbaBatch(createParticularSchedule($this->entity));

        bbaAttach($particular, [$claim->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.batch.0', __('financial_billing.errors.attach_batch_not_tiss', ['code' => $particular->code]));

        expect($claim->fresh()->batch_id)->toBeNull();
    });

    it('guia de outra clínica → 422 genérico (sem dizer qual); lote de outra clínica → 404; sem permissão → 403', function (): void {
        $s1   = createBillableSchedule($this->entity);
        $lot  = bbaBatch($s1, ['clinical_indication' => 'H40.1']);
        $mine = bbaIndividual(createBillableSchedule($this->entity, ['covenant_id' => $s1->covenant_id]));

        $other         = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $theirCovenant = Covenant::factory()->create(['entity_id' => $other->id, 'active' => true]);
        $theirClaim    = BillingClaim::query()->create([
            'entity_id'       => $other->id, 'covenant_id' => $theirCovenant->id, 'status' => BillingClaimStatus::Draft->value,
            'attendance_date' => now()->toDateString(), 'amount' => 100, 'quantity' => 1, 'unit_price' => 100,
        ]);
        $theirBatch = BillingBatch::query()->create([
            'entity_id'    => $other->id, 'covenant_id' => $theirCovenant->id, 'status' => BillingBatchStatus::Draft->value,
            'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
        ]);

        bbaAttach($lot, [$mine->id, $theirClaim->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.claim_ids.0', __('financial_billing.errors.attach_claims_not_found'));

        bbaAttach($theirBatch, [$mine->id])->assertNotFound();
        $this->getJson(route('panel.financial.billing.batches.attachable-claims', $theirBatch->id))->assertNotFound();
        $this->getJson(route('panel.financial.billing.claims.attach-targets', $theirClaim->id))->assertNotFound();

        $doctor = createDoctorForEntity($this->entity)->entityUser;

        $this->actingAs($doctor->user)
            ->withSession(panelSession($doctor))
            ->postJson(route('panel.financial.billing.batches.attach-claims', $lot->id), ['claim_ids' => [$mine->id]])
            ->assertForbidden();

        expect($mine->fresh()->batch_id)->toBeNull();
    });

    it('teto de guias por requisição e ids malformados → 422 sem tocar no banco', function (): void {
        $lot = bbaBatch(createBillableSchedule($this->entity), ['clinical_indication' => 'H40.1']);
        $ids = collect(range(1, BillingBatchAttachService::MAX_CLAIMS + 1))->map(fn () => (string) Str::uuid())->all();

        bbaAttach($lot, $ids)
            ->assertStatus(422)
            ->assertJsonPath('errors.claim_ids.0', __('financial_billing.validation.claims_max', ['max' => BillingBatchAttachService::MAX_CLAIMS]));

        bbaAttach($lot, ['nao-e-uuid'])->assertStatus(422)->assertJsonValidationErrors(['claim_ids.0']);
        bbaAttach($lot, [])->assertStatus(422)->assertJsonPath('errors.claim_ids.0', __('financial_billing.validation.claims_required'));
    });
});
