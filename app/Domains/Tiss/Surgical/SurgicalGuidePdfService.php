<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Surgical;

use App\Domains\Tiss\DTOs\SurgicalGuideData;
use App\Domains\Tiss\Models\TissGuide;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Http\Response;

/**
 * PDF imprimível da solicitação de internação e do anexo de OPME — o formulário que a
 * secretária digita/anexa no portal da operadora quando o pedido não vai por webservice.
 * Montado SÓ do retrato gravado na guia (`SurgicalGuideData`), nunca do caso vivo: o
 * papel bate com o XML gerado.
 */
class SurgicalGuidePdfService
{
    public function render(TissGuide $guide): Response
    {
        $guide->loadMissing(['operator', 'entity', 'items', 'xmlDocument']);

        $data = SurgicalGuideData::fromGuide($guide);

        $filename = preg_replace('/[^A-Za-z0-9\-_.]/', '_', 'tiss-' . $guide->guide_type->value . '-' . $guide->guide_number_provider . '.pdf');

        return SnappyPdf::loadView('pdf.tiss_surgical_guide', [
            'guide'        => $guide,
            'data'         => $data,
            'operatorName' => $guide->operator?->trade_name ?: $guide->operator?->name,
            'beneficiary'  => $guide->beneficiary_name,
            'xmlHash'      => $guide->xmlDocument?->file_hash,
        ])
            ->setPaper('a4')
            ->setOption('margin-top', '1.6cm')
            ->setOption('margin-right', '1.6cm')
            ->setOption('margin-bottom', '1.8cm')
            ->setOption('margin-left', '1.8cm')
            ->setOption('footer-font-size', '7')
            ->setOption('footer-center', $guide->guide_number_provider . ' · ' . __('pdf.page_footer_native'))
            ->setOption('encoding', 'UTF-8')
            ->inline($filename);
    }
}
