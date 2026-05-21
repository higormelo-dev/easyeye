<?php

namespace Database\Factories\AI;

use App\Domains\AI\Models\AiCreditLedgerEntry;
use App\Domains\AI\Models\AiCreditWallet;
use App\Enums\AI\AiLedgerEntryType;
use App\Models\Entity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Domains\AI\Models\AiCreditLedgerEntry>
 */
class AiCreditLedgerEntryFactory extends Factory
{
    protected $model = AiCreditLedgerEntry::class;

    public function definition(): array
    {
        $entity = Entity::factory()->create();
        $wallet = AiCreditWallet::factory()->create(['entity_id' => $entity->id]);

        $amount = $this->faker->numberBetween(10, 200);

        return [
            'entity_id'       => $entity->id,
            'wallet_id'       => $wallet->id,
            'subscription_id' => null,
            'ai_run_id'       => null,
            'type'            => AiLedgerEntryType::Grant->value,
            'amount'          => $amount,
            'balance_after'   => $amount,
            'description'     => 'Entrada de ledger de teste.',
            'metadata'        => null,
            'idempotency_key' => null,
            'created_by'      => null,
        ];
    }

    public function ofType(AiLedgerEntryType $type): static
    {
        return $this->state(['type' => $type->value]);
    }

    public function reserve(int $amount): static
    {
        return $this->state([
            'type'   => AiLedgerEntryType::Reserve->value,
            'amount' => -$amount,
        ]);
    }
}
