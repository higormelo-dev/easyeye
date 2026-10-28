<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DocumentationType};
use App\Exceptions\LockedMedicalRecordException;
use App\Models\{AuditLog, Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, People, User};
use App\Notifications\RequestingDoctorReportReady;
use Illuminate\Support\Facades\Notification;

/**
 * Reenvio ao médico solicitante (Fase 5) — ExamManagerReportResendController.
 * Gate::SendReportToExternalDoctor (admin/doctor, mesmo allowlist de
 * ShareLaudoWithPatient — decisão de expor laudo pra fora não é da secretária).
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->doctorUserModel  = User::factory()->create();
    $this->doctorEntityUser = createEntityUser($this->entity, $this->doctorUserModel, ClientRule::Doctor->value);
    $this->doctor           = Doctor::query()->create([
        'entity_user_id' => $this->doctorEntityUser->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

    $this->secretaryUser       = User::factory()->create();
    $this->secretaryEntityUser = createEntityUser($this->entity, $this->secretaryUser, ClientRule::Secretary->value);

    $this->adminUser       = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->adminUser, ClientRule::Admin->value);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->record  = MedicalRecord::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctor->id,
    ]);

    $this->documentation = MedicalRecordDocumentation::create([
        'medical_record_id'       => $this->record->id,
        'patient_id'              => $this->patient->id,
        'doctor_id'               => $this->doctor->id,
        'type'                    => DocumentationType::Report->value,
        'content'                 => '<p>Achados normais.</p>',
        'requesting_doctor_name'  => 'Dr. Carlos Souza',
        'requesting_doctor_email' => 'carlos.souza@outraclinica.com',
    ]);

    // Só prontuário ASSINADO (imutável) libera o laudo pra fora da clínica —
    // mesma regra do compartilhamento com o paciente. O documento nasce
    // ANTES da assinatura: depois dela, GuardedBySignedRecord recusa filho novo.
    $this->record->sign($this->doctorEntityUser);
});

/** Prontuário + laudo com e-mail de solicitante que NÃO foram assinados. */
function unsignedRecordWithReport($test, bool $lockedOnly = false): MedicalRecordDocumentation
{
    $record = MedicalRecord::create([
        'entity_id'  => $test->entity->id,
        'patient_id' => $test->patient->id,
        'doctor_id'  => $test->doctor->id,
    ]);

    $documentation = MedicalRecordDocumentation::create([
        'medical_record_id'       => $record->id,
        'patient_id'              => $test->patient->id,
        'doctor_id'               => $test->doctor->id,
        'type'                    => DocumentationType::Report->value,
        'content'                 => '<p>Ainda sem assinatura.</p>',
        'requesting_doctor_name'  => 'Dr. Carlos Souza',
        'requesting_doctor_email' => 'carlos.souza@outraclinica.com',
    ]);

    if ($lockedOnly) {
        // Travado mas SEM assinatura (sem signed_at/signature_hash): não é "assinado".
        $record->forceFill(['is_locked' => true])->saveQuietly();
    }

    return $documentation;
}

function actingAsResendDoctor($test)
{
    return $test->actingAs($test->doctorUserModel)->withSession(panelSession($test->doctorEntityUser));
}

it('médico reenvia o laudo ao médico solicitante', function () {
    Notification::fake();

    actingAsResendDoctor($this)
        ->postJson(route('panel.exam-manager.reports.resend', $this->documentation))
        ->assertOk()
        ->assertJsonStructure(['requesting_doctor_sent_at']);

    Notification::assertSentOnDemand(
        RequestingDoctorReportReady::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'carlos.souza@outraclinica.com',
    );

    expect($this->documentation->refresh()->requesting_doctor_sent_at)->not->toBeNull();
});

it('admin reenvia o laudo ao médico solicitante', function () {
    Notification::fake();

    $this->actingAs($this->adminUser)
        ->withSession(panelSession($this->adminEntityUser))
        ->postJson(route('panel.exam-manager.reports.resend', $this->documentation))
        ->assertOk();

    Notification::assertSentOnDemandTimes(RequestingDoctorReportReady::class, 1);
});

