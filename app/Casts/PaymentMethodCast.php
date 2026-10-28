<?php

declare(strict_types=1);

namespace App\Casts;

use App\Enums\PaymentMethod;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast tolerante para payment_method.
 *
 * Diferente do cast de enum nativo, NÃO lança ValueError quando a coluna
 * contém um valor legado/desconhecido (ex.: 'transferencia' gravado por
 * outros fluxos): nesse caso a leitura devolve null em vez de quebrar.
 * Na escrita aceita tanto o enum quanto a string.
 *
 * @implements CastsAttributes<PaymentMethod|null, PaymentMethod|string|null>
 */
class PaymentMethodCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?PaymentMethod
    {
        return $value === null || $value === '' ? null : PaymentMethod::tryFrom((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof PaymentMethod ? $value->value : (string) $value;
    }
}
