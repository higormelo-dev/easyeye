<?php

declare(strict_types=1);

use App\Domains\Tiss\Models\TissGlosa;
use App\Enums\{BillingBatchStatus, BillingClaimStatus, FinancialEntryStatus, FinancialEntryType, PaymentMethod};
use App\Models\{BillingBatch, BillingClaim, CashClose, Covenant, Entity, FinancialCashEntry};
use App\Services\Financial\BillingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * Integridade do faturamento (Fase 2): recebimento com valor líquido de glosa,
 * máquina de estados da guia/lote no servidor, caixa fechado, allowed_actions.
 * Cada caso falha no código anterior (ver comentário "Antes:").
 */

beforeEach(function (): void {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
});

function billingIntegrityClaim(Entity $entity, Covenant $covenant, array $overrides = []): BillingClaim
{
    return BillingClaim::query()->create(array_merge([
        'entity_id'       => $entity->id,
        'covenant_id'     => $covenant->id,
        'status'          => BillingClaimStatus::Submitted->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => 200.00,
        'quantity'        => 1,
        'unit_price'      => 200.00,
    ], $overrides));
}

function billingIntegrityBatch(Entity $entity, Covenant $covenant, BillingBatchStatus $status = BillingBatchStatus::Draft): BillingBatch
{
    return BillingBatch::query()->create([
        'entity_id'    => $entity->id,
        'covenant_id'  => $covenant->id,
        'status'       => $status->value,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end'   => now()->toDateString(),
        'issued_at'    => now(),
    ]);
}

