<?php

use App\Domains\AI\Models\AiRun;
use App\Enums\AI\AiRunStatus;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\{PaymentStatus, PlatformExpenseCategory};
use App\Enums\{ClientRule, SaasRule};
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Manager\FinanceController;
use App\Models\Billing\{Invoice, Payment, PlatformExpense};
use App\Models\{Entity, Plan, User};
use App\Models\Subscription;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Queue};
use Illuminate\Support\Str;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Manager\FinanceController — P&L interno do EasyEye + digest/chat por IA.
 *
 * Segue o padrão já usado em AiProvidersControllerTest: invoca o controller
 * DIRETO (sem o stack de middleware HTTP), único precedente de teste de
 * manager neste projeto. Ainda assim exercita 100% do que importa pra
 * segurança: Gate::authorize() real, cálculo real do PlatformFinanceService,
 * criação real de AiRun sem tocar a carteira de créditos.
 */
beforeEach(function () {
    $this->saas = Entity::factory()->create(['is_client' => false, 'active' => true]);

    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);

    $this->owner = User::factory()->create();
    createEntityUser($this->saas, $this->owner, SaasRule::Financial->value, isOwner: true);

    // Financial "puro" (nem admin, nem owner) — é exatamente quem o pedido
    // de produto diz que NÃO deve ver esta área ("exclusiva dos donos/
    // administradores gerais"), mesmo já tendo acesso a saas.financial hoje.
    $this->financialStaff = User::factory()->create();
    createEntityUser($this->saas, $this->financialStaff, SaasRule::Financial->value);

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'price' => 899.90]);
});

function actingAsManager($test, User $user): void
{
    $test->actingAs($user);
    session(['selected_entity_id' => $test->saas->id]);
}

function callFinanceIndex($test)
{
    $request = Request::create('/panel/manager/finance', 'GET');
    $request->headers->set('X-Inertia', 'true');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => auth()->user());
    app()->instance('request', $request);

    return app(FinanceController::class)->index($request);
}

/**
 * @return array<string, mixed>
 */
function financeIndexProps($test): array
{
    $request = Request::create('/panel/manager/finance', 'GET');
    $request->headers->set('X-Inertia', 'true');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => auth()->user());
    app()->instance('request', $request);

    $response = app(FinanceController::class)->index($request);

    return $response->toResponse($request)->getData(true)['props'];
}

describe('Gate SaasOwnerFinancial', function () {
    it('admin do SaaS acessa', function () {
        actingAsManager($this, $this->admin);

        $response = callFinanceIndex($this);

        expect($response)->toBeInstanceOf(Response::class);
    });

    it('dono (is_owner) acessa mesmo sem ser admin', function () {
        actingAsManager($this, $this->owner);

        $response = callFinanceIndex($this);

        expect($response)->toBeInstanceOf(Response::class);
    });

    it('[SEGURANÇA] Financial "puro" (nem admin, nem owner) É BLOQUEADO — mais restrito que saas.financial', function () {
        actingAsManager($this, $this->financialStaff);

        expect(fn () => callFinanceIndex($this))->toThrow(AuthorizationException::class);
    });

    it('[SEGURANÇA] usuário de entity cliente é bloqueado mesmo tentando forçar a entity SaaS na sessão', function () {
        $clinic     = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $clinicUser = User::factory()->create();
        createEntityUser($clinic, $clinicUser, ClientRule::Admin->value);

        $this->actingAs($clinicUser);
        // Mesmo que a sessão aponte pra entity SaaS, o usuário não tem
        // entity_users lá — hasRoleInEntity/isOwnerOfEntity retornam false.
        session(['selected_entity_id' => $this->saas->id]);

        expect(fn () => callFinanceIndex($this))->toThrow(AuthorizationException::class);
    });
});

