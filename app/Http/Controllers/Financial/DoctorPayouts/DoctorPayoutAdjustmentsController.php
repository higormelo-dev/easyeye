<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial\DoctorPayouts;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\DoctorPayouts\Concerns\AuthorizesDoctorPayouts;
use App\Http\Requests\Financial\DoctorPayoutAdjustmentRequest;
use App\Models\{DoctorPayout, DoctorPayoutAdjustment};
use App\Services\Financial\DoctorPayouts\DoctorPayoutClosingService;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};

/**
 * Ajustes manuais (acréscimo/desconto) de um fechamento ainda não pago.
 */
class DoctorPayoutAdjustmentsController extends Controller
{
    use AuthorizesDoctorPayouts;

    public function __construct(
        private readonly DoctorPayoutClosingService $closing,
    ) {
    }

    public function store(DoctorPayoutAdjustmentRequest $request, DoctorPayout $payout): RedirectResponse|JsonResponse
    {
        $entity = $this->authorizeFinancial();
        $this->assertBelongsToEntity($payout, $entity);

        $this->closing->addAdjustment($payout, (string) $request->validated('description'), $request->signedCents());

        return $this->respond($request, __('financial_doctor_payouts.flash.adjustment_added'));
    }

    public function destroy(Request $request, DoctorPayout $payout, string $adjustment): RedirectResponse|JsonResponse
    {
        $entity = $this->authorizeFinancial();
        $this->assertBelongsToEntity($payout, $entity);

        $model = DoctorPayoutAdjustment::query()
            ->where('entity_id', $entity->id)
            ->where('doctor_payout_id', $payout->id)
            ->whereKey($adjustment)
            ->firstOrFail();

        $this->closing->removeAdjustment($payout, $model);

        return $this->respond($request, __('financial_doctor_payouts.flash.adjustment_removed'));
    }

    private function respond(Request $request, string $message): RedirectResponse|JsonResponse
    {
        return $request->wantsJson()
            ? response()->json(['message' => $message])
            : back()->with('message', $message);
    }
}
