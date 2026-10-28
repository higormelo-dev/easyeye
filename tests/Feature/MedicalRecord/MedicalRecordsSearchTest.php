<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{Covenant, Doctor, Entity, EntityUser, MedicalRecord, Patient, People, Schedule, User};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * Fase 1 / B3 — "Prontuários" no menu: busca geral (paciente, PAC/PMR, CPF,
 * médico, período, assinado/não) com as regras de acesso de hoje aos
 * prontuários + CurrentDoctor (médico vê os dele; sem cadastro, nada).
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));

    $this->entity = Entity::factory()->create(['is_client' => true]);
    $this->other  = Entity::factory()->create(['is_client' => true]);

    [$this->docAUser, $this->docAEu, $this->doctorA] = mrsrDoctor($this->entity, 'DRA. ANA');
    [$this->docBUser, $this->docBEu, $this->doctorB] = mrsrDoctor($this->entity, 'DR. BRUNO');
    [, , $this->otherDoctor]                         = mrsrDoctor($this->other, 'DR. OUTRA');

    [$this->secUser, $this->secEu] = mrsrMember($this->entity, ClientRule::Secretary);
    [$this->admUser, $this->admEu] = mrsrMember($this->entity, ClientRule::Admin);

    $this->joao  = mrsrPatient($this->entity, 'JOÃO DA SILVA', '12345678909');
    $this->maria = mrsrPatient($this->entity, 'MARIA SOUZA', '98765432100');

    $this->recJoaoA  = mrsrRecord($this->joao, $this->doctorA, '2026-10-01 09:00:00');
    $this->recMariaB = mrsrRecord($this->maria, $this->doctorB, '2026-09-15 14:00:00', signed: true);
    $this->recJoaoB  = mrsrRecord($this->joao, $this->doctorB, '2026-08-01 08:00:00');
    $this->alien     = mrsrRecord(mrsrPatient($this->other, 'JOÃO DE OUTRA CLINICA', '11122233344'), $this->otherDoctor, '2026-10-02 10:00:00');
});

function mrsrDoctor(Entity $entity, string $name): array
{
    $user = User::factory()->create(['name' => $name]);
    $eu   = createEntityUser($entity, $user, ClientRule::Doctor->value);

    return [$user, $eu, Doctor::create([
        'entity_user_id' => $eu->id,
        'person_id'      => People::factory()->create(['full_name' => $name])->id,
        'active'         => true,
    ])];
}

function mrsrMember(Entity $entity, ClientRule $rule): array
{
    $user = User::factory()->create();

    return [$user, createEntityUser($entity, $user, $rule->value)];
}

function mrsrPatient(Entity $entity, string $name, string $cpf): Patient
{
    return Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create(['full_name' => $name, 'national_registry' => $cpf, 'birth_date' => '1980-05-10'])->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);
}

function mrsrRecord(Patient $patient, Doctor $doctor, string $at, bool $signed = false): MedicalRecord
{
    $record = MedicalRecord::create([
        'entity_id'      => $patient->entity_id,
        'patient_id'     => $patient->id,
        'doctor_id'      => $doctor->id,
        'main_complaint' => 'QUEIXA SIGILOSA ' . $patient->id,
    ]);
    DB::table('medical_records')->where('id', $record->id)->update([
        'created_at'     => $at,
        'signed_at'      => $signed ? $at : null,
        'signature_hash' => $signed ? hash('sha256', $record->id) : null,
        'is_locked'      => $signed,
    ]);

    return $record->fresh();
}

function mrsrSearch(object $t, User $user, EntityUser $eu, array $query = []): array
{
    $props = null;
    $t->actingAs($user)->withSession(panelSession($eu))
        ->get(route('panel.medical-records.index', $query))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props) {
            $page->component('Panel/MedicalRecords/Search');
            $props = $page->toArray()['props'];
        });

    return $props;
}

function mrsrIds(array $props): array
{
    return collect($props['records']['data'] ?? [])->pluck('id')->all();
}

