<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * De onde veio a unidade: estoque da clínica (baixa o saldo) ou de fora (consignado do hospital/fornecedor).
 */
enum ImplantStockSource: string
{
    case ClinicStock = 'clinic_stock';
    case External    = 'external';

    public function label(): string
    {
        return __('surgery_operation.enums.implant_stock_source.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
