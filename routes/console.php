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
        'Sync concluído: %d settings com mudanças | %d contents criados | %d contents atualizados | %d personalizados preservados (atualização disponível na tela de Modelos)',
        $stats['settings_synced'],
        $stats['contents_created'],
        $stats['contents_updated'],
        $stats['contents_skipped_customized'],
    ));
})->purpose('Sincroniza com os modelos padrão as cópias adotadas que a clínica não personalizou (F4d backfill)');

// Convenções do agendador (docs/internal/runbooks/filas-workers.md):
// - onOneServer() em toda entrada: com CACHE_STORE=redis só um nó roda a tarefa; em um nó só é inócuo.
// - runInBackground() nas tarefas que chamam gateway/WhatsApp/Focus ou varrem muito: o agendador não espera por elas.
//   Só existe em Schedule::command (callback e job não suportam). O mutex do withoutOverlapping é liberado no fim de
//   cada execução (schedule:finish); o prazo (10/30 min, 24 h no padrão) só vale se o processo morrer antes.
// - withoutOverlapping(10) nas frequentes: um kill no meio não trava a tarefa por 1 dia (o padrão é 24 h).

// Expira trials vencidos diariamente
Schedule::call(function () {
    app(TrialService::class)->expireOverdueTrials();
})->dailyAt('00:05')->name('trials:expire')->withoutOverlapping()->onOneServer();

// Fim de período sem renovação: cortesia expira; cobrança automática sem
// pagamento entra em atraso (via Job para garantir observers)
Schedule::job(new ExpireOverdueSubscriptionsJob())
    ->dailyAt('00:10')
    ->name('subscriptions:expire')
    ->withoutOverlapping()
    ->onOneServer();

// Troca de plano agendada (downgrade no fim do período pago): o plano, o
// ciclo e o valor novos passam a valer na data (PlanChangeService). Antes da
// renovação, para a cobrança do dia já sair nos termos novos.
Schedule::call(function () {
    app(SubscriptionCycleService::class)->applyDueScheduledChanges();
})->dailyAt('00:15')->name('subscriptions:plan-changes')->withoutOverlapping()->onOneServer();

// Renovação local (gateways sem recorrência própria): emite a cobrança do
// próximo ciclo alguns dias antes do vencimento, uma por período. Gateways
// com recorrência nativa (Asaas) cobram sozinhos — só o webhook atualiza.
Schedule::call(function () {
    app(SubscriptionCycleService::class)->dispatchDueRenewals();
})->dailyAt('01:00')->name('subscriptions:renew')->withoutOverlapping()->onOneServer();

// Franquia mensal de IA: concede a janela que começou para cada assinatura
// paga com acesso total (anual/semestral/trimestral também recebem todo mês,
// sem acumular) — depois da expiração da madrugada, que já marcou atrasos.
// Garantia: a reserva e o medidor já concedem a janela vencida na hora.
// Idempotente por assinatura + início da janela.
Schedule::command('ai:grant-monthly-quotas')
    ->dailyAt('00:20')
    ->name('ai:grant-monthly-quotas')
    ->withoutOverlapping()
    ->onOneServer();

// Conciliação leve antes da régua: faturas vencidas com cobrança no gateway
// são conferidas na API e o pagamento cujo webhook não chegou (atraso, fila
// pausada) é aplicado — teto por execução e parada no 429.
Schedule::command('billing:reconcile-overdue')
    ->dailyAt('08:30')
    ->name('billing:reconcile-overdue')
    ->runInBackground()
    ->withoutOverlapping()
    ->onOneServer();

// Estornos pedidos pelo manager parados em "solicitado": conferidos no
// gateway (sem resposta definitiva: em minutos; os demais depois de 30 dias)
// e liberados só quando o gateway diz que não existem ou foram negados.
Schedule::command('billing:check-refunds')
    ->hourly()
    ->name('billing:check-refunds')
    ->runInBackground()
    ->withoutOverlapping(10)
    ->onOneServer();

