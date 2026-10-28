<?php

declare(strict_types=1);

use App\Domains\Tiss\Enums\{TissAppealStatus, TissGlosaStatus};
use App\Domains\Tiss\Models\{TissGlosa, TissGlosaAppeal, TissOperator, TissStatusHistory};
use App\Models\{BillingClaim, Entity};
use App\Services\Financial\BillingService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Conciliação de glosas como fila (Fase 3): aba Pendentes de qualquer data
 * ordenada pelo prazo, histórico pelo período, KPIs da fila, busca literal
 * escopada pela clínica, detalhe por recarga parcial sem vazar outra clínica
 * e "Marcar como enviado" mantendo o 409 em transição inválida.
 */

function gqOperator(string $name = 'Operadora'): TissOperator
{
    return TissOperator::query()->create([
        'ans_code' => (string) random_int(100000, 999999),
        'name'     => $name . ' ' . Str::random(4),
        'active'   => true,
    ]);
}

/** @param array<string, mixed> $overrides */
function gqGlosa(Entity $entity, TissOperator $operator, array $overrides = []): TissGlosa
{
    return TissGlosa::query()->create(array_merge([
        'entity_id'         => $entity->id,
        'operator_id'       => $operator->id,
        'status'            => TissGlosaStatus::Open->value,
        'glosa_code'        => '3099',
        'glosa_description' => 'Procedimento não autorizado',
        'amount'            => 100,
        'identified_at'     => now()->toDateString(),
        'deadline'          => now()->addDays(10)->toDateString(),
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function gqAppeal(TissGlosa $glosa, array $overrides = []): TissGlosaAppeal
{
    return TissGlosaAppeal::query()->create(array_merge([
        'entity_id'        => $glosa->entity_id,
        'glosa_id'         => $glosa->id,
        'appeal_number'    => 'REC-' . now()->format('Ym') . '-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'status'           => TissAppealStatus::Submitted->value,
        'reason'           => 'Cobertura contratual válida.',
        'requested_amount' => $glosa->amount,
        'accepted_amount'  => 0,
        'submitted_at'     => now(),
        'deadline'         => now()->addDays(60)->toDateString(),
    ], $overrides));
}

/** @return list<string> ids da página da lista (paginator: `glosas.data`), na ordem da tela */
function gqListIds(TestResponse $response): array
{
    return collect($response->viewData('page')['props']['glosas']['data'])->pluck('id')->all();
}

beforeEach(function (): void {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->entityUser = actingAsFinancialEntityUser($this->entity);
    $this->operator   = gqOperator();
});

describe('fila (aba Pendentes)', function (): void {
    it('lista abertas e recorridas de QUALQUER data, pelo prazo da próxima ação: vencidas primeiro, sem prazo por último', function (): void {
        $soon    = gqGlosa($this->entity, $this->operator, ['deadline' => now()->addDays(10)->toDateString()]);
        $overdue = gqGlosa($this->entity, $this->operator, [
            'deadline'      => now()->subDays(3)->toDateString(),
            'identified_at' => now()->subMonths(6)->toDateString(),
        ]);
        $noDeadline = gqGlosa($this->entity, $this->operator, ['deadline' => null]);

        // Recurso enviado: vale o prazo de resposta da operadora (+30), não o da glosa (−20).
        $waiting = gqGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::Appealed->value, 'deadline' => now()->subDays(20)->toDateString()]);
        gqAppeal($waiting, ['deadline' => now()->addDays(30)->toDateString()]);

        // Recurso só aberto (a enviar): vale o prazo da glosa (+2).
        $toSend = gqGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::Appealed->value, 'deadline' => now()->addDays(2)->toDateString()]);
        gqAppeal($toSend, ['status' => TissAppealStatus::Opened->value, 'submitted_at' => null, 'deadline' => null]);

        $resolved = gqGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::Reversed->value]);

        $response = $this->get(route('panel.financial.tiss.glosas.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Financial/Tiss/GlosasIndex')
                ->where('filters.tab', 'pending')
                ->where('tabCounts.pending', 5)
                ->where('glosas.total', 5));

        expect(gqListIds($response))->toBe([$overdue->id, $toSend->id, $soon->id, $waiting->id, $noDeadline->id])
            ->not->toContain($resolved->id);
    });

    it('KPIs da fila independem do período, respeitam a operadora filtrada e nunca somam outra clínica', function (): void {
        gqGlosa($this->entity, $this->operator, ['amount' => 100, 'deadline' => now()->subDays(2)->toDateString(), 'identified_at' => now()->subYear()->toDateString()]);
        gqGlosa($this->entity, $this->operator, ['amount' => 40, 'deadline' => now()->addDays(2)->toDateString()]);
        $appealed = gqGlosa($this->entity, $this->operator, ['amount' => 70, 'status' => TissGlosaStatus::Appealed->value]);
        gqAppeal($appealed);

        $otherOperator = gqOperator('Outra');
        gqGlosa($this->entity, $otherOperator, ['amount' => 500, 'deadline' => now()->subDay()->toDateString()]);

        $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
        gqGlosa($otherEntity, $this->operator, ['amount' => 900, 'deadline' => now()->subDay()->toDateString()]);

        $this->get(route('panel.financial.tiss.glosas.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.open', 640)
                ->where('summary.open_count', 3)
                ->where('summary.appealed', 70)
                ->where('summary.overdue', 600)
                ->where('summary.overdue_count', 2)
                ->where('summary.due_soon', 40)
                ->where('summary.due_soon_count', 1));

        $this->get(route('panel.financial.tiss.glosas.index', ['operator_id' => $this->operator->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.operator_id', $this->operator->id)
                ->where('summary.open', 140)
                ->where('summary.overdue', 100)
                ->where('summary.overdue_count', 1)
                ->where('tabCounts.pending', 3));
    });

    it('filtros de prazo dos KPIs: vencidas e vencendo em N dias (só abertas)', function (): void {
        $overdue = gqGlosa($this->entity, $this->operator, ['deadline' => now()->subDay()->toDateString()]);
        $soon    = gqGlosa($this->entity, $this->operator, ['deadline' => now()->addDays(3)->toDateString()]);
        gqGlosa($this->entity, $this->operator, ['deadline' => now()->addDays(30)->toDateString()]);
        $appealedOverdue = gqGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::Appealed->value, 'deadline' => now()->subDays(5)->toDateString()]);

        $overdueList = $this->get(route('panel.financial.tiss.glosas.index', ['due' => 'overdue']))
            ->assertInertia(fn ($page) => $page->where('filters.due', 'overdue'));
        expect(gqListIds($overdueList))->toBe([$overdue->id])->not->toContain($appealedOverdue->id);

        $soonList = $this->get(route('panel.financial.tiss.glosas.index', ['due' => 'soon']));
        expect(gqListIds($soonList))->toBe([$soon->id]);
    });
});

