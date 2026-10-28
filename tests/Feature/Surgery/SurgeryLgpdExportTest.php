<?php

declare(strict_types=1);

use App\Enums\Surgery\{PostOpMilestone, SurgeryDocumentType, SurgicalCaseStatus};
use App\Models\{Patient, VisualAcuityType};
use App\Models\Surgery\{SurgeryDocumentIssue, SurgicalCase};
use App\Services\Lgpd\{PatientDataExporter, SurgeryDataExporter};
use App\Services\Surgery\{SurgeryAuthorizationService, SurgeryDocumentIssueService, SurgicalCaseService};
use Illuminate\Support\Facades\DB;

/*
 * Módulo cirúrgico (Fase 4) / LGPD art. 18 — a exportação do paciente inclui as
 * cirurgias dele (e só dele, só desta clínica), sem credenciais da operadora
 * nem caminho de arquivo; a versão do formato subiu para 5.
 */

function sleCase(object $w, ?Patient $patient = null): SurgicalCase
{
    $patient ??= $w->patient;

    $case = app(SurgicalCaseService::class)->create((string) $patient->entity_id, [
        'patient_id'        => (string) $patient->id,
        'surgeon_doctor_id' => (string) $w->doctor->id,
        'title'             => 'Facoemulsificação com LIO',
        'eye'               => 'od',
        'needs_opme'        => true,
        'procedures'        => [['description' => 'Facoemulsificação', 'tuss_code' => '30306019', 'quantity' => 1]],
        'materials'         => [['description' => 'Anel de tensão', 'quantity' => 1, 'technical_justification' => 'Subluxação']],
    ]);

    return $case;
}

beforeEach(function () {
    $this->w = surgeryWorld();
    $this->actingAs($this->w->users['doctor'])->withSession(panelSession($this->w->eus['doctor']));
});

it('exporta o caso do paciente com pedido, autorização e documentos, sem segredo da operadora nem caminho de arquivo', function () {
    $case = app(SurgeryAuthorizationService::class)->request(sleCase($this->w));
    app(SurgeryAuthorizationService::class)->record($case, [
        'type' => 'authorized', 'authorization_number' => 'AUT-1', 'authorization_password' => 'SENHA-SECRETA',
    ]);

    $issue = app(SurgeryDocumentIssueService::class)->issue($case, SurgeryDocumentType::Consent, 'TCLE catarata', '<p>Riscos e alternativas</p>');
    $issue->update(['signed_copy_path' => 'surgery/x/y/arquivo.pdf', 'signed_copy_name' => 'termo.pdf', 'signed_copy_mime' => 'application/pdf']);

    $export = app(PatientDataExporter::class)->export($this->w->patient);

    expect($export['format_version'])->toBe(6)
        ->and($export['surgery'])->toHaveCount(1);

    $out = $export['surgery'][0];

    expect($out['title'])->toBe('Facoemulsificação com LIO')
        ->and($out['status_label'])->toBe($case->refresh()->status->label())
        ->and($out['procedures'][0]['description'])->toBe('Facoemulsificação')
        ->and($out['procedures'][0]['authorized_quantity'])->toBe(1)
        ->and($out['materials'][0]['technical_justification'])->toBe('Subluxação')
        ->and(collect($out['authorization_events'])->pluck('type')->all())->toBe(['requested', 'authorized'])
        ->and(collect($out['authorization_events'])->firstWhere('type', 'authorized')['authorization_number'])->toBe('AUT-1')
        ->and($out['documents'][0]['content'])->toContain('Riscos e alternativas')
        ->and($out['documents'][0]['has_signed_copy'])->toBeTrue()
        ->and($out['documents'][0]['signed_copy_name'])->toBe('termo.pdf')
        ->and($out['clinical_safety_snapshot'])->not->toBeNull();

    $json = json_encode($export, JSON_UNESCAPED_UNICODE);

    expect($json)->not->toContain('SENHA-SECRETA')
        ->and($json)->not->toContain('authorization_password')
        ->and($json)->not->toContain('surgery/x/y/arquivo.pdf')
        ->and($json)->not->toContain('signed_copy_path');
});

