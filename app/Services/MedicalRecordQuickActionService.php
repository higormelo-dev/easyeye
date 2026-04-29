<?php

namespace App\Services;

use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, ReportSettingContent};
use InvalidArgumentException;
use NumberFormatter;

/**
 * Orquestra emissões rápidas (paridade operacional com botões legados).
 */
class MedicalRecordQuickActionService
{
    public function __construct(
        private readonly MedicalRecordDocumentationService $documentationService,
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

        $resolved = $this->documentationService->loadTemplate($content, $patient, $doctor, $entity, $record);
        $html     = $this->applyCustomReplacements($resolved['html'], $this->buildCustomReplacements($action, $payload));

        return $this->documentationService->store(
            $record,
            $content,
            $html,
            $title ?: null,
        );
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
            default => throw new InvalidArgumentException('Ação rápida inválida.'),
        };
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
            'cataract-prescription' => [
                '{{OLHO_OPERADO}}'  => (string) ($payload['eye'] ?? ''),
                '{{DATA_CIRURGIA}}' => (string) ($payload['date_surgery'] ?? ''),
                '{{HORA_CIRURGIA}}' => (string) ($payload['hour_surgery'] ?? ''),
            ],
            'medical-declaration' => [
                '{{CONTEUDO_LIVRE}}' => $this->sanitizeMultiline((string) ($payload['content'] ?? '')),
            ],
            'medication-prescription' => [
                '{{LISTA_MEDICAMENTOS}}' => $this->sanitizeMultiline((string) ($payload['content'] ?? '')),
            ],
            'procedure-request' => [
                '{{PROCEDIMENTOS_SOLICITADOS}}' => $this->sanitizeMultiline((string) ($payload['content'] ?? '')),
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
            '{{DIAS_AFASTAMENTO_EXTENSO}}' => $this->spellNumberPtBr($days),
            '{{DATA_ATUAL}}'               => $date,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveCataractSlug(array $payload): string
    {
        $template = (string) ($payload['template'] ?? 'pre_operatorio');

        return match ($template) {
            '1', 'pre_operatorio' => 'pre_operatorio',
            '2', 'pos_operatorio' => 'pos_operatorio',
            '3', 'instrucoes_cirurgicas' => 'instrucoes_cirurgicas',
            default => 'pre_operatorio',
        };
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
        if ($replacements === []) {
            return $html;
        }

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $html,
        );
    }

    private function sanitizeMultiline(string $value): string
    {
        return nl2br(e(trim($value)));
    }

    private function spellNumberPtBr(int $number): string
    {
        if ($number <= 0) {
            return 'zero';
        }

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter('pt_BR', NumberFormatter::SPELLOUT);
            $formatted = $formatter->format($number);

            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }

        return (string) $number;
    }
}
