<?php

use App\Models\{Entity, Plan, Subscription};
use Illuminate\Support\Facades\DB;

/**
 * "Pago por fora" e "vitalícia" saíram do modelo: a migração converte o que a
 * primeira versão gravou como manual em cortesia, sem ciclo nem valor, sem
 * tocar na cobrança automática. Sem término continua sem término (o manager
 * define a data em "Alterar assinatura").
 */
function convertManualClinic(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    return $entity;
}

it('converte manual em cortesia sem ciclo nem valor; sem término não vira vitalícia; gateway não muda', function () {
    $plan = Plan::factory()->create(['price' => 300]);

    $dated   = Subscription::factory()->for(convertManualClinic())->for($plan)->create(['ends_at' => now()->addMonth()]);
    $forever = Subscription::factory()->for(convertManualClinic())->for($plan)->create(['ends_at' => null]);
    $paying  = Subscription::factory()->gateway()->for(convertManualClinic())->for($plan)->create([
        'billing_cycle' => 'yearly',
        'amount'        => 3000,
    ]);

    // Estado deixado pela primeira versão da migração de condições.
    DB::table('subscriptions')->whereIn('id', [$dated->id, $forever->id])->update([
        'billing_mode'  => 'manual',
        'billing_cycle' => 'yearly',
        'amount'        => 2400,
    ]);

    (require database_path('migrations/2026_10_03_230200_convert_manual_billing_mode_to_complimentary.php'))->up();

    $row = fn (Subscription $s) => DB::table('subscriptions')->where('id', $s->id)->first();

    expect($row($dated))
        ->billing_mode->toBe('complimentary')
        ->billing_cycle->toBeNull()
        ->amount->toBeNull()
        ->and($row($forever))
        ->billing_mode->toBe('complimentary')
        ->billing_cycle->toBeNull()
        ->amount->toBeNull()
        ->ends_at->toBeNull()
        ->and($row($paying))
        ->billing_mode->toBe('gateway')
        ->billing_cycle->toBe('yearly')
        ->and((float) $row($paying)->amount)->toBe(3000.0)
        ->and(DB::table('subscriptions')->where('billing_mode', 'manual')->exists())->toBeFalse();
});
