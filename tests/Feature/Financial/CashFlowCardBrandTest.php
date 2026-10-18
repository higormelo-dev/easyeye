<?php

declare(strict_types=1);

/*
 * Bandeira do cartão no lançamento de caixa (financial_cash_entries.card_brand):
 * opcional, só aceita quando a forma de pagamento usa cartão
 * (PaymentMethod::usesCard()), aparece na tela do caixa, no relatório e no CSV.
 */

use App\Enums\{CardBrand, CashEntryReferenceType, FinancialEntryStatus, FinancialEntryType, PaymentMethod};
use App\Models\{Entity, FinancialCashEntry};

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

function cardBrandPayload(array $overrides = []): array
{
    return array_merge([
        'entry_date'  => now()->toDateString(),
        'description' => 'Consulta particular',
        'type'        => 'income',
        'status'      => 'paid',
        'amount'      => 200,
    ], $overrides);
}

function cardBrandEntry(Entity $entity, array $attrs = []): FinancialCashEntry
{
    return FinancialCashEntry::query()->create(array_merge([
        'entity_id'   => $entity->id,
        'entry_date'  => now()->toDateString(),
        'description' => 'Lançamento',
        'type'        => FinancialEntryType::Income->value,
        'status'      => FinancialEntryStatus::Paid->value,
        'amount'      => 100,
        'active'      => true,
    ], $attrs));
}

describe('PaymentMethod::usesCard', function () {
    it('é verdadeiro só para crédito, crédito + dinheiro e débito + dinheiro', function () {
        $uses = collect(PaymentMethod::cases())->filter->usesCard()->map->value->sort()->values()->all();

        expect($uses)->toBe(['credit', 'credit_cash', 'debit_cash']);
    });
});

