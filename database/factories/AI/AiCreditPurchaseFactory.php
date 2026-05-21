<?php

namespace Database\Factories\AI;

use App\Domains\AI\Models\AiCreditPurchase;
use App\Enums\AI\AiCreditPurchaseStatus;
use App\Models\Entity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Domains\AI\Models\AiCreditPurchase>
 */
class AiCreditPurchaseFactory extends Factory
{
    protected $model = AiCreditPurchase::class;

    public function definition(): array
    {
        return [
            'entity_id'                => Entity::factory(),
            'subscription_id'          => null,
            'requested_by'             => null,
            'credited_ledger_entry_id' => null,
            'package_code'             => 'starter',
            'credits'                  => 25,
            'amount_cents'             => 6990,
            'currency'                 => 'BRL',
            'status'                   => AiCreditPurchaseStatus::PendingPayment->value,
            'description'              => 'Pacote avulso de créditos IA.',
            'metadata'                 => null,
            'idempotency_key'          => null,
        ];
    }

    public function credited(): static
    {
        return $this->state([
            'status'      => AiCreditPurchaseStatus::Credited->value,
            'credited_at' => now(),
        ]);
    }
}
