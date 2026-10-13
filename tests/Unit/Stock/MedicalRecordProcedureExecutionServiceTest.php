<?php

declare(strict_types=1);

use App\Enums\{ClientRule, MedicalRecordProcedureStatus};
use App\Exceptions\{InsufficientStockException, LotRequiredException};
use App\Models\{Doctor, Entity, EntityProduct, MedicalRecord, MedicalRecordProcedure, Patient, People, Procedure, StockMovement, User};
use App\Services\MedicalRecordProcedureExecutionService;
use App\Services\Stock\StockService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// tests/Unit não herda TestCase::class + RefreshDatabase de Pest.php (só
// 'Feature' herda) — mesmo padrão de tests/Unit/Stock/StockServiceTest.php.
uses(TestCase::class, RefreshDatabase::class);

/**
 * Fase 3 — estoque ↔ prontuário. Testa a transição de estado +
 * atomicidade do consumo direto no service, sem HTTP/ACL (isso fica em
 * tests/Feature/Stock/MedicalRecordProceduresTest.php).
 */
beforeEach(function () {
    $this->service = app(MedicalRecordProcedureExecutionService::class);
    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->doctorUser = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    $this->doctor     = Doctor::query()->create([
        'entity_user_id' => $this->doctorUser->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->record  = MedicalRecord::query()->create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctor->id,
    ]);

    $this->catalogProcedure = Procedure::query()->create([
        'entity_id' => $this->entity->id, 'code' => 'PROC-1', 'name' => 'Facectomia', 'active' => true,
    ]);

    $this->mrProcedure = MedicalRecordProcedure::create([
        'entity_id'         => $this->entity->id,
        'patient_id'        => $this->patient->id,
        'medical_record_id' => $this->record->id,
        'procedure_id'      => $this->catalogProcedure->id,
        'doctor_id'         => $this->doctor->id,
        'eye'               => 'right',
    ]);

    $this->product = EntityProduct::create([
        'entity_id' => $this->entity->id, 'name' => 'Lente IOL', 'unit' => 'un', 'active' => true,
    ]);
});

it('marca executado sem itens de consumo (procedimento sem material físico)', function () {
    $updated = $this->service->markDone($this->mrProcedure, $this->doctorUser, [], 'Sem intercorrências.');

    expect($updated->status)->toBe(MedicalRecordProcedureStatus::Done)
        ->and($updated->executed_at)->not->toBeNull()
        ->and((string) $updated->executed_by)->toBe((string) $this->doctorUser->id)
        ->and($updated->notes)->toBe('Sem intercorrências.');
});

it('marca executado E baixa estoque do(s) item(ns) confirmado(s)', function () {
    app(StockService::class)->manualIn($this->product, 10, 500.00);

    $updated = $this->service->markDone($this->mrProcedure, $this->doctorUser, [
        ['entity_product_id' => $this->product->id, 'quantity' => 1.0, 'stock_lot_id' => null],
    ]);

    expect($updated->status)->toBe(MedicalRecordProcedureStatus::Done)
        ->and((float) $this->product->fresh()->qty_on_hand)->toBe(9.0);

    $movement = StockMovement::query()
        ->where('reference_type', MedicalRecordProcedure::class)
        ->where('reference_id', $this->mrProcedure->id)
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->type->value)->toBe('consumption_out')
        ->and((float) $movement->quantity)->toBe(1.0);
});

it('[ATOMICIDADE] se um item falhar por saldo insuficiente, NENHUM item baixa e o procedimento continua requested', function () {
    $productB = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Viscoelástico', 'unit' => 'un', 'active' => true]);

    app(StockService::class)->manualIn($this->product, 10, 500.00); // saldo suficiente
    // productB fica com saldo ZERO de propósito — a 2ª baixa vai falhar.

    expect(fn () => $this->service->markDone($this->mrProcedure, $this->doctorUser, [
        ['entity_product_id' => $this->product->id, 'quantity' => 1.0, 'stock_lot_id' => null],
        ['entity_product_id' => $productB->id, 'quantity' => 1.0, 'stock_lot_id' => null],
    ]))->toThrow(InsufficientStockException::class);

    // rollback total: item A (que teria sucesso sozinho) também foi desfeito.
    expect((float) $this->product->fresh()->qty_on_hand)->toBe(10.0)
        ->and((float) $productB->fresh()->qty_on_hand)->toBe(0.0)
        ->and($this->mrProcedure->fresh()->status)->toBe(MedicalRecordProcedureStatus::Requested)
        ->and(StockMovement::query()->where('reference_type', MedicalRecordProcedure::class)->count())->toBe(0);
});