describe('PlatformFinanceService via index()', function () {
    it('calcula receita, MRR e clínicas pagantes com dado real', function () {
        actingAsManager($this, $this->admin);

        $clinic = Entity::factory()->create(['is_client' => true]);
        // Receita = cobrança automática já paga; sem gateway a assinatura
        // seria cortesia e sem pagamento confirmado não entra no MRR.
        $subscription = Subscription::create([
            'entity_id'       => $clinic->id,
            'plan_id'         => $this->plan->id,
            'billing_mode'    => 'gateway',
            'status'          => SubscriptionStatus::Active->value,
            'starts_at'       => now()->subMonths(2),
            'last_payment_at' => now(),
        ]);

        $invoice = Invoice::create([
            'entity_id'       => $clinic->id,
            'subscription_id' => $subscription->id,
            'plan_id'         => $this->plan->id,
            'reference'       => 'INV-TEST-0001',
            'amount'          => 899.90,
            'status'          => InvoiceStatus::Paid->value,
            'period_start'    => now()->startOfMonth(),
            'period_end'      => now()->endOfMonth(),
        ]);
        Payment::create([
            'entity_id'       => $clinic->id,
            'invoice_id'      => $invoice->id,
            'subscription_id' => $subscription->id,
            'status'          => PaymentStatus::Paid->value,
            'amount'          => 899.90,
            'gateway_fee'     => 25.00,
            'net_amount'      => 874.90,
            'paid_at'         => now(),
        ]);

        $props   = financeIndexProps($this);
        $summary = $props['summary'];

        expect($summary['revenue']['gross'])->toBe(899.90)
            ->and($summary['revenue']['by_plan'][0]['plan_name'])->toBe('Pro')
            ->and($summary['mrr']['amount'])->toBeGreaterThanOrEqual(899.90)
            ->and($summary['paying_clinics'])->toBeGreaterThanOrEqual(1)
            ->and($summary['expenses']['by_category'])->toHaveCount(8) // 6 manuais + IA + gateway
            // JSON não distingue 25 de 25.0 (round-trip via Inertia serializa
            // sem JSON_PRESERVE_ZERO_FRACTION) — compara como número, não tipo.
            ->and((float) collect($summary['expenses']['by_category'])->firstWhere('category', 'gateway_fees')['amount'])->toBe(25.0);
    });
});

describe('CRUD de despesas', function () {
    function callStoreExpense($test, array $data)
    {
        $request = Request::create('/panel/manager/finance/expenses', 'POST', $data);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => auth()->user());

        return app(FinanceController::class)->storeExpense($request);
    }

    it('cria despesa manual e registra auditoria', function () {
        actingAsManager($this, $this->admin);

        $res = callStoreExpense($this, [
            'category'     => PlatformExpenseCategory::Servers->value,
            'description'  => 'AWS — conta de agosto',
            'amount'       => 1234.56,
            'effective_at' => now()->toDateString(),
        ]);

        expect($res->getStatusCode())->toBe(201)
            ->and(PlatformExpense::count())->toBe(1);

        $expense = PlatformExpense::first();
        expect((float) $expense->amount)->toBe(1234.56)
            ->and($expense->created_by)->toBe((string) $this->admin->id);

        expect(DB::table('audit_logs')->where('event', 'platform_expense.created')->exists())->toBeTrue();
    });

    it('[SEGURANÇA] Financial puro não consegue lançar despesa', function () {
        actingAsManager($this, $this->financialStaff);

        expect(fn () => callStoreExpense($this, [
            'category'     => PlatformExpenseCategory::Marketing->value,
            'description'  => 'x',
            'amount'       => 10,
            'effective_at' => now()->toDateString(),
        ]))->toThrow(AuthorizationException::class);

        expect(PlatformExpense::count())->toBe(0);
    });

    it('exclui despesa (soft delete) e registra auditoria', function () {
        actingAsManager($this, $this->admin);
        $expense = PlatformExpense::create([
            'category' => PlatformExpenseCategory::Other->value, 'description' => 'x',
            'amount'   => 10, 'effective_at' => now()->toDateString(),
        ]);

        $request = Request::create("/panel/manager/finance/expenses/{$expense->id}", 'DELETE');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => auth()->user());

        app(FinanceController::class)->destroyExpense($request, $expense);

        expect(PlatformExpense::count())->toBe(0)
            ->and(PlatformExpense::withTrashed()->count())->toBe(1)
            ->and(DB::table('audit_logs')->where('event', 'platform_expense.deleted')->exists())->toBeTrue();
    });
});

