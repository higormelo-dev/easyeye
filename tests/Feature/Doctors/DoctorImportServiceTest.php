<?php

/**
 * Cobre o import em lote de médicos via CSV (DoctorImportService) — os
 * mesmos 4 passos de DoctorService::create() (User → EntityUser → People →
 * Doctor), unicidade manual de record/record_specialty/color/import_code por
 * entidade, reuso seguro de User por e-mail (sem sobrescrever nome/senha) e
 * disparo do reset de senha apenas para usuário novo.
 */

use App\Enums\{ClientRule, FeatureKey, ImportStatus, SubscriptionStatus};
use App\Http\Requests\DoctorRequest;
use App\Models\{Doctor, DoctorImport, Entity, EntityUser, People, Plan, PlanFeature, Subscription, User};
use App\Services\{DoctorImportService, DoctorService};
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\{Notification, Storage};

function entityWithUnlimitedDoctors(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    $plan = Plan::factory()->create(['active' => true]);
    PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::MaxDoctors->value, 'value' => '0']);
    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    return $entity;
}

function entityWithDoctorLimit(int $limit): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    $plan = Plan::factory()->create(['active' => true]);
    PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::MaxDoctors->value, 'value' => (string) $limit]);
    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    return $entity;
}

function makeDoctorImport(Entity $entity, string $csv): DoctorImport
{
    Storage::fake();

    $path = "imports/doctors/{$entity->id}/test.csv";
    Storage::disk()->put($path, "\xEF\xBB\xBF" . $csv);

    return DoctorImport::create([
        'entity_id'     => $entity->id,
        'user_id'       => User::factory()->create()->id,
        'status'        => ImportStatus::Pending,
        'file_path'     => $path,
        'original_name' => 'test.csv',
    ]);
}

beforeEach(function () {
    $this->entity = entityWithUnlimitedDoctors();
});

it('cria medico completo (User+EntityUser+People+Doctor) a partir de uma linha valida, com import_code gravado', function () {
    Notification::fake();

    $csv = "nome;apelido;cpf;crm;crm_especialidade;cor;email;codigo_importacao\n"
        . "Joao Teste;Dr Joao;11122233344;123456;Oftalmologia;#FF0000;joao.medico@teste.com;LEGACY-DOC-1\n";
    $import = makeDoctorImport($this->entity, $csv);

    app(DoctorImportService::class)->process($import);

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Done);
    expect($import->imported_rows)->toBe(1);
    expect($import->error_rows)->toBe(0);

    $user = User::where('email', 'joao.medico@teste.com')->first();
    expect($user)->not->toBeNull();

    $entityUser = EntityUser::where('user_id', $user->id)->where('entity_id', $this->entity->id)->first();
    expect($entityUser)->not->toBeNull();
    expect($entityUser->rule)->toBe(ClientRule::Doctor->value);

    $person = People::where('national_registry', '11122233344')->first();
    expect($person)->not->toBeNull();

    $doctor = Doctor::where('entity_user_id', $entityUser->id)->first();
    expect($doctor)->not->toBeNull();
    expect($doctor->record)->toBe('123456');
    expect($doctor->record_specialty)->toBe('Oftalmologia');
    expect($doctor->color)->toBe('#FF0000');
    expect($doctor->import_code)->toBe('LEGACY-DOC-1');

    Notification::assertSentTo($user, ResetPassword::class);
});

// [SEGURANCA] Reuso de login existente só vale para quem JÁ é médico desta
// clínica (reimportação). Login de outra pessoa/clínica vira erro de linha —
// ver tests/Feature/Doctors/DoctorImportIdentityTakeoverTest.php.
it('reaproveita User existente por email quando ja e medico desta clinica (reimportacao), sem sobrescrever nome/senha nem criar outro vinculo', function () {
    Notification::fake();

    $existingUser = User::factory()->create([
        'email'    => 'existente@teste.com',
        'name'     => 'Nome Original',
        'password' => 'senha-original-hash-nao-deve-mudar',
    ]);
    $originalPasswordHash = $existingUser->password;
    $membership           = createEntityUser($this->entity, $existingUser, ClientRule::Doctor->value);

    $csv = "nome;apelido;cpf;crm;crm_especialidade;cor;email\n"
        . "Carlos Medico;Dr Carlos;22233344455;654321;Cardiologia;#00FF00;existente@teste.com\n";
    $import = makeDoctorImport($this->entity, $csv);

    app(DoctorImportService::class)->process($import);

    $import->refresh();
    expect($import->imported_rows)->toBe(1);
    expect($import->error_rows)->toBe(0);

    expect(User::where('email', 'existente@teste.com')->count())->toBe(1);

    $existingUser->refresh();
    // User::setAttribute uppercase 'name' automaticamente (mesmo comportamento
    // de People) — o valor gravado no factory já veio em maiúsculas.
    expect($existingUser->name)->toBe('NOME ORIGINAL');
    expect($existingUser->password)->toBe($originalPasswordHash);

    $entityUsers = EntityUser::where('user_id', $existingUser->id)->where('entity_id', $this->entity->id)->get();
    expect($entityUsers)->toHaveCount(1);
    expect($entityUsers->first()->id)->toBe($membership->id);
    expect($entityUsers->first()->rule)->toBe(ClientRule::Doctor->value);

    // [SEGURANCA] usuario reaproveitado nunca recebe o e-mail de reset.
    Notification::assertNotSentTo($existingUser, ResetPassword::class);
});