it('[REGRA DE NEGÓCIO] produto requires_lot=true sem lote informado lança LotRequiredException (via service, não só HTTP)', function () {
    $lotProduct = EntityProduct::create([
        'entity_id' => $this->entity->id, 'name' => 'OPM', 'unit' => 'un', 'requires_lot' => true, 'active' => true,
    ]);
    app(StockService::class)->manualIn($lotProduct, 5, 300.00, lot: app(StockService::class)->findOrCreateLot($lotProduct, 'L1', now()->addYear()));

    expect(fn () => $this->service->markDone($this->mrProcedure, $this->doctorUser, [
        ['entity_product_id' => $lotProduct->id, 'quantity' => 1.0, 'stock_lot_id' => null],
    ]))->toThrow(LotRequiredException::class);

    expect($this->mrProcedure->fresh()->status)->toBe(MedicalRecordProcedureStatus::Requested);
});

it('não permite marcar executado um procedimento já cancelado', function () {
    $this->service->cancel($this->mrProcedure);

    expect(fn () => $this->service->markDone($this->mrProcedure, $this->doctorUser))
        ->toThrow(InvalidArgumentException::class);
});

it('não permite cancelar um procedimento já executado', function () {
    $this->service->markDone($this->mrProcedure, $this->doctorUser);

    expect(fn () => $this->service->cancel($this->mrProcedure))
        ->toThrow(InvalidArgumentException::class);
});

/*
 * Pedido num atendimento antigo, executado/cancelado em outro: o prontuário de
 * ORIGEM já está assinado. O ciclo de vida continua possível (a tela atravessa
 * os prontuários do paciente), mas o texto do pedido faz parte do que foi
 * assinado — a execução ACRESCENTA o seu texto, nunca sobrescreve — e a
 * revisão do prontuário assinado não se mexe (achado via revisão adversarial
 * Codex).
 */
describe('origem do procedimento ASSINADA', function () {
    beforeEach(function () {
        $this->mrProcedure->update(['notes' => 'Pedido: facectomia OD.']);

        // Sem texto no pedido — precisa existir ANTES da assinatura (criar depois é recusado).
        $this->blankProcedure = MedicalRecordProcedure::create([
            'entity_id'    => $this->entity->id, 'patient_id' => $this->patient->id, 'medical_record_id' => $this->record->id,
            'procedure_id' => $this->catalogProcedure->id, 'doctor_id' => $this->doctor->id,
        ]);

        $this->record->sign($this->doctorUser);
        $this->signedRevision = $this->record->fresh()->clinical_revision;
    });

    it('executa com baixa de estoque, soma o texto ao pedido e não mexe na revisão do prontuário assinado', function () {
        app(StockService::class)->manualIn($this->product, 10, 500.00);

        $updated = $this->service->markDone($this->mrProcedure, $this->doctorUser, [
            ['entity_product_id' => $this->product->id, 'quantity' => 2.0, 'stock_lot_id' => null],
        ], 'Executado sem intercorrências.');

        expect($updated->status)->toBe(MedicalRecordProcedureStatus::Done)
            ->and($updated->notes)->toBe("Pedido: facectomia OD.\nExecutado sem intercorrências.")
            ->and((float) $this->product->fresh()->qty_on_hand)->toBe(8.0)
            ->and($this->record->fresh()->clinical_revision)->toBe($this->signedRevision)
            ->and($this->record->fresh()->verifyIntegrity())->toBeTrue();
    });

    it('[ATOMICIDADE] saldo insuficiente desfaz tudo: procedimento continua requested, pedido intacto, nada baixado', function () {
        $productB = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Viscoelástico', 'unit' => 'un', 'active' => true]);
        app(StockService::class)->manualIn($this->product, 10, 500.00);

        expect(fn () => $this->service->markDone($this->mrProcedure, $this->doctorUser, [
            ['entity_product_id' => $this->product->id, 'quantity' => 1.0, 'stock_lot_id' => null],
            ['entity_product_id' => $productB->id, 'quantity' => 1.0, 'stock_lot_id' => null],
        ], 'Não deve ficar.'))->toThrow(InsufficientStockException::class);

        $fresh = $this->mrProcedure->fresh();

        expect($fresh->status)->toBe(MedicalRecordProcedureStatus::Requested)
            ->and($fresh->notes)->toBe('Pedido: facectomia OD.')
            ->and((float) $this->product->fresh()->qty_on_hand)->toBe(10.0)
            ->and($this->record->fresh()->clinical_revision)->toBe($this->signedRevision);
    });

    it('cancelar soma o texto ao pedido; sem texto novo o pedido fica exatamente como foi assinado', function () {
        $cancelled = $this->service->cancel($this->mrProcedure, 'Paciente desistiu.');

        expect($cancelled->status)->toBe(MedicalRecordProcedureStatus::Cancelled)
            ->and($cancelled->notes)->toBe("Pedido: facectomia OD.\nPaciente desistiu.");

        $untouched = $this->service->cancel($this->blankProcedure);

        expect($untouched->notes)->toBeNull();
    });

    it('texto novo em branco é ignorado; pedido sem texto recebe só o novo, sem linha vazia', function () {
        $blankDone = $this->service->markDone($this->mrProcedure, $this->doctorUser, [], "  \n ");

        expect($blankDone->notes)->toBe('Pedido: facectomia OD.');

        $done = $this->service->markDone($this->blankProcedure, $this->doctorUser, [], 'Executado.');

        expect($done->notes)->toBe('Executado.');
    });
});

