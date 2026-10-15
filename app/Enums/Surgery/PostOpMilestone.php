<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Marco do pós-operatório. D1/D7/D30 são os retornos planejados (dias a partir da
 * cirurgia); `urgent` é o retorno em 24 h pedido por complicação grave; `other` é
 * avaliação fora dos marcos (só existe como avaliação).
 */
enum PostOpMilestone: string
{
    case D1     = 'd1';
    case D7     = 'd7';
    case D30    = 'd30';
    case Urgent = 'urgent';
    case Other  = 'other';

    public function label(): string
    {
        return __('surgery_postop.enums.post_op_milestone.' . $this->value);
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

    /** Dias a partir da cirurgia (null = não é marco fixo). */
    public function offsetDays(): ?int
    {
        return match ($this) {
            self::D1  => 1,
            self::D7  => 7,
            self::D30 => 30,
            default   => null,
        };
    }

    /** @return list<self> marcos do plano padrão de retornos */
    public static function planned(): array
    {
        return [self::D1, self::D7, self::D30];
    }

    /** @return list<self> marcos que aceitam avaliação estruturada */
    public static function evaluable(): array
    {
        return [self::D1, self::D7, self::D30, self::Other];
    }

    /** @return list<string> */
    public static function evaluableValues(): array
    {
        return array_map(fn (self $m) => $m->value, self::evaluable());
    }

    /** Marco como a recepção vê no retorno ("D7"). */
    public function short(): string
    {
        return match ($this) {
            self::D1, self::D7, self::D30 => strtoupper($this->value),
            default => $this->label(),
        };
    }
}
