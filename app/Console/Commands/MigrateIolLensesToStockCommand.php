<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EntityProduct;
use App\Services\IolLensStockBridgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill de dados (fase 2 do plano de migração de lentes IOL pro estoque)
 * — NÃO mexe em schema, só em dado. Pré-requisito pra migration
 * `2026_09_16_090300_tighten_entity_iol_lenses_stock_link` (que torna
 * entity_product_id NOT NULL + UNIQUE e apaga as colunas legadas) poder
 * rodar sem quebrar: essa migration falha alto e cedo (guarda própria) se
 * ainda houver linha sem entity_product_id ou com duplicata.
 *
 * Lê as colunas legadas (manufacturer/model_name/price/image_path/active)
 * via query builder cru (DB::table), não via o model EntityIolLens — o
 * model já foi atualizado pro schema FINAL (pós fase 3) e não declara mais
 * essas colunas; elas continuam existindo fisicamente até a migration de
 * aperto rodar, então a leitura crua funciona normalmente nesse meio-tempo.
 *
 * Uso:
 *   php artisan iol-lenses:migrate-to-stock            # dry-run, lista o que faria
 *   php artisan iol-lenses:migrate-to-stock --force    # aplica
 *
 * Idempotente: rodar de novo depois de aplicado não encontra mais linha
 * pendente (entity_product_id já preenchido em todas), então não faz nada.
 *
 * Sequência recomendada: dry-run em staging → --force em staging → dry-run
 * em produção → --force em produção → só então deploy da migration de
 * aperto de schema (fase 3), separado.
 */
class MigrateIolLensesToStockCommand extends Command
{
    protected $signature = 'iol-lenses:migrate-to-stock
                            {--force : aplica as alterações (sem isso é dry-run)}';

    protected $description = 'Cria/associa um EntityProduct real pra cada lente IOL da clínica (backfill pré-migração pro módulo de estoque).';

    public function handle(IolLensStockBridgeService $bridge): int
    {
        $force = (bool) $this->option('force');

        $duplicated = $this->findDuplicatedProductLinks();

        if ($duplicated->isNotEmpty()) {
            $this->warn(sprintf('%d entity_product_id duplicado(s) entre lentes diferentes da mesma clínica:', $duplicated->count()));

            foreach ($duplicated as $row) {
                $this->line("  • entity_product_id={$row->entity_product_id} usado por {$row->qty} lentes");
            }

            if (! $force) {
                $this->comment('Dry-run: a segunda/terceira lente de cada grupo receberia um EntityProduct PRÓPRIO (dados dela mesma, nunca fundido/clonado do produto original). Rode com --force pra resolver.');
            } else {
                $this->resolveDuplicatedLinks($duplicated, $bridge);
                $this->info('Duplicatas resolvidas (produto próprio pra cada lente extra).');
            }
        }

        $pending = DB::table('entity_iol_lenses')->whereNull('entity_product_id')->get();

        if ($pending->isEmpty()) {
            $this->info('Nenhuma lente pendente de vínculo com o estoque. Nada a fazer.');
        } else {
            $this->warn(sprintf('%d lente(s) sem EntityProduct vinculado, agrupadas por clínica:', $pending->count()));

            foreach ($pending->groupBy('entity_id') as $entityId => $rows) {
                $this->line("  • entity_id={$entityId}: {$rows->count()} lente(s)");
            }
        }

        $gaps = $this->findBackfillableGaps();

        if ($gaps->isNotEmpty()) {
            $this->warn(sprintf('%d lente(s) já vinculada(s) manualmente com manufacturer/imagem faltando no produto — seriam completadas.', $gaps->count()));
        }

        if (! $force) {
            $this->newLine();
            $this->comment('Dry-run. Rode novamente com --force para aplicar.');

            return self::SUCCESS;
        }

        if ($pending->isNotEmpty()) {
            $this->createMissingProducts($pending, $bridge);
        }

        if ($gaps->isNotEmpty()) {
            $this->backfillGaps($gaps);
        }

        $this->info('Concluído.');

        return self::SUCCESS;
    }

