<?php

/**
 * Plano do convênio na importação de pacientes (CSV):
 * - coluna "plano" com coluna de convênio = plano; sozinha = convênio
 *   (compatível com as planilhas que já usavam "plano" como convênio);
 * - casa pelo registro do produto na ANS ou pelo nome, dentro do convênio;
 * - não encontrado/ambíguo: paciente entra sem plano e a linha volta como aviso;
 * - Particular não guarda plano nem carteirinha.
 */

use App\Enums\{CovenantSource, FeatureKey, ImportStatus, SubscriptionStatus};
use App\Models\{Covenant, CovenantPlan, Entity, Patient, PatientImport, Plan, PlanFeature, Subscription, User};
use App\Services\PatientImportService;
use Illuminate\Support\Facades\Storage;

function picpEntity(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    $plan = Plan::factory()->create(['active' => true]);
    PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::MaxPatients->value, 'value' => '0']);
    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    return $entity;
}

function picpImport(Entity $entity, string $csv): PatientImport
{
    $path = "imports/patients/{$entity->id}/planos.csv";
    Storage::disk()->put($path, "\xEF\xBB\xBF" . $csv);

    $import = PatientImport::create([
        'entity_id' => $entity->id, 'user_id' => User::factory()->create()->id, 'status' => ImportStatus::Pending,
        'file_path' => $path, 'original_name' => 'planos.csv',
    ]);

    app(PatientImportService::class)->process($import);

    return $import->fresh();
}

function picpPatient(Entity $entity, string $name): Patient
{
    return Patient::withoutGlobalScopes()->where('entity_id', $entity->id)
        ->whereHas('person', fn ($q) => $q->where('full_name', $name))->sole();
}

beforeEach(function () {
    Storage::fake();
    $this->entity = picpEntity();
    $this->amil   = Covenant::factory()->create(['name' => 'AMIL', 'ans_registry' => '326305', 'source' => CovenantSource::Ans]);

    $mk = fn (array $a) => CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id' => null, 'covenant_id' => $this->amil->id, 'source' => 'ans', 'active' => true, 'ans_status' => 'active', ...$a,
    ]);
    $this->one    = $mk(['name' => 'AMIL ONE S6500', 'ans_code' => '471234567', 'ans_plan_id' => 1]);
    $this->twin1  = $mk(['name' => 'AMIL S380', 'ans_code' => '472000001', 'ans_plan_id' => 2]);
    $this->twin2  = $mk(['name' => 'AMIL S380', 'ans_code' => '472000002', 'ans_plan_id' => 3]);
    $this->closed = $mk(['name' => 'AMIL VELHO', 'ans_code' => '470000009', 'ans_plan_id' => 4, 'active' => false, 'ans_status' => 'cancelled']);
});

it('com coluna de convênio, "plano" é o plano: casa por nome (sem acento/caixa) e por registro ANS', function () {
    $import = picpImport($this->entity, "nome;celular;convenio;plano;carteirinha\n"
        . "Ana Lima;11911110001;Amil;amil one s6500;0001\n"
        . "Bia Lima;11911110002;AMIL;471.234.567;0002\n"
        . "Caio Lima;11911110003;AMIL;472000002;0003\n");

    expect($import->imported_rows)->toBe(3)
        ->and($import->warning_rows)->toBe(0)
        ->and(picpPatient($this->entity, 'ANA LIMA')->covenant_plan_id)->toBe($this->one->id)
        ->and(picpPatient($this->entity, 'BIA LIMA')->covenant_plan_id)->toBe($this->one->id)
        ->and(picpPatient($this->entity, 'CAIO LIMA')->covenant_plan_id)->toBe($this->twin2->id);
});

