<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services;

use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\PreValidation\Contracts\TissGuideValidationRule;
use App\Domains\Tiss\PreValidation\Rules\{
    AuthorizationNumberRequired,
    BeneficiaryCardNumberRequired,
    BeneficiaryNameRequired,
    CidIndicationRequired,
    DoctorRequired,
    GuideItemsRequired,
    ItemAmountsValid,
    SadtExecutionDateRequired,
    TussCodeActive,
};
use App\Domains\Tiss\PreValidation\TissGuideValidationResult;

final class PreValidateTissGuideService
{
    /**
     * Executa todas as regras de pré-validação e retorna o resultado agregado.
     * Carrega relacionamentos necessários para evitar N+1.
     */
    public function validate(TissGuide $guide): TissGuideValidationResult
    {
        $guide->loadMissing(['operator', 'contract', 'items']);

        $result = new TissGuideValidationResult();

        foreach ($this->rules() as $rule) {
            $result->merge($rule->validate($guide));
        }

        return $result;
    }

    /**
     * Valida todas as guias de um lote e retorna um mapa guide_id => resultado.
     *
     * @param iterable<TissGuide> $guides
     *
     * @return array<string, TissGuideValidationResult>
     */
    public function validateMany(iterable $guides): array
    {
        $results = [];

        foreach ($guides as $guide) {
            $results[(string) $guide->id] = $this->validate($guide);
        }

        return $results;
    }

    /** @return list<TissGuideValidationRule> */
    private function rules(): array
    {
        return [
            new BeneficiaryCardNumberRequired(),
            new BeneficiaryNameRequired(),
            new CidIndicationRequired(),
            new DoctorRequired(),
            new GuideItemsRequired(),
            new ItemAmountsValid(),
            new TussCodeActive(),
            new AuthorizationNumberRequired(),
            new SadtExecutionDateRequired(),
        ];
    }
}
