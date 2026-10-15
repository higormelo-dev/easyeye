<?php

declare(strict_types=1);

namespace App\Services\Surgery\Documents;

use App\Enums\DataAccessPurpose;
use App\Enums\Surgery\{SurgeryDocumentType, SurgicalCaseStatus};
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Surgery\{SurgeryConsentTemplate, SurgeryDocumentIssue, SurgicalCase};
use App\Services\DataAccessLogService;
use App\Services\Surgery\SurgeryDocumentIssueService;
use App\Support\CurrentDoctor;
use Illuminate\Http\{Response, UploadedFile};
use Illuminate\Support\Facades\Storage;
use Mews\Purifier\Facades\Purifier;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Camada fina de TCLE e orientações pré-operatórias sobre o
 * SurgeryDocumentIssueService (retrato congelado, hash, dedupe, substituição).
 *
 * Fluxo: prévia (modelo + variáveis do caso) → o usuário edita o texto →
 * emissão. A emissão resolve as variáveis de novo no servidor (o texto editado
 * pode ter variável digitada à mão) e recusa variável desconhecida. O texto
 * congelado é o ENVIADO, sanitizado — o modelo pode mudar depois sem efeito.
 */
class SurgeryDocumentService
{
    /** Tipos que esta camada emite (os demais vêm de outras etapas do módulo). */
    public const ISSUABLE = [SurgeryDocumentType::Consent, SurgeryDocumentType::PreOpGuidance];

    public function __construct(
        private readonly SurgeryDocumentIssueService $issues,
        private readonly SurgeryTemplateVariables $variables,
        private readonly SurgeryTemplateService $templates,
        private readonly DataAccessLogService $accessLog,
    ) {
    }

    /**
     * Prévia: o modelo (ou o texto de partida) com as variáveis do caso.
     *
     * @return array{title: string, html: string, pending: list<string>, unknown: list<string>, template: array{id: string, version: int}|null}
     */
    public function preview(SurgicalCase $case, SurgeryDocumentType $type, ?string $templateId): array
    {
        $this->assertIssuable($type);

        $template = $templateId !== null ? $this->template($case, $type, $templateId) : null;
        $body     = $template?->body ?? '';
        $resolved = $this->variables->resolve($body, $this->variables->forCase($case));

        return [
            // Olho do caso com a sigla no idioma do usuário (OD/OE/AO · OD/OS/OU), para a tela mostrar
            // ao lado do texto; vazio = a variável {{olho}} sairá em branco e a tela avisa.
            'eye'      => $case->eye ? ['value' => $case->eye->value, 'short' => $case->eye->short(), 'label' => $case->eye->label()] : null,
            'title'    => $template?->name ?? $this->defaultTitle($type),
            'html'     => Purifier::clean($resolved['html'], 'medical'),
            'pending'  => $resolved['pending'],
            'unknown'  => $resolved['unknown'],
            'template' => $template ? ['id' => (string) $template->getKey(), 'version' => (int) $template->version] : null,
            // Retrato do pedido em que a prévia se baseou: volta na emissão e, se o pedido mudou no meio, ela é recusada.
            'basis_hash' => ConsentBasis::hash(ConsentBasis::of($case)),
        ];
    }

    /**
     * Emite (ou reemite) o documento. Reemissão = nova linha; a vigente anterior
     * do mesmo tipo vira histórico (SurgeryDocumentIssueService).
     */
    public function issue(SurgicalCase $case, SurgeryDocumentType $type, string $title, string $content, ?string $templateId, ?string $basisHash = null): SurgeryDocumentIssue
    {
        $this->assertIssuable($type);

        if ($case->status === SurgicalCaseStatus::Cancelled) {
            throw SurgeryRuleException::state('surgery.errors.case_closed');
        }

        $template = $templateId !== null ? $this->template($case, $type, $templateId) : null;
        $resolved = $this->variables->resolve($content, $this->variables->forCase($case));

        if ($resolved['unknown'] !== []) {
            throw SurgeryRuleException::invalid('surgery_documents.errors.unknown_variables', ['variables' => '{{' . implode('}}, {{', $resolved['unknown']) . '}}']);
        }

        if (trim(strip_tags($resolved['html'])) === '') {
            throw SurgeryRuleException::invalid('surgery_documents.errors.empty_content');
        }

        $case->loadMissing('surgeon.person');

        return $this->issues->issue(
            case: $case,
            type: $type,
            title: mb_substr(trim($title), 0, 200),
            content: $resolved['html'],
            data: [
                'source' => $template ? 'template' : 'free_text',
                'items'  => array_values(array_filter([
                    ['label' => (string) __('surgery_documents.pdf.responsible_doctor'), 'value' => trim((string) ($case->surgeon?->person?->full_name ?? '') . ($case->surgeon?->record ? ' — ' . __('surgery.pdf.registry') . ' ' . $case->surgeon->record : ''))],
                    $template ? ['label' => (string) __('surgery_documents.pdf.template'), 'value' => $template->name . ' (v' . $template->version . ')'] : null,
                ])),
            ],
            templateId: $template ? (string) $template->getKey() : null,
            templateVersion: $template ? (int) $template->version : null,
            basisHash: $basisHash,
        );
    }

