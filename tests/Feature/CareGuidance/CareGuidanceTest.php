<?php

declare(strict_types=1);

use App\Domains\CareGuidance\Exceptions\CareGuidanceException;
use App\Domains\CareGuidance\Models\{CareGuidanceAttachment, CareGuidanceIssue, CareGuidanceTemplate};
use App\Domains\CareGuidance\Services\{CareGuidanceIssueService, CareGuidanceTemplateService};
use App\Enums\{ClientRule, MedicalRecordProcedureStatus};
use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordProcedure, Patient, People, Procedure, User};
use App\Support\{CanonicalJson, PanelNavigation};
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Orientações pós-procedimento (Fase 6 / S14): modelos da clínica (Configurações, só quem administra), PDFs anexos
 * (só PDF de verdade, até 5 MB e 5 por modelo) e a emissão no prontuário (só médico), que CONGELA o texto, o local, os
 * colírios e uma cópia dos PDFs — mudar ou apagar o modelo depois não muda o que o paciente recebeu.
 */
beforeEach(function () {
    Storage::fake(config('filesystems.default'));

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->adminAccount = User::factory()->create();
    $this->adminEu      = createEntityUser($this->entity, $this->adminAccount, ClientRule::Admin->value);

    $this->doctorAccount = User::factory()->create();
    $this->doctorEu      = createEntityUser($this->entity, $this->doctorAccount, ClientRule::Doctor->value);
    $this->doctor        = Doctor::query()->create(['entity_user_id' => $this->doctorEu->id, 'person_id' => People::factory()->create()->id, 'active' => true]);

    $this->secretaryAccount = User::factory()->create();
    $this->secretaryEu      = createEntityUser($this->entity, $this->secretaryAccount, ClientRule::Secretary->value);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->record  = MedicalRecord::query()->create(['entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id]);

    $this->facectomy = Procedure::query()->create(['entity_id' => $this->entity->id, 'code' => 'FACO', 'name' => 'Facectomia', 'active' => true]);
    $this->other     = Procedure::query()->create(['entity_id' => $this->entity->id, 'code' => 'PTER', 'name' => 'Pterígio', 'active' => true]);

    $this->procedure = MedicalRecordProcedure::create([
        'entity_id'    => $this->entity->id, 'patient_id' => $this->patient->id, 'medical_record_id' => $this->record->id,
        'procedure_id' => $this->facectomy->id, 'doctor_id' => $this->doctor->id, 'eye' => 'right',
    ]);
});

function cgAs($test, string $who)
{
    $account = $test->{$who . 'Account'};
    $eu      = $test->{$who . 'Eu'};

    return $test->actingAs($account)->withSession(panelSession($eu));
}

function cgPdf(string $content = '%PDF-1.4 conteudo'): UploadedFile
{
    return UploadedFile::fake()->createWithContent('preparo.pdf', $content);
}

/** @return array<string, mixed> */
function cgPayload(array $overrides = []): array
{
    return array_merge([
        'title'          => 'Pós-operatório de catarata',
        'procedure_id'   => null,
        'location_label' => 'Sala de curativos, 2º andar',
        'body'           => "Não coce o olho.\nUse o protetor ao dormir.",
        'active'         => true,
        'eye_drops'      => [
            ['name' => 'Moxifloxacino', 'how' => '1 gota', 'times_per_day' => 4, 'days' => 7],
            ['name' => 'Prednisolona', 'how' => null, 'times_per_day' => 4, 'days' => 21],
        ],
    ], $overrides);
}

function cgTemplate($test, array $overrides = []): array
{
    return cgAs($test, 'admin')->postJson(route('panel.setting.care-guidance.store'), cgPayload($overrides))->assertCreated()->json('data');
}

function cgIssue($test, string $templateId, array $extra = [], ?MedicalRecordProcedure $procedure = null)
{
    return cgAs($test, 'doctor')->postJson(
        route('panel.patients.medicalrecord-procedures.care-guidance.issue', [$test->patient->id, ($procedure ?? $test->procedure)->id]),
        array_merge(['template_id' => $templateId], $extra),
    );
}

// ── modelos (Configurações) ─────────────────────────────────────────────────

it('o admin cria o modelo com local, texto e colírios; a lista traz tudo', function () {
    $data = cgTemplate($this, ['procedure_id' => $this->facectomy->id]);

    expect($data['title'])->toBe('Pós-operatório de catarata')
        ->and($data['procedure_name'])->toBe('Facectomia')
        ->and($data['location_label'])->toBe('Sala de curativos, 2º andar')
        ->and($data['eye_drops'])->toHaveCount(2)
        ->and($data['eye_drops'][0])->toBe(['name' => 'Moxifloxacino', 'how' => '1 gota', 'times_per_day' => 4, 'days' => 7]);

    cgAs($this, 'admin')->get(route('panel.setting.care-guidance.index'))->assertOk()->assertInertia(
        fn ($page) => $page->component('Panel/Settings/CareGuidance/Index')->has('templates', 1)->where('maxAttachments', 5),
    );
});

it('só quem administra mexe nos modelos: médico e secretária recebem 403', function () {
    foreach (['doctor', 'secretary'] as $who) {
        cgAs($this, $who)->get(route('panel.setting.care-guidance.index'))->assertForbidden();
        cgAs($this, $who)->postJson(route('panel.setting.care-guidance.store'), cgPayload())->assertForbidden();
    }

    expect(CareGuidanceTemplate::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('valida o modelo: título e texto obrigatórios, colírios no máximo 10 com vezes 1–12 e dias 1–90', function () {
    $post = fn (array $o) => cgAs($this, 'admin')->postJson(route('panel.setting.care-guidance.store'), cgPayload($o));

    $post(['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');
    $post(['body' => ''])->assertUnprocessable()->assertJsonValidationErrors('body');
    $post(['eye_drops' => array_fill(0, 11, ['name' => 'X'])])->assertUnprocessable()->assertJsonValidationErrors('eye_drops');
    $post(['eye_drops' => [['name' => 'X', 'times_per_day' => 13]]])->assertUnprocessable()->assertJsonValidationErrors('eye_drops.0.times_per_day');
    $post(['eye_drops' => [['name' => 'X', 'days' => 91]]])->assertUnprocessable()->assertJsonValidationErrors('eye_drops.0.days');

    expect(CareGuidanceTemplate::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('colírio sem nome some; o resto é aparado', function () {
    $data = cgTemplate($this, ['eye_drops' => [['name' => '  ', 'how' => 'x'], ['name' => '  Lubrificante  ', 'how' => '  1 gota  ', 'times_per_day' => '3', 'days' => '']]]);

    expect($data['eye_drops'])->toBe([['name' => 'Lubrificante', 'how' => '1 gota', 'times_per_day' => 3, 'days' => null]]);
});

it('procedimento de outra clínica não vale para o modelo; o global vale', function () {
    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $foreign     = Procedure::query()->create(['entity_id' => $otherEntity->id, 'code' => 'X', 'name' => 'De outra', 'active' => true]);
    $global      = Procedure::query()->create(['entity_id' => null, 'code' => 'G', 'name' => 'Global', 'active' => true]);

    cgAs($this, 'admin')->postJson(route('panel.setting.care-guidance.store'), cgPayload(['procedure_id' => $foreign->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('procedure_id');

    cgAs($this, 'admin')->postJson(route('panel.setting.care-guidance.store'), cgPayload(['procedure_id' => $global->id]))->assertCreated();
});

it('edita e exclui o modelo; modelo de outra clínica é 404', function () {
    $data = cgTemplate($this);

    cgAs($this, 'admin')->putJson(route('panel.setting.care-guidance.update', $data['id']), cgPayload(['title' => 'Novo título', 'active' => false]))
        ->assertOk()->assertJsonPath('data.title', 'Novo título')->assertJsonPath('data.active', false);

    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $foreign     = CareGuidanceTemplate::query()->withoutGlobalScopes()->create(['entity_id' => $otherEntity->id, 'title' => 'ISCA', 'body' => 'x']);

    cgAs($this, 'admin')->putJson(route('panel.setting.care-guidance.update', $foreign->id), cgPayload())->assertNotFound();
    cgAs($this, 'admin')->deleteJson(route('panel.setting.care-guidance.destroy', $foreign->id))->assertNotFound();

    cgAs($this, 'admin')->deleteJson(route('panel.setting.care-guidance.destroy', $data['id']))->assertOk();

    expect(CareGuidanceTemplate::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->whereNull('deleted_at')->count())->toBe(0)
        ->and($foreign->refresh()->title)->toBe('ISCA');
});

it('modelo EXCLUÍDO não volta: some da lista e dos oferecidos, não se emite, não se edita nem recebe PDF', function () {
    $keep    = cgTemplate($this, ['title' => 'Fica']);
    $deleted = cgTemplate($this, ['title' => 'Excluído']);

    cgAs($this, 'admin')->deleteJson(route('panel.setting.care-guidance.destroy', $deleted['id']))->assertOk();

    cgAs($this, 'admin')->get(route('panel.setting.care-guidance.index'))->assertOk()->assertInertia(
        fn ($page) => $page->has('templates', 1)->where('templates.0.id', $keep['id']),
    );

    $offered = collect(cgAs($this, 'doctor')->getJson(route('panel.patients.medicalrecord-procedures.care-guidance', [$this->patient->id, $this->procedure->id]))->json('data.templates'))->pluck('id')->all();

    expect($offered)->toBe([$keep['id']]);

    cgIssue($this, $deleted['id'])->assertUnprocessable()->assertJsonPath('message', __('care_guidance.errors.template_not_found'));
    cgAs($this, 'admin')->putJson(route('panel.setting.care-guidance.update', $deleted['id']), cgPayload())->assertNotFound();
    cgAs($this, 'admin')->deleteJson(route('panel.setting.care-guidance.destroy', $deleted['id']))->assertNotFound();
    cgAs($this, 'admin')->post(route('panel.setting.care-guidance.attachments.store', $deleted['id']), ['file' => cgPdf()], ['Accept' => 'application/json'])->assertNotFound();

    expect(CareGuidanceIssue::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(CareGuidanceAttachment::query()->withoutGlobalScopes()->count())->toBe(0);
});

// ── PDFs anexos ─────────────────────────────────────────────────────────────

it('anexa PDF de verdade, baixa o arquivo e remove (arquivo some do disco)', function () {
    $data = cgTemplate($this);

    $res = cgAs($this, 'admin')->post(route('panel.setting.care-guidance.attachments.store', $data['id']), ['file' => cgPdf()], ['Accept' => 'application/json'])->assertCreated();

    $attachment = CareGuidanceAttachment::query()->withoutGlobalScopes()->firstOrFail();

    expect($res->json('data.attachments.0.name'))->toBe('preparo.pdf')
        ->and($attachment->sha256)->toBe(hash('sha256', '%PDF-1.4 conteudo'))
        ->and(Storage::disk()->exists($attachment->path))->toBeTrue();

    cgAs($this, 'admin')->get(route('panel.setting.care-guidance.attachments.download', [$data['id'], $attachment->id]))->assertOk()->assertHeader('content-type', 'application/pdf');

    cgAs($this, 'admin')->deleteJson(route('panel.setting.care-guidance.attachments.destroy', [$data['id'], $attachment->id]))->assertOk();

    expect(CareGuidanceAttachment::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Storage::disk()->exists($attachment->path))->toBeFalse();
});

it('arquivo que não é PDF (mesmo com extensão .pdf) é recusado e nada é guardado', function () {
    $data = cgTemplate($this);

    $res = cgAs($this, 'admin')->post(route('panel.setting.care-guidance.attachments.store', $data['id']), ['file' => cgPdf('MZ... executável disfarçado')], ['Accept' => 'application/json'])->assertUnprocessable();

    expect($res->json('message'))->toBe(__('care_guidance.errors.attachment_not_pdf'))
        ->and(CareGuidanceAttachment::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe([]);
});

it('PDF acima de 5 MB é recusado', function () {
    $data = cgTemplate($this);

    cgAs($this, 'admin')->post(route('panel.setting.care-guidance.attachments.store', $data['id']), ['file' => UploadedFile::fake()->create('grande.pdf', 5121, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    expect(CareGuidanceAttachment::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('no máximo 5 PDFs por modelo', function () {
    $data = cgTemplate($this);

    for ($i = 0; $i < 5; $i++) {
        cgAs($this, 'admin')->post(route('panel.setting.care-guidance.attachments.store', $data['id']), ['file' => cgPdf("%PDF-1.4 n{$i}")], ['Accept' => 'application/json'])->assertCreated();
    }

    $res = cgAs($this, 'admin')->post(route('panel.setting.care-guidance.attachments.store', $data['id']), ['file' => cgPdf('%PDF-1.4 sexto')], ['Accept' => 'application/json'])->assertUnprocessable();

    expect($res->json('message'))->toBe(__('care_guidance.errors.attachment_limit'))
        ->and(CareGuidanceAttachment::query()->withoutGlobalScopes()->count())->toBe(5);
});

it('excluir o modelo apaga os PDFs dele do disco', function () {
    $data = cgTemplate($this);
    cgAs($this, 'admin')->post(route('panel.setting.care-guidance.attachments.store', $data['id']), ['file' => cgPdf()], ['Accept' => 'application/json'])->assertCreated();

    $path = CareGuidanceAttachment::query()->withoutGlobalScopes()->firstOrFail()->path;

    cgAs($this, 'admin')->deleteJson(route('panel.setting.care-guidance.destroy', $data['id']))->assertOk();

    expect(Storage::disk()->exists($path))->toBeFalse();
});

it('anexo de OUTRO modelo (ou de outra clínica) não se baixa nem se remove: 404', function () {
    $mine = cgTemplate($this);
    $b    = cgTemplate($this, ['title' => 'Outro modelo']);
    cgAs($this, 'admin')->post(route('panel.setting.care-guidance.attachments.store', $b['id']), ['file' => cgPdf()], ['Accept' => 'application/json'])->assertCreated();
    $attachment = CareGuidanceAttachment::query()->withoutGlobalScopes()->firstOrFail();

    cgAs($this, 'admin')->get(route('panel.setting.care-guidance.attachments.download', [$mine['id'], $attachment->id]))->assertNotFound();
    cgAs($this, 'admin')->deleteJson(route('panel.setting.care-guidance.attachments.destroy', [$mine['id'], $attachment->id]))->assertUnprocessable();

    expect(CareGuidanceAttachment::query()->withoutGlobalScopes()->count())->toBe(1);
});

// ── emissão (prontuário) ────────────────────────────────────────────────────

it('o médico emite: tudo congelado (texto, local, colírios, olho do procedimento, hash) e o PDF anexo é COPIADO', function () {
    $tpl = cgTemplate($this, ['procedure_id' => $this->facectomy->id]);
    cgAs($this, 'admin')->post(route('panel.setting.care-guidance.attachments.store', $tpl['id']), ['file' => cgPdf()], ['Accept' => 'application/json'])->assertCreated();

    $res = cgIssue($this, $tpl['id'])->assertCreated();

    $issue = CareGuidanceIssue::query()->withoutGlobalScopes()->firstOrFail();

    expect($res->json('message'))->toBe(__('care_guidance.issue.issued'))
        ->and($issue->title)->toBe('Pós-operatório de catarata')
        ->and($issue->body)->toBe("Não coce o olho.\nUse o protetor ao dormir.")
        ->and($issue->location_label)->toBe('Sala de curativos, 2º andar')
        ->and($issue->eye)->toBe('right')
        ->and($issue->eye_drops)->toHaveCount(2)
        ->and((string) $issue->doctor_id)->toBe((string) $this->doctor->id)
        ->and((string) $issue->medical_record_procedure_id)->toBe((string) $this->procedure->id)
        ->and($issue->content_hash)->toMatch(CanonicalJson::HASH_PATTERN)
        ->and($issue->attachments)->toHaveCount(1)
        ->and($issue->attachments[0]['path'])->toContain("issues/{$issue->id}/")
        ->and(Storage::disk()->exists($issue->attachments[0]['path']))->toBeTrue()
        ->and($res->json('data.pdf_url'))->toBe(route('panel.patients.care-guidance.pdf', [$this->patient, $issue->id]))
        ->and($res->json('data.attachments.0.url'))->toBe(route('panel.patients.care-guidance.attachment', [$this->patient, $issue->id, 0]));
});

it('editar ou excluir o modelo depois NÃO muda a orientação entregue (nem o PDF anexo)', function () {
    $tpl = cgTemplate($this);
    cgAs($this, 'admin')->post(route('panel.setting.care-guidance.attachments.store', $tpl['id']), ['file' => cgPdf()], ['Accept' => 'application/json'])->assertCreated();

    $issueId = cgIssue($this, $tpl['id'])->assertCreated()->json('data.id');

    cgAs($this, 'admin')->putJson(route('panel.setting.care-guidance.update', $tpl['id']), cgPayload(['title' => 'Mudou', 'body' => 'Texto novo', 'eye_drops' => []]))->assertOk();
    cgAs($this, 'admin')->deleteJson(route('panel.setting.care-guidance.destroy', $tpl['id']))->assertOk();

    $issue = CareGuidanceIssue::query()->withoutGlobalScopes()->findOrFail($issueId);

    expect($issue->title)->toBe('Pós-operatório de catarata')
        ->and($issue->body)->toContain('protetor ao dormir')
        ->and($issue->eye_drops)->toHaveCount(2)
        ->and(Storage::disk()->exists($issue->attachments[0]['path']))->toBeTrue();

    cgAs($this, 'doctor')->get(route('panel.patients.care-guidance.attachment', [$this->patient, $issueId, 0]))->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('ajustes para este paciente valem só na emissão: o modelo não muda', function () {
    $tpl = cgTemplate($this);

    cgIssue($this, $tpl['id'], [
        'body'           => 'Texto ajustado para este paciente.',
        'location_label' => 'Consultório 3',
        'eye_drops'      => [['name' => 'Lubrificante', 'how' => '1 gota', 'times_per_day' => 6, 'days' => 30]],
    ])->assertCreated();

    $issue = CareGuidanceIssue::query()->withoutGlobalScopes()->firstOrFail();

    expect($issue->body)->toBe('Texto ajustado para este paciente.')
        ->and($issue->location_label)->toBe('Consultório 3')
        ->and($issue->eye_drops)->toBe([['name' => 'Lubrificante', 'how' => '1 gota', 'times_per_day' => 6, 'days' => 30]])
        ->and(CareGuidanceTemplate::query()->withoutGlobalScopes()->firstOrFail()->body)->toContain('Não coce o olho')
        ->and(CareGuidanceTemplate::query()->withoutGlobalScopes()->firstOrFail()->eye_drops)->toHaveCount(2);
});

it('clique duplo não duplica: a mesma orientação do mesmo procedimento devolve a emissão que já existe', function () {
    $tpl = cgTemplate($this);
    cgAs($this, 'admin')->post(route('panel.setting.care-guidance.attachments.store', $tpl['id']), ['file' => cgPdf()], ['Accept' => 'application/json'])->assertCreated();

    $first  = cgIssue($this, $tpl['id'])->assertCreated()->json('data.id');
    $second = cgIssue($this, $tpl['id'])->assertCreated()->json('data.id');

    expect($second)->toBe($first)
        ->and(CareGuidanceIssue::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(collect(Storage::disk()->allFiles())->filter(fn ($f) => str_contains($f, '/issues/'))->count())->toBe(1);

    // Conteúdo diferente = outra emissão.
    $third = cgIssue($this, $tpl['id'], ['body' => 'Outro texto.'])->assertCreated()->json('data.id');

    expect($third)->not->toBe($first)
        ->and(CareGuidanceIssue::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('modelo de outro procedimento, desativado, de outra clínica ou procedimento cancelado: recusa e nada é gravado', function () {
    $forOther = cgTemplate($this, ['procedure_id' => $this->other->id]);
    cgIssue($this, $forOther['id'])->assertUnprocessable()->assertJsonPath('message', __('care_guidance.errors.procedure_mismatch'));

    $inactive = cgTemplate($this, ['active' => false]);
    cgIssue($this, $inactive['id'])->assertUnprocessable()->assertJsonPath('message', __('care_guidance.errors.template_inactive'));

    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $foreign     = CareGuidanceTemplate::query()->withoutGlobalScopes()->create(['entity_id' => $otherEntity->id, 'title' => 'ISCA', 'body' => 'x']);
    cgIssue($this, $foreign->id)->assertUnprocessable()->assertJsonPath('message', __('care_guidance.errors.template_not_found'));

    $ok = cgTemplate($this, ['title' => 'Geral']);
    $this->procedure->forceFill(['status' => MedicalRecordProcedureStatus::Cancelled->value])->saveQuietly();
    cgIssue($this, $ok['id'])->assertUnprocessable()->assertJsonPath('message', __('care_guidance.errors.procedure_cancelled'));

    expect(CareGuidanceIssue::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('PDF do modelo que sumiu do disco: a emissão é recusada por inteiro (sem emissão pela metade)', function () {
    $tpl = cgTemplate($this);
    cgAs($this, 'admin')->post(route('panel.setting.care-guidance.attachments.store', $tpl['id']), ['file' => cgPdf()], ['Accept' => 'application/json'])->assertCreated();

    Storage::disk()->delete(CareGuidanceAttachment::query()->withoutGlobalScopes()->firstOrFail()->path);

    cgIssue($this, $tpl['id'])->assertUnprocessable()->assertJsonPath('message', __('care_guidance.errors.attachment_missing_file'));

    expect(CareGuidanceIssue::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(collect(Storage::disk()->allFiles())->filter(fn ($f) => str_contains($f, '/issues/'))->all())->toBe([]);
});

/** Sobe `$count` PDFs de conteúdos diferentes para o modelo, com `created_at` crescente (a ordem em que a emissão os copia). */
function cgAttachPdfs($test, string $templateId, int $count): array
{
    foreach (range(1, $count) as $n) {
        cgAs($test, 'admin')->post(route('panel.setting.care-guidance.attachments.store', $templateId), ['file' => cgPdf("%PDF-1.4 anexo {$n}")], ['Accept' => 'application/json'])->assertCreated();
    }

    $attachments = CareGuidanceAttachment::query()->withoutGlobalScopes()->where('template_id', $templateId)->get()->values();

    // Os três sobem no mesmo segundo: a ordem do `created_at` seria um empate; fixa para o teste saber qual vem primeiro.
    foreach ($attachments as $index => $attachment) {
        $attachment->forceFill(['created_at' => now()->subMinutes($count - $index)])->saveQuietly();
    }

    return CareGuidanceAttachment::query()->withoutGlobalScopes()->where('template_id', $templateId)->orderBy('created_at')->get()->all();
}

/** @return list<string> arquivos de emissões que sobraram no disco */
function cgIssueFiles(): array
{
    return collect(Storage::disk()->allFiles())->filter(fn ($f) => str_contains($f, '/issues/'))->values()->all();
}

it('vários PDFs e o 3º sumiu do disco: as cópias do 1º e do 2º não ficam órfãs, nem a cada nova tentativa', function () {
    $tpl         = cgTemplate($this);
    $attachments = cgAttachPdfs($this, $tpl['id'], 3);

    Storage::disk()->delete($attachments[2]->path);

    foreach ([1, 2, 3] as $attempt) {
        cgIssue($this, $tpl['id'])->assertUnprocessable()->assertJsonPath('message', __('care_guidance.errors.attachment_missing_file'));
    }

    expect(CareGuidanceIssue::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(cgIssueFiles())->toBe([]);
});

it('a cópia do 2º PDF que FALHA (deixando o arquivo pela metade) desfaz tudo: nem o 1º nem o destino pela metade ficam no disco', function () {
    $tpl = cgTemplate($this);
    cgAttachPdfs($this, $tpl['id'], 3);

    $real   = Storage::disk();
    $copies = 0;
    $flaky  = Mockery::mock($real);
    $flaky->shouldReceive('copy')->andReturnUsing(function (string $from, string $to) use ($real, &$copies): bool {
        if (++$copies === 2) {
            $real->put($to, '%PDF-1.4 pela metade');

            return false;
        }

        return $real->copy($from, $to);
    });
    Storage::set(config('filesystems.default'), $flaky);

    cgIssue($this, $tpl['id'])->assertUnprocessable()->assertJsonPath('message', __('care_guidance.errors.attachment_missing_file'));

    expect($copies)->toBe(2)
        ->and(CareGuidanceIssue::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(cgIssueFiles())->toBe([]);
});

it('a falha da limpeza no disco não troca o motivo da recusa e é registrada', function () {
    $tpl         = cgTemplate($this);
    $attachments = cgAttachPdfs($this, $tpl['id'], 2);

    Storage::disk()->delete($attachments[1]->path);

    $real  = Storage::disk();
    $flaky = Mockery::mock($real);
    $flaky->shouldReceive('delete')->andThrow(new RuntimeException('disco indisponível'));
    Storage::set(config('filesystems.default'), $flaky);

    $reported = [];
    app(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported): bool {
        $reported[] = $e->getMessage();

        return false;
    });

    cgIssue($this, $tpl['id'])->assertUnprocessable()->assertJsonPath('message', __('care_guidance.errors.attachment_missing_file'));

    expect($reported)->toContain('disco indisponível')
        ->and(CareGuidanceIssue::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('a emissão é imutável', function () {
    $tpl = cgTemplate($this);
    cgIssue($this, $tpl['id'])->assertCreated();

    $issue = CareGuidanceIssue::query()->withoutGlobalScopes()->firstOrFail();

    expect(fn () => $issue->update(['body' => 'reescrita']))->toThrow(LogicException::class)
        ->and($issue->refresh()->body)->toContain('Não coce');
});

// ── ACL, clínica e leitura ─────────────────────────────────────────────────

it('só o médico emite (secretária 403) e só ele vê os modelos; a recepção vê o que foi entregue', function () {
    $tpl = cgTemplate($this);

    cgAs($this, 'secretary')->postJson(route('panel.patients.medicalrecord-procedures.care-guidance.issue', [$this->patient->id, $this->procedure->id]), ['template_id' => $tpl['id']])->assertForbidden();

    cgIssue($this, $tpl['id'])->assertCreated();

    $desk = cgAs($this, 'secretary')->getJson(route('panel.patients.medicalrecord-procedures.care-guidance', [$this->patient->id, $this->procedure->id]))->assertOk();

    expect($desk->json('data.can_issue'))->toBeFalse()
        ->and($desk->json('data.templates'))->toBe([])
        ->and($desk->json('data.issues'))->toHaveCount(1)
        ->and($desk->json('data.issues.0.pdf_url'))->not->toBeEmpty();

    $doc = cgAs($this, 'doctor')->getJson(route('panel.patients.medicalrecord-procedures.care-guidance', [$this->patient->id, $this->procedure->id]))->assertOk();

    expect($doc->json('data.can_issue'))->toBeTrue()
        ->and($doc->json('data.templates'))->toHaveCount(1)
        ->and($doc->json('data.t.issue.title'))->not->toBeEmpty();
});

it('os modelos oferecidos são os do procedimento e os gerais — nunca os de outro procedimento nem os desativados', function () {
    cgTemplate($this, ['title' => 'Geral']);
    cgTemplate($this, ['title' => 'Da facectomia', 'procedure_id' => $this->facectomy->id]);
    cgTemplate($this, ['title' => 'Do pterígio', 'procedure_id' => $this->other->id]);
    cgTemplate($this, ['title' => 'Desativado', 'active' => false]);

    $titles = collect(cgAs($this, 'doctor')->getJson(route('panel.patients.medicalrecord-procedures.care-guidance', [$this->patient->id, $this->procedure->id]))->json('data.templates'))->pluck('title')->all();

    // O específico do procedimento vem antes do geral.
    expect($titles)->toBe(['Da facectomia', 'Geral']);
});

it('procedimento de outro paciente ou de outra clínica: 404', function () {
    $tpl = cgTemplate($this);

    $otherPatient = Patient::factory()->create(['entity_id' => $this->entity->id]);

    cgAs($this, 'doctor')->getJson(route('panel.patients.medicalrecord-procedures.care-guidance', [$otherPatient->id, $this->procedure->id]))->assertNotFound();
    cgAs($this, 'doctor')->postJson(route('panel.patients.medicalrecord-procedures.care-guidance.issue', [$otherPatient->id, $this->procedure->id]), ['template_id' => $tpl['id']])->assertNotFound();

    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $foreignPat  = Patient::factory()->create(['entity_id' => $otherEntity->id]);
    cgAs($this, 'doctor')->getJson(route('panel.patients.medicalrecord-procedures.care-guidance', [$foreignPat->id, $this->procedure->id]))->assertNotFound();
});

it('PDF e anexo de orientação de OUTRO paciente (mesmo na clínica) ou de outra clínica não abrem: 404', function () {
    $tpl     = cgTemplate($this);
    $issueId = cgIssue($this, $tpl['id'])->assertCreated()->json('data.id');

    $otherPatient = Patient::factory()->create(['entity_id' => $this->entity->id]);

    cgAs($this, 'doctor')->get(route('panel.patients.care-guidance.pdf', [$otherPatient->id, $issueId]))->assertNotFound();
    cgAs($this, 'doctor')->get(route('panel.patients.care-guidance.attachment', [$otherPatient->id, $issueId, 0]))->assertNotFound();

    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $foreignPat  = Patient::factory()->create(['entity_id' => $otherEntity->id]);

    cgAs($this, 'doctor')->get(route('panel.patients.care-guidance.pdf', [$foreignPat->id, $issueId]))->assertNotFound();
});

it('o PDF é montado SÓ do retrato congelado (view pdf.care_guidance com a emissão)', function () {
    $tpl     = cgTemplate($this);
    $issueId = cgIssue($this, $tpl['id'])->assertCreated()->json('data.id');

    cgAs($this, 'admin')->putJson(route('panel.setting.care-guidance.update', $tpl['id']), cgPayload(['body' => 'TEXTO NOVO DO MODELO']))->assertOk();

    $pdf = Mockery::mock();
    $pdf->shouldReceive('setPaper', 'setOption')->andReturnSelf();
    $pdf->shouldReceive('inline')->andReturn(response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']));

    SnappyPdf::shouldReceive('loadView')->once()->withArgs(function (string $view, array $data) use ($issueId) {
        return $view === 'pdf.care_guidance'
            && (string) $data['issue']->id === $issueId
            && str_contains($data['issue']->body, 'protetor ao dormir')
            && ! str_contains($data['issue']->body, 'TEXTO NOVO DO MODELO');
    })->andReturn($pdf);

    cgAs($this, 'secretary')->get(route('panel.patients.care-guidance.pdf', [$this->patient, $issueId]))->assertOk();
});

it('a view do PDF escapa o texto (nada de HTML do modelo no papel), traz local, olho, colírios e anexos', function () {
    $tpl = cgTemplate($this, ['body' => "<script>alert('x')</script>\nSegunda linha", 'title' => 'Titulo <b>x</b>']);
    cgAs($this, 'admin')->post(route('panel.setting.care-guidance.attachments.store', $tpl['id']), ['file' => cgPdf()], ['Accept' => 'application/json'])->assertCreated();
    $issueId = cgIssue($this, $tpl['id'])->assertCreated()->json('data.id');

    $issue = CareGuidanceIssue::query()->withoutGlobalScopes()->findOrFail($issueId);
    $html  = view('pdf.care_guidance', ['issue' => $issue, 'patient' => $this->patient, 'entity' => $this->entity, 'setting' => null, 'doctor' => $this->doctor])->render();

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;')
        ->and($html)->toContain('Titulo &lt;b&gt;x&lt;/b&gt;')
        ->and($html)->toContain('Segunda linha')
        ->and($html)->toContain('Sala de curativos, 2º andar')
        ->and($html)->toContain(__('care_guidance.pdf.eyes.right'))
        ->and($html)->toContain('Moxifloxacino')
        ->and($html)->toContain('preparo.pdf');
});

it('o item de menu das orientações existe para o admin e a rota resolve', function () {
    $nav = collect(PanelNavigation::build());
    session(panelSession($this->adminEu));
    $this->actingAs($this->adminAccount);

    $flat = json_encode(PanelNavigation::build());

    expect($flat)->toContain('panel.setting.care-guidance.index')
        ->and(route('panel.setting.care-guidance.index'))->toContain('/panel/setting/care-guidance');
});

// ── defesa em profundidade: o serviço recusa sozinho o que o request também barra ──

it('o serviço recusa PDF acima de 5 MB e mais de 10 colírios mesmo sem passar pelo request', function () {
    $template = CareGuidanceTemplate::query()->withoutGlobalScopes()->create(['entity_id' => $this->entity->id, 'title' => 'T', 'body' => 'x']);
    $service  = app(CareGuidanceTemplateService::class);

    $huge = UploadedFile::fake()->createWithContent('grande.pdf', '%PDF-' . str_repeat('a', 5 * 1024 * 1024));

    expect(fn () => $service->addAttachment($template, $huge))->toThrow(CareGuidanceException::class)
        ->and(CareGuidanceAttachment::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(fn () => $service->normalizeDrops(array_fill(0, 11, ['name' => 'X'])))->toThrow(CareGuidanceException::class);

    // No limite exato passa.
    $edge = UploadedFile::fake()->createWithContent('limite.pdf', '%PDF-' . str_repeat('a', 5 * 1024 * 1024 - 5));
    $service->addAttachment($template, $edge);

    expect(CareGuidanceAttachment::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and($service->normalizeDrops(array_fill(0, 10, ['name' => 'X'])))->toHaveCount(10);
});

it('a mesma orientação só conta como a mesma dentro da janela: depois dela, é uma nova emissão', function () {
    $tpl   = cgTemplate($this);
    $first = cgIssue($this, $tpl['id'])->assertCreated()->json('data.id');

    $this->travelTo(now()->addMinutes(CareGuidanceIssueService::DEDUPE_MINUTES + 1));

    $later = cgIssue($this, $tpl['id'])->assertCreated()->json('data.id');

    expect($later)->not->toBe($first)
        ->and(CareGuidanceIssue::query()->withoutGlobalScopes()->count())->toBe(2);
});
