<?php

use App\Broadcasting\ManagerMedicinePosologyBatchChannel;
use App\Domains\AI\Contracts\AiProviderInterface;
use App\Domains\AI\Models\{AiModelPrice, AiRun};
use App\Domains\AI\Models\AiProviderTopup;
use App\Domains\AI\Services\{AiProviderManager, AiProviderSettings};
use App\DTOs\AI\{AiProviderResponseData, AiRequestData, AiUsageData};
use App\Enums\AI\AiProvider;
use App\Enums\{ClientRule, ImportStatus, MedicinePosologySource, MedicineSource, SaasRule};
use App\Events\ImportProgressUpdated;
use App\Jobs\ProcessMedicinePosologyBatchJob;
use App\Models\{AuditLog, Entity, Medicine, MedicinePosologyBatch, MedicinePosologyBatchGroup, User};
use App\Services\Medicines\{MedicineCatalogFilters, MedicinePosologyBatchService};
use Illuminate\Support\Facades\{Cache, DB, Event, Http, Queue};
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;

/**
 * Manager → Medicamentos: "Gerar posologia com IA (lote)" segundo os filtros
 * aplicados. Só itens SEM posologia; itens iguais (princípio ativo +
 * concentração + forma) = UMA chamada; grava marcado "IA – revisar"; fila +
 * progresso por WebSocket; um lote por vez; nunca sobrescreve.
 */
class BatchPosologyFakeProvider implements AiProviderInterface
{
    /** @var list<AiRequestData> */
    public array $requests = [];

    /** @param Closure(AiRequestData, int): (string|Throwable) $reply */
    public function __construct(private AiProvider $code, private Closure $reply)
    {
    }

    public function generate(AiRequestData $request): AiProviderResponseData
    {
        $this->requests[] = $request;
        $reply            = ($this->reply)($request, count($this->requests));

        if ($reply instanceof Throwable) {
            throw $reply;
        }

        return new AiProviderResponseData(
            provider: $this->code,
            model: 'fake-model',
            content: $reply,
            usage: new AiUsageData(inputTokens: 100, outputTokens: 50, rawCostUsd: 0.0001),
            latencyMs: 5,
        );
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsJsonMode(): bool
    {
        return true;
    }

    public function provider(): AiProvider
    {
        return $this->code;
    }
}

function batchPosologyJson(array $overrides = []): string
{
    return json_encode(array_merge([
        'dosage'       => '1 gota no olho afetado',
        'frequency'    => 'de 6/6h',
        'duration'     => '7 dias',
        'instructions' => 'Agitar antes de usar.',
        'note'         => '',
    ], $overrides), JSON_UNESCAPED_UNICODE);
}

/**
 * Todos os provedores com a mesma regra de resposta.
 *
 * @return array<string, BatchPosologyFakeProvider>
 */
function fakeBatchAi(?Closure $reply = null): array
{
    $reply ??= fn () => batchPosologyJson();
    $providers = [];

    foreach (AiProvider::cases() as $case) {
        $providers[$case->value] = new BatchPosologyFakeProvider($case, $reply);
    }

    app()->instance(AiProviderManager::class, new AiProviderManager($providers, app(AiProviderSettings::class)));

    return $providers;
}

/** @param array<string, mixed> $attributes */
function batchMedicine(array $attributes): Medicine
{
    return Medicine::withoutGlobalScopes()->create(array_merge([
        'source'        => MedicineSource::Cmed,
        'active'        => true,
        'is_marketed'   => true,
        'is_ophthalmic' => false,
    ], $attributes));
}

function asBatchAdmin(string $rule = 'admin')
{
    return test()->actingAs(test()->admin)->withSession([
        'selected_entity_id'        => test()->saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => $rule,
    ]);
}

/** Lote criado pelo serviço (como o botão faz), sem passar pela fila. */
function startBatch(array $filters = [], ?string $provider = null): MedicinePosologyBatch
{
    $service = app(MedicinePosologyBatchService::class);

    return $service->start(
        app(MedicineCatalogFilters::class)->normalize($filters),
        $provider,
        test()->admin,
        (string) test()->saas->id,
    );
}

function runBatchJob(MedicinePosologyBatch $batch): void
{
    (new ProcessMedicinePosologyBatchJob($batch))->handle(app(MedicinePosologyBatchService::class));
}

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();

