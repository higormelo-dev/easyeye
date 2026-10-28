<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DataAccessPurpose, DocumentationType, DraftStatus};
use App\Models\{DataAccessLog, Doctor, Entity, EyeImageReportDraft, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, People, User};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo secretária-rascunha / médico-emite (Fase 5) —
 * ExamManagerReportDraftController. Emitir de fato continua 100% em
 * ExamManagerReportController::store() (doctor-only via Gate::IssueReport);
 * este controller só cobre o preparo (Gate::DraftImageReport).
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

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->exam    = PatientExam::factory()->create(['patient_id' => $this->patient->id]);

    ['schedule' => $this->schedule] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'date_time'  => now(),
    ]);
});

function actingAsDraftDoctor($test)
{
    return $test->actingAs($test->doctorUserModel)->withSession(panelSession($test->doctorEntityUser));
}

function actingAsDraftSecretary($test)
{
    return $test->actingAs($test->secretaryUser)->withSession(panelSession($test->secretaryEntityUser));
}

it('secretária cria um rascunho', function () {
    $response = actingAsDraftSecretary($this)->postJson(route('panel.exam-manager.report-drafts.store'), [
        'patient_id'          => $this->patient->id,
        'exam_ids'            => [$this->exam->id],
        'content'             => '<p>Achados preliminares.</p>',
        'clinical_indication' => 'Rastreio de rotina.',
    ]);

    $response->assertCreated();
    expect(EyeImageReportDraft::count())->toBe(1);

    $draft = EyeImageReportDraft::query()->firstOrFail();
    expect($draft->status)->toBe(DraftStatus::Draft)
        ->and($draft->content)->toContain('Achados preliminares')
        ->and($draft->clinical_indication)->toBe('Rastreio de rotina.')
        ->and($draft->exam_ids)->toBe([$this->exam->id]);
});

it('secretária salva o bloco de imagens no rascunho (image_report_payload) e ele volta no show()', function () {
    $secondExam = PatientExam::factory()->create(['patient_id' => $this->patient->id]);

    actingAsDraftSecretary($this)->postJson(route('panel.exam-manager.report-drafts.store'), [
        'patient_id'   => $this->patient->id,
        'exam_ids'     => [$this->exam->id, $secondExam->id],
        'content'      => '<p>Rascunho com imagens.</p>',
        'image_report' => [
            'enabled' => true,
            'columns' => 2,
            'images'  => [
                ['exam_id' => $this->exam->id, 'caption' => 'OD'],
                ['exam_id' => $secondExam->id, 'caption' => 'OE'],
            ],
        ],
    ])->assertCreated();

    $draft = EyeImageReportDraft::query()->firstOrFail();
    expect($draft->image_report_payload)->toBe([
        'enabled'    => true,
        'columns'    => 2,
        'pair_od_oe' => false,
        'images'     => [
            ['exam_id' => $this->exam->id, 'caption' => 'OD', 'order' => 0],
            ['exam_id' => $secondExam->id, 'caption' => 'OE', 'order' => 1],
        ],
    ]);

    actingAsDraftSecretary($this)
        ->getJson(route('panel.exam-manager.report-drafts.show', $draft))
        ->assertOk()
        ->assertJsonPath('image_report_payload.images.1.exam_id', $secondExam->id);
});

it('[GAP] secretária insere imagem inline (botão "Inserir imagem") no rascunho — referência sobrevive ao salvar e ao reabrir, sem a src crua — achado via revisão adversarial Codex', function () {
    $response = actingAsDraftSecretary($this)->postJson(route('panel.exam-manager.report-drafts.store'), [
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Achados.</p><p><img src="https://signed-url.example/x.jpg" data-exam-image-id="' . $this->exam->id . '" alt="Foto OD" style="max-width:320px"></p>',
    ]);

    $response->assertCreated();
    $draft = EyeImageReportDraft::query()->firstOrFail();

    // Rascunho NUNCA congela bytes (nunca chama ReportImageBlockBuilder) —
    // só guarda a referência; a src crua do cliente nunca é confiável.
    expect($draft->content)->not->toContain('https://signed-url.example/x.jpg')
        ->and($draft->content)->toContain('data-exam-image-id="' . $this->exam->id . '"');

    actingAsDraftSecretary($this)
        ->getJson(route('panel.exam-manager.report-drafts.show', $draft))
        ->assertOk()
        ->assertJsonPath('content', $draft->content);
});

