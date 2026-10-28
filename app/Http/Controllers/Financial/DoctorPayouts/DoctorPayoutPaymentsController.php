<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial\DoctorPayouts;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\DoctorPayouts\Concerns\AuthorizesDoctorPayouts;
use App\Http\Requests\Financial\{DoctorPayoutReasonRequest, PayDoctorPayoutRequest};
use App\Models\{DoctorPayout, DoctorPayoutPayment};
use App\Services\Financial\DoctorPayouts\DoctorPayoutClosingService;
use App\Support\Money;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Number;

/**
 * Pagamentos de um fechamento de repasse: registrar um pagamento (parcial ou
 * o saldo — gera a despesa no Fluxo de Caixa) e estornar um pagamento (admin
 * ou financeiro, com motivo — remove a despesa dele).
 */
class DoctorPayoutPaymentsController extends Controller
{
    use AuthorizesDoctorPayouts;

    public function __construct(
        private readonly DoctorPayoutClosingService $closing,
    ) {
    }

    public function store(PayDoctorPayoutRequest $request, DoctorPayout $payout): RedirectResponse|JsonResponse
    {
        $entity = $this->authorizeFinancial();
        $this->assertBelongsToEntity($payout, $entity);

        $payout = $this->closing->pay($payout, $request->validated(), auth()->id());

        $message = $payout->isPaid()
            ? __('financial_doctor_payouts.flash.paid')
            : __('financial_doctor_payouts.flash.payment_partial', [
                'remaining' => Number::currency(
                    (Money::toCents($payout->total_amount) - Money::toCents($payout->paid_amount)) / 100,
                    'BRL',
                    app()->getLocale(),
                ),
            ]);

        return $this->respond($request, $message);
    }

    public function destroy(DoctorPayoutReasonRequest $request, DoctorPayout $payout, DoctorPayoutPayment $payment): RedirectResponse|JsonResponse
    {
        $entity = $this->authorizeFinancial();
        $this->assertBelongsToEntity($payout, $entity);

        // Decisão de 2026-09-29: admin OU financeiro estorna (o grupo de rotas já
        // exige o papel financeiro); o serviço confere que o pagamento é deste
        // fechamento e desta clínica.
        $this->closing->reversePayment($payout, $payment, (string) $request->validated('reason'), auth()->id());

        return $this->respond($request, __('financial_doctor_payouts.flash.payment_reversed'));
    }

    private function respond(Request $request, string $message): RedirectResponse|JsonResponse
    {
        return $request->wantsJson()
            ? response()->json(['message' => $message])
            : back()->with('message', $message);
    }
}