describe('histórico (Resolvidas / Todas)', function (): void {
    it('usa o período (data de identificação) e o filtro "Recuperadas" junta revertidas total e parcial', function (): void {
        $reversed = gqGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::Reversed->value]);
        $partial  = gqGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::PartialReversed->value]);
        $kept     = gqGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::Maintained->value]);
        $oldDone  = gqGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::Reversed->value, 'identified_at' => now()->subMonths(4)->toDateString()]);
        $open     = gqGlosa($this->entity, $this->operator);

        $resolved = $this->get(route('panel.financial.tiss.glosas.index', ['tab' => 'resolved']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.tab', 'resolved')->where('tabCounts.resolved', 3)->where('tabCounts.all', 4));

        expect(gqListIds($resolved))->toEqualCanonicalizing([$reversed->id, $partial->id, $kept->id]);

        $recovered = $this->get(route('panel.financial.tiss.glosas.index', ['tab' => 'resolved', 'status' => 'recovered']));
        expect(gqListIds($recovered))->toEqualCanonicalizing([$reversed->id, $partial->id]);

        $all = $this->get(route('panel.financial.tiss.glosas.index', ['tab' => 'all']));
        expect(gqListIds($all))->toContain($open->id)->not->toContain($oldDone->id);

        $wide = $this->get(route('panel.financial.tiss.glosas.index', [
            'tab'  => 'resolved',
            'from' => now()->subMonths(5)->toDateString(),
            'to'   => now()->toDateString(),
        ]));
        expect(gqListIds($wide))->toContain($oldDone->id);
    });
});

