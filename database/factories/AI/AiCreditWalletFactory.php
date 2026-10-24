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
            'entity_id'                => Entity::factory(),
            'balance_cents'            => 0,
            'reserved_balance_cents'   => 0,
            'lifetime_purchased_cents' => 0,
            'lifetime_consumed_cents'  => 0,
        ];
    }

    /** Saldo comprado em CENTAVOS de BRL. */
    public function withBalance(int $balanceCents): static
    {
        return $this->state(['balance_cents' => $balanceCents]);
    }

    public function withReserved(int $reservedCents): static
    {
        return $this->state(['reserved_balance_cents' => $reservedCents]);
    }
}
