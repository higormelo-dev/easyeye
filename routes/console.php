<?php

use App\Jobs\Billing\ExpireOverdueSubscriptionsJob;
use App\Services\Billing\SubscriptionCycleService;
use App\Services\{ReportSettingService, TrialService};
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\{Artisan, Schedule};

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// F4d — Backfill: sincroniza contents/variables das cópias adoptadas com os
// templates globais. Idempotente. Roda em deploy quando há novos seeds.
Artisan::command('reports:sync-adopted', function () {
    $stats = app(ReportSettingService::class)->syncAdoptedContentsWithGlobal();

    $this->info(sprintf(
        'Sync concluído: %d settings com mudanças | %d contents criados | %d contents atualizados',
        $stats['settings_synced'],
        $stats['contents_created'],
        $stats['contents_updated'],
    ));
})->purpose('Sincroniza contents/variables das cópias adoptadas com os templates globais (F4d backfill)');

// Expira trials vencidos diariamente
Schedule::call(function () {
    app(TrialService::class)->expireOverdueTrials();
})->dailyAt('00:05')->name('trials:expire')->withoutOverlapping();

// Fim de período sem renovação: cortesia expira; cobrança automática sem
// pagamento entra em atraso (via Job para garantir observers)
Schedule::job(new ExpireOverdueSubscriptionsJob())
    ->dailyAt('00:10')
    ->name('subscriptions:expire')
    ->withoutOverlapping();

// Troca de plano agendada (downgrade no fim do período pago): o plano, o
// ciclo e o valor novos passam a valer na data (PlanChangeService). Antes da
// renovação, para a cobrança do dia já sair nos termos novos.
Schedule::call(function () {
    app(SubscriptionCycleService::class)->applyDueScheduledChanges();
})->dailyAt('00:15')->name('subscriptions:plan-changes')->withoutOverlapping();

// Renovação local (gateways sem recorrência própria): emite a cobrança do
// próximo ciclo alguns dias antes do vencimento, uma por período. Gateways
// com recorrência nativa (Asaas) cobram sozinhos — só o webhook atualiza.
Schedule::call(function () {
    app(SubscriptionCycleService::class)->dispatchDueRenewals();
})->dailyAt('01:00')->name('subscriptions:renew')->withoutOverlapping();

// Franquia mensal de IA: concede a janela que começou para cada assinatura
// paga com acesso total (anual/semestral/trimestral também recebem todo mês,
// sem acumular) — depois da expiração da madrugada, que já marcou atrasos.
// Garantia: a reserva e o medidor já concedem a janela vencida na hora.
// Idempotente por assinatura + início da janela.
Schedule::command('ai:grant-monthly-quotas')
    ->dailyAt('00:20')
    ->name('ai:grant-monthly-quotas')
    ->withoutOverlapping();

// Régua de cobrança (lembrete D-5, pagamento não identificado, acesso
// limitado no D+3, encerramento no D+7) — em horário comercial, depois da
// expiração da madrugada. Idempotente: cada etapa sai uma vez por vencimento.
Schedule::command('billing:dunning')
    ->dailyAt('09:00')
    ->name('billing:dunning')
    ->withoutOverlapping();

// Fim do teste grátis: e-mail + WhatsApp aos contatos de cobrança 3 dias
// antes, 1 dia antes e no dia (horário comercial, depois da régua).
// Idempotente por assinatura, passo e data de fim do trial.
Schedule::command('billing:trial-notices')
    ->dailyAt('09:10')
    ->name('billing:trial-notices')
    ->withoutOverlapping();

// billing:retry (BillingRetrySchedule → RetryFailedPaymentJob) saiu do
// agendador: nada cria agendamentos novos e a nova tentativa da renovação é
// do subscriptions:renew (mesma fatura do período). Os pendentes do código
// anterior emitiriam uma 2ª cobrança do mesmo período ou cobrariam
// assinatura já encerrada (o job também se recusa a isso).

// Onda 4, C5 — Notifica médicos sobre runs em WaitingApproval há >24h.
Schedule::command('ai:notify-waiting-approval')
    ->dailyAt('07:00')
    ->name('ai:notify-waiting-approval')
    ->withoutOverlapping();

// Convites de clínica (médico/usuário) vencidos: expira e descarta os dados
// digitados (LGPD).
Schedule::command('clinic-invitations:expire')
    ->dailyAt('03:30')
    ->name('clinic-invitations:expire')
    ->withoutOverlapping();

// Onda 4, C6 — Purga feedbacks antigos para conformidade LGPD (>90 dias).
Schedule::command('ai:purge-feedbacks')
    ->weeklyOn(0, '03:00')
    ->name('ai:purge-feedbacks')
    ->withoutOverlapping();

// Runs de IA presos (worker parado/job perdido) prendem créditos reservados —
// expira e devolve a reserva (compensateFailedRun é idempotente).
Schedule::command('ai:expire-stale-runs')
    ->hourly()
    ->name('ai:expire-stale-runs')
    ->withoutOverlapping();

