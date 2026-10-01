<?php

declare(strict_types=1);

/**
 * People é identidade GLOBAL (sem entity_id): o mesmo registro pode ser o
 * paciente/médico de várias clínicas e o titular do Portal do Paciente.
 *
 * Achados cobertos (regressão):
 *  - Importação vinculava People de OUTRA clínica por CPF (ou nome+telefone)
 *    vindo da planilha, sem prova de posse: a clínica A passava a ler o
 *    cadastro de B, reescrevê-lo (edição) e trocar o e-mail para receber o
 *    convite do portal do titular (dados clínicos de B via exportação LGPD).
 *  - Edição de paciente/médico reescrevia o People usado por outra clínica.
 *  - Exclusão de paciente/médico apagava o People ainda usado por outra
 *    clínica: a contagem passava pelo EntityScope e só via a clínica atual.
 */

use App\Enums\{ClientRule, FeatureKey, ImportStatus, SubscriptionStatus};
use App\Models\{Covenant, Doctor, DoctorImport, Entity, Patient, PatientImport, People, Plan, PlanFeature, Subscription, User};
use App\Services\{DoctorImportService, PatientImportService};
use Illuminate\Support\Facades\Storage;

function spiEntity(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    $plan = Plan::factory()->create(['active' => true]);

    foreach ([FeatureKey::MaxPatients, FeatureKey::MaxDoctors] as $feature) {
        PlanFeature::create(['plan_id' => $plan->id, 'feature' => $feature->value, 'value' => '0']);
    }

    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    return $entity;
}

/** People com valores controlados (telefones/CEP já canônicos, como o FormRequest grava). */
function spiPerson(array $overrides = []): People
{
    return People::factory()->create(array_merge([
        'email'                  => 'titular.' . uniqid() . '@example.com',
        'telephone'              => '1133334444',
        'cellphone'              => '11999998888',
        'whatsapp'               => true,
        'zipcode'                => '01001000',
        'complement'             => null,
        'state'                  => 'SP',
        'state_registry_initial' => 'SP',
        'country'                => 'BRASIL',
    ], $overrides))->fresh();
}

function spiPatient(Entity $entity, People $person, array $attributes = []): Patient
{
    return Patient::query()->create(array_merge([
        'entity_id'   => $entity->id,
        'person_id'   => $person->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ], $attributes));
}

function spiDoctor(Entity $entity, People $person, ?User $user = null): Doctor
{
    return Doctor::query()->create([
        'entity_user_id' => createEntityUser($entity, $user ?? User::factory()->create(), ClientRule::Doctor->value)->id,
        'person_id'      => $person->id,
        'record'         => 'CRM' . random_int(10000, 99999),
        'active'         => true,
    ]);
}

/** Payload de edição do paciente com os dados ATUAIS do People (como o modal reenvia). */
function spiPatientPayload(People $person, Covenant $covenant, array $overrides = []): array
{
    return array_merge([
        'covenant_id'            => $covenant->id,
        'name'                   => $person->full_name,
        'nickname'               => $person->nickname,
        'birth_date'             => $person->birth_date?->format('Y-m-d'),
        'gender'                 => $person->gender,
        'marital_status'         => $person->marital_status,
        'email'                  => $person->email,
        'mother_name'            => $person->mother_name,
        'father_name'            => $person->father_name,
        'national_registry'      => $person->national_registry,
        'state_registry'         => $person->state_registry,
        'state_registry_agency'  => $person->state_registry_agency,
        'state_registry_initial' => $person->state_registry_initial,
        'state_registry_date'    => $person->state_registry_date?->format('Y-m-d'),
        'telephone'              => $person->telephone,
        'cellphone'              => $person->cellphone,
        'whatsapp'               => (bool) $person->whatsapp,
        'zipcode'                => $person->zipcode,
        'address'                => $person->address,
        'number'                 => $person->number,
        'complement'             => $person->complement,
        'district'               => $person->district,
        'city'                   => $person->city,
        'state'                  => $person->state,
        'country'                => $person->country,
        'active'                 => true,
    ], $overrides);
}

