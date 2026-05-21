<?php

namespace App\Services;

use App\Enums\ExamReportRegistry;
use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, ReportSettingContent};
use InvalidArgumentException;
use Mews\Purifier\Facades\Purifier;

/**
 * Orquestra emissões rápidas (paridade operacional com botões legados).
 */
class MedicalRecordQuickActionService
{
    public function __construct(
        private readonly MedicalRecordDocumentationService $documentationService,
        private readonly SurgerySchedulingDocService $surgerySchedulingDoc,
        private readonly DayExtensionService $dayExtension,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function issue(
        string $action,
        MedicalRecord $record,
        Patient $patient,
        Doctor $doctor,
        Entity $entity,
        array $payload = [],
    ): MedicalRecordDocumentation {
        [$settingTitle, $slug, $title] = $this->resolveTemplateDefinition($action, $payload);

        $content = $this->findTemplateContent(
            (string) $entity->id,
            $settingTitle,
            $slug,
        );

        $customReplacements = $this->buildCustomReplacements($action, $payload);

        // Custom replacements precedem o resolver: caso contrário variáveis
        // tipadas (e.g. {{DATA_ATUAL}} source_type=date) sobrescrevem o
        // payload do médico (ex.: atestado retroativo com data específica).
        $contentForResolve = $this->applyTemplateOverrides($content, $customReplacements);

        $resolved = $this->documentationService->loadTemplate($contentForResolve, $patient, $doctor, $entity, $record);
        $html     = $this->applyCustomReplacements($resolved['html'], []);
        $html     = $this->appendObservations($action, $html, $payload);

        return $this->documentationService->store(
            $record,
            $content,
            $html,
            $title ?: null,
        );
    }

    /**
     * Aplica custom replacements (payload do médico) no conteúdo bruto do
     * template — antes do resolver. Devolve um clone do `ReportSettingContent`
     * para evitar mutação do model recuperado do banco.
     *
     * @param array<string, string> $replacements
     */
    private function applyTemplateOverrides(ReportSettingContent $content, array $replacements): ReportSettingContent
    {
        if ($replacements === []) {
            return $content;
        }

        $clone          = clone $content;
        $clone->content = str_replace(
            array_keys($replacements),
            array_values($replacements),
            (string) $content->content,
        );

        return $clone;
    }

    /**
     * F7 — atestados aceitam observações livres do médico, anexadas ao final
     * do template renderizado em um bloco semântico próprio. Outros actions
     * são pass-through.
     *
     * @param array<string, mixed> $payload
     */
    private function appendObservations(string $action, string $html, array $payload): string
    {
        if (! in_array($action, ['attendance-certificate', 'medical-certificate'], true)) {
            return $html;
        }

        $raw = trim((string) ($payload['content'] ?? ''));

        if ($raw === '') {
            return $html;
        }

        // Atestados agora vêm do TinyMCE (HTML rico). Detecta tags para
        // decidir entre sanitizar HTML (profile `medical`) ou escapar +
        // nl2br (legado / payload sem editor).
        $isHtml = $raw !== strip_tags($raw);
        $body   = $isHtml ? $this->sanitizeHtml($raw) : nl2br(e($raw));

        // ESTRUTURA INLINE INTENCIONAL — usamos `<hr>` separador e inline styles
        // em vez de `border-top` num div container. Motivo: o wkhtmltopdf 0.12.6
        // tem bug onde `border-top` em container próximo a um `position: fixed`
        // (como o bloco de assinatura) reordena o fluxo: parte do conteúdo
        // aparece, parte é posicionada APÓS o bloco fixed. `<hr>` + `<p>`
        // soltos no fluxo (sem container com border) são renderizados na
        // ordem correta pelo wkhtmltopdf.
        return $html
            . '<hr style="border:0;border-top:1px solid #999;margin:1.4em 0 0.6em;width:100%;">'
            . '<p style="margin:0 0 0.4em;"><strong style="color:#1976d2;">Observações:</strong></p>'
            . '<div>' . $body . '</div>';
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{0:string, 1:string, 2:string}
     */
    private function resolveTemplateDefinition(string $action, array $payload): array
    {
        return match ($action) {
            'pterygium-prescription'  => ['RECEITUÁRIO DE PTERÍGIO', 'pos_operatorio', 'Receituário de Pterígio'],
            'test-eye'                => ['TESTE DO OLHINHO', 'padrao', 'Teste do Olhinho'],
            'attendance-certificate'  => ['ATESTADO DE COMPARECIMENTO', 'comparecimento', 'Atestado de Comparecimento'],
            'medical-certificate'     => ['ATESTADO MÉDICO', 'afastamento', 'Atestado Médico'],
            'cataract-prescription'   => ['RECEITUÁRIO DE CATARATA', $this->resolveCataractSlug($payload), 'Receituário de Catarata'],
            'retinal-mapping'         => ['MAPEAMENTO DE RETINA', 'padrao', 'Mapeamento de Retina'],
            'ophthalmological-report' => ['LAUDO OFTALMOLÓGICO', 'completo', 'Laudo Oftalmológico'],
            'medical-declaration'     => ['RELATÓRIO OFTALMOLÓGICO', 'declaracao_medica', 'Declaração Médica'],
            'medication-prescription' => ['PRESCRIÇÃO DE MEDICAMENTOS', 'padrao', 'Prescrição de Medicamentos'],
            'procedure-request'       => ['SOLICITAÇÃO DE PROCEDIMENTOS', 'padrao', 'Solicitação de Procedimentos'],
            'lens-prescription'       => [
                'PRESCRIÇÃO DE LENTES',
                $this->resolveLensPrescriptionSlug($payload),
                $this->resolveLensPrescriptionTitle($payload),
            ],
            'exam-report' => $this->resolveExamReportDefinition($payload),
            default       => throw new InvalidArgumentException('Ação rápida inválida.'),
        };
    }

    /**
     * Resolve definição de template para emissão genérica de laudo de exame.
     *
     * Centraliza no `ExamReportRegistry` o mapeamento `exam_type → ReportSetting+slug`,
     * permitindo que F4 evolua para 15 tipos sem alterar o controller.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{0:string, 1:string, 2:string}
     */
    private function resolveExamReportDefinition(array $payload): array
    {
        $examType = (string) ($payload['exam_type'] ?? '');
        $exam     = ExamReportRegistry::tryFrom($examType);

        if (! $exam) {
            throw new InvalidArgumentException("Tipo de exame inválido: {$examType}");
        }

        // F4e — exames com múltiplas variantes (Pentacam) usam payload.subtype
        // p/ resolver o slug correto do template. Sem subtype, cai no default.
        $subtype = isset($payload['subtype']) && $payload['subtype'] !== ''
            ? (string) $payload['subtype']
            : null;

        $slug = $exam->resolveSubtypeSlug($subtype);

        $defaultTitle = $exam->label();

        if ($subtype !== null && ($subtypeLabel = $exam->subtypeLabel($subtype)) !== null) {
            $defaultTitle = $exam->label() . ' — ' . $subtypeLabel;
        }

        $title = (string) ($payload['title'] ?? '');
        $title = $title !== '' ? $title : $defaultTitle;

        return [$exam->settingTitle(), $slug, $title];
    }

    /**
     * Mapeia o modo de impressão (paridade smart_oftal templates 1..4) para o slug do template.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveLensPrescriptionSlug(array $payload): string
    {
        $mode = (string) ($payload['mode'] ?? 'dynamic');

        return match ($mode) {
            'dynamic'            => 'dinamica_longe',
            'static'             => 'estatica_longe',
            'presbyopia_dynamic' => 'longe_perto_presbiopia',
            'presbyopia'         => 'perto_presbiopia',
            default              => 'dinamica_longe',
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveLensPrescriptionTitle(array $payload): string
    {
        $mode = (string) ($payload['mode'] ?? 'dynamic');

        return match ($mode) {
            'dynamic'            => 'Receituário de Lentes — Dinâmica',
            'static'             => 'Receituário de Lentes — Estática',
            'presbyopia_dynamic' => 'Receituário de Lentes — Longe e Perto (Presbiopia)',
            'presbyopia'         => 'Receituário de Lentes — Perto (Presbiopia)',
            default              => 'Receituário de Lentes',
        };
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, string>
     */
    private function buildCustomReplacements(string $action, array $payload): array
    {
        return match ($action) {
            'medical-certificate'   => $this->buildMedicalCertificateReplacements($payload),
            'cataract-prescription' => $this->surgerySchedulingDoc->buildReplacements(
                (string) ($payload['eye'] ?? ''),
                isset($payload['date_surgery']) ? (string) $payload['date_surgery'] : null,
                isset($payload['hour_surgery']) ? (string) $payload['hour_surgery'] : null,
            ),
            'medical-declaration' => [
                '{{CONTEUDO_LIVRE}}' => $this->sanitizeMultiline((string) ($payload['content'] ?? '')),
            ],
            'medication-prescription' => [
                '{{LISTA_MEDICAMENTOS}}' => $this->sanitizeMultiline((string) ($payload['content'] ?? '')),
            ],
            'procedure-request' => [
                '{{PROCEDIMENTOS_SOLICITADOS}}' => $this->sanitizeMultiline((string) ($payload['content'] ?? '')),
            ],
            'exam-report' => [
                // exam-report content vem do TinyMCE (HTML rico). Usa profile
                // `medical` em vez de escape literal para preservar formatação
                // clínica e bloquear XSS persistente em PDF/views.
                '{{CONTEUDO_LIVRE}}' => $this->sanitizeHtml((string) ($payload['content'] ?? '')),
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, string>
     */
    private function buildMedicalCertificateReplacements(array $payload): array
    {
        $days = max(1, (int) ($payload['days'] ?? 1));
        $date = (string) ($payload['date'] ?? '');
        $date = $date !== '' ? $date : now()->format('d/m/Y');

        return [
            '{{DIAS_AFASTAMENTO}}'         => (string) $days,
            '{{DIAS_AFASTAMENTO_EXTENSO}}' => $this->dayExtension->spell($days),
            '{{DATA_ATUAL}}'               => $date,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveCataractSlug(array $payload): string
    {
        return $this->surgerySchedulingDoc->resolveTemplateSlug(
            isset($payload['template']) ? (string) $payload['template'] : null,
        );
    }

    /**
     * Localiza o template ativo para um exame do `ExamReportRegistry` na entidade,
     * com fallback para template global. Reusado pelo endpoint exam-template.
     */
    public function findActiveTemplateForExam(string $entityId, ExamReportRegistry $exam): ReportSettingContent
    {
        return $this->findTemplateContent($entityId, $exam->settingTitle(), $exam->slug());
    }

    /**
     * F4e — variante do anterior que respeita subtipo selecionado pelo médico
     * em exames multi-template (ex: Pentacam). Subtype inválido cai no default.
     */
    public function findActiveTemplateForExamSubtype(string $entityId, ExamReportRegistry $exam, ?string $subtype): ReportSettingContent
    {
        $slug = $exam->resolveSubtypeSlug($subtype);

        return $this->findTemplateContent($entityId, $exam->settingTitle(), $slug);
    }

    private function findTemplateContent(string $entityId, string $settingTitle, string $slug): ReportSettingContent
    {
        /** @var ReportSettingContent|null $content */
        $content = ReportSettingContent::query()
            ->with('reportSetting')
            ->where('slug', $slug)
            ->where('active', true)
            ->whereHas('reportSetting', fn ($q) => $q
                ->where('title', $settingTitle)
                ->where('active', true)
                ->where(fn ($query) => $query->where('entity_id', $entityId)->orWhereNull('entity_id')))
            ->get()
            ->sortBy(fn (ReportSettingContent $item) => $item->reportSetting?->entity_id ? 0 : 1)
            ->first();

        if (! $content) {
            throw new InvalidArgumentException("Template não encontrado para ação: {$settingTitle} / {$slug}");
        }

        return $content;
    }

    /**
     * @param array<string, string> $replacements
     */
    private function applyCustomReplacements(string $html, array $replacements): string
    {
        if ($replacements !== []) {
            $html = str_replace(
                array_keys($replacements),
                array_values($replacements),
                $html,
            );
        }

        // Scrub final: placeholders custom não preenchidos (payload sem o valor)
        // viram string vazia. Evita "{{XYZ}}" residual no PDF emitido.
        return preg_replace('/\{\{[A-Z_][A-Z0-9_]*\}\}/u', '', $html) ?? $html;
    }

    private function sanitizeMultiline(string $value): string
    {
        return nl2br(e(trim($value)));
    }

    /**
     * Sanitiza HTML rico (TinyMCE) com profile `medical` do HTMLPurifier.
     * Preserva formatação clínica (listas, tabelas, headings) e remove
     * vetores de XSS (script, iframe, on*, javascript: URIs).
     */
    private function sanitizeHtml(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return Purifier::clean($value, 'medical');
    }
}