    /**
     * Sem unicidade de entity_product_id no schema atual (só passa a
     * existir na migration de aperto), duas lentes podem já apontar pro
     * MESMO produto (vínculo manual antigo/GAP-FILL) — a migration de
     * aperto rejeitaria isso. Detecta antes de qualquer escrita.
     */
    private function findDuplicatedProductLinks()
    {
        return DB::table('entity_iol_lenses')
            ->select('entity_product_id', DB::raw('COUNT(*) as qty'))
            ->whereNotNull('entity_product_id')
            ->groupBy('entity_product_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();
    }

    /**
     * Pra cada produto duplicado, mantém o vínculo na lente mais ANTIGA
     * (created_at) no produto original e cria um EntityProduct NOVO E
     * PRÓPRIO pra cada lente adicional que apontava pro mesmo produto —
     * nunca funde, nunca apaga, nunca deixa a lente extra sem estoque
     * próprio.
     *
     * BUGFIX (achado na verificação manual fim-a-fim): a primeira versão
     * clonava o produto ORIGINAL (`$sourceProduct->replicate()`) — errado:
     * duas lentes que colidiram no mesmo entity_product_id (vínculo manual
     * antigo, GAP-FILL) quase sempre são lentes DIFERENTES (fabricante/
     * modelo/preço próprios, snapshot nas colunas legadas de cada uma) que
     * só compartilhavam o produto por engano. Clonar o produto original
     * dava o NOME/PREÇO ERRADO pro produto da lente extra. Agora usa os
     * dados da PRÓPRIA lente extra (mesmas colunas legadas lidas em
     * createMissingProducts()), igual a uma lente pendente comum.
     */
    private function resolveDuplicatedLinks($duplicated, IolLensStockBridgeService $bridge): void
    {
        foreach ($duplicated as $row) {
            $lensRows = DB::table('entity_iol_lenses')
                ->where('entity_product_id', $row->entity_product_id)
                ->orderBy('created_at')
                ->get();

            $original = $lensRows->shift(); // fica com o produto original
            $this->line("  • mantendo vínculo original na lente {$original->id}");

            foreach ($lensRows as $extra) {
                $product = EntityProduct::create([
                    'entity_id'           => $extra->entity_id,
                    'product_category_id' => $bridge->findOrCreateCategory($extra->entity_id)->id,
                    'name'                => $extra->model_name,
                    'manufacturer'        => $extra->manufacturer,
                    'unit'                => 'un',
                    'is_opm'     => true,
                    'sale_price' => $extra->price,
                    'image_path' => $extra->image_path,
                    'active'     => (bool) $extra->active,
                ]);

                DB::table('entity_iol_lenses')
                    ->where('id', $extra->id)
                    ->update(['entity_product_id' => $product->id]);

                $this->line("  • lente {$extra->id} ({$extra->manufacturer} {$extra->model_name}): produto próprio {$product->code} criado (não compartilha mais com {$row->entity_product_id})");
            }
        }
    }

    private function createMissingProducts($pending, IolLensStockBridgeService $bridge): void
    {
        DB::transaction(function () use ($pending, $bridge) {
            foreach ($pending as $row) {
                $product = EntityProduct::create([
                    'entity_id'           => $row->entity_id,
                    'product_category_id' => $bridge->findOrCreateCategory($row->entity_id)->id,
                    'name'                => $row->model_name,
                    'manufacturer'        => $row->manufacturer,
                    'unit'                => 'un',
                    // TODO(fase-3-tiss): ver nota equivalente em
                    // IolLensStockBridgeService::create().
                    'is_opm'     => true,
                    'sale_price' => $row->price,
                    'image_path' => $row->image_path,
                    'active'     => (bool) $row->active,
                ]);

                DB::table('entity_iol_lenses')
                    ->where('id', $row->id)
                    ->update(['entity_product_id' => $product->id]);

                $this->line("  ✓ lente {$row->id} ({$row->manufacturer} {$row->model_name}) → produto {$product->code}");
            }
        });
    }

    /**
     * Lentes JÁ vinculadas manualmente (GAP-FILL antigo) cujo produto não
     * tem manufacturer/imagem — preenche só o que está faltando, nunca
     * sobrescreve customização que a clínica já tenha feito no produto.
     */
    /**
     * BUGFIX (achado na verificação manual fim-a-fim): a versão anterior
     * flagava a lente sempre que o PRODUTO estivesse sem manufacturer/
     * imagem, mesmo quando a LENTE também não tinha nada pra doar (os dois
     * lados null) — inofensivo (backfillGaps() não escrevia nada mesmo
     * assim, `if ($update === []) continue`), mas quebrava a idempotência
     * do relatório: rodar o comando de novo sempre "encontrava" a mesma
     * lente como pendente pra sempre, mesmo já totalmente processada.
     * Agora só entra na lista quando existe algo REAL pra copiar (lado da
     * lente preenchido, lado do produto vazio).
     */
    private function findBackfillableGaps()
    {
        return DB::table('entity_iol_lenses as l')
            ->join('entity_products as p', 'p.id', '=', 'l.entity_product_id')
            ->whereNotNull('l.entity_product_id')
            ->where(fn ($q) => $q
                ->where(fn ($qq) => $qq->whereNull('p.manufacturer')->whereNotNull('l.manufacturer'))
                ->orWhere(fn ($qq) => $qq->whereNull('p.image_path')->whereNotNull('l.image_path')))
            ->select('l.id as lens_id', 'l.manufacturer', 'l.image_path', 'p.id as product_id', 'p.manufacturer as product_manufacturer', 'p.image_path as product_image_path')
            ->get();
    }

    private function backfillGaps($gaps): void
    {
        DB::transaction(function () use ($gaps) {
            foreach ($gaps as $row) {
                $update = [];

                if ($row->product_manufacturer === null && $row->manufacturer !== null) {
                    $update['manufacturer'] = $row->manufacturer;
                }

                if ($row->product_image_path === null && $row->image_path !== null) {
                    $update['image_path'] = $row->image_path;
                }

                if ($update === []) {
                    continue;
                }

                EntityProduct::where('id', $row->product_id)->update($update);
                $this->line("  ✓ produto {$row->product_id}: completado ".implode(', ', array_keys($update)));
            }
        });
    }
}
