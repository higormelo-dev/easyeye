<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Recall\Services\{RecallInviteService, RecallKillSwitch};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rodada automática dos convites de retorno por WhatsApp (ADR 0002, item 9): para cada clínica com o canal ligado nos
 * três níveis (pausa global liberada, chave do Manager e chave da própria clínica), convida os pacientes com retorno
 * a vencer ou vencido — respeitando janela de horário, intervalo mínimo, teto por ciclo e teto diário com ramp-up.
 * A decisão é do RecallInviteGuard sob trava; cada convite ainda é reconferido no momento do envio.
 *
 * Falha inesperada de um paciente é relatada e NÃO interrompe os demais; o comando só falha se alguma ocorreu (as
 * recusas por regra — sem consentimento, teto do dia — são o funcionamento normal e não falham o comando).
 */
class SendRecallInvitesCommand extends Command
{
    protected $signature = 'recall:send-invites {--entity= : só esta clínica (id)} {--dry-run : mostra o que sairia, sem gravar nem enviar}';

    protected $description = 'Convida por WhatsApp os pacientes com retorno a vencer ou vencido (clínicas com o canal ligado)';

    public function handle(RecallInviteService $service, RecallKillSwitch $killSwitch): int
    {
        if ($killSwitch->isPaused()) {
            $this->warn('Convites de retorno pausados pelo EasyEye (pausa global): nada a fazer.');

            return self::SUCCESS;
        }

        $only = (string) $this->option('entity');

        $clinics = DB::table('whatsapp_settings as ws')
            ->join('recall_clinic_settings as rs', 'rs.entity_id', '=', 'ws.entity_id')
            ->where('ws.recall_enabled', true)
            ->where('ws.active', true)
            ->where('rs.whatsapp_invites_enabled', true)
            ->when($only !== '', static fn ($query) => $query->where('ws.entity_id', $only))
            ->orderBy('ws.entity_id')
            ->pluck('ws.entity_id');

        $totals = ['queued' => 0, 'errors' => 0];

        foreach ($clinics as $entityId) {
            $report = $service->runClinic((string) $entityId, null, (bool) $this->option('dry-run'));

            $totals['queued'] += $report['queued'];
            $totals['errors'] += $report['errors'];

            $this->line(sprintf(
                '[%s] candidatos: %d · %s: %d · recusados: %s · falhas: %d%s',
                $entityId,
                $report['candidates'],
                $this->option('dry-run') ? 'sairiam' : 'enfileirados',
                $report['queued'],
                $report['skipped'] === [] ? '0' : json_encode($report['skipped']),
                $report['errors'],
                $report['stopped'] !== null ? " · parou: {$report['stopped']}" : '',
            ));
        }

        $this->info(sprintf('Clínicas: %d · %s: %d · falhas: %d', $clinics->count(), $this->option('dry-run') ? 'sairiam' : 'enfileirados', $totals['queued'], $totals['errors']));

        return $totals['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