describe('digest() e chat() — AiRun sem tocar a carteira de créditos', function () {
    function callDigest($test)
    {
        $request = Request::create('/panel/manager/finance/ai/digest', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => auth()->user());

        return app(FinanceController::class)->digest($request);
    }

    function callChat($test, string $prompt, ?string $conversationId = null)
    {
        $data = ['user_prompt' => $prompt];

        if ($conversationId) {
            $data['conversation_id'] = $conversationId;
        }

        $request = Request::create('/panel/manager/finance/ai/chat', 'POST', $data);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => auth()->user());

        return app(FinanceController::class)->chat($request);
    }

    it('digest() cria AiRun workflow=platform_finance_digest, entity=SaaS, ZERO créditos envolvidos', function () {
        Queue::fake();
        actingAsManager($this, $this->admin);

        $res = callDigest($this);
        expect($res->getStatusCode())->toBe(201);

        $run = AiRun::query()->firstOrFail();
        expect($run->workflow)->toBe('platform_finance_digest')
            ->and((string) $run->entity_id)->toBe((string) $this->saas->id)
            ->and($run->reserved_credits)->toBe(0)
            ->and($run->estimated_credits)->toBe(0)
            ->and($run->input_summary['expects_json'])->toBeTrue()
            ->and($run->input_summary['system_prompt'])->toContain(__('ai.platform_finance_digest_system_prompt'))
            ->and($run->input_summary['system_prompt'])->toContain(__('ai.security_preamble'))
            ->and($run->input_summary['context'])->toHaveKey('resumo_financeiro');

        // Nenhuma entrada de ledger de crédito foi criada pra este run.
        expect(DB::table('ai_credit_ledger_entries')->where('ai_run_id', $run->id)->exists())->toBeFalse();
    });

    it('chat() cria AiRun workflow=platform_finance_chat com conversation_id', function () {
        Queue::fake();
        actingAsManager($this, $this->owner);

        $convId = (string) Str::uuid();
        $res    = callChat($this, 'Por que nosso lucro caiu este mês?', $convId);

        expect($res->getStatusCode())->toBe(201);

        $run = AiRun::query()->firstOrFail();
        expect($run->workflow)->toBe('platform_finance_chat')
            ->and($run->conversation_id)->toBe($convId)
            ->and($run->input_summary['user_prompt'])->toBe('Por que nosso lucro caiu este mês?')
            ->and($run->input_summary['expects_json'])->toBeFalse();
    });

    it('[SEGURANÇA] Financial puro não consegue disparar digest nem chat', function () {
        Queue::fake();
        actingAsManager($this, $this->financialStaff);

        expect(fn () => callDigest($this))->toThrow(AuthorizationException::class);
        expect(fn () => callChat($this, 'teste teste'))->toThrow(AuthorizationException::class);
        expect(AiRun::count())->toBe(0);
    });
});

describe('showAiRun() — polling + auto-finalização', function () {
    function callShowAiRun($test, AiRun $run)
    {
        $request = Request::create("/panel/manager/finance/ai/runs/{$run->id}", 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => auth()->user());

        return app(FinanceController::class)->showAiRun($run);
    }

    it('finaliza pra Approved automaticamente ao detectar WaitingApproval — sem ceremonial de approve() clínico', function () {
        actingAsManager($this, $this->admin);

        $run = AiRun::query()->create([
            'entity_id'    => $this->saas->id,
            'requested_by' => $this->admin->id,
            'workflow'     => 'platform_finance_digest',
            'mode'         => 'validated',
            'risk_level'   => 'medium',
            'status'       => AiRunStatus::WaitingApproval->value,
            'final_output' => '{"resumo":"tudo bem"}',
        ]);

        $res  = callShowAiRun($this, $run);
        $data = $res->getData(true);

        expect($data['status'])->toBe('approved');
        expect($run->fresh()->status)->toBe(AiRunStatus::Approved);
    });

    it('[SEGURANÇA] 404 pra AiRun de workflow que não é platform_finance_*', function () {
        actingAsManager($this, $this->admin);

        $clinicRun = AiRun::query()->create([
            'entity_id' => $this->saas->id, 'requested_by' => $this->admin->id,
            'workflow'  => 'assistant_chat', 'mode' => 'economy', 'risk_level' => 'low',
            'status'    => AiRunStatus::Approved->value, 'final_output' => 'x',
        ]);

        expect(fn () => callShowAiRun($this, $clinicRun))
            ->toThrow(NotFoundHttpException::class);
    });

    it('[SEGURANÇA] Financial puro não consegue nem fazer polling', function () {
        actingAsManager($this, $this->financialStaff);

        $run = AiRun::query()->create([
            'entity_id' => $this->saas->id, 'requested_by' => $this->admin->id,
            'workflow'  => 'platform_finance_digest', 'mode' => 'validated', 'risk_level' => 'medium',
            'status'    => AiRunStatus::WaitingApproval->value, 'final_output' => 'x',
        ]);

        expect(fn () => callShowAiRun($this, $run))->toThrow(AuthorizationException::class);
    });
});

