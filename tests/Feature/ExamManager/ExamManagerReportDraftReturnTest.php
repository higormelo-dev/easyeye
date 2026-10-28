<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DraftStatus};
use App\Models\{Doctor, Entity, EntityUser, EyeImageReportDraft, Patient, PatientExam, People, User};
use App\Notifications\ImageReportDraftNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\{Event, Notification};

/**
 * Fluxo técnico → médico do laudo de imagem (Fase 5): o médico devolve o
 * rascunho enviado ao técnico com um motivo; o técnico corrige e reenvia.
 * A emissão continua só em ExamManagerReportController::store().
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
    $this->exam    = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
});

function asReturnFlowDoctor($test)
{
    return $test->actingAs($test->doctorUserModel)->withSession(panelSession($test->doctorEntityUser));
}

function asReturnFlowSecretary($test)
{
    return $test->actingAs($test->secretaryUser)->withSession(panelSession($test->secretaryEntityUser));
}

/** Rascunho preparado pela secretária (created_by dela) e já enviado ao médico. */
function submittedReturnFlowDraft($test, array $overrides = []): EyeImageReportDraft
{
    $draftId = asReturnFlowSecretary($test)->postJson(route('panel.exam-manager.report-drafts.store'), [
        'patient_id' => $test->patient->id,
        'exam_ids'   => [$test->exam->id],
        'content'    => '<p>Achados preliminares.</p>',
        ...$overrides,
    ])->assertCreated()->json('id');

    $draft = EyeImageReportDraft::query()->findOrFail($draftId);

    asReturnFlowSecretary($test)->postJson(route('panel.exam-manager.report-drafts.submit', $draft), [
        'draft_revision' => $draft->revision,
    ])->assertOk();

    return $draft->refresh();
}

it('médico devolve o rascunho enviado ao técnico, com o motivo', function () {
    $draft    = submittedReturnFlowDraft($this);
    $revision = $draft->revision;

    asReturnFlowDoctor($this)
        ->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
            'draft_revision' => $revision,
            'reason'         => 'Falta a imagem do OE.',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'returned')
        ->assertJsonPath('return_reason', 'Falta a imagem do OE.');

    $draft->refresh();
    expect($draft->status)->toBe(DraftStatus::Returned)
        ->and($draft->return_reason)->toBe('Falta a imagem do OE.')
        ->and($draft->returned_at)->not->toBeNull()
        ->and((string) $draft->returned_by)->toBe((string) $this->doctorUserModel->id)
        // quem estava com o rascunho aberto ainda "Enviado" não salva por cima sem ver o motivo
        ->and($draft->revision)->toBe($revision + 1)
        ->and($draft->isEditable())->toBeTrue();
});

it('[SEGURANÇA] só o médico devolve: secretária e admin recebem 403 e o rascunho não muda', function () {
    $draft = submittedReturnFlowDraft($this);

    asReturnFlowSecretary($this)
        ->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
            'draft_revision' => $draft->revision,
            'reason'         => 'Tentativa.',
        ])
        ->assertForbidden();

    $this->actingAs($this->adminUser)->withSession(panelSession($this->adminEntityUser))
        ->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
            'draft_revision' => $draft->revision,
            'reason'         => 'Tentativa.',
        ])
        ->assertForbidden();

    expect($draft->refresh()->status)->toBe(DraftStatus::Submitted)
        ->and($draft->return_reason)->toBeNull();
});

it('[SEGURANÇA] perfil médico sem cadastro de médico ativo não devolve (Gate IssueReport)', function () {
    $draft = submittedReturnFlowDraft($this);
    $this->doctor->update(['active' => false]);

    asReturnFlowDoctor($this)
        ->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
            'draft_revision' => $draft->revision,
            'reason'         => 'Sem cadastro ativo.',
        ])
        ->assertForbidden();

    expect($draft->refresh()->status)->toBe(DraftStatus::Submitted);
});

