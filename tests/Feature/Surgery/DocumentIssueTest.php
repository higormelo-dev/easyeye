<?php

declare(strict_types=1);

use App\Enums\Surgery\{SurgeryDocumentType, SurgicalCaseStatus};
use App\Enums\Surgery\SurgeryEye;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\DataAccessLog;
use App\Models\Surgery\{SurgeryDocumentIssue, SurgicalCase};
use App\Services\Surgery\Documents\{SurgeryDocumentService, SurgeryTemplateService};
use App\Services\Surgery\Documents\SurgeryTemplateVariables;
use App\Services\Surgery\Guards\ConsentSignedGuard;
use App\Services\Surgery\Sections\DocumentsSection;
use App\Services\Surgery\{SurgeryDocumentIssueService, SurgicalCaseService};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Módulo cirúrgico (Fase 4) / M3a — emissão de TCLE e orientações
 * pré-operatórias: retrato congelado, dedupe, reemissão, PDF com log de
 * acesso, cópia assinada (tipo pelo conteúdo, tamanho), isolamento entre
 * clínicas, perfis, aba e a condição opcional "TCLE assinado" para realizar.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->w        = surgeryWorld();
    $this->template = app(SurgeryTemplateService::class)->create((string) $this->w->entity->id, [
        'type' => 'consent', 'name' => 'TCLE Faco', 'category' => 1,
        'body' => '<p>Eu, {{paciente}}, autorizo a cirurgia {{cirurgia}} ({{olho}}).</p>{{procedimentos}}',
    ]);
});

function sdiCase(object $w, array $over = []): SurgicalCase
{
    return app(SurgicalCaseService::class)->create((string) $w->entity->id, array_merge([
        'patient_id' => (string) $w->patient->id, 'surgeon_doctor_id' => (string) $w->doctor->id,
        'title'      => 'Facoemulsificação com LIO', 'eye' => 'od',
        'procedures' => [['description' => 'Facoemulsificação com implante de LIO', 'tuss_code' => '30306019']],
    ], $over));
}

function sdiIssue(object $t, string $who, SurgicalCase $case, array $over = [])
{
    return surgeryAs($t->w, $who)->postJson(route('panel.surgery.documents.store', $case), array_merge([
        'type'    => 'consent', 'template_id' => (string) $t->template->id, 'title' => 'Termo de consentimento',
        'content' => '<p>Eu, PACIENTE CIRURGICO, autorizo a cirurgia.</p>',
    ], $over));
}

function sdiPdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
}

// ── Prévia ──────────────────────────────────────────────────────────────────

it('monta a prévia com os dados do caso e o título do modelo; a secretária pode', function () {
    $case = sdiCase($this->w);

    $response = surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.documents.preview', $case), [
        'type' => 'consent', 'template_id' => (string) $this->template->id,
    ])->assertOk();

    expect($response->json('title'))->toBe('TCLE Faco')
        ->and($response->json('html'))->toContain('PACIENTE CIRURGICO')->toContain('Olho direito (OD)')->toContain('30306019')
        ->and($response->json('template'))->toBe(['id' => (string) $this->template->id, 'version' => 1])
        ->and($response->json('pending'))->toBe([]);
});

it('a prévia recusa modelo de outra clínica, inativo ou de outro tipo', function () {
    $case    = sdiCase($this->w);
    $foreign = app(SurgeryTemplateService::class)->create((string) $this->w->other->id, ['type' => 'consent', 'name' => 'Alheio', 'body' => '<p>{{paciente}} texto longo</p>']);
    $preOp   = app(SurgeryTemplateService::class)->create((string) $this->w->entity->id, ['type' => 'pre_op_guidance', 'name' => 'Pré', 'body' => '<p>{{paciente}} texto longo</p>']);
    $off     = app(SurgeryTemplateService::class)->create((string) $this->w->entity->id, ['type' => 'consent', 'name' => 'Off', 'body' => '<p>{{paciente}} texto longo</p>', 'active' => false]);

    foreach ([$foreign, $preOp, $off] as $template) {
        surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.documents.preview', $case), ['type' => 'consent', 'template_id' => (string) $template->id])
            ->assertStatus(422)->assertJsonPath('message', __('surgery_documents.errors.template_not_found'));
    }
});

// ── Emissão ─────────────────────────────────────────────────────────────────

