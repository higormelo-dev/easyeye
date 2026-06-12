<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Models\AiRun;
use App\Enums\AI\AiRunStatus;
use App\Notifications\AiRunWaitingApprovalNotification;
use Illuminate\Console\Command;

/**
 * Onda 4, C5 — Lembra o médico de runs em WaitingApproval há >24h.
 *
 * Idempotência: cada run carrega `notified_pending_at`. O comando notifica se:
 *   - status = WaitingApproval, E
 *   - updated_at < now - 24h (criados/atualizados há mais de 24h), E
 *   - notified_pending_at IS NULL OU notified_pending_at < now - 24h.
 *
 * Roda diário 07:00 e re-notifica 1× por dia até o médico decidir.
 */
class NotifyWaitingApprovalCommand extends Command
{
    protected $signature = 'ai:notify-waiting-approval {--dry-run : Lista runs sem enviar notificação}';

    protected $description = 'Notifica médicos sobre runs IA aguardando decisão há mais de 24h';

    public function handle(): int
    {
        $cutoff = now()->subDay();
        $dryRun = (bool) $this->option('dry-run');

        $runs = AiRun::query()
            ->where('status', AiRunStatus::WaitingApproval->value)
            ->where('updated_at', '<', $cutoff)
            ->where(function ($q) use ($cutoff): void {
                $q->whereNull('notified_pending_at')
                    ->orWhere('notified_pending_at', '<', $cutoff);
            })
            ->with(['requestedBy', 'patient.person', 'entity'])
            ->orderBy('updated_at')
            ->get();

        $count = $runs->count();
        $this->info(($dryRun ? '[dry-run] ' : '') . "Runs a notificar: {$count}");

        if ($count === 0) {
            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($runs as $run) {
            $user = $run->requestedBy;

            if (! $user) {
                $this->warn("Run {$run->id} sem requestedBy resolvido; pulando.");

                continue;
            }

            $patient = $run->patient?->person?->full_name ?? $run->patient?->code ?? '—';

            if ($dryRun) {
                $this->line(" • {$run->id} → {$user->email} (paciente: {$patient}, há {$run->created_at?->diffForHumans()})");

                continue;
            }

            $user->notify(new AiRunWaitingApprovalNotification($run));
            $run->forceFill(['notified_pending_at' => now()])->saveQuietly();
            $sent++;
        }

        if (! $dryRun) {
            $this->info("Notificações enviadas: {$sent}");
        }

        return self::SUCCESS;
    }
}
