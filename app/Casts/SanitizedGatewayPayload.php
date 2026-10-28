<?php

namespace App\Casts;

use App\Support\Billing\PayloadSanitizer;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * JSON da resposta do gateway gravado já sem dados do portador do cartão e
 * segredos (PayloadSanitizer) — vale para qualquer caminho que grave a
 * coluna (checkout, ativação, renovação, webhook), não só os que lembram de
 * limpar antes.
 *
 * @implements CastsAttributes<array<array-key, mixed>|null, mixed>
 */
class SanitizedGatewayPayload implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? json_encode(PayloadSanitizer::clean($value), JSON_UNESCAPED_UNICODE) : null;
    }
}