    /** PDF inline, montado só do retrato gravado; o acesso entra no log de dados sensíveis. */
    public function pdf(SurgeryDocumentIssue $issue): Response
    {
        $this->logAccess($issue);

        return $this->issues->pdf($issue);
    }

    public function attachSignedCopy(SurgeryDocumentIssue $issue, UploadedFile $file): SurgeryDocumentIssue
    {
        return $this->issues->attachSignedCopy($issue, $file);
    }

    /** Cópia assinada para visualizar/baixar (autenticado; o arquivo fica em disco privado). */
    public function signedCopy(SurgeryDocumentIssue $issue): StreamedResponse
    {
        if (! $issue->hasSignedCopy() || ! Storage::disk('local')->exists((string) $issue->signed_copy_path)) {
            abort(404);
        }

        $this->logAccess($issue);

        $name = $this->safeFilename((string) $issue->signed_copy_name, (string) $issue->signed_copy_mime);

        return Storage::disk('local')->response((string) $issue->signed_copy_path, $name, [
            'Content-Type'           => (string) $issue->signed_copy_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ]);
    }

    /** Título de partida quando não há modelo. */
    public function defaultTitle(SurgeryDocumentType $type): string
    {
        return (string) __('surgery_documents.default_title.' . $type->value);
    }

    /** @return array<string, mixed> */
    public function serialize(SurgeryDocumentIssue $issue, ?string $caseId = null): array
    {
        $issue->loadMissing('issuer', 'issuerDoctor.person');

        $caseId ??= (string) $issue->surgical_case_id;

        return [
            'id'                 => (string) $issue->getKey(),
            'type'               => $issue->type->value,
            'type_label'         => $issue->type->label(),
            'title'              => $issue->title,
            'issued_at'          => $issue->issued_at?->toIso8601String(),
            'issuer'             => $issue->issuer?->name,
            'doctor'             => $issue->issuerDoctor?->person?->full_name,
            'template_version'   => $issue->template_version,
            'current'            => $issue->isCurrent(),
            'superseded_by_id'   => $issue->superseded_by_id,
            'scope'              => $issue->payload['scope'] ?? 'main',
            'requires_signature' => self::requiresSignature($issue->type),
            'has_signed_copy'    => $issue->hasSignedCopy(),
            'signed_copy'        => $issue->hasSignedCopy() ? [
                'name'        => $issue->signed_copy_name,
                'mime'        => $issue->signed_copy_mime,
                'size'        => $issue->signed_copy_size,
                'uploaded_at' => $issue->signed_copy_uploaded_at?->toIso8601String(),
            ] : null,
            'content_hash' => $issue->content_hash,
            'can_reissue'  => $issue->isCurrent() && in_array($issue->type, self::ISSUABLE, true),
            // Texto de partida da reemissão — só dos vigentes reemissíveis (evita carregar todos os corpos).
            'content' => $issue->isCurrent() && in_array($issue->type, self::ISSUABLE, true) ? $issue->content : null,
            'urls'    => [
                'pdf'               => route('panel.surgery.documents.pdf', ['surgicalCase' => $caseId, 'document' => $issue->getKey()]),
                'signed_copy'       => route('panel.surgery.documents.signed-copy.show', ['surgicalCase' => $caseId, 'document' => $issue->getKey()]),
                'signed_copy_store' => route('panel.surgery.documents.signed-copy.store', ['surgicalCase' => $caseId, 'document' => $issue->getKey()]),
            ],
        ];
    }

    /** Tipos que pedem a assinatura do paciente (cópia digitalizada) e geram alerta enquanto faltar. */
    public static function requiresSignature(SurgeryDocumentType $type): bool
    {
        return in_array($type, [SurgeryDocumentType::Consent, SurgeryDocumentType::ImplantRecord], true);
    }

    // ── Internos ────────────────────────────────────────────────────────────

    private function assertIssuable(SurgeryDocumentType $type): void
    {
        if (! in_array($type, self::ISSUABLE, true)) {
            throw SurgeryRuleException::invalid('surgery_documents.errors.type_not_issuable');
        }
    }

    /** Modelo ativo, do tipo certo, DESTA clínica — qualquer outro é 422 (nunca vaza que existe). */
    private function template(SurgicalCase $case, SurgeryDocumentType $type, string $templateId): SurgeryConsentTemplate
    {
        $template = SurgeryConsentTemplate::query()
            ->where('entity_id', $case->entity_id)
            ->where('type', $type->value)
            ->where('active', true)
            ->whereKey($templateId)
            ->first();

        if ($template === null) {
            throw SurgeryRuleException::invalid('surgery_documents.errors.template_not_found');
        }

        return $template;
    }

    private function logAccess(SurgeryDocumentIssue $issue): void
    {
        $this->accessLog->log(
            $issue,
            CurrentDoctor::isDoctorProfile() ? DataAccessPurpose::PatientCare : DataAccessPurpose::Administrative,
            entityId: (string) $issue->entity_id,
        );
    }

    /** Nome de download sem caminho nem caractere de controle, com a extensão do tipo real do arquivo. */
    private function safeFilename(string $name, string $mime): string
    {
        $extension = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            default           => 'png',
        };

        $base = preg_replace('/[^\p{L}\p{N}\-_. ]/u', '_', pathinfo(basename($name), PATHINFO_FILENAME)) ?: 'documento';

        return mb_substr(trim($base), 0, 100) . '.' . $extension;
    }
}
