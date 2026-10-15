<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Surgical;

use App\Domains\Tiss\Models\TissGuide;
use Illuminate\Support\Arr;

/**
 * Leitura tipada do retrato gravado em `tiss_guides.payload.surgical`.
 *
 * O retrato tem duas camadas: `data` (derivada do caso cirúrgico no momento da
 * geração) e `overrides` (o que o usuário completou/corrigiu à mão na guia). O
 * valor efetivo é `overrides` por cima de `data` — a regeração a partir do caso
 * troca a `data` e PRESERVA os `overrides`.
 */
final class SurgicalGuideSnapshot
{
    /** Versão do formato do retrato (muda só com migração de payload). */
    public const VERSION = 1;

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $overrides
     */
    private function __construct(private readonly array $data, private readonly array $overrides)
    {
    }

    public static function of(TissGuide $guide): self
    {
        $surgical = (array) (($guide->payload ?? [])['surgical'] ?? []);

        return new self((array) ($surgical['data'] ?? []), (array) ($surgical['overrides'] ?? []));
    }

    /** Valor efetivo (override > derivado do caso), com notação de ponto. */
    public function get(string $key, mixed $default = null): mixed
    {
        if (Arr::has($this->overrides, $key) && $this->filled(Arr::get($this->overrides, $key))) {
            return Arr::get($this->overrides, $key);
        }

        return Arr::get($this->data, $key, $default);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        return $value === null || $value === '' ? $default : (string) $value;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->get($key);
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed> */
    public function overrides(): array
    {
        return $this->overrides;
    }

    private function filled(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }
}
