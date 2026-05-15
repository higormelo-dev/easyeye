<?php

namespace App\Services;

use App\Enums\{DocumentationType, PaperSize};
use App\Models\Entity;
use App\Models\{MedicalRecord, MedicalRecordDocumentation, ReportSetting};
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Http\Response;

/**
 * Gera PDFs via barryvdh/laravel-snappy (wkhtmltopdf).
 *
 * Substitui o dompdf do smart_oftal. As vantagens do wkhtmltopdf são:
 *   - Renderização completa de CSS3/HTML5 (via WebKit)
 *   - Suporte a cabeçalhos e rodapés em HTML separados
 *   - Melhor fidelidade visual para templates clínicos complexos
 */
class MedicalRecordPdfService
{
    /**
     * Gera o PDF completo do prontuário (todas as seções clínicas).
     */
    public function generateRecord(MedicalRecord $record): Response
    {
        $record->loadMissing([
            'patient.person',
            'patient.covenant',
            'doctor.person',
            'schedule',
            'visualAcuityType',
            'colorVisionType',
            'NearPointConvergence',
            'coverTestType',
            'additionType',
            'lensAway',
            'lensNear',
            'visualAcuityTypeWithoutCorrectionRight',
            'visualAcuityTypeWithoutCorrectionLeft',
            'visualAcuityTypeWitCorrectionRight',
            'visualAcuityTypeWitCorrectionLeft',
            'signedBy.user',
        ]);

        $setting = $this->resolveEntitySetting(
            $record->schedule?->entity_id ?? session('selected_entity_id')
        );

        $filename = 'PMR-' . $record->code . '-' . ($record->patient->person?->full_name ?? 'paciente') . '.pdf';
        $filename = preg_replace('/[^A-Za-z0-9\-_.]/', '_', $filename);

        return SnappyPdf::loadView('pdf.medical_record', compact('record', 'setting'))
            ->setPaper($setting?->paper_size?->value ?? PaperSize::A4->value)
            ->setOption('margin-top', ($setting?->margin_top ?? 2.0) . 'cm')
            ->setOption('margin-right', ($setting?->margin_right ?? 1.5) . 'cm')
            ->setOption('margin-bottom', ($setting?->margin_bottom ?? 2.0) . 'cm')
            ->setOption('margin-left', ($setting?->margin_left ?? 2.5) . 'cm')
            ->setOption('footer-font-size', '8')
            ->setOption('footer-right', 'Pág. [page] de [topage]')
            ->setOption('encoding', 'UTF-8')
            ->inline($filename);
    }

    /**
     * Resolve o ReportSetting correto para geração de PDF de documentação.
     *
     * Prioridade:
     *   1. Cópia adotada pela entidade do mesmo template global do documento.
     *   2. Setting próprio do documento (pode ser global ou da entidade).
     *   3. Qualquer setting ativo da entidade (fallback genérico).
     */
    private function resolveSettingForDoc(MedicalRecordDocumentation $doc, ?string $entityId): ?ReportSetting
    {
        $docSetting = $doc->reportSetting;

        // Se o setting do documento é um template global, tenta usar a cópia adotada
        // pela entidade para que as configurações de layout da clínica sejam aplicadas.
        if ($docSetting?->isGlobal() && $entityId) {
            $entityCopy = ReportSetting::where('entity_id', $entityId)
                ->where('source_setting_id', $docSetting->id)
                ->where('active', true)
                ->first();

            if ($entityCopy) {
                return $entityCopy;
            }
        }

        return $docSetting ?? $this->resolveEntitySetting($entityId);
    }

    /**
     * Resolve o ReportSetting ativo para a entidade.
     * Fallback para o primeiro setting global publicado quando a entidade
     * ainda não adotou os modelos do sistema (evita usar defaults hardcoded).
     */
    private function resolveEntitySetting(?string $entityId): ?ReportSetting
    {
        if ($entityId) {
            $setting = ReportSetting::where('entity_id', $entityId)
                ->where('active', true)
                ->first();

            if ($setting) {
                return $setting;
            }
        }

        return ReportSetting::whereNull('entity_id')
            ->where('active', true)
            ->first();
    }

