<?php

declare(strict_types=1);

namespace App\Domains\AI\Services\Catalog;

use App\Enums\AI\AiProvider;
use Illuminate\Support\Facades\Http;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Catálogo público de preços do LiteLLM (model_prices_and_context_window.json,
 * mantido pela comunidade e atualizado a cada lançamento): preço por token de
 * milhares de modelos, de todos os provedores da lista pronta.
 *
 * Só os modelos que geram texto (mode chat; "responses" só na OpenAI, que usa
 * essa API) dos provedores conhecidos entram no índice. Chaves com prefixo
 * ("gemini/gemini-2.5-flash", "groq/openai/gpt-oss-120b") viram o id que a
 * API do provedor usa ("gemini-2.5-flash", "openai/gpt-oss-120b").
 *
 * Segurança: só HTTPS (inclusive em redirecionamento), teto de tamanho antes
 * e depois de baixar, JSON validado. Nenhuma chave de API vai nesta chamada.
 */
class LiteLlmPriceCatalog
{
    /**
     * @throws AiCatalogSyncException
     */
    public function load(): PriceCatalog
    {
        $url = (string) config('ai.catalog_sync.prices_url');
        $max = (int) config('ai.catalog_sync.prices_max_bytes', 30 * 1024 * 1024);

        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new AiCatalogSyncException(__('manager_ai.sync_prices_unavailable'));
        }

        try {
            $response = Http::withUserAgent((string) config('ai.user_agent', 'EasyEye/1.0'))
                ->withOptions([
                    ...(config('ai.http_force_ipv4') ? ['force_ip_resolve' => 'v4'] : []),
                    'allow_redirects' => ['max' => 3, 'protocols' => ['https']],
                    // Corta antes de baixar um arquivo maior que o teto.
                    'on_headers' => function (ResponseInterface $response) use ($max) {
                        if ((int) $response->getHeaderLine('Content-Length') > $max) {
                            throw new RuntimeException('catalog too large');
                        }
                    },
                ])
                ->timeout(max(5, (int) config('ai.catalog_sync.timeout_seconds', 60)))
                ->connectTimeout(15)
                ->retry(2, 2000, throw: false)
                ->get($url);
        } catch (Throwable) {
            throw new AiCatalogSyncException(__('manager_ai.sync_prices_unavailable'));
        }

        $body = $response->successful() ? $response->body() : '';

        if ($body === '' || strlen($body) > $max) {
            throw new AiCatalogSyncException(__('manager_ai.sync_prices_unavailable'));
        }

        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiCatalogSyncException(__('manager_ai.sync_prices_invalid'));
        }

        unset($body);

        [$index, $nonText] = is_array($data) ? $this->index($data) : [[], []];

        // Formato mudou (nenhum preço reconhecido): melhor não mexer em nada.
        if ($index === []) {
            throw new AiCatalogSyncException(__('manager_ai.sync_prices_invalid'));
        }

        $etag = trim((string) $response->header('ETag'), '" ');

        return new PriceCatalog($index, $etag !== '' ? mb_substr($etag, 0, 120) : null, $nonText);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array{0: array<string, array<string, CatalogPrice>>, 1: array<string, array<string, true>>}
     */
    private function index(array $data): array
    {
        $wanted = [];

        foreach (AiProvider::cases() as $provider) {
            $wanted[$provider->litellmProvider()] = $provider;
        }

        $index   = [];
        $nonText = [];

        foreach ($data as $key => $entry) {
            if (! is_string($key) || ! is_array($entry)) {
                continue;
            }

            $provider = $wanted[(string) ($entry['litellm_provider'] ?? '')] ?? null;

            if ($provider === null) {
                continue;
            }

            $prefix   = $provider->litellmProvider() . '/';
            $prefixed = str_starts_with($key, $prefix);
            $model    = $prefixed ? substr($key, strlen($prefix)) : $key;
            $mode     = $entry['mode'] ?? null;

            if ($model === '') {
                continue;
            }

            if (! ($mode === 'chat' || ($mode === 'responses' && $provider === AiProvider::OpenAI))) {
                $nonText[$provider->value][$model] = true;

                continue;
            }

            $price = CatalogPrice::fromPerToken(
                $entry['input_cost_per_token'] ?? null,
                $entry['output_cost_per_token'] ?? null,
                $entry['output_cost_per_reasoning_token'] ?? null,
            );

            // Há duplicatas com e sem prefixo (modelo / provedor/modelo):
            // a com prefixo do provedor é a canônica.
            if ($price === null || (isset($index[$provider->value][$model]) && ! $prefixed)) {
                continue;
            }

            $index[$provider->value][$model] = $price;
        }

        return [$index, $nonText];
    }
}
