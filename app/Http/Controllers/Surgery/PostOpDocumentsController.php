<?php

declare(strict_types=1);

namespace App\Http\Controllers\Surgery;

use App\Enums\Surgery\SurgeryDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Surgery\Concerns\AuthorizesSurgery;
use App\Models\Surgery\{SurgeryDocumentIssue, SurgicalCase};
use App\Services\Surgery\SurgeryDocumentIssueService;
use Illuminate\Http\Response;

/** PDF das orientações pós-operatórias e da alta — montado só do retrato congelado. */
class PostOpDocumentsController extends Controller
{
    use AuthorizesSurgery;

    public function __construct(private readonly SurgeryDocumentIssueService $documents)
    {
    }

    public function pdf(SurgicalCase $surgicalCase, SurgeryDocumentIssue $document): Response
    {
        $this->authorizeCaseAccess($surgicalCase);

        abort_unless(
            (string) $document->surgical_case_id === (string) $surgicalCase->getKey()
            && in_array($document->type, [SurgeryDocumentType::PostOpGuidance, SurgeryDocumentType::Discharge], true),
            404,
        );

        $this->logSurgeryRead($document, (string) $document->patient_id);

        return $this->documents->pdf($document);
    }
}
