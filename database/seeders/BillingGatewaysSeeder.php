<?php

namespace Database\Seeders;

use App\Models\Billing\Gateway;
use Illuminate\Database\Seeder;

class BillingGatewaysSeeder extends Seeder
{
    public function run(): void
    {
        $gateways = [
            ['code' => 'infinitepay', 'name' => 'InfinitePay', 'priority' => 10],
            ['code' => 'asaas', 'name' => 'Asaas', 'priority' => 20],
            ['code' => 'mercadopago', 'name' => 'Mercado Pago', 'priority' => 30],
            ['code' => 'pagarme', 'name' => 'Pagar.me', 'priority' => 40],
            ['code' => 'stripe_br', 'name' => 'Stripe Brasil', 'priority' => 50],
            ['code' => 'pagbank', 'name' => 'PagBank', 'priority' => 60],
        ];

        foreach ($gateways as $gateway) {
            Gateway::query()->updateOrCreate(
                ['code' => $gateway['code']],
                [
                    'name'                      => $gateway['name'],
                    'active'                    => true,
                    'supports_subscriptions'    => true,
                    'supports_one_time_charges' => true,
                    'supports_refunds'          => true,
                    'supports_webhooks'         => true,
                    'priority'                  => $gateway['priority'],
                ],
            );
        }
    }
}
