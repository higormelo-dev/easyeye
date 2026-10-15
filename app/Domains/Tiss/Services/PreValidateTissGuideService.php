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
    EyeSideRecommended,
    GuideItemsRequired,
    ItemAmountsValid,
    SadtExecutionDateRequired,
    SurgicalGuideRules,
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

        foreach ($this->rules($guide) as $rule) {
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
    private function rules(TissGuide $guide): array
    {
        // Mensagens avulsas do módulo cirúrgico têm o próprio conjunto (campos de outro
        // formato: o CID é uma lista, o "item" é procedimento solicitado ou OPME).
        if ($guide->guide_type->isSurgicalRequest()) {
            return [new SurgicalGuideRules(), new TussCodeActive()];
        }

        return [
            new BeneficiaryCardNumberRequired(),
            new BeneficiaryNameRequired(),
            new CidIndicationRequired(),
            new DoctorRequired(),
            new EyeSideRecommended(),
            new GuideItemsRequired(),
            new ItemAmountsValid(),
            new TussCodeActive(),
            new AuthorizationNumberRequired(),
            new SadtExecutionDateRequired(),
        ];
    }
}