it('devolver exige o motivo (texto, não só marcação) e o token de revisão', function () {
    $draft = submittedReturnFlowDraft($this);
    $url   = route('panel.exam-manager.report-drafts.return', $draft);

    asReturnFlowDoctor($this)->postJson($url, ['draft_revision' => $draft->revision])
        ->assertStatus(422)->assertJsonValidationErrors('reason');

    asReturnFlowDoctor($this)->postJson($url, ['draft_revision' => $draft->revision, 'reason' => '   '])
        ->assertStatus(422);

    // só marcação: depois do strip_tags não sobra motivo
    asReturnFlowDoctor($this)->postJson($url, ['draft_revision' => $draft->revision, 'reason' => '<b></b>'])
        ->assertStatus(422);

    asReturnFlowDoctor($this)->postJson($url, ['reason' => 'Falta algo.'])
        ->assertStatus(422)->assertJsonValidationErrors('draft_revision');

    expect($draft->refresh()->status)->toBe(DraftStatus::Submitted);
});

it('o motivo é gravado como texto puro, sem marcação', function () {
    $draft = submittedReturnFlowDraft($this);

    asReturnFlowDoctor($this)->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
        'draft_revision' => $draft->revision,
        'reason'         => 'Revisar <script>alert(1)</script>a lateralidade.',
    ])->assertOk();

    expect($draft->refresh()->return_reason)->not->toContain('<script>')
        ->and($draft->return_reason)->toContain('a lateralidade');
});

it('[GAP] revisão obsoleta: o técnico salvou uma correção enquanto o médico devolvia → 409 e nada muda', function () {
    $draft         = submittedReturnFlowDraft($this);
    $staleRevision = $draft->revision;

    asReturnFlowSecretary($this)->putJson(route('panel.exam-manager.report-drafts.update', $draft), [
        'exam_ids'       => [$this->exam->id],
        'content'        => '<p>Correção do técnico.</p>',
        'draft_revision' => $staleRevision,
    ])->assertOk();

    asReturnFlowDoctor($this)->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
        'draft_revision' => $staleRevision,
        'reason'         => 'Falta algo.',
    ])->assertStatus(409);

    expect($draft->refresh()->status)->toBe(DraftStatus::Submitted);
});

it('só rascunho enviado ao médico pode ser devolvido (rascunho novo e emitido dão 422)', function () {
    $fresh = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'content'    => '<p>Ainda no técnico.</p>',
        'status'     => DraftStatus::Draft,
    ]);
    $issued = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'content'    => '<p>Já emitido.</p>',
        'status'     => DraftStatus::Issued,
    ]);

    foreach ([$fresh, $issued] as $draft) {
        asReturnFlowDoctor($this)->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
            'draft_revision' => $draft->revision,
            'reason'         => 'Não deveria.',
        ])->assertStatus(422);
    }

    expect($fresh->refresh()->status)->toBe(DraftStatus::Draft)
        ->and($issued->refresh()->status)->toBe(DraftStatus::Issued);
});

it('[GAP] relê sob lock: devolver duas vezes seguidas → a segunda é 422 (já não está enviado)', function () {
    $draft = submittedReturnFlowDraft($this);

    asReturnFlowDoctor($this)->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
        'draft_revision' => $draft->revision,
        'reason'         => 'Primeira.',
    ])->assertOk();

    $draft->refresh();
    asReturnFlowDoctor($this)->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
        'draft_revision' => $draft->revision,
        'reason'         => 'Segunda.',
    ])->assertStatus(422);

    expect($draft->refresh()->return_reason)->toBe('Primeira.');
});

it('[SEGURANÇA] rascunho de outra clínica não pode ser devolvido (404)', function () {
    $otherEntity  = Entity::factory()->create(['is_client' => true]);
    $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
    $otherDraft   = EyeImageReportDraft::create([
        'entity_id'  => $otherEntity->id,
        'patient_id' => $otherPatient->id,
        'content'    => '<p>De outra clínica.</p>',
        'status'     => DraftStatus::Submitted,
    ]);

    asReturnFlowDoctor($this)->postJson(route('panel.exam-manager.report-drafts.return', $otherDraft), [
        'draft_revision' => $otherDraft->revision,
        'reason'         => 'Não é meu.',
    ])->assertNotFound();

    expect($otherDraft->refresh()->status)->toBe(DraftStatus::Submitted);
});

