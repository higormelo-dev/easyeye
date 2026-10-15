<?php

declare(strict_types=1);

namespace App\Services\Surgery\Sections;

use App\Enums\Surgery\{SurgeryDocumentType, SurgicalCaseStatus};
use App\Models\Surgery\{SurgeryDocumentIssue, SurgicalCase};
use App\Services\Surgery\PostOp\SurgeryDischargeService;
use App\Services\Surgery\SurgeryParticipants;
use App\Support\CurrentDoctor;

/** Aba "Alta": o que falta, a decisão do cirurgião e, depois, o documento de alta emitido. */
final class DischargeSection implements SurgeryShowSection
{
    public function __construct(
        private readonly SurgeryDischargeService $discharge,
        private readonly SurgeryParticipants $participants,
    ) {
    }

    public function key(): string
    {
        return 'discharge';
    }

    public function order(): int
    {
        return 80;
    }

    public function label(): string
    {
        return (string) __('surgery_postop.discharge.tab');
    }

    public function visible(SurgicalCase $case, array $can): bool
    {
        return in_array($case->status, [SurgicalCaseStatus::Performed, SurgicalCaseStatus::Discharged], true);
    }

    public function props(SurgicalCase $case, array $can): array
    {
        $caseId   = (string) $case->getKey();
        $doctor   = CurrentDoctor::issuer();
        $surgeon  = $doctor !== null && $this->participants->isSurgeon($case, $doctor);
        $blockers = $this->discharge->blockers($case);

        $document = $case->status === SurgicalCaseStatus::Discharged
            ? $case->documents()->where('type', SurgeryDocumentType::Discharge->value)->whereNull('superseded_by_id')->with('issuerDoctor.person')->first()
            : null;

        return [
            'discharged'    => $case->status === SurgicalCaseStatus::Discharged,
            'discharged_at' => $case->discharged_at?->toIso8601String(),
            'blockers'      => $blockers,
            'can_discharge' => $can['perform'] && $surgeon && $case->status === SurgicalCaseStatus::Performed,
            'is_surgeon'    => $surgeon,
            'document'      => $document === null ? null : $this->documentRow($document, $caseId),
            'urls'          => ['discharge' => route('panel.surgery.postop.discharge', $caseId)],
        ];
    }

    /** @return array<string, mixed> */
    private function documentRow(SurgeryDocumentIssue $d, string $caseId): array
    {
        return [
            'id'             => (string) $d->getKey(),
            'issued_at'      => $d->issued_at->toIso8601String(),
            'doctor'         => $d->issuerDoctor?->person?->full_name,
            'discharge_text' => $d->payload['data']['discharge_text'] ?? null,
            'follow_up_plan' => $d->payload['data']['follow_up_plan'] ?? null,
            'pdf_url'        => route('panel.surgery.postop.documents.pdf', ['surgicalCase' => $caseId, 'document' => (string) $d->getKey()]),
        ];
    }
}