function spiPatientImport(Entity $entity, string $csv): PatientImport
{
    Storage::fake('private');

    $path = "imports/patients/{$entity->id}/spi.csv";
    Storage::disk('private')->put($path, "\xEF\xBB\xBF" . $csv);

    return PatientImport::create([
        'entity_id' => $entity->id, 'user_id' => User::factory()->create()->id,
        'status'    => ImportStatus::Pending, 'file_path' => $path, 'original_name' => 'spi.csv',
    ]);
}

function spiDoctorImport(Entity $entity, string $csv): DoctorImport
{
    Storage::fake('private');

    $path = "imports/doctors/{$entity->id}/spi.csv";
    Storage::disk('private')->put($path, "\xEF\xBB\xBF" . $csv);

    return DoctorImport::create([
        'entity_id' => $entity->id, 'user_id' => User::factory()->create()->id,
        'status'    => ImportStatus::Pending, 'file_path' => $path, 'original_name' => 'spi.csv',
    ]);
}

function spiErrorReason(PatientImport|DoctorImport $import): string
{
    $lines = array_values(array_filter(explode("\n", Storage::disk('private')->get($import->errors_file_path))));

    return str_getcsv($lines[1], ';')[1];
}

beforeEach(function (): void {
    $this->clinicA = spiEntity();
    $this->clinicB = spiEntity();

    $this->adminA      = User::factory()->create();
    $this->entityUserA = createEntityUser($this->clinicA, $this->adminA, ClientRule::Admin->value);

    Covenant::factory()->create(['entity_id' => $this->clinicA->id, 'name' => 'Particular']);
    $this->covenantA = Covenant::factory()->create(['entity_id' => $this->clinicA->id]);

    // Paciente da clínica B (a vítima), sem conta no portal.
    $this->personB  = spiPerson(['national_registry' => '52998224725', 'email' => 'vitima@clinicab.com']);
    $this->patientB = spiPatient($this->clinicB, $this->personB);
});

