<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Models\AiCreditWallet;
use App\Domains\AI\Services\AiCreditWalletService;
use Illuminate\Console\Command;

/**
 * Saneia o "Reservados" das carteiras de IA: deve ser só o que execuções em
 * andamento reservaram do saldo comprado. Liquidações antigas devolviam à
 * cota (que expira) créditos que tinham saído do saldo comprado e deixavam
 * o valor preso em reserved_balance. Sem --apply, só lista o que mudaria.
 * Rodar uma vez depois do deploy; não fica agendado.
 */
class ReconcileAiReservedBalanceCommand extends Command
{
    protected $signature = 'ai:reconcile-reserved-balance {--apply : Devolve ao saldo comprado o reservado sem execução em andamento}';

    protected $description = 'Confere o saldo reservado das carteiras de IA e devolve ao saldo comprado o que não tem execução em andamento';

    public function handle(AiCreditWalletService $wallets): int
    {
        $apply = (bool) $this->option('apply');
        $rows  = [];

        AiCreditWallet::query()
            ->where('reserved_balance', '>', 0)
            ->pluck('entity_id')
            ->each(function (string $entityId) use ($wallets, $apply, &$rows): void {
                $result = $wallets->reconcileReservedBalance($entityId, $apply);

                if ($result['phantom'] > 0) {
                    $rows[] = [$result['entity_id'], $result['reserved'], $result['expected'], $result['phantom'], $result['applied'] ? 'sim' : 'não'];
                }
            });

        if ($rows === []) {
            $this->info('Nenhuma carteira com reservado sem execução em andamento.');

            return self::SUCCESS;
        }

        $this->table(['Empresa', 'Reservado', 'Em andamento', 'Sem execução', 'Devolvido'], $rows);

        if (! $apply) {
            $this->warn('Nada foi alterado. Rode com --apply para devolver ao saldo comprado.');
        }

        return self::SUCCESS;
    }
}
