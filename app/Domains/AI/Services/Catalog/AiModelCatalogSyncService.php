<?php

declare(strict_types=1);

namespace App\Domains\AI\Services\Catalog;

use App\Domains\AI\Models\{AiCatalogSync, AiModelPrice};
use App\Domains\AI\Repositories\EloquentAiModelPriceRepository;
use App\Domains\AI\Services\{AiProviderSettings, AiUsdBrlRate};
use App\Enums\AI\AiProvider;
use App\Enums\ImportStatus;
use Illuminate\Support\{Collection, Number};
use Illuminate\Support\Facades\{DB, Log};
use Throwable;

/**
 * Sincroniza o catálogo de modelos/preços de IA (ai_model_prices) — botão
 * "Sincronizar agora" (Manager → Provedores de IA) ou verificação diária:
 *
 * 1. Baixa o catálogo de preços LiteLLM (USD por token → por 1M).
 * 2. Para cada provedor com chave no .env, lista os modelos pela API oficial.
 * 3. Modelos já cadastrados: preço atualizado quando mudou — exceto preço
 *    travado (editado à mão) e variação suspeita (> fator configurado), que
 *    só aparecem no resultado para revisão. Sem chave, só confere preços.
 * 4. Modelos novos com preço entram INATIVOS (o admin escolhe o que usar);
 *    o modelo em uso do provedor entra ativo. Sem preço: não entra (não há
 *    como cobrar) — fica listado no resultado.
 * 5. Modelo que o provedor deixou de oferecer é marcado (unlisted_at) para a
 *    tela avisar — nunca desativado sozinho (a cobrança não quebra).
 *
 * Nenhuma chamada gasta tokens. Chaves só no .env; nada de segredo no banco.
 */
class AiModelCatalogSyncService
{
    /** Amostra guardada por lista nos detalhes (o total vai nos contadores). */
    private const DETAILS_LIMIT = 50;

    /** Diferença de preço desprezível (arredondamento). */
    private const EPSILON = 0.000001;

    private const COUNTERS = [
        'models_listed', 'created_count', 'updated_count', 'unchanged_count',
        'locked_count', 'unlisted_count', 'missing_price_count', 'suspicious_count',
    ];

    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $details = [];

    /** Cotação estimada (sem recarga registrada) usada na conversão de R$. */
    private bool $estimatedRate = false;

    public function __construct(
        private readonly LiteLlmPriceCatalog $catalog,
        private readonly AiProviderModelLister $lister,
        private readonly AiProviderSettings $settings,
        private readonly AiUsdBrlRate $usdBrl,
    ) {
    }