it('emite o TCLE: retrato congelado com hash, modelo/versão e quem emitiu', function () {
    $case = sdiCase($this->w);

    $response = sdiIssue($this, 'secretary', $case)->assertCreated();

    $issue = SurgeryDocumentIssue::query()->findOrFail($response->json('id'));

    expect($issue->type)->toBe(SurgeryDocumentType::Consent)
        ->and($issue->content_hash)->toHaveLength(64)
        ->and((string) $issue->template_id)->toBe((string) $this->template->id)
        ->and($issue->template_version)->toBe(1)
        ->and((string) $issue->issued_by)->toBe((string) $this->w->users['secretary']->id)
        ->and($issue->issued_by_doctor_id)->toBeNull()
        ->and($issue->isCurrent())->toBeTrue()
        ->and($issue->payload['data']['items'][0]['value'])->toContain('DR. CIRURGIAO')
        ->and($response->json('pdf'))->toBe(route('panel.surgery.documents.pdf', ['surgicalCase' => $case->id, 'document' => $issue->id]));
});

it('o médico que emite fica como signatário do documento', function () {
    $case = sdiCase($this->w);

    $issue = SurgeryDocumentIssue::query()->findOrFail(sdiIssue($this, 'doctor', $case)->assertCreated()->json('id'));

    expect((string) $issue->issued_by_doctor_id)->toBe((string) $this->w->doctor->id)
        ->and($issue->payload['signer']['name'])->toBe('DR. CIRURGIAO');
});

it('editar o modelo depois NÃO altera o documento emitido', function () {
    $case  = sdiCase($this->w);
    $issue = SurgeryDocumentIssue::query()->findOrFail(sdiIssue($this, 'secretary', $case)->json('id'));
    $hash  = $issue->content_hash;

    app(SurgeryTemplateService::class)->update($this->template, ['body' => '<p>Texto TOTALMENTE novo para {{paciente}}</p>']);

    $issue->refresh();

    expect($issue->content)->toContain('autorizo a cirurgia')->not->toContain('TOTALMENTE')
        ->and($issue->content_hash)->toBe($hash)
        ->and($issue->template_version)->toBe(1)
        ->and($this->template->refresh()->version)->toBe(2);

    // O documento emitido é imutável por qualquer caminho do model.
    expect(fn () => $issue->update(['content' => '<p>adulterado</p>']))->toThrow(LogicException::class);
    expect(fn () => $issue->delete())->toThrow(LogicException::class);
});

it('o mesmo conteúdo emitido de novo em seguida devolve a emissão existente (clique duplo)', function () {
    $case = sdiCase($this->w);

    $first  = sdiIssue($this, 'secretary', $case)->assertCreated()->json('id');
    $second = sdiIssue($this, 'secretary', $case)->assertCreated()->json('id');

    expect($second)->toBe($first)->and(SurgeryDocumentIssue::query()->count())->toBe(1);
});

it('reemitir com texto diferente cria nova linha e a anterior vira histórico', function () {
    $case = sdiCase($this->w);

    $first  = sdiIssue($this, 'secretary', $case)->json('id');
    $second = sdiIssue($this, 'secretary', $case, ['content' => '<p>Texto corrigido pelo médico.</p>'])->json('id');

    expect($second)->not->toBe($first);

    $old = SurgeryDocumentIssue::query()->findOrFail($first);
    $new = SurgeryDocumentIssue::query()->findOrFail($second);

    expect((string) $old->superseded_by_id)->toBe($second)
        ->and($old->isCurrent())->toBeFalse()
        ->and($new->isCurrent())->toBeTrue();
});

it('TCLE e orientações do mesmo caso são independentes: cada tipo tem o seu vigente', function () {
    $case = sdiCase($this->w);
    $pre  = app(SurgeryTemplateService::class)->create((string) $this->w->entity->id, ['type' => 'pre_op_guidance', 'name' => 'Pré', 'body' => '<p>Orientações para {{paciente}}.</p>']);

    sdiIssue($this, 'secretary', $case)->assertCreated();
    sdiIssue($this, 'secretary', $case, ['type' => 'pre_op_guidance', 'template_id' => (string) $pre->id, 'title' => 'Orientações', 'content' => '<p>Jejum de 8 horas.</p>'])->assertCreated();

    expect(SurgeryDocumentIssue::query()->whereNull('superseded_by_id')->count())->toBe(2);
});

