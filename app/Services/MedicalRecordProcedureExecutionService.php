<?php

declare(strict_types=1);

namespace App\Services;

use App\Domains\Recall\Intravitreal\Exceptions\IntravitrealException;
use App\Domains\Recall\Intravitreal\Services\IntravitrealApplicationService;
use App\Enums\MedicalRecordProcedureStatus;
use App\Models\{Doctor, EntityProduct, EntityUser, MedicalRecord, MedicalRecordProcedure, StockLot};
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Transição de estado de App\Models\MedicalRecordProcedure + consumo de
 * estoque opcional associado (Fase 3 — estoque ↔ prontuário).
 *
 * markDone() é ATÔMICO: status do procedimento + TODAS as baixas de
 * estoque (uma por item) commitam juntas ou nenhuma commita. Se o item 3
 * de 5 falhar (ex.: saldo insuficiente daquele lote), os itens 1-2 também
 * são desfeitos e o procedimento continua `requested` — nunca fica em
 * estado parcial (metade do material baixado, procedimento "meio
 * executado"). DB::transaction() aninha via savepoint com a transação
 * própria de cada StockService::consumptionOut() — mesmo padrão já usado
 * em StockService::adjustToCountedQuantity().
 *
 * Toda transição passa por transitionUnderLock(), na ordem: trava a linha do
 * prontuário de ORIGEM (medical_record_id — a mesma de Signable::sign()) →
 * relê o procedimento com FOR UPDATE → só então confere o status e escreve.
 * Conferir o status na instância carregada ANTES da trava deixava duas abas
 * que viram "solicitado" passarem as duas: a que esperava a trava
 * sobrescrevia o estado e o texto da que já tinha concluído (execução virava
 * cancelamento, material baixado duas vezes) — achado via revisão adversarial
 * Codex. O estado final (Executado/Cancelado) não regride; quem perde a
 * corrida leva InvalidArgumentException (422 no controller) e nada é gravado.
 *
 * Pedido num atendimento antigo e executado/cancelado em outro é o fluxo
 * normal e segue possível depois da assinatura da origem (MedicalRecordProcedure
 * ::signedRecordWritableAttributes()), mas o texto do pedido (`notes`) faz
 * parte do que foi assinado: com a origem assinada (lido sob a trava) a
 * execução/cancelamento ACRESCENTA o seu texto, nunca sobrescreve. Com a
 * origem ainda aberta o texto continua sendo substituído, como sempre foi — e
 * a escrita sobe o clinical_revision dela (GuardedBySignedRecord).
 *
 * Injeção intravítrea (procedimento do catálogo com `clinical_kind` = intravitreal_injection): a execução também
 * registra a APLICAÇÃO do curso do olho (lote e validade, autorização do convênio, intervalo decidido) NA MESMA
 * transação — depois da trava do prontuário e do procedimento, nesta ordem: prontuário → procedimento → paciente
 * (recall) → curso → aplicação. Sem os dados da aplicação (ou com lote vencido) nada é gravado: nunca existe
 * "injeção executada" sem lote. O ciclo de vida do procedimento continua sendo a única escrita em
 * `medical_record_procedures`; tudo do curso vive nas tabelas do curso.
 */
class MedicalRecordProcedureExecutionService
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly IntravitrealApplicationService $intravitreal,
    ) {
    }

    /**
     * @param list<array{entity_product_id: string, quantity: float, stock_lot_id: ?string}> $items
     * @param array<string, mixed>|null                                                      $intravitreal dados da aplicação (injeção intravítrea); ignorado nos demais procedimentos
     *
     * @throws IntravitrealException aplicação recusada (lote, autorização, intervalo)
     */
    public function markDone(
        MedicalRecordProcedure $procedure,
        EntityUser $executedBy,
        array $items = [],
        ?string $notes = null,
        ?array $intravitreal = null,
        ?Doctor $performedBy = null,
    ): MedicalRecordProcedure {
        return $this->transitionUnderLock(
            $procedure,
            MedicalRecordProcedureStatus::Done,
            function (MedicalRecordProcedure $current, bool $originSigned) use ($executedBy, $items, $notes, $intravitreal, $performedBy): void {
                foreach ($items as $item) {
                    $product = EntityProduct::query()
                        ->where('entity_id', $current->entity_id)
                        ->findOrFail($item['entity_product_id']);

                    $lot = null;

                    if (! empty($item['stock_lot_id'])) {
                        $lot = StockLot::query()
                            ->where('entity_id', $current->entity_id)
                            ->findOrFail($item['stock_lot_id']);
                    }

                    $this->stockService->consumptionOut(
                        product: $product,
                        quantity: $item['quantity'],
                        note: "Consumo em procedimento: {$current->procedure?->name}",
                        referenceType: MedicalRecordProcedure::class,
                        referenceId: $current->id,
                        lot: $lot,
                    );
                }

                $current->update([
                    'status'      => MedicalRecordProcedureStatus::Done,
                    'executed_at' => now(),
                    'executed_by' => $executedBy->id,
                    'notes'       => $this->resolveNotes($current->notes, $notes, $originSigned),
                ]);

                if ($current->procedure?->isIntravitreal()) {
                    $this->intravitreal->recordExecution($current, $intravitreal ?? [], $performedBy);
                }
            },
        );
    }

    public function cancel(MedicalRecordProcedure $procedure, ?string $notes = null): MedicalRecordProcedure
    {
        return $this->transitionUnderLock(
            $procedure,
            MedicalRecordProcedureStatus::Cancelled,
            function (MedicalRecordProcedure $current, bool $originSigned) use ($notes): void {
                $current->update([
                    'status' => MedicalRecordProcedureStatus::Cancelled,
                    'notes'  => $this->resolveNotes($current->notes, $notes, $originSigned),
                ]);

                if ($current->procedure?->isIntravitreal()) {
                    $this->intravitreal->releaseProcedure($current);
                }
            },
        );
    }

    /**
     * Trava, relê, confere e só então escreve — tudo numa transação. $apply
     * recebe o procedimento ATUAL (lido sob a trava, nunca o que o caller
     * carregou antes) e se o prontuário de origem já está assinado.
     *
     * FOR UPDATE no próprio procedimento, além da trava do prontuário: quem
     * escreve pelo guard já se serializa no prontuário, mas isto também cobre
     * uma escrita que não passe por ele.
     *
     * @param callable(MedicalRecordProcedure, bool): void $apply
     *
     * @throws InvalidArgumentException transição inválida a partir do status atual
     */
    private function transitionUnderLock(
        MedicalRecordProcedure $procedure,
        MedicalRecordProcedureStatus $target,
        callable $apply,
    ): MedicalRecordProcedure {
        return DB::transaction(function () use ($procedure, $target, $apply) {
            $originSigned = MedicalRecord::lockAndCheckSigned($procedure->medical_record_id);

            $current = MedicalRecordProcedure::query()
                ->whereKey($procedure->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertTransition($current, $target);

            $apply($current, $originSigned);

            return $current->fresh();
        });
    }

    /**
     * Texto que fica em `notes` ao executar/cancelar. Origem aberta: o novo
     * texto substitui o antigo (se vier um). Origem assinada: o pedido original
     * é parte do registro assinado — o texto novo vai DEPOIS dele, numa linha
     * própria; sem texto novo, nada muda.
     */
    private function resolveNotes(?string $current, ?string $incoming, bool $originSigned): ?string
    {
        if (! $originSigned) {
            return $incoming ?? $current;
        }

        $incoming = trim((string) $incoming);

        if ($incoming === '') {
            return $current;
        }

        return filled($current) ? $current . "\n" . $incoming : $incoming;
    }

    private function assertTransition(MedicalRecordProcedure $procedure, MedicalRecordProcedureStatus $target): void
    {
        if (! $procedure->status->canTransitionTo($target)) {
            throw new InvalidArgumentException(
                "Procedimento está '{$procedure->status->label()}' — não pode virar '{$target->label()}'.",
            );
        }
    }
}