describe('registrar recebimento', function (): void {
    it('usa valor da guia menos a glosa como padrão do recebimento e do lançamento de caixa', function (): void {
        $claim = billingIntegrityClaim($this->entity, $this->covenant);

        $this->post(route('panel.financial.billing.claims.denied', $claim->id), ['glosa_amount' => 50])
            ->assertSessionHasNoErrors();

        $this->post(route('panel.financial.billing.claims.paid', $claim->id))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $claim->refresh();
        $entry = FinancialCashEntry::query()->where('billing_claim_id', $claim->id)->firstOrFail();

        // Antes: 200,00 (valor cheio) no caixa, mesmo com R$ 50 glosados.
        expect($claim->status)->toBe(BillingClaimStatus::Paid)
            ->and((float) $claim->paid_amount)->toBe(150.0)
            ->and((float) $claim->glosa_amount)->toBe(50.0)
            ->and((float) $entry->amount)->toBe(150.0);
    });

    it('lança no caixa o valor, a data e a forma de pagamento informados', function (): void {
        $claim    = billingIntegrityClaim($this->entity, $this->covenant);
        $received = now()->subDays(3)->toDateString();

        $this->post(route('panel.financial.billing.claims.paid', $claim->id), [
            'paid_amount'    => 120.5,
            'paid_at'        => $received,
            'payment_method' => PaymentMethod::Cash->value,
            'notes'          => 'Demonstrativo 09/2026',
        ])->assertSessionHasNoErrors();

        $entry = FinancialCashEntry::query()->where('billing_claim_id', $claim->id)->firstOrFail();

        // Antes: entry_date sempre hoje, ignorando a data do recebimento.
        expect($entry->entry_date->toDateString())->toBe($received)
            ->and((float) $entry->amount)->toBe(120.5)
            ->and($entry->payment_method)->toBe(PaymentMethod::Cash)
            ->and($entry->notes)->toBe('Demonstrativo 09/2026')
            ->and($claim->fresh()->paid_at->toDateString())->toBe($received);
    });

    it('recusa forma de pagamento fora da lista', function (): void {
        $claim = billingIntegrityClaim($this->entity, $this->covenant);

        // Antes: qualquer texto ia para o lançamento de caixa.
        $this->post(route('panel.financial.billing.claims.paid', $claim->id), ['payment_method' => 'pix-magico'])
            ->assertSessionHasErrors('payment_method');

        expect($claim->fresh()->status)->toBe(BillingClaimStatus::Submitted)
            ->and(FinancialCashEntry::query()->where('billing_claim_id', $claim->id)->exists())->toBeFalse();
    });

    it('recusa data de recebimento futura', function (): void {
        $claim = billingIntegrityClaim($this->entity, $this->covenant);

        $this->post(route('panel.financial.billing.claims.paid', $claim->id), ['paid_at' => now()->addDays(2)->toDateString()])
            ->assertSessionHasErrors('paid_at');

        expect($claim->fresh()->status)->toBe(BillingClaimStatus::Submitted);
    });

    it('recusa recebimento de valor zero (ex.: glosa total sem valor informado)', function (): void {
        $claim = billingIntegrityClaim($this->entity, $this->covenant);

        $this->post(route('panel.financial.billing.claims.denied', $claim->id), ['glosa_amount' => 200]);

        // Antes: o padrão era o valor cheio (R$ 200 no caixa de uma guia 100% glosada).
        $this->post(route('panel.financial.billing.claims.paid', $claim->id))
            ->assertSessionHasErrors('paid_amount');

        expect($claim->fresh()->status)->toBe(BillingClaimStatus::Denied)
            ->and(FinancialCashEntry::query()->where('billing_claim_id', $claim->id)->exists())->toBeFalse();
    });

    it('não lança recebimento num caixa já fechado', function (): void {
        $claim = billingIntegrityClaim($this->entity, $this->covenant);
        $day   = now()->subDays(5)->toDateString();

        CashClose::query()->create([
            'entity_id'     => $this->entity->id,
            'period_start'  => $day,
            'period_end'    => $day,
            'closed_at'     => now(),
            'total_income'  => 0,
            'total_expense' => 0,
            'balance'       => 0,
        ]);

        // Antes: criava o lançamento sem checar o fechamento de caixa.
        $this->post(route('panel.financial.billing.claims.paid', $claim->id), ['paid_at' => $day])
            ->assertSessionHasErrors('paid_at');

        expect($claim->fresh()->status)->toBe(BillingClaimStatus::Submitted)
            ->and(FinancialCashEntry::query()->where('billing_claim_id', $claim->id)->exists())->toBeFalse();
    });

    it('é idempotente: guia já paga não tem valor regravado nem lançamento duplicado', function (): void {
        $claim   = billingIntegrityClaim($this->entity, $this->covenant);
        $service = app(BillingService::class);

        $service->markClaimPaid($claim, ['paid_amount' => 100]);
        $service->markClaimPaid($claim->fresh(), ['paid_amount' => 180]);

        // Antes: paid_amount virava 180 enquanto o caixa continuava com 100.
        expect((float) $claim->fresh()->paid_amount)->toBe(100.0)
            ->and(FinancialCashEntry::query()->where('billing_claim_id', $claim->id)->count())->toBe(1)
            ->and((float) FinancialCashEntry::query()->where('billing_claim_id', $claim->id)->value('amount'))->toBe(100.0);
    });

    it('bloqueia pagar guia glosada cujo atendimento já foi faturado em outra guia ativa', function (): void {
        $schedule = createBillableSchedule($this->entity);
        $service  = app(BillingService::class);

        $first = $service->createIndividual(['schedule_id' => $schedule->id, 'unit_price' => 150, 'clinical_indication' => 'H40.1']);
        $service->markClaimDenied($first, ['glosa_amount' => 150, 'glosa_code' => '3099']);

        // Glosada libera o agendamento: uma nova guia ativa é criada para ele.
        $service->createIndividual(['schedule_id' => $schedule->id, 'unit_price' => 150, 'clinical_indication' => 'H40.1']);

        // Antes: violava o índice único parcial (SQLSTATE 23505 → erro 500).
        $this->post(route('panel.financial.billing.claims.paid', $first->id), ['paid_amount' => 50])
            ->assertSessionHasErrors('status');

        expect($first->fresh()->status)->toBe(BillingClaimStatus::Denied);
    });

    it('avisa (erro visível) quando a guia já estava paga, em vez de dizer que registrou', function (): void {
        $claim = billingIntegrityClaim($this->entity, $this->covenant);
        app(BillingService::class)->markClaimPaid($claim, ['paid_amount' => 100]);

        // Aba desatualizada / outro usuário: antes o service ignorava (no-op) e
        // o controller respondia "Recebimento registrado", sem gravar nada.
        $this->post(route('panel.financial.billing.claims.paid', $claim->id), ['paid_amount' => 180])
            ->assertSessionHasErrors(['status' => __('financial_billing.errors.claim_already_paid', ['code' => $claim->code])])
            ->assertSessionMissing('success');

        expect((float) $claim->fresh()->paid_amount)->toBe(100.0)
            ->and(FinancialCashEntry::query()->where('billing_claim_id', $claim->id)->count())->toBe(1);
    });

    it('recusa receber guia que já tem receita no caixa (legado: paga e depois glosada)', function (): void {
        $claim = billingIntegrityClaim($this->entity, $this->covenant, [
            'status'       => BillingClaimStatus::Denied->value,
            'glosa_amount' => 50,
        ]);

        // Receita de 200 lançada quando a guia foi paga, antes da glosa da tela antiga.
        FinancialCashEntry::query()->create([
            'entity_id'        => $this->entity->id,
            'covenant_id'      => $this->covenant->id,
            'billing_claim_id' => $claim->id,
            'entry_date'       => now()->subDays(10)->toDateString(),
            'description'      => 'Recebimento de guia',
            'type'             => FinancialEntryType::Income->value,
            'status'           => FinancialEntryStatus::Paid->value,
            'amount'           => 200.00,
            'active'           => true,
        ]);

        // Antes: virava Paga com paid_amount 150 enquanto o caixa seguia com 200.
        $this->post(route('panel.financial.billing.claims.paid', $claim->id))
            ->assertSessionHasErrors(['status' => __('financial_billing.errors.claim_has_cash_entry', ['code' => $claim->code])]);

        $claim->refresh();

        expect($claim->status)->toBe(BillingClaimStatus::Denied)
            ->and($claim->paid_amount === null || (float) $claim->paid_amount === 0.0)->toBeTrue()
            ->and(FinancialCashEntry::query()->where('billing_claim_id', $claim->id)->pluck('amount')->map(fn ($a) => (float) $a)->all())->toBe([200.0]);
    });

    it('recebimento acima do restante reverte a glosa: Recebido + Glosado não passa do valor da guia', function (float $glosa, float $paid, float $expectedGlosa): void {
        $claim = billingIntegrityClaim($this->entity, $this->covenant);

        $this->post(route('panel.financial.billing.claims.denied', $claim->id), ['glosa_amount' => $glosa])
            ->assertSessionHasNoErrors();
        $this->post(route('panel.financial.billing.claims.paid', $claim->id), ['paid_amount' => $paid])
            ->assertSessionHasNoErrors();

        $claim->refresh();

        // Antes: glosa ficava intacta (ex.: glosa 200 + recebido 200 numa guia de 200).
        expect($claim->status)->toBe(BillingClaimStatus::Paid)
            ->and((float) $claim->paid_amount)->toBe($paid)
            ->and((float) $claim->glosa_amount)->toBe($expectedGlosa)
            ->and(round((float) $claim->paid_amount + (float) $claim->glosa_amount, 2))->toBeLessThanOrEqual((float) $claim->amount);
    })->with([
        'glosa total revertida (recurso aceito)' => [200.0, 200.0, 0.0],
        'glosa parcialmente revertida'           => [50.0, 180.0, 20.0],
        'recebimento do restante mantém a glosa' => [50.0, 150.0, 50.0],
        'recebimento parcial mantém a glosa'     => [50.0, 100.0, 50.0],
    ]);
});

