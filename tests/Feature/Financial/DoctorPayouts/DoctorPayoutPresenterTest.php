<?php

/*
 * Demonstrativo (PDF) com a mesma composição da tela: parcela e recebido
 * acumulado, esperado do atendimento, escopo da regra, divisão e deduções por
 * tipo, fixo proporcional, já liberado e alertas — nos dois idiomas.
 */

use App\Services\Financial\DoctorPayouts\DoctorPayoutPresenter;
use Illuminate\Support\Number;

function presenterRow(array $overrides = []): array
{
    return array_merge([
        'key'          => 'schedule:s1', 'date' => '2026-08-10T09:00:00', 'patient_id' => null, 'patient_name' => null, 'patient_code' => null,
        'service_type' => 'consultation', 'description' => 'CONSULTA', 'covenant_name' => null, 'is_particular' => true,
        'charged'      => 150.0, 'base_source' => 'received', 'rule' => ['calculation' => 'percentage', 'percentage' => 60.0, 'fixed' => null],
        'rule_id'      => 'r1', 'rule_scope' => null, 'payout' => 85.5, 'basis' => 'receipt', 'tranche' => 1, 'received' => 150.0,
        'expected'     => 150.0, 'released_before' => 0.0, 'forecast' => null, 'beneficiary_role' => 'executor', 'share_percentage' => 100.0,
        'net'          => null, 'deductions' => 0.0, 'deductions_breakdown' => null, 'status' => 'closed', 'payout_id' => 'p1', 'payout_code' => 'REP-1',
        'warnings'     => [],
    ], $overrides);
}

it('parcela 2 com já liberado, esperado, escopo e deduções por tipo — as contas do PDF fecham', function (string $locale) {
    app()->setLocale($locale);
    $money = fn (float $value) => Number::currency($value, 'BRL', $locale);

    $lines = app(DoctorPayoutPresenter::class)->compositionLines(presenterRow([
        'tranche'    => 2, 'received' => 300.0, 'expected' => 350.0, 'released_before' => 85.5,
        'rule_scope' => 'Dra. Ana · Consulta · Particular',
        'net'        => 285.0, 'deductions' => 15.0, 'deductions_breakdown' => ['card' => 15.0, 'tax' => 0.0, 'admin' => 0.0],
    ]));

    expect($lines['base'])->toContain(__('financial_doctor_payouts.base_sources.received'))
        ->toContain(__('financial_doctor_payouts.tranche_label', ['number' => 2]) . ' · ' . __('financial_doctor_payouts.received_total', ['value' => $money(300)]))
        ->toContain(__('financial_doctor_payouts.expected_line', ['value' => $money(350)]))
        ->and($lines['rule'])->toContain('Dra. Ana · Consulta · Particular')
        ->toContain(__('financial_doctor_payouts.split_net_line', ['net' => $money(285), 'deductions' => $money(15)]))
        ->toContain(__('financial_doctor_payouts.deduction_parts.card') . ' ' . $money(15))
        ->and($lines['payout'])->toContain(__('financial_doctor_payouts.released_before', ['value' => $money(85.5)]));
})->with(['pt_BR', 'en']);

it('estorno de ato removido explica o valor negativo; fixo pago em parte mostra a proporção', function () {
    $presenter = app(DoctorPayoutPresenter::class);
    $money     = fn (float $value) => Number::currency($value, 'BRL', app()->getLocale());

    $reversal = $presenter->compositionLines(presenterRow(['payout' => -90.0, 'charged' => -150.0, 'warnings' => ['act_removed']]));
    expect($reversal['payout'])->toContain(__('financial_doctor_payouts.warnings.act_removed'));

    $fixed = $presenter->compositionLines(presenterRow([
        'rule' => ['calculation' => 'fixed', 'percentage' => null, 'fixed' => 100.0], 'received' => 120.0, 'expected' => 200.0, 'payout' => 60.0,
    ]));
    expect($fixed['rule'])->toContain(__('financial_doctor_payouts.rule_fixed_proportional', ['received' => $money(120), 'expected' => $money(200)]));
});

it('rótulo da regra: % do recebido líquido no regime atual; % do cobrado nos itens do regime anterior', function () {
    $presenter = app(DoctorPayoutPresenter::class);
    $rule      = ['calculation' => 'percentage', 'percentage' => 60.0, 'fixed' => null];

    expect($presenter->ruleLabel($rule, 'receipt'))->toBe(__('financial_doctor_payouts.rule_percentage', ['value' => '60']))
        ->and($presenter->ruleLabel($rule, 'production'))->toBe(__('financial_doctor_payouts.rule_percentage_production', ['value' => '60']))
        ->and($presenter->ruleLabel($rule, 'receipt'))->not->toBe($presenter->ruleLabel($rule, 'production'));
});