// Health check real dos gateways (Asaas: GET /v3/myAccount/status/) — mantém
// a chave em uso (3 meses sem uso = desabilitada) e alerta o time quando a
// credencial é recusada ou é de outro ambiente.
Schedule::command('billing:gateway-health')
    ->dailyAt('06:10')
    ->name('billing:gateway-health')
    ->runInBackground()
    ->withoutOverlapping()
    ->onOneServer();

// Régua de cobrança (lembrete D-5, pagamento não identificado, acesso
// limitado no D+3, encerramento no D+7) — em horário comercial, depois da
// expiração da madrugada. Idempotente: cada etapa sai uma vez por vencimento.
Schedule::command('billing:dunning')
    ->dailyAt('09:00')
    ->name('billing:dunning')
    ->runInBackground()
    ->withoutOverlapping()
    ->onOneServer();

// Fim do teste grátis: e-mail + WhatsApp aos contatos de cobrança 3 dias
// antes, 1 dia antes e no dia (horário comercial, depois da régua).
// Idempotente por assinatura, passo e data de fim do trial.
Schedule::command('billing:trial-notices')
    ->dailyAt('09:10')
    ->name('billing:trial-notices')
    ->withoutOverlapping()
    ->onOneServer();

// billing:retry (BillingRetrySchedule → RetryFailedPaymentJob) saiu do
// agendador: nada cria agendamentos novos e a nova tentativa da renovação é
// do subscriptions:renew (mesma fatura do período). Os pendentes do código
// anterior emitiriam uma 2ª cobrança do mesmo período ou cobrariam
// assinatura já encerrada (o job também se recusa a isso).

// Onda 4, C5 — Notifica médicos sobre runs em WaitingApproval há >24h.
Schedule::command('ai:notify-waiting-approval')
    ->dailyAt('07:00')
    ->name('ai:notify-waiting-approval')
    ->withoutOverlapping()
    ->onOneServer();

// Convites de revisão clínica (Fase 6) vencidos: expiram e perdem o rascunho do parecer.
Schedule::command('clinical-review:expire')
    ->dailyAt('03:20')
    ->name('clinical-review:expire')
    ->withoutOverlapping()
    ->onOneServer();

// Integridade dos protocolos de retorno (Fase 6): refaz o hash do texto gravado de cada versão em uso
// ou em revisão; a que divergir sai de uso (suspensa/retirada) e o comando FALHA para o monitoramento ver.
Schedule::command('recall:verify-integrity')
    ->dailyAt('05:15')
    ->name('recall:verify-integrity')
    ->runInBackground()
    ->withoutOverlapping()
    ->onOneServer();

// Recall de pacientes (Fase 6): reavalia, a cada minuto, os pacientes que um ato clínico marcou na outbox
// (prontuário, exame, agenda, medicação, cadastro, condição) — quem grava nunca espera a avaliação.
Schedule::command('recall:evaluate-dirty')
    ->everyMinute()
    ->name('recall:evaluate-dirty')
    ->runInBackground()
    ->withoutOverlapping(10)
    ->onOneServer();

// Conciliação noturna do recall: rede de segurança da outbox (gravação em massa, importação, prazos que
// passaram com o relógio). Falha de paciente é relatada e o comando falha para o monitoramento ver.
Schedule::command('recall:reconcile')
    ->dailyAt('02:30')
    ->name('recall:reconcile')
    ->runInBackground()
    ->withoutOverlapping()
    ->onOneServer();

// Convites de retorno por WhatsApp (Fase 6): de hora em hora convida quem tem retorno a vencer/vencido, só nas clínicas
// com o canal ligado nos três níveis e dentro da janela de horário de cada uma. O ritmo (intervalo mínimo, teto por
// ciclo, teto diário com ramp-up) é do RecallInviteGuard; cada convite é reconferido no momento do envio.
Schedule::command('recall:send-invites')
    ->hourlyAt(5)
    ->name('recall:send-invites')
    ->runInBackground()
    ->withoutOverlapping(30)
    ->onOneServer();

// Rede de segurança do convite: reenfileira o que ficou pending sem job e cancela o que não saiu no prazo.
Schedule::command('recall:requeue-pending')
    ->everyTenMinutes()
    ->name('recall:requeue-pending')
    ->withoutOverlapping(10)
    ->onOneServer();

