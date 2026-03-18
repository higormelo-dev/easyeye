<?php

namespace Database\Factories;

use App\Models\{Entity, TvDisplay};
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TvDisplay>
 */
class TvDisplayFactory extends Factory
{
    protected $model = TvDisplay::class;

    public function definition(): array
    {
        return [
            'entity_id'    => Entity::factory(),
            'name'         => fake()->words(2, true) . ' TV',
            'status'       => TvDisplay::STATUS_ACTIVE,
            'token'        => Str::random(48),
            'pin'          => null,
            'last_seen_at' => null,
            'active'       => true,
        ];
    }

    /** TV pendente aguardando aprovação (sem token, com PIN). */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => TvDisplay::STATUS_PENDING,
            'token'  => null,
            'pin'    => TvDisplay::generatePin(),
            'active' => false,
        ]);
    }

    /** TV ativa com token gerado. */
    public function active(): static
    {
        return $this->state(fn () => [
            'status' => TvDisplay::STATUS_ACTIVE,
            'token'  => TvDisplay::generateToken(),
            'pin'    => null,
            'active' => true,
        ]);
    }

    /** TV revogada (desativada). */
    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => TvDisplay::STATUS_REVOKED,
            'active' => false,
        ]);
    }
}