it('[SEGURANÇA] rascunho rejeita referência inline citando exame fora da seleção (422), nunca aceita em silêncio', function () {
    $otherExam = PatientExam::factory()->create(['patient_id' => $this->patient->id]);

    actingAsDraftSecretary($this)->postJson(route('panel.exam-manager.report-drafts.store'), [
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id], // $otherExam NÃO está na seleção
        'content'    => '<p><img src="x" data-exam-image-id="' . $otherExam->id . '" alt="Foto"></p>',
    ])->assertStatus(422);

    expect(EyeImageReportDraft::count())->toBe(0);
});

it('secretária edita e depois envia o rascunho pro médico', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Rascunho inicial.</p>',
        'status'     => DraftStatus::Draft,
    ]);

    actingAsDraftSecretary($this)
        ->putJson(route('panel.exam-manager.report-drafts.update', $draft), [
            'exam_ids'       => [$this->exam->id],
            'content'        => '<p>Rascunho revisado.</p>',
            'draft_revision' => $draft->revision,
        ])
        ->assertOk();

    expect($draft->refresh()->content)->toContain('Rascunho revisado');

    actingAsDraftSecretary($this)
        ->postJson(route('panel.exam-manager.report-drafts.submit', $draft), [
            'draft_revision' => $draft->revision, // já refletido (refresh() acima) = o que o update() bumpou
        ])
        ->assertOk()
        ->assertJsonPath('status', 'submitted');

    expect($draft->refresh()->status)->toBe(DraftStatus::Submitted)
        ->and($draft->submitted_at)->not->toBeNull();
});

it('[SEGURANÇA] secretária não emite laudo — store() continua doctor-only mesmo com draft_id', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Pronto para revisão.</p>',
        'status'     => DraftStatus::Submitted,
    ]);

    actingAsDraftSecretary($this)
        ->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Pronto para revisão.</p>',
            'confirm_open_record' => true,
            'draft_id'            => $draft->id,
        ])
        ->assertForbidden();

    expect(MedicalRecordDocumentation::count())->toBe(0)
        ->and($draft->refresh()->status)->toBe(DraftStatus::Submitted);
});

it('médico emite a partir de um rascunho submetido — rascunho fecha o ciclo (Issued)', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Pronto para revisão.</p>',
        'status'     => DraftStatus::Submitted,
    ]);

    $response = actingAsDraftDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
        'patient_id'          => $this->patient->id,
        'exam_ids'            => [$this->exam->id],
        'content'             => '<p>Pronto para revisão, aprovado.</p>',
        'confirm_open_record' => true,
        'draft_id'            => $draft->id,
        'draft_revision'      => $draft->revision,
    ]);

    $response->assertCreated();
    $doc = MedicalRecordDocumentation::query()->firstOrFail();

    expect($draft->refresh()->status)->toBe(DraftStatus::Issued)
        ->and((string) $draft->issued_documentation_id)->toBe((string) $doc->id)
        ->and($draft->issued_at)->not->toBeNull();
});

/**
 * Contenção real provada com a mesma técnica de ConsultationRecordOpeningLockTest:
 * DB::listen() injeta a emissão da OUTRA requisição (2º médico, mesmo
 * rascunho) exatamente entre a checagem de fora (sem lock — ainda vê
 * Submitted) e a releitura sob lock dentro da transação. Sem o lock+releitura,
 * as duas passavam e issued_documentation_id ficava só com a última a
 * comitar, orfanando o documento da primeira (achado via revisão Codex).
 */
