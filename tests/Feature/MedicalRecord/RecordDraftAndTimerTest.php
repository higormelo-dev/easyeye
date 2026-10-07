<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ScheduleSituation};
use App\Http\Requests\StoreMedicalRecordRequest;
use App\Models\{AuditLog, Covenant, Doctor, Entity, EntityUser, MedicalRecord, MedicalRecordDraft, Patient, People, RecordVersion, Schedule, ScheduleSituationLog, User};
use App\Services\MedicalRecords\MedicalRecordDraftService;
use App\Services\{MedicalRecordSignatureService, ScheduleService};
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule as ConsoleSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 * Fase 1 / B2 — prontuário: autosave (rascunho separado no servidor) e o
 * cronômetro/confirmação de finalizar (props do fluxo).
 *
 * Rascunho: só do autor e da clínica dele; criptografado; nunca em prontuário
 * assinado; apagado ao salvar/finalizar e pela limpeza agendada (7 dias); não
 * gera versão nem auditoria do prontuário a cada gravação.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-06 10:30:00'));

    $this->entity = Entity::factory()->create(['is_client' => true]);
    $this->other  = Entity::factory()->create(['is_client' => true]);

    [$this->docUser, $this->docEu, $this->doctor]    = rdtDoctor($this->entity);
    [$this->doc2User, $this->doc2Eu, $this->doctor2] = rdtDoctor($this->entity);

    $this->patient      = rdtPatient($this->entity);
    $this->otherPatient = rdtPatient($this->entity);

    $this->schedule = Schedule::query()->create([
        'entity_id'  => $this->entity->id,
        'doctor_id'  => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'full_name'  => 'MARIA DA SILVA',
        'date_time'  => '2026-10-06 10:00:00',
        'situation'  => ScheduleSituation::Waiting->value,
        'arrived_at' => '2026-10-06 09:50:00',
        'active'     => true,
    ]);

    $this->record = MedicalRecord::create([
        'entity_id'      => $this->entity->id,
        'patient_id'     => $this->patient->id,
        'doctor_id'      => $this->doctor->id,
        'schedule_id'    => $this->schedule->id,
        'main_complaint' => 'Baixa visual',
    ]);
    // Prontuário salvo 10 min atrás (o rascunho de agora é mais novo).
    DB::table('medical_records')->where('id', $this->record->id)->update(['updated_at' => '2026-10-06 10:20:00']);
    $this->record->refresh();
});

function rdtDoctor(Entity $entity, bool $active = true): array
{
    $user = User::factory()->create();
    $eu   = createEntityUser($entity, $user, ClientRule::Doctor->value);

    return [$user, $eu, Doctor::create([
        'entity_user_id' => $eu->id,
        'person_id'      => People::factory()->create()->id,
        'record'         => (string) random_int(10000, 99999),
        'active'         => $active,
    ])];
}

function rdtPatient(Entity $entity): Patient
{
    return Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create(['full_name' => 'MARIA DA SILVA'])->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);
}

function rdtAs(object $t, User $user, EntityUser $eu): object
{
    return $t->actingAs($user)->withSession(panelSession($eu));
}

function rdtUrl(Patient $patient, array $query = []): string
{
    return route('panel.patients.medicalrecord-draft.save', $patient) . ($query ? '?' . http_build_query($query) : '');
}

function rdtProps(object $t, User $user, EntityUser $eu, string $url): array
{
    $props = null;
    rdtAs($t, $user, $eu)->get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return $props;
}

