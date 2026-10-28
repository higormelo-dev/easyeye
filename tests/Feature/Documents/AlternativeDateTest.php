<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{AuditLog, Covenant, Entity, EntityUser, MedicalRecord, MedicalRecordDocumentation, Patient, People, ReportSetting, ReportSettingContent, User};
use App\Services\Lgpd\PatientDataExporter;
use App\Services\MedicalRecordPdfService;
use Database\Seeders\{ReportSettingContentSeeder, ReportSettingSeeder, ReportSettingVariableSeeder};
use Illuminate\Support\Carbon;

/*
 * Fase 2 / B3 — data alternativa: receita, relatório, pedido de exames e
 * atestados podem sair com outra data IMPRESSA, dentro da faixa configurada
 * (padrão: 30 dias antes e 30 depois). O emitido de fato fica em created_at
 * e na auditoria; o PDF (cabeçalho e assinatura) e as variáveis de data do
 * modelo usam a data impressa; reimprimir não troca pela data de hoje.
 */
beforeEach(function () {
    $this->seed([ReportSettingSeeder::class, ReportSettingContentSeeder::class, ReportSettingVariableSeeder::class]);
    Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00', 'America/Sao_Paulo'));

    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true, 'city' => 'Brasília', 'state' => 'DF']);
    $this->docEu   = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    $this->doctor  = doctorRecordForEntityUser($this->docEu);
    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create(['full_name' => 'JOSE PEREIRA'])->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);
    $this->record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'Revisão',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function fb3dAs(object $test, EntityUser $eu): object
{
    return $test->actingAs($eu->user)->withSession(panelSession($eu));
}

function fb3dIssue(object $test, string $action, array $payload)
{
    return fb3dAs($test, $test->docEu)->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [$test->patient, $test->record, $action]),
        $payload,
    );
}

dataset('fb3d documents', [
    'receita'          => ['medication-prescription', ['content' => 'Colírio lubrificante 4x ao dia']],
    'receita catarata' => ['cataract-prescription', ['eye' => 'right', 'template' => 'pre_operatorio']],
    'relatório'        => ['medical-declaration', ['content' => 'Paciente em acompanhamento.']],
    'pedido'           => ['procedure-request', ['items' => [['name' => 'Campimetria computadorizada', 'code' => '40103137']]]],
    'atestado médico'  => ['medical-certificate', ['days' => 2]],
    'comparecimento'   => ['attendance-certificate', []],
    'acompanhante'     => ['companion-certificate', ['companion_name' => 'Ana Souza']],
]);

it('grava a data impressa, mantém o emitido de fato e registra na auditoria', function (string $action, array $payload) {
    fb3dIssue($this, $action, [...$payload, 'document_date' => '2026-09-20'])
        ->assertCreated()
        ->assertJsonPath('document_date', '20/09/2026');

    $doc = MedicalRecordDocumentation::query()->latest()->firstOrFail();

    expect($doc->document_date->toDateString())->toBe('2026-09-20')
        ->and($doc->created_at->setTimezone('America/Sao_Paulo')->toDateString())->toBe('2026-10-07')
        ->and($doc->printedDate()->toDateString())->toBe('2026-09-20')
        ->and(AuditLog::query()->where('event', 'alternative_date')->where('auditable_id', $doc->id)->value('new_values'))
        ->toMatchArray(['document_date' => '2026-09-20']);
})->with('fb3d documents');

it('recusa data fora da faixa (31 dias antes ou depois) sem emitir nada', function (string $action, array $payload) {
    fb3dIssue($this, $action, [...$payload, 'document_date' => '2026-09-06'])
        ->assertUnprocessable()->assertJsonValidationErrors('document_date');
    fb3dIssue($this, $action, [...$payload, 'document_date' => '2026-11-07'])
        ->assertUnprocessable()->assertJsonValidationErrors('document_date');

    expect(MedicalRecordDocumentation::query()->count())->toBe(0);
})->with('fb3d documents');

it('limites da faixa vêm da configuração (30/30 por padrão; 0 = só hoje)', function () {
    fb3dIssue($this, 'medical-certificate', ['days' => 1, 'document_date' => '2026-09-07'])->assertCreated();
    fb3dIssue($this, 'medical-certificate', ['days' => 1, 'document_date' => '2026-11-06'])->assertCreated();

    config(['clinical_documents.alternative_date.days_before' => 0, 'clinical_documents.alternative_date.days_after' => 0]);

    fb3dIssue($this, 'medical-certificate', ['days' => 1, 'document_date' => '2026-10-06'])
        ->assertUnprocessable()->assertJsonValidationErrors('document_date');
});

it('data igual a hoje não é alternativa (sem document_date e sem evento)', function () {
    fb3dIssue($this, 'medical-certificate', ['days' => 1, 'document_date' => '2026-10-07'])->assertCreated();

    $doc = MedicalRecordDocumentation::query()->firstOrFail();

    expect($doc->document_date)->toBeNull()
        ->and(AuditLog::query()->where('event', 'alternative_date')->exists())->toBeFalse();
});

it('as variáveis de data do modelo usam a data impressa', function () {
    fb3dIssue($this, 'medication-prescription', ['content' => 'Lubrificante', 'document_date' => '2026-10-01'])->assertCreated();
    fb3dIssue($this, 'medical-certificate', ['days' => 3, 'document_date' => '2026-10-02'])->assertCreated();

    [$rx, $cert] = MedicalRecordDocumentation::query()->orderBy('created_at')->orderBy('title', 'desc')->get()->sortBy('document_date')->values()->all();

    expect($rx->content)->toContain('Data: 01/10/2026')->not->toContain('07/10/2026')
        ->and($cert->content)->toContain('a partir de 02/10/2026')
        ->and($cert->content)->toContain('2 de outubro de 2026');
});