it('o técnico corrige o devolvido (segue editável) e reenvia: volta a Enviado e o motivo some', function () {
    $draft = submittedReturnFlowDraft($this);
    asReturnFlowDoctor($this)->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
        'draft_revision' => $draft->revision,
        'reason'         => 'Falta a imagem do OE.',
    ])->assertOk();
    $draft->refresh();

    asReturnFlowSecretary($this)->putJson(route('panel.exam-manager.report-drafts.update', $draft), [
        'exam_ids'       => [$this->exam->id],
        'content'        => '<p>Achados com o OE.</p>',
        'draft_revision' => $draft->revision,
    ])->assertOk()->assertJsonPath('status', 'returned');

    $draft->refresh();
    expect($draft->return_reason)->toBe('Falta a imagem do OE.'); // editar não apaga o motivo

    asReturnFlowSecretary($this)->postJson(route('panel.exam-manager.report-drafts.submit', $draft), [
        'draft_revision' => $draft->revision,
    ])->assertOk()->assertJsonPath('status', 'submitted')->assertJsonPath('return_reason', null);

    $draft->refresh();
    expect($draft->status)->toBe(DraftStatus::Submitted)
        ->and($draft->return_reason)->toBeNull()
        ->and($draft->returned_at)->toBeNull()
        ->and($draft->returned_by)->toBeNull();
});

it('o médico ainda pode emitir um rascunho devolvido (continua editável)', function () {
    $draft = submittedReturnFlowDraft($this);
    asReturnFlowDoctor($this)->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
        'draft_revision' => $draft->revision,
        'reason'         => 'Revisar.',
    ])->assertOk();

    expect($draft->refresh()->isEditable())->toBeTrue()
        ->and($draft->canBeSubmitted())->toBeTrue();
});

// ── Avisos por e-mail ──────────────────────────────────────────────────────

it('enviar ao médico avisa o médico endereçado (o da pré-visualização), sem dado de paciente no e-mail', function () {
    Notification::fake();

    submittedReturnFlowDraft($this, ['resolved_doctor_id' => $this->doctor->id]);

    Notification::assertSentTo(
        $this->doctorUserModel,
        ImageReportDraftNotification::class,
        function (ImageReportDraftNotification $notification) {
            $mail    = $notification->toMail($this->doctorUserModel);
            $content = implode(' ', array_merge([$mail->subject], $mail->introLines, [$mail->actionUrl ?? '']));

            return $notification->event === ImageReportDraftNotification::SUBMITTED
                && ! str_contains($content, (string) $this->patient->person?->full_name);
        },
    );
});

it('sem médico endereçado o envio não dispara e-mail (só o selo da tela avisa)', function () {
    Notification::fake();

    submittedReturnFlowDraft($this);

    Notification::assertNothingSent();
});

it('devolver avisa quem preparou o rascunho, e o e-mail não leva o motivo', function () {
    $draft = submittedReturnFlowDraft($this);
    Notification::fake();

    asReturnFlowDoctor($this)->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
        'draft_revision' => $draft->revision,
        'reason'         => 'Motivo clínico sigiloso.',
    ])->assertOk();

    Notification::assertSentTo(
        $this->secretaryUser,
        ImageReportDraftNotification::class,
        function (ImageReportDraftNotification $notification) {
            $mail    = $notification->toMail($this->secretaryUser);
            $content = implode(' ', array_merge([$mail->subject], $mail->introLines));

            return $notification->event === ImageReportDraftNotification::RETURNED
                && ! str_contains($content, 'sigiloso');
        },
    );
    Notification::assertNotSentTo($this->doctorUserModel, ImageReportDraftNotification::class);
});

it('o médico que devolve um rascunho que ele mesmo preparou não recebe o próprio aviso', function () {
    $draftId = asReturnFlowDoctor($this)->postJson(route('panel.exam-manager.report-drafts.store'), [
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Do próprio médico.</p>',
    ])->assertCreated()->json('id');
    $draft = EyeImageReportDraft::query()->findOrFail($draftId);
    asReturnFlowDoctor($this)->postJson(route('panel.exam-manager.report-drafts.submit', $draft), [
        'draft_revision' => $draft->revision,
    ])->assertOk();
    $draft->refresh();
    Notification::fake();

    asReturnFlowDoctor($this)->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
        'draft_revision' => $draft->revision,
        'reason'         => 'Revisar depois.',
    ])->assertOk();

    Notification::assertNothingSent();
});