describe('autosave — gravar, ler, só o autor', function () {
    it('o médico grava o rascunho do prontuário em edição; vai criptografado e só com campos do prontuário', function () {
        rdtAs($this, $this->docUser, $this->docEu)
            ->putJson(rdtUrl($this->patient), [
                'medical_record_id' => $this->record->id,
                'payload'           => [
                    'main_complaint'   => 'Dor ocular intensa OD há 2 dias',
                    'clinical_conduct' => 'Colírio X 4x/dia',
                    'diagnosis_cids'   => [['code' => 'H10', 'description' => 'Conjuntivite']],
                    'doctor_id'        => $this->doctor2->id, // fora do rascunho
                    'qualquer_coisa'   => 'lixo',
                ],
            ])
            ->assertOk()
            ->assertJsonStructure(['saved_at', 'saved_at_label']);

        $draft = MedicalRecordDraft::query()->sole();
        expect($draft->user_id)->toBe($this->docUser->id)
            ->and($draft->entity_id)->toBe($this->entity->id)
            ->and($draft->context_key)->toBe('record:' . $this->record->id)
            ->and($draft->payload)->toBe([
                'main_complaint'   => 'Dor ocular intensa OD há 2 dias',
                'clinical_conduct' => 'Colírio X 4x/dia',
                'diagnosis_cids'   => [['code' => 'H10', 'description' => 'Conjuntivite']],
            ]);

        $raw = DB::table('medical_record_drafts')->value('payload');
        expect($raw)->not->toContain('Dor ocular')
            ->and($raw)->not->toContain('H10');

        rdtAs($this, $this->docUser, $this->docEu)
            ->getJson(rdtUrl($this->patient, ['medical_record_id' => $this->record->id]))
            ->assertOk()
            ->assertJsonPath('payload.main_complaint', 'Dor ocular intensa OD há 2 dias');
    });

    it('grava de novo a mesma tela: um rascunho só, atualizado', function () {
        foreach (['primeira versão', 'segunda versão'] as $text) {
            rdtAs($this, $this->docUser, $this->docEu)->putJson(rdtUrl($this->patient), [
                'medical_record_id' => $this->record->id,
                'payload'           => ['hda' => $text],
            ])->assertOk();
        }

        expect(MedicalRecordDraft::query()->count())->toBe(1)
            ->and(MedicalRecordDraft::query()->sole()->payload)->toBe(['hda' => 'segunda versão']);
    });

    it('rascunho de um médico nunca aparece para outro nem para outra clínica', function () {
        rdtAs($this, $this->docUser, $this->docEu)->putJson(rdtUrl($this->patient), [
            'medical_record_id' => $this->record->id,
            'payload'           => ['hda' => 'só meu'],
        ])->assertOk();

        rdtAs($this, $this->doc2User, $this->doc2Eu)
            ->getJson(rdtUrl($this->patient, ['medical_record_id' => $this->record->id]))
            ->assertNotFound();
        rdtAs($this, $this->doc2User, $this->doc2Eu)
            ->deleteJson(rdtUrl($this->patient, ['medical_record_id' => $this->record->id]))
            ->assertNoContent();
        expect(MedicalRecordDraft::query()->count())->toBe(1); // o DELETE do colega não apaga o meu

        // O mesmo usuário noutra clínica: o paciente nem existe lá.
        $euOther = createEntityUser($this->other, $this->docUser, ClientRule::Doctor->value);
        Doctor::create(['entity_user_id' => $euOther->id, 'person_id' => People::factory()->create()->id, 'active' => true]);
        rdtAs($this, $this->docUser, $euOther)
            ->getJson(rdtUrl($this->patient, ['medical_record_id' => $this->record->id]))
            ->assertNotFound();

        // E o edit do colega não oferece o meu rascunho.
        $props = rdtProps($this, $this->doc2User, $this->doc2Eu, route('panel.patients.medicalrecords.edit', [$this->patient, $this->record]));
        expect($props['draft'])->toBeNull();
    });

    it('só médico com cadastro ativo; contexto de outro paciente é recusado', function () {
        [$secUser]            = [User::factory()->create()];
        $secEu                = createEntityUser($this->entity, $secUser, ClientRule::Secretary->value);
        [$inUser, $inEu]      = rdtDoctor($this->entity, active: false);
        $otherPatientRecord   = MedicalRecord::create(['entity_id' => $this->entity->id, 'patient_id' => $this->otherPatient->id, 'doctor_id' => $this->doctor->id]);
        $otherPatientSchedule = Schedule::query()->create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'patient_id' => $this->otherPatient->id,
            'full_name' => 'OUTRO', 'date_time' => '2026-10-06 11:00:00', 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
        ]);
        $payload = ['payload' => ['hda' => 'x']];

        rdtAs($this, $secUser, $secEu)->putJson(rdtUrl($this->patient), $payload)->assertForbidden();
        rdtAs($this, $inUser, $inEu)->putJson(rdtUrl($this->patient), $payload)->assertForbidden();
        rdtAs($this, $this->docUser, $this->docEu)
            ->putJson(rdtUrl($this->patient), [...$payload, 'medical_record_id' => $otherPatientRecord->id])
            ->assertNotFound();
        rdtAs($this, $this->docUser, $this->docEu)
            ->putJson(rdtUrl($this->patient), [...$payload, 'schedule_id' => $otherPatientSchedule->id])
            ->assertNotFound();

        expect(MedicalRecordDraft::query()->count())->toBe(0);
    });

    it('conteúdo grande demais é recusado', function () {
        rdtAs($this, $this->docUser, $this->docEu)->putJson(rdtUrl($this->patient), [
            'payload' => ['observation_general' => str_repeat('a', MedicalRecordDraftService::MAX_PAYLOAD_BYTES + 10)],
        ])->assertStatus(413);

        expect(MedicalRecordDraft::query()->count())->toBe(0);
    });

    it('não gera versão nem auditoria do prontuário (nem mexe no updated_at dele)', function () {
        $audits   = AuditLog::query()->count();
        $versions = RecordVersion::query()->count();

        rdtAs($this, $this->docUser, $this->docEu)->putJson(rdtUrl($this->patient), [
            'medical_record_id' => $this->record->id,
            'payload'           => ['hda' => 'rascunho'],
        ])->assertOk();

        expect(AuditLog::query()->count())->toBe($audits)
            ->and(RecordVersion::query()->count())->toBe($versions)
            ->and($this->record->fresh()->updated_at->toDateTimeString())->toBe('2026-10-06 10:20:00');
    });
});

