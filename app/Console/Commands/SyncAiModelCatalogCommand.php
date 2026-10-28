<?php

namespace App\Console\Commands;

use App\Domains\AI\Models\AiCatalogSync;
use App\Enums\ImportStatus;
use App\Jobs\SyncAiModelCatalogJob;
use Illuminate\Console\Command;

/**
 * Verificação diária do catálogo de modelos/preços de IA: modelos pela API
 * de cada provedor com chave no .env + preços do catálogo público LiteLLM.
 * Só leitura (nenhuma chamada gasta tokens).
 */
class SyncAiModelCatalogCommand extends Command
{
    protected $signature = 'ai:sync-model-catalog';

    protected $description = 'Sincroniza os modelos e preços dos provedores de IA (API dos provedores + catálogo LiteLLM)';

    public function handle(): int
    {
        $running = AiCatalogSync::query()
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->exists();

        if ($running) {
            $this->info('Já existe uma sincronização do catálogo de IA em andamento.');

            return self::SUCCESS;
        }

        $sync = AiCatalogSync::query()->create([
            'user_id' => null,
            'source'  => AiCatalogSync::SOURCE_SCHEDULED,
            'status'  => ImportStatus::Pending,
        ]);

        SyncAiModelCatalogJob::dispatch($sync);

        $this->info('Sincronização do catálogo de IA enfileirada.');

        return self::SUCCESS;
    }
}
