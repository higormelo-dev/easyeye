<?php

declare(strict_types=1);

namespace App\Services\Surgery\Sections;

use App\Enums\Surgery\{ChecklistPhase, SurgicalCaseStatus};
use App\Models\Surgery\SurgicalCase;
use App\Services\Surgery\{SurgeryChecklistService, SurgicalCaseService};

/** Aba "Checklist": Entrada, Pausa Cirúrgica e Saída (cirurgia segura, OMS). */
final class ChecklistSection implements SurgeryShowSection
{
    public function __construct(private readonly SurgeryChecklistService $checklist)
    {
    }

    public function key(): string
    {
        return 'checklist';
    }

    public function order(): int
    {
        return 35;
    }

    public function label(): string
    {
        return (string) __('surgery_schedule.checklist.tab');
    }

    public function visible(SurgicalCase $case, array $can): bool
    {
        return in_array($case->status, [SurgicalCaseStatus::Scheduled, SurgicalCaseStatus::Performed, SurgicalCaseStatus::Discharged], true);
    }

    public function props(SurgicalCase $case, array $can): array
    {
        $case->loadMissing('iolPlans');

        $overview = $this->checklist->overview($case);
        $snapshot = (array) ($case->clinical_safety_snapshot ?? []);

        return [
            ...$overview,
            'can_answer' => $can['manage'] && $overview['accepts_work'],
            // O que a equipe confere na Entrada e na Pausa, à mão na própria aba.
            'safety' => [
                'taken_at'     => $case->clinical_safety_snapshot_at?->toIso8601String(),
                'live_differs' => app(SurgicalCaseService::class)->safetySnapshotIsStale($case),
                'allergies'    => $snapshot['allergies_snapshot'] ?? null,
                'medications'  => $snapshot['medications_snapshot'] ?? [],
                'risk_alerts'  => $snapshot['risk_alerts'] ?? [],
            ],
            'iol_plans' => $case->iolPlans->map(fn ($p) => [
                'eye'   => $p->eye->label(),
                'model' => $p->model_label,
                'power' => $p->power,
            ])->all(),
            'eye'        => $case->eye->label(),
            'anesthesia' => $case->anesthesia?->label(),
            'urls'       => [
                'save'  => route('panel.surgery.checklist.save', ['surgicalCase' => $case->getKey(), 'phase' => '__PHASE__']),
                'close' => route('panel.surgery.checklist.close', ['surgicalCase' => $case->getKey(), 'phase' => '__PHASE__']),
            ],
            'phases_order' => ChecklistPhase::values(),
        ];
    }
}