describe('importação de pacientes nunca vincula cadastro de outra clínica', function (): void {
    // O mesmo paciente pode estar em várias clínicas: CPF de outra clínica
    // gera o cadastro PRÓPRIO de A — sem erro de linha e sem tocar no de B.
    it('CPF de paciente de OUTRA clínica: A importa com People próprio e o People de B fica intocado', function (): void {
        $original = $this->personB->only(['full_name', 'email', 'cellphone']);

        $import = spiPatientImport($this->clinicA, "nome;celular;cpf\nQUALQUER NOME;11911112222;529.982.247-25\n");
        app(PatientImportService::class)->process($import);

        $import->refresh();
        $patientA = Patient::withoutGlobalScopes()->where('entity_id', $this->clinicA->id)->sole();

        expect($import->imported_rows)->toBe(1)
            ->and($import->error_rows)->toBe(0)
            ->and($patientA->person_id)->not->toBe($this->personB->id)
            ->and($patientA->person->national_registry)->toBe('52998224725')
            ->and($this->personB->fresh()->only(['full_name', 'email', 'cellphone']))->toBe($original);
    });

    it('vale também quando o paciente de B foi excluído (os dados continuam sendo de B)', function (): void {
        $this->patientB->delete();
        $original = $this->personB->only(['full_name', 'email', 'cellphone']);

        $import = spiPatientImport($this->clinicA, "nome;celular;cpf\nQUALQUER NOME;11911112222;52998224725\n");
        app(PatientImportService::class)->process($import);

        expect($import->fresh()->imported_rows)->toBe(1)
            ->and(Patient::withoutGlobalScopes()->where('entity_id', $this->clinicA->id)->sole()->person_id)->not->toBe($this->personB->id)
            ->and($this->personB->fresh()->only(['full_name', 'email', 'cellphone']))->toBe($original);
    });

    it('nome + telefone de paciente de OUTRA clínica não casa: cria um People novo para A', function (): void {
        $import = spiPatientImport(
            $this->clinicA,
            "nome;celular\n{$this->personB->full_name};{$this->personB->cellphone}\n",
        );
        app(PatientImportService::class)->process($import);

        $patientA = Patient::withoutGlobalScopes()->where('entity_id', $this->clinicA->id)->sole();

        expect($import->fresh()->imported_rows)->toBe(1)
            ->and($patientA->person_id)->not->toBe($this->personB->id);
    });

    it('reimportar paciente da PRÓPRIA clínica (mesmo CPF) continua sendo pulado, como antes', function (): void {
        $personA = spiPerson(['national_registry' => '11144477735']);
        spiPatient($this->clinicA, $personA);

        $import = spiPatientImport($this->clinicA, "nome;celular;cpf\n{$personA->full_name};11911112222;11144477735\n");
        app(PatientImportService::class)->process($import);

        expect($import->fresh()->skipped_rows)->toBe(1)
            ->and($import->fresh()->error_rows)->toBe(0);
    });

    it('nome + telefone de paciente da PRÓPRIA clínica continua deduplicando (pula)', function (): void {
        $personA = spiPerson(['national_registry' => null, 'cellphone' => '11955554444']);
        spiPatient($this->clinicA, $personA);

        $import = spiPatientImport($this->clinicA, "nome;celular\n{$personA->full_name};11955554444\n");
        app(PatientImportService::class)->process($import);

        expect($import->fresh()->skipped_rows)->toBe(1);
    });

    it('People sem vínculo com nenhuma clínica NÃO é reaproveitado pelo CPF (pode ser de outra conta do portal)', function (): void {
        $orphan = spiPerson(['national_registry' => '11144477735']);

        $import = spiPatientImport($this->clinicA, "nome;celular;cpf\n{$orphan->full_name};11911112222;11144477735\n");
        app(PatientImportService::class)->process($import);

        expect($import->fresh()->imported_rows)->toBe(1)
            ->and(Patient::withoutGlobalScopes()->where('entity_id', $this->clinicA->id)->sole()->person_id)->not->toBe($orphan->id);
    });
});

describe('importação de médicos não vincula nem reescreve cadastro de outra clínica', function (): void {
    // CPF que só existe como PACIENTE (de outra clínica ou desta) não é
    // conflito: o médico ganha o cadastro PRÓPRIO desta clínica, igual ao
    // cadastro manual — o People de B nunca é lido nem sobrescrito.
    it('CPF de paciente de OUTRA clínica: médico importado com People próprio e o People de B intocado', function (): void {
        $original = $this->personB->only(['full_name', 'nickname', 'email', 'cellphone']);

        $import = spiDoctorImport(
            $this->clinicA,
            "nome;apelido;cpf;crm;crm_especialidade;cor;email\nINVASOR;Dr Invasor;52998224725;999;Oftalmo;#123456;invasor@clinica-a.com\n",
        );
        app(DoctorImportService::class)->process($import);

        $import->refresh();
        expect($import->imported_rows)->toBe(1)
            ->and($this->personB->fresh()->only(['full_name', 'nickname', 'email', 'cellphone']))->toBe($original)
            ->and(Doctor::where('person_id', $this->personB->id)->exists())->toBeFalse()
            ->and(Doctor::query()->sole()->person->national_registry)->toBe('52998224725');
    });

    it('People de PACIENTE da própria clínica (também usado por outra) não vira o cadastro do médico', function (): void {
        // Mesmo People: paciente em B e em A (vínculo legado).
        spiPatient($this->clinicA, $this->personB);
        $original = $this->personB->only(['full_name', 'nickname', 'email']);

        $import = spiDoctorImport(
            $this->clinicA,
            "nome;apelido;cpf;crm;crm_especialidade;cor;email\nOUTRO NOME;Dr Outro;52998224725;999;Oftalmo;#123456;medico.a@clinica-a.com\n",
        );
        app(DoctorImportService::class)->process($import);

        expect($import->fresh()->imported_rows)->toBe(1)
            ->and($this->personB->fresh()->only(['full_name', 'nickname', 'email']))->toBe($original)
            ->and(Doctor::where('person_id', $this->personB->id)->exists())->toBeFalse();
    });
});