it('origem ABERTA: texto novo substitui o do pedido e, sem texto novo, o antigo fica — como sempre foi', function () {
    $this->mrProcedure->update(['notes' => 'Pedido: facectomia OD.']);

    $kept = $this->service->markDone($this->mrProcedure, $this->doctorUser);

    expect($kept->notes)->toBe('Pedido: facectomia OD.');

    $other = MedicalRecordProcedure::create([
        'entity_id'    => $this->entity->id, 'patient_id' => $this->patient->id, 'medical_record_id' => $this->record->id,
        'procedure_id' => $this->catalogProcedure->id, 'doctor_id' => $this->doctor->id, 'notes' => 'Pedido: YAG.',
    ]);

    $replaced = $this->service->cancel($other, 'Paciente desistiu.');

    expect($replaced->notes)->toBe('Paciente desistiu.');
});

/*
 * Corrida entre execução e cancelamento — achado via revisão adversarial
 * Codex: o status era conferido na instância carregada ANTES de qualquer trava
 * e a escrita seguia usando essa mesma instância. Duas abas que carregaram o
 * procedimento como "solicitado" passavam as duas pela checagem; a que
 * chegava depois (esperando a trava do prontuário) sobrescrevia o estado e o
 * texto da que já tinha concluído — inclusive com o prontuário assinado, onde
 * a exceção do ciclo de vida deixa a escrita passar sem subir a revisão. Aqui,
 * "abas" = instâncias independentes carregadas antes de qualquer operação.
 */
