<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DocumentationType, ReportSettingStatus};
use App\Models\{DataAccessLog, Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, People, ReportSetting, User};
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\{ExecutableFinder, Process};

/**
 * Laudo de imagem pro médico solicitante (Fase 5) — ExternalMedicalReportController.
 * Rota pública, sem conta/portal: o link assinado é o único controle de
 * acesso (mesmo padrão de PatientPortalInvitationAcceptTest.php).
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $doctorUser = User::factory()->create();
    $doctorEu   = createEntityUser($this->entity, $doctorUser, ClientRule::Doctor->value);

    $this->doctorEntityUser = $doctorEu;
    $this->doctor           = Doctor::query()->create([
        'entity_user_id' => $doctorEu->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

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
        'title'                   => 'Laudo de Exame de Imagem',
        'content'                 => '<p>Achados normais.</p>',
        'requesting_doctor_name'  => 'Dr. Carlos Souza',
        'requesting_doctor_email' => 'carlos.souza@outraclinica.com',
    ]);

    // O link público só abre pra laudo de prontuário ASSINADO (imutável).
    $this->record->sign($doctorEu);
});

function signedExternalReportUrl(string $documentationId, $expiration = null)
{
    return URL::temporarySignedRoute(
        'external-reports.show',
        $expiration ?? now()->addDays(7),
        ['documentation' => $documentationId],
    );
}

/**
 * Texto do PDF lido por `pdftotext` (poppler-utils). Sem o executável (ou se ele falhar) o teste reprova dizendo isso:
 * a saída vazia que o `shell_exec` devolvia fazia a asserção de conteúdo acusar "cabeçalho errado".
 */
function externalReportPdfText(string $pdf): string
{
    $binary = (new ExecutableFinder())->find('pdftotext');

    if ($binary === null) {
        test()->fail('Pré-requisito ausente: pdftotext (poppler-utils) não encontrado no PATH. Instale com `apt-get install poppler-utils`.');
    }

    $path = tempnam(sys_get_temp_dir(), 'eeye_test_pdf_');

    if ($path === false) {
        test()->fail('Não foi possível criar o arquivo temporário do PDF em ' . sys_get_temp_dir());
    }

    try {
        if (file_put_contents($path, $pdf) === false) {
            test()->fail("Não foi possível gravar o PDF em {$path}");
        }

        $process = new Process([$binary, $path, '-']);
        $process->run();
    } finally {
        @unlink($path);
    }

    if (! $process->isSuccessful()) {
        test()->fail("pdftotext falhou (status {$process->getExitCode()}): " . trim($process->getErrorOutput()));
    }

    return $process->getOutput();
}

it('link assinado válido devolve o PDF do laudo, sem precisar de login', function () {
    $url = signedExternalReportUrl($this->documentation->id);

    $response = $this->get($url);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

it('[SEGURANÇA] link expirado é rejeitado com 403', function () {
    $url = signedExternalReportUrl($this->documentation->id, now()->subDay());

    $this->get($url)->assertForbidden();
});

it('[SEGURANÇA] adulterar o documentation_id da URL invalida a assinatura', function () {
    // Outro prontuário (este já está assinado e não aceita documento novo).
    $otherRecord = MedicalRecord::create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctor->id,
    ]);
    $other = MedicalRecordDocumentation::create([
        'medical_record_id'       => $otherRecord->id,
        'patient_id'              => $this->patient->id,
        'doctor_id'               => $this->doctor->id,
        'type'                    => DocumentationType::Report->value,
        'content'                 => '<p>Outro laudo.</p>',
        'requesting_doctor_email' => 'outro@externo.com',
    ]);

    $url      = signedExternalReportUrl($this->documentation->id);
    $tampered = str_replace($this->documentation->id, $other->id, $url);

    $this->get($tampered)->assertForbidden();
});

it('[SEGURANÇA] documento sem requesting_doctor_email devolve 404 mesmo com assinatura válida', function () {
    // Prontuário assinado = imutável: o campo é mexido pelo canal de bookkeeping (saveQuietly).
    $this->documentation->forceFill(['requesting_doctor_email' => null])->saveQuietly();

    $url = signedExternalReportUrl($this->documentation->id);

    $this->get($url)->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// Laudo só é legível de fora com o prontuário ASSINADO (achado via revisão
// adversarial Codex, 15ª rodada): antes o link abria um laudo que a clínica
// ainda podia editar ou excluir.
// ─────────────────────────────────────────────────────────────────────────────

function unsignedReportWithRequestingDoctor($test, bool $lockedOnly = false): MedicalRecordDocumentation
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
        'title'                   => 'Laudo ainda editável',
        'content'                 => '<p>Rascunho clínico.</p>',
        'requesting_doctor_email' => 'carlos.souza@outraclinica.com',
    ]);

    if ($lockedOnly) {
        $record->forceFill(['is_locked' => true])->saveQuietly();
    }

    return $documentation;
}