it('[GAP] relê sob lock: emissão concorrente do MESMO rascunho (outro médico) é bloqueada, não duplicada', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Pronto para revisão.</p>',
        'status'     => DraftStatus::Submitted,
    ]);

    // Documento "da outra requisição" que já venceu a corrida — só existe
    // pra satisfazer o FK de issued_documentation_id injetado abaixo.
    $otherRecord = MedicalRecord::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctor->id,
    ]);
    $otherDoc = MedicalRecordDocumentation::create([
        'medical_record_id' => $otherRecord->id,
        'patient_id'        => $this->patient->id,
        'doctor_id'         => $this->doctor->id,
        'type'              => DocumentationType::Report->value,
        'title'             => 'Laudo (outro médico, venceu a corrida)',
        'content'           => '<p>Emitido pela outra requisição.</p>',
    ]);

    $injected = false;
    DB::listen(function (QueryExecuted $query) use (&$injected, $draft, $otherDoc) {
        $sql = strtolower($query->sql);

        // "select *" (não "select count(*)...") — a regra de validação
        // exists:eye_image_report_drafts,id do FormRequest roda ANTES de
        // qualquer coisa no controller e também bate em "eye_image_report_drafts";
        // sem o "*" aqui a injeção disparava cedo demais (ainda na
        // validação), antes até da checagem de fora ler o rascunho.
        if ($injected
            || ! str_contains($sql, 'select *')
            || ! str_contains($sql, 'eye_image_report_drafts')
            || str_contains($sql, 'for update')) {
            return;
        }
        $injected = true;
        // A OUTRA requisição já terminou de emitir e comitou — entre a
        // checagem sem lock (de fora) e a releitura sob lock (dentro da
        // transação) desta requisição.
        DB::table('eye_image_report_drafts')->where('id', $draft->id)->update([
            'status'                  => DraftStatus::Issued->value,
            'issued_at'               => now(),
            'issued_documentation_id' => $otherDoc->id,
        ]);
    });

    $response = actingAsDraftDoctor($this)->postJson(route('panel.exam-manager.reports.store'), [
        'patient_id'          => $this->patient->id,
        'exam_ids'            => [$this->exam->id],
        'content'             => '<p>Tentativa concorrente — deveria perder a corrida.</p>',
        'confirm_open_record' => true,
        'draft_id'            => $draft->id,
        'draft_revision'      => $draft->revision,
    ]);

    expect($injected)->toBeTrue(); // prova que a injeção rodou ANTES da transação, não é vacuamente verde
    $response->assertStatus(422);

    // Nenhum documento NOVO criado por esta requisição — só o "da outra"
    // que a injeção simulou. issued_documentation_id continua com o dono
    // certo (o vencedor da corrida), nunca sobrescrito/orfanado.
    expect(MedicalRecordDocumentation::count())->toBe(1)
        ->and((string) $draft->refresh()->issued_documentation_id)->toBe((string) $otherDoc->id);
});

/**
 * Mesma técnica do teste acima, aplicada a submit()/destroy()/update()
 * (revisão Codex, P2): esses 3 métodos mudavam o status do rascunho sem
 * nenhuma proteção de corrida. O binding implícito de {draft} na rota já
 * dispara um "select * ... eye_image_report_drafts" ANTES do corpo do
 * método rodar — é esse o ponto em que a injeção abaixo acontece, deixando
 * o $draft em memória (e todas as checagens de fora, que só leem esse
 * objeto já carregado) com o status ANTIGO, exatamente como a corrida real
 * entre duas requisições simultâneas deixaria.
 */
it('[GAP] relê sob lock: secretária submete o rascunho ENQUANTO médico emite o MESMO rascunho — bloqueado, não reabre o ciclo', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Pronto para revisão.</p>',
        'status'     => DraftStatus::Draft,
    ]);

    // Documento "da emissão concorrente" que já venceu a corrida — só existe
    // pra satisfazer o FK de issued_documentation_id injetado abaixo.
    $otherRecord = MedicalRecord::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctor->id,
    ]);
    $otherDoc = MedicalRecordDocumentation::create([
        'medical_record_id' => $otherRecord->id,
        'patient_id'        => $this->patient->id,
        'doctor_id'         => $this->doctor->id,
        'type'              => DocumentationType::Report->value,
        'title'             => 'Laudo (médico, emitido durante o submit concorrente)',
        'content'           => '<p>Emitido pela outra requisição.</p>',
    ]);

    $injected = false;
    DB::listen(function (QueryExecuted $query) use (&$injected, $draft, $otherDoc) {
        $sql = strtolower($query->sql);

        // "select *" (não "select count(*)...") e nunca "for update" — mesmo
        // filtro do teste acima, pra não disparar a injeção cedo demais nem
        // bater na própria releitura sob lock.
        if ($injected
            || ! str_contains($sql, 'select *')
            || ! str_contains($sql, 'eye_image_report_drafts')
            || str_contains($sql, 'for update')) {
            return;
        }
        $injected = true;
        // O médico já terminou de emitir e comitou — entre a leitura que
        // populou $draft (ainda Draft em memória) e a releitura sob lock
        // dentro da transação de submit().
        DB::table('eye_image_report_drafts')->where('id', $draft->id)->update([
            'status'                  => DraftStatus::Issued->value,
            'issued_at'               => now(),
            'issued_documentation_id' => $otherDoc->id,
        ]);
    });

    $response = actingAsDraftSecretary($this)->postJson(route('panel.exam-manager.report-drafts.submit', $draft), [
        'draft_revision' => $draft->revision,
    ]);

    expect($injected)->toBeTrue(); // prova que a injeção rodou antes da releitura sob lock
    $response->assertStatus(422);

    // status continua Issued — submit() NÃO sobrescreve de volta pra
    // Submitted (o que deixaria issued_documentation_id órfão/inconsistente
    // e reabriria o rascunho pra uma segunda emissão).
    expect($draft->refresh()->status)->toBe(DraftStatus::Issued)
        ->and((string) $draft->issued_documentation_id)->toBe((string) $otherDoc->id);
});

