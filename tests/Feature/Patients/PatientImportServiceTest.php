<?php

/**
 * Cobre o mapeamento de `codigo_importacao` (import_code) no import de
 * pacientes via CSV — o campo só pode ser preenchido por este fluxo,
 * nunca pela tela normal de cadastro/edição.
 */

use App\Enums\{FeatureKey, ImportStatus, SubscriptionStatus};
use App\Models\{Covenant, Entity, Patient, PatientImport, Plan, PlanFeature, Subscription, User};
use App\Models\People;
use App\Services\PatientImportService;
use Illuminate\Support\Facades\Storage;

function entityWithUnlimitedPatients(): Entity
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

function makePatientImport(Entity $entity, string $csv): PatientImport
{
    Storage::fake();

    $path = "imports/patients/{$entity->id}/test.csv";
    Storage::disk()->put($path, "\xEF\xBB\xBF" . $csv);

    return PatientImport::create([
        'entity_id'     => $entity->id,
        'user_id'       => User::factory()->create()->id,
        'status'        => ImportStatus::Pending,
        'file_path'     => $path,
        'original_name' => 'test.csv',
    ]);
}

beforeEach(function () {
    $this->entity = entityWithUnlimitedPatients();
    // patients.covenant_id é NOT NULL — toda linha precisa resolver um convênio.
    Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'Particular']);
});

it('mapeia a coluna codigo_importacao e grava em patients.import_code', function () {
    $csv    = "nome;celular;cpf;convenio;codigo_importacao\nJoao Teste;11999999999;11122233344;Particular;LEGACY-001\n";
    $import = makePatientImport($this->entity, $csv);

    app(PatientImportService::class)->process($import);

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Done);
    expect($import->imported_rows)->toBe(1);
    expect($import->error_rows)->toBe(0);

    $patient = Patient::where('entity_id', $this->entity->id)->first();
    expect($patient->import_code)->toBe('LEGACY-001');
});

it('nao quebra quando a planilha nao traz codigo_importacao (campo fica null)', function () {
    $csv    = "nome;celular;convenio\nMaria Sem Codigo;11988887777;Particular\n";
    $import = makePatientImport($this->entity, $csv);

    app(PatientImportService::class)->process($import);

    $patient = Patient::where('entity_id', $this->entity->id)->first();
    expect($patient->import_code)->toBeNull();
});

it('rejeita codigo_importacao ja usado por outro paciente da mesma entidade', function () {
    $csv = "nome;celular;cpf;convenio;codigo_importacao\n"
        . "Primeiro Paciente;11911111111;11100011100;Particular;DUP-1\n"
        . "Segundo Paciente;11922222222;22200022200;Particular;DUP-1\n";
    $import = makePatientImport($this->entity, $csv);

    app(PatientImportService::class)->process($import);

    $import->refresh();
    expect($import->imported_rows)->toBe(1);
    expect($import->error_rows)->toBe(1);
    expect(Patient::where('entity_id', $this->entity->id)->count())->toBe(1);
    expect(Patient::where('entity_id', $this->entity->id)->first()->import_code)->toBe('DUP-1');
});

it('[SEGURANCA] import_code nao pode ser setado via tela normal de cadastro (nao esta em $fillable)', function () {
    $covenant = Covenant::where('entity_id', $this->entity->id)->first();

    $patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => $covenant->id,
        'import_code' => 'TENTATIVA-MANUAL',
    ]);

    expect($patient->fresh()->import_code)->toBeNull();
});

// ── Convênio (Particular / match / erro legível) ───────────────────────────

it('existe exatamente um convênio global PARTICULAR (migration de dados)', function () {
    expect(Covenant::whereNull('entity_id')->whereRaw('upper(name) = ?', ['PARTICULAR'])->count())->toBe(1);
});

it('convenio vazio vira o PARTICULAR global quando a clinica nao tem o proprio', function () {
    Covenant::where('entity_id', $this->entity->id)->delete();
    $global = Covenant::whereNull('entity_id')->whereRaw('upper(name) = ?', ['PARTICULAR'])->firstOrFail();

    $import = makePatientImport($this->entity, "nome;celular\nSem Convenio;11977776666\n");
    app(PatientImportService::class)->process($import);

    expect($import->fresh()->imported_rows)->toBe(1);
    expect(Patient::where('entity_id', $this->entity->id)->first()->covenant_id)->toBe($global->id);
});

it('convenio PARTICULAR da planilha casa, com prioridade pro convenio da propria clinica', function () {
    $own = Covenant::where('entity_id', $this->entity->id)->firstOrFail();

    $import = makePatientImport($this->entity, "nome;celular;convenio\nPaciente Part;11966665555;PARTICULAR\n");
    app(PatientImportService::class)->process($import);

    expect(Patient::where('entity_id', $this->entity->id)->first()->covenant_id)->toBe($own->id);
});

it('casa convenio ignorando acento e caixa', function () {
    $sula = Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'SulAmérica']);

    $import = makePatientImport($this->entity, "nome;celular;convenio\nPaciente Sula;11955554444;sulamerica\n");
    app(PatientImportService::class)->process($import);

    expect(Patient::where('entity_id', $this->entity->id)->first()->covenant_id)->toBe($sula->id);
});