it('recusa variável desconhecida, texto vazio, tipo que não é daqui e caso cancelado — sem gravar', function () {
    $case = sdiCase($this->w);

    sdiIssue($this, 'secretary', $case, ['content' => '<p>Olá {{cpf}}</p>'])->assertStatus(422)
        ->assertJsonPath('message', __('surgery_documents.errors.unknown_variables', ['variables' => '{{cpf}}']));
    sdiIssue($this, 'secretary', $case, ['content' => '<p> </p>'])->assertStatus(422)
        ->assertJsonPath('message', __('surgery_documents.errors.empty_content'));
    sdiIssue($this, 'secretary', $case, ['type' => 'implant_record'])->assertStatus(422)->assertJsonValidationErrors('type');
    sdiIssue($this, 'secretary', $case, ['title' => ''])->assertStatus(422)->assertJsonValidationErrors('title');
    sdiIssue($this, 'secretary', $case, ['template_id' => 'nao-e-uuid'])->assertStatus(422)->assertJsonValidationErrors('template_id');

    app(SurgicalCaseService::class)->cancel($case, 'Paciente desistiu');
    sdiIssue($this, 'secretary', $case)->assertStatus(409);

    expect(SurgeryDocumentIssue::query()->count())->toBe(0);
});

it('o texto emitido é sanitizado (sem script nem manipulador de evento)', function () {
    $case = sdiCase($this->w);

    $issue = SurgeryDocumentIssue::query()->findOrFail(sdiIssue($this, 'secretary', $case, [
        'content' => '<p onclick="x()">Texto</p><script>alert(1)</script><img src="x" onerror="y()">',
    ])->json('id'));

    expect($issue->content)->toContain('Texto')->not->toContain('<script')->not->toContain('onclick')->not->toContain('onerror')->not->toContain('<img');
});

it('perfil e clínica: financeiro 403, médico de fora do caso 403, outra clínica 404', function () {
    $case  = sdiCase($this->w);
    $other = createDoctorForEntity($this->w->entity);
    $mine  = sdiCase($this->w, ['title' => 'Do colega', 'surgeon_doctor_id' => (string) $other->id]);

    sdiIssue($this, 'financial', $case)->assertForbidden();
    sdiIssue($this, 'doctor', $mine)->assertForbidden();

    $w2 = surgeryWorld();
    surgeryAs($w2, 'admin')->postJson(route('panel.surgery.documents.preview', $case), ['type' => 'consent'])->assertNotFound();
    surgeryAs($w2, 'admin')->postJson(route('panel.surgery.documents.store', $case), ['type' => 'consent', 'title' => 'x', 'content' => '<p>x</p>'])->assertNotFound();

    expect(SurgeryDocumentIssue::query()->count())->toBe(0);
});

// ── PDF ─────────────────────────────────────────────────────────────────────

it('o PDF sai do retrato gravado e o acesso entra no log de dados sensíveis', function () {
    $case  = sdiCase($this->w);
    $issue = SurgeryDocumentIssue::query()->findOrFail(sdiIssue($this, 'secretary', $case)->json('id'));

    $response = surgeryAs($this->w, 'secretary')->get(route('panel.surgery.documents.pdf', ['surgicalCase' => $case, 'document' => $issue]))->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/pdf');

    $log = DataAccessLog::query()->where('resource_id', $issue->id)->firstOrFail();

    expect((string) $log->patient_id)->toBe((string) $this->w->patient->id)
        ->and((string) $log->entity_id)->toBe((string) $this->w->entity->id)
        ->and($log->resource_type)->toBe(SurgeryDocumentIssue::class)
        ->and($log->purpose->value)->toBe('administrative');
});

it('PDF e cópia assinada: documento de outro caso ou de outra clínica é 404', function () {
    $case  = sdiCase($this->w);
    $other = sdiCase($this->w, ['title' => 'Outro caso']);
    $issue = SurgeryDocumentIssue::query()->findOrFail(sdiIssue($this, 'secretary', $case)->json('id'));

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.documents.pdf', ['surgicalCase' => $other, 'document' => $issue]))->assertNotFound();
    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.documents.signed-copy.show', ['surgicalCase' => $other, 'document' => $issue]))->assertNotFound();

    $w2 = surgeryWorld();
    surgeryAs($w2, 'admin')->get(route('panel.surgery.documents.pdf', ['surgicalCase' => $case, 'document' => $issue]))->assertNotFound();
    surgeryAs($w2, 'admin')->get(route('panel.surgery.documents.signed-copy.show', ['surgicalCase' => $case, 'document' => $issue]))->assertNotFound();
});

// ── Cópia assinada ──────────────────────────────────────────────────────────

