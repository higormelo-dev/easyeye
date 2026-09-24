<?php

use App\Domains\Tiss\Models\TissVersion;
use App\Enums\AI\{AiRiskLevel, AiRunMode};
use App\Enums\{ClientRule, FeatureKey, ScheduleSituation, SubscriptionStatus};
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\{Covenant, Doctor, Entity, EntityIntegrator, EntityUser, EntityUserIntegrator, Patient, PatientAccount, People, Plan, PlanFeature, Schedule, Subscription, User};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Monta o contexto completo de um integrador para testes:
 * entity → subscription (plano com features customizáveis) → integratorUser → integrator → token.
 *
 * Retorna: entity, integratorUser, integrator, token (plainText), headers
 */
function setupIntegrator(array $featureOverrides = []): array
{
    $features = array_merge([
        FeatureKey::HasApiIntegrator->value => '1',
    ], $featureOverrides);

    $plan = Plan::factory()->create();

    foreach ($features as $key => $value) {
        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature' => $key,
            'value'   => $value,
        ]);
    }

    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    Subscription::create([
        'entity_id' => $entity->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $integratorUser = EntityUserIntegrator::factory()->create([
        'entity_id' => $entity->id,
        'active'    => true,
    ]);

    $integrator = EntityIntegrator::factory()->create([
        'entity_user_integrator_id' => $integratorUser->id,
        'active'                    => true,
    ]);

    $token = $integratorUser->createToken(
        'integrator-token',
        ['integrator_id:' . $integrator->id],
        Carbon::now()->addDays(7),
    );

    return [
        'entity'         => $entity,
        'integratorUser' => $integratorUser,
        'integrator'     => $integrator,
        'token'          => $token->plainTextToken,
        'headers'        => ['Authorization' => 'Bearer ' . $token->plainTextToken],
    ];
}

/**
 * Cria um registro EntityUser (membership) para uso nos testes de ACL.
 * O código é gerado automaticamente pelo booted() do model.
 */
function createEntityUser(
    Entity $entity,
    User $user,
    string $rule,
    bool $active = true,
    bool $isOwner = false,
): EntityUser {
    return EntityUser::create([
        'entity_id' => $entity->id,
        'user_id'   => $user->id,
        'rule'      => $rule,
        'active'    => $active,
        'is_owner'  => $isOwner,
        'joined_at' => now(),
    ]);
}

/**
 * Cria um Doctor vinculado a uma Entity para uso em testes de agendamento.
 * Retorna o Doctor criado.
 */
function createDoctorForEntity(Entity $entity): Doctor
{
    $user   = User::factory()->create();
    $people = People::factory()->create();
    $eu     = createEntityUser($entity, $user, ClientRule::Doctor->value);

    return Doctor::create([
        'entity_user_id' => $eu->id,
        'person_id'      => $people->id,
        'active'         => true,
    ]);
}

/**
 * Cria um Schedule vinculado a uma Entity para uso em testes.
 * Retorna ['schedule' => Schedule, 'doctor' => Doctor].
 */
function createScheduleForEntity(Entity $entity, array $attrs = []): array
{
    $doctor   = createDoctorForEntity($entity);
    $schedule = Schedule::create(array_merge([
        'entity_id' => $entity->id,
        'doctor_id' => $doctor->id,
        'full_name' => 'Paciente Teste',
        'date_time' => now(),
        'situation' => ScheduleSituation::Scheduled->value,
        'active'    => true,
    ], $attrs));

    return compact('schedule', 'doctor');
}

/**
 * Sessão padrão de um EntityUser navegando o painel da clínica.
 * Inclui as chaves que o middleware entity.selected espera para resolver o tenant.
 */
function panelSession($entityUser): array
{
    return [
        'selected_entity_id'        => $entityUser->entity_id,
        'selected_entity_user_id'   => $entityUser->id,
        'selected_entity_user_rule' => $entityUser->rule,
        'selected_entity_is_client' => true,
    ];
}

/**
 * Provisiona plano com a feature `has_inventory_module` habilitada +
 * assinatura ativa pra uma entity — usado por qualquer teste que precise de
 * uma clínica com acesso ao módulo de Estoque (Products, IolLenses,
 * ProductCategories, StockCounts, StockReports, ...), já que essas rotas
 * vivem atrás do gate `feature:has_inventory_module` (ver routes/web.php).
 * Compartilhado aqui (não duplicado por arquivo de teste) porque Pest carrega
 * todos os arquivos de tests/Feature no mesmo processo — duas funções
 * globais com o MESMO nome em arquivos diferentes quebram a suite inteira
 * com "Cannot redeclare function" assim que os dois são carregados juntos.
 */
function giveInventoryModuleAccess(Entity $entity): void
{
    $plan = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($plan)->create();
    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);
}

/**
 * Cria uma Entity com assinatura ativa num plano SEM o módulo de estoque —
 * usada pelos testes de feature-gate que esperam 403 (Products, IolLenses,
 * ProductCategories, ProductImports, StockCounts, StockReports, dashboard).
 *
 * skipAutoTrial é obrigatório aqui: Entity::factory()->create() dispara
 * EntityObserver::created(), que inicia um trial automático (geralmente no
 * plano de menor tier, com todas as features "de entrada" habilitadas). Sem
 * suprimir esse trial, a entity fica com DUAS subscriptions "accessible"
 * simultâneas — o trial automático e a assinatura sem o módulo criada aqui —
 * e FeatureGateService::getSubscription() faz ->first() sem ORDER BY, então
 * o teste vira nao-determinístico (às vezes pega o trial, que libera o
 * módulo). Em produção esse cenário não existe: SubscriptionService::activate()
 * sempre cancela a assinatura corrente antes de criar outra.
 */
