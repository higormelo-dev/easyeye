<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Motivo da procura no pronto atendimento — lista rápida oftalmológica.
 * "Outro" exige a descrição. Queimadura química, trauma ocular e perda súbita
 * de visão sugerem prioridade imediata (a recepção pode mudar).
 */
enum UrgentCareReason: int
{
    case OcularTrauma     = 1;
    case ForeignBody      = 2;
    case ChemicalBurn     = 3;
    case RedEye           = 4;
    case EyePain          = 5;
    case SuddenVisionLoss = 6;
    case PostOpComplaint  = 7;
    case Other            = 99;

    public function key(): string
    {
        return match ($this) {
            self::OcularTrauma     => 'ocular_trauma',
            self::ForeignBody      => 'foreign_body',
            self::ChemicalBurn     => 'chemical_burn',
            self::RedEye           => 'red_eye',
            self::EyePain          => 'eye_pain',
            self::SuddenVisionLoss => 'sudden_vision_loss',
            self::PostOpComplaint  => 'post_op_complaint',
            self::Other            => 'other',
        };
    }

    public function label(): string
    {
        return __('urgent_care.reason_' . $this->key());
    }

    /** Prioridade que a tela pré-seleciona ao escolher o motivo (null = não sugere). */
    public function suggestedPriority(): ?UrgentCarePriority
    {
        return match ($this) {
            self::ChemicalBurn, self::OcularTrauma, self::SuddenVisionLoss => UrgentCarePriority::Immediate,
            default => null,
        };
    }

    /** @return list<array{value: int, key: string, label: string, suggested_priority: ?int}> */
    public static function options(): array
    {
        return array_map(fn (self $r) => [
            'value'              => $r->value,
            'key'                => $r->key(),
            'label'              => $r->label(),
            'suggested_priority' => $r->suggestedPriority()?->value,
        ], self::cases());
    }
}
