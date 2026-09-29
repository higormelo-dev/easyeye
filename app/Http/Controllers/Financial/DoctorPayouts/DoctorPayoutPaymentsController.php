<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial\DoctorPayouts;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\DoctorPayouts\Concerns\AuthorizesDoctorPayouts;
use App\Http\Requests\Financial\{DoctorPayoutReasonRequest, PayDoctorPayoutRequest};
use App\Models\DoctorPayout;
use App\Services\Financial\DoctorPayouts\DoctorPayoutClosingService;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};

/**
 * Pagamento de um fechamento de repasse: registrar (gera a despesa no Fluxo
 * de Caixa) e estornar (admin, com motivo — remove a despesa).
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

        $this->closing->pay($payout, $request->validated(), auth()->id());

        return $this->respond($request, __('financial_doctor_payouts.flash.paid'));
    }

    public function destroy(DoctorPayoutReasonRequest $request, DoctorPayout $payout): RedirectResponse|JsonResponse
    {
        $entity = $this->authorizeFinancial();
        $this->assertBelongsToEntity($payout, $entity);
        abort_unless($this->isEntityAdmin($entity), 403, __('financial_doctor_payouts.admin_only'));

        $this->closing->reversePayment($payout, (string) $request->validated('reason'), auth()->id());

        return $this->respond($request, __('financial_doctor_payouts.flash.payment_reversed'));
    }

    private function respond(Request $request, string $message): RedirectResponse|JsonResponse
    {
        return $request->wantsJson()
            ? response()->json(['message' => $message])
            : back()->with('message', $message);
    }
}
