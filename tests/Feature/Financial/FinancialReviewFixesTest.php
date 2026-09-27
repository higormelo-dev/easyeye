<?php

declare(strict_types=1);

/**
 * Correções da revisão da Fase 3 (financeiro):
 *  - Faturamento: convênio do filtro inativo/excluído (ex.: vindo do relatório
 *    de convênios) aparecia com o select em branco — agora vai à parte em
 *    `filteredCovenant`, sem entrar na lista dos modais e sem vazar outra clínica;
 *  - Glosas: resumo por operadora agrupava pelo NOME (homônimas se fundiam).
 */

use App\Domains\Tiss\Enums\TissGlosaStatus;
use App\Domains\Tiss\Models\{TissGlosa, TissOperator};
use App\Models\{Covenant, Entity};
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

describe('Faturamento — convênio do filtro fora da lista', function (): void {
    it('convênio inativo da clínica vai em filteredCovenant (e não na lista dos modais)', function (): void {
        $inactive = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => false, 'name' => 'CONVENIO ANTIGO']);

        $this->get(route('panel.financial.billing.index', ['tab' => 'claims', 'covenant_id' => $inactive->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filteredCovenant.id', $inactive->id)
                ->where('filteredCovenant.name', 'CONVENIO ANTIGO')
                ->where('covenants', fn ($list) => ! collect($list)->contains('id', $inactive->id)));
    });

    it('convênio de OUTRA clínica nunca aparece (nem o nome)', function (): void {
        $foreign = Covenant::factory()->create(['entity_id' => Entity::factory()->create()->id, 'name' => 'CONVENIO DE OUTRA CLINICA']);

        $this->get(route('panel.financial.billing.index', ['covenant_id' => $foreign->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filteredCovenant', null))
            ->assertDontSee('CONVENIO DE OUTRA CLINICA');
    });

    it('sem filtro (ou convênio já na lista) não manda filteredCovenant', function (): void {
        $this->get(route('panel.financial.billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filteredCovenant', null));
    });
});

describe('Glosas — resumo por operadora', function (): void {
    it('operadoras homônimas ficam em linhas separadas (agrupa pelo id)', function (): void {
        $operators = collect([1, 2])->map(fn (int $i) => TissOperator::query()->create([
            'ans_code'   => (string) (400000 + $i),
            'name'       => 'UNIMED',
            'trade_name' => 'UNIMED',
            'active'     => true,
        ]));

        foreach ($operators as $index => $operator) {
            TissGlosa::query()->create([
                'entity_id'         => $this->entity->id,
                'operator_id'       => $operator->id,
                'status'            => TissGlosaStatus::Open->value,
                'glosa_code'        => '30' . $index,
                'glosa_description' => 'Motivo ' . Str::random(4),
                'amount'            => 100 + $index,
                'identified_at'     => now()->toDateString(),
            ]);
        }

        $this->get(route('panel.financial.tiss.glosas.index', ['tab' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('byOperator', fn ($rows) => collect($rows)->where('name', 'UNIMED')->count() === 2
                    && collect($rows)->pluck('id')->sort()->values()->all() === $operators->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all()));
    });
});
