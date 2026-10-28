<?php

declare(strict_types=1);

use App\Http\Requests\Financial\ProcedurePriceRequest;
use App\Models\{Covenant, Entity, Procedure, ProcedurePrice};
use App\Services\Financial\ProcedurePriceService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Tabela de Preços — Fase 4: o Salvar envia SÓ as linhas alteradas, com
 * semântica explícita por linha (preço informado = grava; null = remove
 * aquele preço; linha ausente = intocada — nunca "ausente = remover"), os
 * procedimentos do lote são validados com UMA consulta, há teto de itens por
 * request e os preços do convênio de origem do "Copiar de outro convênio"
 * chegam por recarga parcial, sem rota nova e sem vazar outra clínica.
 */

beforeEach(function (): void {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant   = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'UNIMED', 'ans_registry' => '326305']);
    $this->procedures = Procedure::factory()->count(3)->create(['entity_id' => $this->entity->id, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

function ppbPrice(Entity $entity, Covenant $covenant, Procedure $procedure, float $price, bool $charging = true): ProcedurePrice
{
    return ProcedurePrice::query()->create([
        'entity_id'    => $entity->id,
        'covenant_id'  => $covenant->id,
        'procedure_id' => $procedure->id,
        'price'        => $price,
        'charging'     => $charging,
        'active'       => true,
    ]);
}

function ppbRow(Entity $entity, Covenant $covenant, Procedure $procedure): ?ProcedurePrice
{
    return ProcedurePrice::withTrashed()
        ->where('entity_id', $entity->id)
        ->where('covenant_id', $covenant->id)
        ->where('procedure_id', $procedure->id)
        ->first();
}

/** @return array<string, mixed> preço vivo (null = sem linha ou apagada) por procedimento */
function ppbLivePrices(Entity $entity, Covenant $covenant, iterable $procedures): array
{
    $out = [];

    foreach ($procedures as $procedure) {
        $row                          = ppbRow($entity, $covenant, $procedure);
        $out[(string) $procedure->id] = $row === null || $row->trashed() ? null : (float) $row->price;
    }

    return $out;
}

describe('salvar só as linhas alteradas', function (): void {
    it('procedimentos que não vieram no lote ficam intocados (nunca "ausente = remover")', function (): void {
        [$a, $b, $c] = $this->procedures->all();
        ppbPrice($this->entity, $this->covenant, $a, 100);
        ppbPrice($this->entity, $this->covenant, $b, 200);
        ppbPrice($this->entity, $this->covenant, $c, 300);

        $this->post(route('panel.financial.procedure-prices.store'), [
            'covenant_id' => $this->covenant->id,
            'items'       => [['procedure_id' => $b->id, 'price' => '250,00', 'charging' => true]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        expect(ppbLivePrices($this->entity, $this->covenant, [$a, $b, $c]))
            ->toBe([(string) $a->id => 100.0, (string) $b->id => 250.0, (string) $c->id => 300.0]);
    });

    it('preço limpo (null) remove SÓ aquele preço', function (): void {
        [$a, $b, $c] = $this->procedures->all();
        ppbPrice($this->entity, $this->covenant, $a, 100);
        ppbPrice($this->entity, $this->covenant, $b, 200);
        ppbPrice($this->entity, $this->covenant, $c, 300);

        $this->post(route('panel.financial.procedure-prices.store'), [
            'covenant_id' => $this->covenant->id,
            'items'       => [['procedure_id' => $b->id, 'price' => null, 'charging' => true]],
        ])->assertSessionHasNoErrors();

        expect(ppbLivePrices($this->entity, $this->covenant, [$a, $b, $c]))
            ->toBe([(string) $a->id => 100.0, (string) $b->id => null, (string) $c->id => 300.0])
            ->and(ppbRow($this->entity, $this->covenant, $b)->trashed())->toBeTrue();
    });

    it('linha sem a chave "price" é erro na linha (422), não remoção — e o lote não grava nada', function (): void {
        [$a, $b] = $this->procedures->all();
        ppbPrice($this->entity, $this->covenant, $a, 100);

        $this->postJson(route('panel.financial.procedure-prices.store'), [
            'covenant_id' => $this->covenant->id,
            'items'       => [
                ['procedure_id' => $a->id, 'charging' => true],
                ['procedure_id' => $b->id, 'price' => 55],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.price' => __('financial_procedure_prices.price_missing')]);

        expect(ppbLivePrices($this->entity, $this->covenant, [$a, $b]))
            ->toBe([(string) $a->id => 100.0, (string) $b->id => null]);
    });

    it('no serviço: item sem a chave "price" não muda nada e o que não veio fica como está', function (): void {
        [$a, $b] = $this->procedures->all();
        ppbPrice($this->entity, $this->covenant, $a, 100);
        ppbPrice($this->entity, $this->covenant, $b, 200);

        app(ProcedurePriceService::class)->syncForCovenant((string) $this->entity->id, (string) $this->covenant->id, [
            ['procedure_id' => $a->id, 'charging' => false],
        ]);

        expect(ppbLivePrices($this->entity, $this->covenant, [$a, $b]))
            ->toBe([(string) $a->id => 100.0, (string) $b->id => 200.0]);
    });

    it('lote vazio é aceito e não muda nada', function (): void {
        [$a] = $this->procedures->all();
        ppbPrice($this->entity, $this->covenant, $a, 100);

        $this->post(route('panel.financial.procedure-prices.store'), ['covenant_id' => $this->covenant->id, 'items' => []])
            ->assertSessionHasNoErrors();

        expect(ppbLivePrices($this->entity, $this->covenant, [$a]))->toBe([(string) $a->id => 100.0]);
    });

    it('convênio sem operadora TISS continua gravando charging=false no lote parcial', function (): void {
        [$a]  = $this->procedures->all();
        $cash = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'PARTICULAR', 'ans_registry' => null]);

        $this->post(route('panel.financial.procedure-prices.store'), [
            'covenant_id' => $cash->id,
            'items'       => [['procedure_id' => $a->id, 'price' => '80,00', 'charging' => true]],
        ])->assertSessionHasNoErrors();

        expect(ppbRow($this->entity, $cash, $a)->charging)->toBeFalse();
    });
});

describe('validação do lote', function (): void {
    it('procedimento de outra clínica (request forjado) → 422 na linha, mensagem traduzida e nada gravado', function (): void {
        [$a]     = $this->procedures->all();
        $other   = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $foreign = Procedure::factory()->create(['entity_id' => $other->id, 'active' => true]);

        $this->postJson(route('panel.financial.procedure-prices.store'), [
            'covenant_id' => $this->covenant->id,
            'items'       => [
                ['procedure_id' => $a->id, 'price' => '10,00'],
                ['procedure_id' => $foreign->id, 'price' => '20,00'],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.procedure_id' => __('financial_procedure_prices.procedure_invalid')])
            ->assertJsonMissingValidationErrors(['items.0.procedure_id']);

        expect(ProcedurePrice::withTrashed()->where('entity_id', $this->entity->id)->exists())->toBeFalse()
            ->and(ProcedurePrice::withTrashed()->where('procedure_id', $foreign->id)->exists())->toBeFalse();
    });

    it('procedimento global é aceito; excluído ou inexistente não', function (): void {
        $global  = Procedure::factory()->create(['entity_id' => null, 'active' => true]);
        $deleted = Procedure::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
        $deleted->delete();

        $this->postJson(route('panel.financial.procedure-prices.store'), [
            'covenant_id' => $this->covenant->id,
            'items'       => [
                ['procedure_id' => $global->id, 'price' => 10],
                ['procedure_id' => $deleted->id, 'price' => 20],
                ['procedure_id' => (string) Str::uuid(), 'price' => 30],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.procedure_id', 'items.2.procedure_id'])
            ->assertJsonMissingValidationErrors(['items.0.procedure_id']);

        $this->post(route('panel.financial.procedure-prices.store'), [
            'covenant_id' => $this->covenant->id,
            'items'       => [['procedure_id' => $global->id, 'price' => 10]],
        ])->assertSessionHasNoErrors();

        expect((float) ppbRow($this->entity, $this->covenant, $global)->price)->toBe(10.0);
    });

    it('procedimento repetido no mesmo lote → 422 (a linha é ambígua)', function (): void {
        [$a] = $this->procedures->all();

        $this->postJson(route('panel.financial.procedure-prices.store'), [
            'covenant_id' => $this->covenant->id,
            'items'       => [
                ['procedure_id' => $a->id, 'price' => 10],
                ['procedure_id' => $a->id, 'price' => null],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.procedure_id' => __('financial_procedure_prices.procedure_duplicate')]);

        expect(ppbRow($this->entity, $this->covenant, $a))->toBeNull();
    });

    it('mensagens por linha seguem o idioma do usuário', function (): void {
        // Idioma da sessão (o middleware SetLocale redefine o app()->setLocale()).
        $this->withSession(['locale' => 'en']);
        $other   = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $foreign = Procedure::factory()->create(['entity_id' => $other->id, 'active' => true]);

        $this->postJson(route('panel.financial.procedure-prices.store'), [
            'covenant_id' => $this->covenant->id,
            'items'       => [['procedure_id' => $foreign->id, 'price' => 1]],
        ])->assertJsonValidationErrors(['items.0.procedure_id' => 'Procedure not found or from another clinic.']);
    });

    it('acima do teto de itens por request → 422 sem validar linha a linha e nada gravado', function (): void {
        [$a]   = $this->procedures->all();
        $items = array_fill(0, ProcedurePriceRequest::MAX_ITEMS + 1, ['procedure_id' => $a->id, 'price' => 1]);

        $procedureQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$procedureQueries): void {
            if (preg_match('/from\s+"procedures"/i', $query->sql)) {
                $procedureQueries++;
            }
        });

        $this->postJson(route('panel.financial.procedure-prices.store'), ['covenant_id' => $this->covenant->id, 'items' => $items])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items' => __('financial_procedure_prices.items_max', ['max' => ProcedurePriceRequest::MAX_ITEMS])])
            ->assertJsonMissingValidationErrors(['items.0.procedure_id', 'items.1.procedure_id']);

        expect($procedureQueries)->toBe(0)
            ->and(ppbRow($this->entity, $this->covenant, $a))->toBeNull();
    });

    it('valida os procedimentos do lote com UMA consulta, qualquer que seja o tamanho do lote', function (int $size): void {
        $procedures = Procedure::factory()->count($size)->sequence(
            fn ($sequence) => ['entity_id' => $sequence->index % 2 === 0 ? $this->entity->id : null, 'active' => true],
        )->create();

        $procedureQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$procedureQueries): void {
            if (preg_match('/from\s+"procedures"/i', $query->sql)) {
                $procedureQueries[] = $query->sql;
            }
        });

        $this->post(route('panel.financial.procedure-prices.store'), [
            'covenant_id' => $this->covenant->id,
            'items'       => $procedures->map(fn (Procedure $p, int $i) => ['procedure_id' => $p->id, 'price' => 10 + $i])->all(),
        ])->assertSessionHasNoErrors();

        expect($procedureQueries)->toHaveCount(1)
            ->and($procedureQueries[0])->toContain(' in (')
            ->and(ProcedurePrice::query()->where('entity_id', $this->entity->id)->count())->toBe($size);
    })->with([
        '1 linha'   => [1],
        '40 linhas' => [40],
    ]);
});

describe('"Copiar de outro convênio" (recarga parcial)', function (): void {
    beforeEach(function (): void {
        [$this->a, $this->b, $this->c] = $this->procedures->all();

        $this->source = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'BRADESCO', 'ans_registry' => '005711']);

        // a: só preço da clínica; b: só padrão do sistema; c: clínica sobrepõe o padrão.
        ppbPrice($this->entity, $this->source, $this->a, 150);
        ProcedurePrice::factory()->create(['entity_id' => null, 'covenant_id' => $this->source->id, 'procedure_id' => $this->b->id, 'price' => 80]);
        ProcedurePrice::factory()->create(['entity_id' => null, 'covenant_id' => $this->source->id, 'procedure_id' => $this->c->id, 'price' => 70]);
        ppbPrice($this->entity, $this->source, $this->c, 90);
    });

    function ppbPartial(array $query): array
    {
        return test()->withHeaders(array_merge(inertiaHeaders(), [
            'X-Inertia-Partial-Component' => 'Panel/Financial/ProcedurePrices/Index',
            'X-Inertia-Partial-Data'      => 'sourcePrices',
        ]))->get(route('panel.financial.procedure-prices.index', $query))->assertOk()->json('props');
    }

    it('só vem na recarga parcial e traz o preço da clínica ou, sem ele, o padrão do sistema', function (): void {
        // Outra clínica com preço no mesmo convênio global/procedimento: não pode vazar.
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        ProcedurePrice::factory()->create(['entity_id' => $other->id, 'covenant_id' => $this->source->id, 'procedure_id' => $this->b->id, 'price' => 999]);
        // Procedimento inativo (fora da grade) com preço: não entra.
        $inactive = Procedure::factory()->create(['entity_id' => $this->entity->id, 'active' => false]);
        ppbPrice($this->entity, $this->source, $inactive, 5);

        $this->get(route('panel.financial.procedure-prices.index', ['covenant_id' => $this->covenant->id, 'source_covenant_id' => $this->source->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('sourcePrices')->where('limits.max_items', ProcedurePriceRequest::MAX_ITEMS));

        $props = ppbPartial(['covenant_id' => $this->covenant->id, 'source_covenant_id' => $this->source->id]);

        expect($props)->not->toHaveKeys(['prices', 'inheritedPrices', 'procedures', 'covenants'])
            ->and($props['sourcePrices']['covenant_id'])->toBe($this->source->id)
            ->and($props['sourcePrices']['prices'])->toEqual([
                (string) $this->a->id => 150,
                (string) $this->b->id => 80,
                (string) $this->c->id => 90,
            ]);
    });

    it('convênio global também serve de origem', function (): void {
        $global = Covenant::factory()->create(['entity_id' => null, 'active' => true, 'name' => 'GLOBAL']);
        ProcedurePrice::factory()->create(['entity_id' => null, 'covenant_id' => $global->id, 'procedure_id' => $this->a->id, 'price' => 33]);

        $props = ppbPartial(['source_covenant_id' => $global->id]);

        expect($props['sourcePrices'])->toEqual(['covenant_id' => $global->id, 'prices' => [(string) $this->a->id => 33]]);
    });

    it('origem de outra clínica, inativa ou id inválido → null (nada vaza)', function (): void {
        $other        = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $foreign      = Covenant::factory()->create(['entity_id' => $other->id, 'active' => true]);
        $inactivePlan = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => false]);
        ProcedurePrice::factory()->create(['entity_id' => $other->id, 'covenant_id' => $foreign->id, 'procedure_id' => $this->a->id, 'price' => 999]);

        foreach ([$foreign->id, $inactivePlan->id, 'abc', ''] as $sourceId) {
            expect(ppbPartial(['source_covenant_id' => $sourceId])['sourcePrices'])->toBeNull();
        }
    });
});
