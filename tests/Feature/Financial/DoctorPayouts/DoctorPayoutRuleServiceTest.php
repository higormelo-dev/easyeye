<?php

/*
 * Repasse médico — cadastro de regras (DoctorPayoutRuleService): "todos os
 * tipos", coerência dos campos e recusa de vigência sobreposta no mesmo escopo.
 */

use App\Models\{Covenant, DoctorPayoutRule, Entity};
use App\Services\Financial\DoctorPayouts\DoctorPayoutRuleService;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctor  = createDoctorForEntity($this->entity);
    $this->service = app(DoctorPayoutRuleService::class);
});

function payoutRuleData(array $overrides = []): array
{
    return array_merge([
        'doctor_id'     => null,
        'service_type'  => 'consultation',
        'visit_type_id' => null,
        'procedure_id'  => null,
        'exam_type_id'  => null,
        'payer_scope'   => 'any',
        'covenant_id'   => null,
        'calculation'   => 'percentage',
        'percentage'    => '60',
        'fixed_amount'  => null,
        'valid_from'    => null,
        'valid_until'   => null,
        'active'        => true,
        'notes'         => null,
    ], $overrides);
}

it('"todos os tipos" cria uma regra por tipo de serviço', function () {
    $created = $this->service->create($this->entity->id, payoutRuleData(['service_type' => 'all']));

    expect($created)->toHaveCount(3)
        ->and($created->map(fn (DoctorPayoutRule $r) => $r->service_type->value)->sort()->values()->all())
        ->toBe(['consultation', 'exam', 'procedure']);
});

it('zera o que o cálculo/pagador não usa', function () {
    $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);

    $rule = $this->service->create($this->entity->id, payoutRuleData([
        'calculation'  => 'fixed',
        'percentage'   => '60',
        'fixed_amount' => '80.00',
        'payer_scope'  => 'particular',
        'covenant_id'  => $covenant->id,
    ]))->first();

    expect($rule->percentage)->toBeNull()
        ->and((string) $rule->fixed_amount)->toBe('80.00')
        ->and($rule->covenant_id)->toBeNull();
});

it('recusa vigência sobreposta no mesmo escopo e aceita escopos/vigências distintos', function () {
    $this->service->create($this->entity->id, payoutRuleData(['valid_from' => '2026-01-01', 'valid_until' => '2026-06-30']));

    expect(fn () => $this->service->create($this->entity->id, payoutRuleData(['valid_from' => '2026-06-30'])))
        ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.rule_overlap'));

    expect(fn () => $this->service->create($this->entity->id, payoutRuleData())) // sem limite cruza com tudo
        ->toThrow(ValidationException::class);

    // Mesma vigência, escopo diferente (médico específico / pagador) — permitido.
    $this->service->create($this->entity->id, payoutRuleData(['doctor_id' => $this->doctor->id, 'valid_from' => '2026-01-01']));
    $this->service->create($this->entity->id, payoutRuleData(['payer_scope' => 'particular', 'valid_from' => '2026-01-01']));

    // Mesmo escopo, vigência seguinte — permitido; inativa não disputa.
    $this->service->create($this->entity->id, payoutRuleData(['valid_from' => '2026-07-01']));
    $this->service->create($this->entity->id, payoutRuleData(['valid_from' => '2026-03-01', 'active' => false]));

    expect(DoctorPayoutRule::query()->where('entity_id', $this->entity->id)->count())->toBe(5);
});

it('na edição, a própria regra não conta como sobreposição; reativar volta a checar', function () {
    $first  = $this->service->create($this->entity->id, payoutRuleData(['valid_until' => '2026-06-30']))->first();
    $second = $this->service->create($this->entity->id, payoutRuleData(['valid_from' => '2026-07-01', 'active' => false]))->first();

    $updated = $this->service->update($first, payoutRuleData(['valid_until' => '2026-06-30', 'percentage' => '65']));
    expect((string) $updated->percentage)->toBe('65.00');

    expect(fn () => $this->service->update($second, payoutRuleData(['valid_from' => '2026-06-15', 'active' => true])))
        ->toThrow(ValidationException::class);
});

it('regras de outra clínica não interferem na sobreposição nem na busca', function () {
    $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->service->create($other->id, payoutRuleData());

    $this->service->create($this->entity->id, payoutRuleData());

    expect($this->service->activeRulesFor($this->entity->id, $this->doctor->id))->toHaveCount(1);
});