    /**
     * Renders the footer HTML to a temp file for use with wkhtmltopdf's --footer-html.
     * Returns the file path (caller is responsible for unlinking after use).
     */
    private function buildFooterFile(?Entity $entity, ?ReportSetting $setting): ?string
    {
        if (! $entity) {
            return null;
        }

        $parts = array_filter([
            $entity->address,
            $entity->number     ? 'nº ' . $entity->number : null,
            $entity->complement ?: null,
            $entity->district   ?: null,
            ($entity->city && $entity->state)
                ? $entity->city . '/' . $entity->state
                : ($entity->city ?? $entity->state ?? null),
            $entity->zipcode ? 'CEP ' . $entity->zipcode : null,
        ]);

        $html = view('pdf.partials.footer', [
            'address'    => count($parts) ? implode(', ', $parts) : null,
            'telephone'  => $entity->telephone  ?: null,
            'cellphone'  => $entity->cellphone  ?: null,
            'email'      => $entity->email      ?: null,
            'fontFamily' => $setting?->font_family ?? 'Arial',
        ])->render();

        $path = tempnam(sys_get_temp_dir(), 'eeye_ftr_') . '.html';
        file_put_contents($path, $html);

        return $path;
    }

    /**
     * Gera o Laudo de Tonômetria com o horário capturado no momento da impressão.
     */
    public function generateTonometry(MedicalRecord $record, string $time): Response
    {
        $record->loadMissing(['patient.person', 'doctor.person', 'schedule']);

        $setting = ReportSetting::where('entity_id', $record->schedule?->entity_id ?? session('selected_entity_id'))
            ->where('active', true)
            ->first();

        $entity = $record->schedule?->entity
            ?? Entity::find($record->schedule?->entity_id ?? session('selected_entity_id'));

        $filename   = 'TONOMETRIA-' . $record->code . '.pdf';
        $footerPath = $this->buildFooterFile($entity, $setting);

        $pdf = SnappyPdf::loadView('pdf.tonometry', compact('record', 'setting', 'time'))
            ->setPaper($setting?->paper_size?->value ?? PaperSize::A4->value)
            ->setOption('margin-top', ($setting?->margin_top ?? 2.0) . 'cm')
            ->setOption('margin-right', ($setting?->margin_right ?? 1.5) . 'cm')
            ->setOption('margin-bottom', max((float) ($setting?->margin_bottom ?? 2.0), 2.0) . 'cm')
            ->setOption('margin-left', ($setting?->margin_left ?? 2.5) . 'cm')
            ->setOption('encoding', 'UTF-8')
            ->setOption('footer-html', $footerPath)
            ->inline($filename);

        if ($footerPath) {
            @unlink($footerPath);
        }

        return $pdf;
    }