/**
 * Mesma técnica dos testes acima, mas a corrida é com update() (secretária
 * edita o MESMO rascunho), não com a emissão: o conteúdo é esvaziado ENTRE a
 * checagem de fora (vê o $draft antigo, ainda com texto) e a releitura sob
 * lock dentro de submit(). Sem reaplicar a checagem de conteúdo vazio contra
 * $locked (relido), submit() aprovava um rascunho que, no momento em que o
 * lock foi de fato adquirido, já estava vazio (achado via revisão Codex).
 */
it('[GAP] relê sob lock: conteúdo é esvaziado por uma edição concorrente ENTRE a checagem de fora e a releitura sob lock — submit() não aprova um rascunho vazio', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Pronto para revisão.</p>',
        'status'     => DraftStatus::Draft,
    ]);

    $injected = false;
    DB::listen(function (QueryExecuted $query) use (&$injected, $draft) {
        $sql = strtolower($query->sql);

        // Mesmo filtro dos testes acima: "select *" (não "for update"), pra
        // disparar a injeção exatamente no binding implícito de {draft} na
        // rota, antes do corpo de submit() rodar.
        if ($injected
            || ! str_contains($sql, 'select *')
            || ! str_contains($sql, 'eye_image_report_drafts')
            || str_contains($sql, 'for update')) {
            return;
        }
        $injected = true;
        // Outra requisição (secretária editando o MESMO rascunho via
        // update(), que permite conteúdo vazio) já esvaziou o conteúdo e
        // comitou — entre a leitura que populou $draft (ainda com texto em
        // memória) e a releitura sob lock dentro da transação de submit().
        DB::table('eye_image_report_drafts')->where('id', $draft->id)->update(['content' => '']);
    });

    $response = actingAsDraftSecretary($this)->postJson(route('panel.exam-manager.report-drafts.submit', $draft), [
        'draft_revision' => $draft->revision,
    ]);

    expect($injected)->toBeTrue(); // prova que a injeção rodou antes da releitura sob lock
    $response->assertStatus(422);

    // status continua Draft — submit() não aprova um rascunho que, sob
    // lock, já estava vazio (mesmo passando pela checagem de fora, que só
    // viu o $draft antigo em memória).
    expect($draft->refresh()->status)->toBe(DraftStatus::Draft)
        ->and($draft->submitted_at)->toBeNull();
});

it('[GAP] relê sob lock: descartar o rascunho ENQUANTO médico emite o MESMO rascunho — bloqueado, não perde a referência ao documento emitido', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Pronto para revisão.</p>',
        'status'     => DraftStatus::Submitted,
    ]);

    $otherRecord = MedicalRecord::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctor->id,
    ]);
    $otherDoc = MedicalRecordDocumentation::create([
        'medical_record_id' => $otherRecord->id,
        'patient_id'        => $this->patient->id,
        'doctor_id'         => $this->doctor->id,
        'type'              => DocumentationType::Report->value,
        'title'             => 'Laudo (médico, emitido durante o descarte concorrente)',
        'content'           => '<p>Emitido pela outra requisição.</p>',
    ]);

    $injected = false;
    DB::listen(function (QueryExecuted $query) use (&$injected, $draft, $otherDoc) {
        $sql = strtolower($query->sql);

        if ($injected
            || ! str_contains($sql, 'select *')
            || ! str_contains($sql, 'eye_image_report_drafts')
            || str_contains($sql, 'for update')) {
            return;
        }
        $injected = true;
        DB::table('eye_image_report_drafts')->where('id', $draft->id)->update([
            'status'                  => DraftStatus::Issued->value,
            'issued_at'               => now(),
            'issued_documentation_id' => $otherDoc->id,
        ]);
    });

    $response = actingAsDraftSecretary($this)->deleteJson(route('panel.exam-manager.report-drafts.destroy', $draft), [
        'draft_revision' => $draft->revision,
    ]);

    expect($injected)->toBeTrue();
    $response->assertStatus(422);

    // status continua Issued — destroy() NÃO sobrescreve pra Discarded por
    // cima de um rascunho que, entre a checagem de fora e o lock, já virou
    // o laudo assinado de outra requisição.
    expect($draft->refresh()->status)->toBe(DraftStatus::Issued)
        ->and((string) $draft->issued_documentation_id)->toBe((string) $otherDoc->id);
});

