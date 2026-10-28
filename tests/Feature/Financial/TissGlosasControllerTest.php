<?php

declare(strict_types=1);

use App\Domains\Tiss\Enums\{TissAppealStatus, TissGlosaStatus};
use App\Domains\Tiss\Models\{TissGlosa, TissGlosaAppeal, TissOperator};
use App\Http\Controllers\Financial\TissGlosasController;
use App\Models\Entity;
use Illuminate\Http\Request;
use Illuminate\Support\{Number, Str};
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Tela panel/financial/tiss/glosas — números (KPI "Recuperado"), contrato das
 * props (datas ISO, cor do status), filtros inválidos, validação das ações de
 * recurso e preservação do período após cada ação.
 */
function tgcOperator(): TissOperator
{
    return TissOperator::query()->create([
        'ans_code' => (string) random_int(100000, 999999),
        'name'     => 'Operadora ' . Str::random(4),
        'active'   => true,
    ]);
}

/** @param array<string, mixed> $overrides */
function tgcGlosa(Entity $entity, TissOperator $operator, array $overrides = []): TissGlosa
{
    return TissGlosa::query()->create(array_merge([
        'entity_id'         => $entity->id,
        'operator_id'       => $operator->id,
        'status'            => TissGlosaStatus::Open->value,
        'glosa_code'        => '3099',
        'glosa_description' => 'Procedimento não autorizado',
        'amount'            => 150,
        'identified_at'     => now()->toDateString(),
        'deadline'          => now()->addDays(10)->toDateString(),
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function tgcAppeal(TissGlosa $glosa, array $overrides = []): TissGlosaAppeal
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

beforeEach(function (): void {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->entityUser = actingAsFinancialEntityUser($this->entity);
    $this->operator   = tgcOperator();
});

describe('index', function (): void {
    // Antes o card somava o amount INTEIRO das glosas Reversed/PartialReversed:
    // glosa de R$ 1.000 revertida parcialmente em R$ 200 contava R$ 1.000.
    it('calcula "Recuperado" pela soma de accepted_amount dos recursos aceitos', function (): void {
        $partial = tgcGlosa($this->entity, $this->operator, ['amount' => 1000, 'status' => TissGlosaStatus::PartialReversed->value]);
        tgcAppeal($partial, ['status' => TissAppealStatus::Accepted->value, 'accepted_amount' => 200]);

        $reversed = tgcGlosa($this->entity, $this->operator, ['amount' => 300, 'status' => TissGlosaStatus::Reversed->value]);
        tgcAppeal($reversed, ['status' => TissAppealStatus::Accepted->value, 'accepted_amount' => 300]);

        $maintained = tgcGlosa($this->entity, $this->operator, ['amount' => 500, 'status' => TissGlosaStatus::Maintained->value]);
        tgcAppeal($maintained, ['status' => TissAppealStatus::Rejected->value, 'accepted_amount' => 0]);

        // Fora do período padrão (mês atual): não entra.
        $old = tgcGlosa($this->entity, $this->operator, [
            'amount'        => 700,
            'status'        => TissGlosaStatus::Reversed->value,
            'identified_at' => now()->subMonths(3)->toDateString(),
        ]);
        tgcAppeal($old, ['status' => TissAppealStatus::Accepted->value, 'accepted_amount' => 700]);

        $this->get(route('panel.financial.tiss.glosas.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Financial/Tiss/GlosasIndex')
                ->where('summary.recovered', 500)
                ->where('summary.total', 1800)
                ->where('summary.count', 3));
    });

    it('não conta como recuperado mais do que o valor glosado (dado legado com aceito > glosa)', function (): void {
        $glosa = tgcGlosa($this->entity, $this->operator, ['amount' => 100, 'status' => TissGlosaStatus::Reversed->value]);
        tgcAppeal($glosa, ['status' => TissAppealStatus::Accepted->value, 'accepted_amount' => 250]);

        $this->get(route('panel.financial.tiss.glosas.index'))
            ->assertInertia(fn ($page) => $page->where('summary.recovered', 100));
    });

    it('envia datas em ISO, a cor do status e o prazo de resposta do recurso para o front formatar', function (): void {
        $glosa = tgcGlosa($this->entity, $this->operator, [
            'identified_at' => now()->toDateString(),
            'deadline'      => now()->addDays(3)->toDateString(),
            'status'        => TissGlosaStatus::Appealed->value,
        ]);
        tgcAppeal($glosa, ['deadline' => now()->addDays(60)->toDateString()]);

        $this->get(route('panel.financial.tiss.glosas.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('glosas.data.0.identified_at', now()->toDateString())
                ->where('glosas.data.0.deadline', now()->addDays(3)->toDateString())
                ->where('glosas.data.0.status_color', TissGlosaStatus::Appealed->color())
                ->where('glosas.data.0.appeals.0.deadline', now()->addDays(60)->toDateString())
                ->where('glosas.data.0.appeals.0.status', TissAppealStatus::Submitted->value)
                ->has('summary.appeal_response_days')
                ->has('t.deadline_overdue'));
    });

    it('ignora from/to inválidos (sem erro 500) e devolve o período normalizado em filters', function (): void {
        $this->get(route('panel.financial.tiss.glosas.index', ['from' => 'abc', 'to' => '2026-13-45']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.from', now()->startOfMonth()->toDateString())
                ->where('filters.to', now()->endOfMonth()->toDateString()));
    });

    it('inverte período trocado (from > to) em vez de mostrar lista vazia', function (): void {
        tgcGlosa($this->entity, $this->operator, ['identified_at' => '2026-01-15']);

        $this->get(route('panel.financial.tiss.glosas.index', ['from' => '2026-01-31', 'to' => '2026-01-01']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.from', '2026-01-01')
                ->where('filters.to', '2026-01-31')
                ->where('summary.count', 1));
    });

    it('não mostra glosas de outra clínica', function (): void {
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        tgcGlosa($other, $this->operator, ['amount' => 999]);

        $this->get(route('panel.financial.tiss.glosas.index'))
            ->assertInertia(fn ($page) => $page->where('summary.count', 0)->where('summary.total', 0));
    });
});

describe('abrir recurso', function (): void {
    it('exige justificativa de ao menos 10 caracteres também no servidor', function (): void {
        $glosa = tgcGlosa($this->entity, $this->operator);

        $this->post(route('panel.financial.tiss.glosas.appeal', $glosa->id), ['reason' => 'curto'])
            ->assertSessionHasErrors('reason');

        expect($glosa->appeals()->count())->toBe(0)
            ->and($glosa->fresh()->status)->toBe(TissGlosaStatus::Open);
    });

    it('volta para a mesma URL (com o período filtrado) depois de abrir o recurso', function (): void {
        $glosa   = tgcGlosa($this->entity, $this->operator);
        $listUrl = route('panel.financial.tiss.glosas.index', ['from' => '2026-01-01', 'to' => '2026-01-31']);

        $this->withHeader('Referer', $listUrl)
            ->post(route('panel.financial.tiss.glosas.appeal', $glosa->id), ['reason' => 'Procedimento coberto conforme contrato.'])
            ->assertRedirect($listUrl)
            ->assertSessionHas('success');
    });

    // Duas abas/usuários: o model veio do route binding ainda "Aberta", mas outra
    // requisição já abriu o recurso. A checagem agora relê a glosa com lock.
    it('rechecagem sob lock: não abre um segundo recurso quando a glosa já foi recorrida por outra requisição', function (): void {
        $glosa = tgcGlosa($this->entity, $this->operator);
        $stale = TissGlosa::query()->findOrFail($glosa->id);

        TissGlosa::query()->whereKey($glosa->id)->update(['status' => TissGlosaStatus::Appealed->value]);

        session(panelSession($this->entityUser));
        $request = Request::create('/', 'POST', ['reason' => 'Procedimento coberto conforme contrato.']);

        try {
            app(TissGlosasController::class)->appeal($request, $stale);
            $this->fail('Deveria ter abortado com 409.');
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(409);
        }

        expect(TissGlosaAppeal::query()->where('glosa_id', $glosa->id)->count())->toBe(0);
    });
});

describe('enviar recurso', function (): void {
    it('volta para a mesma URL (com o período filtrado) depois de marcar como enviado', function (): void {
        $glosa   = tgcGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::Appealed->value]);
        $appeal  = tgcAppeal($glosa, ['status' => TissAppealStatus::Opened->value, 'submitted_at' => null, 'deadline' => null]);
        $listUrl = route('panel.financial.tiss.glosas.index', ['from' => '2026-02-01', 'to' => '2026-02-28']);

        $this->withHeader('Referer', $listUrl)
            ->post(route('panel.financial.tiss.glosas.appeals.submit', $appeal->id))
            ->assertRedirect($listUrl);

        expect($appeal->fresh()->status)->toBe(TissAppealStatus::Submitted);
    });
});

describe('rechecagem sob lock (duas abas)', function (): void {
    it('não reenvia um recurso que outra requisição já marcou como enviado', function (): void {
        $glosa  = tgcGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::Appealed->value]);
        $appeal = tgcAppeal($glosa, ['status' => TissAppealStatus::Opened->value, 'submitted_at' => null, 'deadline' => null]);
        $stale  = TissGlosaAppeal::query()->findOrFail($appeal->id);

        $firstDeadline = now()->addDays(60)->toDateString();
        TissGlosaAppeal::query()->whereKey($appeal->id)->update([
            'status'       => TissAppealStatus::Submitted->value,
            'submitted_at' => now()->subDay(),
            'deadline'     => $firstDeadline,
        ]);

        session(panelSession($this->entityUser));

        try {
            app(TissGlosasController::class)->submitAppeal(Request::create('/', 'POST'), $stale);
            $this->fail('Deveria ter abortado com 409.');
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(409);
        }

        expect($appeal->fresh()->submitted_at->toDateString())->toBe(now()->subDay()->toDateString());
    });

    it('não registra uma segunda decisão quando outra requisição já resolveu o recurso', function (): void {
        $glosa  = tgcGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::Appealed->value]);
        $appeal = tgcAppeal($glosa);
        $stale  = TissGlosaAppeal::query()->findOrFail($appeal->id);

        TissGlosaAppeal::query()->whereKey($appeal->id)->update([
            'status'          => TissAppealStatus::Accepted->value,
            'accepted_amount' => 150,
        ]);
        TissGlosa::query()->whereKey($glosa->id)->update(['status' => TissGlosaStatus::Reversed->value]);

        session(panelSession($this->entityUser));

        try {
            app(TissGlosasController::class)->resolveAppeal(Request::create('/', 'POST', ['decision' => 'rejected']), $stale);
            $this->fail('Deveria ter abortado com 409.');
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(409);
        }

        expect($appeal->fresh()->status)->toBe(TissAppealStatus::Accepted)
            ->and($glosa->fresh()->status)->toBe(TissGlosaStatus::Reversed);
    });
});

describe('decisão do recurso', function (): void {
    it('explica o teto com o valor glosado formatado na mensagem de erro', function (): void {
        $glosa  = tgcGlosa($this->entity, $this->operator, ['amount' => 1234.5, 'status' => TissGlosaStatus::Appealed->value]);
        $appeal = tgcAppeal($glosa);

        $this->post(route('panel.financial.tiss.glosas.appeals.resolve', $appeal->id), ['decision' => 'accepted', 'accepted_amount' => 2000])
            ->assertSessionHasErrors(['accepted_amount' => __('financial_glosas.accepted_amount_max', [
                'max' => Number::currency(1234.5, 'BRL', app()->getLocale()),
            ])]);
    });

    it('"Aceito" sem valor aceito é recusado (antes gravava recurso Aceito com glosa Mantida)', function (): void {
        $glosa  = tgcGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::Appealed->value]);
        $appeal = tgcAppeal($glosa);

        $this->post(route('panel.financial.tiss.glosas.appeals.resolve', $appeal->id), ['decision' => 'accepted'])
            ->assertSessionHasErrors('accepted_amount');

        expect($appeal->fresh()->status)->toBe(TissAppealStatus::Submitted)
            ->and($glosa->fresh()->status)->toBe(TissGlosaStatus::Appealed);
    });

    it('"Aceito" com valor zero é recusado', function (): void {
        $glosa  = tgcGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::Appealed->value]);
        $appeal = tgcAppeal($glosa);

        $this->post(route('panel.financial.tiss.glosas.appeals.resolve', $appeal->id), ['decision' => 'accepted', 'accepted_amount' => 0])
            ->assertSessionHasErrors('accepted_amount');

        expect($appeal->fresh()->status)->toBe(TissAppealStatus::Submitted);
    });

    it('valor aceito não pode passar do valor glosado', function (): void {
        $glosa  = tgcGlosa($this->entity, $this->operator, ['amount' => 150, 'status' => TissGlosaStatus::Appealed->value]);
        $appeal = tgcAppeal($glosa);

        $this->post(route('panel.financial.tiss.glosas.appeals.resolve', $appeal->id), ['decision' => 'accepted', 'accepted_amount' => 150.01])
            ->assertSessionHasErrors('accepted_amount');

        expect($appeal->fresh()->status)->toBe(TissAppealStatus::Submitted);
    });

    // A coluna é numeric(14,2): 0.004 gravaria 0,00 (Aceito sem valor + glosa
    // "Revertida parcialmente") e 199.995 gravaria 200,00 (recuperado total) com a
    // glosa "Revertida parcialmente". Antes os dois passavam pela validação.
    it('valor aceito com mais de 2 casas decimais é recusado com mensagem traduzida', function (string $amount): void {
        $glosa  = tgcGlosa($this->entity, $this->operator, ['amount' => 200, 'status' => TissGlosaStatus::Appealed->value]);
        $appeal = tgcAppeal($glosa);

        $this->post(route('panel.financial.tiss.glosas.appeals.resolve', $appeal->id), ['decision' => 'accepted', 'accepted_amount' => $amount])
            ->assertSessionHasErrors(['accepted_amount' => __('financial_glosas.accepted_amount_decimals')]);

        expect($appeal->fresh()->status)->toBe(TissAppealStatus::Submitted)
            ->and((float) $appeal->fresh()->accepted_amount)->toBe(0.0)
            ->and($glosa->fresh()->status)->toBe(TissGlosaStatus::Appealed);
    })->with([
        'arredonda para zero'   => ['0.004'],
        'arredonda para o teto' => ['199.995'],
    ]);

    it('aceita valor com centavos (2 casas) e marca a glosa como revertida parcialmente', function (): void {
        $glosa  = tgcGlosa($this->entity, $this->operator, ['amount' => 200, 'status' => TissGlosaStatus::Appealed->value]);
        $appeal = tgcAppeal($glosa);

        $this->post(route('panel.financial.tiss.glosas.appeals.resolve', $appeal->id), ['decision' => 'accepted', 'accepted_amount' => '199.99'])
            ->assertSessionHasNoErrors();

        expect((float) $appeal->fresh()->accepted_amount)->toBe(199.99)
            ->and($glosa->fresh()->status)->toBe(TissGlosaStatus::PartialReversed);
    });

    it('aceita exatamente o valor glosado (limite inclusivo) e reverte a glosa', function (): void {
        $glosa  = tgcGlosa($this->entity, $this->operator, ['amount' => 150, 'status' => TissGlosaStatus::Appealed->value]);
        $appeal = tgcAppeal($glosa);

        $this->post(route('panel.financial.tiss.glosas.appeals.resolve', $appeal->id), ['decision' => 'accepted', 'accepted_amount' => 150])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect($appeal->fresh()->status)->toBe(TissAppealStatus::Accepted)
            ->and($glosa->fresh()->status)->toBe(TissGlosaStatus::Reversed);
    });

    it('"Rejeitado" ignora um valor aceito que sobrou do formulário (grava 0)', function (): void {
        $glosa  = tgcGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::Appealed->value]);
        $appeal = tgcAppeal($glosa);

        $this->post(route('panel.financial.tiss.glosas.appeals.resolve', $appeal->id), ['decision' => 'rejected', 'accepted_amount' => 90])
            ->assertSessionHasNoErrors();

        $appeal->refresh();

        expect($appeal->status)->toBe(TissAppealStatus::Rejected)
            ->and((float) $appeal->accepted_amount)->toBe(0.0)
            ->and($glosa->fresh()->status)->toBe(TissGlosaStatus::Maintained);
    });

    it('volta para a mesma URL (com o período filtrado) depois de registrar a decisão', function (): void {
        $glosa   = tgcGlosa($this->entity, $this->operator, ['status' => TissGlosaStatus::Appealed->value]);
        $appeal  = tgcAppeal($glosa);
        $listUrl = route('panel.financial.tiss.glosas.index', ['from' => '2026-03-01', 'to' => '2026-03-31']);

        $this->withHeader('Referer', $listUrl)
            ->post(route('panel.financial.tiss.glosas.appeals.resolve', $appeal->id), ['decision' => 'rejected'])
            ->assertRedirect($listUrl);
    });
});