describe('quem vê o quê', function () {
    it('secretária e admin veem os prontuários da clínica (mais novos primeiro), nunca de outra', function () {
        foreach ([[$this->secUser, $this->secEu], [$this->admUser, $this->admEu]] as [$user, $eu]) {
            expect(mrsrIds(mrsrSearch($this, $user, $eu)))->toBe([
                $this->recJoaoA->id, $this->recMariaB->id, $this->recJoaoB->id,
            ]);
        }
    });

    it('médico vê só os prontuários dele, mesmo pedindo outro médico na URL', function () {
        expect(mrsrIds(mrsrSearch($this, $this->docAUser, $this->docAEu)))->toBe([$this->recJoaoA->id])
            ->and(mrsrIds(mrsrSearch($this, $this->docAUser, $this->docAEu, ['doctor' => $this->doctorB->id])))->toBe([$this->recJoaoA->id]);

        $props = mrsrSearch($this, $this->docAUser, $this->docAEu);
        expect($props['doctors'])->toBe([])
            ->and($props['isDoctor'])->toBeTrue();
    });

    it('perfil médico sem cadastro: nada, com o aviso', function () {
        [$user, $eu] = mrsrMember($this->entity, ClientRule::Doctor);

        $props = mrsrSearch($this, $user, $eu);

        expect($props['records'])->toBeNull()
            ->and($props['doctorMissing'])->toBeTrue();
    });

    it('financeiro não abre (mesmo acesso de hoje aos prontuários)', function () {
        [$user, $eu] = mrsrMember($this->entity, ClientRule::Financial);

        $this->actingAs($user)->withSession(panelSession($eu))->get(route('panel.medical-records.index'))->assertForbidden();
    });

    it('a lista não traz conteúdo clínico; editar só para o médico e só o não assinado', function () {
        $staffRows = collect(mrsrSearch($this, $this->secUser, $this->secEu)['records']['data']);
        expect(json_encode($staffRows))->not->toContain('QUEIXA SIGILOSA')
            ->and($staffRows->pluck('edit_url')->filter()->all())->toBe([])
            ->and($staffRows->firstWhere('id', $this->recJoaoA->id)['show_url'])->toBe(route('panel.patients.medicalrecords.show', [$this->joao, $this->recJoaoA]));

        [$userB, $euB] = [$this->docBUser, $this->docBEu];
        $doctorRows    = collect(mrsrSearch($this, $userB, $euB)['records']['data'])->keyBy('id');
        expect($doctorRows[$this->recJoaoB->id]['edit_url'])->toBe(route('panel.patients.medicalrecords.edit', [$this->joao, $this->recJoaoB]))
            ->and($doctorRows[$this->recMariaB->id]['edit_url'])->toBeNull() // assinado
            ->and($doctorRows[$this->recMariaB->id]['is_signed'])->toBeTrue();
    });

    it('prontuário excluído e paciente excluído não aparecem', function () {
        $this->recJoaoB->delete();
        $this->maria->delete();

        expect(mrsrIds(mrsrSearch($this, $this->secUser, $this->secEu)))->toBe([$this->recJoaoA->id]);
    });

    it('o menu tem "Prontuários" para admin, médico e secretária', function () {
        foreach ([[$this->secUser, $this->secEu], [$this->docAUser, $this->docAEu], [$this->admUser, $this->admEu]] as [$user, $eu]) {
            $nav = mrsrSearch($this, $user, $eu)['nav'];
            expect(collect($nav)->pluck('key')->all())->toContain('medical-records');
        }
    });
});

describe('filtros', function () {
    it('paciente por nome sem acento, CPF, código PAC e código PMR (com ou sem prefixo)', function () {
        $s = fn (string $q) => mrsrIds(mrsrSearch($this, $this->secUser, $this->secEu, ['q' => $q]));

        expect($s('joao da silva'))->toBe([$this->recJoaoA->id, $this->recJoaoB->id])
            ->and($s('987.654.321-00'))->toBe([$this->recMariaB->id])
            ->and($s($this->maria->code))->toBe([$this->recMariaB->id])
            ->and($s($this->recJoaoB->code))->toBe([$this->recJoaoB->id])
            ->and($s('pmr-' . (int) substr($this->recJoaoA->code, 4)))->toBe([$this->recJoaoA->id])
            ->and($s('OUTRA CLINICA'))->toBe([]);
    });

    it('médico, período e assinado/não', function () {
        $s = fn (array $q) => mrsrIds(mrsrSearch($this, $this->secUser, $this->secEu, $q));

        expect($s(['doctor' => $this->doctorB->id]))->toBe([$this->recMariaB->id, $this->recJoaoB->id])
            ->and($s(['from' => '2026-09-01', 'until' => '2026-09-30']))->toBe([$this->recMariaB->id])
            ->and($s(['signed' => 'yes']))->toBe([$this->recMariaB->id])
            ->and($s(['signed' => 'no']))->toBe([$this->recJoaoA->id, $this->recJoaoB->id])
            // médico de outra clínica no filtro é ignorado
            ->and($s(['doctor' => $this->otherDoctor->id]))->toHaveCount(3);
    });

    it('filtro inválido é recusado', function () {
        $this->actingAs($this->secUser)->withSession(panelSession($this->secEu))
            ->get(route('panel.medical-records.index', ['from' => '2026-10-10', 'until' => '2026-10-01', 'signed' => 'talvez']))
            ->assertSessionHasErrors(['until', 'signed']);
    });

    it('marca o prontuário que veio do pronto atendimento', function () {
        $schedule = Schedule::query()->create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctorA->id, 'patient_id' => $this->joao->id,
            'full_name' => 'JOÃO DA SILVA', 'date_time' => now(), 'situation' => ScheduleSituation::InProgress->value,
            'origin'    => Schedule::ORIGIN_URGENT_CARE, 'active' => true,
        ]);
        DB::table('medical_records')->where('id', $this->recJoaoA->id)->update(['schedule_id' => $schedule->id]);

        $rows = collect(mrsrSearch($this, $this->secUser, $this->secEu)['records']['data'])->keyBy('id');

        expect($rows[$this->recJoaoA->id]['is_urgent_care'])->toBeTrue()
            ->and($rows[$this->recJoaoB->id]['is_urgent_care'])->toBeFalse();
    });
});

it('medical_record_search.php tem as mesmas chaves em pt_BR e en', function () {
    expect(array_keys(require lang_path('en/medical_record_search.php')))
        ->toEqualCanonicalizing(array_keys(require lang_path('pt_BR/medical_record_search.php')));
});
