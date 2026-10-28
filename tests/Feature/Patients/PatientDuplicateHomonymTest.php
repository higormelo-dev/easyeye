<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, ImportStatus, SubscriptionStatus};
use App\Models\{Covenant, Entity, Patient, PatientImport, People, Plan, PlanFeature, Subscription, User};
use App\Services\PatientImportService;
use Illuminate\Support\Facades\Storage;

/*
 * Paciente duplicado barrado só pelo nome: PatientRequest usava
 * uniqueInClinic('full_name') e bloqueava homônimo legítimo (e não pegava
 * "José" × "JOSE"). Regra nova (App\Services\PatientDuplicateFinder), na
 * mesma clínica, ignorando excluídos:
 *  - mesmo nome (sem acento/caixa, espaços normalizados) + mesmo nascimento →
 *    bloqueia, com o código do paciente existente;
 *  - mesmo nome, nascimento diferente ou vazio → 422 em confirm_homonym até
 *    reenviar com confirm_homonym=1 ("é outra pessoa").
 * Caminhos: cadastro completo (Pacientes e abas da Agenda — mesma rota),
 * edição, cadastro rápido da lista de espera e importação de pacientes.
 */
beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'PARTICULAR', 'active' => true]);

    $user         = User::factory()->create();
    $this->member = createEntityUser($this->entity, $user, ClientRule::Secretary->value);
    $this->actingAs($user)->withSession(panelSession($this->member));

    $this->existing = f0PatientIn($this->entity, $this->covenant, 'JOSÉ DA SILVA', '1980-03-15');
});

function f0PatientIn(Entity $entity, Covenant $covenant, string $name, ?string $birth): Patient
{
    return Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create(['full_name' => $name, 'birth_date' => $birth])->id,
        'covenant_id' => $covenant->id,
        'active'      => true,
    ]);
}

function f0NewPatientPayload(object $test, array $extra = []): array
{
    return array_merge([
        'name'              => 'jose  da   silva',
        'birth_date'        => '1980-03-15',
        'gender'            => 1,
        'marital_status'    => 1,
        'email'             => 'homonimo' . uniqid() . '@example.com',
        'national_registry' => (string) random_int(10000000000, 99999999999),
        'cellphone'         => '11988887777',
        'whatsapp'          => false,
        'covenant_id'       => $test->covenant->id,
    ], $extra);
}

