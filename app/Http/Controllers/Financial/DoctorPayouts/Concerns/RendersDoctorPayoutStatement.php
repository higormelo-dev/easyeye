<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial\DoctorPayouts\Concerns;

use App\Models\{DoctorPayout, Entity};
use App\Services\Financial\DoctorPayouts\{DoctorPayoutExporter, DoctorPayoutPresenter};
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Http\{RedirectResponse, Request};
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * PDF do demonstrativo de repasse — o mesmo documento para a clínica
 * (Financeiro) e para o médico (Meus repasses). Falha do wkhtmltopdf vira
 * mensagem na tela (report + volta), como nos relatórios financeiros; o
 * download é auditado.
 */
trait RendersDoctorPayoutStatement
{
    private function statementPdf(
        Request $request,
        Entity $entity,
        DoctorPayout $payout,
        DoctorPayoutPresenter $presenter,
        DoctorPayoutExporter $exporter,
        bool $forDoctor = false,
    ): SymfonyResponse|RedirectResponse {
        try {
            $response = SnappyPdf::loadView('pdf.doctor_payout_statement', [
                'entity' => $entity,
                // PDF do médico: o mesmo recorte da tela dele (sem estornos,
                // observações de pagamento nem nomes da equipe).
                'statement'   => $presenter->statement($payout, $forDoctor),
                'presenter'   => $presenter,
                'locale'      => app()->getLocale(),
                'generatedAt' => now(),
            ])
                ->setPaper('a4')
                ->setOrientation('portrait')
                ->setOption('footer-right', __('financial_doctor_payouts.pdf_page'))
                ->setOption('footer-font-size', 8)
                ->download(sprintf('%s_%s.pdf', __('financial_doctor_payouts.export_filename'), $payout->code));
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', __('financial_doctor_payouts.errors.pdf_failed'));
        }

        $exporter->audit($request, (string) $entity->id, 'doctor_payout_statement', 'pdf', [
            'payout_id' => $payout->id,
            'code'      => $payout->code,
        ], (int) $payout->items_count);

        return $response;
    }
}