// WhatsApp (Gupshup) — confirmação de consulta: roda de hora em hora dentro do
// horário comercial; a idempotência é do banco (1 confirmação por consulta),
// então repetição nunca duplica mensagem. Horário restrito por respeito ao
// paciente (nada de mensagem de madrugada).
Schedule::command('whatsapp:send-confirmations')
    ->hourly()
    ->between('8:00', '20:00')
    ->name('whatsapp:send-confirmations')
    ->withoutOverlapping();

// WhatsApp — mensagens presas em `sending` (worker caiu no meio da chamada à
// Gupshup) viram failed unknown_delivery + alerta; nunca reenvia sozinho.
Schedule::command('whatsapp:sweep-stuck')
    ->everyTenMinutes()
    ->name('whatsapp:sweep-stuck')
    ->withoutOverlapping();

// Catálogo global de convênios ← Cadastro de Operadoras da ANS (dados
// abertos, atualizados diariamente). Só lê dados públicos: seguro em
// qualquer ambiente; ANS_OPERATORS_SYNC_ENABLED=false desliga.
Schedule::command('covenants:sync-ans')
    ->weeklyOn(1, '04:30')
    ->when(fn () => (bool) config('covenants.ans.sync_enabled'))
    ->name('covenants:sync-ans')
    ->withoutOverlapping();

// Catálogo global de medicamentos ← lista de preços CMED + dados abertos da
// Anvisa (só lê dados públicos; sem mudança desde a última carga, não
// reprocessa). Terça, longe da sincronização da ANS (segunda 04:30).
// CMED_SYNC_ENABLED=false desliga.
Schedule::command('medicines:sync-cmed')
    ->weeklyOn(2, '05:00')
    ->when(fn () => (bool) config('medicines.cmed.sync_enabled'))
    ->name('medicines:sync-cmed')
    ->withoutOverlapping();

// Catálogo de modelos/preços de IA ← API de cada provedor com chave no .env +
// catálogo público de preços LiteLLM (só leitura; nenhuma chamada gasta
// tokens). Preço editado à mão no painel fica travado.
// AI_CATALOG_SYNC_ENABLED=false desliga.
Schedule::command('ai:sync-model-catalog')
    ->dailyAt('03:40')
    ->when(fn () => (bool) config('ai.catalog_sync.enabled'))
    ->name('ai:sync-model-catalog')
    ->withoutOverlapping();

// WhatsApp (Gupshup) — pesquisa de satisfação pós-atendimento (delay por
// clínica; max_age_days evita spam retroativo ao ativar a feature).
Schedule::command('whatsapp:send-surveys')
    ->hourly()
    ->between('9:00', '20:00')
    ->name('whatsapp:send-surveys')
    ->withoutOverlapping();

// Estoque (GAP fechado): estoque baixo / lote vencendo — cedo de manhã pra
// já aparecer no mural quando a equipe abrir o painel.
Schedule::command('stock:check-alerts')
    ->dailyAt('06:30')
    ->name('stock:check-alerts')
    ->withoutOverlapping();

// Log de tendência da fila do integrador: retenção de 7 dias (pedido do
// usuário). Diário, não semanal como ai:purge-feedbacks — uma janela curta
// de 7 dias precisa de expurgo diário pra não derivar até 13-14 dias de
// acúmulo entre execuções.
Schedule::command('queue-health:prune-history')
    ->dailyAt('03:30')
    ->name('queue-health:prune-history')
    ->withoutOverlapping();

// Canal de comando do backend pro desktop: retenção de 30 dias, só de
// linhas terminais (completed/failed) — ver o doc comment de
// PruneIntegratorCommandsCommand pra por que `pending` nunca é apagado.
// Janela de 30 dias é folgada o bastante pra rodar semanalmente, ao
// contrário do log de 7 dias acima.
Schedule::command('integrator-commands:prune')
    ->weeklyOn(1, '03:45')
    ->name('integrator-commands:prune')
    ->withoutOverlapping();

// Webhooks de billing: retenção (BILLING_WEBHOOK_RETENTION_DAYS, padrão
// 90) só dos processados — com falha ou não processados ficam para análise.
Schedule::command('billing:prune-webhook-events')
    ->dailyAt('03:50')
    ->name('billing:prune-webhook-events')
    ->withoutOverlapping();

// Pedidos de pacote de créditos de IA nunca pagos: descartados depois de
// AI_CREDIT_PACK_PENDING_EXPIRY_DAYS (padrão 7) — não ficam como "em aberto".
Schedule::command('ai:expire-credit-pack-orders')
    ->dailyAt('04:10')
    ->name('ai:expire-credit-pack-orders')
    ->withoutOverlapping();

Schedule::command('integrator-outbox:publish')->everyMinute()->withoutOverlapping();
