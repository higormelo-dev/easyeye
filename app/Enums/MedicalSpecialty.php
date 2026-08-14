<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Especialidade/área oftalmológica do agendamento.
 *
 * Classificação da CONSULTA (o que está sendo tratado nesta visita), não um
 * traço permanente do paciente — o mesmo paciente pode ter uma consulta de
 * Catarata este mês e de Retina e vítreo no próximo. Por isso vive em
 * `schedules`, não em `patients`.
 *
 * Lista fixa (não é catálogo configurável por entity) — conjunto clínico
 * padrão de sub-especialidades oftalmológicas.
 */
enum MedicalSpecialty: int
{
    case GeneralOphthalmology = 1;  // Oftalmologia geral
    case Cataract             = 2;  // Catarata
    case Glaucoma             = 3;  // Glaucoma
    case RetinaVitreous       = 4;  // Retina e vítreo
    case Cornea               = 5;  // Córnea
    case Pediatric            = 6;  // Oftalmopediatria
    case Strabismus           = 7;  // Estrabismo
    case NeuroOphthalmology   = 8;  // Neuro-oftalmologia
    case Uveitis              = 9;  // Uveíte
    case OculoplasticSurgery  = 10; // Plástica ocular
    case Refractive           = 11; // Refrativa
    case ContactLenses        = 12; // Lentes de contato
    case LowVision            = 13; // Baixa visão

    public function label(): string
    {
        return match ($this) {
            self::GeneralOphthalmology => __('actions.specialty_general'),
            self::Cataract             => __('actions.specialty_cataract'),
            self::Glaucoma             => __('actions.specialty_glaucoma'),
            self::RetinaVitreous       => __('actions.specialty_retina_vitreous'),
            self::Cornea               => __('actions.specialty_cornea'),
            self::Pediatric            => __('actions.specialty_pediatric'),
            self::Strabismus           => __('actions.specialty_strabismus'),
            self::NeuroOphthalmology   => __('actions.specialty_neuro'),
            self::Uveitis              => __('actions.specialty_uveitis'),
            self::OculoplasticSurgery  => __('actions.specialty_oculoplastic'),
            self::Refractive           => __('actions.specialty_refractive'),
            self::ContactLenses        => __('actions.specialty_contact_lenses'),
            self::LowVision            => __('actions.specialty_low_vision'),
        };
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['id' => $case->value, 'name' => $case->label()],
            self::cases(),
        );
    }
}