    /**
     * Gera o PDF de uma documentação clínica (receituário, solicitação, etc.).
     * Para tipo 'tonometry', usa a view dedicada com dados do campo content (JSON).
     */
    public function generateDocumentation(MedicalRecordDocumentation $doc): Response
    {
        $doc->loadMissing(['patient.person', 'doctor.person', 'reportSetting', 'medicalRecord.schedule']);

        $filename = strtoupper($doc->type->value) . '-' . $doc->id . '.pdf';

        if ($doc->type === DocumentationType::Tonometry) {
            $entity = $doc->medicalRecord?->schedule?->entity
                ?? Entity::find(session('selected_entity_id'));
            $setting = $this->resolveEntitySetting($entity?->id);
            $tData   = $doc->tonometryData();
            $patient = $doc->patient;
            $doctor  = $doc->doctor;
            $od      = $tData['od'];
            $oe      = $tData['oe'];
            $time    = $tData['time'] ?? now()->format('H:i');

            $footerPath = $this->buildFooterFile($entity, $setting);

            $pdf = SnappyPdf::loadView('pdf.tonometry', compact('patient', 'doctor', 'entity', 'setting', 'od', 'oe', 'time'))
                ->setPaper('A5', 'portrait')
                ->setOption('margin-top', '1.5cm')
                ->setOption('margin-right', '1.5cm')
                ->setOption('margin-bottom', '2cm')
                ->setOption('margin-left', '1.5cm')
                ->setOption('encoding', 'UTF-8')
                ->setOption('footer-html', $footerPath)
                ->inline($filename);

            if ($footerPath) {
                @unlink($footerPath);
            }

            return $pdf;
        }

        // reportSetting pode ser null se o content veio de um template global ou o setting foi removido.
        $entityId = $doc->medicalRecord?->schedule?->entity_id ?? session('selected_entity_id');
        $setting  = $this->resolveSettingForDoc($doc, $entityId);

        $entity     = $doc->medicalRecord?->schedule?->entity ?? Entity::find($entityId);
        $footerPath = $this->buildDocumentationFooterFile($setting, $entity);

        // --footer-html exige margem-bottom mínima para renderizar no espaço reservado.
        $marginBottom = $footerPath
            ? max((float) ($setting?->margin_bottom ?? 0), 0.8)
            : (float) ($setting?->margin_bottom ?? 0);

        $pdf = SnappyPdf::loadView('pdf.documentation', compact('doc', 'setting'))
            ->setPaper($setting?->paper_size?->value ?? PaperSize::A4->value)
            ->setOption('margin-top', ($setting?->margin_top ?? 0) . 'cm')
            ->setOption('margin-right', ($setting?->margin_right ?? 1.5) . 'cm')
            ->setOption('margin-bottom', $marginBottom . 'cm')
            ->setOption('margin-left', ($setting?->margin_left ?? 1.5) . 'cm')
            ->setOption('encoding', 'UTF-8');

        if ($footerPath) {
            $pdf->setOption('footer-html', $footerPath);
        }

        $result = $pdf->inline($filename);

        if ($footerPath) {
            @unlink($footerPath);
        }

        return $result;
    }

    /**
     * Gera HTML temporário para --footer-html da documentação clínica.
     * Respeita as configurações granulares do ReportSetting (footer_text,
     * footer_show_address, footer_show_phone).
     */
    private function buildDocumentationFooterFile(?ReportSetting $setting, ?Entity $entity): ?string
    {
        if (! ($setting?->show_footer)) {
            return null;
        }

        $font  = htmlspecialchars($setting->font_family ?? 'Arial', ENT_QUOTES);
        $parts = [];

        $parts[] = htmlspecialchars($setting->footer_text ?: ($entity?->name ?? ''), ENT_QUOTES);

        if (($setting->footer_show_address ?? false) && $entity?->address_full) {
            $parts[] = htmlspecialchars($entity->address_full, ENT_QUOTES);
        }

        if (($setting->footer_show_phone ?? false) && ($entity?->telephone || $entity?->cellphone)) {
            $parts[] = htmlspecialchars((string) ($entity->telephone ?? $entity->cellphone), ENT_QUOTES);
        }

        $parts[] = 'Emitido em ' . now()->isoFormat('L LT');

        $body = implode(' &nbsp;·&nbsp; ', $parts);

        $html = <<<HTML
            <!DOCTYPE html>
            <html><head><meta charset="UTF-8"></head>
            <body style="margin:0;padding:4px 0 0;font-family:{$font},Helvetica,sans-serif;font-size:8pt;color:#aaa;text-align:center;border-top:1px solid #e0e0e0;">
                {$body}
            </body></html>
            HTML;

        $path = tempnam(sys_get_temp_dir(), 'eeye_doc_ftr_') . '.html';
        file_put_contents($path, $html);

        return $path;
    }
}