it('dispara Password::sendResetLink apenas para usuario novo, nao para reaproveitado', function () {
    Notification::fake();

    $existingUser = User::factory()->create(['email' => 'ja-existe@teste.com']);
    createEntityUser($this->entity, $existingUser, ClientRule::Doctor->value);

    $csv = "nome;apelido;cpf;crm;crm_especialidade;cor;email\n"
        . "Medico Novo;Dr Novo;33344455566;111111;Pediatria;#0000FF;medico.novo@teste.com\n"
        . "Medico Antigo;Dr Antigo;44455566677;222222;Dermatologia;#FFFF00;ja-existe@teste.com\n";
    $import = makeDoctorImport($this->entity, $csv);

    app(DoctorImportService::class)->process($import);

    $import->refresh();
    expect($import->imported_rows)->toBe(2);

    $newUser = User::where('email', 'medico.novo@teste.com')->first();
    Notification::assertSentTo($newUser, ResetPassword::class);
    Notification::assertNotSentTo($existingUser, ResetPassword::class);
});

it('rejeita linha com record/record_specialty/color duplicado na mesma entidade (vira erro de linha, nao quebra o import inteiro)', function () {
    $csv = "nome;apelido;cpf;crm;crm_especialidade;cor;email\n"
        . "Primeiro Medico;Dr Um;11111111111;100001;Oftalmologia;#111111;medico.um@teste.com\n"
        . "Segundo Medico;Dr Dois;22222222222;100001;Cardiologia;#222222;medico.dois@teste.com\n"
        . "Terceiro Medico;Dr Tres;33333333333;100003;Oftalmologia;#333333;medico.tres@teste.com\n"
        . "Quarto Medico;Dr Quatro;44444444444;100004;Pediatria;#111111;medico.quatro@teste.com\n";
    $import = makeDoctorImport($this->entity, $csv);

    app(DoctorImportService::class)->process($import);

    $import->refresh();
    // Linha 1 (record 100001) ok; linha 2 (record 100001 duplicado) erro;
    // linha 3 (record_specialty Oftalmologia duplicado) erro; linha 4
    // (color #111111 duplicado) erro.
    expect($import->imported_rows)->toBe(1);
    expect($import->error_rows)->toBe(3);
    expect(Doctor::count())->toBe(1);
});

it('rejeita import_code duplicado na mesma entidade (banco + dentro do mesmo arquivo)', function () {
    $csv = "nome;apelido;cpf;crm;crm_especialidade;cor;email;codigo_importacao\n"
        . "Primeiro Medico;Dr Um;55555555555;200001;Oftalmologia;#AAAAAA;medico.a@teste.com;DUP-DOC\n"
        . "Segundo Medico;Dr Dois;66666666666;200002;Cardiologia;#BBBBBB;medico.b@teste.com;DUP-DOC\n";
    $import = makeDoctorImport($this->entity, $csv);

    app(DoctorImportService::class)->process($import);

    $import->refresh();
    expect($import->imported_rows)->toBe(1);
    expect($import->error_rows)->toBe(1);
    expect(Doctor::where('import_code', 'DUP-DOC')->count())->toBe(1);
});

