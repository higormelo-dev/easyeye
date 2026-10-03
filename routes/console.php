<?php

use App\Jobs\Billing\{ExpireOverdueSubscriptionsJob, RenewSubscriptionJob, RetryFailedPaymentJob};
use App\Models\Billing\BillingRetrySchedule;
use App\Models\Subscription;
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

// Expira assinaturas pagas vencidas (via Job para garantir observers)
Schedule::job(new ExpireOverdueSubscriptionsJob())
    ->dailyAt('00:10')
    ->name('subscriptions:expire')
    ->withoutOverlapping();

// Processa renovações de assinaturas com next_billing_at vencido
Schedule::call(function () {
    Subscription::query()
        ->where('status', 'active')
        ->whereNotNull('next_billing_at')
        ->where('next_billing_at', '<=', now())
        ->select('id')
        ->each(fn ($sub) => RenewSubscriptionJob::dispatch($sub->id));
})->dailyAt('01:00')->name('subscriptions:renew')->withoutOverlapping();

// Processa retries de pagamentos falhos (2× ao dia)
Schedule::call(function () {
    BillingRetrySchedule::due()
        ->select('id')
        ->each(fn ($schedule) => RetryFailedPaymentJob::dispatch($schedule->id));
})->twiceDaily(9, 15)->name('billing:retry')->withoutOverlapping();

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

// WhatsApp (Z-API) — confirmação de consulta: roda de hora em hora dentro do
// horário comercial; a idempotência é do banco (1 confirmação por consulta),
// então repetição nunca duplica mensagem. Horário restrito por respeito ao
// paciente (nada de mensagem de madrugada).
Schedule::command('whatsapp:send-confirmations')
    ->hourly()
    ->between('8:00', '20:00')
    ->name('whatsapp:send-confirmations')
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

// WhatsApp (Z-API) — pesquisa de satisfação pós-atendimento (delay por
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

Schedule::command('integrator-outbox:publish')->everyMinute()->withoutOverlapping();