it('anexa a cópia assinada (PDF), guarda hash e metadados, troca a vigente mantendo o arquivo anterior e serve só autenticado', function () {
    $case  = sdiCase($this->w);
    $issue = SurgeryDocumentIssue::query()->findOrFail(sdiIssue($this, 'secretary', $case)->json('id'));
    $url   = route('panel.surgery.documents.signed-copy.store', ['surgicalCase' => $case, 'document' => $issue]);

    surgeryAs($this->w, 'secretary')->post($url, ['file' => UploadedFile::fake()->createWithContent('termo-assinado.pdf', sdiPdfBytes())])->assertRedirect();

    $issue->refresh();
    $firstPath = $issue->signed_copy_path;

    expect($issue->hasSignedCopy())->toBeTrue()
        ->and($issue->signed_copy_mime)->toBe('application/pdf')
        ->and($issue->signed_copy_hash)->toBe(hash('sha256', sdiPdfBytes()))
        ->and((string) $issue->signed_copy_uploaded_by)->toBe((string) $this->w->users['secretary']->id);
    Storage::disk('local')->assertExists($firstPath);

    // O conteúdo emitido não muda com a cópia anexada.
    expect($issue->content_hash)->toHaveLength(64);

    $show = route('panel.surgery.documents.signed-copy.show', ['surgicalCase' => $case, 'document' => $issue]);
    $got  = surgeryAs($this->w, 'secretary')->get($show)->assertOk();
    expect($got->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($got->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect(DataAccessLog::query()->where('resource_id', $issue->id)->count())->toBe(1);

    // Troca antes da cirurgia: o arquivo anterior continua guardado (trilha), só deixa de ser a cópia vigente.
    surgeryAs($this->w, 'secretary')->post($url, ['file' => UploadedFile::fake()->image('assinado.jpg')])->assertRedirect();
    $issue->refresh();

    expect($issue->signed_copy_mime)->toBe('image/jpeg')->and($issue->signed_copy_path)->not->toBe($firstPath);
    Storage::disk('local')->assertExists($firstPath);

    auth()->logout();
    test()->flushSession();
    $this->get($show)->assertRedirect();
});

it('recusa arquivo disfarçado, tamanho acima do limite e sem arquivo', function () {
    $case  = sdiCase($this->w);
    $issue = SurgeryDocumentIssue::query()->findOrFail(sdiIssue($this, 'secretary', $case)->json('id'));
    $url   = route('panel.surgery.documents.signed-copy.store', ['surgicalCase' => $case, 'document' => $issue]);

    // Extensão .pdf e Content-Type de PDF, mas o conteúdo é um executável.
    surgeryAs($this->w, 'secretary')->postJson($url, ['file' => UploadedFile::fake()->createWithContent('termo.pdf', "MZ\x90\x00\x03\x00\x00\x00 programa")])
        ->assertStatus(422)->assertJsonPath('message', __('surgery.errors.signed_copy_type'));

    surgeryAs($this->w, 'secretary')->postJson($url, ['file' => UploadedFile::fake()->create('grande.pdf', 11 * 1024, 'application/pdf')])
        ->assertStatus(422)->assertJsonValidationErrors('file');

    surgeryAs($this->w, 'secretary')->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('file');

    expect($issue->refresh()->hasSignedCopy())->toBeFalse();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('sem cópia anexada o download é 404; financeiro e outra clínica não anexam', function () {
    $case  = sdiCase($this->w);
    $issue = SurgeryDocumentIssue::query()->findOrFail(sdiIssue($this, 'secretary', $case)->json('id'));
    $store = route('panel.surgery.documents.signed-copy.store', ['surgicalCase' => $case, 'document' => $issue]);

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.documents.signed-copy.show', ['surgicalCase' => $case, 'document' => $issue]))->assertNotFound();
    surgeryAs($this->w, 'financial')->postJson($store, ['file' => UploadedFile::fake()->createWithContent('a.pdf', sdiPdfBytes())])->assertForbidden();
    surgeryAs(surgeryWorld(), 'admin')->postJson($store, ['file' => UploadedFile::fake()->createWithContent('a.pdf', sdiPdfBytes())])->assertNotFound();

    expect($issue->refresh()->hasSignedCopy())->toBeFalse();
});

// ── Aba ─────────────────────────────────────────────────────────────────────

it('a aba lista os documentos de qualquer etapa, o que falta assinar e o que se pode emitir', function () {
    $case = sdiCase($this->w);
    sdiIssue($this, 'secretary', $case)->assertCreated();

    // Documento de outra etapa (termo do implante) emitido pelo médico.
    test()->actingAs($this->w->users['doctor'])->withSession(panelSession($this->w->eus['doctor']));
    app(SurgeryDocumentIssueService::class)->issue($case, SurgeryDocumentType::ImplantRecord, 'Registro do implante', '<p>Lote 123</p>');

    $page = surgeryAs($this->w, 'secretary')->get(route('panel.surgery.show', $case))
        ->assertInertia(fn ($p) => $p
            ->where('tabs', fn ($tabs) => collect($tabs)->pluck('key')->contains('documents'))
            ->has('sections.documents.documents', 2)
            ->has('sections.documents.pending_signature', 2)
            ->where('sections.documents.can_issue', true)
            ->has('sections.documents.issuable', 2)
            ->has('t.documents.ui.title'));

    expect($page)->not->toBeNull();

    // Anexa a cópia do TCLE: sai da lista de pendências.
    $consent = SurgeryDocumentIssue::query()->where('type', 'consent')->firstOrFail();
    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.documents.signed-copy.store', ['surgicalCase' => $case, 'document' => $consent]), ['file' => UploadedFile::fake()->createWithContent('a.pdf', sdiPdfBytes())]);

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.show', $case))
        ->assertInertia(fn ($p) => $p->has('sections.documents.pending_signature', 1)->where('sections.documents.pending_signature.0.type_label', SurgeryDocumentType::ImplantRecord->label()));
});

it('a pendência não conta documento substituído nem orientação (assinatura opcional)', function () {
    $case = sdiCase($this->w);
    $pre  = app(SurgeryTemplateService::class)->create((string) $this->w->entity->id, ['type' => 'pre_op_guidance', 'name' => 'Pré', 'body' => '<p>Orientações para {{paciente}}.</p>']);

    sdiIssue($this, 'secretary', $case, ['content' => '<p>Versão 1</p>']);
    sdiIssue($this, 'secretary', $case, ['content' => '<p>Versão 2</p>']);
    sdiIssue($this, 'secretary', $case, ['type' => 'pre_op_guidance', 'template_id' => (string) $pre->id, 'title' => 'Orientações', 'content' => '<p>Jejum.</p>']);

    $props = app(DocumentsSection::class)->props($case->fresh(), ['manage' => true, 'perform' => false]);

    expect($props['documents'])->toHaveCount(3)
        ->and($props['pending_signature'])->toHaveCount(1)
        ->and(collect($props['documents'])->where('current', false))->toHaveCount(1)
        ->and(SurgeryDocumentService::requiresSignature(SurgeryDocumentType::PreOpGuidance))->toBeFalse();
});

it('a emissão some da aba para caso cancelado e para quem só consulta', function () {
    $case = sdiCase($this->w);
    app(SurgicalCaseService::class)->cancel($case, 'Cancelado');

    $props = app(DocumentsSection::class)->props($case->fresh(), ['manage' => true, 'perform' => false]);
    expect($props['can_issue'])->toBeFalse()->and($props['issuable'])->toBe([]);

    $props = app(DocumentsSection::class)->props(sdiCase($this->w, ['title' => 'Outro']), ['manage' => false, 'perform' => false]);
    expect($props['can_issue'])->toBeFalse()->and($props['can_attach'])->toBeFalse();
});

// ── Condição opcional para realizar ─────────────────────────────────────────

it('ConsentSignedGuard: exige o TCLE vigente com cópia assinada, ou a justificativa do cirurgião', function () {
    $case  = sdiCase($this->w);
    $guard = new ConsentSignedGuard();
    $doc   = $this->w->doctor;

    expect(fn () => $guard->assert($case, [], $doc))->toThrow(SurgeryRuleException::class);

    $issue = SurgeryDocumentIssue::query()->findOrFail(sdiIssue($this, 'secretary', $case)->json('id'));
    expect(fn () => $guard->assert($case, [], $doc))->toThrow(SurgeryRuleException::class);
    expect(fn () => $guard->assert($case, ['consent_override_reason' => 'curto'], $doc))->toThrow(SurgeryRuleException::class);

    $guard->assert($case, ['consent_override_reason' => 'Urgência: paciente sem condição de assinar'], $doc);

    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.documents.signed-copy.store', ['surgicalCase' => $case, 'document' => $issue]), ['file' => UploadedFile::fake()->createWithContent('a.pdf', sdiPdfBytes())]);
    $guard->assert($case, [], $doc);

    // Reemitido: a cópia assinada era da emissão anterior — falta de novo.
    sdiIssue($this, 'secretary', $case, ['content' => '<p>Texto reemitido.</p>']);
    expect(fn () => $guard->assert($case, [], $doc))->toThrow(SurgeryRuleException::class);
});

// ── O termo assinado precisa AINDA cobrir o pedido ──────────────────────────

/** Emite o TCLE do caso e anexa a cópia assinada. */
function sdiSignedConsent(object $t, SurgicalCase $case, array $over = []): SurgeryDocumentIssue
{
    $issue = SurgeryDocumentIssue::query()->findOrFail(sdiIssue($t, 'secretary', $case, $over)->json('id'));

    surgeryAs($t->w, 'secretary')->post(route('panel.surgery.documents.signed-copy.store', ['surgicalCase' => $case, 'document' => $issue]), ['file' => UploadedFile::fake()->createWithContent('a.pdf', sdiPdfBytes())])->assertRedirect();

    return $issue->refresh();
}

it('TCLE assinado para um olho deixa de valer quando o pedido muda para o outro: desatualizado até reemitir e assinar de novo', function () {
    $case  = sdiCase($this->w); // OD
    $guard = new ConsentSignedGuard();
    $doc   = $this->w->doctor;

    $issue = sdiSignedConsent($this, $case);

    $guard->assert($case, [], $doc);
    expect(ConsentSignedGuard::state($case))->toBe('signed');

    // O rascunho passa de OD para OE: o papel assinado segue "vigente" e "assinado", mas fala do outro olho.
    app(SurgicalCaseService::class)->update($case, ['eye' => 'oe']);
    $case->refresh();

    expect(ConsentSignedGuard::state($case))->toBe('outdated')
        ->and($issue->refresh()->isCurrent())->toBeTrue()
        ->and($issue->hasSignedCopy())->toBeTrue();

    expect(fn () => $guard->assert($case, [], $doc))
        ->toThrow(SurgeryRuleException::class, __('surgery_documents.errors.consent_outdated', ['fields' => __('surgery_documents.consent_fields.eye')]));

    // Só a justificativa do cirurgião (mínimo de 10 caracteres) libera.
    expect(fn () => $guard->assert($case, ['consent_override_reason' => 'curto'], $doc))->toThrow(SurgeryRuleException::class);
    $guard->assert($case, ['consent_override_reason' => 'Urgência: sem condição de reassinar'], $doc);

    // Reemitir (agora para o OE) volta a exigir a cópia assinada — e com ela vale de novo.
    $second = sdiSignedConsent($this, $case, ['content' => '<p>Termo do olho esquerdo.</p>']);

    expect((string) $second->id)->not->toBe((string) $issue->id)
        ->and(ConsentSignedGuard::state($case))->toBe('signed');

    $guard->assert($case, [], $doc);
});

it('mudar procedimento ou cirurgião invalida o termo; data, local, título e a LIO planejada não', function () {
    $service = app(SurgicalCaseService::class);

    // Não invalidam: título (rótulo), duração, LIO planejada (a potência se ajusta com a biometria).
    $case = sdiCase($this->w);
    sdiSignedConsent($this, $case);

    $service->update($case, [
        'title'                      => 'Outro rótulo da cirurgia',
        'estimated_duration_minutes' => 45,
        'iol_plans'                  => [['eye' => 'od', 'model_label' => 'LIO X', 'power' => 21.5]],
    ]);
    $service->update($case->refresh(), ['iol_plans' => [['eye' => 'od', 'model_label' => 'LIO X', 'power' => 22.0]]]);

    expect(ConsentSignedGuard::state($case->refresh()))->toBe('signed');

    // Invalida: outro procedimento.
    $service->update($case, ['procedures' => [['description' => 'Vitrectomia posterior', 'tuss_code' => '30307058']]]);

    expect(ConsentSignedGuard::state($case->refresh()))->toBe('outdated');

    // Invalida: outro cirurgião.
    $case2 = sdiCase($this->w, ['title' => 'Segundo caso']);
    sdiSignedConsent($this, $case2);
    expect(ConsentSignedGuard::state($case2))->toBe('signed');

    $newSurgeon = createDoctorForEntity($this->w->entity);
    $service->update($case2, ['surgeon_doctor_id' => (string) $newSurgeon->id]);

    expect(ConsentSignedGuard::state($case2->refresh()))->toBe('outdated');
});

it('anestesia: em branco na emissão (preenchida à mão no papel) não invalida ao ser definida; definida na emissão, invalida ao mudar', function () {
    $service = app(SurgicalCaseService::class);

    $blank = sdiCase($this->w);
    sdiSignedConsent($this, $blank);
    $service->update($blank, ['anesthesia' => 'topical']);

    expect(ConsentSignedGuard::state($blank->refresh()))->toBe('signed');

    $set = sdiCase($this->w, ['title' => 'Com anestesia', 'anesthesia' => 'topical']);
    sdiSignedConsent($this, $set);

    expect(ConsentSignedGuard::state($set->refresh()))->toBe('signed');

    $service->update($set, ['anesthesia' => 'general']);

    expect(ConsentSignedGuard::state($set->refresh()))->toBe('outdated');
});

it('a prévia devolve o retrato do pedido; emitir com o retrato velho (o pedido mudou no meio) é recusado e a prévia nova emite', function () {
    $case = sdiCase($this->w);

    $preview = surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.documents.preview', $case), ['type' => 'consent', 'template_id' => (string) $this->template->id])->assertOk();
    $hash    = $preview->json('basis_hash');

    expect($hash)->toBeString()->toHaveLength(64);

    // Outra pessoa troca o olho enquanto o texto era editado.
    app(SurgicalCaseService::class)->update($case, ['eye' => 'oe']);

    sdiIssue($this, 'secretary', $case, ['basis_hash' => $hash, 'content' => '<p>Texto do olho direito.</p>'])
        ->assertStatus(409)
        ->assertJsonPath('message', __('surgery_documents.errors.consent_basis_changed'));

    expect(SurgeryDocumentIssue::query()->count())->toBe(0);

    $fresh = surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.documents.preview', $case), ['type' => 'consent', 'template_id' => (string) $this->template->id])->json('basis_hash');

    expect($fresh)->not->toBe($hash);

    sdiIssue($this, 'secretary', $case, ['basis_hash' => $fresh, 'content' => '<p>Texto do olho esquerdo.</p>'])->assertCreated();

    // O retrato fica gravado no termo (e entra no hash do conteúdo).
    $issue = SurgeryDocumentIssue::query()->firstOrFail();

    expect($issue->payload['data']['consent_basis']['eye'])->toBe('oe')
        ->and($issue->payload['data']['consent_basis']['procedures'])->toBe(['30306019|facoemulsificação com implante de lio']);
});

it('a aba Documentos avisa do termo desatualizado e a Condição para realizar mostra o motivo', function () {
    $case = sdiCase($this->w);
    sdiSignedConsent($this, $case);

    $props = app(DocumentsSection::class)->props($case, ['manage' => true, 'perform' => true]);

    expect($props['outdated_consent'])->toBeNull()
        ->and($props['documents'][0]['outdated_fields'])->toBe([]);

    app(SurgicalCaseService::class)->update($case, ['eye' => 'ao']);

    $props = app(DocumentsSection::class)->props($case->refresh(), ['manage' => true, 'perform' => true]);

    expect($props['outdated_consent']['fields'])->toBe(__('surgery_documents.consent_fields.eye'))
        ->and($props['documents'][0]['outdated_fields'])->toBe(['eye']);
});

// ── Revisão adversarial (C8 / C9) ───────────────────────────────────────────

it('C8: reemitir o texto de uma emissão JÁ substituída cria a vigente, não devolve a antiga', function () {
    $case = sdiCase($this->w);

    $a  = sdiIssue($this, 'secretary', $case, ['content' => '<p>Texto A</p>'])->json('id');
    $b  = sdiIssue($this, 'secretary', $case, ['content' => '<p>Texto B</p>'])->json('id');
    $a2 = sdiIssue($this, 'secretary', $case, ['content' => '<p>Texto A</p>'])->assertCreated()->json('id');

    expect($a2)->not->toBe($a)->not->toBe($b);

    $current = SurgeryDocumentIssue::query()->where('type', 'consent')->whereNull('superseded_by_id')->get();

    expect($current)->toHaveCount(1)
        ->and((string) $current->first()->id)->toBe($a2)
        ->and((string) SurgeryDocumentIssue::query()->findOrFail($b)->superseded_by_id)->toBe($a2)
        ->and(SurgeryDocumentIssue::query()->count())->toBe(3);
});

it('C9: depois da cirurgia realizada o TCLE não é emitido nem reemitido; orientações seguem livres', function () {
    $case  = sdiCase($this->w);
    $first = sdiIssue($this, 'secretary', $case)->json('id');
    $issue = SurgeryDocumentIssue::query()->findOrFail($first);
    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.documents.signed-copy.store', ['surgicalCase' => $case, 'document' => $issue]), ['file' => UploadedFile::fake()->createWithContent('a.pdf', sdiPdfBytes())]);

    $case->forceFill(['status' => SurgicalCaseStatus::Performed])->save();

    sdiIssue($this, 'secretary', $case, ['content' => '<p>Termo reemitido depois.</p>'])
        ->assertStatus(409)->assertJsonPath('message', __('surgery_documents.errors.consent_locked'));

    // O vigente assinado continua sendo o mesmo.
    expect(SurgeryDocumentIssue::query()->count())->toBe(1)
        ->and(SurgeryDocumentIssue::query()->findOrFail($first)->isCurrent())->toBeTrue()
        ->and(SurgeryDocumentIssue::query()->findOrFail($first)->hasSignedCopy())->toBeTrue();

    $pre = app(SurgeryTemplateService::class)->create((string) $this->w->entity->id, ['type' => 'pre_op_guidance', 'name' => 'Pré', 'body' => '<p>Orientações para {{paciente}}.</p>']);
    sdiIssue($this, 'secretary', $case, ['type' => 'pre_op_guidance', 'template_id' => (string) $pre->id, 'title' => 'Orientações', 'content' => '<p>Retornos.</p>'])->assertCreated();

    // A cópia assinada não é trocada depois de realizada.
    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.documents.signed-copy.store', ['surgicalCase' => $case, 'document' => $issue]), ['file' => UploadedFile::fake()->image('outra.jpg')])
        ->assertStatus(409)->assertJsonPath('message', __('surgery.errors.signed_copy_locked'));
    expect($issue->refresh()->signed_copy_mime)->toBe('application/pdf');
});

it('C9: a aba esconde emitir/reemitir TCLE e trocar a cópia depois de realizada', function () {
    $case  = sdiCase($this->w);
    $issue = SurgeryDocumentIssue::query()->findOrFail(sdiIssue($this, 'secretary', $case)->json('id'));
    surgeryAs($this->w, 'secretary')->post(route('panel.surgery.documents.signed-copy.store', ['surgicalCase' => $case, 'document' => $issue]), ['file' => UploadedFile::fake()->createWithContent('a.pdf', sdiPdfBytes())]);

    $case->forceFill(['status' => SurgicalCaseStatus::Performed])->save();

    $props = app(DocumentsSection::class)->props($case->fresh(), ['manage' => true, 'perform' => false]);

    expect(collect($props['issuable'])->pluck('type')->all())->toBe(['pre_op_guidance'])
        ->and($props['documents'][0]['can_reissue'])->toBeFalse()
        ->and($props['documents'][0]['copy_locked'])->toBeTrue();
});

// ── Revisão LGPD/clínica (L13 / L1) ─────────────────────────────────────────

it('L13: o PDF do documento formata nascimento e emissão no idioma (pt dd/mm, en mm/dd)', function () {
    $case   = sdiCase($this->w);
    $issue  = SurgeryDocumentIssue::query()->findOrFail(sdiIssue($this, 'secretary', $case)->json('id'));
    $render = fn () => view('pdf.surgery_document', [
        'issue'  => $issue, 'entity' => $this->w->entity, 'header' => $issue->payload['header'],
        'signer' => $issue->payload['signer'], 'data' => $issue->payload['data'],
    ])->render();

    app()->setLocale('pt_BR');
    $pt = $render();
    app()->setLocale('en');
    $en = $render();

    expect($pt)->toContain('10/03/1950')->and($en)->toContain('03/10/1950')->not->toContain('10/03/1950');
    expect($pt)->toContain($issue->issued_at->copy()->locale('pt_BR')->isoFormat('L LT'))
        ->and($en)->toContain($issue->issued_at->copy()->locale('en')->isoFormat('L LT'));
});

it('L1: a prévia traz o olho do caso com a sigla no idioma do usuário (pt OD/OE, en OD/OS)', function () {
    $case = sdiCase($this->w, ['eye' => 'oe']);

    // A requisição usa o idioma do usuário (SetLocale): pt_BR.
    $eye = surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.documents.preview', $case), ['type' => 'consent', 'template_id' => (string) $this->template->id])
        ->assertOk()->json('eye');

    expect($eye['value'])->toBe('oe')->and($eye['short'])->toBe('OE')->and($eye['label'])->toContain('OE');

    app()->setLocale('en');
    expect(SurgeryEye::Left->short())->toBe('OS')
        ->and(app(SurgeryTemplateVariables::class)->forCase($case->fresh())['olho'])->toContain('OS');
});
