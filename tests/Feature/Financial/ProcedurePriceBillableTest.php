<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Entity, Procedure, ProcedurePrice, User};
use App\Services\Financial\ProcedurePriceService;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Tabela de Preços — fase 3: "Cobrar do convênio (guia TISS)" só vale para
 * convênio com operadora TISS (registro ANS). Nos demais (ex.: Particular) o
 * servidor grava charging=false mesmo com request forjado; a tela recebe a
 * flag por convênio, o preço padrão herdado e o link de cadastro conforme a
 * permissão — sem nunca ler ou gravar dados de outra clínica.
 */

beforeEach(function (): void {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->tissPlan   = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'UNIMED', 'ans_registry' => '326305']);
    $this->cashPlan   = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'PARTICULAR DA CLINICA', 'ans_registry' => null]);
    $this->procedure  = Procedure::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'code' => '10101012', 'name' => 'Consulta']);
    $this->entityUser = actingAsFinancialEntityUser($this->entity);
});

function procedurePriceBillableRow(Entity $entity, Covenant $covenant, Procedure $procedure): ?ProcedurePrice
{
    return ProcedurePrice::withTrashed()
        ->where('entity_id', $entity->id)
        ->where('covenant_id', $covenant->id)
        ->where('procedure_id', $procedure->id)
        ->first();
}

function procedurePriceBillablePost(Covenant $covenant, Procedure $procedure, mixed $charging, string $price = '150,00'): array
{
    $item = ['procedure_id' => $procedure->id, 'price' => $price];

    if ($charging !== 'omit') {
        $item['charging'] = $charging;
    }

    return ['covenant_id' => $covenant->id, 'items' => [$item]];
}