describe('transições de status da guia', function (): void {
    it('não deixa glosar uma guia já paga', function (): void {
        $claim = billingIntegrityClaim($this->entity, $this->covenant);
        app(BillingService::class)->markClaimPaid($claim);

        // Antes: virava Glosada com a receita ainda no caixa.
        $this->post(route('panel.financial.billing.claims.denied', $claim->id), ['glosa_amount' => 200])
            ->assertSessionHasErrors('status');

        expect($claim->fresh()->status)->toBe(BillingClaimStatus::Paid);
    });

    it('relê a guia travada ao glosar: instância desatualizada não sobrescreve pagamento', function (): void {
        $claim = billingIntegrityClaim($this->entity, $this->covenant);
        $stale = BillingClaim::query()->findOrFail($claim->id); // ainda "Enviado" em memória

        app(BillingService::class)->markClaimPaid($claim);

        // Antes: markClaimDenied usava a instância recebida, sem lockForUpdate.
        expect(fn () => app(BillingService::class)->markClaimDenied($stale, ['glosa_amount' => 10]))
            ->toThrow(ValidationException::class);

        expect($claim->fresh()->status)->toBe(BillingClaimStatus::Paid);
    });

    it('não deixa glosar de novo uma guia já glosada', function (): void {
        $claim = billingIntegrityClaim($this->entity, $this->covenant);

        $this->post(route('panel.financial.billing.claims.denied', $claim->id), ['glosa_amount' => 50]);

        $this->post(route('panel.financial.billing.claims.denied', $claim->id), ['glosa_amount' => 80])
            ->assertSessionHasErrors('status');

        expect((float) $claim->fresh()->glosa_amount)->toBe(50.0);
    });

    it('não deixa pagar nem glosar rascunho de um lote ainda não enviado', function (): void {
        $batch = billingIntegrityBatch($this->entity, $this->covenant);
        $claim = billingIntegrityClaim($this->entity, $this->covenant, [
            'batch_id' => $batch->id,
            'status'   => BillingClaimStatus::Draft->value,
        ]);

        // Antes: aceitava pagar/glosar guia que nunca chegou à operadora.
        $this->post(route('panel.financial.billing.claims.paid', $claim->id))
            ->assertSessionHasErrors('status');
        $this->post(route('panel.financial.billing.claims.denied', $claim->id), ['glosa_amount' => 10])
            ->assertSessionHasErrors('status');

        expect($claim->fresh()->status)->toBe(BillingClaimStatus::Draft)
            ->and(FinancialCashEntry::query()->where('billing_claim_id', $claim->id)->exists())->toBeFalse();
    });

    it('não aceita pagar nem glosar guia cancelada', function (): void {
        $claim = billingIntegrityClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Cancelled->value]);

        $this->post(route('panel.financial.billing.claims.paid', $claim->id))->assertSessionHasErrors('status');
        $this->post(route('panel.financial.billing.claims.denied', $claim->id))->assertSessionHasErrors('status');

        expect($claim->fresh()->status)->toBe(BillingClaimStatus::Cancelled);
    });

    it('mantém o caminho de guia individual (sem lote): rascunho pode ser glosado e recebido', function (): void {
        $schedule = createBillableSchedule($this->entity);
        $claim    = app(BillingService::class)->createIndividual([
            'schedule_id'         => $schedule->id,
            'unit_price'          => 150,
            'clinical_indication' => 'H40.1',
        ]);

        $this->post(route('panel.financial.billing.claims.denied', $claim->id), ['glosa_amount' => 30, 'glosa_code' => '3099'])
            ->assertSessionHasNoErrors();
        $this->post(route('panel.financial.billing.claims.paid', $claim->id))
            ->assertSessionHasNoErrors();

        expect((float) $claim->fresh()->paid_amount)->toBe(120.0)
            ->and(TissGlosa::query()->where('guide_id', $claim->tiss_guide_id)->exists())->toBeTrue();
    });
});

