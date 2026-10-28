<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Unidade de medida do item de estoque (App\Models\EntityProduct).
 * Puramente de exibição/arredondamento — não converte entre unidades.
 */
enum StockUnit: string
{
    case Unit    = 'un';
    case Box     = 'cx';
    case Bottle  = 'fr';
    case Pair    = 'par';
    case Ampoule = 'amp';
    case Ml      = 'ml';
    case Mg      = 'mg';
    case Gram    = 'g';
    case Liter   = 'l';

    public function label(): string
    {
        // Texto em lang/{locale}/stock_enums.php (pt_BR mantém os rótulos de sempre).
        return __("stock_enums.units.{$this->value}");
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
