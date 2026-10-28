<?php

namespace Database\Seeders;

use App\Domains\Recall\Models\RecallProtocol;
use App\Domains\Recall\Services\ProtocolCatalogService;
use App\Domains\Recall\Support\ProposedProtocolDrafts;
use Illuminate\Database\Seeder;

/**
 * Cria os protocolos de retorno (recall) propostos pela EasyEye — SEMPRE em
 * RASCUNHO. Nenhum seeder nem migration pode criar versão além de `draft`: quem
 * congela, envia ao Revisor Clínico, aceita e libera é o Manager (ADR 0002,
 * invariante I8; há teste que guarda isto).
 *
 * Idempotente e não destrutivo: só cria o protocolo cuja `key` ainda não existe e
 * NUNCA mexe em protocolo existente (o conteúdo pode já ter sido editado,
 * congelado ou validado).
 */
class RecallProtocolDraftsSeeder extends Seeder
{
    public function run(ProtocolCatalogService $catalog): void
    {
        foreach (ProposedProtocolDrafts::all() as $key => $proposal) {
            if (RecallProtocol::query()->where('key', $key)->exists()) {
                continue;
            }

            $catalog->createProtocol($key, $proposal['translations'], $proposal['content'], $proposal['sort_order']);
        }
    }
}