it('[GAP] descarta com draft_revision OBSOLETA (outra pessoa salvou uma correção enquanto esta tela ainda tinha a revisão antiga aberta): rejeita com 409, a correção não é perdida — achado via revisão adversarial Codex (destroy() só checava isEditable(), nunca comparava revision como update()/submit() já faziam)', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Rascunho inicial.</p>',
        'status'     => DraftStatus::Draft,
    ]);
    $staleRevision = $draft->revision; // 1 — o que esta tela "carregou"

    // Outra aba/pessoa salva uma correção DE VERDADE ENQUANTO esta tela
    // ainda tinha a revisão antiga carregada — avança revision pra 2.
    actingAsDraftSecretary($this)->putJson(route('panel.exam-manager.report-drafts.update', $draft), [
        'exam_ids'       => [$this->exam->id],
        'content'        => '<p>Correção de outra pessoa.</p>',
        'draft_revision' => $staleRevision,
    ])->assertOk();

    $response = actingAsDraftSecretary($this)->deleteJson(route('panel.exam-manager.report-drafts.destroy', $draft), [
        'draft_revision' => $staleRevision,
    ]);

    $response->assertStatus(409);
    // A correção de outra pessoa não foi perdida — o rascunho continua
    // Draft (editável/emissível), nunca Discarded por cima de uma
    // correção que ninguém com a tela aberta nunca viu.
    expect($draft->refresh()->status)->toBe(DraftStatus::Draft)
        ->and($draft->content)->toContain('Correção de outra pessoa');
});

it('[SEGURANÇA] descarta SEM draft_revision: 422, nunca passa por cima da checagem de obsolescência', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id,
        'exam_ids'  => [$this->exam->id], 'content' => '<p>Rascunho.</p>', 'status' => DraftStatus::Draft,
    ]);

    $response = actingAsDraftSecretary($this)->deleteJson(route('panel.exam-manager.report-drafts.destroy', $draft));

    $response->assertStatus(422)->assertJsonValidationErrors(['draft_revision']);
    expect($draft->refresh()->status)->toBe(DraftStatus::Draft);
});

it('[GAP] relê sob lock: editar o rascunho ENQUANTO médico emite o MESMO rascunho — bloqueado, não sobrescreve o já emitido', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Pronto para revisão.</p>',
        'status'     => DraftStatus::Draft,
    ]);

    $otherRecord = MedicalRecord::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctor->id,
    ]);
    $otherDoc = MedicalRecordDocumentation::create([
        'medical_record_id' => $otherRecord->id,
        'patient_id'        => $this->patient->id,
        'doctor_id'         => $this->doctor->id,
        'type'              => DocumentationType::Report->value,
        'title'             => 'Laudo (médico, emitido durante a edição concorrente)',
        'content'           => '<p>Emitido pela outra requisição.</p>',
    ]);

    $injected = false;
    DB::listen(function (QueryExecuted $query) use (&$injected, $draft, $otherDoc) {
        $sql = strtolower($query->sql);

        if ($injected
            || ! str_contains($sql, 'select *')
            || ! str_contains($sql, 'eye_image_report_drafts')
            || str_contains($sql, 'for update')) {
            return;
        }
        $injected = true;
        DB::table('eye_image_report_drafts')->where('id', $draft->id)->update([
            'status'                  => DraftStatus::Issued->value,
            'issued_at'               => now(),
            'issued_documentation_id' => $otherDoc->id,
        ]);
    });

    $response = actingAsDraftSecretary($this)->putJson(route('panel.exam-manager.report-drafts.update', $draft), [
        'exam_ids' => [$this->exam->id],
        'content'  => '<p>Tentativa de edição concorrente.</p>',
    ]);

    expect($injected)->toBeTrue();
    $response->assertStatus(422);

    // status continua Issued e o conteúdo NÃO foi sobrescrito pela edição
    // que perdeu a corrida.
    expect($draft->refresh()->status)->toBe(DraftStatus::Issued)
        ->and($draft->content)->not->toContain('Tentativa de edição concorrente');
});