describe('busca', function (): void {
    it('trata %, _ e \\ como caracteres literais', function (): void {
        $percent    = gqGlosa($this->entity, $this->operator, ['glosa_description' => 'Desconto de 50% no pacote']);
        $noPercent  = gqGlosa($this->entity, $this->operator, ['glosa_description' => 'Desconto de 500 no pacote']);
        $underscore = gqGlosa($this->entity, $this->operator, ['glosa_description' => 'Campo item_a divergente']);
        $noUnder    = gqGlosa($this->entity, $this->operator, ['glosa_description' => 'Campo itemXa divergente']);
        $backslash  = gqGlosa($this->entity, $this->operator, ['glosa_description' => 'Caminho C:\\guias invalido']);
        $noBack     = gqGlosa($this->entity, $this->operator, ['glosa_description' => 'Caminho C:guias invalido']);

        expect(gqListIds($this->get(route('panel.financial.tiss.glosas.index', ['search' => '50%']))))
            ->toBe([$percent->id])->not->toContain($noPercent->id);

        expect(gqListIds($this->get(route('panel.financial.tiss.glosas.index', ['search' => 'm_a']))))
            ->toBe([$underscore->id])->not->toContain($noUnder->id);

        expect(gqListIds($this->get(route('panel.financial.tiss.glosas.index', ['search' => 'C:\\g']))))
            ->toBe([$backslash->id])->not->toContain($noBack->id);
    });

    it('acha por nº da guia, código GUI, código/motivo (sem acento) e nº do recurso — só na clínica da sessão', function (): void {
        $service = app(BillingService::class);
        $claim   = $service->createIndividual(['schedule_id' => createBillableSchedule($this->entity)->id, 'unit_price' => 150, 'clinical_indication' => 'H40.1']);
        $service->markClaimDenied($claim, ['glosa_amount' => 150, 'glosa_code' => '1801']);
        $claim->refresh();
        $fromBilling = TissGlosa::query()->where('guide_id', $claim->tiss_guide_id)->firstOrFail();
        $withAppeal  = gqGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::Appealed->value, 'glosa_description' => 'Guia sem assinatura']);
        $appeal      = gqAppeal($withAppeal, ['appeal_number' => 'REC-209901-00042']);
        $accented    = gqGlosa($this->entity, $this->operator, ['glosa_description' => 'Procedimento NÃO AUTORIZADO pela operadora']);

        $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $theirs      = gqGlosa($otherEntity, $this->operator, ['glosa_description' => 'Guia sem assinatura']);

        $search = fn (string $term) => gqListIds($this->get(route('panel.financial.tiss.glosas.index', ['search' => $term, 'tab' => 'all'])));

        expect($search($claim->code))->toBe([$fromBilling->id])
            ->and($search($claim->tissGuide->guide_number_provider))->toBe([$fromBilling->id])
            ->and($search('1801'))->toBe([$fromBilling->id])
            ->and($search($appeal->appeal_number))->toBe([$withAppeal->id])
            ->and($search('SEM ASSINATURA'))->toBe([$withAppeal->id])
            ->and($search('sem assinatura'))->not->toContain($theirs->id)
            ->and($search('não autorizado'))->toBe([$accented->id])
            ->and($search('nao autorizado'))->toBe([$accented->id]);
    });
});

