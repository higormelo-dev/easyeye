<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Rascunho (editável) ou finalizada (imutável, com hash).
 */
enum PostOpEvaluationStatus: string
{
    case Draft     = 'draft';
    case Finalized = 'finalized';

    public function label(): string
    {
        return __('surgery_postop.enums.post_op_evaluation_status.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