describe('Análise por IA — período da tela e análise salva', function () {
    function financeDigestRun($test, array $periodo, array $overrides = []): AiRun
    {
        // forceCreate: created_at não é fillable — create() ignoraria a data e empataria as análises.
        return AiRun::query()->forceCreate([
            'entity_id'     => $test->saas->id,
            'requested_by'  => $test->admin->id,
            'workflow'      => 'platform_finance_digest',
            'mode'          => 'economy',
            'risk_level'    => 'medium',
            'status'        => AiRunStatus::Approved->value,
            'final_output'  => '{"resumo":"Receita subiu","ganhando":[],"perdendo":[],"oportunidades":[],"acoes_sugeridas":[]}',
            'input_summary' => ['context' => ['periodo' => $periodo]],
            ...$overrides,
        ]);
    }

    it('digest() usa o período enviado pela tela (antes ia sempre "este mês")', function () {
        Queue::fake();
        actingAsManager($this, $this->admin);

        $request = Request::create('/panel/manager/finance/ai/digest', 'POST', ['preset' => '12m']);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => auth()->user());

        app(FinanceController::class)->digest($request);

        expect(AiRun::query()->sole()->input_summary['context']['periodo']['preset'])->toBe('12m');
    });

    it('a última análise do mesmo período vem pronta ao abrir a tela — não paga de novo', function () {
        actingAsManager($this, $this->admin);
        $month = ['de' => now()->startOfMonth()->toDateString(), 'ate' => now()->toDateString(), 'preset' => 'this_month'];

        financeDigestRun($this, $month, ['created_at' => now()->subDays(2), 'final_output' => '{"resumo":"Antiga"}']);
        $latest = financeDigestRun($this, $month, ['final_output' => "```json\n{\"resumo\":\"Mais recente\",\"ganhando\":[]}\n```"]);

        $last = financeIndexProps($this)['ai']['last_digest'];

        expect($last['run_id'])->toBe((string) $latest->id)
            ->and($last['result']['resumo'])->toBe('Mais recente') // tolera a cerca ```json
            ->and($last['from'])->toBe($month['de'])
            ->and($last['to'])->toBe($month['ate'])
            ->and(financeIndexProps($this)['ai']['chat_max_length'])->toBe(4000);
    });

    it('duas análises no mesmo segundo: vale a criada por último (antes a escolha era aleatória)', function () {
        actingAsManager($this, $this->admin);
        $this->freezeSecond();
        $month = ['de' => now()->startOfMonth()->toDateString(), 'ate' => now()->toDateString(), 'preset' => 'this_month'];

        $first = financeDigestRun($this, $month, ['final_output' => '{"resumo":"Primeira"}', 'status' => AiRunStatus::WaitingApproval->value]);
        usleep(2000); // outro milissegundo no UUIDv7; o created_at continua igual
        $second = financeDigestRun($this, $month, ['final_output' => '{"resumo":"Segunda"}']);
        // Como no uso real: a primeira é aprovada depois e a linha muda de lugar na tabela.
        $first->update(['status' => AiRunStatus::Approved->value]);

        expect(AiRun::query()->pluck('created_at')->unique())->toHaveCount(1)
            ->and(financeIndexProps($this)['ai']['last_digest']['run_id'])->toBe((string) $second->id);
    });

    it('não reaproveita análise de outro período, que falhou, de outra entidade ou com JSON inválido', function () {
        actingAsManager($this, $this->admin);
        $month = ['de' => now()->startOfMonth()->toDateString(), 'ate' => now()->toDateString(), 'preset' => 'this_month'];
        $other = Entity::factory()->create(['is_client' => false]);

        financeDigestRun($this, [...$month, 'preset' => '12m']);
        financeDigestRun($this, $month, ['status' => AiRunStatus::Failed->value]);
        financeDigestRun($this, $month, ['entity_id' => $other->id]);
        financeDigestRun($this, $month, ['final_output' => 'resposta sem JSON']);

        expect(financeIndexProps($this)['ai']['last_digest'])->toBeNull();
    });

    it('showAiRun devolve a análise já decodificada e o período que o servidor usou', function () {
        actingAsManager($this, $this->admin);

        $run = financeDigestRun($this, ['de' => '2026-07-01', 'ate' => '2026-09-30', 'preset' => 'custom'], [
            'status'       => AiRunStatus::WaitingApproval->value,
            'final_output' => "Segue a análise:\n```json\n{\"resumo\":\"ok\",\"ganhando\":[]}\n```",
        ]);

        $request = Request::create("/panel/manager/finance/ai/runs/{$run->id}", 'GET');
        $request->setLaravelSession(app('session.store'));
        $data = app(FinanceController::class)->showAiRun($run)->getData(true);

        expect($data['status'])->toBe('approved')
            ->and($data['result'])->toBe(['resumo' => 'ok', 'ganhando' => []])
            ->and($data['period'])->toBe(['from' => '2026-07-01', 'to' => '2026-09-30']);
    });
});