    app(AiProviderSettings::class)->setEnabledCodes([AiProvider::OpenAI->value]);
    config([
        'medicines.posology_batch.delay_ms'                 => 0,
        'medicines.posology_batch.job_time_budget_seconds'  => 600,
        'medicines.posology_batch.max_consecutive_failures' => 5,
    ]);

    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create(['name' => 'Admin Lote']);
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);

    // Dois itens IGUAIS (caixa/acento/espaço diferentes) = 1 grupo.
    $this->predA = batchMedicine([
        'name'                => 'PREDOPTIC', 'active_ingredient' => 'Acetato de Prednisolona', 'concentration' => '10 MG/ML',
        'pharmaceutical_form' => 'suspensao_oftalmica', 'is_ophthalmic' => true, 'laboratory' => 'GEOLAB',
    ]);
    $this->predB = batchMedicine([
        'name'                => 'PRED FORT', 'active_ingredient' => 'acetato de  prednisolona', 'concentration' => '10 mg/ml',
        'pharmaceutical_form' => 'suspensao_oftalmica', 'is_ophthalmic' => true, 'laboratory' => 'ALLERGAN',
    ]);
    // Outro grupo (concentração diferente).
    $this->timolol = batchMedicine([
        'name'                => 'TIMOPTOL', 'active_ingredient' => 'maleato de timolol', 'concentration' => '5 MG/ML',
        'pharmaceutical_form' => 'solucao_oftalmica', 'is_ophthalmic' => true,
    ]);
    // Já tem posologia: nunca entra.
    $this->withPosology = batchMedicine([
        'name'                => 'TOBREX', 'active_ingredient' => 'tobramicina', 'concentration' => '3 MG/ML',
        'pharmaceutical_form' => 'solucao_oftalmica', 'is_ophthalmic' => true, 'dosage' => '1 gota',
    ]);
});

describe('prévia', function () {
    it('conta só os sem posologia e agrupa itens iguais (sem caixa, acento e espaço)', function () {
        asBatchAdmin()->getJson(route('manager.medicines.posology-batches.preview'))
            ->assertOk()
            ->assertJsonPath('medicines', 3)
            ->assertJsonPath('groups', 2)
            ->assertJsonPath('batch_groups', 2)
            ->assertJsonPath('batch_medicines', 3)
            ->assertJsonPath('remaining_groups', 0)
            ->assertJsonPath('cap', 200)
            ->assertJsonPath('running', null);
    });

    it('nada é gravado nem enviado à IA na prévia', function () {
        $fakes = fakeBatchAi();

        asBatchAdmin()->getJson(route('manager.medicines.posology-batches.preview'))->assertOk();

        expect($fakes['openai']->requests)->toBe([])
            ->and(MedicinePosologyBatch::query()->count())->toBe(0)
            ->and(AiRun::withoutGlobalScopes()->count())->toBe(0);
    });

    it('respeita cada filtro da tela', function (array $filters, int $medicines, int $groups) {
        // Curado sem posologia, não oftálmico, inativo.
        batchMedicine(['name' => 'ACETAZOLAMIDA', 'concentration' => '250 MG', 'source' => MedicineSource::Manual, 'active' => false]);
        // CMED não comercializado.
        batchMedicine(['name' => 'DIAMOX', 'active_ingredient' => 'acetazolamida', 'concentration' => '250 MG', 'is_marketed' => false]);

        asBatchAdmin()->getJson(route('manager.medicines.posology-batches.preview', $filters))
            ->assertOk()
            ->assertJsonPath('medicines', $medicines)
            ->assertJsonPath('groups', $groups);
    })->with([
        'sem filtro'             => [[], 5, 4],
        'origem curado'          => [['source' => 'manual'], 1, 1],
        'origem CMED'            => [['source' => 'cmed'], 4, 3],
        'status inativo'         => [['status' => 'inactive'], 1, 1],
        'só oftálmicos'          => [['ophthalmic' => 1], 3, 2],
        'não comercializado'     => [['cmed_situation' => 'not_marketed'], 1, 1],
        'busca'                  => [['search' => 'prednisolona'], 2, 1],
        'posologia: sem'         => [['posology' => 'empty'], 5, 4],
        'posologia: IA revisar'  => [['posology' => 'ai_pending'], 0, 0],
        'posologia: revisada'    => [['posology' => 'reviewed'], 0, 0],
        'filtro inválido ignora' => [['source' => 'xpto'], 5, 4],
    ]);

    it('teto de chamadas: processa os primeiros N grupos (ordem do filtro) e informa o restante', function () {
        config(['medicines.posology_batch.max_groups' => 1]);

        asBatchAdmin()->getJson(route('manager.medicines.posology-batches.preview'))
            ->assertOk()
            ->assertJsonPath('groups', 2)
            ->assertJsonPath('batch_groups', 1)
            // Ordem por nome: PRED FORT (grupo da prednisolona) vem primeiro.
            ->assertJsonPath('batch_medicines', 2)
            ->assertJsonPath('remaining_groups', 1)
            ->assertJsonPath('cap', 1);
    });

    it('custo estimado pelo preço do modelo da IA (US$ e R$); sem preço = indisponível', function () {
        $model = app(AiProviderSettings::class)->model('openai');
        AiModelPrice::query()->where('provider', 'openai')->delete();

        asBatchAdmin()->getJson(route('manager.medicines.posology-batches.preview'))
            ->assertOk()
            ->assertJsonPath('estimates.openai.usd', null);

        AiModelPrice::query()->create([
            'provider'       => 'openai', 'model' => $model, 'input_usd_per_million' => 1.0, 'output_usd_per_million' => 4.0,
            'effective_from' => now()->subDay(), 'active' => true,
        ]);

        $json = asBatchAdmin()->getJson(route('manager.medicines.posology-batches.preview'))->assertOk()->json();

        expect($json['estimates']['openai']['usd'])->toBeFloat()->toBeGreaterThan(0)
            ->and($json['estimates']['openai']['brl'])->toEqualWithDelta($json['estimates']['openai']['usd'] * $json['usd_brl']['rate'], 0.0001);
    });

    it('com lote rodando, a prévia devolve o progresso dele', function () {
        $batch = startBatch();

        asBatchAdmin()->getJson(route('manager.medicines.posology-batches.preview'))
            ->assertOk()
            ->assertJsonPath('running.id', $batch->id)
            ->assertJsonPath('running.channel', 'manager.medicines.posology-batches.' . $batch->id);
    });
});

