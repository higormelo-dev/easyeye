<?php

namespace Database\Factories\AI;

use App\Domains\AI\Models\AiModelPrice;
use App\Enums\AI\AiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Domains\AI\Models\AiModelPrice>
 */
class AiModelPriceFactory extends Factory
{
    protected $model = AiModelPrice::class;

    public function definition(): array
    {
        return [
            'provider'                  => AiProvider::OpenAI->value,
            'model'                     => 'gpt-4o-mini',
            'input_usd_per_million'     => 0.15,
            'output_usd_per_million'    => 0.60,
            'reasoning_usd_per_million' => null,
            'tool_call_usd'             => null,
            'effective_from'            => now()->subYear(),
            'effective_until'           => null,
            'active'                    => true,
        ];
    }

    public function forModel(AiProvider $provider, string $model, float $inputPerMillion, float $outputPerMillion): static
    {
        return $this->state([
            'provider'               => $provider->value,
            'model'                  => $model,
            'input_usd_per_million'  => $inputPerMillion,
            'output_usd_per_million' => $outputPerMillion,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false, 'effective_until' => now()]);
    }
}
