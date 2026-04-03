<?php

namespace App\Enums;

/**
 * Tipo de parceiro/revendedor.
 * Cada tipo tem expectativas diferentes de volume, suporte e comissão.
 */
enum PartnerType: string
{
    case Distributor = 'distributor';  // Distribuidor de equipamentos oftalmológicos
    case Association = 'association';  // Associação/conselho médico (CBO, CREMESP, etc.)
    case Consultant  = 'consultant';   // Consultor independente
    case Agency      = 'agency';       // Agência de marketing/tecnologia

    public function label(): string
    {
        return match ($this) {
            self::Distributor => 'Distribuidor de Equipamentos',
            self::Association => 'Associação Médica',
            self::Consultant  => 'Consultor',
            self::Agency      => 'Agência',
        };
    }

    /** Taxa de comissão padrão por tipo (%) */
    public function defaultCommissionRate(): float
    {
        return match ($this) {
            self::Distributor => 15.0,
            self::Association => 10.0,
            self::Consultant  => 20.0,
            self::Agency      => 12.0,
        };
    }
}