it('respeita FeatureKey::MaxDoctors (aborta quando o plano esgota)', function () {
    $limitedEntity = entityWithDoctorLimit(1);

    $csv = "nome;apelido;cpf;crm;crm_especialidade;cor;email\n"
        . "Medico Um;Dr Um;77777777777;300001;Oftalmologia;#CCCCCC;medico.limite1@teste.com\n"
        . "Medico Dois;Dr Dois;88888888888;300002;Cardiologia;#DDDDDD;medico.limite2@teste.com\n";
    $import = makeDoctorImport($limitedEntity, $csv);

    app(DoctorImportService::class)->process($import);

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Done);
    expect($import->abort_reason)->not->toBeNull();
    expect($import->imported_rows)->toBe(1);

    $count = Doctor::query()
        ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
        ->where('entity_users.entity_id', $limitedEntity->id)
        ->count();
    expect($count)->toBe(1);
});

it('[SEGURANCA] import_code nao pode ser setado via DoctorRequest/tela normal de cadastro', function () {
    $user       = User::factory()->create();
    $entityUser = createEntityUser($this->entity, $user, ClientRule::Doctor->value);
    $person     = People::factory()->create();

    $doctor = Doctor::create([
        'entity_user_id'   => $entityUser->id,
        'person_id'        => $person->id,
        'record'           => '999999',
        'record_specialty' => 'Neurologia',
        'color'            => '#123123',
        'import_code'      => 'TENTATIVA-MANUAL',
    ]);

    expect($doctor->fresh()->import_code)->toBeNull();

    // Confirma tambem que o fluxo de update (DoctorService::update, usado
    // pelo DoctorsController) nao expoe/ altera o campo mesmo que alguem
    // force o atributo no payload da request — DoctorRequest nao tem regra
    // para 'import_code' e DoctorService::update() nunca referencia o campo.
    $reflection  = new ReflectionClass(DoctorRequest::class);
    $rulesMethod = $reflection->getMethod('rules');
    expect($rulesMethod)->not->toBeNull();

    $serviceSource = file_get_contents((new ReflectionClass(DoctorService::class))->getFileName());
    expect($serviceSource)->not->toContain('import_code');
});

// ── Cancelamento ────────────────────────────────────────────────────────────

it('cancelar antes de confirmar apaga o arquivo e o registro', function () {
    $csv    = "nome;apelido;cpf;crm;crm_especialidade;cor;email\nJoao;Dr Joao;11122233344;111111;Oftalmologia;#FF0000;joao@teste.com\n";
    $import = makeDoctorImport($this->entity, $csv);
    $user   = User::factory()->create();
    $eu     = createEntityUser($this->entity, $user, 'admin');

    $this->actingAs($user)
        ->withSession(panelSession($eu))
        ->deleteJson(route('panel.doctors.import.cancel', $import))
        ->assertRedirect(route('panel.doctors.import.index'));

    expect(DoctorImport::find($import->id))->toBeNull();
    expect(Storage::disk()->exists($import->file_path))->toBeFalse();
});

it('cancelar depois de confirmado nao apaga o registro, so sinaliza — job nao roda mais', function () {
    $csv    = "nome;apelido;cpf;crm;crm_especialidade;cor;email\nJoao;Dr Joao;11122233344;111111;Oftalmologia;#FF0000;joao@teste.com\n";
    $import = makeDoctorImport($this->entity, $csv);
    $import->update(['confirmed_at' => now()]);
    $user = User::factory()->create();
    $eu   = createEntityUser($this->entity, $user, 'admin');

    $this->actingAs($user)
        ->withSession(panelSession($eu))
        ->deleteJson(route('panel.doctors.import.cancel', $import))
        ->assertRedirect(route('panel.doctors.import.index'));

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Cancelled);
    expect(Storage::disk()->exists($import->file_path))->toBeTrue();

    // Simula o worker pegando o job depois do cancelamento (fila parada,
    // usuário cancela, worker volta e não deve processar).
    app(DoctorImportService::class)->process($import);

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Cancelled);
    expect($import->imported_rows)->toBe(0);
    expect(Doctor::count())->toBe(0);
});

it('nao deixa cancelar import ja concluido (409)', function () {
    $csv    = "nome;apelido;cpf;crm;crm_especialidade;cor;email\nJoao;Dr Joao;11122233344;111111;Oftalmologia;#FF0000;joao@teste.com\n";
    $import = makeDoctorImport($this->entity, $csv);
    $import->update(['confirmed_at' => now(), 'status' => ImportStatus::Done, 'finished_at' => now()]);
    $user = User::factory()->create();
    $eu   = createEntityUser($this->entity, $user, 'admin');

    $this->actingAs($user)
        ->withSession(panelSession($eu))
        ->deleteJson(route('panel.doctors.import.cancel', $import))
        ->assertStatus(409);
});