// ── B4: destinatário revalidado (vínculo ATIVO da clínica) no envio e no job ──

it('[SEGURANÇA] vínculo do médico endereçado revogado (inativo ou excluído): nenhum e-mail com o nome da clínica', function (string $revocation) {
    $this->doctorEntityUser->forceFill($revocation === 'inactive' ? ['active' => false] : [])->save();

    if ($revocation === 'deleted') {
        $this->doctorEntityUser->delete();
    }
    Notification::fake();

    submittedReturnFlowDraft($this, ['resolved_doctor_id' => $this->doctor->id]);

    Notification::assertNothingSent();
})->with([
    'vínculo desativado' => ['inactive'],
    'vínculo excluído'   => ['deleted'],
]);

it('[SEGURANÇA] médico endereçado cujo usuário foi desativado ou excluído não recebe o aviso', function (string $state) {
    $state === 'inactive' ? $this->doctorUserModel->forceFill(['active' => false])->save() : $this->doctorUserModel->delete();
    Notification::fake();

    submittedReturnFlowDraft($this, ['resolved_doctor_id' => $this->doctor->id]);

    Notification::assertNothingSent();
})->with([
    'usuário desativado' => ['inactive'],
    'usuário excluído'   => ['deleted'],
]);

it('[SEGURANÇA] quem preparou o rascunho e perdeu o vínculo, ou o perfil de preparar, não recebe o aviso de devolução', function (string $change) {
    $draft = submittedReturnFlowDraft($this);
    match ($change) {
        'inactive' => $this->secretaryEntityUser->forceFill(['active' => false])->save(),
        'deleted'  => $this->secretaryEntityUser->delete(),
        'role'     => $this->secretaryEntityUser->forceFill(['rule' => ClientRule::Financial->value])->save(),
        'user'     => $this->secretaryUser->forceFill(['active' => false])->save(),
    };
    Notification::fake();

    asReturnFlowDoctor($this)->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
        'draft_revision' => $draft->revision,
        'reason'         => 'Ajustar.',
    ])->assertOk();

    Notification::assertNothingSent();
})->with([
    'vínculo desativado'               => ['inactive'],
    'vínculo excluído'                 => ['deleted'],
    'perfil sem permissão de preparar' => ['role'],
    'usuário desativado'               => ['user'],
]);

it('o vínculo de OUTRA clínica não vale: o usuário só recebe se estiver ativo na clínica do rascunho', function () {
    $draft       = submittedReturnFlowDraft($this);
    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    // Mesma pessoa tem vínculo ativo em outra clínica, mas o da clínica do rascunho foi revogado.
    createEntityUser($otherEntity, $this->secretaryUser, ClientRule::Secretary->value);
    $this->secretaryEntityUser->forceFill(['active' => false])->save();
    Notification::fake();

    asReturnFlowDoctor($this)->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
        'draft_revision' => $draft->revision,
        'reason'         => 'Ajustar.',
    ])->assertOk();

    Notification::assertNothingSent();
});

it('[SEGURANÇA] o aviso já enfileirado é revalidado na hora de sair (shouldSend): vínculo revogado entre enfileirar e enviar não envia', function () {
    $notification = new ImageReportDraftNotification(
        ImageReportDraftNotification::RETURNED,
        'Clínica X',
        'https://example.test/exam-manager',
        (string) $this->entity->id,
    );

    expect($notification->shouldSend($this->secretaryUser, 'mail'))->toBeTrue();

    EntityUser::query()->whereKey($this->secretaryEntityUser->id)->update(['active' => false]);
    expect($notification->shouldSend($this->secretaryUser->fresh(), 'mail'))->toBeFalse();

    EntityUser::query()->whereKey($this->secretaryEntityUser->id)->update(['active' => true]);
    expect($notification->shouldSend($this->secretaryUser->fresh(), 'mail'))->toBeTrue();

    $this->secretaryEntityUser->delete();
    expect($notification->shouldSend($this->secretaryUser->fresh(), 'mail'))->toBeFalse();
});

