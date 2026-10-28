<?php

/*
 * Repasse médico — qual regra vale para cada item (DoctorPayoutRuleResolver)
 * e quanto ela paga: especificidade, pagador, vigência e centavos.
 */

use App\DTOs\DoctorPayout\PayoutItemData;
use App\Enums\DoctorPayout\{DoctorPayoutBaseSource, DoctorPayoutServiceType, DoctorPayoutSourceType, DoctorPayoutWarning};
use App\Models\DoctorPayoutRule;
use App\Services\Financial\DoctorPayouts\DoctorPayoutRuleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function payoutResolverItem(array $overrides = []): PayoutItemData
{
    return new PayoutItemData(...array_merge([
        'sourceType'   => DoctorPayoutSourceType::Schedule,
        'sourceId'     => 'schedule-1',
        'serviceType'  => DoctorPayoutServiceType::Consultation,
        'performedAt'  => CarbonImmutable::parse('2026-06-10 09:00:00'),
        'doctorId'     => 'doctor-a',
        'patientId'    => null,
        'covenantId'   => 'covenant-ans',
        'covenantName' => 'OPERADORA',
        'isParticular' => false,
        'description'  => 'CONSULTA',
        'visitTypeId'  => 'visit-consulta',
        'procedureId'  => null,
        'examTypeId'   => null,
        'baseCents'    => 20000,
        'baseSource'   => DoctorPayoutBaseSource::Charged,
    ], $overrides));
}

function payoutResolverRule(array $attributes, ?string $id = null): DoctorPayoutRule
{
    $rule = new DoctorPayoutRule(array_merge([
        'service_type' => 'consultation',
        'payer_scope'  => 'any',
        'calculation'  => 'percentage',
        'percentage'   => '50.00',
        'active'       => true,
    ], $attributes));

    $rule->id = $id ?? (string) Str::uuid();

    return $rule;
}

function payoutResolverRun(array $rules, PayoutItemData $item): PayoutItemData
{
    return (new DoctorPayoutRuleResolver())->resolveItem(collect($rules), $item);
}

it('regra do médico vence a regra geral', function () {
    $general = payoutResolverRule(['percentage' => '50.00']);
    $own     = payoutResolverRule(['doctor_id' => 'doctor-a', 'percentage' => '60.00']);
    $other   = payoutResolverRule(['doctor_id' => 'doctor-b', 'percentage' => '90.00']);

    $item = payoutResolverRun([$general, $own, $other], payoutResolverItem());

    expect($item->ruleId)->toBe($own->id)
        ->and($item->payoutCents)->toBe(12000);
});

it('item específico vence o padrão do tipo; tipo de atendimento vence procedimento', function () {
    $default   = payoutResolverRule(['service_type' => 'procedure', 'percentage' => '10.00']);
    $procedure = payoutResolverRule(['service_type' => 'procedure', 'procedure_id' => 'proc-yag', 'percentage' => '20.00']);
    $visitType = payoutResolverRule(['service_type' => 'procedure', 'visit_type_id' => 'visit-yag', 'percentage' => '30.00']);

    $appointment = payoutResolverItem([
        'serviceType' => DoctorPayoutServiceType::Procedure, 'visitTypeId' => 'visit-yag', 'procedureId' => 'proc-yag',
    ]);
    $fromRecord = payoutResolverItem([
        'serviceType' => DoctorPayoutServiceType::Procedure, 'visitTypeId' => null, 'procedureId' => 'proc-yag',
    ]);
    $otherProcedure = payoutResolverItem([
        'serviceType' => DoctorPayoutServiceType::Procedure, 'visitTypeId' => null, 'procedureId' => 'proc-outro',
    ]);

    $rules = [$default, $procedure, $visitType];

    expect(payoutResolverRun($rules, $appointment)->ruleId)->toBe($visitType->id)
        ->and(payoutResolverRun($rules, $fromRecord)->ruleId)->toBe($procedure->id)
        ->and(payoutResolverRun($rules, $otherProcedure)->ruleId)->toBe($default->id);
});

