<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ScheduleSituation, UrgentCarePriority, UrgentCareReason, UrgentCareStatus};
use App\Enums\ClinicalSafety\AllergyStatus;
use App\Models\{AuditLog, Covenant, DataAccessLog, Entity, EntityUser, Patient, PatientAllergy, People, Schedule, UrgentCareEntry, User};
use App\Support\PatientAccess;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia;

/*
 * Fase 2 / A1 — alergias do paciente (nível paciente, não consulta).
 *
 * Decisão do dono: médico E recepção registram; a recepção só como
 * "informada pelo paciente"; só o médico com cadastro ativo confirma, corrige
 * o confirmado ou descarta. "Nega alergias" explícito (quem/quando). Alerta
 * para todos que atendem (Agenda, pronto atendimento) — e nada para perfil
 * sem acesso a Pacientes.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->other  = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->secretary = createEntityUser($this->entity, User::factory()->create(['name' => 'Rita Recepção']), ClientRule::Secretary->value);

    $this->docEu  = createEntityUser($this->entity, User::factory()->create(['name' => 'Dra. Ana']), ClientRule::Doctor->value);
    $this->doctor = doctorRecordForEntityUser($this->docEu);

    // Perfil médico SEM cadastro de médico ativo.
    $this->docNoRecord = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    doctorRecordForEntityUser($this->docNoRecord, active: false);

    $this->patient      = cs2Patient($this->entity, 'MARIA ALERGICA');
    $this->otherPatient = cs2Patient($this->other, 'OUTRA CLINICA');
});

function cs2Patient(Entity $entity, string $name): Patient
{
    return Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create(['full_name' => $name])->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);
}

function cs2As(object $test, EntityUser $eu): object
{
    return $test->actingAs($eu->user)->withSession(panelSession($eu));
}

/** @return array<string, mixed> */
function cs2Allergy(array $overrides = []): array
{
    return array_merge([
        'category'  => 'medication',
        'substance' => 'Dipirona',
        'reaction'  => 'Urticária',
        'severity'  => 'moderate',
    ], $overrides);
}

