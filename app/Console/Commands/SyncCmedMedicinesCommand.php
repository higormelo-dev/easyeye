<?php

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Jobs\ProcessMedicineImportJob;
use App\Models\MedicineImport;
use Illuminate\Console\Command;

/**
 * Verificação semanal do catálogo global de medicamentos com as fontes
 * oficiais (lista de preços CMED + dados abertos da Anvisa). Só lê dados
 * públicos; se nada mudou desde a última carga, termina sem reprocessar.
 */
class SyncCmedMedicinesCommand extends Command
{
    protected $signature = 'medicines:sync-cmed';

    protected $description = 'Atualiza o catálogo global de medicamentos com a lista de preços CMED e os dados abertos da Anvisa';

    public function handle(): int
    {
        $running = MedicineImport::query()
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->exists();

        if ($running) {
            $this->info('Já existe uma carga do catálogo de medicamentos em andamento.');

            return self::SUCCESS;
        }

        $import = MedicineImport::query()->create([
            'user_id' => null,
            'source'  => MedicineImport::SOURCE_SCHEDULED,
            'force'   => false,
            'status'  => ImportStatus::Pending,
        ]);

        ProcessMedicineImportJob::dispatch($import);

        $this->info('Sincronização com a CMED/Anvisa enfileirada.');

        return self::SUCCESS;
    }
}