it('[SEGURANÇA] prontuário NÃO assinado: o link assinado e válido devolve 404 — o laudo ainda pode mudar', function () {
    $documentation = unsignedReportWithRequestingDoctor($this);

    $this->get(signedExternalReportUrl($documentation->id))
        ->assertNotFound();

    expect(DataAccessLog::query()->where('resource_id', $documentation->id)->exists())->toBeFalse();
});

it('[SEGURANÇA] prontuário só travado (is_locked) sem assinatura também devolve 404', function () {
    $documentation = unsignedReportWithRequestingDoctor($this, lockedOnly: true);

    $this->get(signedExternalReportUrl($documentation->id))->assertNotFound();
});

it('o mesmo link passa a abrir quando o prontuário é assinado', function () {
    $documentation = unsignedReportWithRequestingDoctor($this);
    $url           = signedExternalReportUrl($documentation->id);

    $this->get($url)->assertNotFound();

    $documentation->medicalRecord->sign($this->doctorEntityUser);

    $this->get($url)->assertOk();
});

it('isExternallyDeliverable(): só prontuário com assinatura (signed_at + signature_hash) libera', function () {
    expect($this->documentation->refresh()->isExternallyDeliverable())->toBeTrue()
        ->and(unsignedReportWithRequestingDoctor($this)->isExternallyDeliverable())->toBeFalse()
        ->and(unsignedReportWithRequestingDoctor($this, lockedOnly: true)->isExternallyDeliverable())->toBeFalse();
});

it('registra o acesso em DataAccessLog com ator anônimo (user_id nulo)', function () {
    $url = signedExternalReportUrl($this->documentation->id);

    $this->get($url)->assertOk();

    $log = DataAccessLog::query()->where('resource_id', $this->documentation->id)->firstOrFail();
    expect($log->user_id)->toBeNull()
        ->and($log->patient_id)->toBe((string) $this->patient->id);
});

/**
 * Documento sem schedule (laudo de imagem de exame importado manualmente —
 * cenário normal do Gerenciador de Imagens) + visitante sem sessão (ou,
 * pior, autenticado no painel de OUTRA clínica numa aba ao lado): antes só
 * session('selected_entity_id') decidia a clínica do log de auditoria e do
 * cabeçalho/rodapé do PDF — sem sessão, ficava null (log desaparecia do
 * relatório de auditoria da clínica certa); com sessão de outra clínica,
 * vazava o nome/logo/endereço ERRADOS no PDF (achado via revisão Codex).
 */
it('[GAP] usa a clínica DO DOCUMENTO (patient->entity) no log de auditoria e no PDF, nunca a da sessão do visitante', function () {
    ReportSetting::create([
        'entity_id'   => $this->entity->id,
        'show_header' => true,
        'show_footer' => true,
        'title'       => 'Padrão (fixture clínica)',
        'active'      => true,
        'status'      => ReportSettingStatus::Published,
    ]);

    // Visitante autenticado no painel de OUTRA clínica numa aba ao lado —
    // a sessão aponta pra uma entidade diferente da dona do documento.
    $otherEntity = Entity::factory()->create(['is_client' => true, 'name' => 'Clínica Errada LTDA']);
    session(['selected_entity_id' => $otherEntity->id]);

    $url      = signedExternalReportUrl($this->documentation->id);
    $response = $this->get($url);
    $response->assertOk();

    $text = externalReportPdfText($response->getContent());

    // O cabeçalho do PDF usa text-transform:uppercase (CSS) — comparação
    // precisa ignorar caixa, senão "SALAZAR..." nunca bate com "Salazar...".
    $upperText = mb_strtoupper($text);
    expect($upperText)->toContain(mb_strtoupper($this->entity->name))
        ->and($upperText)->not->toContain('CLÍNICA ERRADA LTDA');

    $log = DataAccessLog::query()->where('resource_id', $this->documentation->id)->latest('accessed_at')->firstOrFail();
    expect((string) $log->entity_id)->toBe((string) $this->entity->id);
});