// Convites de clínica (médico/usuário) vencidos: expira e descarta os dados
// digitados (LGPD).
Schedule::command('clinic-invitations:expire')
    ->dailyAt('03:30')
    ->name('clinic-invitations:expire')
    ->withoutOverlapping()
    ->onOneServer();

// Autosave do prontuário: rascunhos parados há mais de 7 dias (dado de saúde
// não salvo pelo médico) — LGPD, não guardar além do necessário.
Schedule::command('medical-records:prune-drafts')
    ->dailyAt('03:40')
    ->name('medical-records:prune-drafts')
    ->withoutOverlapping()
    ->onOneServer();

// Onda 4, C6 — Purga feedbacks antigos para conformidade LGPD (>90 dias).
Schedule::command('ai:purge-feedbacks')
    ->weeklyOn(0, '03:00')
    ->name('ai:purge-feedbacks')
    ->withoutOverlapping()
    ->onOneServer();

// Runs de IA presos (worker parado/job perdido) prendem créditos reservados —
// expira e devolve a reserva (compensateFailedRun é idempotente).
Schedule::command('ai:expire-stale-runs')
    ->hourly()
    ->name('ai:expire-stale-runs')
    ->withoutOverlapping(10)
    ->onOneServer();

// WhatsApp (Gupshup) — confirmação de consulta: roda de hora em hora dentro do
// horário comercial; a idempotência é do banco (1 confirmação por consulta),
// então repetição nunca duplica mensagem. Horário restrito por respeito ao
// paciente (nada de mensagem de madrugada).
Schedule::command('whatsapp:send-confirmations')
    ->hourly()
    ->between('8:00', '20:00')
    ->name('whatsapp:send-confirmations')
    ->runInBackground()
    ->withoutOverlapping(10)
    ->onOneServer();

// WhatsApp — mensagens presas em `sending` (worker caiu no meio da chamada à
// Gupshup) viram failed unknown_delivery + alerta; nunca reenvia sozinho.
Schedule::command('whatsapp:sweep-stuck')
    ->everyTenMinutes()
    ->name('whatsapp:sweep-stuck')
    ->withoutOverlapping(10)
    ->onOneServer();

// Catálogo global de convênios ← Cadastro de Operadoras da ANS (dados
// abertos, atualizados diariamente). Só lê dados públicos: seguro em
// qualquer ambiente; ANS_OPERATORS_SYNC_ENABLED=false desliga.
Schedule::command('covenants:sync-ans')
    ->weeklyOn(1, '04:30')
    ->when(fn () => (bool) config('covenants.ans.sync_enabled'))
    ->name('covenants:sync-ans')
    ->withoutOverlapping()
    ->onOneServer();

// Catálogo global de medicamentos ← lista de preços CMED + dados abertos da
// Anvisa (só lê dados públicos; sem mudança desde a última carga, não
// reprocessa). Terça, longe da sincronização da ANS (segunda 04:30).
// CMED_SYNC_ENABLED=false desliga.
Schedule::command('medicines:sync-cmed')
    ->weeklyOn(2, '05:00')
    ->when(fn () => (bool) config('medicines.cmed.sync_enabled'))
    ->name('medicines:sync-cmed')
    ->withoutOverlapping()
    ->onOneServer();

// Catálogo de modelos/preços de IA ← API de cada provedor com chave no .env +
// catálogo público de preços LiteLLM (só leitura; nenhuma chamada gasta
// tokens). Preço editado à mão no painel fica travado.
// AI_CATALOG_SYNC_ENABLED=false desliga.
Schedule::command('ai:sync-model-catalog')
    ->dailyAt('03:40')
    ->when(fn () => (bool) config('ai.catalog_sync.enabled'))
    ->name('ai:sync-model-catalog')
    ->withoutOverlapping()
    ->onOneServer();

// WhatsApp (Gupshup) — pesquisa de satisfação pós-atendimento (delay por
// clínica; max_age_days evita spam retroativo ao ativar a feature).
Schedule::command('whatsapp:send-surveys')
    ->hourly()
    ->between('9:00', '20:00')
    ->name('whatsapp:send-surveys')
    ->runInBackground()
    ->withoutOverlapping(10)
    ->onOneServer();