describe('edição não reescreve o People usado por outra clínica', function (): void {
    beforeEach(function (): void {
        // Vínculo legado (criado pela importação antiga): mesmo People em A e B.
        $this->patientA = spiPatient($this->clinicA, $this->personB, ['covenant_id' => $this->covenantA->id]);
    });

    it('trocar o e-mail (vetor do convite do portal) é recusado com 422 e nada é gravado', function (): void {
        $this->actingAs($this->adminA)
            ->withSession(panelSession($this->entityUserA))
            ->putJson(
                route('panel.patients.update', $this->patientA->id),
                spiPatientPayload($this->personB, $this->covenantA, ['email' => 'invasor@example.com']),
            )
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', __('shared_identity.person_shared_readonly'));

        expect($this->personB->fresh()->email)->toBe('vitima@clinicab.com');
    });

    it('trocar endereço/telefone também é recusado (alteraria o cadastro que B vê)', function (): void {
        $this->actingAs($this->adminA)
            ->withSession(panelSession($this->entityUserA))
            ->putJson(
                route('panel.patients.update', $this->patientA->id),
                spiPatientPayload($this->personB, $this->covenantA, ['cellphone' => '11900001111', 'address' => 'RUA NOVA']),
            )
            ->assertStatus(422);

        expect($this->personB->fresh()->cellphone)->toBe('11999998888');
    });

    it('editar só o vínculo com a clínica (convênio/situação) com os dados pessoais inalterados continua funcionando', function (): void {
        $otherCovenant = Covenant::factory()->create(['entity_id' => $this->clinicA->id]);

        $this->actingAs($this->adminA)
            ->withSession(panelSession($this->entityUserA))
            ->putJson(
                route('panel.patients.update', $this->patientA->id),
                spiPatientPayload($this->personB, $otherCovenant),
            )
            ->assertOk();

        expect($this->patientA->fresh()->covenant_id)->toBe($otherCovenant->id);
    });

    it('People exclusivo da clínica continua editável normalmente', function (): void {
        $personA  = spiPerson();
        $patientA = spiPatient($this->clinicA, $personA, ['covenant_id' => $this->covenantA->id]);

        $this->actingAs($this->adminA)
            ->withSession(panelSession($this->entityUserA))
            ->putJson(
                route('panel.patients.update', $patientA->id),
                spiPatientPayload($personA, $this->covenantA, ['email' => 'novo.email@example.com']),
            )
            ->assertOk();

        expect($personA->fresh()->email)->toBe('novo.email@example.com');
    });

    it('vínculo de B só com paciente EXCLUÍDO não trava a edição em A (People readotado)', function (): void {
        $this->patientB->delete();

        $this->actingAs($this->adminA)
            ->withSession(panelSession($this->entityUserA))
            ->putJson(
                route('panel.patients.update', $this->patientA->id),
                spiPatientPayload($this->personB, $this->covenantA, ['email' => 'novo.email@example.com']),
            )
            ->assertOk();

        expect($this->personB->fresh()->email)->toBe('novo.email@example.com');
    });

    it('cadastro com CPF de People excluído mas ainda usado por paciente ATIVO de outra clínica cria People próprio (o de B intocado)', function (): void {
        // Estado deixado pela exclusão antiga (apagava o People compartilhado).
        $this->patientA->forceDelete();
        $this->personB->delete();

        $this->actingAs($this->adminA)
            ->withSession(panelSession($this->entityUserA))
            ->postJson(route('panel.patients.store'), [
                'covenant_id'       => $this->covenantA->id,
                'name'              => 'INVASOR',
                'birth_date'        => '1990-01-01',
                'gender'            => 1,
                'marital_status'    => 1,
                'email'             => 'invasor@example.com',
                'national_registry' => '52998224725',
                'cellphone'         => '11911112222',
                'whatsapp'          => false,
            ])
            ->assertSuccessful();

        $person = People::withTrashed()->find($this->personB->id);
        expect($person->email)->toBe('vitima@clinicab.com')
            ->and(Patient::withoutGlobalScopes()->where('entity_id', $this->clinicA->id)->where('person_id', $this->personB->id)->exists())->toBeFalse();
    });
});

