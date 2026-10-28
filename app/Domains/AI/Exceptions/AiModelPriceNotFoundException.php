<?php

declare(strict_types=1);

namespace App\Domains\AI\Exceptions;

use App\Enums\AI\AiProvider;
use RuntimeException;

class AiModelPriceNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly AiProvider $provider,
        public readonly string $model,
    ) {
        parent::__construct("Preço não encontrado para provider [{$provider->value}] e model [{$model}].");
    }
}
