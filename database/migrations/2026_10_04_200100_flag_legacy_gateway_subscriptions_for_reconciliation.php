<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cobrança automática criada pelo código anterior precisa de conciliação
 * antes de entrar nas regras novas (régua, expiração, renovação local):
 *
 * - o código anterior descartava next_billing_at (fora do $fillable) e nunca
 *   estendia ends_at depois da ativação — quem paga em dia ficou com ends_at
 *   parado no fim do 1º ciclo;
 * - o job antigo expirava essas linhas (Expired) com a recorrência do
 *   gateway ainda cobrando;
 * - ciclo e valor contratados não foram gravados.
 *
 * Critério: billing_mode = gateway, sem next_billing_at (toda linha do fluxo
 * novo grava o vencimento), não removida e não tentativa recusada pelo
 * gateway, e:
 *
 *  - a mais recente da empresa (a vigente): ativa, em atraso ou expirada;
 *  - as antigas (já substituídas) que ainda podem ter recorrência viva:
 *    ativa ou em atraso (o código anterior não cancelava a em atraso na
 *    troca, nunca parava a recorrência no gateway e o webhook antigo
 *    ressuscitava qualquer linha) e expirada com recorrência no gateway.
 *    Sem a marcação elas entrariam na régua (e-mail de encerramento à
 *    clínica e cancelamento no gateway), na expiração e na renovação local.
 *    A marcação de uma linha antiga nunca libera acesso (só a da vigente:
 *    Subscription::accessLevel/scopeAccessible); o billing:reconcile-legacy
 *    a mostra como "recorrência duplicada" quando a recorrência segue ativa.
 *
 * Também devolve ao trial (billing_mode nulo, D1) o trial cancelado pelo
 * manager no código anterior: ele ganhava billing_state 'cancelled' sem
 * nunca passar pelo gateway e a 230100 o promovia a cobrança automática.
 */
return new class() extends Migration {
    public function up(): void
    {
        DB::table('subscriptions')
            ->where('billing_mode', 'gateway')
            ->whereNull('gateway')
            ->whereNotNull('trial_ends_at')
            ->update(['billing_mode' => null]);

        $notFailed = fn (string $table) => fn (Builder $q) => $q->whereNull("{$table}.cancelled_reason")
            ->orWhere("{$table}.cancelled_reason", '!=', 'activation_failed');

        $legacy = fn () => DB::table('subscriptions')
            ->where('billing_mode', 'gateway')
            ->whereNull('next_billing_at')
            ->whereIn('status', ['active', 'past_due', 'expired'])
            ->whereNull('deleted_at')
            ->where($notFailed('subscriptions'));

        // Mesma regra de Subscription::scopeLatestPerEntity.
        $newer = fn (Builder $newer) => $newer->selectRaw('1')
            ->from('subscriptions as newer')
            ->whereColumn('newer.entity_id', 'subscriptions.entity_id')
            ->whereNull('newer.deleted_at')
            ->where($notFailed('newer'))
            ->where(fn (Builder $q) => $q->whereColumn('newer.created_at', '>', 'subscriptions.created_at')
                ->orWhere(fn (Builder $tie) => $tie->whereColumn('newer.created_at', 'subscriptions.created_at')
                    ->whereColumn('newer.id', '>', 'subscriptions.id')));

        // A vigente da empresa.
        $legacy()
            ->whereNotExists($newer)
            ->update(['needs_billing_reconciliation' => true]);

        // As antigas que ainda podem ter recorrência viva.
        $legacy()
            ->whereExists($newer)
            ->where(fn (Builder $q) => $q->whereIn('status', ['active', 'past_due'])
                ->orWhere(fn (Builder $expired) => $expired->whereNotNull('gateway_subscription_id')
                    ->where('gateway_subscription_id', '!=', '')))
            ->update(['needs_billing_reconciliation' => true]);
    }

    public function down(): void
    {
        // Só dados: a coluna sai na 200000.
    }
};