describe('envio de lote', function (): void {
    it('não reenvia lote que não está em rascunho', function (BillingBatchStatus $status): void {
        $batch = billingIntegrityBatch($this->entity, $this->covenant, $status);
        billingIntegrityClaim($this->entity, $this->covenant, ['batch_id' => $batch->id, 'status' => BillingClaimStatus::Draft->value]);

        // Antes: lote cancelado/processado voltava para "Enviado".
        $this->post(route('panel.financial.billing.batches.submit', $batch->id))
            ->assertSessionHasErrors('batch');

        expect($batch->fresh()->status)->toBe($status);
    })->with([
        'cancelado'  => BillingBatchStatus::Cancelled,
        'processado' => BillingBatchStatus::Processed,
        'rejeitado'  => BillingBatchStatus::Rejected,
    ]);

    it('lote particular vira "cobrado" com mensagem própria', function (): void {
        $schedule = createParticularSchedule($this->entity);
        $batch    = app(BillingService::class)->createBatch([
            'covenant_id' => $schedule->covenant_id,
            'date_from'   => now()->subDay()->toDateString(),
            'date_until'  => now()->toDateString(),
            'unit_price'  => 180,
        ]);

        $this->post(route('panel.financial.billing.batches.submit', $batch->id))
            ->assertSessionHas('success', __('financial_billing.flash.batch_charged', ['code' => $batch->code]));

        expect($batch->fresh()->status)->toBe(BillingBatchStatus::Submitted);
    });

    it('não gera XML TISS de lote particular pela URL direta', function (): void {
        $schedule = createParticularSchedule($this->entity);
        $batch    = app(BillingService::class)->createBatch([
            'covenant_id' => $schedule->covenant_id,
            'date_from'   => now()->subDay()->toDateString(),
            'date_until'  => now()->toDateString(),
            'unit_price'  => 180,
        ]);

        // Antes: a UI escondia o botão, mas o GET gerava XML pelo gerador legado
        // e marcava as guias particulares como exportadas.
        $this->get(route('panel.financial.billing.batches.xml', $batch->id))
            ->assertRedirect()
            ->assertSessionHas('error', __('financial_billing.errors.batch_xml_particular', ['code' => $batch->code]));

        expect($batch->fresh()->xml_path)->toBeNull()
            ->and(BillingClaim::query()->where('batch_id', $batch->id)->where('is_tiss_exported', true)->exists())->toBeFalse();
    });

    it('continua baixando o XML de lote TISS', function (): void {
        $schedule = createBillableSchedule($this->entity);
        $batch    = app(BillingService::class)->createBatch([
            'covenant_id'         => $schedule->covenant_id,
            'date_from'           => now()->subDay()->toDateString(),
            'date_until'          => now()->toDateString(),
            'unit_price'          => 150,
            'clinical_indication' => 'H40.1',
        ]);

        $this->get(route('panel.financial.billing.batches.xml', $batch->id))
            ->assertOk()
            ->assertDownload(mb_strtolower($batch->code) . '.xml');
    });

    it('informa no flash quantas guias entraram e quantas ficaram com pendência', function (): void {
        $schedule = createBillableSchedule($this->entity);

        // Sem CID: a guia fica com pendência TISS e não entra no lote TISS.
        // Antes: o flash só dizia "Lote X criado", como se tudo tivesse entrado.
        $response = $this->post(route('panel.financial.billing.batch.store'), [
            'covenant_id' => $schedule->covenant_id,
            'date_from'   => now()->subDay()->toDateString(),
            'date_until'  => now()->toDateString(),
            'unit_price'  => 150,
        ]);

        $batch = BillingBatch::query()->latest('created_at')->firstOrFail();

        $response->assertSessionHas('success', __('financial_billing.flash.batch_created_detail', [
            'code' => $batch->code, 'included' => 0, 'pending' => 1,
        ]));
    });
});