describe('cadastro completo (Pacientes e abas da Agenda)', function () {
    it('mesmo nome (sem acento/caixa/espaços) + mesmo nascimento é duplicado: bloqueia com o código existente', function () {
        $this->postJson(route('panel.patients.store'), f0NewPatientPayload($this))
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', __('validation.custom.patient.duplicate', ['codes' => $this->existing->code]))
            ->assertJsonMissingPath('errors.confirm_homonym');

        // Nem a confirmação de homônimo libera duplicado real.
        $this->postJson(route('panel.patients.store'), f0NewPatientPayload($this, ['confirm_homonym' => true]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        expect(Patient::where('entity_id', $this->entity->id)->count())->toBe(1);
    });

    it('mesmo nome com nascimento diferente pede confirmação; com confirm_homonym=1 salva', function () {
        $payload = f0NewPatientPayload($this, ['birth_date' => '1992-11-02']);

        $this->postJson(route('panel.patients.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.confirm_homonym.0', __('validation.custom.patient.homonym', ['codes' => $this->existing->code]))
            ->assertJsonMissingPath('errors.name');

        expect(Patient::where('entity_id', $this->entity->id)->count())->toBe(1);

        $this->postJson(route('panel.patients.store'), $payload + ['confirm_homonym' => true])->assertOk();

        expect(Patient::where('entity_id', $this->entity->id)->count())->toBe(2);
    });

    it('nascimento vazio no cadastro existente também é homônimo (pede confirmação)', function () {
        $semData = f0PatientIn($this->entity, $this->covenant, 'MARIA SOUZA', null);

        $this->postJson(route('panel.patients.store'), f0NewPatientPayload($this, ['name' => 'Maria Souza', 'birth_date' => '2001-01-01']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.confirm_homonym.0', __('validation.custom.patient.homonym', ['codes' => $semData->code]));
    });

    it('nome diferente não conflita (e homônimo legítimo não é mais barrado só pelo nome)', function () {
        $this->postJson(route('panel.patients.store'), f0NewPatientPayload($this, ['name' => 'JOSÉ DA SILVA JUNIOR']))
            ->assertOk();
    });

    it('editar o próprio paciente não conflita com ele mesmo', function () {
        $this->putJson(route('panel.patients.update', $this->existing->id), f0NewPatientPayload($this, [
            'name'      => 'José da Silva',
            'cellphone' => '11900001111',
            'active'    => true,
        ]))->assertOk();
    });

    it('editar outro paciente para o nome+nascimento de um existente é bloqueado; homônimo pede confirmação', function () {
        $other = f0PatientIn($this->entity, $this->covenant, 'CARLOS LIMA', '1975-07-07');

        $this->putJson(route('panel.patients.update', $other->id), f0NewPatientPayload($this, ['active' => true]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->putJson(route('panel.patients.update', $other->id), f0NewPatientPayload($this, ['birth_date' => '1975-07-07', 'active' => true]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirm_homonym']);

        $this->putJson(route('panel.patients.update', $other->id), f0NewPatientPayload($this, ['birth_date' => '1975-07-07', 'active' => true, 'confirm_homonym' => true]))
            ->assertOk();
    });

    it('edição que não muda nome nem nascimento não reabre a checagem (homônimo já confirmado não trava)', function () {
        $twin = f0PatientIn($this->entity, $this->covenant, 'JOSE DA SILVA', '1999-09-09');

        $this->putJson(route('panel.patients.update', $twin->id), f0NewPatientPayload($this, [
            'name'       => 'JOSE DA SILVA',
            'birth_date' => '1999-09-09',
            'active'     => true,
        ]))->assertOk();
    });

    it('paciente excluído (soft delete) não conta', function () {
        $this->existing->delete();

        $this->postJson(route('panel.patients.store'), f0NewPatientPayload($this))->assertOk();
    });

    it('outra clínica não interfere (isolamento)', function () {
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        f0PatientIn($other, Covenant::factory()->create(['entity_id' => $other->id]), 'ANA PAULA', '1990-01-01');

        $this->postJson(route('panel.patients.store'), f0NewPatientPayload($this, ['name' => 'Ana Paula', 'birth_date' => '1990-01-01']))
            ->assertOk();
    });
});

describe('cadastro rápido (lista de espera)', function () {
    it('nome de paciente existente pede confirmação; com confirm_homonym=1 cria (convênio Particular)', function () {
        $this->postJson(route('panel.patients.quick'), ['name' => 'José da Silva', 'cellphone' => '11977776666'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.confirm_homonym.0', __('validation.custom.patient.homonym', ['codes' => $this->existing->code]));

        $created = $this->postJson(route('panel.patients.quick'), ['name' => 'José da Silva', 'cellphone' => '11977776666', 'confirm_homonym' => true])
            ->assertCreated()
            ->json('patient');

        expect(Patient::find($created['id'])->covenant_id)->toBe($this->covenant->id);
    });

    it('nome novo cria direto', function () {
        $this->postJson(route('panel.patients.quick'), ['name' => 'Fulano Novo', 'cellphone' => '11955554444'])
            ->assertCreated()
            ->assertJsonPath('patient.full_name', 'FULANO NOVO');
    });
});

describe('importação de pacientes', function () {
    beforeEach(function () {
        $plan = Plan::factory()->create(['active' => true]);
        PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::MaxPatients->value, 'value' => '0']);
        Subscription::factory()->create([
            'entity_id' => $this->entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);
    });

    it('duplicado real vira erro da linha (não grava); homônimo importa com aviso', function () {
        Storage::fake();
        $path = "imports/patients/{$this->entity->id}/f0-dup.csv";
        Storage::disk()->put($path, "nome;celular;nascimento;convenio\n"
            . "Jose da Silva;11912340000;15/03/1980;Particular\n"
            . "Jose da Silva;11912341111;20/10/1995;Particular\n");

        $import = PatientImport::create([
            'entity_id' => $this->entity->id, 'user_id' => User::factory()->create()->id,
            'status'    => ImportStatus::Pending, 'file_path' => $path, 'original_name' => 'f0-dup.csv',
        ]);

        app(PatientImportService::class)->process($import);
        $import->refresh();

        $report = Storage::disk()->get($import->errors_file_path);

        expect($import->imported_rows)->toBe(1)
            ->and($import->error_rows)->toBe(1)
            ->and($import->warning_rows)->toBe(1)
            ->and(Patient::where('entity_id', $this->entity->id)->count())->toBe(2)
            ->and($report)->toContain(__('validation.custom.patient.duplicate_import', ['codes' => $this->existing->code]))
            ->and($report)->toContain(__('validation.custom.patient.homonym_import', ['codes' => $this->existing->code]));
    });
});
