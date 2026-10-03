<?php

declare(strict_types=1);

namespace App\Domains\AI\Services\Catalog;

use App\Enums\AI\AiProvider;
use Illuminate\Http\Client\{PendingRequest, Response};
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Modelos de texto que a chave configurada (.env) dá acesso, pela API
 * oficial de listagem de cada provedor — só leitura, não gasta tokens:
 *
 * - OpenAI e compatíveis: GET {base}/models
 * - Anthropic: GET /v1/models (paginado)
 * - Gemini: GET /v1beta/models (paginado; só quem faz generateContent)
 *
 * Erros viram mensagem traduzida com o status HTTP — nunca o corpo da
 * resposta nem a chave.
 */
class AiProviderModelLister
{
    /** Teto de páginas por provedor (proteção contra paginação sem fim). */
    private const MAX_PAGES = 10;

    /** Id de modelo aceito (vai para o banco e para a tela). */
    private const MODEL_ID = '#^[A-Za-z0-9][A-Za-z0-9._:/@+-]{0,119}$#';

    /**
     * @return array<string, ?CatalogPrice> id do modelo => preço informado pela própria API (quando houver)
     *
     * @throws AiCatalogSyncException
     */
    public function list(AiProvider $provider): array
    {
        $key = trim((string) config("services.{$provider->value}.api_key", ''));

        if ($key === '') {
            throw new AiCatalogSyncException(__('manager_ai.sync_provider_no_key'));
        }

        return match ($provider) {
            AiProvider::Anthropic => $this->anthropic($key),
            AiProvider::Gemini    => $this->gemini($key),
            default               => $this->openAiStyle($provider, $key),
        };
    }

    /** @return array<string, ?CatalogPrice> */
    private function openAiStyle(AiProvider $provider, string $key): array
    {
        $client = match (config("ai.providers.{$provider->value}.list_auth") ?? config("ai.providers.{$provider->value}.auth")) {
            'api-key' => $this->client($provider)->withHeaders(['api-key' => $key]),
            // Maritaca: GET /models com "Authorization: Key ..." (docs.maritaca.ai).
            'key'   => $this->client($provider)->withHeaders(['Authorization' => 'Key ' . $key]),
            default => $this->client($provider)->withToken($key),
        };

        $json   = $this->get($client, '/models');
        $models = [];

        foreach ((array) data_get($json, 'data', []) as $item) {
            // Mistral informa as capacidades: fora quem não faz chat.
            if (data_get($item, 'capabilities.completion_chat') === false) {
                continue;
            }

            $this->add($models, data_get($item, 'id'));
        }

        return $models;
    }

    /** @return array<string, ?CatalogPrice> */
    private function anthropic(string $key): array
    {
        $client = $this->client(AiProvider::Anthropic)->withHeaders([
            'x-api-key'         => $key,
            'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
        ]);

        $models = [];
        $after  = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $json = $this->get($client, '/v1/models', array_filter(['limit' => 1000, 'after_id' => $after]));

            foreach ((array) data_get($json, 'data', []) as $item) {
                $this->add($models, data_get($item, 'id'));
            }

            $after = data_get($json, 'last_id');

            if (data_get($json, 'has_more') !== true || ! is_string($after) || $after === '') {
                break;
            }
        }

        return $models;
    }

    /** @return array<string, ?CatalogPrice> */
    private function gemini(string $key): array
    {
        // Chave no header (nunca na URL — vazaria em logs de proxy).
        $client = $this->client(AiProvider::Gemini)->withHeaders(['x-goog-api-key' => $key]);
        $models = [];
        $token  = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $json = $this->get($client, '/v1beta/models', array_filter(['pageSize' => 1000, 'pageToken' => $token]));

            foreach ((array) data_get($json, 'models', []) as $item) {
                if (! in_array('generateContent', (array) data_get($item, 'supportedGenerationMethods', []), true)) {
                    continue;
                }

                $name = data_get($item, 'name');
                $this->add($models, is_string($name) ? preg_replace('#^models/#', '', $name) : null);
            }

            $token = data_get($json, 'nextPageToken');

            if (! is_string($token) || $token === '') {
                break;
            }
        }

        return $models;
    }

    /**
     * @param array<string, ?CatalogPrice> $models
     */
    private function add(array &$models, mixed $id, ?CatalogPrice $price = null): void
    {
        if (is_string($id) && preg_match(self::MODEL_ID, $id) === 1) {
            $models[$id] = $price;
        }
    }

    private function client(AiProvider $provider): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config("ai.providers.{$provider->value}.base_url", ''), '/'))
            ->withOptions(config('ai.http_force_ipv4') ? ['force_ip_resolve' => 'v4'] : [])
            ->acceptJson()
            ->withUserAgent((string) config('ai.user_agent', 'EasyEye/1.0'))
            ->connectTimeout(10)
            ->timeout(max(5, (int) config('ai.catalog_sync.timeout_seconds', 60)))
            ->retry(1, 1000, throw: false);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<mixed>
     *
     * @throws AiCatalogSyncException
     */
    private function get(PendingRequest $client, string $path, array $query = []): array
    {
        try {
            $response = $client->get($path, $query);
        } catch (Throwable) {
            throw new AiCatalogSyncException(__('manager_ai.sync_provider_unreachable'));
        }

        if (! $response->successful()) {
            throw new AiCatalogSyncException($this->httpError($response));
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new AiCatalogSyncException(__('manager_ai.sync_provider_invalid'));
        }

        return $json;
    }

    private function httpError(Response $response): string
    {
        return in_array($response->status(), [401, 403], true)
            ? __('manager_ai.sync_provider_unauthorized', ['status' => $response->status()])
            : __('manager_ai.sync_provider_http_error', ['status' => $response->status()]);
    }
}
