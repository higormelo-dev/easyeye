<?php

namespace App\Services;

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

        $setting = ReportSetting::where('entity_id', $record->schedule?->entity_id ?? session('selected_entity_id'))
            ->where('active', true)
            ->first();

        $filename = 'PMR-' . $record->code . '-' . ($record->patient->person?->full_name ?? 'paciente') . '.pdf';
        $filename = preg_replace('/[^A-Za-z0-9\-_.]/', '_', $filename);

        return SnappyPdf::loadView('pdf.medical_record', compact('record', 'setting'))
            ->setPaper($setting?->paper_size ?? 'A4')
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
     * Gera o PDF de uma documentação clínica (receituário, solicitação, etc.).
     */
    public function generateDocumentation(MedicalRecordDocumentation $doc): Response
    {
        $doc->loadMissing([
            'patient.person',
            'doctor.person',
            'reportSetting',
        ]);

        $setting  = $doc->reportSetting;
        $filename = strtoupper($doc->type) . '-' . $doc->id . '.pdf';

        return SnappyPdf::loadView('pdf.documentation', compact('doc', 'setting'))
            ->setPaper($setting?->paper_size ?? 'A4')
            ->setOption('margin-top', ($setting?->margin_top ?? 2.0) . 'cm')
            ->setOption('margin-right', ($setting?->margin_right ?? 1.5) . 'cm')
            ->setOption('margin-bottom', ($setting?->margin_bottom ?? 2.0) . 'cm')
            ->setOption('margin-left', ($setting?->margin_left ?? 2.5) . 'cm')
            ->setOption('encoding', 'UTF-8')
            ->inline($filename);
    }
}