describe('POST: bandeira ao criar', function () {
    it('persiste a bandeira com crédito, na clínica da sessão', function () {
        $this->postJson(route('panel.financial.cash-flow.store'), cardBrandPayload([
            'payment_method' => 'credit',
            'card_brand'     => 'visa',
        ]))->assertOk();

        $entry = FinancialCashEntry::query()->where('entity_id', $this->entity->id)->firstOrFail();

        expect($entry->card_brand)->toBe(CardBrand::Visa)
            ->and($entry->payment_method)->toBe(PaymentMethod::Credit);
        $this->assertDatabaseHas('financial_cash_entries', ['id' => $entry->id, 'entity_id' => $this->entity->id, 'card_brand' => 'visa']);
    });

    it('aceita a bandeira com débito + dinheiro e com crédito + dinheiro', function () {
        foreach (['debit_cash' => 'elo', 'credit_cash' => 'mastercard'] as $method => $brand) {
            $this->postJson(route('panel.financial.cash-flow.store'), cardBrandPayload(['payment_method' => $method, 'card_brand' => $brand]))
                ->assertOk();
        }

        expect(FinancialCashEntry::query()->where('entity_id', $this->entity->id)->orderBy('card_brand')->pluck('card_brand')->map->value->all())
            ->toBe(['elo', 'mastercard']);
    });

    it('a bandeira é opcional mesmo com cartão', function () {
        $this->postJson(route('panel.financial.cash-flow.store'), cardBrandPayload(['payment_method' => 'credit']))->assertOk();
        $this->postJson(route('panel.financial.cash-flow.store'), cardBrandPayload(['payment_method' => 'credit', 'card_brand' => '']))->assertOk();

        expect(FinancialCashEntry::query()->where('entity_id', $this->entity->id)->whereNull('card_brand')->count())->toBe(2);
    });

    it('normaliza a caixa da bandeira', function () {
        $this->postJson(route('panel.financial.cash-flow.store'), cardBrandPayload(['payment_method' => 'credit', 'card_brand' => ' VISA ']))
            ->assertOk();

        expect(FinancialCashEntry::query()->where('entity_id', $this->entity->id)->firstOrFail()->card_brand)->toBe(CardBrand::Visa);
    });

    it('recusa bandeira inexistente', function () {
        $this->postJson(route('panel.financial.cash-flow.store'), cardBrandPayload(['payment_method' => 'credit', 'card_brand' => 'bitcoin']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('card_brand');

        expect(FinancialCashEntry::query()->count())->toBe(0);
    });

    it('recusa bandeira com forma sem cartão, sem forma ou cortesia', function () {
        foreach ([['payment_method' => 'cash'], ['payment_method' => 'transfer'], ['payment_method' => 'courtesy'], []] as $extra) {
            $this->postJson(route('panel.financial.cash-flow.store'), cardBrandPayload($extra + ['card_brand' => 'visa']))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['card_brand' => __('financial_cash_flow.card_brand_only_card')]);
        }

        expect(FinancialCashEntry::query()->count())->toBe(0);
    });
});

describe('PATCH: bandeira ao editar', function () {
    beforeEach(function () {
        $this->entry = cardBrandEntry($this->entity, ['payment_method' => PaymentMethod::Credit->value, 'card_brand' => CardBrand::Visa->value]);
    });

    it('troca a bandeira', function () {
        $this->patchJson(route('panel.financial.cash-flow.update', $this->entry->id), cardBrandPayload(['payment_method' => 'credit', 'card_brand' => 'elo']))
            ->assertOk();

        expect($this->entry->fresh()->card_brand)->toBe(CardBrand::Elo);
    });

    it('sem reenviar a forma, vale a forma gravada (continua aceitando bandeira)', function () {
        $this->patchJson(route('panel.financial.cash-flow.update', $this->entry->id), cardBrandPayload(['card_brand' => 'amex']))
            ->assertOk();

        expect($this->entry->fresh()->card_brand)->toBe(CardBrand::Amex);
    });

    it('limpa a bandeira quando o campo vai vazio', function () {
        $this->patchJson(route('panel.financial.cash-flow.update', $this->entry->id), cardBrandPayload(['payment_method' => 'credit', 'card_brand' => null]))
            ->assertOk();

        expect($this->entry->fresh()->card_brand)->toBeNull();
    });

    it('recusa mudar para forma sem cartão mantendo a bandeira no envio', function () {
        $this->patchJson(route('panel.financial.cash-flow.update', $this->entry->id), cardBrandPayload(['payment_method' => 'cash', 'card_brand' => 'visa']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('card_brand');

        $fresh = $this->entry->fresh();
        expect($fresh->payment_method)->toBe(PaymentMethod::Credit)
            ->and($fresh->card_brand)->toBe(CardBrand::Visa);
    });

    it('trocar para forma sem cartão (sem reenviar a bandeira) não deixa a bandeira presa', function () {
        $this->patchJson(route('panel.financial.cash-flow.update', $this->entry->id), cardBrandPayload(['payment_method' => 'cash']))
            ->assertOk();

        $fresh = $this->entry->fresh();
        expect($fresh->payment_method)->toBe(PaymentMethod::Cash)
            ->and($fresh->card_brand)->toBeNull();
    });

    it('lançamento antigo sem bandeira segue editável', function () {
        $old = cardBrandEntry($this->entity, ['payment_method' => PaymentMethod::Cash->value]);

        $this->patchJson(route('panel.financial.cash-flow.update', $old->id), cardBrandPayload(['description' => 'Ajustado', 'payment_method' => 'cash']))
            ->assertOk();

        expect($old->fresh()->description)->toBe('Ajustado')
            ->and($old->fresh()->card_brand)->toBeNull();
    });

    it('no recebimento da agenda com pagamento dividido a bandeira continua editável (forma travada)', function () {
        ['schedule' => $schedule] = createScheduleForEntity($this->entity);

        $split = cardBrandEntry($this->entity, [
            'status'         => FinancialEntryStatus::Pending->value,
            'amount'         => 300, 'amount_cash' => 100, 'amount_credit' => 200,
            'payment_method' => PaymentMethod::CreditCash->value,
            'reference_type' => CashEntryReferenceType::Schedule->value, 'reference_id' => $schedule->id,
        ]);

        $this->patchJson(route('panel.financial.cash-flow.update', $split->id), cardBrandPayload(['amount' => 300, 'card_brand' => 'hipercard']))
            ->assertOk();

        $fresh = $split->fresh();
        expect($fresh->card_brand)->toBe(CardBrand::Hipercard)
            ->and($fresh->payment_method)->toBe(PaymentMethod::CreditCash);

        // forma continua travada
        $this->patchJson(route('panel.financial.cash-flow.update', $split->id), cardBrandPayload(['amount' => 300, 'payment_method' => 'cash']))
            ->assertUnprocessable();
    });
});

describe('Tela do caixa e relatório', function () {
    it('o index expõe uses_card por forma, as bandeiras e card_brand(_label) por linha', function () {
        cardBrandEntry($this->entity, ['description' => 'Com bandeira', 'payment_method' => PaymentMethod::Credit->value, 'card_brand' => CardBrand::Mastercard->value]);
        cardBrandEntry($this->entity, ['description' => 'Sem bandeira', 'payment_method' => PaymentMethod::Cash->value]);

        $this->get(route('panel.financial.cash-flow.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Financial/CashFlow/Index')
                ->where('payment_methods', fn ($methods) => collect($methods)->mapWithKeys(fn ($m) => [$m['value'] => $m['uses_card']])->all() === [
                    'cash'        => false,
                    'credit'      => true,
                    'credit_cash' => true,
                    'debit_cash'  => true,
                    'transfer'    => false,
                    'courtesy'    => false,
                ])
                ->where('card_brands', fn ($brands) => collect($brands)->pluck('value')->all() === ['visa', 'mastercard', 'elo', 'amex', 'hipercard', 'diners', 'others'])
                ->where('card_brands.1.label', 'Mastercard')
                ->where('entries.data', fn ($rows) => collect($rows)->firstWhere('description', 'Com bandeira')['card_brand'] === 'mastercard'
                    && collect($rows)->firstWhere('description', 'Com bandeira')['card_brand_label'] === 'Mastercard'
                    && collect($rows)->firstWhere('description', 'Sem bandeira')['card_brand'] === null
                    && collect($rows)->firstWhere('description', 'Sem bandeira')['card_brand_label'] === null));
    });

    it('o relatório de fluxo de caixa devolve o rótulo da bandeira por linha', function () {
        cardBrandEntry($this->entity, ['description' => 'Com bandeira', 'payment_method' => PaymentMethod::Credit->value, 'card_brand' => CardBrand::Diners->value]);

        $this->get(route('panel.financial.reports.cash-flow'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('entries.data.0.card_brand_label', 'Diners Club')
                ->where('entries.data.0.payment_method_label', PaymentMethod::Credit->label()));
    });

    it('não mostra a bandeira de outra clínica', function () {
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        cardBrandEntry($other, ['description' => 'Alheio', 'payment_method' => PaymentMethod::Credit->value, 'card_brand' => CardBrand::Visa->value]);

        $this->get(route('panel.financial.cash-flow.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('entries.data', 0));

        $csv = $this->get(route('panel.financial.reports.cash-flow.export'))->assertOk()->getContent();
        expect($csv)->not->toContain('Alheio')->not->toContain('Visa');
    });
});

describe('Exportação CSV', function () {
    it('traz Forma de pagamento e Bandeira no fim, com os rótulos da linha', function () {
        cardBrandEntry($this->entity, ['description' => 'No cartão', 'payment_method' => PaymentMethod::Credit->value, 'card_brand' => CardBrand::Visa->value]);
        cardBrandEntry($this->entity, ['description' => 'Em dinheiro', 'payment_method' => PaymentMethod::Cash->value, 'entry_date' => now()->addSecond()->toDateString()]);
        cardBrandEntry($this->entity, ['description' => 'Antigo sem forma', 'entry_date' => now()->toDateString()]);

        $body = $this->get(route('panel.financial.reports.cash-flow.export'))->assertOk()->getContent();
        $rows = array_map(
            fn (string $line) => str_getcsv($line, ';', '"', ''),
            preg_split('/\r?\n/', trim(substr($body, 3))),
        );

        $byDescription = collect($rows)->skip(1)->keyBy(2);

        expect(array_slice($rows[0], -3))->toBe(['Valor', 'Forma de pagamento', 'Bandeira'])
            ->and(array_slice($byDescription['No cartão'], -2))->toBe([PaymentMethod::Credit->label(), 'Visa'])
            ->and(array_slice($byDescription['Em dinheiro'], -2))->toBe([PaymentMethod::Cash->label(), ''])
            ->and(array_slice($byDescription['Antigo sem forma'], -2))->toBe(['', '']);
    });

    it('cabeçalhos em inglês para usuário em inglês', function () {
        $body = $this->withSession(['locale' => 'en'])
            ->get(route('panel.financial.reports.cash-flow.export'))
            ->assertOk()
            ->getContent();

        $header = str_getcsv(preg_split('/\r?\n/', trim(substr($body, 3)))[0], ';', '"', '');

        expect(array_slice($header, -2))->toBe(['Payment method', 'Card brand']);
    });
});