it('[GAP] envio falha (SMTP indisponível) devolve 502 e nunca marca sent_at como enviado', function () {
    // sendNow() (nunca ->notify()/ShouldQueue — ver RequestingDoctorReportReady)
    // é quem de fato manda o e-mail aqui; reenviar é um comando explícito de
    // "mandar AGORA", então o botão só pode voltar "Enviado" se o canal mail
    // de fato confirmou. Sem isto, o endpoint marcava sent_at mesmo com o
    // envio tendo falhado de verdade (achado via revisão Codex).
    Notification::shouldReceive('sendNow')
        ->once()
        ->andThrow(new RuntimeException('SMTP indisponível (teste)'));

    actingAsResendDoctor($this)
        ->postJson(route('panel.exam-manager.reports.resend', $this->documentation))
        ->assertStatus(502)
        ->assertJsonStructure(['message']);

    expect($this->documentation->refresh()->requesting_doctor_sent_at)->toBeNull();
});

it('[GAP] o envio acontece com o prontuário ASSINADO: sent_at é gravado (saveQuietly passa pelo guard) e a trilha de auditoria registra', function () {
    // GuardedBySignedRecord bloqueia QUALQUER update() comum no documento
    // quando o prontuário dono está assinado (CFM 2.227/2018) — update()
    // de requesting_doctor_sent_at caía nesse guard e lançava 403 mesmo o
    // e-mail JÁ TENDO SIDO ENVIADO (sendNow() acima confirma antes de
    // gravar), fazendo a tela mostrar "falha no reenvio" e incentivar
    // reenviar de novo — e-mail duplicado pro médico solicitante (achado
    // via revisão Codex). Agora o envio SÓ existe com o prontuário assinado
    // (beforeEach), então este é o caminho normal, não a exceção.
    Notification::fake();
    expect($this->record->refresh()->isSigned())->toBeTrue();

    actingAsResendDoctor($this)
        ->postJson(route('panel.exam-manager.reports.resend', $this->documentation))
        ->assertOk()
        ->assertJsonStructure(['requesting_doctor_sent_at']);

    Notification::assertSentOnDemandTimes(RequestingDoctorReportReady::class, 1);
    expect($this->documentation->refresh()->requesting_doctor_sent_at)->not->toBeNull();

    // saveQuietly() pula o Auditable automático — confirma que o log manual
    // (ExamManagerReportResendController) realmente gravou a trilha.
    $audit = AuditLog::query()
        ->where('auditable_type', MedicalRecordDocumentation::class)
        ->where('auditable_id', $this->documentation->id)
        ->where('event', 'updated')
        ->get()
        ->first(fn (AuditLog $log): bool => array_key_exists('requesting_doctor_sent_at', $log->new_values ?? []));

    expect($audit)->not->toBeNull();
});

it('[SEGURANÇA] secretária não pode reenviar (Gate SendReportToExternalDoctor)', function () {
    Notification::fake();

    $this->actingAs($this->secretaryUser)
        ->withSession(panelSession($this->secretaryEntityUser))
        ->postJson(route('panel.exam-manager.reports.resend', $this->documentation))
        ->assertForbidden();

    Notification::assertNothingSent();
});

it('sem e-mail de solicitante cadastrado, devolve 422', function () {
    // Prontuário assinado = imutável: o campo é mexido pelo canal de bookkeeping (saveQuietly).
    $this->documentation->forceFill(['requesting_doctor_email' => null])->saveQuietly();
    Notification::fake();

    actingAsResendDoctor($this)
        ->postJson(route('panel.exam-manager.reports.resend', $this->documentation))
        ->assertStatus(422);

    Notification::assertNothingSent();
});