it('convenio desconhecido vira erro legivel na linha, sem SQL cru no CSV de erros', function () {
    $import = makePatientImport($this->entity, "nome;celular;convenio\nPaciente X;11944443333;CONVENIO INEXISTENTE\n");
    app(PatientImportService::class)->process($import);

    $import->refresh();
    expect($import->imported_rows)->toBe(0);
    expect($import->error_rows)->toBe(1);

    $errors = Storage::disk()->get($import->errors_file_path);
    expect($errors)->toContain('não encontrado')
        ->and($errors)->not->toContain('SQLSTATE');
    expect(Patient::where('entity_id', $this->entity->id)->count())->toBe(0);
});

// ── Cancelamento ────────────────────────────────────────────────────────────

it('cancelar antes de confirmar apaga o arquivo e o registro', function () {
    $import = makePatientImport($this->entity, "nome;celular\nFulano;11999999999\n");
    $user   = User::factory()->create();
    $eu     = createEntityUser($this->entity, $user, 'admin');

    $this->actingAs($user)
        ->withSession(panelSession($eu))
        ->deleteJson(route('panel.patients.import.cancel', $import))
        ->assertRedirect(route('panel.patients.import.index'));

    expect(PatientImport::find($import->id))->toBeNull();
    expect(Storage::disk()->exists($import->file_path))->toBeFalse();
});

it('cancelar depois de confirmado nao apaga o registro, so sinaliza — job nao roda mais', function () {
    $import = makePatientImport($this->entity, "nome;celular\nFulano;11999999999\n");
    $import->update(['confirmed_at' => now()]);
    $user = User::factory()->create();
    $eu   = createEntityUser($this->entity, $user, 'admin');

    $this->actingAs($user)
        ->withSession(panelSession($eu))
        ->deleteJson(route('panel.patients.import.cancel', $import))
        ->assertRedirect(route('panel.patients.import.index'));

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Cancelled);
    expect(Storage::disk()->exists($import->file_path))->toBeTrue();

    // Simula o worker pegando o job depois do cancelamento (cenário real do
    // bug: fila parada, usuário cancela, worker volta e não deve processar).
    app(PatientImportService::class)->process($import);

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Cancelled);
    expect($import->imported_rows)->toBe(0);
    expect(Patient::where('entity_id', $this->entity->id)->count())->toBe(0);
});

it('nao deixa cancelar import ja concluido (409)', function () {
    $import = makePatientImport($this->entity, "nome;celular\nFulano;11999999999\n");
    $import->update(['confirmed_at' => now(), 'status' => ImportStatus::Done, 'finished_at' => now()]);
    $user = User::factory()->create();
    $eu   = createEntityUser($this->entity, $user, 'admin');

    $this->actingAs($user)
        ->withSession(panelSession($eu))
        ->deleteJson(route('panel.patients.import.cancel', $import))
        ->assertStatus(409);
});

it('baixa o relatorio de erros quando o arquivo existe', function () {
    $import = makePatientImport($this->entity, "nome;celular\nFulano;11999999999\n");
    $path   = "imports/patients/{$this->entity->id}/errors_{$import->id}.csv";
    Storage::disk()->put($path, "linha;erro\n2;x\n");
    $import->update(['status' => ImportStatus::Done, 'errors_file_path' => $path]);

    $user = User::factory()->create();
    $eu   = createEntityUser($this->entity, $user, 'admin');

    $this->actingAs($user)
        ->withSession(panelSession($eu))
        ->get(route('panel.patients.import.errors', $import))
        ->assertOk()
        ->assertDownload("erros_importacao_{$import->id}.csv");
});

it('relatorio de erros ausente no disco volta com aviso em vez de erro 500', function () {
    $import = makePatientImport($this->entity, "nome;celular\nFulano;11999999999\n");
    $import->update([
        'status'           => ImportStatus::Done,
        'errors_file_path' => "imports/patients/{$this->entity->id}/errors_{$import->id}.csv",
    ]);

    $user = User::factory()->create();
    $eu   = createEntityUser($this->entity, $user, 'admin');

    $this->actingAs($user)
        ->withSession(panelSession($eu))
        ->from(route('panel.patients.import.index'))
        ->get(route('panel.patients.import.errors', $import))
        ->assertRedirect(route('panel.patients.import.index'))
        ->assertSessionHas('error', __('imports.errors_file_missing'));
});

it('relatorio de erros de outra clinica continua 404', function () {
    $import = makePatientImport($this->entity, "nome;celular\nFulano;11999999999\n");
    $import->update(['errors_file_path' => 'imports/patients/x/errors.csv']);

    $other = entityWithUnlimitedPatients();
    $user  = User::factory()->create();
    $eu    = createEntityUser($other, $user, 'admin');

    $this->actingAs($user)
        ->withSession(panelSession($eu))
        ->get(route('panel.patients.import.errors', $import))
        ->assertNotFound();
});
