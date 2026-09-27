<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\Entity;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Serializa "mexer no caixa" × "fechar o período" da clínica.
 *
 * Antes: quem lançava checava "período fechado?" com um SELECT simples e o
 * fechamento só esperava quem já tinha feito o INSERT. Um lançamento que
 * passava na checagem enquanto o fechamento somava era gravado DENTRO do
 * período fechado e ficava fora do snapshot do CashClose (totais do
 * fechamento ≠ lançamentos do período, sem como corrigir pela tela).
 *
 * PostgreSQL — advisory lock de TRANSAÇÃO por clínica, solto no COMMIT:
 *  - escritores (lançar/editar/excluir, registrar recebimento de guia) pegam o
 *    lock COMPARTILHADO: não se bloqueiam entre si;
 *  - o fechamento pega o EXCLUSIVO: espera os escritores em andamento e segura
 *    os novos até commitar — quem esperou relê e enxerga o fechamento.
 * Quem chama pega o lock ANTES de checar o período (a checagem precisa ser um
 * comando novo, depois da espera) e antes da numeração do lançamento.
 *
 * O fechamento não pega nenhum outro lock depois deste, então não fecha ciclo
 * (deadlock) com escritores que já seguram agendamento/guia/numeração.
 *
 * Demais drivers: FOR SHARE (escritor) / FOR UPDATE (fechamento) na linha da clínica.
 */
final class CashPeriodLock
{
    /** Namespace da chave do advisory lock (não colide com as chaves de numeração). */
    private const LOCK_NAMESPACE = 'financial_cash_period';

    /** Lançar, editar ou excluir no caixa; registrar recebimento de guia. */
    public static function forWriting(string $entityId): void
    {
        self::acquire($entityId, exclusive: false);
    }

    /** Fechar um período do caixa. */
    public static function forClosing(string $entityId): void
    {
        self::acquire($entityId, exclusive: true);
    }

    private static function acquire(string $entityId, bool $exclusive): void
    {
        $connection = DB::connection();

        // Lock de transação pego fora de transação some no fim do próprio comando.
        if ($connection->transactionLevel() === 0) {
            throw new LogicException('CashPeriodLock must be acquired inside a database transaction.');
        }

        if ($connection->getDriverName() === 'pgsql') {
            $connection->select(
                $exclusive ? 'select pg_advisory_xact_lock(?)' : 'select pg_advisory_xact_lock_shared(?)',
                [self::lockKey($entityId)],
                false,
            );

            return;
        }

        $query = Entity::query()->whereKey($entityId);

        ($exclusive ? $query->lockForUpdate() : $query->sharedLock())->firstOrFail();
    }

    /**
     * Chave bigint estável por clínica (60 bits do sha1, sempre positiva).
     * Colisão entre clínicas só serializaria as duas — nunca mistura caixas.
     */
    private static function lockKey(string $entityId): int
    {
        return (int) hexdec(substr(sha1(self::LOCK_NAMESPACE . '|' . $entityId), 0, 15));
    }
}