describe('autosave — oferta ao abrir, assinado, salvar e limpeza', function () {
    it('edit oferece o rascunho mais novo que o prontuário; o mais velho não', function () {
        rdtAs($this, $this->docUser, $this->docEu)->putJson(rdtUrl($this->patient), [
            'medical_record_id' => $this->record->id,
            'payload'           => ['hda' => 'novo'],
        ])->assertOk();

        $props = rdtProps($this, $this->docUser, $this->docEu, route('panel.patients.medicalrecords.edit', [$this->patient, $this->record]));
        expect($props['draft']['updated_at'])->toStartWith('2026-10-06T10:30:00')
            ->and($props['draft']['updated_at_label'])->toBe('06/10/2026 10:30')
            ->and($props['urls']['draft'])->toBe(route('panel.patients.medicalrecord-draft.save', $this->patient));

        // O prontuário foi salvo depois (por outra aba, por exemplo).
        $this->travel(5)->minutes();
        DB::table('medical_records')->where('id', $this->record->id)->update(['updated_at' => now()]);

        $props = rdtProps($this, $this->docUser, $this->docEu, route('panel.patients.medicalrecords.edit', [$this->patient, $this->record]));
        expect($props['draft'])->toBeNull();
    });

    it('novo atendimento pela Agenda: o rascunho é da tela do agendamento', function () {
        rdtAs($this, $this->docUser, $this->docEu)->putJson(rdtUrl($this->patient), [
            'schedule_id' => $this->schedule->id,
            'payload'     => ['main_complaint' => 'novo pela agenda'],
        ])->assertOk();

        expect(MedicalRecordDraft::query()->sole()->context_key)->toBe('schedule:' . $this->schedule->id);

        $props = rdtProps($this, $this->docUser, $this->docEu, route('panel.patients.medicalrecords.create', $this->patient) . '?schedule_id=' . $this->schedule->id);
        expect($props['draft'])->not->toBeNull();

        // Sem agendamento é outra tela (outro rascunho).
        $props = rdtProps($this, $this->docUser, $this->docEu, route('panel.patients.medicalrecords.create', $this->patient));
        expect($props['draft'])->toBeNull();
    });

    it('prontuário assinado: não aceita rascunho (423), não oferece e descarta o que havia', function () {
        rdtAs($this, $this->docUser, $this->docEu)->putJson(rdtUrl($this->patient), [
            'medical_record_id' => $this->record->id,
            'payload'           => ['hda' => 'antes de assinar'],
        ])->assertOk();

        DB::table('medical_records')->where('id', $this->record->id)->update(['is_locked' => true, 'signed_at' => now()]);

        rdtAs($this, $this->docUser, $this->docEu)->putJson(rdtUrl($this->patient), [
            'medical_record_id' => $this->record->id,
            'payload'           => ['hda' => 'depois'],
        ])->assertStatus(423);

        $props = rdtProps($this, $this->docUser, $this->docEu, route('panel.patients.medicalrecords.edit', [$this->patient, $this->record]));
        expect($props['draft'])->toBeNull()
            ->and(MedicalRecordDraft::query()->count())->toBe(0);
    });

    it('assinar pelo serviço apaga os rascunhos do prontuário (de qualquer autor)', function () {
        foreach ([[$this->docUser, $this->docEu], [$this->doc2User, $this->doc2Eu]] as [$user, $eu]) {
            rdtAs($this, $user, $eu)->putJson(rdtUrl($this->patient), [
                'medical_record_id' => $this->record->id,
                'payload'           => ['hda' => 'rascunho'],
            ])->assertOk();
        }

        app(MedicalRecordSignatureService::class)->sign($this->record, $this->docEu->fresh());

        expect(MedicalRecordDraft::query()->count())->toBe(0);
    });

    it('salvar o prontuário apaga o rascunho dele; o de outro paciente fica', function () {
        rdtAs($this, $this->docUser, $this->docEu)->putJson(rdtUrl($this->patient), [
            'medical_record_id' => $this->record->id,
            'payload'           => ['hda' => 'rascunho'],
        ])->assertOk();
        rdtAs($this, $this->docUser, $this->docEu)->putJson(rdtUrl($this->otherPatient), [
            'payload' => ['hda' => 'outro paciente'],
        ])->assertOk();

        rdtAs($this, $this->docUser, $this->docEu)
            ->put(route('panel.patients.medicalrecords.update', [$this->patient, $this->record]), [
                'doctor_id'      => $this->doctor->id,
                'main_complaint' => 'Salvo de verdade',
                'schedule_id'    => $this->schedule->id,
                'flow_action'    => 'save',
            ])
            ->assertSessionHasNoErrors();

        expect(MedicalRecordDraft::query()->pluck('context_key')->all())->toBe(['new']);
    });

    it('finalizar (novo atendimento pela Agenda) apaga o rascunho da tela do agendamento', function () {
        rdtAs($this, $this->docUser, $this->docEu)->putJson(rdtUrl($this->patient), [
            'schedule_id' => $this->schedule->id,
            'payload'     => ['main_complaint' => 'rascunho do novo'],
        ])->assertOk();

        rdtAs($this, $this->docUser, $this->docEu)
            ->post(route('panel.patients.medicalrecords.store', $this->patient), [
                'doctor_id'      => $this->doctor->id,
                'main_complaint' => 'Atendimento completo',
                'schedule_id'    => $this->schedule->id,
                'flow_action'    => 'finish',
            ])
            ->assertRedirect(route('panel.schedules.index'));

        expect(MedicalRecordDraft::query()->count())->toBe(0)
            ->and($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Attended);
    });

    it('a limpeza agendada apaga rascunhos com mais de 7 dias e mantém os recentes', function () {
        $make = fn (string $updatedAt, string $context) => DB::table('medical_record_drafts')->insert([
            'id'          => (string) Str::uuid(),
            'entity_id'   => $this->entity->id,
            'user_id'     => $this->docUser->id,
            'patient_id'  => $this->patient->id,
            'context_key' => $context,
            'payload'     => encrypt(json_encode(['hda' => 'x']), false),
            'created_at'  => $updatedAt,
            'updated_at'  => $updatedAt,
        ]);
        $make('2026-09-28 10:00:00', 'new');
        $make('2026-09-30 10:00:00', 'record:' . $this->record->id);

        $this->artisan('medical-records:prune-drafts')->assertSuccessful();

        expect(DB::table('medical_record_drafts')->pluck('context_key')->all())->toBe(['record:' . $this->record->id]);

        $events = collect(app(ConsoleSchedule::class)->events())->map(fn ($e) => $e->command ?? '');
        expect($events->contains(fn (string $c) => str_contains($c, 'medical-records:prune-drafts')))->toBeTrue();
    });

    it('os campos do rascunho são os do formulário do prontuário (sem médico/agendamento/fluxo)', function () {
        $keys = collect(array_keys((new StoreMedicalRecordRequest())->rules()))
            ->reject(fn (string $key) => str_contains($key, '.'))
            ->reject(fn (string $key) => in_array($key, ['doctor_id', 'schedule_id', 'flow_action', 'post_save_action'], true))
            ->sort()->values()->all();

        expect(collect(MedicalRecordDraftService::FIELDS)->sort()->values()->all())->toBe($keys);
    });
});