it('pagador: convênio específico > grupo convênio > qualquer; particular só casa com particular', function () {
    $any        = payoutResolverRule(['percentage' => '10.00']);
    $covenants  = payoutResolverRule(['payer_scope' => 'covenant', 'calculation' => 'fixed', 'percentage' => null, 'fixed_amount' => '80.00']);
    $specific   = payoutResolverRule(['payer_scope' => 'covenant', 'covenant_id' => 'covenant-ans', 'calculation' => 'fixed', 'percentage' => null, 'fixed_amount' => '95.00']);
    $particular = payoutResolverRule(['payer_scope' => 'particular', 'percentage' => '60.00']);

    $rules = [$any, $covenants, $specific, $particular];

    expect(payoutResolverRun($rules, payoutResolverItem())->ruleId)->toBe($specific->id)
        ->and(payoutResolverRun($rules, payoutResolverItem(['covenantId' => 'covenant-x']))->ruleId)->toBe($covenants->id)
        ->and(payoutResolverRun($rules, payoutResolverItem(['covenantId' => null, 'isParticular' => true]))->ruleId)->toBe($particular->id)
        ->and(payoutResolverRun([$any, $covenants], payoutResolverItem(['covenantId' => null, 'isParticular' => true]))->ruleId)->toBe($any->id);
});

it('respeita a vigência (limites inclusivos), a situação e o tipo de serviço', function () {
    $june     = payoutResolverRule(['valid_from' => '2026-06-01', 'valid_until' => '2026-06-10', 'percentage' => '40.00']);
    $fromJuly = payoutResolverRule(['valid_from' => '2026-07-01', 'percentage' => '70.00']);
    $inactive = payoutResolverRule(['active' => false, 'percentage' => '99.00']);
    $examOnly = payoutResolverRule(['service_type' => 'exam', 'percentage' => '98.00']);

    $rules = [$june, $fromJuly, $inactive, $examOnly];

    expect(payoutResolverRun($rules, payoutResolverItem(['performedAt' => CarbonImmutable::parse('2026-06-10 23:00:00')]))->ruleId)->toBe($june->id)
        ->and(payoutResolverRun($rules, payoutResolverItem(['performedAt' => CarbonImmutable::parse('2026-07-01 08:00:00')]))->ruleId)->toBe($fromJuly->id)
        ->and(payoutResolverRun($rules, payoutResolverItem(['performedAt' => CarbonImmutable::parse('2026-06-15 08:00:00')]))->ruleId)->toBeNull();
});

it('percentual em centavos com meio centavo para cima; fixo ignora a base', function () {
    $half  = payoutResolverRule(['percentage' => '50.00']);
    $fixed = payoutResolverRule(['calculation' => 'fixed', 'percentage' => null, 'fixed_amount' => '35.50']);

    expect(payoutResolverRun([$half], payoutResolverItem(['baseCents' => 333]))->payoutCents)->toBe(167)
        ->and(payoutResolverRun([$half], payoutResolverItem(['baseCents' => 332]))->payoutCents)->toBe(166)
        ->and(payoutResolverRun([$fixed], payoutResolverItem(['baseCents' => 0, 'baseSource' => DoctorPayoutBaseSource::None])))
        ->payoutCents->toBe(3550)
        ->ruleFixedCents->toBe(3550)
        ->warnings->toBe([]);
});

it('percentual sobre item sem valor alerta; sem regra bloqueia o fechamento', function () {
    $percentage = payoutResolverRule(['service_type' => 'exam', 'percentage' => '50.00']);
    $exam       = payoutResolverItem([
        'serviceType' => DoctorPayoutServiceType::Exam, 'baseCents' => 0, 'baseSource' => DoctorPayoutBaseSource::None,
    ]);

    $resolved = payoutResolverRun([$percentage], $exam);
    expect($resolved->payoutCents)->toBe(0)
        ->and($resolved->hasWarning(DoctorPayoutWarning::NoBaseValue))->toBeTrue()
        ->and($resolved->blocksClosing())->toBeFalse();

    $unmatched = payoutResolverRun([], payoutResolverItem());
    expect($unmatched->ruleId)->toBeNull()
        ->and($unmatched->hasWarning(DoctorPayoutWarning::NoRule))->toBeTrue()
        ->and($unmatched->blocksClosing())->toBeTrue();
});