it('o aviso enfileirado não sai se o vínculo foi revogado antes do envio (caminho real do envio); com vínculo ativo, sai', function () {
    Event::fake([NotificationSent::class]);
    $make = fn () => new ImageReportDraftNotification(
        ImageReportDraftNotification::SUBMITTED,
        'Clínica X',
        'https://example.test/exam-manager',
        (string) $this->entity->id,
    );

    Notification::sendNow($this->doctorUserModel, $make());
    Event::assertDispatchedTimes(NotificationSent::class, 1);

    EntityUser::query()->whereKey($this->doctorEntityUser->id)->update(['active' => false]);
    Notification::sendNow($this->doctorUserModel->fresh(), $make());

    Event::assertDispatchedTimes(NotificationSent::class, 1); // o 2º não saiu
});

// ── Fila (index) ───────────────────────────────────────────────────────────

it('a fila só aceita status do enum (valor desconhecido dá 422, não lista vazia)', function () {
    asReturnFlowDoctor($this)
        ->getJson(route('panel.exam-manager.report-drafts.index', ['status' => 'qualquer-coisa']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('a fila filtra por devolvidos e traz motivo e médico endereçado', function () {
    $draft = submittedReturnFlowDraft($this, ['resolved_doctor_id' => $this->doctor->id]);
    submittedReturnFlowDraft($this); // outro, ainda aguardando o médico
    asReturnFlowDoctor($this)->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
        'draft_revision' => $draft->revision,
        'reason'         => 'Falta o OE.',
    ])->assertOk();

    $response = asReturnFlowSecretary($this)
        ->getJson(route('panel.exam-manager.report-drafts.index', ['status' => 'returned']))
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.status'))->toBe('returned')
        ->and($response->json('data.0.return_reason'))->toBe('Falta o OE.')
        ->and($response->json('data.0.resolved_doctor_name'))->not->toBeNull();
});

it('"só os meus": o médico vê o endereçado a ele ou sem destinatário; quem prepara vê o que preparou', function () {
    $otherDoctorUser       = User::factory()->create();
    $otherDoctorEntityUser = createEntityUser($this->entity, $otherDoctorUser, ClientRule::Doctor->value);
    $otherDoctor           = Doctor::query()->create([
        'entity_user_id' => $otherDoctorEntityUser->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

    $mine     = submittedReturnFlowDraft($this, ['resolved_doctor_id' => $this->doctor->id]);
    $open     = submittedReturnFlowDraft($this);
    $forOther = submittedReturnFlowDraft($this, ['resolved_doctor_id' => $otherDoctor->id]);

    $doctorIds = asReturnFlowDoctor($this)
        ->getJson(route('panel.exam-manager.report-drafts.index', ['status' => 'submitted', 'mine' => 1]))
        ->assertOk()
        ->json('data.*.id');

    expect($doctorIds)->toContain($mine->id, $open->id)
        ->and($doctorIds)->not->toContain($forOther->id);

    // O que a secretária não preparou não entra no "só os que preparei" dela.
    $adminDraft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'content'    => '<p>Do admin.</p>',
        'status'     => DraftStatus::Submitted,
    ]);
    $secretaryIds = asReturnFlowSecretary($this)
        ->getJson(route('panel.exam-manager.report-drafts.index', ['status' => 'submitted', 'mine' => 1]))
        ->assertOk()
        ->json('data.*.id');

    expect($secretaryIds)->toContain($mine->id, $open->id, $forOther->id)
        ->and($secretaryIds)->not->toContain($adminDraft->id);
});

it('o selo de devolvidos conta só o que o técnico preparou; o médico não vê esse selo', function () {
    $draft = submittedReturnFlowDraft($this);
    asReturnFlowDoctor($this)->postJson(route('panel.exam-manager.report-drafts.return', $draft), [
        'draft_revision' => $draft->revision,
        'reason'         => 'Ajustar.',
    ])->assertOk();

    asReturnFlowSecretary($this)->get(route('panel.exam-manager.index'))
        ->assertInertia(fn ($page) => $page->where('canDraftReports', true)->where('draftReportsReturnedCount', 1)->where('draftReportsPendingCount', 0));

    asReturnFlowDoctor($this)->get(route('panel.exam-manager.index'))
        ->assertInertia(fn ($page) => $page->where('draftReportsReturnedCount', 0));
});