it('PDF mostra a data impressa no cabeçalho e na assinatura — e não a de hoje ao reimprimir', function () {
    fb3dIssue($this, 'companion-certificate', ['companion_name' => 'Ana Souza', 'document_date' => '2026-09-25'])->assertCreated();
    $doc = MedicalRecordDocumentation::query()->firstOrFail()->load(['patient.person', 'doctor.person', 'medicalRecord']);

    // Reimpressão dias depois.
    Carbon::setTestNow(Carbon::parse('2026-10-20 09:00:00', 'America/Sao_Paulo'));

    $setting = ReportSetting::query()->whereNull('entity_id')->where('title', 'ATESTADO DE ACOMPANHANTE')->firstOrFail();
    $html    = view('pdf.documentation', ['doc' => $doc, 'setting' => $setting, 'examSummary' => []])->render();

    expect($html)->toContain('25/09/2026')
        ->toContain('25 de setembro de 2026')
        ->not->toContain('20/10/2026')
        ->not->toContain('20 de outubro de 2026');
});

it('documento sem data alternativa reimpresso mostra a data da emissão', function () {
    fb3dIssue($this, 'medical-certificate', ['days' => 1])->assertCreated();
    $doc = MedicalRecordDocumentation::query()->firstOrFail()->load(['patient.person', 'doctor.person', 'medicalRecord']);

    Carbon::setTestNow(Carbon::parse('2026-10-20 09:00:00', 'America/Sao_Paulo'));
    $setting = ReportSetting::query()->whereNull('entity_id')->where('title', 'ATESTADO MÉDICO')->firstOrFail();
    $html    = view('pdf.documentation', ['doc' => $doc, 'setting' => $setting, 'examSummary' => []])->render();

    expect($html)->toContain('07/10/2026')->not->toContain('20/10/2026');
});

describe('editor de Documentações (relatório e demais modelos)', function () {
    beforeEach(function () {
        $this->report = ReportSettingContent::query()
            ->where('slug', 'declaracao_medica')
            ->whereHas('reportSetting', fn ($q) => $q->withoutGlobalScopes()->whereNull('entity_id'))
            ->firstOrFail();
    });

    it('salva com data alternativa dentro da faixa e recusa fora dela', function () {
        $url = route('panel.patients.medicalrecords.documentations.store', [$this->patient, $this->record]);

        fb3dAs($this, $this->docEu)->postJson($url, [
            'report_setting_content_id' => $this->report->id, 'content' => '<p>Relatório</p>', 'document_date' => '2026-10-15',
        ])->assertCreated()->assertJsonPath('document_date', '15/10/2026');

        fb3dAs($this, $this->docEu)->postJson($url, [
            'report_setting_content_id' => $this->report->id, 'content' => '<p>Relatório</p>', 'document_date' => '2027-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('document_date');

        expect(MedicalRecordDocumentation::query()->count())->toBe(1)
            ->and(MedicalRecordDocumentation::query()->value('document_date')->toDateString())->toBe('2026-10-15');
    });

    it('a prévia do modelo resolve as datas do texto com a data escolhida', function () {
        $prescription = ReportSettingContent::query()
            ->where('slug', 'padrao')
            ->whereHas('reportSetting', fn ($q) => $q->withoutGlobalScopes()->whereNull('entity_id')->where('title', 'PRESCRIÇÃO DE MEDICAMENTOS'))
            ->firstOrFail();

        $res = fb3dAs($this, $this->docEu)->postJson(
            route('panel.patients.medicalrecords.template-preview', [$this->patient, $this->record]),
            ['report_setting_content_id' => $prescription->id, 'document_date' => '2026-09-30'],
        )->assertOk();

        expect($res->json('content'))->toContain('30/09/2026')->not->toContain('07/10/2026');
    });
});

it('exportação LGPD leva a data impressa e os dados estruturados do documento', function () {
    fb3dIssue($this, 'companion-certificate', ['companion_name' => 'Ana Souza', 'period' => 'morning', 'document_date' => '2026-09-25'])->assertCreated();

    $export = app(PatientDataExporter::class)->export($this->patient->fresh());
    $doc    = $export['medical_records'][0]['documentations'][0];

    expect($doc['document_date'])->toBe('2026-09-25')
        ->and($doc['details'])->toMatchArray(['companion_name' => 'Ana Souza', 'period' => 'morning']);
});

it('rodapé do PDF mostra quando o documento foi emitido de fato (não a hora da reimpressão)', function () {
    fb3dIssue($this, 'medical-certificate', ['days' => 1, 'document_date' => '2026-09-30'])->assertCreated();
    $doc = MedicalRecordDocumentation::query()->firstOrFail();

    Carbon::setTestNow(Carbon::parse('2026-10-20 16:45:00', 'America/Sao_Paulo'));

    $setting = ReportSetting::query()->whereNull('entity_id')->where('title', 'ATESTADO MÉDICO')->firstOrFail();
    $method  = new ReflectionMethod(MedicalRecordPdfService::class, 'buildDocumentationFooterFile');
    $path    = $method->invoke(app(MedicalRecordPdfService::class), $setting, $this->entity, $doc->created_at);
    $footer  = (string) file_get_contents($path);
    @unlink($path);

    expect($footer)->toContain('Emitido em 07/10/2026 10:00')->not->toContain('20/10/2026');
});