describe('filtros inválidos', function (): void {
    it('tab/status/operadora/prazo/busca/detalhe inválidos caem no padrão (sem 500)', function (): void {
        gqGlosa($this->entity, $this->operator);
        $foreignOperator = gqOperator('Sem glosa aqui');

        $this->get(route('panel.financial.tiss.glosas.index', [
            'tab'         => '<script>',
            'status'      => 'reversed', // não existe na aba Pendentes
            'operator_id' => $foreignOperator->id,
            'due'         => 'ontem',
            'search'      => ['x'],
            'detail'      => 'nao-e-uuid',
            'from'        => 'abc',
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.tab', 'pending')
                ->where('filters.status', null)
                ->where('filters.operator_id', null)
                ->where('filters.due', null)
                ->where('filters.search', '')
                ->where('glosaDetail', null)
                ->has('glosas.data', 1));

        $this->get(route('panel.financial.tiss.glosas.index', ['tab' => 'resolved', 'due' => 'overdue', 'operator_id' => 'nao-e-uuid']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.due', null)->where('filters.operator_id', null));
    });
});

describe('detalhe (recarga parcial)', function (): void {
    it('traz guia (paciente só pelo nome), recursos e a linha do tempo da clínica — sem histórico de outra clínica', function (): void {
        $service = app(BillingService::class);
        $claim   = $service->createIndividual(['schedule_id' => createBillableSchedule($this->entity)->id, 'unit_price' => 150, 'clinical_indication' => 'H40.1']);
        $service->markClaimDenied($claim, ['glosa_amount' => 150, 'glosa_code' => '3099']);
        $claim->refresh();
        $glosa = TissGlosa::query()->where('guide_id', $claim->tiss_guide_id)->firstOrFail();

        $this->post(route('panel.financial.tiss.glosas.appeal', $glosa->id), ['reason' => 'Procedimento coberto conforme contrato.'])
            ->assertSessionHasNoErrors();
        $appeal = $glosa->appeals()->firstOrFail();
        $this->post(route('panel.financial.tiss.glosas.appeals.submit', $appeal->id))->assertSessionHasNoErrors();

        // Histórico forjado com o mesmo context_id, mas de outra clínica: não pode aparecer.
        $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
        TissStatusHistory::query()->withoutGlobalScopes()->create([
            'entity_id'      => $otherEntity->id,
            'context_type'   => 'glosa',
            'context_id'     => $glosa->id,
            'current_status' => TissGlosaStatus::Cancelled->value,
            'reason'         => 'NAO-DEVE-APARECER',
            'changed_at'     => now(),
        ]);

        $response = $this->get(route('panel.financial.tiss.glosas.index', ['detail' => $glosa->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('glosaDetail.id', $glosa->id)
                ->where('glosaDetail.guide.claim_code', $claim->code)
                ->where('glosaDetail.guide.provider_number', $claim->tissGuide->guide_number_provider)
                ->where('glosaDetail.appeals.0.appeal_number', $appeal->appeal_number)
                ->where('glosaDetail.appeals.0.reason', 'Procedimento coberto conforme contrato.')
                ->has('glosaDetail.timeline', 2)
                ->where('glosaDetail.timeline.0.context', 'glosa')
                ->where('glosaDetail.timeline.0.current_label', TissGlosaStatus::Appealed->label())
                ->where('glosaDetail.timeline.1.context', 'appeal')
                ->where('glosaDetail.timeline.1.appeal_number', $appeal->appeal_number));

        $detail = $response->viewData('page')['props']['glosaDetail'];

        expect($detail['guide'])->toHaveKeys(['patient_name', 'attendance_date'])
            ->and($detail['guide'])->not->toHaveKeys(['beneficiary_card_number', 'card_number', 'cpf'])
            ->and(collect($detail['timeline'])->pluck('reason')->all())->not->toContain('NAO-DEVE-APARECER');
    });

    it('glosa de outra clínica no detalhe: "missing", sem nenhum dado dela', function (): void {
        $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $theirs      = gqGlosa($otherEntity, $this->operator, ['glosa_description' => 'Motivo sigiloso']);

        $this->get(route('panel.financial.tiss.glosas.index', ['detail' => $theirs->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('glosaDetail', ['missing' => true]));
    });

    it('recarga parcial (only: glosaDetail) devolve só o detalhe, sem refazer lista e KPIs', function (): void {
        $glosa = gqGlosa($this->entity, $this->operator);

        $response = $this->withHeaders(array_merge(inertiaHeaders(), [
            'X-Inertia-Partial-Component' => 'Panel/Financial/Tiss/GlosasIndex',
            'X-Inertia-Partial-Data'      => 'glosaDetail',
        ]))->get(route('panel.financial.tiss.glosas.index', ['detail' => $glosa->id]));

        $response->assertOk();
        $props = $response->json('props');

        expect($props)->toHaveKey('glosaDetail')
            ->and($props['glosaDetail']['id'])->toBe($glosa->id)
            ->and($props)->not->toHaveKeys(['glosas', 'summary', 'tabCounts', 'byOperator']);
    });
});

describe('ações', function (): void {
    it('"Marcar como enviado" mantém 409 quando o recurso já foi enviado', function (): void {
        $glosa  = gqGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::Appealed->value]);
        $appeal = gqAppeal($glosa);
        $sentAt = $appeal->submitted_at->toDateTimeString();

        $this->post(route('panel.financial.tiss.glosas.appeals.submit', $appeal->id))->assertStatus(409);

        expect($appeal->fresh()->status)->toBe(TissAppealStatus::Submitted)
            ->and($appeal->fresh()->submitted_at->toDateTimeString())->toBe($sentAt);
    });

    it('recurso de outra clínica não resolve no binding (404) e não muda nada', function (): void {
        $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $theirs      = gqGlosa($otherEntity, $this->operator, ['status' => TissGlosaStatus::Appealed->value]);
        $appeal      = gqAppeal($theirs, ['status' => TissAppealStatus::Opened->value, 'submitted_at' => null, 'deadline' => null]);

        $this->post(route('panel.financial.tiss.glosas.appeals.submit', $appeal->id))->assertNotFound();

        expect($appeal->fresh()->status)->toBe(TissAppealStatus::Opened);
    });

    it('a guia de faturamento aparece na linha (código GUI) para ligar a glosa ao Faturamento', function (): void {
        $service = app(BillingService::class);
        $claim   = $service->createIndividual(['schedule_id' => createBillableSchedule($this->entity)->id, 'unit_price' => 150, 'clinical_indication' => 'H40.1']);
        $service->markClaimDenied($claim, ['glosa_amount' => 150, 'glosa_code' => '3099']);

        $this->get(route('panel.financial.tiss.glosas.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('glosas.data.0.claim_code', BillingClaim::query()->findOrFail($claim->id)->code)
                ->where('glosas.data.0.guide_number', fn ($number) => filled($number)));
    });
});
