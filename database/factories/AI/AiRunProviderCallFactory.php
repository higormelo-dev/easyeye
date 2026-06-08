<?php

namespace Database\Factories\AI;

use App\Domains\AI\Models\{AiRun, AiRunProviderCall};
use App\Enums\AI\{AiProvider, AiProviderCallRole};
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Domains\AI\Models\AiRunProviderCall>
 */
class AiRunProviderCallFactory extends Factory
{
    protected $model = AiRunProviderCall::class;

    public function definition(): array
    {
        return [
            'ai_run_id'          => AiRun::factory(),
            'provider'           => AiProvider::OpenAI->value,
            'model'              => 'gpt-4o-mini',
            'role'               => AiProviderCallRole::Generator->value,
            'status'             => 'completed',
            'input_tokens'       => $this->faker->numberBetween(200, 5000),
            'output_tokens'      => $this->faker->numberBetween(100, 2000),
            'reasoning_tokens'   => null,
            'tool_calls_count'   => 0,
            'raw_cost_usd'       => $this->faker->randomFloat(8, 0.0001, 0.05),
            'normalized_credits' => $this->faker->numberBetween(1, 10),
            'latency_ms'         => $this->faker->numberBetween(200, 4000),
            'request_hash'       => hash('sha256', (string) $this->faker->uuid()),
            'response_hash'      => hash('sha256', (string) $this->faker->uuid()),
            'metadata'           => null,
            'error_message'      => null,
        ];
    }

    public function reviewer(): static
    {
        return $this->state(['role' => AiProviderCallRole::Reviewer->value]);
    }

    public function adjudicator(): static
    {
        return $this->state(['role' => AiProviderCallRole::Adjudicator->value]);
    }

    public function failed(string $reason = 'Provider error'): static
    {
        return $this->state([
            'status'             => 'failed',
            'error_message'      => $reason,
            'normalized_credits' => 0,
        ]);
    }
}