it('plano não encontrado, ambíguo ou indisponível: paciente entra sem plano e a linha volta como aviso', function () {
    $import = picpImport($this->entity, "nome;celular;convenio;plano\n"
        . "Dani Lima;11911110004;AMIL;Plano Inventado\n"
        . "Edu Lima;11911110005;AMIL;AMIL S380\n"
        . "Fabi Lima;11911110006;AMIL;AMIL VELHO\n");

    expect($import->imported_rows)->toBe(3)
        ->and($import->error_rows)->toBe(0)
        ->and($import->warning_rows)->toBe(3)
        ->and(picpPatient($this->entity, 'DANI LIMA')->covenant_plan_id)->toBeNull()
        ->and(picpPatient($this->entity, 'EDU LIMA')->covenant_plan_id)->toBeNull()
        ->and(picpPatient($this->entity, 'FABI LIMA')->covenant_plan_id)->toBeNull()
        ->and($import->progressPayload()['warning_rows'])->toBe(3);

    $lines   = array_values(array_filter(explode("\n", ltrim(Storage::disk()->get($import->errors_file_path), "\xEF\xBB\xBF"))));
    $reasons = array_map(fn ($line) => str_getcsv($line, ';')[1], array_slice($lines, 1));

    expect($reasons)->toContain(__('imports.patients.plan_not_found', ['plan' => 'Plano Inventado']))
        ->and($reasons)->toContain(__('imports.patients.plan_ambiguous', ['plan' => 'AMIL S380']))
        ->and($reasons)->toContain(__('imports.patients.plan_not_found', ['plan' => 'AMIL VELHO']));
});

it('sem coluna de convênio, "plano" continua sendo o convênio (planilhas antigas)', function () {
    $import = picpImport($this->entity, "nome;celular;plano\nGabi Lima;11911110007;Amil\n");

    $patient = picpPatient($this->entity, 'GABI LIMA');
    expect($import->imported_rows)->toBe(1)
        ->and($patient->covenant_id)->toBe($this->amil->id)
        ->and($patient->covenant_plan_id)->toBeNull();
});

it('pré-visualização mostra a coluna "plano" como Plano quando há coluna de convênio', function () {
    $path = "imports/patients/{$this->entity->id}/preview.csv";
    Storage::disk()->put($path, "nome;celular;convenio;plano\nHugo Lima;11911110008;AMIL;AMIL ONE S6500\n");
    $import = PatientImport::create([
        'entity_id' => $this->entity->id, 'user_id' => User::factory()->create()->id, 'status' => ImportStatus::Pending,
        'file_path' => $path, 'original_name' => 'preview.csv',
    ]);

    $preview = app(PatientImportService::class)->generatePreview($import);

    expect(collect($preview['mapped_columns'])->firstWhere('csv_header', 'plano')['field'])->toBe('_plan')
        ->and(collect($preview['mapped_columns'])->firstWhere('csv_header', 'convenio')['field'])->toBe('_covenant')
        ->and($preview['sample_rows'][0]['Plano'])->toBe('AMIL ONE S6500');
});

it('Particular não guarda plano nem carteirinha (mesma regra do cadastro manual)', function () {
    Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'Particular']);

    picpImport($this->entity, "nome;celular;convenio;plano;carteirinha\nIvo Lima;11911110009;Particular;AMIL ONE S6500;999\n");

    $patient = picpPatient($this->entity, 'IVO LIMA');
    expect($patient->covenant_plan_id)->toBeNull()
        ->and($patient->card_number)->toBeNull();
});

it('[TENANT] plano próprio de outra clínica nunca é casado', function () {
    $other = picpEntity();
    CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id' => $other->id, 'covenant_id' => $this->amil->id, 'name' => 'SÓ DA OUTRA', 'active' => true,
    ]);

    $import = picpImport($this->entity, "nome;celular;convenio;plano\nJu Lima;11911110010;AMIL;Só da Outra\n");

    expect($import->warning_rows)->toBe(1)
        ->and(picpPatient($this->entity, 'JU LIMA')->covenant_plan_id)->toBeNull();
});

it('modelo de importação traz a coluna "plano"', function () {
    $eu = createEntityUser($this->entity, User::factory()->create(), 'admin');

    $csv = $this->actingAs($eu->user)->withSession(panelSession($eu))
        ->get(route('panel.patients.import.template'))
        ->assertOk()
        ->streamedContent();

    expect(str_getcsv(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))[0], ';'))->toContain('convenio', 'plano', 'carteirinha');
});
