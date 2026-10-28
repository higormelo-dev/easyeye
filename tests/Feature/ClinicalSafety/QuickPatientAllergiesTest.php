<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Enums\ClinicalSafety\{AllergyCategory, AllergyStatus};
use App\Models\{AuditLog, Covenant, Entity, EntityUser, Patient, PatientAllergy, People, User};
use App\Services\ClinicalSafety\PatientClinicalSafetyService;

/*
 * Fase 2 / G2 — alergias no CADASTRO RÁPIDO de paciente (lista de espera e
 * pronto atendimento — rota panel.patients.quick).
 *
 * Campo opcional de substâncias (texto curto, vírgula ou ponto e vírgula) e
 * "nega alergias". Alergia entra como "informada pelo paciente" (recepção
 * nunca confirma), categoria "outro" (o texto curto não diz se é remédio,
 * colírio, látex ou alimento; a checagem da receita usa a substância, não a
 * categoria). Nada informado = "sem registro". "Nega" + alergias = 422 e nada
 * é gravado. Tudo na mesma transação do cadastro.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'PARTICULAR', 'active' => true]);

    $this->secretary = createEntityUser($this->entity, User::factory()->create(['name' => 'Rita Recepção']), ClientRule::Secretary->value);
    $this->docEu     = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    doctorRecordForEntityUser($this->docEu);
});

function g2fase2As(object $test, EntityUser $eu): object
{
    return $test->actingAs($eu->user)->withSession(panelSession($eu));
}

function g2fase2Quick(object $test, array $extra = [], ?EntityUser $as = null)
{
    return g2fase2As($test, $as ?? $test->secretary)->postJson(route('panel.patients.quick'), array_merge([
        'name' => 'Paciente Rápido', 'cellphone' => '11955554444',
    ], $extra));
}

function g2fase2Patient(string $id): Patient
{
    return Patient::query()->withoutGlobalScopes()->findOrFail($id);
}

describe('cadastro rápido com alergias', function () {
    it('substâncias viram alergias "informadas pelo paciente" (categoria outro), sem repetir, em nome de quem cadastrou', function () {
        $id = g2fase2Quick($this, ['allergies' => 'Dipirona; penicilina,  DIPÍRONA , látex'])
            ->assertCreated()
            ->json('patient.id');

        $allergies = PatientAllergy::query()->where('patient_id', $id)->orderBy('created_at')->get();

        expect($allergies->pluck('substance')->all())->toBe(['Dipirona', 'penicilina', 'látex'])
            ->and($allergies->every(fn (PatientAllergy $a) => $a->status === AllergyStatus::Reported && $a->category === AllergyCategory::Other))->toBeTrue()
            ->and($allergies->every(fn (PatientAllergy $a) => $a->confirmed_at === null && $a->created_by === $this->secretary->user_id))->toBeTrue()
            ->and(g2fase2Patient($id)->allergies_denied_at)->toBeNull();

        // Auditoria (dado sensível): cada alergia criada vai para audit_logs.
        expect(AuditLog::query()->where('auditable_type', PatientAllergy::class)->where('event', 'created')->count())->toBe(3);
    });

    it('médico cadastrando pelo mesmo atalho também registra como informada (confirma depois, no atendimento)', function () {
        $id = g2fase2Quick($this, ['allergies' => 'Iodo'], $this->docEu)->assertCreated()->json('patient.id');

        expect(PatientAllergy::query()->where('patient_id', $id)->sole()->status)->toBe(AllergyStatus::Reported);
    });

    it('"nega alergias" grava quem e quando', function () {
        $id = g2fase2Quick($this, ['allergies_denied' => true])->assertCreated()->json('patient.id');

        $patient = g2fase2Patient($id);

        expect($patient->allergies_denied_at)->not->toBeNull()
            ->and($patient->allergies_denied_by)->toBe($this->secretary->user_id)
            ->and(PatientAllergy::query()->where('patient_id', $id)->count())->toBe(0)
            ->and(app(PatientClinicalSafetyService::class)->summary($patient)['allergy_state'])->toBe('denied');
    });

    it('sem preencher nada fica "sem registro de alergias" (ninguém perguntou)', function () {
        $id = g2fase2Quick($this, ['allergies' => '  ', 'allergies_denied' => false])->assertCreated()->json('patient.id');

        $patient = g2fase2Patient($id);

        expect(PatientAllergy::query()->where('patient_id', $id)->count())->toBe(0)
            ->and($patient->allergies_denied_at)->toBeNull()
            ->and(app(PatientClinicalSafetyService::class)->summary($patient)['allergy_state'])->toBe('unknown');
    });
});

describe('validação no servidor', function () {
    it('"nega alergias" junto com alergias → 422 e NADA é gravado (nem o paciente)', function () {
        g2fase2Quick($this, ['allergies' => 'Dipirona', 'allergies_denied' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allergies_denied');

        expect(People::query()->where('full_name', 'like', '%RÁPIDO%')->orWhere('full_name', 'like', '%Rápido%')->count())->toBe(0)
            ->and(PatientAllergy::query()->count())->toBe(0);
    });

    it('limites: texto > 500, mais de 10 substâncias, substância curta ou longa demais → 422', function () {
        g2fase2Quick($this, ['allergies' => str_repeat('a', 501)])->assertUnprocessable()->assertJsonValidationErrors('allergies');

        $eleven = implode(', ', array_map(fn (int $i) => 'Substancia ' . chr(64 + $i), range(1, 11)));
        g2fase2Quick($this, ['allergies' => $eleven])->assertUnprocessable()->assertJsonValidationErrors('allergies');

        g2fase2Quick($this, ['allergies' => 'Dipirona; X'])->assertUnprocessable()->assertJsonValidationErrors('allergies');
        g2fase2Quick($this, ['allergies' => str_repeat('b', 161)])->assertUnprocessable()->assertJsonValidationErrors('allergies');

        g2fase2Quick($this, ['allergies_denied' => 'talvez'])->assertUnprocessable()->assertJsonValidationErrors('allergies_denied');

        expect(Patient::query()->withoutGlobalScopes()->count())->toBe(0);
    });

    it('mensagem em inglês com a tela em inglês', function () {
        $this->actingAs($this->secretary->user)
            ->withSession([...panelSession($this->secretary), 'locale' => 'en'])
            ->postJson(route('panel.patients.quick'), ['name' => 'Quick Patient', 'cellphone' => '11955554444', 'allergies' => 'Dipirona', 'allergies_denied' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.allergies_denied.0', 'Enter the allergies or mark "denies allergies" — not both.');
    });
});
