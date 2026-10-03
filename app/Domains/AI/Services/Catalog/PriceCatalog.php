<?php

declare(strict_types=1);

namespace App\Domains\AI\Services\Catalog;

use App\Domains\AI\Repositories\EloquentAiModelPriceRepository;
use App\Enums\AI\AiProvider;

/** Catálogo de preços carregado (LiteLLM), indexado por provedor e modelo. */
final readonly class PriceCatalog
{
    /** Ids que não geram texto (embeddings, áudio, imagem, moderação, tempo real...). */
    private const NON_TEXT_ID = '/(?:^|[-_.\/:])(?:embed\w*|tts|whisper|dall-e|image|images|audio|realtime|transcribe|moderation|sora|guard|rerank|vision-preview-image)(?:$|[-_.\/:])|^(?:babbage|davinci|imagen|veo)-/i';

    /**
     * @param array<string, array<string, CatalogPrice>> $prices  provedor => modelo => preço
     * @param array<string, array<string, true>>         $nonText provedor => modelos que o catálogo conhece como não-texto
     */
    public function __construct(
        private array $prices,
        public ?string $version = null,
        private array $nonText = [],
    ) {
    }

    /**
     * Modelo que não gera texto — fora do catálogo de IA do EasyEye (não
     * conta como "sem preço"): conhecido assim pelo catálogo ou pelo nome.
     */
    public function isNonText(AiProvider $provider, string $model): bool
    {
        return isset($this->nonText[$provider->value][$model]) || preg_match(self::NON_TEXT_ID, $model) === 1;
    }

    /** Preço do modelo; snapshot datado sem preço próprio usa o do modelo-base. */
    public function find(AiProvider $provider, string $model): ?CatalogPrice
    {
        $models = $this->prices[$provider->value] ?? [];

        return $models[$model] ?? $models[EloquentAiModelPriceRepository::stripSnapshotDate($model)] ?? null;
    }

    /**
     * Mais preços de um provedor fora do catálogo LiteLLM (ex.: Maritaca, com
     * preço oficial em R$ convertido). Não sobrescreve o que o catálogo já tem.
     *
     * @param array<string, CatalogPrice> $prices
     */
    public function withPrices(AiProvider $provider, array $prices): self
    {
        $all                   = $this->prices;
        $all[$provider->value] = ($all[$provider->value] ?? []) + $prices;

        return new self($all, $this->version, $this->nonText);
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->prices));
    }
}
