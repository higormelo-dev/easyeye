<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\CareGuidance\Exceptions\CareGuidanceException;
use App\Domains\CareGuidance\Models\CareGuidanceIssue;
use App\Domains\CareGuidance\Services\{CareGuidanceIssueService, CareGuidanceTemplateService};
use App\Enums\{DataAccessPurpose, EntityGate};
use App\Http\Requests\CareGuidance\IssueCareGuidanceRequest;
use App\Models\{Entity, MedicalRecordProcedure, Patient, ReportSetting};
use App\Support\CurrentDoctor;
use App\Traits\LogsDataAccess;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Http\{JsonResponse, Response};
use Illuminate\Support\Facades\{Gate, Storage};
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Orientações pós-procedimento no prontuário (Fase 6 / S14). Leitura (as já entregues, o PDF e os anexos) segue o grupo
 * do prontuário — a recepção entrega o papel; os MODELOS para emitir e a emissão são ato do médico (Gate IssueReport).
 * O que o paciente recebe é o retrato congelado (CareGuidanceIssue): nada aqui lê o modelo atual para montar o PDF.
 */
class CareGuidanceController extends Controller
{
    use LogsDataAccess;

    public function __construct(private readonly CareGuidanceIssueService $issues)
    {
    }

    /** Os modelos que servem ao procedimento (só médico) e o que já foi entregue por ele. */
    public function procedure(Patient $patient, MedicalRecordProcedure $medicalRecordProcedure): JsonResponse
    {
        $entity = $this->authorizeProcedure($patient, $medicalRecordProcedure);

        $canIssue = Gate::allows(EntityGate::IssueReport->value, $entity);

        $this->logAccess($patient, DataAccessPurpose::PatientCare, patientId: (string) $patient->id);

        return response()->json(['data' => [
            'can_issue' => $canIssue,
            'templates' => $canIssue ? $this->issues->templatesFor($medicalRecordProcedure) : [],
            'issues'    => array_map(fn (array $issue): array => array_merge($issue, $this->urls($patient, $issue)), $this->issues->issuesFor((string) $entity->id, (string) $patient->id, (string) $medicalRecordProcedure->id)),
            'max_drops' => CareGuidanceTemplateService::MAX_DROPS,
            't'         => trans('care_guidance'),
        ]]);
    }

    public function issue(IssueCareGuidanceRequest $request, Patient $patient, MedicalRecordProcedure $medicalRecordProcedure): JsonResponse
    {
        $this->authorizeProcedure($patient, $medicalRecordProcedure);

        // Autoria = o médico que entrega (logado, cadastro ativo), como nos demais documentos.
        $doctor = CurrentDoctor::issuer();
        abort_if($doctor === null, 403, CurrentDoctor::missingMessage());

        try {
            $issue = $this->issues->issue($medicalRecordProcedure, (string) $request->validated('template_id'), $doctor, $request->overrides());
        } catch (CareGuidanceException $e) {
            return response()->json([
                'message' => __('care_guidance.errors.' . $e->reason()),
                'errors'  => $e->field() !== null ? [$e->field() => [__('care_guidance.errors.' . $e->reason())]] : null,
            ], 422);
        }

        $this->logAccess($issue, DataAccessPurpose::PatientCare, patientId: (string) $patient->id);

        $row = $this->issues->serializeIssue($issue->load('doctor.person:id,full_name'));

        return response()->json(['message' => __('care_guidance.issue.issued'), 'data' => array_merge($row, $this->urls($patient, $row))], 201);
    }

    /** PDF da orientação ENTREGUE, montado só do retrato congelado. */
    public function pdf(Patient $patient, string $issue): Response
    {
        $entity = $this->authorizePatient($patient);
        $model  = $this->ownedIssue($patient, $issue);

        $this->logAccess($model, DataAccessPurpose::PatientCare, patientId: (string) $patient->id);

        $model->loadMissing('doctor.person');
        $setting = ReportSetting::query()->where('entity_id', $entity->id)->where('active', true)->first();

        return SnappyPdf::loadView('pdf.care_guidance', [
            'issue'   => $model,
            'patient' => $patient,
            'entity'  => $entity,
            'setting' => $setting,
            'doctor'  => $model->doctor,
        ])
            ->setPaper('A4', 'portrait')
            ->setOption('margin-top', '1.5cm')
            ->setOption('margin-right', '1.8cm')
            ->setOption('margin-bottom', '1.5cm')
            ->setOption('margin-left', '1.8cm')
            ->setOption('encoding', 'UTF-8')
            ->inline('ORIENTACOES.pdf');
    }

    /** PDF anexo da orientação entregue (a CÓPIA gravada na emissão). */
    public function attachment(Patient $patient, string $issue, int $index): StreamedResponse
    {
        $this->authorizePatient($patient);
        $model = $this->ownedIssue($patient, $issue);
        $file  = ($model->attachments ?? [])[$index] ?? null;

        abort_if($file === null || ! Storage::disk()->exists($file['path']), 404);

        $this->logAccess($model, DataAccessPurpose::PatientCare, patientId: (string) $patient->id);

        return Storage::disk()->download($file['path'], $file['name'], ['Content-Type' => 'application/pdf']);
    }

    /**
     * @param array<string, mixed> $issue
     *
     * @return array{pdf_url: string, attachments: list<array<string, mixed>>}
     */
    private function urls(Patient $patient, array $issue): array
    {
        return [
            'pdf_url'     => route('panel.patients.care-guidance.pdf', [$patient, $issue['id']]),
            'attachments' => array_map(
                fn (array $file): array => $file + ['url' => route('panel.patients.care-guidance.attachment', [$patient, $issue['id'], $file['index']])],
                $issue['attachments'],
            ),
        ];
    }

    private function authorizePatient(Patient $patient): Entity
    {
        $entityId = (string) session('selected_entity_id');

        abort_unless((string) $patient->entity_id === $entityId, 404);

        return Entity::findOrFail($entityId);
    }

    private function authorizeProcedure(Patient $patient, MedicalRecordProcedure $procedure): Entity
    {
        $entity = $this->authorizePatient($patient);

        abort_unless((string) $procedure->entity_id === (string) $entity->id, 404);
        abort_if((string) $procedure->patient_id !== (string) $patient->id, 404);

        return $entity;
    }

    private function ownedIssue(Patient $patient, string $id): CareGuidanceIssue
    {
        return CareGuidanceIssue::query()->withoutGlobalScopes()
            ->where('entity_id', $patient->entity_id)
            ->where('patient_id', $patient->id)
            ->findOrFail($id);
    }
}