function entityWithoutInventoryModule(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    $plan = Plan::factory()->create(['active' => true]);
    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    return $entity;
}

/**
 * Headers de uma request Inertia. Inclui X-Inertia-Version computado pelo middleware
 * para evitar 409 (version mismatch) em testes que disparam GET com X-Inertia.
 */
function inertiaHeaders(): array
{
    $middleware = app(HandleInertiaRequests::class);
    $version    = $middleware->version(request()) ?? '';

    return [
        'X-Inertia'         => 'true',
        'X-Inertia-Version' => $version,
        'X-Requested-With'  => 'XMLHttpRequest',
    ];
}

/**
 * Payload mínimo válido para POST /panel/ai/runs (cria uma execução de IA).
 */
function baseRunPayload(): array
{
    return [
        'workflow'          => 'report_drafting',
        'mode'              => AiRunMode::Validated->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'user_prompt'       => 'Gerar rascunho clínico com linguagem de apoio para revisão médica.',
        'system_prompt'     => 'Você é um assistente de apoio clínico.',
        'context'           => ['specialty' => 'ophthalmology'],
        'attachments'       => [],
        'expects_json'      => false,
        'max_output_tokens' => 700,
    ];
}

/**
 * Autentica um PatientAccount via login HTTP real (POST na rota de login do
 * guard "patient"), em vez de TestCase::actingAs($account, 'patient').
 *
 * actingAs($user, $guard) chama internamente Auth::shouldUse($guard), que
 * troca o "default guard" GLOBAL da aplicação (AuthManager) para 'patient'
 * pelo resto do teste. Isso faz Request::user() SEM guard explícito — usado
 * por HandleInertiaRequests::authProps(), que espera um App\Models\User do
 * guard "web" — resolver o PatientAccount por engano e quebrar
 * (Call to undefined method PatientAccount::entityUsers()). Em produção isso
 * nunca acontece (nada no código do Portal do Paciente chama
 * Auth::shouldUse()) — é um artefato específico do test helper. Login real
 * via HTTP evita o artefato e também exercita o fluxo de verdade.
 */
function loginAsPatient(PatientAccount $account, string $password = 'password'): void
{
    test()->post(route('patient-portal.login.store'), [
        'email'    => $account->email,
        'password' => $password,
    ])->assertRedirect(route('patient-portal.dashboard'));
}

/**
 * Garante uma versão TISS ativa (código 202603 / layout 04.03.00) para os
 * testes de faturamento — sem isso ResolveTissVersionService lança
 * RuntimeException, já que RefreshDatabase não roda o TissReferenceSeeder.
 */
function seedActiveTissVersion(): void
{
    TissVersion::query()->firstOrCreate(
        ['code' => '202603'],
        ['layout_version' => '04.03.00', 'effective_from' => '2026-03-01', 'active' => true],
    );
}

/**
 * Agendamento atendido, com convênio real (ans_registry preenchido) e
 * paciente com carteirinha — pronto para faturar via BillingService com
 * pré-validação TISS passando (falta só o CID por guia, que cada teste
 * define conforme o cenário).
 */
function createBillableSchedule(Entity $entity, array $overrides = []): Schedule
{
    $doctor   = createDoctorForEntity($entity);
    $covenant = Covenant::factory()->create([
        'entity_id'    => $entity->id,
        'active'       => true,
        'table'        => true,
        'ans_registry' => '326305',
    ]);
    $patient = Patient::factory()->create([
        'entity_id'   => $entity->id,
        'covenant_id' => $covenant->id,
        'card_number' => '1234567890',
    ]);

    return Schedule::create(array_merge([
        'entity_id'   => $entity->id,
        'doctor_id'   => $doctor->id,
        'patient_id'  => $patient->id,
        'covenant_id' => $covenant->id,
        'full_name'   => $patient->person->name ?? 'Paciente Teste',
        'date_time'   => now()->subHour(),
        'situation'   => ScheduleSituation::Attended->value,
        'active'      => true,
    ], $overrides));
}

/**
 * Agendamento atendido de convênio "particular" (sem ans_registry) — usado
 * pelos testes que garantem que faturamento sem operadora TISS real
 * continua funcionando fora do domínio Domains\Tiss.
 */
function createParticularSchedule(Entity $entity): Schedule
{
    $doctor   = createDoctorForEntity($entity);
    $covenant = Covenant::factory()->create([
        'entity_id'    => $entity->id,
        'active'       => true,
        'table'        => false,
        'name'         => 'PARTICULAR',
        'ans_registry' => null,
    ]);
    $patient = Patient::factory()->create([
        'entity_id'   => $entity->id,
        'covenant_id' => $covenant->id,
    ]);

    return Schedule::create([
        'entity_id'   => $entity->id,
        'doctor_id'   => $doctor->id,
        'patient_id'  => $patient->id,
        'covenant_id' => $covenant->id,
        'full_name'   => $patient->person->name ?? 'Paciente Particular',
        'date_time'   => now()->subHour(),
        'situation'   => ScheduleSituation::Attended->value,
        'active'      => true,
    ]);
}

/**
 * Sessão de painel + login real + versão TISS ativa — ponto de entrada padrão
 * para testes HTTP do módulo de faturamento (BillingController/TissGlosasController).
 */
function actingAsFinancialEntityUser(Entity $entity): EntityUser
{
    seedActiveTissVersion();

    $user       = User::factory()->create();
    $entityUser = createEntityUser($entity, $user, ClientRule::Admin->value, isOwner: true);

    test()->withSession(panelSession($entityUser))->actingAs($user);

    return $entityUser;
}