describe('exclusão não apaga o People usado por outra clínica', function (): void {
    it('excluir o paciente em A mantém o People que B ainda usa ($patientB->person continua preenchido)', function (): void {
        $patientA = spiPatient($this->clinicA, $this->personB);

        $this->actingAs($this->adminA)
            ->withSession(panelSession($this->entityUserA))
            ->deleteJson(route('panel.patients.destroy', $patientA->id))
            ->assertOk();

        expect($patientA->fresh()->trashed())->toBeTrue()
            ->and($this->personB->fresh()->trashed())->toBeFalse()
            ->and(Patient::withoutGlobalScopes()->find($this->patientB->id)->person)->not->toBeNull();
    });

    it('People sem outro uso continua indo para a lixeira junto com o paciente (comportamento anterior)', function (): void {
        $personA  = spiPerson();
        $patientA = spiPatient($this->clinicA, $personA);

        $this->actingAs($this->adminA)
            ->withSession(panelSession($this->entityUserA))
            ->deleteJson(route('panel.patients.destroy', $patientA->id))
            ->assertOk();

        expect(People::withTrashed()->find($personA->id)->trashed())->toBeTrue();
    });

    it('excluir o médico em A mantém o People usado por médico de OUTRA clínica', function (): void {
        $person  = spiPerson();
        $doctorB = spiDoctor($this->clinicB, $person);
        $doctorA = spiDoctor($this->clinicA, $person);

        $this->actingAs($this->adminA)
            ->withSession(panelSession($this->entityUserA))
            ->deleteJson(route('panel.doctors.destroy', $doctorA->id))
            ->assertOk();

        expect($person->fresh()->trashed())->toBeFalse()
            ->and($doctorB->fresh()->person)->not->toBeNull();
    });

    it('excluir o médico em A mantém o People que é paciente de OUTRA clínica', function (): void {
        $doctorA = spiDoctor($this->clinicA, $this->personB);

        $this->actingAs($this->adminA)
            ->withSession(panelSession($this->entityUserA))
            ->deleteJson(route('panel.doctors.destroy', $doctorA->id))
            ->assertOk();

        expect($this->personB->fresh()->trashed())->toBeFalse();
    });

    it('excluir o médico em A mantém o People que também é paciente da PRÓPRIA clínica (antes: contagem 1 apagava)', function (): void {
        $person   = spiPerson();
        $patientA = spiPatient($this->clinicA, $person);
        $doctorA  = spiDoctor($this->clinicA, $person);

        $this->actingAs($this->adminA)
            ->withSession(panelSession($this->entityUserA))
            ->deleteJson(route('panel.doctors.destroy', $doctorA->id))
            ->assertOk();

        expect($person->fresh()->trashed())->toBeFalse()
            ->and($patientA->fresh()->person)->not->toBeNull();
    });

    it('médico sem outro uso do People: People vai para a lixeira (comportamento anterior)', function (): void {
        $person  = spiPerson();
        $doctorA = spiDoctor($this->clinicA, $person);

        $this->actingAs($this->adminA)
            ->withSession(panelSession($this->entityUserA))
            ->deleteJson(route('panel.doctors.destroy', $doctorA->id))
            ->assertOk();

        expect(People::withTrashed()->find($person->id)->trashed())->toBeTrue();
    });
});