it('[GAP] atualiza com draft_revision ATUAL: aceita normalmente (token não é exigência vazia) — achado via revisão adversarial Codex', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Rascunho inicial.</p>',
        'status'     => DraftStatus::Draft,
    ]);

    $response = actingAsDraftSecretary($this)->putJson(route('panel.exam-manager.report-drafts.update', $draft), [
        'exam_ids'       => [$this->exam->id],
        'content'        => '<p>Rascunho atualizado.</p>',
        'draft_revision' => $draft->revision,
    ]);

    $response->assertOk();
    expect($response->json('revision'))->toBe(2) // incrementou de verdade
        ->and($draft->refresh()->content)->toContain('Rascunho atualizado');
});

it('[GAP] atualiza com draft_revision OBSOLETA (outra pessoa salvou enquanto esta tela estava aberta): rejeita com 409, nunca sobrescreve a correção alheia — achado via revisão adversarial Codex', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Rascunho inicial.</p>',
        'status'     => DraftStatus::Draft,
    ]);
    $staleRevision = $draft->revision; // 1 — o que esta tela "carregou"

    // Outra aba/pessoa salva uma correção DE VERDADE (mesmo endpoint)
    // ENQUANTO esta tela ainda tinha a revisão antiga carregada — avança
    // revision pra 2.
    actingAsDraftSecretary($this)->putJson(route('panel.exam-manager.report-drafts.update', $draft), [
        'exam_ids'       => [$this->exam->id],
        'content'        => '<p>Correção de outra pessoa.</p>',
        'draft_revision' => $staleRevision,
    ])->assertOk();

    $response = actingAsDraftSecretary($this)->putJson(route('panel.exam-manager.report-drafts.update', $draft), [
        'exam_ids'       => [$this->exam->id],
        'content'        => '<p>Tentativa com revisão antiga.</p>',
        'draft_revision' => $staleRevision,
    ]);

    $response->assertStatus(409);
    expect($draft->refresh()->content)->toContain('Correção de outra pessoa')
        ->and($draft->content)->not->toContain('Tentativa com revisão antiga');
});

it('[GAP] revisão obsoleta detectada mesmo com updated_at idêntico entre as 2 versões (colisão do precision 0 de timestamps() nesta tabela — revision nunca depende de relógio) — achado na 2ª rodada de revisão adversarial Codex', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Rascunho inicial.</p>',
        'status'     => DraftStatus::Draft,
    ]);
    $staleRevision   = $draft->revision; // 1 — o que esta tela "carregou"
    $frozenUpdatedAt = $draft->updated_at;

    // Outra pessoa corrige de verdade (revision avança pra 2), mas
    // updated_at fica PINADO no MESMO valor — reproduz a colisão real
    // (Schema Builder usa precision 0 pra timestamps() desta tabela; 2
    // saves no mesmo segundo gravam o MESMO updated_at, ver migration
    // 2026_10_21_000100_add_revision_counter_to_eye_image_report_drafts).
    $draft->forceFill(['content' => '<p>Correção de outra pessoa.</p>'])->save();
    $draft->incrementRevision();
    $draft->forceFill(['updated_at' => $frozenUpdatedAt])->saveQuietly();
    expect($draft->refresh()->updated_at->equalTo($frozenUpdatedAt))->toBeTrue() // confirma a colisão
        ->and($draft->revision)->toBe(2); // revision avançou mesmo assim

    $response = actingAsDraftSecretary($this)->putJson(route('panel.exam-manager.report-drafts.update', $draft), [
        'exam_ids'       => [$this->exam->id],
        'content'        => '<p>Tentativa com revisão obsoleta.</p>',
        'draft_revision' => $staleRevision,
    ]);

    $response->assertStatus(409);
    expect($draft->refresh()->content)->toContain('Correção de outra pessoa');
});

it('[SEGURANÇA] atualiza SEM draft_revision: 422, nunca passa por cima da checagem de obsolescência — achado na 3ª rodada de revisão adversarial Codex', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Rascunho inicial.</p>',
        'status'     => DraftStatus::Draft,
    ]);

    // Omitir o campo (em vez de mandar o valor errado) ANTES desligava a
    // checagem por completo — bastava não enviar nada pra sobrescrever a
    // correção de qualquer um sem nunca receber 409.
    $response = actingAsDraftSecretary($this)->putJson(route('panel.exam-manager.report-drafts.update', $draft), [
        'exam_ids' => [$this->exam->id],
        'content'  => '<p>Tentativa sem token de revisão.</p>',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['draft_revision']);
    expect($draft->refresh()->content)->toContain('Rascunho inicial');
});

