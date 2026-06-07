<?php

use App\Enums\{ClientRule, FinancialEntryType, PaymentMethod, ScheduleSituation};
use App\Models\{Entity, FinancialCashEntry, User};
use Illuminate\Testing\TestResponse;

// ── fixtures ──────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->user   = User::factory()->create();

    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Admin->value);

    ['schedule' => $this->schedule] = createScheduleForEntity($this->entity, [
        'situation' => ScheduleSituation::Waiting->value,
    ]);
});

/** POST autenticado com a sessão da entity (admin por padrão). */
function postCashEntry($test, array $payload = [], ?array $session = null): TestResponse
{
    return $test->actingAs($test->user)
        ->withSession($session ?? panelSession($test->entityUser))
        ->postJson(route('panel.schedules.cash-entry.store', $test->schedule), array_merge([
            'entry_date'     => now()->toDateString(),
            'description'    => 'Consulta — Paciente Teste',
            'payment_method' => PaymentMethod::Cash->value,
            'amount'         => 250.00,
        ], $payload));
}

describe('POST /panel/schedules/{schedule}/cash-entry', function () {
    it('cria lançamento vinculado ao agendamento', function () {
        postCashEntry($this)->assertOk();

        $entry = FinancialCashEntry::query()->firstOrFail();

        expect($entry->reference_type)->toBe('schedule')
            ->and($entry->reference_id)->toBe($this->schedule->id)
            ->and($entry->type)->toBe(FinancialEntryType::Income)
            ->and((float) $entry->amount)->toBe(250.00)
            ->and($entry->entity_id)->toBe($this->entity->id)
            ->and($entry->patient_id)->toBe($this->schedule->patient_id)
            ->and($entry->doctor_id)->toBe($this->schedule->doctor_id);
    });

    it('bloqueia segundo lançamento ativo para o mesmo agendamento', function () {
        postCashEntry($this)->assertOk();
        postCashEntry($this)->assertStatus(422);

        expect(FinancialCashEntry::query()->count())->toBe(1);
    });

    it('nega acesso a usuário sem permissão financeira (secretary)', function () {
        $secretaryUser = User::factory()->create();
        $secretaryEu   = createEntityUser($this->entity, $secretaryUser, ClientRule::Secretary->value);

        $this->actingAs($secretaryUser)
            ->withSession(panelSession($secretaryEu))
            ->postJson(route('panel.schedules.cash-entry.store', $this->schedule), [
                'entry_date'     => now()->toDateString(),
                'description'    => 'X',
                'payment_method' => PaymentMethod::Cash->value,
                'amount'         => 10,
            ])
            ->assertForbidden();

        expect(FinancialCashEntry::query()->count())->toBe(0);
    });

    it('valida campos obrigatórios', function () {
        postCashEntry($this, ['amount' => null, 'payment_method' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'payment_method']);
    });

    it('exige autenticação', function () {
        $this->postJson(route('panel.schedules.cash-entry.store', $this->schedule), [])
            ->assertUnauthorized();
    });
});

describe('validação de pagamento misto (breakdown)', function () {
    it('aceita crédito+dinheiro quando a soma confere com o total', function () {
        postCashEntry($this, [
            'payment_method' => PaymentMethod::CreditCash->value,
            'amount'         => 300.00,
            'amount_credit'  => 200.00,
            'amount_cash'    => 100.00,
            'installments'   => 2,
        ])->assertOk();

        expect((float) FinancialCashEntry::query()->firstOrFail()->amount)->toBe(300.00);
    });

    it('rejeita quando a soma do breakdown não bate com o total', function () {
        postCashEntry($this, [
            'payment_method' => PaymentMethod::CreditCash->value,
            'amount'         => 300.00,
            'amount_credit'  => 200.00,
            'amount_cash'    => 50.00, // soma 250 ≠ 300
            'installments'   => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        expect(FinancialCashEntry::query()->count())->toBe(0);
    });

    it('rejeita débito+dinheiro com parcela ausente do breakdown', function () {
        postCashEntry($this, [
            'payment_method' => PaymentMethod::DebitCash->value,
            'amount'         => 120.00,
            'amount_debit'   => 120.00,
            // amount_cash ausente
        ])->assertStatus(422)->assertJsonValidationErrors(['amount_cash']);
    });

    it('exige número de parcelas para pagamento no crédito', function () {
        postCashEntry($this, [
            'payment_method' => PaymentMethod::Credit->value,
            'amount'         => 90.00,
            'installments'   => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['installments']);
    });

    it('ignora breakdown em pagamento à vista (não combinado)', function () {
        postCashEntry($this, [
            'payment_method' => PaymentMethod::Cash->value,
            'amount'         => 90.00,
            'amount_credit'  => 999.00, // irrelevante para Cash
        ])->assertOk();
    });
});
