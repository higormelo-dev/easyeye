<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BillingCycle;
use App\Models\Subscription;
use App\Services\Billing\LegacySubscriptionReconciler;
use App\Support\Billing\DunningSchedule;
use Carbon\{CarbonImmutable, CarbonInterface};
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Concilia com o gateway as assinaturas de cobrança automática do código
 * anterior (marcadas na migração 2026_10_04_200100). Simulação por padrão:
 * consulta o gateway e mostra o que faria; --apply grava. Regra em
 * LegacySubscriptionReconciler.
 *
 * --close=ID encerra a revisão manual de uma linha, sempre com uma saída
 * explícita e o motivo:
 *  - --action=cancel: cancela a assinatura e para a recorrência no gateway;
 *  - --action=paid-until --until=AAAA-MM-DD: ativa, paga até o fim desse dia,
 *    e daí em diante segue o fluxo novo.
 */
class ReconcileLegacySubscriptionsCommand extends Command
{
    protected $signature = 'billing:reconcile-legacy
        {--apply : Grava a conciliação (sem esta opção é só simulação)}
        {--close= : Encerra a revisão manual desta assinatura (id); exige --action e --reason}
        {--action= : Saída da revisão: cancel (cancela e para a recorrência no gateway) ou paid-until (ativa até --until)}
        {--until= : Com --action=paid-until: último dia pago (AAAA-MM-DD, hoje ou depois)}
        {--reason= : Motivo do encerramento da revisão (obrigatório com --close)}';

    protected $description = 'Concilia com o gateway as assinaturas de cobrança automática criadas antes da régua (simulação por padrão)';

    public function handle(LegacySubscriptionReconciler $reconciler): int
    {
        if (filled($this->option('close'))) {
            return $this->close($reconciler);
        }

        $apply = (bool) $this->option('apply');
        $rows  = $reconciler->run($apply);

        if ($rows->isEmpty()) {
            $this->info(__('billing_console.reconcile.nothing'));

            return self::SUCCESS;
        }

        $this->table(
            collect(['subscription', 'entity', 'gateway', 'status', 'outcome', 'reason', 'terms', 'next_due', 'unpaid_due', 'days_overdue', 'last_payment'])
                ->map(fn (string $key) => __("billing_console.reconcile.col_{$key}"))
                ->all(),
            $rows->map(fn (array $row) => $this->tableRow($row, $apply))->all(),
        );

        foreach ($rows->groupBy('outcome') as $outcome => $group) {
            $this->line(sprintf('%-40s %d', __("billing_console.reconcile.outcome.{$outcome}"), $group->count()));
        }

        // Como a régua trata o que foi conciliado (e o que ficou de fora).
        if ($rows->contains('reason', 'overdue')) {
            $this->line(__('billing_console.reconcile.note_past_due'));
        }

        if ($rows->contains('reason', 'overdue_never_paid')) {
            $this->line(__('billing_console.reconcile.note_never_paid'));
        }

        if ($rows->contains(fn (array $row) => $row['current_id'] !== null)) {
            $this->line(__('billing_console.reconcile.note_superseded'));
        }

        $apply
            ? $this->info(__('billing_console.reconcile.applied', ['count' => $rows->where('applied', true)->count()]))
            : $this->warn(__('billing_console.reconcile.dry_run'));

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function tableRow(array $row, bool $apply): array
    {
        /** @var Subscription $subscription */
        $subscription = $row['subscription'];
        $changes      = $row['changes'];
        $cycle        = $changes['billing_cycle'] ?? $subscription->billing_cycle;
        $amount       = $changes['amount'] ?? $subscription->amount;
        $unpaidDue    = $row['unpaid_due'];
        $outcome      = __("billing_console.reconcile.outcome.{$row['outcome']}");
        $reason       = __("billing_console.reconcile.reason.{$row['reason']}")
            . ($row['detail'] ? " ({$row['detail']})" : '')
            . ($row['current_id'] ? ' · ' . __('billing_console.reconcile.current_is', ['id' => $row['current_id']]) : '');

        return [
            (string) $subscription->id,
            (string) $subscription->entity?->name,
            (string) ($subscription->gateway ?: '-'),
            $subscription->status->label(),
            $apply && $row['applied'] ? "{$outcome} ✓" : $outcome,
            trim($reason),
            $cycle instanceof BillingCycle ? trim($cycle->label() . ($amount !== null ? ' · ' . number_format((float) $amount, 2, '.', '') : '')) : '-',
            $this->date($changes['next_billing_at'] ?? null),
            $this->date($unpaidDue),
            // Atraso real (desde o vencimento não pago); a régua conta da conciliação.
            $unpaidDue instanceof CarbonInterface ? (string) DunningSchedule::daysSince($unpaidDue) : '-',
            $this->date($changes['last_payment_at'] ?? $subscription->last_payment_at),
        ];
    }

    private function close(LegacySubscriptionReconciler $reconciler): int
    {
        $id     = (string) $this->option('close');
        $action = (string) $this->option('action');
        $reason = trim((string) $this->option('reason'));

        // Sem saída explícita, nada muda: tirar só a marcação não é neutro.
        if (! in_array($action, LegacySubscriptionReconciler::CLOSE_ACTIONS, true)) {
            $this->error(__('billing_console.reconcile.action_required'));

            return self::INVALID;
        }

        if (mb_strlen($reason) < 10) {
            $this->error(__('billing_console.reconcile.reason_required'));

            return self::INVALID;
        }

        $until = null;

        if ($action === LegacySubscriptionReconciler::CLOSE_PAID_UNTIL) {
            $until = $this->untilDate();

            if ($until === null) {
                $this->error(__('billing_console.reconcile.until_required'));

                return self::INVALID;
            }
        }

        try {
            $subscription = $reconciler->close($id, $action, $reason, $until);
        } catch (ValidationException $e) {
            $this->error((string) collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }

        $this->info($action === LegacySubscriptionReconciler::CLOSE_CANCEL
            ? __('billing_console.reconcile.closed_cancel', ['id' => $subscription->id])
            : __('billing_console.reconcile.closed_paid_until', ['id' => $subscription->id, 'date' => $subscription->ends_at?->toDateString()]));

        return self::SUCCESS;
    }

    /** --until no formato AAAA-MM-DD (data real); null se faltar ou for inválida. */
    private function untilDate(): ?CarbonImmutable
    {
        $value = trim((string) $this->option('until'));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function date(mixed $value): string
    {
        return $value instanceof CarbonInterface ? $value->toDateString() : '-';
    }
}