it('[SEGURANÇA] envia (submit) SEM draft_revision: 422, nunca passa por cima da checagem de obsolescência — achado na 5ª rodada de revisão adversarial Codex', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Pronto para revisão.</p>',
        'status'     => DraftStatus::Draft,
    ]);

    $response = actingAsDraftSecretary($this)->postJson(route('panel.exam-manager.report-drafts.submit', $draft));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['draft_revision']);
    expect($draft->refresh()->status)->toBe(DraftStatus::Draft);
});

it('[GAP] envia (submit) com draft_revision OBSOLETA: 409, nunca marca "pronto pra revisão" um conteúdo que esta tela nunca viu — achado na 5ª rodada de revisão adversarial Codex', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Texto que esta tela carregou.</p>',
        'status'     => DraftStatus::Draft,
    ]);
    $staleRevision = $draft->revision; // 1 — o que esta tela "carregou"

    // Outra aba/pessoa corrige de verdade (mesmo endpoint) ENQUANTO esta
    // tela ainda tinha a revisão antiga — avança revision pra 2.
    actingAsDraftSecretary($this)->putJson(route('panel.exam-manager.report-drafts.update', $draft), [
        'exam_ids'       => [$this->exam->id],
        'content'        => '<p>Correção de outra pessoa.</p>',
        'draft_revision' => $staleRevision,
    ])->assertOk();

    $response = actingAsDraftSecretary($this)->postJson(route('panel.exam-manager.report-drafts.submit', $draft), [
        'draft_revision' => $staleRevision,
    ]);

    $response->assertStatus(409);
    // Nunca vira Submitted com o texto antigo — a correção da outra pessoa
    // continua Draft (editável), nunca "pronta pra revisão" sem que nenhuma
    // tela tenha de fato visto esse conteúdo.
    expect($draft->refresh()->status)->toBe(DraftStatus::Draft)
        ->and($draft->content)->toContain('Correção de outra pessoa');
});

it('[SEGURANÇA] rascunho já emitido não pode ser reaproveitado numa segunda emissão', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Já emitido.</p>',
        'status'     => DraftStatus::Issued,
        'issued_at'  => now(),
    ]);

    actingAsDraftDoctor($this)
        ->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Tentativa de reemitir.</p>',
            'confirm_open_record' => true,
            'draft_id'            => $draft->id,
        ])
        ->assertStatus(422);
});

it('descarte é bloqueado quando o rascunho já foi emitido', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Já emitido.</p>',
        'status'     => DraftStatus::Issued,
    ]);

    actingAsDraftSecretary($this)
        ->deleteJson(route('panel.exam-manager.report-drafts.destroy', $draft))
        ->assertStatus(422);

    expect($draft->refresh()->status)->toBe(DraftStatus::Issued);
});

it('descarta um rascunho em aberto (vira Discarded, não some do banco)', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'exam_ids'   => [$this->exam->id],
        'content'    => '<p>Abandonado.</p>',
        'status'     => DraftStatus::Draft,
    ]);

    actingAsDraftSecretary($this)
        ->deleteJson(route('panel.exam-manager.report-drafts.destroy', $draft), ['draft_revision' => $draft->revision])
        ->assertOk()
        ->assertJson(['discarded' => true]);

    expect($draft->refresh()->status)->toBe(DraftStatus::Discarded);
});

it('[SEGURANÇA] rascunho de outra clínica não é visível (404)', function () {
    $otherEntity  = Entity::factory()->create(['is_client' => true]);
    $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
    $otherDraft   = EyeImageReportDraft::create([
        'entity_id'  => $otherEntity->id,
        'patient_id' => $otherPatient->id,
        'content'    => '<p>De outra clínica.</p>',
        'status'     => DraftStatus::Draft,
    ]);

    actingAsDraftSecretary($this)
        ->getJson(route('panel.exam-manager.report-drafts.show', $otherDraft))
        ->assertNotFound();
});

