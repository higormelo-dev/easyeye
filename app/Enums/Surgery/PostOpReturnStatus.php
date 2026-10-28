<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Andamento de um retorno planejado do pós-operatório.
 */
enum PostOpReturnStatus: string
{
    case Pending    = 'pending';
    case Scheduling = 'scheduling';
    case Scheduled  = 'scheduled';
    case Failed     = 'failed';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return __('surgery_postop.enums.post_op_return_status.' . $this->value);
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

    /** Pode ser (re)tentado agora. */
    public function isRetryable(): bool
    {
        return in_array($this, [self::Pending, self::Failed], true);
    }
}