describe('tela (index)', function (): void {
    it('marca por convênio se há operadora TISS, sem expor o registro ANS', function (): void {
        $response  = $this->get(route('panel.financial.procedure-prices.index'))->assertOk();
        $covenants = collect($response->viewData('page')['props']['covenants'])->keyBy('name');

        expect($covenants['UNIMED']['tiss'])->toBeTrue()
            ->and($covenants['PARTICULAR DA CLINICA']['tiss'])->toBeFalse()
            ->and(array_keys($covenants['UNIMED']))->toBe(['id', 'name', 'tiss']);
    });

    it('envia o preço padrão do sistema (linha global ativa) e nunca o preço de outra clínica', function (): void {
        $other     = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $inactive  = Procedure::factory()->create(['entity_id' => null, 'active' => true]);
        $fromOther = Procedure::factory()->create(['entity_id' => null, 'active' => true]);

        ProcedurePrice::factory()->create(['entity_id' => null, 'covenant_id' => $this->tissPlan->id, 'procedure_id' => $this->procedure->id, 'price' => 99.9]);
        ProcedurePrice::factory()->create(['entity_id' => null, 'covenant_id' => $this->tissPlan->id, 'procedure_id' => $inactive->id, 'price' => 10, 'active' => false]);
        ProcedurePrice::factory()->create(['entity_id' => $other->id, 'covenant_id' => $this->tissPlan->id, 'procedure_id' => $fromOther->id, 'price' => 777]);

        $this->get(route('panel.financial.procedure-prices.index', ['covenant_id' => $this->tissPlan->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedCovenantId', $this->tissPlan->id)
                ->where("inheritedPrices.{$this->procedure->id}", 99.9)
                ->missing("inheritedPrices.{$inactive->id}")
                ->missing("inheritedPrices.{$fromOther->id}")
                ->missing("prices.{$fromOther->id}"));
    });

    it('link para cadastrar convênio só para quem pode abrir Configurações › Convênios', function (): void {
        $this->get(route('panel.financial.procedure-prices.index'))
            ->assertInertia(fn (Assert $page) => $page->where('links.covenants', route('panel.setting.covenants.index')));

        $user       = User::factory()->create();
        $entityUser = createEntityUser($this->entity, $user, ClientRule::Financial->value);

        $this->withSession(panelSession($entityUser))->actingAs($user)
            ->get(route('panel.financial.procedure-prices.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('links.covenants', null));
    });

    it('lang pt_BR e en da tela têm as mesmas chaves', function (): void {
        $pt = array_keys(Arr::dot(require lang_path('pt_BR/financial_procedure_prices.php')));
        $en = array_keys(Arr::dot(require lang_path('en/financial_procedure_prices.php')));

        sort($pt);
        sort($en);

        expect($en)->toBe($pt);
    });
});

describe('salvar (store): normalização do "Cobrar do convênio"', function (): void {
    it('convênio com operadora TISS respeita o que foi enviado (e o padrão é cobrar)', function (mixed $charging, bool $expected): void {
        $this->post(route('panel.financial.procedure-prices.store'), procedurePriceBillablePost($this->tissPlan, $this->procedure, $charging))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect(procedurePriceBillableRow($this->entity, $this->tissPlan, $this->procedure)->charging)->toBe($expected);
    })->with([
        'marcado'     => [true, true],
        'desmarcado'  => [false, false],
        'texto "0"'   => ['0', false],
        'sem o campo' => ['omit', true],
    ]);

    it('convênio sem operadora TISS grava charging=false mesmo com request forjado', function (mixed $charging): void {
        $this->post(route('panel.financial.procedure-prices.store'), procedurePriceBillablePost($this->cashPlan, $this->procedure, $charging))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $row = procedurePriceBillableRow($this->entity, $this->cashPlan, $this->procedure);

        expect($row->charging)->toBeFalse()
            ->and((float) $row->price)->toBe(150.0);
    })->with([
        'forjado true' => [true],
        'forjado "1"'  => ['1'],
        'forjado "on"' => ['on'],
        'sem o campo'  => ['omit'],
    ]);

    it('Particular global (sem registro ANS) também nunca cobra por guia', function (): void {
        $particular = Covenant::factory()->create(['entity_id' => null, 'active' => true, 'name' => 'PARTICULAR', 'ans_registry' => null]);

        $this->post(route('panel.financial.procedure-prices.store'), procedurePriceBillablePost($particular, $this->procedure, true))
            ->assertSessionHasNoErrors();

        expect(procedurePriceBillableRow($this->entity, $particular, $this->procedure)->charging)->toBeFalse();
    });

    it('salvar corrige a marcação antiga de um convênio sem TISS (inclusive ao restaurar preço apagado)', function (): void {
        $row = ProcedurePrice::query()->create([
            'entity_id' => $this->entity->id, 'covenant_id' => $this->cashPlan->id, 'procedure_id' => $this->procedure->id,
            'price'     => 100, 'charging' => true,
        ]);
        $row->delete();

        $this->post(route('panel.financial.procedure-prices.store'), procedurePriceBillablePost($this->cashPlan, $this->procedure, true, '120,00'))
            ->assertSessionHasNoErrors();

        $restored = procedurePriceBillableRow($this->entity, $this->cashPlan, $this->procedure);

        expect($restored->id)->toBe($row->id)
            ->and($restored->trashed())->toBeFalse()
            ->and($restored->charging)->toBeFalse()
            ->and((float) $restored->price)->toBe(120.0);
    });

    it('o serviço decide pela operadora TISS só com convênio da clínica ou global', function (): void {
        $service = app(ProcedurePriceService::class);
        $other   = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $foreign = Covenant::factory()->create(['entity_id' => $other->id, 'active' => true, 'ans_registry' => '359017']);

        expect($service->billsViaTiss((string) $this->entity->id, (string) $this->tissPlan->id))->toBeTrue()
            ->and($service->billsViaTiss((string) $this->entity->id, (string) $this->cashPlan->id))->toBeFalse()
            // Convênio TISS de outra clínica não "empresta" a elegibilidade.
            ->and($service->billsViaTiss((string) $this->entity->id, (string) $foreign->id))->toBeFalse();

        $service->syncForCovenant((string) $this->entity->id, (string) $this->cashPlan->id, [
            ['procedure_id' => $this->procedure->id, 'price' => 80.0, 'charging' => true],
        ]);

        expect(procedurePriceBillableRow($this->entity, $this->cashPlan, $this->procedure)->charging)->toBeFalse();
    });
});

describe('salvar (store): isolamento entre clínicas e validação', function (): void {
    it('convênio de outra clínica → 422 e nada gravado', function (): void {
        $other   = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $foreign = Covenant::factory()->create(['entity_id' => $other->id, 'active' => true, 'ans_registry' => '326305']);

        $this->post(route('panel.financial.procedure-prices.store'), procedurePriceBillablePost($foreign, $this->procedure, true))
            ->assertSessionHasErrors('covenant_id');

        $this->postJson(route('panel.financial.procedure-prices.store'), procedurePriceBillablePost($foreign, $this->procedure, true))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('covenant_id');

        expect(ProcedurePrice::withTrashed()->where('covenant_id', $foreign->id)->exists())->toBeFalse();
    });

    it('procedimento de outra clínica → 422 na linha e o lote não grava nada', function (): void {
        $other       = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $foreignProc = Procedure::factory()->create(['entity_id' => $other->id, 'active' => true]);

        $this->post(route('panel.financial.procedure-prices.store'), [
            'covenant_id' => $this->tissPlan->id,
            'items'       => [
                ['procedure_id' => $this->procedure->id, 'price' => '10,00', 'charging' => true],
                ['procedure_id' => $foreignProc->id, 'price' => '20,00', 'charging' => true],
            ],
        ])->assertSessionHasErrors('items.1.procedure_id');

        expect(ProcedurePrice::withTrashed()->where('entity_id', $this->entity->id)->exists())->toBeFalse()
            ->and(ProcedurePrice::withTrashed()->where('procedure_id', $foreignProc->id)->exists())->toBeFalse();
    });

    it('entity_id forjado no payload é ignorado: grava sempre na clínica da sessão', function (): void {
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);

        $this->post(route('panel.financial.procedure-prices.store'), [
            'entity_id'   => $other->id,
            'covenant_id' => $this->tissPlan->id,
            'items'       => [['procedure_id' => $this->procedure->id, 'price' => '30,00', 'entity_id' => $other->id]],
        ])->assertSessionHasNoErrors();

        expect(ProcedurePrice::query()->where('entity_id', $other->id)->exists())->toBeFalse()
            ->and(procedurePriceBillableRow($this->entity, $this->tissPlan, $this->procedure))->not->toBeNull();
    });

    it('preço acima da capacidade da coluna vira erro de validação na linha (não 500)', function (): void {
        $this->post(route('panel.financial.procedure-prices.store'), procedurePriceBillablePost($this->tissPlan, $this->procedure, true, '1000000000000'))
            ->assertSessionHasErrors('items.0.price');

        expect(procedurePriceBillableRow($this->entity, $this->tissPlan, $this->procedure))->toBeNull();
    });

    it('perfil sem permissão financeira (secretária) não salva nem abre a tabela', function (): void {
        $user       = User::factory()->create();
        $entityUser = createEntityUser($this->entity, $user, ClientRule::Secretary->value);

        $this->withSession(panelSession($entityUser))->actingAs($user)
            ->get(route('panel.financial.procedure-prices.index'))
            ->assertForbidden();

        $this->withSession(panelSession($entityUser))->actingAs($user)
            ->post(route('panel.financial.procedure-prices.store'), procedurePriceBillablePost($this->tissPlan, $this->procedure, true))
            ->assertForbidden();

        expect(procedurePriceBillableRow($this->entity, $this->tissPlan, $this->procedure))->toBeNull();
    });
});
