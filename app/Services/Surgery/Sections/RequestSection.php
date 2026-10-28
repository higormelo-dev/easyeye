<?php

declare(strict_types=1);

namespace App\Services\Surgery\Sections;

use App\Models\Surgery\{SurgicalCase, SurgicalCaseExam, SurgicalCaseIolPlan, SurgicalCaseMaterial, SurgicalCaseProcedure, SurgicalTeamMember};
use App\Services\Surgery\SurgicalCaseView;

/** Aba "Pedido": procedimentos, OPME, LIO planejada, exames de apoio, equipe e retrato de segurança clínica. */
final class RequestSection implements SurgeryShowSection
{
    public function key(): string
    {
        return 'request';
    }

    public function order(): int
    {
        return 10;
    }

    public function label(): string
    {
        return (string) __('surgery.tabs.request');
    }

    public function visible(SurgicalCase $case, array $can): bool
    {
        return true;
    }

    public function props(SurgicalCase $case, array $can): array
    {
        $case->loadMissing(['procedures', 'materials', 'iolPlans', 'exams.exam.examType', 'team']);

        $snapshot = (array) ($case->clinical_safety_snapshot ?? []);

        return [
            'procedures' => $case->procedures->map(fn (SurgicalCaseProcedure $p) => [
                'id'                  => (string) $p->getKey(),
                'procedure_id'        => $p->procedure_id,
                'tuss_code'           => $p->tuss_code,
                'description'         => $p->description,
                'quantity'            => (int) $p->quantity,
                'access_route'        => $p->access_route,
                'authorized_quantity' => $p->authorized_quantity,
            ])->all(),
            'materials' => $case->materials->map(fn (SurgicalCaseMaterial $m) => [
                'id'                      => (string) $m->getKey(),
                'entity_product_id'       => $m->entity_product_id,
                'description'             => $m->description,
                'quantity'                => (int) $m->quantity,
                'manufacturer'            => $m->manufacturer,
                'anvisa_registration'     => $m->anvisa_registration,
                'manufacturer_ref'        => $m->manufacturer_ref,
                'operating_authorization' => $m->operating_authorization,
                'manufacturer_option'     => $m->manufacturer_option,
                'unit_value'              => $m->unit_value,
                'technical_justification' => $m->technical_justification,
                'authorized_quantity'     => $m->authorized_quantity,
            ])->all(),
            'iol_plans' => $case->iolPlans->map(fn (SurgicalCaseIolPlan $p) => [
                'id'                   => (string) $p->getKey(),
                'eye'                  => $p->eye->value,
                'eye_label'            => $p->eye->label(),
                'entity_product_id'    => $p->entity_product_id,
                'model_label'          => $p->model_label,
                'power'                => $p->power,
                'cylinder'             => $p->cylinder,
                'axis'                 => $p->axis,
                'formula'              => $p->formula,
                'a_constant'           => $p->a_constant,
                'target_refraction'    => $p->target_refraction,
                'predicted_refraction' => $p->predicted_refraction,
                'notes'                => $p->notes,
            ])->all(),
            'exams' => $case->exams->map(fn (SurgicalCaseExam $e) => [
                'id'              => (string) $e->getKey(),
                'patient_exam_id' => (string) $e->patient_exam_id,
                'role'            => $e->role,
                'name'            => $e->exam?->examType?->name,
                'performed_at'    => $e->exam?->exam_performed_at?->toDateString(),
                'laterality'      => SurgicalCaseView::lateralityLabel($e->exam?->laterality),
                'notes'           => $e->notes,
            ])->all(),
            'team' => $case->team->map(fn (SurgicalTeamMember $t) => [
                'id'         => (string) $t->getKey(),
                'role'       => $t->role->value,
                'role_label' => $t->role->label(),
                'doctor_id'  => $t->doctor_id,
                'name'       => $t->name,
                'registry'   => $t->registry,
            ])->all(),
            'safety' => [
                'taken_at'    => $case->clinical_safety_snapshot_at?->toIso8601String(),
                'allergies'   => $snapshot['allergies_snapshot'] ?? null,
                'medications' => $snapshot['medications_snapshot'] ?? [],
                'risk_alerts' => $snapshot['risk_alerts'] ?? [],
            ],
            'can_edit_request'  => $can['manage'] && $case->status->allowsRequestChanges(),
            'can_edit_clinical' => $can['perform'] && $case->status->allowsAnyChange(),
            'can_edit_team'     => $can['manage'] && $case->status->allowsAnyChange(),
            'access_routes'     => SurgicalCaseProcedure::ACCESS_ROUTES,
            'exam_roles'        => SurgicalCaseExam::ROLES,
        ];
    }
}
