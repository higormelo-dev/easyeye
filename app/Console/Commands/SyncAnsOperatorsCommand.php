<?php

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Jobs\ProcessCovenantImportJob;
use App\Models\CovenantImport;
use Illuminate\Console\Command;

/**
 * Sincronização semanal do catálogo global de convênios com o Cadastro de
 * Operadoras da ANS (dados abertos). Só lê dados públicos e atualiza o
 * catálogo — sem efeito externo (cobrança, mensagem a paciente).
 */
class SyncAnsOperatorsCommand extends Command
{
    protected $signature = 'covenants:sync-ans';

    protected $description = 'Atualiza o catálogo global de convênios com a lista de operadoras da ANS';

    public function handle(): int
    {
        $running = CovenantImport::query()
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->exists();

        if ($running) {
            $this->info('Já existe uma sincronização em andamento.');

            return self::SUCCESS;
        }

        $import = CovenantImport::query()->create([
            'user_id'    => null,
            'source'     => CovenantImport::SOURCE_SCHEDULED,
            'status'     => ImportStatus::Pending,
            'modalities' => config('covenants.ans.default_modalities'),
        ]);

        ProcessCovenantImportJob::dispatch($import);

        $this->info('Sincronização com a ANS enfileirada.');

        return self::SUCCESS;
    }
}