describe('registro e permissões', function () {
    it('recepção registra sempre como "informada pelo paciente" — mesmo pedindo confirmada', function () {
        $res = cs2As($this, $this->secretary)
            ->postJson(route('panel.patients.allergies.store', $this->patient), cs2Allergy(['status' => 'confirmed']))
            ->assertCreated()
            ->assertJsonPath('data.allergy_state', 'has')
            ->assertJsonPath('data.can_review', false);

        $allergy = PatientAllergy::query()->sole();
        expect($allergy->status)->toBe(AllergyStatus::Reported)
            ->and($allergy->confirmed_at)->toBeNull()
            ->and($allergy->created_by)->toBe($this->secretary->user_id)
            ->and($allergy->entity_id)->toBe($this->entity->id);

        expect($res->json('data.allergies.0.registered_by'))->toBe('RITA RECEPÇÃO')
            ->and($res->json('data.allergies.0.status_label'))->toBe('Informada pelo paciente');
    });

    it('princípio ativo com combinação longa (até 1000, igual ao catálogo CMED) não é recusado (14ª revisão Codex, P2)', function () {
        // patient_allergies.active_ingredient e a validação tinham ficado em
        // 500 — abaixo do limite real do catálogo
        // (medicines.active_ingredient, 1000), que o importador da Anvisa usa
        // pra combinação com vários princípios ativos.
        $longIngredient = rtrim(str_repeat('PRINCIPIO ATIVO COMBINADO, ', 20), ', ');
        expect(mb_strlen($longIngredient))->toBeGreaterThan(500)->toBeLessThanOrEqual(1000);

        cs2As($this, $this->docEu)
            ->postJson(route('panel.patients.allergies.store', $this->patient), cs2Allergy(['active_ingredient' => $longIngredient]))
            ->assertCreated();

        expect(PatientAllergy::query()->sole()->active_ingredient)->toBe($longIngredient);
    });

    it('médico com cadastro ativo registra já confirmada (ou como informada, se escolher)', function () {
        cs2As($this, $this->docEu)
            ->postJson(route('panel.patients.allergies.store', $this->patient), cs2Allergy())
            ->assertCreated()
            ->assertJsonPath('data.can_review', true)
            ->assertJsonPath('data.allergies.0.status', 'confirmed')
            ->assertJsonPath('data.allergies.0.confirmed_by', 'DRA. ANA');

        cs2As($this, $this->docEu)
            ->postJson(route('panel.patients.allergies.store', $this->patient), cs2Allergy(['substance' => 'Látex', 'category' => 'latex', 'status' => 'reported']))
            ->assertCreated();

        expect(PatientAllergy::query()->where('substance', 'Látex')->value('status'))->toBe(AllergyStatus::Reported);
    });

    it('só o médico com cadastro ativo confirma ou descarta (recepção e médico sem cadastro: 403)', function () {
        $allergy = cs2As($this, $this->secretary)
            ->postJson(route('panel.patients.allergies.store', $this->patient), cs2Allergy())->assertCreated();
        $id = PatientAllergy::query()->value('id');

        foreach ([$this->secretary, $this->docNoRecord] as $who) {
            cs2As($this, $who)->postJson(route('panel.patients.allergies.confirm', [$this->patient, $id]))->assertForbidden();
            cs2As($this, $who)->postJson(route('panel.patients.allergies.discard', [$this->patient, $id]))->assertForbidden();
        }

        expect(PatientAllergy::query()->value('status'))->toBe(AllergyStatus::Reported);

        cs2As($this, $this->docEu)
            ->postJson(route('panel.patients.allergies.confirm', [$this->patient, $id]))
            ->assertOk()
            ->assertJsonPath('data.allergies.0.status', 'confirmed');

        $fresh = PatientAllergy::query()->sole();
        expect($fresh->confirmed_by)->toBe($this->docEu->user_id)->and($fresh->confirmed_at)->not->toBeNull();

        cs2As($this, $this->docEu)
            ->postJson(route('panel.patients.allergies.discard', [$this->patient, $id]), ['reason' => 'Teste de provocação negativo'])
            ->assertOk()
            ->assertJsonPath('data.allergy_state', 'unknown')
            ->assertJsonPath('data.allergies.0.status', 'discarded')
            ->assertJsonPath('data.allergies.0.discard_reason', 'Teste de provocação negativo');

        // Descartada fica no histórico (nunca apagada).
        expect(PatientAllergy::query()->count())->toBe(1);
    });

    it('recepção corrige a informada, mas não a confirmada pelo médico; descartada não muda', function () {
        cs2As($this, $this->secretary)->postJson(route('panel.patients.allergies.store', $this->patient), cs2Allergy())->assertCreated();
        $id = PatientAllergy::query()->value('id');

        cs2As($this, $this->secretary)
            ->putJson(route('panel.patients.allergies.update', [$this->patient, $id]), cs2Allergy(['reaction' => 'Edema de glote', 'severity' => 'severe']))
            ->assertOk()
            ->assertJsonPath('data.allergies.0.reaction', 'Edema de glote')
            ->assertJsonPath('data.allergies.0.status', 'reported');

        cs2As($this, $this->docEu)->postJson(route('panel.patients.allergies.confirm', [$this->patient, $id]))->assertOk();

        cs2As($this, $this->secretary)
            ->putJson(route('panel.patients.allergies.update', [$this->patient, $id]), cs2Allergy(['reaction' => 'Mudou']))
            ->assertForbidden();

        cs2As($this, $this->docEu)
            ->putJson(route('panel.patients.allergies.update', [$this->patient, $id]), cs2Allergy(['reaction' => 'Corrigida pelo médico']))
            ->assertOk()
            ->assertJsonPath('data.allergies.0.reaction', 'Corrigida pelo médico');

        cs2As($this, $this->docEu)->postJson(route('panel.patients.allergies.discard', [$this->patient, $id]))->assertOk();
        cs2As($this, $this->docEu)
            ->putJson(route('panel.patients.allergies.update', [$this->patient, $id]), cs2Allergy())
            ->assertUnprocessable();
    });

    it('mesma substância ativa não duplica (sem acento/maiúsculas) — o alerta não repete', function () {
        cs2As($this, $this->secretary)->postJson(route('panel.patients.allergies.store', $this->patient), cs2Allergy(['substance' => 'Látex']))->assertCreated();

        cs2As($this, $this->secretary)
            ->postJson(route('panel.patients.allergies.store', $this->patient), cs2Allergy(['substance' => 'LATEX']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('substance');

        // Descartada não conta: pode registrar de novo.
        $id = PatientAllergy::query()->value('id');
        cs2As($this, $this->docEu)->postJson(route('panel.patients.allergies.discard', [$this->patient, $id]))->assertOk();
        cs2As($this, $this->secretary)->postJson(route('panel.patients.allergies.store', $this->patient), cs2Allergy(['substance' => 'latex']))->assertCreated();

        // E a descartada não volta a ficar ativa por cima da nova.
        cs2As($this, $this->docEu)->postJson(route('panel.patients.allergies.confirm', [$this->patient, $id]))->assertUnprocessable();
    });

    it('valida categoria, gravidade e substância', function () {
        cs2As($this, $this->secretary)
            ->postJson(route('panel.patients.allergies.store', $this->patient), ['category' => 'xyz', 'substance' => '', 'severity' => 'fatal'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category', 'substance', 'severity']);
    });
});

describe('tenant e acesso', function () {
    it('paciente de outra clínica: 404; alergia de outro paciente: 404', function () {
        cs2As($this, $this->secretary)
            ->postJson(route('panel.patients.allergies.store', $this->otherPatient), cs2Allergy())
            ->assertNotFound();
        cs2As($this, $this->secretary)->getJson(route('panel.patients.clinical-safety.show', $this->otherPatient))->assertNotFound();

        $foreign = PatientAllergy::query()->create([
            ...cs2Allergy(), 'entity_id' => $this->other->id, 'patient_id' => $this->otherPatient->id, 'status' => 'reported',
        ]);

        cs2As($this, $this->docEu)
            ->postJson(route('panel.patients.allergies.confirm', [$this->patient, $foreign->id]))
            ->assertNotFound();
        expect($foreign->fresh()->status)->toBe(AllergyStatus::Reported);
    });

    it('perfil sem acesso a Pacientes não registra nem lê', function () {
        $user = createEntityUser($this->entity, User::factory()->create(), ClientRule::User->value);

        cs2As($this, $user)->getJson(route('panel.patients.clinical-safety.show', $this->patient))->assertForbidden();
        cs2As($this, $user)->postJson(route('panel.patients.allergies.store', $this->patient), cs2Allergy())->assertForbidden();

        expect(PatientAllergy::query()->count())->toBe(0);
    });

    it('leitura da situação vai para o log de acesso (dado sensível)', function () {
        cs2As($this, $this->secretary)->getJson(route('panel.patients.clinical-safety.show', $this->patient))->assertOk();

        expect(DataAccessLog::query()->where('patient_id', $this->patient->id)->count())->toBe(1);
    });
});

describe('"nega alergias"', function () {
    it('diferencia "não perguntado" de "nega"; guarda quem/quando; some ao registrar alergia', function () {
        cs2As($this, $this->secretary)->getJson(route('panel.patients.clinical-safety.show', $this->patient))
            ->assertJsonPath('data.allergy_state', 'unknown');

        cs2As($this, $this->secretary)
            ->putJson(route('panel.patients.allergy-status.update', $this->patient), ['denied' => true])
            ->assertOk()
            ->assertJsonPath('data.allergy_state', 'denied')
            ->assertJsonPath('data.denied.by', 'RITA RECEPÇÃO');

        expect($this->patient->fresh()->allergies_denied_by)->toBe($this->secretary->user_id);

        cs2As($this, $this->docEu)->postJson(route('panel.patients.allergies.store', $this->patient), cs2Allergy())
            ->assertCreated()
            ->assertJsonPath('data.allergy_state', 'has')
            ->assertJsonPath('data.denied', null);

        expect($this->patient->fresh()->allergies_denied_at)->toBeNull();
    });

    it('com alergia ativa não marca "nega" (422)', function () {
        cs2As($this, $this->secretary)->postJson(route('panel.patients.allergies.store', $this->patient), cs2Allergy())->assertCreated();

        cs2As($this, $this->secretary)
            ->putJson(route('panel.patients.allergy-status.update', $this->patient), ['denied' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('denied');
    });

    it('paciente novo: alergias e "nega" vão junto com o cadastro, como informadas', function () {
        $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id]);

        $payload = [
            'covenant_id' => $covenant->id, 'name' => 'NOVO PACIENTE', 'national_registry' => '52998224725', 'birth_date' => '1980-01-01',
            'gender'      => 1, 'marital_status' => 1, 'email' => 'novo@example.test', 'cellphone' => '61999990000', 'whatsapp' => false,
            'allergies'   => [cs2Allergy(['substance' => 'Iodo', 'category' => 'antiseptic'])],
            'medications' => [['name' => 'Tansulosina 0,4 mg', 'active_ingredient' => 'tansulosina']],
        ];

        cs2As($this, $this->secretary)->postJson(route('panel.patients.store'), $payload)->assertOk();

        $patient = Patient::query()->whereHas('person', fn ($q) => $q->where('full_name', 'NOVO PACIENTE'))->sole();

        expect($patient->allergies()->sole())
            ->status->toBe(AllergyStatus::Reported)
            ->substance->toBe('Iodo')
            ->and($patient->medications()->sole()->confirmed_at)->toBeNull();
    });
});

describe('cadastro: erro de item clínico não cai no campo errado', function () {
    it('medicação duplicada DENTRO do próprio cadastro dá erro indexado ("medications.1.name"), não no nome do paciente (10ª revisão Codex, P2)', function () {
        $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id]);

        $payload = [
            'covenant_id' => $covenant->id, 'name' => 'OUTRO PACIENTE', 'national_registry' => '52998224725', 'birth_date' => '1980-01-01',
            'gender'      => 1, 'marital_status' => 1, 'email' => 'outro@example.test', 'cellphone' => '61999990001', 'whatsapp' => false,
            'medications' => [
                ['name' => 'Tansulosina 0,4 mg', 'active_ingredient' => 'tansulosina'],
                ['name' => 'Tansulosina 0,4 mg', 'active_ingredient' => 'tansulosina'], // mesmo item, de novo
            ],
        ];

        cs2As($this, $this->secretary)->postJson(route('panel.patients.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('medications.1.name')
            ->assertJsonMissingValidationErrors('name');

        // Erro no meio do cadastro desfaz tudo — paciente não fica pela metade.
        expect(Patient::query()->whereHas('person', fn ($q) => $q->where('full_name', 'OUTRO PACIENTE'))->exists())->toBeFalse();
    });
});

describe('auditoria', function () {
    it('criar, confirmar e descartar vão para audit_logs com o motivo', function () {
        cs2As($this, $this->secretary)->postJson(route('panel.patients.allergies.store', $this->patient), cs2Allergy())->assertCreated();
        $id = PatientAllergy::query()->value('id');
        cs2As($this, $this->docEu)->postJson(route('panel.patients.allergies.confirm', [$this->patient, $id]))->assertOk();
        cs2As($this, $this->docEu)->postJson(route('panel.patients.allergies.discard', [$this->patient, $id]), ['reason' => 'Erro de cadastro'])->assertOk();

        $logs = AuditLog::query()->where('auditable_type', PatientAllergy::class)->where('auditable_id', $id)->orderBy('created_at')->get();

        expect($logs->pluck('event')->all())->toBe(['created', 'updated', 'updated'])
            ->and($logs[0]->user_id)->toBe($this->secretary->user_id)
            ->and($logs[1]->new_values['status'])->toBe('confirmed')
            ->and($logs[2]->new_values['discard_reason'])->toBe('Erro de cadastro')
            ->and($logs[2]->user_id)->toBe($this->docEu->user_id);
    });
});

describe('alerta para quem atende', function () {
    beforeEach(function () {
        PatientAllergy::query()->create([
            ...cs2Allergy(['severity' => 'severe']), 'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'status' => 'confirmed',
        ]);
        $this->schedule = Schedule::create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id,
            'full_name' => 'MARIA ALERGICA', 'date_time' => Carbon::parse('2026-10-07 10:00'),
            'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
        ]);
        $this->noAnswer = cs2Patient($this->entity, 'SEM REGISTRO');
        Schedule::create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'patient_id' => $this->noAnswer->id,
            'full_name' => 'SEM REGISTRO', 'date_time' => Carbon::parse('2026-10-07 11:00'),
            'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
        ]);
    });

    it('card da Agenda: alergia com nome/gravidade; "sem registro" quando ninguém perguntou', function () {
        $rows = [];
        cs2As($this, $this->secretary)->get(route('panel.schedules.index', ['date' => '2026-10-07']))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$rows) {
                $rows = collect($page->toArray()['props']['scheduleItems'])->where('type', 'schedule')->keyBy('name')->all();
            });

        expect($rows['MARIA ALERGICA']['allergy'])->toBe(['state' => 'has', 'items' => [['substance' => 'Dipirona', 'severe' => true]], 'severe' => true])
            ->and($rows['SEM REGISTRO']['allergy']['state'])->toBe('unknown');
    });

    it('drawer da Agenda traz as alergias ativas e registra o acesso', function () {
        cs2As($this, $this->secretary)->getJson(route('panel.schedules.show', $this->schedule))
            ->assertOk()
            ->assertJsonPath('data.clinical_safety.allergy_state', 'has')
            ->assertJsonPath('data.clinical_safety.allergies.0.substance', 'Dipirona')
            ->assertJsonPath('data.clinical_safety.allergies.0.severity', 'severe');

        expect(DataAccessLog::query()->where('patient_id', $this->patient->id)->exists())->toBeTrue();
    });

    it('pronto atendimento: alerta na fila', function () {
        UrgentCareEntry::query()->create([
            'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id,
            'reason'    => UrgentCareReason::cases()[0]->value, 'priority' => UrgentCarePriority::Normal->value,
            'status'    => UrgentCareStatus::Waiting->value, 'arrived_at' => now(),
        ]);

        cs2As($this, $this->secretary)->get(route('panel.urgent-care.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('queue.0.allergy.state', 'has')
                ->where('queue.0.allergy.items.0.substance', 'Dipirona'));
    });

    it('perfil sem acesso a Pacientes não recebe a lista (Agenda, drawer, prontuário, pronto atendimento)', function () {
        $this->app->instance(PatientAccess::class, new class() extends PatientAccess {
            public function current(): bool
            {
                return false;
            }
        });

        cs2As($this, $this->secretary)->get(route('panel.schedules.index', ['date' => '2026-10-07']))
            ->assertInertia(function (AssertableInertia $page) {
                foreach (collect($page->toArray()['props']['scheduleItems'])->where('type', 'schedule') as $row) {
                    expect($row['allergy'])->toBeNull();
                }
            });

        cs2As($this, $this->secretary)->getJson(route('panel.schedules.show', $this->schedule))
            ->assertJsonPath('data.clinical_safety', null);

        cs2As($this, $this->docEu)->get(route('panel.patients.medicalrecords.create', $this->patient))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('clinicalSafety', null));

        UrgentCareEntry::query()->create([
            'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id,
            'reason'    => UrgentCareReason::cases()[0]->value, 'priority' => UrgentCarePriority::Normal->value,
            'status'    => UrgentCareStatus::Waiting->value, 'arrived_at' => now(),
        ]);
        cs2As($this, $this->secretary)->get(route('panel.urgent-care.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('queue.0.allergy', null));
    });

    it('cabeçalho do prontuário: alergias, estado e dados da checagem do receituário (só para quem emite)', function () {
        cs2As($this, $this->docEu)->get(route('panel.patients.medicalrecords.create', $this->patient))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clinicalSafety.allergy_state', 'has')
                ->where('clinicalSafety.allergies.0.substance', 'Dipirona')
                ->where('clinicalSafety.can_review', true)
                ->where('clinicalSafety.check.allergies.0.terms', ['dipirona']));

        $admin = createEntityUser($this->entity, User::factory()->create(), ClientRule::Admin->value);
        cs2As($this, $admin)->get(route('panel.patients.medicalrecords.index', $this->patient))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clinicalSafety.allergy_state', 'has')
                ->where('clinicalSafety.can_review', false)
                ->where('clinicalSafety.check', null));
    });
});