    public function run(AiCatalogSync $sync): void
    {
        $this->counters      = array_fill_keys(self::COUNTERS, 0);
        $this->details       = [];
        $this->estimatedRate = false;

        $sync->update([
            'status'          => ImportStatus::Processing,
            'phase'           => AiCatalogSync::PHASE_PRICES,
            'total_providers' => count(AiProvider::cases()),
            'started_at'      => now(),
            'error'           => null,
        ]);

        try {
            $prices = $this->withStaticPrices($this->catalog->load());

            $sync->update(['phase' => AiCatalogSync::PHASE_MODELS, 'prices_version' => $prices->version]);

            $results = [];

            foreach (AiProvider::cases() as $provider) {
                // Cancelada pelo admin (estava parada): não continua.
                if ($this->cancelled($sync)) {
                    return;
                }

                $results[$provider->value] = $this->syncProvider($provider, $prices);

                $sync->update([
                    'processed_providers' => count($results),
                    'providers'           => $results,
                    ...$this->counters,
                ]);
            }

            if ($this->cancelled($sync)) {
                return;
            }

            $sync->update([
                ...$this->counters,
                'status'      => ImportStatus::Done,
                'phase'       => null,
                'details'     => $this->details,
                'notice'      => $this->notice($results),
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            if (! $e instanceof AiCatalogSyncException) {
                Log::error('Falha na sincronização do catálogo de modelos de IA', [
                    'sync_id' => $sync->id,
                    'error'   => $e->getMessage(),
                    'at'      => basename($e->getFile()) . ':' . $e->getLine(),
                ]);
            }

            $sync->update([
                ...$this->counters,
                'status'      => ImportStatus::Failed,
                'phase'       => null,
                'details'     => $this->details ?: null,
                'error'       => $e instanceof AiCatalogSyncException ? $e->getMessage() : __('manager_ai.sync_failed_generic'),
                'finished_at' => now(),
            ]);
        }
    }

    /**
     * @return array{status: string, listed: int, message: ?string}
     */
    private function syncProvider(AiProvider $provider, PriceCatalog $prices): array
    {
        $code   = $provider->value;
        $listed = null;
        $result = ['status' => AiCatalogSync::PROVIDER_SKIPPED, 'listed' => 0, 'message' => __('manager_ai.sync_provider_no_key')];

        if (config("ai.providers.{$code}.catalog_listing") === false) {
            // Azure: a API lista modelos-base, não os deployments — os
            // deployments entram no catálogo à mão; aqui só se conferem os preços.
            $result = ['status' => AiCatalogSync::PROVIDER_SKIPPED, 'listed' => 0, 'message' => __('manager_ai.sync_provider_manual_models')];
        } elseif (filled(config("services.{$code}.api_key"))) {
            try {
                $listed = $this->lister->list($provider);
                $result = ['status' => AiCatalogSync::PROVIDER_OK, 'listed' => count($listed), 'message' => null];
            } catch (AiCatalogSyncException $e) {
                $result = ['status' => AiCatalogSync::PROVIDER_FAILED, 'listed' => 0, 'message' => mb_substr($e->getMessage(), 0, 160)];
            }
        }

        $this->counters['models_listed'] += $result['listed'];

        $rows = $this->currentRows($code);

        $this->reconcileExisting($provider, $rows, $prices, $listed);

        if ($listed !== null) {
            $this->discover($provider, $rows, $prices, $listed);
        }

        return $result;
    }

    /**
     * Linha vigente de cada modelo (sem fim de vigência; a mais recente).
     *
     * @return Collection<int, AiModelPrice>
     */
    private function currentRows(string $code): Collection
    {
        return AiModelPrice::query()
            ->where('provider', $code)
            ->whereNull('effective_until')
            ->orderByDesc('effective_from')
            ->get()
            ->unique('model')
            ->values();
    }

    /**
     * @param Collection<int, AiModelPrice>     $rows
     * @param array<string, ?CatalogPrice>|null $listed
     */
    private function reconcileExisting(AiProvider $provider, Collection $rows, PriceCatalog $prices, ?array $listed): void
    {
        $now        = now();
        $confirmed  = [];
        $relisted   = [];
        $unlisted   = [];
        $listedBase = $listed === null ? [] : $this->bases(array_keys($listed));
        $inUse      = $this->settings->model($provider->value);

        foreach ($rows as $row) {
            if ($listed !== null) {
                $isListed = array_key_exists($row->model, $listed) || isset($listedBase[$row->model]);

                if ($isListed && $row->unlisted_at !== null) {
                    $relisted[] = $row->id;
                }

                if (! $isListed && $row->active) {
                    $this->counters['unlisted_count']++;
                    $this->detail('unlisted', ['provider' => $provider->value, 'model' => $row->model, 'in_use' => $row->model === $inUse]);

                    if ($row->unlisted_at === null) {
                        $unlisted[] = $row->id;
                    }
                }
            }

            // Preço informado pela API do provedor (quando houver) vale mais que o catálogo.
            $price = ($listed[$row->model] ?? null) ?? $prices->find($provider, $row->model);

            if ($price === null) {
                continue;
            }

            if (! $this->differs($row, $price)) {
                $this->counters['unchanged_count']++;
                $confirmed[] = $row->id;

                continue;
            }

            if ($row->price_locked) {
                $this->counters['locked_count']++;
                $this->detail('locked', $this->change($provider, $row, $price));

                continue;
            }

            if ($this->suspicious($row, $price)) {
                $this->counters['suspicious_count']++;
                $this->detail('suspicious', $this->change($provider, $row, $price));

                continue;
            }

            $this->detail('updated', $this->change($provider, $row, $price));

            // Eloquent (e não update em massa): mudança de preço fica na auditoria.
            $row->update([
                'input_usd_per_million'     => $price->input,
                'output_usd_per_million'    => $price->output,
                'reasoning_usd_per_million' => $price->reasoning,
                'synced_at'                 => $now,
            ]);

            $this->counters['updated_count']++;
        }

        // Só carimbos (sem mudança de preço): em massa, fora da auditoria.
        $this->stamp($confirmed, ['synced_at' => $now]);
        $this->stamp($relisted, ['unlisted_at' => null]);
        $this->stamp($unlisted, ['unlisted_at' => $now]);
    }

    /**
     * @param Collection<int, AiModelPrice> $rows
     * @param array<string, ?CatalogPrice>  $listed
     */
    private function discover(AiProvider $provider, Collection $rows, PriceCatalog $prices, array $listed): void
    {
        $known = $rows->pluck('model')->flip()->all();

        // Também linhas encerradas (histórico): não recria um modelo que já existiu.
        $existing = AiModelPrice::query()->where('provider', $provider->value)->pluck('model')->flip()->all();
        $inUse    = $this->settings->model($provider->value);
        $now      = now();
        $new      = [];

        foreach ($listed as $model => $listedPrice) {
            $model = (string) $model;

            if (isset($known[$model]) || isset($existing[$model])) {
                continue;
            }

            // Snapshot datado de um modelo presente (gpt-4o-2024-08-06 com gpt-4o):
            // o modelo-base já cobre (a cobrança cai nele).
            $base = EloquentAiModelPriceRepository::stripSnapshotDate($model);

            if ($base !== $model && (isset($known[$base]) || isset($existing[$base]) || array_key_exists($base, $listed))) {
                continue;
            }

            $price = $listedPrice ?? $prices->find($provider, $model);

            // Embeddings, áudio, imagem...: não é modelo de texto, fica de fora sem alarde.
            if ($price === null && $prices->isNonText($provider, $model)) {
                continue;
            }

            if ($price === null) {
                $this->counters['missing_price_count']++;
                $this->detail('missing_price', ['provider' => $provider->value, 'model' => $model]);

                continue;
            }

            $active = $model === $inUse;

            $new[] = [
                'id'                        => (new AiModelPrice())->newUniqueId(),
                'provider'                  => $provider->value,
                'model'                     => $model,
                'input_usd_per_million'     => $price->input,
                'output_usd_per_million'    => $price->output,
                'reasoning_usd_per_million' => $price->reasoning,
                'tool_call_usd'             => null,
                'effective_from'            => $now,
                'effective_until'           => null,
                // Novo entra inativo: o admin escolhe o que usar. O modelo em
                // uso do provedor entra ativo (some o aviso "sem preço").
                'active'       => $active,
                'source'       => AiModelPrice::SOURCE_SYNC,
                'price_locked' => false,
                'synced_at'    => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];

            $this->detail('created', ['provider' => $provider->value, 'model' => $model, 'active' => $active, ...$price->summary()]);
        }

        // Em massa (centenas de modelos por provedor): a trilha é o histórico da sincronização.
        foreach (array_chunk($new, 200) as $chunk) {
            DB::table('ai_model_prices')->insert($chunk);
        }

        $this->counters['created_count'] += count($new);
    }

    /**
     * Preços oficiais fora do catálogo LiteLLM, em R$ por 1M tokens
     * (config ai.providers.{código}.prices_brl), convertidos para US$ pela
     * cotação do sistema (a da última recarga de provedor).
     */
    private function withStaticPrices(PriceCatalog $catalog): PriceCatalog
    {
        foreach (AiProvider::cases() as $provider) {
            $table = (array) config("ai.providers.{$provider->value}.prices_brl", []);

            if ($table === []) {
                continue;
            }

            $rate                = $this->usdBrl->current();
            $this->estimatedRate = $this->estimatedRate || $rate['is_fallback'];

            $prices = [];

            foreach ($table as $model => [$input, $output]) {
                $prices[(string) $model] = new CatalogPrice(round($input / $rate['rate'], 6), round($output / $rate['rate'], 6));
            }

            $catalog = $catalog->withPrices($provider, $prices);
        }

        return $catalog;
    }

    /** Preço efetivo diferente (raciocínio sem preço próprio = preço da saída). */
    private function differs(AiModelPrice $row, CatalogPrice $price): bool
    {
        $output = (float) $row->output_usd_per_million;

        return abs((float) $row->input_usd_per_million - $price->input) > self::EPSILON
            || abs($output - $price->output) > self::EPSILON
            || abs((float) ($row->reasoning_usd_per_million ?? $output) - $price->effectiveReasoning()) > self::EPSILON;
    }

    /**
     * Variação maior que o fator configurado (ou preço que zerou): provável
     * erro no catálogo — não aplica sozinho, protege a cobrança.
     */
    private function suspicious(AiModelPrice $row, CatalogPrice $price): bool
    {
        $factor = max(1.0, (float) config('ai.catalog_sync.max_price_change_factor', 10));

        foreach ([[(float) $row->input_usd_per_million, $price->input], [(float) $row->output_usd_per_million, $price->output]] as [$old, $new]) {
            if ($old <= 0) {
                continue;
            }

            if ($new <= 0 || max($new / $old, $old / $new) > $factor) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function change(AiProvider $provider, AiModelPrice $row, CatalogPrice $price): array
    {
        return [
            'provider' => $provider->value,
            'model'    => $row->model,
            'old'      => ['input' => (float) $row->input_usd_per_million, 'output' => (float) $row->output_usd_per_million],
            'new'      => $price->summary(),
        ];
    }

    /** @param array<string, mixed> $item */
    private function detail(string $list, array $item): void
    {
        if (count($this->details[$list] ?? []) < self::DETAILS_LIMIT) {
            $this->details[$list][] = $item;
        }
    }

    /**
     * @param list<string>         $ids
     * @param array<string, mixed> $values
     */
    private function stamp(array $ids, array $values): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            AiModelPrice::query()->whereKey($chunk)->toBase()->update([...$values, 'updated_at' => now()]);
        }
    }

    /**
     * @param list<string> $models
     *
     * @return array<string, true>
     */
    private function bases(array $models): array
    {
        $bases = [];

        foreach ($models as $model) {
            $bases[EloquentAiModelPriceRepository::stripSnapshotDate((string) $model)] = true;
        }

        return $bases;
    }

    private function cancelled(AiCatalogSync $sync): bool
    {
        return AiCatalogSync::query()->whereKey($sync->id)->value('status') === ImportStatus::Cancelled->value;
    }

    /**
     * @param array<string, array{status: string, listed: int, message: ?string}> $results
     */
    private function notice(array $results): ?string
    {
        $notices = [];
        $failed  = array_keys(array_filter($results, fn ($r) => $r['status'] === AiCatalogSync::PROVIDER_FAILED));
        $listed  = array_filter($results, fn ($r) => $r['status'] === AiCatalogSync::PROVIDER_OK);

        if ($failed !== []) {
            $notices[] = __('manager_ai.sync_notice_failed', [
                'providers' => implode(', ', array_map(fn ($c) => AiProvider::from($c)->label(), $failed)),
            ]);
        }

        if ($listed === [] && $failed === []) {
            $notices[] = __('manager_ai.sync_notice_no_keys');
        }

        if ($this->counters['suspicious_count'] > 0) {
            $notices[] = __('manager_ai.sync_notice_suspicious', ['count' => $this->counters['suspicious_count']]);
        }

        if ($this->estimatedRate) {
            $notices[] = __('manager_ai.sync_notice_estimated_rate', ['rate' => Number::currency(AiUsdBrlRate::FALLBACK, 'BRL', app()->getLocale())]);
        }

        return $notices === [] ? null : implode(' ', $notices);
    }
}