describe('props da tela', function (): void {
    it('envia allowed_actions por guia de acordo com o status', function (): void {
        $batch = billingIntegrityBatch($this->entity, $this->covenant);

        $submitted = billingIntegrityClaim($this->entity, $this->covenant, ['amount' => 100]);
        $paid      = billingIntegrityClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Paid->value, 'amount' => 101]);
        $denied    = billingIntegrityClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Denied->value, 'amount' => 102, 'glosa_amount' => 40]);
        $inBatch   = billingIntegrityClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Draft->value, 'batch_id' => $batch->id, 'amount' => 103]);

        $response = $this->get(route('panel.financial.billing.index'))->assertOk();

        $rows = collect($response->viewData('page')['props']['claims']['data'])->keyBy('id');

        expect($rows[$submitted->id]['allowed_actions'])->toBe(['pay', 'deny'])
            ->and($rows[$paid->id]['allowed_actions'])->toBe([])
            ->and($rows[$denied->id]['allowed_actions'])->toBe(['pay'])
            ->and($rows[$denied->id]['receivable_amount'])->toEqual(62.0)
            // Fase 4: rascunho em lote ainda em rascunho não recebe nem glosa,
            // mas pode ser cancelado (sai do lote; o atendimento volta a faturar).
            ->and($rows[$inBatch->id]['allowed_actions'])->toBe(['cancel'])
            ->and($rows[$submitted->id]['status_badge'])->toStartWith('badge-soft-');
    });

    it('não oferece "Registrar recebimento" que o servidor recusaria (refaturada / receita já lançada)', function (): void {
        $schedule = createBillableSchedule($this->entity);
        $service  = app(BillingService::class);

        $rebilled = $service->createIndividual(['schedule_id' => $schedule->id, 'unit_price' => 150, 'clinical_indication' => 'H40.1']);
        $service->markClaimDenied($rebilled, ['glosa_amount' => 50, 'glosa_code' => '3099']);
        $newClaim = $service->createIndividual(['schedule_id' => $schedule->id, 'unit_price' => 150, 'clinical_indication' => 'H40.1']);

        $launched = billingIntegrityClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Denied->value, 'glosa_amount' => 50]);
        FinancialCashEntry::query()->create([
            'entity_id'        => $this->entity->id,
            'billing_claim_id' => $launched->id,
            'entry_date'       => now()->toDateString(),
            'description'      => 'Recebimento de guia',
            'type'             => FinancialEntryType::Income->value,
            'status'           => FinancialEntryStatus::Paid->value,
            'amount'           => 200.00,
            'active'           => true,
        ]);

        $plainDenied = billingIntegrityClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Denied->value, 'glosa_amount' => 50]);

        DB::enableQueryLog();
        $response         = $this->get(route('panel.financial.billing.index', ['tab' => 'claims']))->assertOk();
        $cashEntryQueries = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'from "financial_cash_entries"'))->count();

        $rows = collect($response->viewData('page')['props']['claims']['data'])->keyBy('id');

        // Antes: as duas primeiras recebiam ['pay'] e o botão sempre falhava no servidor.
        expect($rows[$rebilled->id]['allowed_actions'])->toBe([])
            ->and($rows[$launched->id]['allowed_actions'])->toBe([])
            ->and($rows[$plainDenied->id]['allowed_actions'])->toBe(['pay'])
            // Fase 4: guia TISS individual em rascunho (fora de lote) também pode
            // ser cancelada e ter a pendência corrigida.
            ->and($rows[$newClaim->id]['allowed_actions'])->toBe(['pay', 'deny', 'cancel', 'fix_pending'])
            // Uma consulta para a lista inteira, não uma por guia.
            ->and($cashEntryQueries)->toBe(1);
    });

    it('envia allowed_actions e resumo por lote (TISS × particular)', function (): void {
        $tissSchedule = createBillableSchedule($this->entity);
        $service      = app(BillingService::class);

        $tissBatch = $service->createBatch([
            'covenant_id'         => $tissSchedule->covenant_id,
            'date_from'           => now()->subDay()->toDateString(),
            'date_until'          => now()->toDateString(),
            'unit_price'          => 150,
            'clinical_indication' => 'H40.1',
        ]);

        $particularSchedule = createParticularSchedule($this->entity);
        $particularBatch    = $service->createBatch([
            'covenant_id' => $particularSchedule->covenant_id,
            'date_from'   => now()->subDay()->toDateString(),
            'date_until'  => now()->toDateString(),
            'unit_price'  => 180,
        ]);

        $response = $this->get(route('panel.financial.billing.index', ['tab' => 'batches']))->assertOk();
        $rows     = collect($response->viewData('page')['props']['batches']['data'])->keyBy('id');

        // Fase 4: lote em rascunho também oferece "Cancelar lote" e, sendo
        // TISS com o lote TISS aberto, "Adicionar guias" (sem pendência, sem "Reprocessar").
        expect($rows[$tissBatch->id]['allowed_actions'])->toBe(['submit', 'download_xml', 'cancel', 'add_claims'])
            ->and($rows[$tissBatch->id]['is_particular'])->toBeFalse()
            ->and($rows[$tissBatch->id]['code'])->toBe($tissBatch->code)
            ->and($rows[$tissBatch->id]['included_count'])->toBe(1)
            ->and($rows[$tissBatch->id]['pending_count'])->toBe(0)
            ->and($rows[$tissBatch->id]['total_amount'])->toEqual(150.0)
            ->and($rows[$particularBatch->id]['allowed_actions'])->toBe(['submit', 'cancel'])
            ->and($rows[$particularBatch->id]['is_particular'])->toBeTrue();
    });

    it('normaliza filtros inválidos na URL em vez de estourar erro 500', function (): void {
        // Antes: from=abc e covenant_id não-UUID chegavam crus ao PostgreSQL.
        $this->get(route('panel.financial.billing.index', [
            'from'         => 'abc',
            'to'           => '2026-02-30',
            'covenant_id'  => 'nao-e-uuid',
            'claim_status' => 'inexistente',
            'tab'          => '<script>',
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.from', now()->startOfMonth()->toDateString())
                ->where('filters.to', now()->toDateString())
                ->where('filters.covenant_id', null)
                ->where('filters.claim_status', null)
                ->where('filters.tab', 'eligible'));
    });

    it('devolve aba, convênio e status na URL e aplica o convênio a guias e lotes', function (): void {
        $other = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);

        $mine   = billingIntegrityClaim($this->entity, $this->covenant);
        $theirs = billingIntegrityClaim($this->entity, $other);
        billingIntegrityBatch($this->entity, $this->covenant);
        billingIntegrityBatch($this->entity, $other);

        // Antes: filters só tinha from/to e o convênio só filtrava os elegíveis.
        $this->get(route('panel.financial.billing.index', [
            'covenant_id'  => $this->covenant->id,
            'claim_status' => 'submitted',
            'tab'          => 'claims',
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.covenant_id', $this->covenant->id)
                ->where('filters.claim_status', 'submitted')
                ->where('filters.tab', 'claims')
                ->has('claims.data', 1)
                ->where('claims.data.0.id', $mine->id)
                ->where('totals.claims', 1)
                ->has('batches.data', 1)
                ->where('totals.batches', 1));

        expect($theirs->exists)->toBeTrue();
    });

    it('lista como elegível só atendimento sem guia ativa, sem bindar os ids das guias', function (): void {
        $free      = createBillableSchedule($this->entity);
        $billed    = createBillableSchedule($this->entity);
        $afterDeny = createBillableSchedule($this->entity);
        $service   = app(BillingService::class);

        $service->createIndividual(['schedule_id' => $billed->id, 'unit_price' => 100, 'clinical_indication' => 'H40.1']);
        $denied = $service->createIndividual(['schedule_id' => $afterDeny->id, 'unit_price' => 100, 'clinical_indication' => 'H40.1']);
        $service->markClaimDenied($denied, ['glosa_amount' => 100]);

        DB::enableQueryLog();

        $response = $this->get(route('panel.financial.billing.index'))->assertOk();

        $ids = collect($response->viewData('page')['props']['eligibleSchedules']['data'])->pluck('id')->all();

        expect($ids)->toContain($free->id)
            ->toContain($afterDeny->id)
            ->not->toContain($billed->id);

        // Antes: pluck de todos os schedule_id faturados virava whereNotIn com um
        // binding por guia (o PostgreSQL recusa acima de 65.535 → erro 500).
        $eligibleSql = collect(DB::getQueryLog())
            ->first(fn (array $q) => str_contains($q['query'], 'from "schedules"') && str_contains($q['query'], 'billing_claims'));

        expect($eligibleSql)->not->toBeNull()
            ->and($eligibleSql['query'])->toContain('not exists')
            ->and($eligibleSql['bindings'])->not->toContain($billed->id);
    });
});

describe('textos', function (): void {
    it('traduz os status de guia e lote pelo idioma, mantendo o texto em pt_BR', function (): void {
        app()->setLocale('en');
        expect(BillingClaimStatus::Draft->label())->toBe('Draft')
            ->and(BillingBatchStatus::Processed->label())->toBe('Processed');

        app()->setLocale('pt_BR');
        expect(BillingClaimStatus::Draft->label())->toBe('Rascunho')
            ->and(BillingClaimStatus::Denied->label())->toBe('Glosado')
            ->and(BillingBatchStatus::Rejected->label())->toBe('Rejeitado');
    });

    it('mensagens da importação de retorno vêm de financial_billing (não dependem de financial.billing)', function (): void {
        $covenant = Covenant::factory()->create([
            'entity_id'        => $this->entity->id,
            'active'           => true,
            'ans_registry'     => null,
            'tiss_operator_id' => null,
        ]);

        // Simula a limpeza da seção billing de lang/*/financial.php (a tela não
        // usa mais aquele grupo). Antes: o flash virava a chave crua.
        app('translator')->setLoaded(['*' => ['financial' => ['pt_BR' => [], 'en' => []]]]);

        $this->post(route('panel.financial.billing.import-return'), [
            'covenant_id' => $covenant->id,
            'xml_file'    => UploadedFile::fake()->createWithContent('retorno.xml', '<a></a>'),
        ])
            ->assertRedirect()
            ->assertSessionHas('error', __('financial_billing.errors.import_return_no_operator'));

        expect(__('financial_billing.errors.import_return_no_operator'))->not->toBe('financial_billing.errors.import_return_no_operator')
            ->and(__('financial_billing.flash.import_return_success', ['count' => 2]))->toContain('2')
            ->and(__('financial_billing.flash.import_return_empty'))->not->toBe('financial_billing.flash.import_return_empty')
            ->and(__('financial_billing.errors.import_return_failed'))->not->toBe('financial_billing.errors.import_return_failed');
    });

    it('mantém as mesmas chaves em lang/pt_BR e lang/en financial_billing.php', function (): void {
        $flatten = function (array $items, string $prefix = '') use (&$flatten): array {
            $keys = [];

            foreach ($items as $key => $value) {
                $keys = is_array($value)
                    ? array_merge($keys, $flatten($value, "{$prefix}{$key}."))
                    : array_merge($keys, ["{$prefix}{$key}"]);
            }

            return $keys;
        };

        $pt = $flatten(require lang_path('pt_BR/financial_billing.php'));
        $en = $flatten(require lang_path('en/financial_billing.php'));

        expect(array_values(array_diff($pt, $en)))->toBe([])
            ->and(array_values(array_diff($en, $pt)))->toBe([]);
    });
});
