<?php

namespace App\Services;

use App\Models\Entity;

/**
 * Formata a localização (cidade/estado/país) para exibição em documentos PDF
 * de forma sensível ao locale do destino.
 *
 * Hierarquia de fallback:
 *   1. Dados da entidade (clínica) — cenário normal de produção
 *   2. Configuração do proprietário do SaaS (config/saas.php) — fallback de
 *      segurança usado apenas durante onboarding ou migração
 *
 * Convenção por locale:
 *   - pt_BR: "Cidade/UF" (ex: "São Paulo/SP")
 *   - demais: "City, State, Country" (ex: "Lisbon, Portugal")
 */
class LocationFormatter
{
    /**
     * Resolve cidade/estado/país com fallback para o owner do SaaS.
     *
     * @return array{city: string, state: string, country: string}
     */
    public function resolve(?Entity $entity): array
    {
        $owner = config('saas.owner', []);

        return [
            'city'    => (string) ($entity?->city ?: ($owner['city'] ?? '')),
            'state'   => (string) ($entity?->state ?: ($owner['state'] ?? '')),
            'country' => (string) ($entity?->country ?: ($owner['country'] ?? '')),
        ];
    }

    /**
     * Formata a localização como string única, usando convenção do locale informado.
     *
     * @param string|null $locale IETF BCP 47 (ex: pt_BR, en_US). Default = locale ativo.
     */
    public function format(?Entity $entity, ?string $locale = null): string
    {
        $loc    = $this->resolve($entity);
        $locale = $locale ?? app()->getLocale();

        // Formato brasileiro: "Cidade/UF" — país omitido por redundância.
        if ($this->isBrazilian($locale, $loc['country'])) {
            return collect([$loc['city'], $loc['state']])
                ->filter(fn ($v) => $v !== '')
                ->implode('/');
        }

        // Formato internacional: "City, State, Country" — vírgulas, country incluído.
        return collect([$loc['city'], $loc['state'], $loc['country']])
            ->filter(fn ($v) => $v !== '')
            ->implode(', ');
    }

    private function isBrazilian(string $locale, string $country): bool
    {
        return str_starts_with(strtolower($locale), 'pt_br')
            || strtoupper($country) === 'BR';
    }
}