it('[LGPD] show() do rascunho grava log de acesso ao conteúdo clínico (art. 37)', function () {
    $draft = EyeImageReportDraft::create([
        'entity_id'           => $this->entity->id,
        'patient_id'          => $this->patient->id,
        'content'             => '<p>Achados confidenciais.</p>',
        'clinical_indication' => 'Rastreio de rotina.',
        'status'              => DraftStatus::Draft,
    ]);

    actingAsDraftSecretary($this)
        ->getJson(route('panel.exam-manager.report-drafts.show', $draft))
        ->assertOk();

    $log = DataAccessLog::query()
        ->where('resource_type', EyeImageReportDraft::class)
        ->where('resource_id', $draft->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->purpose)->toBe(DataAccessPurpose::PatientCare)
        ->and($log->patient_id)->toBe((string) $this->patient->id);
});

it('[SEGURANÇA] draft_id de outra clínica é rejeitado com 404 na emissão', function () {
    $otherEntity  = Entity::factory()->create(['is_client' => true]);
    $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
    $otherDraft   = EyeImageReportDraft::create([
        'entity_id'  => $otherEntity->id,
        'patient_id' => $otherPatient->id,
        'content'    => '<p>De outra clínica.</p>',
        'status'     => DraftStatus::Submitted,
    ]);

    actingAsDraftDoctor($this)
        ->postJson(route('panel.exam-manager.reports.store'), [
            'patient_id'          => $this->patient->id,
            'exam_ids'            => [$this->exam->id],
            'content'             => '<p>Tentando usar rascunho de outra clínica.</p>',
            'confirm_open_record' => true,
            'draft_id'            => $otherDraft->id,
            'draft_revision'      => $otherDraft->revision,
        ])
        ->assertNotFound();

    expect(MedicalRecordDocumentation::count())->toBe(0);
});

it('lista rascunhos filtrando por status', function () {
    EyeImageReportDraft::create(['entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'content' => 'A', 'status' => DraftStatus::Draft]);
    EyeImageReportDraft::create(['entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'content' => 'B', 'status' => DraftStatus::Submitted]);

    $response = actingAsDraftDoctor($this)->getJson(route('panel.exam-manager.report-drafts.index', ['status' => 'submitted']));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.status'))->toBe('submitted');
});

it('[GAP] mais de 25 rascunhos: pagina em vez de truncar — o mais antigo continua alcançável na página 2 (achado via revisão Codex)', function () {
    foreach (range(1, 27) as $i) {
        $draft = EyeImageReportDraft::create([
            'entity_id'  => $this->entity->id,
            'patient_id' => $this->patient->id,
            'title'      => "Rascunho {$i}",
            'content'    => "Conteúdo {$i}",
            'status'     => DraftStatus::Draft,
        ]);
        // updated_at não está em $fillable (correto — é gerenciado pelo
        // Eloquent) e create() descartava silenciosamente o valor passado
        // ali, deixando os 27 registros com o MESMO "agora" (ordem quase
        // empatada e imprevisível). forceFill()+saveQuietly() define um
        // valor determinístico e distinto por registro, sem brigar com o
        // auto-touch do Eloquent (updateTimestamps() só sobrescreve
        // updated_at quando ele NÃO está "dirty" — forceFill() já deixa).
        $draft->forceFill(['updated_at' => now()->subMinutes(27 - $i)])->saveQuietly(); // $i=1 é o MAIS antigo, $i=27 o mais novo
    }

    $page1 = actingAsDraftDoctor($this)->getJson(route('panel.exam-manager.report-drafts.index'));
    $page1->assertOk();
    expect($page1->json('data'))->toHaveCount(25)
        ->and($page1->json('meta.total'))->toBe(27)
        ->and($page1->json('meta.last_page'))->toBe(2)
        ->and($page1->json('meta.has_more'))->toBeTrue()
        // O mais antigo (Rascunho 1) nunca aparece na 1ª página — updated_at
        // mais distante, igual já era antes da correção (isso não muda).
        ->and(collect($page1->json('data'))->pluck('title'))->not->toContain('Rascunho 1');

    $page2 = actingAsDraftDoctor($this)->getJson(route('panel.exam-manager.report-drafts.index', ['page' => 2]));
    $page2->assertOk();
    // Sem a correção (limit(100) fixo, sem paginação de verdade), não havia
    // pra onde ir buscar o resto — ?page=2 simplesmente não tinha efeito
    // nenhum. Agora alcançável de verdade (achado via revisão Codex).
    expect($page2->json('data'))->toHaveCount(2)
        ->and($page2->json('meta.has_more'))->toBeFalse()
        ->and(collect($page2->json('data'))->pluck('title'))->toContain('Rascunho 1');
});