// Estoque (GAP fechado): estoque baixo / lote vencendo — cedo de manhã pra
// já aparecer no mural quando a equipe abrir o painel.
Schedule::command('stock:check-alerts')
    ->dailyAt('06:30')
    ->name('stock:check-alerts')
    ->withoutOverlapping()
    ->onOneServer();

// Log de tendência da fila do integrador: retenção de 7 dias (pedido do
// usuário). Diário, não semanal como ai:purge-feedbacks — uma janela curta
// de 7 dias precisa de expurgo diário pra não derivar até 13-14 dias de
// acúmulo entre execuções.
Schedule::command('queue-health:prune-history')
    ->dailyAt('03:30')
    ->name('queue-health:prune-history')
    ->withoutOverlapping()
    ->onOneServer();

// Canal de comando do backend pro desktop: retenção de 30 dias, só de
// linhas terminais (completed/failed) — ver o doc comment de
// PruneIntegratorCommandsCommand pra por que `pending` nunca é apagado.
// Janela de 30 dias é folgada o bastante pra rodar semanalmente, ao
// contrário do log de 7 dias acima.
Schedule::command('integrator-commands:prune')
    ->weeklyOn(1, '03:45')
    ->name('integrator-commands:prune')
    ->withoutOverlapping()
    ->onOneServer();

// Webhooks de billing: retenção (BILLING_WEBHOOK_RETENTION_DAYS, padrão
// 90) só dos processados — com falha ou não processados ficam para análise.
Schedule::command('billing:prune-webhook-events')
    ->dailyAt('03:50')
    ->name('billing:prune-webhook-events')
    ->withoutOverlapping()
    ->onOneServer();

// Pedidos de pacote de créditos de IA nunca pagos: descartados depois de
// AI_CREDIT_PACK_PENDING_EXPIRY_DAYS (padrão 7) — não ficam como "em aberto".
Schedule::command('ai:expire-credit-pack-orders')
    ->dailyAt('04:10')
    ->name('ai:expire-credit-pack-orders')
    ->withoutOverlapping()
    ->onOneServer();

// Publica a outbox do integrador a cada minuto. Em segundo plano para um destino lento não segurar o agendador; o
// mutex é liberado no fim de cada execução (schedule:finish) e os 10 min só valem se o processo morrer antes disso.
Schedule::command('integrator-outbox:publish')
    ->everyMinute()
    ->name('integrator-outbox:publish')
    ->runInBackground()
    ->withoutOverlapping(10)
    ->onOneServer();

// Módulo cirúrgico (Fase 4): autorização cuja validade passou vira evento
// `expired` e o caso volta a aguardar renovação — idempotente.
Schedule::command('surgery:expire-authorizations')
    ->dailyAt('00:25')
    ->name('surgery:expire-authorizations')
    ->withoutOverlapping()
    ->onOneServer();

// NFS-e do SaaS (Manager → Nota fiscal): emite a nota das faturas pagas que ficaram sem ela (listener que falhou,
// emissão automática ligada depois do pagamento, cadastro corrigido). Só age com a emissão automática ligada; no máximo
// 3 notas por fatura e nunca nota cancelada. `--dry-run` mostra o que faria.
Schedule::command('fiscal:nfse-reissue')
    ->dailyAt('05:10')
    ->name('fiscal:nfse-reissue')
    ->runInBackground()
    ->withoutOverlapping()
    ->onOneServer();

// NFS-e do SaaS: rede de segurança do webhook da Focus. Consulta as notas paradas em "processando" e reenfileira o envio
// das que nunca saíram (job perdido). A Focus retenta o webhook por até 48 h; depois disso só a consulta direta resolve.
Schedule::command('fiscal:nfse-sync')
    ->everyTenMinutes()
    ->name('fiscal:nfse-sync')
    ->runInBackground()
    ->withoutOverlapping(10)
    ->onOneServer();

// Falhas de job antigas: 30 dias bastam para diagnóstico; sem isto failed_jobs cresce sem teto.
Schedule::command('queue:prune-failed --hours=720')
    ->dailyAt('04:20')
    ->name('queue:prune-failed')
    ->withoutOverlapping(10)
    ->onOneServer();