describe('cronômetro e finalizar (props do fluxo)', function () {
    it('início = 1ª vez "Em consulta"; atendido traz o fim; trava de caixa e nome vão para o modal', function () {
        $service = app(ScheduleService::class);
        $this->travelTo(Carbon::parse('2026-10-06 10:05:00'));
        $service->changeSituation($this->schedule, ScheduleSituation::InProgress, $this->docEu->id);
        $this->travelTo(Carbon::parse('2026-10-06 10:12:00'));
        $service->changeSituation($this->schedule, ScheduleSituation::Dilating, $this->docEu->id);
        $this->travelTo(Carbon::parse('2026-10-06 10:40:00'));
        $service->changeSituation($this->schedule, ScheduleSituation::InProgress, $this->docEu->id);

        $flow = rdtProps($this, $this->docUser, $this->docEu, route('panel.patients.medicalrecords.edit', [$this->patient, $this->record]))['scheduleFlow'];
        expect($flow['started_at'])->toStartWith('2026-10-06T10:05:00')
            ->and($flow['finished_at'])->toBeNull()
            ->and($flow['patient_name'])->toBe('MARIA DA SILVA')
            ->and($flow['cash_blocked'])->toBeFalse();

        $this->entity->forceFill(['requires_cash_to_complete' => true])->save();
        $flow = rdtProps($this, $this->docUser, $this->docEu, route('panel.patients.medicalrecords.edit', [$this->patient, $this->record]))['scheduleFlow'];
        expect($flow['cash_blocked'])->toBeTrue();

        $this->entity->forceFill(['requires_cash_to_complete' => false])->save();
        $this->travelTo(Carbon::parse('2026-10-06 10:58:00'));
        $service->changeSituation($this->schedule, ScheduleSituation::Attended, $this->docEu->id);

        $flow = rdtProps($this, $this->docUser, $this->docEu, route('panel.patients.medicalrecords.edit', [$this->patient, $this->record]))['scheduleFlow'];
        expect($flow['started_at'])->toStartWith('2026-10-06T10:05:00')
            ->and($flow['finished_at'])->toStartWith('2026-10-06T10:58:00')
            ->and($flow['cash_blocked'])->toBeFalse();
    });

    it('sem "Em consulta" ainda (abriu agora pela Agenda): sem início — a tela conta da abertura', function () {
        $flow = rdtProps($this, $this->docUser, $this->docEu, route('panel.patients.medicalrecords.create', $this->patient) . '?schedule_id=' . $this->schedule->id)['scheduleFlow'];

        expect($flow['started_at'])->toBeNull();
        expect(ScheduleSituationLog::query()->count())->toBe(0);
    });

    it('?schedule_id= de OUTRO paciente não liga fluxo nem cronômetro a esta tela', function () {
        $otherSchedule = Schedule::query()->create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'patient_id' => $this->otherPatient->id,
            'full_name' => 'OUTRO', 'date_time' => '2026-10-06 11:00:00', 'situation' => ScheduleSituation::InProgress->value, 'active' => true,
        ]);

        $props = rdtProps($this, $this->docUser, $this->docEu, route('panel.patients.medicalrecords.create', $this->patient) . '?schedule_id=' . $otherSchedule->id);

        expect($props['scheduleFlow'])->toBeNull();
    });
});
