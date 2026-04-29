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
            $setting = ReportSetting::where('entity_id', $entity?->id)->where('active', true)->first();
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
