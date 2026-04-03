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

        $setting = ReportSetting::where('entity_id', $record->schedule?->entity_id ?? session('selected_entity_id'))
            ->where('active', true)
            ->first();

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
     * Gera o Laudo de Tonômetria com o horário capturado no momento da impressão.
     */
    public function generateTonometry(MedicalRecord $record, string $time): Response
    {
        $record->loadMissing(['patient.person', 'doctor.person', 'schedule']);

        $setting = ReportSetting::where('entity_id', $record->schedule?->entity_id ?? session('selected_entity_id'))
            ->where('active', true)
            ->first();

        $filename = 'TONOMETRIA-' . $record->code . '.pdf';

        return SnappyPdf::loadView('pdf.tonometry', compact('record', 'setting', 'time'))
            ->setPaper($setting?->paper_size?->value ?? PaperSize::A4->value)
            ->setOption('margin-top', ($setting?->margin_top ?? 2.0) . 'cm')
            ->setOption('margin-right', ($setting?->margin_right ?? 1.5) . 'cm')
            ->setOption('margin-bottom', ($setting?->margin_bottom ?? 2.0) . 'cm')
            ->setOption('margin-left', ($setting?->margin_left ?? 2.5) . 'cm')
            ->setOption('encoding', 'UTF-8')
            ->inline($filename);
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
            $setting = ReportSetting::where('entity_id', $entity?->id)->where('active', true)->first();
            $tData   = $doc->tonometryData();
            $patient = $doc->patient;
            $doctor  = $doc->doctor;
            $od      = $tData['od'];
            $oe      = $tData['oe'];
            $time    = $tData['time'] ?? now()->format('H:i');

            return SnappyPdf::loadView('pdf.tonometry', compact('patient', 'doctor', 'entity', 'setting', 'od', 'oe', 'time'))
                ->setPaper('A5', 'portrait')
                ->setOption('margin-top', '1.5cm')
                ->setOption('margin-right', '1.5cm')
                ->setOption('margin-bottom', '1.5cm')
                ->setOption('margin-left', '1.5cm')
                ->setOption('encoding', 'UTF-8')
                ->inline($filename);
        }

        $setting = $doc->reportSetting;

        return SnappyPdf::loadView('pdf.documentation', compact('doc', 'setting'))
            ->setPaper($setting?->paper_size?->value ?? PaperSize::A4->value)
            ->setOption('margin-top', ($setting?->margin_top ?? 2.0) . 'cm')
            ->setOption('margin-right', ($setting?->margin_right ?? 1.5) . 'cm')
            ->setOption('margin-bottom', ($setting?->margin_bottom ?? 2.0) . 'cm')
            ->setOption('margin-left', ($setting?->margin_left ?? 2.5) . 'cm')
            ->setOption('encoding', 'UTF-8')
            ->inline($filename);
    }
}