it('[SEGURANÇA] laudo de outra clínica devolve 404', function () {
    $otherEntity   = Entity::factory()->create(['is_client' => true]);
    $otherDoctorEu = createEntityUser($otherEntity, User::factory()->create(), ClientRule::Doctor->value);
    $otherDoctor   = Doctor::query()->create([
        'entity_user_id' => $otherDoctorEu->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);
    $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
    $otherRecord  = MedicalRecord::create(['entity_id' => $otherEntity->id, 'patient_id' => $otherPatient->id, 'doctor_id' => $otherDoctor->id]);
    $otherDoc     = MedicalRecordDocumentation::create([
        'medical_record_id'       => $otherRecord->id,
        'patient_id'              => $otherPatient->id,
        'doctor_id'               => $otherDoctor->id,
        'type'                    => DocumentationType::Report->value,
        'content'                 => '<p>De outra clínica.</p>',
        'requesting_doctor_email' => 'alguem@fora.com',
    ]);

    actingAsResendDoctor($this)
        ->postJson(route('panel.exam-manager.reports.resend', $otherDoc))
        ->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// Laudo só sai da clínica com o prontuário ASSINADO (achado via revisão
// adversarial Codex, 15ª rodada): antes o e-mail com o link público saía na
// emissão, com o prontuário ainda editável e o documento ainda excluível — o
// médico solicitante ficava com um laudo que a clínica podia mudar ou apagar.
// ─────────────────────────────────────────────────────────────────────────────

it('[SEGURANÇA] prontuário NÃO assinado: o envio é recusado (422), nada sai e sent_at continua nulo', function () {
    Notification::fake();
    $documentation = unsignedRecordWithReport($this);

    actingAsResendDoctor($this)
        ->postJson(route('panel.exam-manager.reports.resend', $documentation))
        ->assertUnprocessable()
        ->assertJsonPath('message', __('eye_images.report_resend_record_not_signed'));

    Notification::assertNothingSent();
    expect($documentation->refresh()->requesting_doctor_sent_at)->toBeNull();
});

it('[SEGURANÇA] prontuário só travado (is_locked) mas SEM assinatura não libera o envio — vale a assinatura, não o cadeado', function () {
    Notification::fake();
    $documentation = unsignedRecordWithReport($this, lockedOnly: true);

    actingAsResendDoctor($this)
        ->postJson(route('panel.exam-manager.reports.resend', $documentation))
        ->assertUnprocessable();

    Notification::assertNothingSent();
});

it('[SEGURANÇA] o mesmo documento que era recusado antes da assinatura é enviado depois dela', function () {
    Notification::fake();
    $documentation = unsignedRecordWithReport($this);

    actingAsResendDoctor($this)->postJson(route('panel.exam-manager.reports.resend', $documentation))->assertUnprocessable();
    Notification::assertNothingSent();

    $documentation->medicalRecord->sign($this->doctorEntityUser);

    actingAsResendDoctor($this)->postJson(route('panel.exam-manager.reports.resend', $documentation))->assertOk();

    Notification::assertSentOnDemandTimes(RequestingDoctorReportReady::class, 1);
    expect($documentation->refresh()->requesting_doctor_sent_at)->not->toBeNull();
});

it('[SEGURANÇA] laudo ENTREGUE ao médico solicitante não pode mais ser excluído pelo endpoint clínico comum', function () {
    Notification::fake();

    actingAsResendDoctor($this)->postJson(route('panel.exam-manager.reports.resend', $this->documentation))->assertOk();
    expect($this->documentation->refresh()->requesting_doctor_sent_at)->not->toBeNull();

    actingAsResendDoctor($this)
        ->deleteJson(route('panel.patients.medicalrecords.documentations.destroy', [$this->patient, $this->record, $this->documentation]))
        ->assertForbidden();

    expect(MedicalRecordDocumentation::query()->whereKey($this->documentation->id)->exists())->toBeTrue();

    // Nem pelo model (outro caminho qualquer que use delete()).
    expect(fn () => $this->documentation->refresh()->delete())->toThrow(LockedMedicalRecordException::class);
    expect(MedicalRecordDocumentation::query()->whereKey($this->documentation->id)->exists())->toBeTrue();
});
