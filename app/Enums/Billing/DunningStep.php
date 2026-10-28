<?php

namespace App\Enums\Billing;

/**
 * Etapas da régua de cobrança (comando billing:dunning). Cada etapa é
 * registrada uma vez por assinatura e vencimento em
 * `subscription_dunning_steps` — rodar o comando de novo não repete aviso.
 *
 * Cliente que já pagou (cobrança automática):
 *  - Reminder: lembrete antes do vencimento (D-5);
 *  - Overdue: venceu sem pagamento — "pagamento não identificado" com o
 *    link (D0/D+1), acesso normal com aviso;
 *  - Limited: acesso limitado (D+3) — IA e financeiro bloqueados;
 *  - Terminated: assinatura encerrada por inadimplência (D+7), cobrança
 *    parada no gateway.
 *
 * Contratação que nunca pagou (sem régua — o acesso acaba no vencimento):
 *  - FirstChargeOverdue: aviso no dia seguinte ao vencimento;
 *  - FirstChargeTerminated: encerrada no D+7 do vencimento.
 */
enum DunningStep: string
{
    case Reminder              = 'reminder';
    case Overdue               = 'overdue';
    case Limited               = 'limited';
    case Terminated            = 'terminated';
    case FirstChargeOverdue    = 'first_charge_overdue';
    case FirstChargeTerminated = 'first_charge_terminated';

    public function label(): string
    {
        return __("manager_subscriptions.dunning_step.{$this->value}");
    }

    /** Etapa que encerra a assinatura. */
    public function terminates(): bool
    {
        return in_array($this, [self::Terminated, self::FirstChargeTerminated], true);
    }
}
