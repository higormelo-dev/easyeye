<?php

namespace Database\Factories\AI;

use App\Domains\AI\Models\AiCreditWallet;
use App\Models\Entity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Domains\AI\Models\AiCreditWallet>
 */
class AiCreditWalletFactory extends Factory
{
    protected $model = AiCreditWallet::class;

    public function definition(): array
    {
        return [
            'entity_id'          => Entity::factory(),
            'balance'            => 0,
            'reserved_balance'   => 0,
            'lifetime_purchased' => 0,
            'lifetime_consumed'  => 0,
        ];
    }

    public function withBalance(int $balance): static
    {
        return $this->state(['balance' => $balance]);
    }

    public function withReserved(int $reserved): static
    {
        return $this->state(['reserved_balance' => $reserved]);
    }
}
