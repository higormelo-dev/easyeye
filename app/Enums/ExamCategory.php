<?php

namespace App\Enums;

use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Options;
use ArchTech\Enums\Values;

enum ExamCategory: int
{
    use InvokableCases;
    use Options;
    use Values;

    case OTHER = 0;
    case BASIC_EVALUATION = 1;
    case REFRACTION = 2;
    case RETINA_AND_VITREOUS = 3;
    case GLAUCOMA = 4;
    case CORNEA_AND_OCULAR_SURFACE = 5;
    case CATARACT_AND_LENS = 6;
    case STRABISMUS_AND_BINOCULAR_VISION = 7;
    case PEDIATRIC_OPHTHALMOLOGY = 8;
    case NEURO_OPHTHALMOLOGY = 9;
    case FUNCTIONAL_TESTS = 10;
    case PRE_AND_POST_OPERATIVE = 11;

    public function label(): string
    {
        return match ($this) {
            self::OTHER => 'Outros',
            self::BASIC_EVALUATION => 'Avaliação Básica',
            self::REFRACTION => 'Refração',
            self::RETINA_AND_VITREOUS => 'Retina e Vítreo',
            self::GLAUCOMA => 'Glaucoma',
            self::CORNEA_AND_OCULAR_SURFACE => 'Córnea e Superfície Ocular',
            self::CATARACT_AND_LENS => 'Catarata e Cristalino',
            self::STRABISMUS_AND_BINOCULAR_VISION => 'Estrabismo e Visão Binocular',
            self::PEDIATRIC_OPHTHALMOLOGY => 'Oftalmopediatria',
            self::NEURO_OPHTHALMOLOGY => 'Neuro-oftalmologia',
            self::FUNCTIONAL_TESTS => 'Testes Funcionais',
            self::PRE_AND_POST_OPERATIVE => 'Pré e Pós-operatório',
        };
    }
}
