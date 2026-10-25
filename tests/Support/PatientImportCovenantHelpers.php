<?php

declare(strict_types=1);

/**
 * Helpers compartilhados pelos testes do de-para de convênios da importação de pacientes
 * (PatientImportCovenantMappingTest, PatientImportCovenantReprocessTest, PatientImportFilesRetentionTest). Pest carrega
 * todos os arquivos no mesmo processo, então as funções são globais com prefixo `picm`; `require_once` nos testes
 * permite rodar cada arquivo sozinho.
 */

use App\Enums\{FeatureKey, ImportStatus, SubscriptionStatus};
use App\Models\{Covenant, CovenantPlan, Entity, PatientImport, PatientImportCovenantMapping, Plan, PlanFeature, Subscription, User};
use App\Services\Covenants\{CovenantResolutionException, PatientImportCovenantResolver};
use App\Services\PatientImportService;
use App\Support\ImportNameNormalizer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function picmEntity(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    return $entity;
}

/** Clínica com assinatura ativa e limite de pacientes ilimitado (processa de verdade). */
function picmClinic(): Entity
{
    $entity = picmEntity();
    $plan   = Plan::factory()->create(['active' => true]);
    PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::MaxPatients->value, 'value' => '0']);
    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    return $entity;
}

/** Grava o CSV e processa na hora (mesmo caminho do job), devolvendo o import já atualizado. */
function picmProcess(Entity $entity, string $csv, array $attributes = []): PatientImport
{
    $path = "imports/patients/{$entity->id}/" . Str::uuid() . '.csv';
    Storage::disk()->put($path, "\xEF\xBB\xBF" . $csv);

    $import = picmImport($entity, ['file_path' => $path, ...$attributes]);
    app(PatientImportService::class)->process($import);

    return $import->fresh();
}

/** @param array<string, mixed> $attributes */
function picmImport(Entity $entity, array $attributes = []): PatientImport
{
    return PatientImport::create([
        'entity_id' => $entity->id, 'user_id' => User::factory()->create()->id, 'status' => ImportStatus::Pending,
        'file_path' => "imports/patients/{$entity->id}/arquivo.csv", 'original_name' => 'arquivo.csv', ...$attributes,
    ]);
}

/** O PARTICULAR global semeado pela migration (o catálogo de teste já nasce com ele). */
function picmParticular(): Covenant
{
    return Covenant::withoutGlobalScopes()->whereNull('entity_id')->where('name', 'PARTICULAR')->sole();
}

function picmPlan(Covenant $covenant, string $name = 'PLANO GOLD', array $attributes = []): CovenantPlan
{
    return CovenantPlan::withoutGlobalScopes()->forceCreate(['entity_id' => null, 'covenant_id' => $covenant->id, 'name' => $name, 'active' => true, ...$attributes]);
}

function picmMapping(Entity $entity, string $sourceName, Covenant $covenant, ?CovenantPlan $plan = null): PatientImportCovenantMapping
{
    return PatientImportCovenantMapping::create([
        'entity_id'   => $entity->id, 'source_name' => $sourceName, 'source_name_normalized' => ImportNameNormalizer::covenantKey($sourceName),
        'covenant_id' => $covenant->id, 'covenant_plan_id' => $plan?->id,
    ]);
}

function picmResolver(Entity $entity, ?array $snapshot = null): PatientImportCovenantResolver
{
    $resolver = new PatientImportCovenantResolver();
    $resolver->load($entity->id, $snapshot);

    return $resolver;
}

function picmFailure(Closure $call): CovenantResolutionException
{
    try {
        $call();
    } catch (CovenantResolutionException $e) {
        return $e;
    }

    throw new RuntimeException('Esperava CovenantResolutionException.');
}

/** CSV original + import pendente/pronto, sem processar (o relatório lê só o arquivo e os metadados). */
function picmReportImport(Entity $entity, string $csv, array $attributes = []): PatientImport
{
    $path = "imports/patients/{$entity->id}/" . Str::uuid() . '.csv';
    Storage::disk()->put($path, "\xEF\xBB\xBF" . $csv);

    return picmImport($entity, ['file_path' => $path, 'status' => ImportStatus::Done, 'files_expire_at' => now()->addDays(30), ...$attributes]);
}

/** CSV de erros no formato real do saveErrorsFile(): [linha, mensagem, nome do convênio]. */
function picmErrorsFile(PatientImport $import, array $rows): void
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, "\xEF\xBB\xBF");
    fputcsv($stream, ['_linha', '_erro', 'full_name', 'cellphone', '_covenant', '_plan'], ';');

    foreach ($rows as [$line, $message, $covenant]) {
        fputcsv($stream, [$line, $message, 'PACIENTE SIGILOSO ' . $line, '1199990' . $line, $covenant, ''], ';');
    }

    rewind($stream);
    $path = "imports/patients/{$import->entity_id}/errors_{$import->id}.csv";
    Storage::disk()->put($path, stream_get_contents($stream));
    fclose($stream);

    $import->update(['errors_file_path' => $path]);
}

function picmNotFound(string $name): string
{
    return "Convênio \"{$name}\" não encontrado. Cadastre-o em Configurações › Convênios ou corrija a planilha.";
}

/** Planilha de 6 linhas: PAS 606 ×3 (linhas 2, 3, 7), Sul América (4), ASPEB inativo (5), sem convênio (6). */
function picmReportCsv(): string
{
    return "nome;celular;convenio\n"
        . "Ana;11911110001;PAS 606\nBia;11911110002;PAS 606\nCaio;11911110003;Sul América\n"
        . "Dani;11911110004;ASPEB\nEdu;11911110005;\nFabi;11911110006;pas 606\n";
}

function picmReportCatalog(): void
{
    Covenant::factory()->create(['name' => 'SULAMERICA']);
    Covenant::factory()->create(['name' => 'ASPEB', 'active' => false]);
}

function picmMappings(Entity $entity): array
{
    return PatientImportCovenantMapping::withoutGlobalScopes()->where('entity_id', $entity->id)->orderBy('source_name_normalized')->get()
        ->mapWithKeys(fn ($m) => [$m->source_name_normalized => [$m->source_name, $m->covenant_id, $m->covenant_plan_id]])->all();
}

function picmErrors(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Esperava ValidationException.');
}

/** Clínica "de verdade" (com o trial automático) para atravessar o middleware do painel. */
function picmPanelClinic(): Entity
{
    return Entity::factory()->create(['is_client' => true, 'active' => true]);
}

function picmAs(Entity $clinic, string $rule = 'admin')
{
    $user = User::factory()->create();

    return test()->actingAs($user)->withSession(panelSession(createEntityUser($clinic, $user, $rule)));
}