describe('início', function () {
    it('cria o lote com o plano confirmado, coloca na fila e registra na auditoria', function () {
        Queue::fake();

        asBatchAdmin()->post(route('manager.medicines.posology-batches.store'), ['ophthalmic' => 1, 'search' => 'pred'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $batch = MedicinePosologyBatch::query()->sole();
        expect($batch->status)->toBe(ImportStatus::Pending)
            ->and($batch->provider)->toBe('openai')
            ->and($batch->total_groups)->toBe(1)
            ->and($batch->total_medicines)->toBe(2)
            ->and($batch->filters['search'])->toBe('pred')
            ->and($batch->filters['ophthalmic'])->toBeTrue()
            ->and((string) $batch->user_id)->toBe((string) $this->admin->id)
            ->and($batch->groups()->sole()->medicine_ids)->toEqualCanonicalizing([$this->predA->id, $this->predB->id]);

        Queue::assertPushed(ProcessMedicinePosologyBatchJob::class, fn ($job) => $job->batch->is($batch));

        $audit = DB::table('audit_logs')->where('event', 'manager.medicine.posology_batch.start')->sole();
        expect((string) $audit->user_id)->toBe((string) $this->admin->id)
            ->and((string) $audit->auditable_id)->toBe((string) $batch->id);
    });

    it('um lote por vez: com outro rodando, recusa', function () {
        Queue::fake();
        startBatch();

        asBatchAdmin()->post(route('manager.medicines.posology-batches.store'))
            ->assertSessionHasErrors(['batch' => __('manager_medicines.batch_in_progress')]);

        expect(MedicinePosologyBatch::query()->count())->toBe(1);
        Queue::assertNothingPushed();
    });

    it('filtro sem itens sem posologia: recusa (nada a fazer)', function () {
        Queue::fake();

        asBatchAdmin()->post(route('manager.medicines.posology-batches.store'), ['search' => 'tobramicina'])
            ->assertSessionHasErrors(['batch' => __('manager_medicines.batch_nothing_to_do')]);

        expect(MedicinePosologyBatch::query()->count())->toBe(0);
    });

    it('várias IAs: exige a escolha; IA não configurada é recusada; a escolhida fica no lote', function () {
        Queue::fake();
        app(AiProviderSettings::class)->setEnabledCodes(['openai', 'gemini']);

        asBatchAdmin()->post(route('manager.medicines.posology-batches.store'))
            ->assertSessionHasErrors(['provider' => __('manager_medicines.ai_choose_provider')]);
        asBatchAdmin()->post(route('manager.medicines.posology-batches.store'), ['provider' => 'anthropic'])
            ->assertSessionHasErrors(['provider' => __('manager_medicines.ai_provider_invalid')]);
        asBatchAdmin()->post(route('manager.medicines.posology-batches.store'), ['provider' => 'xpto'])
            ->assertSessionHasErrors('provider');

        asBatchAdmin()->post(route('manager.medicines.posology-batches.store'), ['provider' => 'gemini'])
            ->assertSessionHasNoErrors();

        expect(MedicinePosologyBatch::query()->sole()->provider)->toBe('gemini');
    });

    it('sem IA configurada: recusa', function () {
        config(['ai.provider_runtime' => 'real', 'services.openai.api_key' => null, 'services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

        asBatchAdmin()->post(route('manager.medicines.posology-batches.store'))
            ->assertSessionHasErrors(['provider' => __('manager_medicines.ai_unavailable')]);
    });
});

describe('execução (job)', function () {
    it('UMA chamada por grupo, aplicada a todos do grupo, marcada "IA – revisar"; run de plataforma com custo real', function () {
        $fakes = fakeBatchAi(fn (AiRequestData $r) => str_contains(json_encode($r->context), 'timolol')
            ? batchPosologyJson(['dosage' => '1 gota', 'frequency' => 'de 12/12h', 'duration' => 'uso contínuo', 'instructions' => ''])
            : batchPosologyJson());

        runBatchJob(startBatch());

        expect($fakes['openai']->requests)->toHaveCount(2);

        foreach ([$this->predA, $this->predB] as $medicine) {
            $fresh = $medicine->fresh();
            expect($fresh->dosage)->toBe('1 gota no olho afetado')
                ->and($fresh->frequency)->toBe('de 6/6h')
                ->and($fresh->posology_source)->toBe(MedicinePosologySource::Ai)
                ->and($fresh->posology_ai_generated_at)->not->toBeNull()
                ->and($fresh->posology_reviewed_at)->toBeNull()
                ->and($fresh->posologyPendingReview())->toBeTrue();
        }

        $timolol = $this->timolol->fresh();
        expect($timolol->frequency)->toBe('de 12/12h')
            ->and($timolol->instructions)->toBeNull();

        // Quem já tinha posologia: intocado.
        expect($this->withPosology->fresh()->dosage)->toBe('1 gota')
            ->and($this->withPosology->fresh()->posology_source)->toBe(MedicinePosologySource::Manual);

        $batch = MedicinePosologyBatch::query()->sole();
        expect($batch->status)->toBe(ImportStatus::Done)
            ->and($batch->processed_groups)->toBe(2)
            ->and($batch->updated_count)->toBe(3)
            ->and($batch->failed_groups)->toBe(0)
            ->and($batch->ai_calls)->toBe(2)
            ->and($batch->cost_usd)->toEqualWithDelta(0.0002, 0.0000001)
            ->and($batch->finished_at)->not->toBeNull();

        $runs = AiRun::withoutGlobalScopes()->get();
        expect($runs)->toHaveCount(2)
            ->and($runs->every(fn (AiRun $run) => $run->workflow === 'medicine_posology'
                && (string) $run->entity_id === (string) $this->saas->id
                && (int) $run->consumed_credits === 0
                && $run->input_summary['metadata']['batch_id'] === $batch->id
                && $run->input_summary['metadata']['source'] === 'manager_medicines_batch'
                && $run->input_summary['metadata']['patient_data'] === false))->toBeTrue();

        // Prompt só com catálogo (nunca dado de paciente), do servidor.
        expect($fakes['openai']->requests[0]->systemPrompt)->toContain('POSOLOGIA SUGERIDA PADRÃO')
            ->and(array_keys($fakes['openai']->requests[0]->context['medicamento']))
            ->each->toBeIn(['nome', 'principio_ativo', 'concentracao', 'forma_farmaceutica', 'apresentacao', 'classe_terapeutica', 'uso_oftalmico']);
    });

    it('auditoria: alteração dos itens registrada em nome de quem disparou, com origem IA', function () {
        fakeBatchAi();

        runBatchJob(startBatch());

        $log = AuditLog::query()->where('auditable_id', $this->timolol->id)->where('event', 'updated')->latest('created_at')->first();
        expect($log)->not->toBeNull()
            ->and((string) $log->user_id)->toBe((string) $this->admin->id)
            ->and($log->new_values['posology_source'])->toBe('ai')
            ->and((string) $this->timolol->fresh()->updated_by)->toBe((string) $this->admin->id);
    });

    it('[SEGURANÇA] resposta da IA vira texto plano no tamanho das colunas', function () {
        fakeBatchAi(fn () => batchPosologyJson([
            'dosage'       => '<script>alert(1)</script>1 gota',
            'frequency'    => str_repeat('a', 400),
            'instructions' => '<img src=x onerror=alert(1)>Agitar.',
        ]));

        runBatchJob(startBatch(['search' => 'timolol']));

        $fresh = $this->timolol->fresh();
        expect($fresh->dosage)->toBe('alert(1)1 gota')
            ->and(mb_strlen($fresh->frequency))->toBe(255)
            ->and($fresh->instructions)->toBe('Agitar.');
    });

    it('nunca sobrescreve: item que ganhou posologia no meio do lote fica com a do admin', function () {
        fakeBatchAi(function (AiRequestData $request) {
            // Admin salva a posologia de um item do grupo enquanto a IA responde.
            Medicine::withoutGlobalScopes()->whereKey(test()->predB->id)->update(['dosage' => '2 gotas', 'posology_source' => 'manual']);

            return batchPosologyJson();
        });

        runBatchJob(startBatch(['search' => 'prednisolona']));

        expect($this->predB->fresh()->dosage)->toBe('2 gotas')
            ->and($this->predB->fresh()->posology_source)->toBe(MedicinePosologySource::Manual)
            ->and($this->predA->fresh()->dosage)->toBe('1 gota no olho afetado');

        $batch = MedicinePosologyBatch::query()->sole();
        expect($batch->updated_count)->toBe(1)
            ->and($batch->skipped_count)->toBe(1)
            ->and($batch->groups()->sole()->skipped_count)->toBe(1);
    });

    it('grupo em que todos ganharam posologia antes da vez dele: nenhuma chamada', function () {
        $fakes = fakeBatchAi();
        $batch = startBatch(['search' => 'timolol']);
        Medicine::withoutGlobalScopes()->whereKey($this->timolol->id)->update(['instructions' => 'Manual.']);

        runBatchJob($batch);

        expect($fakes['openai']->requests)->toBe([])
            ->and($batch->fresh()->status)->toBe(ImportStatus::Done)
            ->and($batch->groups()->sole()->status)->toBe(MedicinePosologyBatchGroup::STATUS_SKIPPED)
            ->and($this->timolol->fresh()->dosage)->toBeNull();
    });

    it('falha de um grupo (erro da IA ou resposta inválida) não para o lote', function () {
        batchMedicine(['name' => 'ZYLET', 'active_ingredient' => 'loteprednol', 'concentration' => '5 MG/ML', 'pharmaceutical_form' => 'suspensao_oftalmica']);

        fakeBatchAi(function (AiRequestData $request) {
            $context = json_encode($request->context);

            return match (true) {
                str_contains($context, 'timolol')     => new RuntimeException('OpenAI request failed [400]: segredo-interno'),
                str_contains($context, 'loteprednol') => 'Não sei.',
                default                               => batchPosologyJson(),
            };
        });

        runBatchJob(startBatch());

        $batch = MedicinePosologyBatch::query()->sole();
        expect($batch->status)->toBe(ImportStatus::Done)
            ->and($batch->processed_groups)->toBe(3)
            ->and($batch->failed_groups)->toBe(2)
            ->and($batch->updated_count)->toBe(2)
            ->and($this->timolol->fresh()->dosage)->toBeNull()
            ->and($this->predA->fresh()->dosage)->not->toBeNull();

        $errors = $batch->groups()->where('status', MedicinePosologyBatchGroup::STATUS_FAILED)->pluck('error')->implode(' ');
        expect($errors)->not->toContain('segredo-interno')
            ->and($errors)->toContain(__('manager_medicines.ai_no_suggestion'));

        // Histórico na tela mostra os grupos com falha.
        asBatchAdmin()->get(route('manager.medicines.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('posologyBatches', 1)
                ->has('posologyBatches.0.failures', 2)
                ->where('posologyBatches.0.user', $this->admin->fresh()->name)
                ->where('posologyBatches.0.failed_groups', 2));
    });

    it('erro transitório (sobrecarga/demora): nova tentativa limitada, com pausa', function () {
        $fakes = fakeBatchAi(fn (AiRequestData $r, int $call) => $call === 1
            ? new RuntimeException('OpenAI request failed [503]: overloaded')
            : batchPosologyJson());

        runBatchJob(startBatch(['search' => 'timolol']));

        $batch = MedicinePosologyBatch::query()->sole();
        expect($fakes['openai']->requests)->toHaveCount(2)
            ->and($batch->ai_calls)->toBe(2)
            ->and($batch->updated_count)->toBe(1)
            ->and($batch->groups()->sole()->attempts)->toBe(2);

        Sleep::assertSleptTimes(1);
    });

    it('erro transitório persistente: desiste após as novas tentativas e segue', function () {
        config(['medicines.posology_batch.retries' => 2]);
        $fakes = fakeBatchAi(fn () => new RuntimeException('cURL error 28: Operation timed out'));

        runBatchJob(startBatch(['search' => 'timolol']));

        expect($fakes['openai']->requests)->toHaveCount(3)
            ->and(MedicinePosologyBatch::query()->sole()->failed_groups)->toBe(1)
            ->and(MedicinePosologyBatch::query()->sole()->status)->toBe(ImportStatus::Done);
    });

    it('falhas seguidas (provedor fora): encerra o lote sem gastar o resto', function () {
        config(['medicines.posology_batch.max_consecutive_failures' => 2]);
        batchMedicine(['name' => 'ZYLET', 'active_ingredient' => 'loteprednol', 'concentration' => '5 MG/ML']);
        $fakes = fakeBatchAi(fn () => new RuntimeException('OpenAI request failed [400]: x'));

        runBatchJob(startBatch());

        $batch = MedicinePosologyBatch::query()->sole();
        expect($fakes['openai']->requests)->toHaveCount(2)
            ->and($batch->status)->toBe(ImportStatus::Failed)
            ->and($batch->error)->toContain(AiProvider::OpenAI->label())
            ->and($batch->groups()->where('status', MedicinePosologyBatchGroup::STATUS_PENDING)->count())->toBe(1);
    });

    it('cancelado antes de começar: o job não chama a IA', function () {
        $fakes = fakeBatchAi();
        $batch = startBatch();

        asBatchAdmin()->post(route('manager.medicines.posology-batches.cancel', $batch->id))->assertSessionHasNoErrors();
        runBatchJob($batch);

        expect($fakes['openai']->requests)->toBe([])
            ->and($batch->fresh()->status)->toBe(ImportStatus::Cancelled)
            ->and($this->predA->fresh()->dosage)->toBeNull();
    });

    it('cancelado no meio: para antes do próximo grupo e não grava a resposta em andamento', function () {
        $fakes = fakeBatchAi(function () {
            MedicinePosologyBatch::query()->update(['status' => ImportStatus::Cancelled->value]);

            return batchPosologyJson();
        });

        runBatchJob(startBatch());

        $batch = MedicinePosologyBatch::query()->sole();
        expect($fakes['openai']->requests)->toHaveCount(1)
            ->and($batch->status)->toBe(ImportStatus::Cancelled)
            ->and($batch->updated_count)->toBe(0)
            ->and($batch->ai_calls)->toBe(1)
            ->and(Medicine::withoutGlobalScopes()->where('posology_source', 'ai')->count())->toBe(0);
    });

    it('orçamento de tempo esgotado: processa e agenda a continuação na fila', function () {
        config(['medicines.posology_batch.job_time_budget_seconds' => 0]);
        fakeBatchAi();
        $batch = startBatch();
        Queue::fake();

        runBatchJob($batch);

        expect($batch->fresh()->processed_groups)->toBe(1)
            ->and($batch->fresh()->status)->toBe(ImportStatus::Processing);
        Queue::assertPushed(ProcessMedicinePosologyBatchJob::class, fn ($job) => $job->batch->is($batch));
    });

    it('entrega duplicada da fila (lote já sendo processado): não processa em paralelo', function () {
        $fakes = fakeBatchAi();
        $batch = startBatch();
        $lock  = Cache::lock('medicine-posology-batch:run:' . $batch->id, 60);
        $lock->get();

        runBatchJob($batch);
        $lock->release();

        expect($fakes['openai']->requests)->toBe([])
            ->and($batch->fresh()->status)->toBe(ImportStatus::Pending);
    });

    it('job morto (falha fora do serviço): lote marcado como falho e libera outro', function () {
        $batch = startBatch();

        (new ProcessMedicinePosologyBatchJob($batch))->failed(new RuntimeException('timeout'));

        expect($batch->fresh()->status)->toBe(ImportStatus::Failed)
            ->and($batch->fresh()->error)->toBe(__('manager_medicines.batch_failed_generic'));
    });

    it('transmite o progresso pelo WebSocket (canal privado do lote), sem endpoint de status', function () {
        Event::fake([ImportProgressUpdated::class]);
        fakeBatchAi();
        $batch = startBatch();

        runBatchJob($batch);

        $channel = 'manager.medicines.posology-batches.' . $batch->id;
        Event::assertDispatched(ImportProgressUpdated::class, fn (ImportProgressUpdated $e) => $e->channel === $channel
            && $e->payload['status'] === 'processing');
        Event::assertDispatched(ImportProgressUpdated::class, fn (ImportProgressUpdated $e) => $e->channel === $channel
            && $e->payload['is_done'] === true
            && $e->payload['progress'] === 100
            && $e->payload['updated_count'] === 3
            && $e->payload['processed_groups'] === 2);

        expect(collect(app('router')->getRoutes()->getRoutesByName())->keys()
            ->filter(fn ($name) => str_contains($name, 'posology-batches') && str_contains($name, 'status'))->all())->toBe([]);
    });
});

describe('cancelamento', function () {
    it('cancela o lote em andamento e audita', function () {
        $batch = startBatch();

        asBatchAdmin()->post(route('manager.medicines.posology-batches.cancel', $batch->id))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($batch->fresh()->status)->toBe(ImportStatus::Cancelled)
            ->and(DB::table('audit_logs')->where('event', 'manager.medicine.posology_batch.cancel')->count())->toBe(1);
    });

    it('lote já terminado: recusa', function () {
        $batch = startBatch();
        $batch->update(['status' => ImportStatus::Done]);

        asBatchAdmin()->post(route('manager.medicines.posology-batches.cancel', $batch->id))
            ->assertSessionHasErrors(['batch' => __('manager_medicines.batch_not_running')]);
    });
});

describe('revisão pelo modal, selo e filtro', function () {
    beforeEach(function () {
        fakeBatchAi();
        runBatchJob(startBatch());
    });

    it('salvar o item pelo modal de edição torna a posologia revisada (manual)', function () {
        asBatchAdmin()->put(route('manager.medicines.update', $this->timolol->id), [
            'dosage' => '1 gota', 'frequency' => 'de 12/12h', 'duration' => '', 'instructions' => '',
        ])->assertSessionHasNoErrors();

        $fresh = $this->timolol->fresh();
        expect($fresh->posology_source)->toBe(MedicinePosologySource::Manual)
            ->and($fresh->posology_reviewed_at)->not->toBeNull()
            ->and((string) $fresh->posology_reviewed_by)->toBe((string) $this->admin->id)
            // Histórico: continua sabendo que a IA gerou.
            ->and($fresh->posology_ai_generated_at)->not->toBeNull()
            ->and($fresh->posologyPendingReview())->toBeFalse();
    });

    it('salvar sem mudar nada também revisa (o admin viu a sugestão)', function () {
        $fresh = $this->predA->fresh();

        asBatchAdmin()->put(route('manager.medicines.update', $fresh->id), [
            'dosage' => $fresh->dosage, 'frequency' => $fresh->frequency, 'duration' => $fresh->duration, 'instructions' => $fresh->instructions,
        ])->assertSessionHasNoErrors();

        expect($this->predA->fresh()->posology_source)->toBe(MedicinePosologySource::Manual);
    });

    it('ativar/desativar (sem posologia na requisição) não mexe na revisão', function () {
        $manual = batchMedicine(['name' => 'COLIRIO X', 'source' => MedicineSource::Manual]);
        Medicine::withoutGlobalScopes()->whereKey($manual->id)->update(['dosage' => 'x', 'posology_source' => 'ai']);

        asBatchAdmin()->put(route('manager.medicines.update', $manual->id), ['active' => false])->assertSessionHasNoErrors();

        expect($manual->fresh()->posology_source)->toBe(MedicinePosologySource::Ai);
    });

    it('linha do catálogo leva o selo e o filtro "Posologia" separa IA não revisada / revisada / sem', function () {
        batchMedicine(['name' => 'SEM NADA', 'active_ingredient' => 'xyz']);

        asBatchAdmin()->get(route('manager.medicines.index', ['posology' => 'ai_pending']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.posology', 'ai_pending')
                ->has('medicines.data', 3)
                ->where('medicines.data.0.posology_pending_review', true)
                ->where('medicines.data.0.posology_source', 'ai')
                ->whereNot('medicines.data.0.posology_ai_generated_at', null));

        asBatchAdmin()->get(route('manager.medicines.index', ['posology' => 'reviewed']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('medicines.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['TOBREX']));

        asBatchAdmin()->get(route('manager.medicines.index', ['posology' => 'empty']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('medicines.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['SEM NADA']));
    });

    it('no receituário a posologia gerada por IA segue aparecendo como sugestão', function () {
        expect(Medicine::withoutGlobalScopes()->whereKey($this->timolol->id)->value('dosage'))->toBe('1 gota no olho afetado');
    });
});

it('cadastro com posologia sem origem explícita (seeder/manual) fica "manual"', function () {
    $m = Medicine::withoutGlobalScopes()->create(['name' => 'CURADO', 'source' => MedicineSource::Manual, 'dosage' => '1 gota', 'active' => true]);

    expect($m->fresh()->posology_source)->toBe(MedicinePosologySource::Manual);
});

it('página entrega lote em andamento, histórico e teto', function () {
    config(['medicines.posology_batch.max_groups' => 50]);
    $batch = startBatch();

    asBatchAdmin()->get(route('manager.medicines.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('runningPosologyBatch.id', $batch->id)
            ->where('runningPosologyBatch.is_done', false)
            ->where('posologyBatchCap', 50)
            ->has('posologyBatches', 1));
});

describe('[SEGURANÇA] permissões', function () {
    it('papel SaaS não-admin não usa prévia, início nem cancelamento', function () {
        Queue::fake();
        $batch   = startBatch();
        $support = User::factory()->create();
        createEntityUser($this->saas, $support, SaasRule::Support->value);
        $as = fn () => test()->actingAs($support)->withSession([
            'selected_entity_id'        => $this->saas->id,
            'selected_entity_is_client' => false,
            'selected_entity_user_rule' => SaasRule::Support->value,
        ]);

        $as()->getJson(route('manager.medicines.posology-batches.preview'))->assertForbidden();
        $as()->post(route('manager.medicines.posology-batches.store'))->assertForbidden();
        $as()->post(route('manager.medicines.posology-batches.cancel', $batch->id))->assertForbidden();

        expect($batch->fresh()->status)->toBe(ImportStatus::Pending);
    });

    it('usuário de clínica não acessa', function () {
        $clinic = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $user   = User::factory()->create();
        createEntityUser($clinic, $user, ClientRule::Admin->value);

        test()->actingAs($user)->withSession([
            'selected_entity_id'        => $clinic->id,
            'selected_entity_is_client' => true,
            'selected_entity_user_rule' => ClientRule::Admin->value,
        ])->getJson(route('manager.medicines.posology-batches.preview'))->assertStatus(403);
    });

    it('canal do progresso: admin SaaS entra; suporte e clínica não', function () {
        $batch = startBatch();
        $join  = function (User $user, Entity $entity) use ($batch) {
            session(['selected_entity_id' => $entity->id, 'selected_entity_is_client' => $entity->isClient()]);

            return app(ManagerMedicinePosologyBatchChannel::class)->join($user, $batch->id);
        };

        $support = User::factory()->create();
        createEntityUser($this->saas, $support, SaasRule::Support->value);
        $clinic     = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $clinicUser = User::factory()->create();
        createEntityUser($clinic, $clinicUser, ClientRule::Admin->value);

        expect($join($this->admin, $this->saas))->toBeTrue()
            ->and($join($support, $this->saas))->toBeFalse()
            ->and($join($clinicUser, $clinic))->toBeFalse()
            ->and(app(ManagerMedicinePosologyBatchChannel::class)->join($this->admin, 'nao-e-uuid'))->toBeFalse();
    });
});

it('progresso ao vivo traz custo e estimativa também em real (cotação do Uso de IA) e o início, para o tempo restante', function () {
    batchMedicine(['name' => 'TIMOLOL 0,5%', 'active_ingredient' => 'timolol', 'concentration' => '5 MG/ML']);
    $batch = startBatch();
    $batch->update(['cost_usd' => 0.01, 'estimated_cost_usd' => 0.02, 'started_at' => now()->subMinutes(3)]);

    // Sem recarga de provedor: cotação de referência, sinalizada.
    $fallback = $batch->fresh()->progressPayload();
    expect($fallback['usd_brl_is_fallback'])->toBeTrue()
        ->and($fallback['cost_brl'])->toBe(round(0.01 * $fallback['usd_brl_rate'], 4))
        ->and($fallback['started_at'])->not->toBeNull();

    // Com recarga registrada: usa a cotação dela.
    AiProviderTopup::create([
        'provider'      => 'openai',
        'amount_usd'    => 10,
        'amount_brl'    => 54,
        'exchange_rate' => 5.4,
        'topped_up_at'  => now(),
        'created_by'    => test()->admin->id,
    ]);

    $payload = $batch->fresh()->progressPayload();
    expect($payload['usd_brl_is_fallback'])->toBeFalse()
        ->and($payload['usd_brl_rate'])->toBe(5.4)
        ->and($payload['cost_brl'])->toBe(0.054)
        ->and($payload['estimated_cost_usd'])->toBe(0.02)
        ->and($payload['estimated_cost_brl'])->toBe(0.108);
});

describe('aprovar a posologia gerada por IA com um clique', function () {
    function pendingAiMedicine(array $attributes = []): Medicine
    {
        $medicine = batchMedicine([
            'name'              => 'ACU FRESH 5 MG/ML',
            'active_ingredient' => 'carmelose sódica',
            'concentration'     => '5 MG/ML',
            'dosage'            => '1-2 gotas no olho afetado',
            'frequency'         => 'várias vezes ao dia',
            ...$attributes,
        ]);
        // Grava como o lote faz: origem IA, sem revisão.
        DB::table('medicines')->where('id', $medicine->id)->update([
            'posology_source'          => MedicinePosologySource::Ai->value,
            'posology_ai_generated_at' => now()->subHour(),
            'posology_reviewed_at'     => null,
            'posology_reviewed_by'     => null,
        ]);

        return $medicine->fresh();
    }

    it('aprova como está: vira revisada (quem/quando), texto intacto, histórico da IA mantido e sai do contador', function () {
        $medicine = pendingAiMedicine();

        asBatchAdmin()->get(route('manager.medicines.index'))
            ->assertInertia(fn ($page) => $page->where('stats.ai_pending', 1));

        asBatchAdmin()->post(route('manager.medicines.posology.approve', $medicine->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = $medicine->fresh();
        expect($fresh->posology_source)->toBe(MedicinePosologySource::Manual)
            ->and($fresh->posology_reviewed_at)->not->toBeNull()
            ->and((string) $fresh->posology_reviewed_by)->toBe((string) $this->admin->id)
            ->and($fresh->posology_ai_generated_at)->not->toBeNull()
            ->and($fresh->dosage)->toBe('1-2 gotas no olho afetado')
            ->and($fresh->frequency)->toBe('várias vezes ao dia');

        asBatchAdmin()->get(route('manager.medicines.index'))
            ->assertInertia(fn ($page) => $page->where('stats.ai_pending', 0));
    });

    it('não está mais pendente (aprovada/editada por outra pessoa): recusa sem mexer', function () {
        $medicine = pendingAiMedicine();
        asBatchAdmin()->post(route('manager.medicines.posology.approve', $medicine->id))->assertSessionHasNoErrors();
        $reviewedAt = $medicine->fresh()->posology_reviewed_at;

        asBatchAdmin()->from(route('manager.medicines.index'))
            ->post(route('manager.medicines.posology.approve', $medicine->id))
            ->assertSessionHasErrors(['posology' => __('manager_medicines.posology_approve_not_pending')]);

        expect($medicine->fresh()->posology_reviewed_at?->toIso8601String())->toBe($reviewedAt?->toIso8601String());
    });

    it('item manual (não gerado por IA) não é "aprovado"', function () {
        $manual = batchMedicine(['name' => 'TIMOLOL', 'dosage' => '1 gota', 'frequency' => '12/12h']);

        asBatchAdmin()->post(route('manager.medicines.posology.approve', $manual->id))
            ->assertSessionHasErrors('posology');
    });

    it('fica gravado na auditoria', function () {
        $medicine = pendingAiMedicine();

        asBatchAdmin()->post(route('manager.medicines.posology.approve', $medicine->id));

        expect(AuditLog::query()
            ->where('auditable_id', $medicine->id)
            ->where('event', 'updated')
            ->exists())->toBeTrue();
    });

    it('suporte do SaaS e usuário de clínica não aprovam', function () {
        $medicine = pendingAiMedicine();
        $support  = User::factory()->create();
        createEntityUser($this->saas, $support, SaasRule::Support->value);

        $this->actingAs($support)->withSession([
            'selected_entity_id'        => $this->saas->id,
            'selected_entity_is_client' => false,
            'selected_entity_user_rule' => SaasRule::Support->value,
        ])->post(route('manager.medicines.posology.approve', $medicine->id))->assertStatus(403);

        expect($medicine->fresh()->posology_source)->toBe(MedicinePosologySource::Ai);
    });
});