describe('instância velha: duas abas carregaram o procedimento como solicitado', function () {
    beforeEach(function () {
        $this->mrProcedure->update(['notes' => 'Pedido: facectomia OD.']);

        $this->tabA = MedicalRecordProcedure::query()->findOrFail($this->mrProcedure->id);
        $this->tabB = MedicalRecordProcedure::query()->findOrFail($this->mrProcedure->id);
    });

    $origins = ['origem aberta' => false, 'origem assinada' => true];

    it('[CORRIDA] cancelar depois de outra aba executar é rejeitado: Executado, texto e execução do vencedor ficam, estoque baixado uma vez', function (bool $signed) {
        app(StockService::class)->manualIn($this->product, 10, 500.00);

        if ($signed) {
            $this->record->sign($this->doctorUser);
        }

        $this->service->markDone($this->tabA, $this->doctorUser, [
            ['entity_product_id' => $this->product->id, 'quantity' => 2.0, 'stock_lot_id' => null],
        ], 'Executado.');

        expect(fn () => $this->service->cancel($this->tabB, 'Cancelado depois.'))->toThrow(InvalidArgumentException::class);

        $fresh = $this->mrProcedure->fresh();

        expect($fresh->status)->toBe(MedicalRecordProcedureStatus::Done)
            ->and($fresh->notes)->toBe($signed ? "Pedido: facectomia OD.\nExecutado." : 'Executado.')
            ->and($fresh->executed_at)->not->toBeNull()
            ->and((string) $fresh->executed_by)->toBe((string) $this->doctorUser->id)
            ->and((float) $this->product->fresh()->qty_on_hand)->toBe(8.0)
            ->and(StockMovement::query()->where('reference_type', MedicalRecordProcedure::class)->count())->toBe(1);
    })->with($origins);

    it('[CORRIDA] executar depois de outra aba cancelar é rejeitado: Cancelado e texto do vencedor ficam, NENHUMA baixa de estoque', function (bool $signed) {
        app(StockService::class)->manualIn($this->product, 10, 500.00);

        if ($signed) {
            $this->record->sign($this->doctorUser);
        }

        $this->service->cancel($this->tabA, 'Paciente desistiu.');

        expect(fn () => $this->service->markDone($this->tabB, $this->doctorUser, [
            ['entity_product_id' => $this->product->id, 'quantity' => 2.0, 'stock_lot_id' => null],
        ], 'Executado depois.'))->toThrow(InvalidArgumentException::class);

        $fresh = $this->mrProcedure->fresh();

        expect($fresh->status)->toBe(MedicalRecordProcedureStatus::Cancelled)
            ->and($fresh->notes)->toBe($signed ? "Pedido: facectomia OD.\nPaciente desistiu." : 'Paciente desistiu.')
            ->and($fresh->executed_at)->toBeNull()
            ->and($fresh->executed_by)->toBeNull()
            ->and((float) $this->product->fresh()->qty_on_hand)->toBe(10.0)
            ->and(StockMovement::query()->where('reference_type', MedicalRecordProcedure::class)->count())->toBe(0);
    })->with($origins);

    it('[CORRIDA] duas execuções com instâncias separadas: a segunda é rejeitada e o material é baixado UMA vez', function (bool $signed) {
        app(StockService::class)->manualIn($this->product, 10, 500.00);

        if ($signed) {
            $this->record->sign($this->doctorUser);
        }

        $item = [['entity_product_id' => $this->product->id, 'quantity' => 2.0, 'stock_lot_id' => null]];

        $this->service->markDone($this->tabA, $this->doctorUser, $item, 'Primeira.');

        expect(fn () => $this->service->markDone($this->tabB, $this->doctorUser, $item, 'Segunda.'))->toThrow(InvalidArgumentException::class);

        expect($this->mrProcedure->fresh()->notes)->toBe($signed ? "Pedido: facectomia OD.\nPrimeira." : 'Primeira.')
            ->and((float) $this->product->fresh()->qty_on_hand)->toBe(8.0)
            ->and(StockMovement::query()->where('reference_type', MedicalRecordProcedure::class)->count())->toBe(1);
    })->with($origins);

    it('[CORRIDA] o texto de :action é montado sobre o estado TRAVADO, não sobre o que a instância velha carregou', function (string $action) {
        $this->record->sign($this->doctorUser);

        // Outra operação mexeu só em notes (coluna do ciclo de vida, permitida
        // com a origem assinada); a aba velha ainda carrega o texto antigo.
        MedicalRecordProcedure::query()->findOrFail($this->mrProcedure->id)->update(['notes' => 'Pedido: facectomia OD (revisado).']);

        $result = $action === 'markDone'
            ? $this->service->markDone($this->tabA, $this->doctorUser, [], 'Texto novo.')
            : $this->service->cancel($this->tabA, 'Texto novo.');

        expect($result->notes)->toBe("Pedido: facectomia OD (revisado).\nTexto novo.");
    })->with(['markDone', 'cancel']);

    it('procedimento removido enquanto a aba velha esperava: 404 (ModelNotFound), nada gravado', function () {
        MedicalRecordProcedure::query()->findOrFail($this->mrProcedure->id)->delete();

        expect(fn () => $this->service->cancel($this->tabA, 'Tarde demais.'))->toThrow(ModelNotFoundException::class);
    });
});

it('[ORDEM DE LOCKS] trava o prontuário de origem ANTES de reler o procedimento — a ordem do guard (prontuário → filho); invertida, dois escritores do mesmo procedimento podem entrar em deadlock', function (string $action) {
    $log = [];

    DB::listen(function (QueryExecuted $query) use (&$log): void {
        $log[] = strtolower($query->sql);
    });

    if ($action === 'markDone') {
        $this->service->markDone($this->mrProcedure, $this->doctorUser);
    } else {
        $this->service->cancel($this->mrProcedure);
    }

    $firstLock = function (string $table) use ($log): ?int {
        foreach ($log as $position => $sql) {
            if (str_contains($sql, "from \"{$table}\"") && str_contains($sql, 'for update')) {
                return $position;
            }
        }

        return null;
    };

    $record    = $firstLock('medical_records');
    $procedure = $firstLock('medical_record_procedures');

    expect($record)->not->toBeNull()
        ->and($procedure)->not->toBeNull()
        ->and($record)->toBeLessThan($procedure);
})->with(['markDone', 'cancel']);
