<?php

declare(strict_types=1);

namespace App\Services\Surgery\Sections;

use App\Enums\Surgery\{SurgeryDocumentType, SurgicalCaseStatus};
use App\Models\Surgery\{SurgeryDocumentIssue, SurgicalCase};
use App\Services\Surgery\Documents\{ConsentBasis, SurgeryDocumentService, SurgeryTemplateService};
use App\Services\Surgery\SurgeryDocumentIssueService;
use Illuminate\Support\Facades\Route;

/**
 * Aba "Documentos": TODOS os documentos congelados do caso (TCLE, orientações,
 * termo do implante, alta, orientações pós-operatórias — inclusive os emitidos
 * por outras etapas), com a situação da assinatura do paciente (cópia
 * digitalizada anexada × pendente) e a emissão de TCLE e orientações
 * pré-operatórias a partir dos modelos da clínica.
 */
final class DocumentsSection implements SurgeryShowSection
{
    public function __construct(
        private readonly SurgeryDocumentService $documents,
        private readonly SurgeryTemplateService $templates,
    ) {
    }

    public function key(): string
    {
        return 'documents';
    }

    public function order(): int
    {
        return 40;
    }

    public function label(): string
    {
        return (string) __('surgery_documents.tab');
    }

    public function visible(SurgicalCase $case, array $can): bool
    {
        return true;
    }

    public function props(SurgicalCase $case, array $can): array
    {
        $issues = $case->documents()->with(['issuer', 'issuerDoctor.person'])->get();
        // Realizada a cirurgia, o TCLE e a cópia assinada ficam como estão (sem reemitir nem trocar).
        $performed = $case->status->wasPerformed();
        $outdated  = null;
        $rows      = $issues->map(function (SurgeryDocumentIssue $d) use ($case, $performed, &$outdated) {
            $row = $this->documents->serialize($d, (string) $case->getKey());

            // O termo vigente que já não cobre o pedido (olho, procedimento, cirurgião, anestesia mudaram depois de emitido).
            $row['outdated_fields'] = $d->type === SurgeryDocumentType::Consent && $d->isCurrent() ? ConsentBasis::divergence($d, $case) : [];

            if ($row['outdated_fields'] !== []) {
                $outdated = ['id' => (string) $d->getKey(), 'title' => $d->title, 'fields' => ConsentBasis::labels($row['outdated_fields'])];
            }

            if ($performed) {
                $row['can_reissue'] = $row['can_reissue'] && $d->type !== SurgeryDocumentType::Consent;
                $row['copy_locked'] = $d->hasSignedCopy();
            }

            return $row;
        })->values()->all();

        $pending = $issues->filter(
            fn (SurgeryDocumentIssue $d) => $d->isCurrent() && SurgeryDocumentService::requiresSignature($d->type) && ! $d->hasSignedCopy(),
        );

        $canIssue = $can['manage'] && $case->status !== SurgicalCaseStatus::Cancelled;

        return [
            'documents'         => $rows,
            'outdated_consent'  => $outdated,
            'pending_signature' => $pending->map(fn (SurgeryDocumentIssue $d) => ['id' => (string) $d->getKey(), 'title' => $d->title, 'type_label' => $d->type->label()])->values()->all(),
            'can_issue'         => $canIssue,
            'can_attach'        => $can['manage'],
            'issuable'          => $canIssue ? collect(SurgeryDocumentService::ISSUABLE)->reject(fn (SurgeryDocumentType $type) => $performed && $type === SurgeryDocumentType::Consent)->map(fn (SurgeryDocumentType $type) => [
                'type'      => $type->value,
                'label'     => $type->label(),
                'templates' => $this->templates->suggestionsFor($case, $type)->map(fn ($t) => [
                    'id'        => (string) $t->getKey(),
                    'name'      => $t->name,
                    'version'   => (int) $t->version,
                    'category'  => $t->categoryLabel(),
                    'suggested' => $this->templates->isSuggested($case, $t),
                ])->all(),
            ])->values()->all() : [],
            'urls' => [
                'preview'   => route('panel.surgery.documents.preview', ['surgicalCase' => $case->getKey()]),
                'store'     => route('panel.surgery.documents.store', ['surgicalCase' => $case->getKey()]),
                'templates' => $can['manage'] && Route::has('panel.setting.surgery-templates.index') ? route('panel.setting.surgery-templates.index') : null,
            ],
            'signed_copy_mimes'  => SurgeryDocumentIssueService::SIGNED_COPY_MIMES,
            'signed_copy_max_mb' => (int) (SurgeryDocumentIssueService::SIGNED_COPY_MAX_KB / 1024),
        ];
    }
}