describe('ficha impressa do paciente (Agenda)', function () {
    it('traz a linha de alergia em destaque; sem registro quando ninguém perguntou', function () {
        $schedule = Schedule::create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id,
            'full_name' => 'MARIA ALERGICA', 'date_time' => Carbon::parse('2026-10-07 10:00'),
            'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
        ]);
        $html = null;
        SnappyPdf::shouldReceive('loadView')->andReturnUsing(function (string $view, array $data) use (&$html) {
            $html = view($view, $data)->render();

            return new class() {
                public function __call($method, $args)
                {
                    return $method === 'inline' ? response('pdf') : $this;
                }
            };
        });

        cs2As($this, $this->secretary)->get(route('panel.schedules.patient-sheet', $schedule))->assertOk();
        expect($html)->toContain('Sem registro de alergias');

        PatientAllergy::query()->create([
            ...cs2Allergy(['severity' => 'severe']), 'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'status' => 'confirmed',
        ]);
        cs2As($this, $this->secretary)->get(route('panel.schedules.patient-sheet', $schedule))->assertOk();
        expect($html)->toContain('ALERGIA')->toContain('Dipirona (Urticária; Grave / anafilaxia; Confirmada pelo médico)');
    });
});

describe('agenda impressa', function () {
    it('marca quem TEM alergia (com gravidade); perfil sem acesso a Pacientes não recebe', function () {
        PatientAllergy::query()->create([
            ...cs2Allergy(['severity' => 'severe']), 'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'status' => 'confirmed',
        ]);
        Schedule::create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id,
            'full_name' => 'MARIA ALERGICA', 'date_time' => Carbon::parse('2026-10-07 10:00'),
            'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
        ]);
        $data = null;
        SnappyPdf::shouldReceive('loadView')->andReturnUsing(function (string $view, array $d) use (&$data) {
            $data = $d;

            return new class() {
                public function __call($method, $args)
                {
                    return $method === 'inline' ? response('pdf') : $this;
                }
            };
        });

        cs2As($this, $this->secretary)->get(route('panel.schedules.print', ['date' => '2026-10-07']))->assertOk();
        expect($data['rows']->firstWhere('name', 'MARIA ALERGICA')['allergy'])->toBe('ALERGIA: Dipirona (grave)');
        expect(view('pdf.schedule_agenda', $data)->render())->toContain('ALERGIA: Dipirona (grave)');

        $this->app->instance(PatientAccess::class, new class() extends PatientAccess {
            public function current(): bool
            {
                return false;
            }
        });
        cs2As($this, $this->secretary)->get(route('panel.schedules.print', ['date' => '2026-10-07']))->assertOk();
        expect($data['rows']->firstWhere('name', 'MARIA ALERGICA')['allergy'])->toBeNull();
    });
});
