<?php

use App\Enums\{ClientRule, PaymentMethod, ScheduleSituation};
use App\Models\{Entity, User};
use Illuminate\Testing\TestResponse;

// A exigência de caixa para concluir o atendimento é OPCIONAL por entidade
// (requires_cash_to_complete). Por padrão é desligada — aqui habilitamos para
// validar o comportamento sob a flag.
beforeEach(function () {
    $this->entity = Entity::factory()->create([
        'is_client'                 => true,
        'active'                    => true,
        'requires_cash_to_complete' => true,
    ]);
    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Admin->value);

    ['schedule' => $this->schedule] = createScheduleForEntity($this->entity, [
        'situation' => ScheduleSituation::InProgress->value,
    ]);
});

/** PATCH situação -> Atendido. */
function markAttended($test): TestResponse
{
    return $test->actingAs($test->user)
        ->withSession(panelSession($test->entityUser))
        ->patchJson(route('panel.schedules.situation', $test->schedule), [
            'situation' => ScheduleSituation::Attended->value,
        ]);
}

/** POST lançamento de caixa para o agendamento. */
function cashEntry($test, array $payload = []): TestResponse
{
    return $test->actingAs($test->user)
        ->withSession(panelSession($test->entityUser))
        ->postJson(route('panel.schedules.cash-entry.store', $test->schedule), array_merge([
            'entry_date'     => now()->toDateString(),
            'description'    => 'Atendimento',
            'payment_method' => PaymentMethod::Cash->value,
            'amount'         => 100,
        ], $payload));
}

describe('conclusão do atendimento exige caixa quando a entidade habilita a flag', function () {
    it('bloqueia Atendido sem lançamento no caixa', function () {
        markAttended($this)
            ->assertStatus(422)
            ->assertJson(['requires_cash_entry' => true]);

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::InProgress);
    });

    it('permite Atendido após lançar no caixa', function () {
        cashEntry($this, ['amount' => 150])->assertOk();
        markAttended($this)->assertOk();

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Attended);
    });

    it('permite Atendido com Cortesia (R$ 0)', function () {
        cashEntry($this, ['payment_method' => PaymentMethod::Courtesy->value, 'amount' => 0])->assertOk();
        markAttended($this)->assertOk();

        expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Attended);
    });
});