it('não exporta caso de outro paciente nem de outra clínica', function () {
    sleCase($this->w);

    $otherPatient = Patient::factory()->create(['entity_id' => $this->w->entity->id, 'covenant_id' => $this->w->particular->id, 'active' => true]);
    sleCase($this->w, $otherPatient);

    $foreign = surgeryWorld();
    sleCase($foreign);

    $export = app(PatientDataExporter::class)->export($this->w->patient);

    expect($export['surgery'])->toHaveCount(1)
        ->and(app(PatientDataExporter::class)->export($otherPatient)['surgery'])->toHaveCount(1)
        ->and(app(PatientDataExporter::class)->export($foreign->patient)['surgery'])->toHaveCount(1);
});

it('paciente sem cirurgia exporta lista vazia', function () {
    expect(app(PatientDataExporter::class)->export($this->w->patient)['surgery'])->toBe([]);
});

it('inclui o caso cancelado (histórico completo) e o excluído', function () {
    $case = sleCase($this->w);
    app(SurgicalCaseService::class)->cancel($case, 'Paciente desistiu');

    $draft = sleCase($this->w);
    app(SurgicalCaseService::class)->destroyDraft($draft);

    $export = app(PatientDataExporter::class)->export($this->w->patient)['surgery'];

    expect($export)->toHaveCount(2)
        ->and(collect($export)->pluck('status')->sort()->values()->all())->toBe(['cancelled', 'draft']);
});

it('o documento emitido é exportado uma vez por emissão, vigente ou substituída', function () {
    $case    = sleCase($this->w);
    $service = app(SurgeryDocumentIssueService::class);

    $service->issue($case, SurgeryDocumentType::Consent, 'TCLE', '<p>versão 1</p>');
    $service->issue($case, SurgeryDocumentType::Consent, 'TCLE', '<p>versão 2</p>');

    $docs = app(PatientDataExporter::class)->export($this->w->patient)['surgery'][0]['documents'];

    expect($docs)->toHaveCount(2)
        ->and(collect($docs)->where('superseded', true))->toHaveCount(1)
        ->and(SurgeryDocumentIssue::query()->count())->toBe(2);
});

it('[cobertura] toda tabela cirúrgica com patient_id ou surgical_case_id está declarada na exportação', function () {
    $tables = collect(DB::select("
        select distinct table_name from information_schema.columns
         where table_schema = current_schema()
           and column_name in ('patient_id', 'surgical_case_id')
           and (table_name like 'surg%')
    "))->pluck('table_name')->all();

    $undeclared = array_values(array_diff($tables, SurgeryDataExporter::COVERED_TABLES));

    expect($undeclared)->toBe([], 'tabela nova com dado do paciente fora do exportador LGPD: ' . implode(', ', $undeclared));
});

it('avaliação pós-operatória sai com acuidade e achados por nome, não por UUID', function () {
    $case = sleCase($this->w);
    $case->forceFill(['status' => SurgicalCaseStatus::Performed, 'performed_at' => now()])->save();

    $va = VisualAcuityType::query()->create(['name' => '20/40', 'scale' => 1, 'active' => true, 'entity_id' => null]);

    $this->postJson(route('panel.surgery.postop.evaluations.store', $case), [
        'milestone'       => 'd1', 'eye' => 'od', 'evaluated_at' => now()->format('Y-m-d H:i:s'), 'iop_mmhg' => 14,
        'va_corrected_id' => (string) $va->id, 'findings' => ['corneal_edema'], 'adherence' => 'good', 'plan' => 'Manter.',
    ])->assertCreated();

    $row = app(PatientDataExporter::class)->export($this->w->patient)['surgery'][0]['postop_evaluations'][0];

    expect($row['va_corrected_label'])->toBe('20/40')
        ->and($row['findings_labels'])->not->toBeEmpty()
        ->and($row['milestone_label'])->toBe(PostOpMilestone::D1->label());
});
