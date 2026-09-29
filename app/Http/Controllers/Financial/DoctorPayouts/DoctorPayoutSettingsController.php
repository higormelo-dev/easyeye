<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial\DoctorPayouts;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\DoctorPayouts\Concerns\AuthorizesDoctorPayouts;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};

/**
 * Política da clínica: médicos veem os próprios repasses ("Meus repasses")?
 * Só admin decide (expõe valores ao médico); a mudança fica no audit_logs
 * (Entity é Auditable).
 */
class DoctorPayoutSettingsController extends Controller
{
    use AuthorizesDoctorPayouts;

    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $entity = $this->authorizeFinancial();
        abort_unless($this->isEntityAdmin($entity), 403, __('financial_doctor_payouts.admin_only'));

        $validated = $request->validate([
            'doctor_payouts_visible' => ['required', 'boolean'],
        ]);

        $visible = filter_var($validated['doctor_payouts_visible'], FILTER_VALIDATE_BOOLEAN);

        if ((bool) $entity->doctor_payouts_visible !== $visible) {
            $entity->doctor_payouts_visible = $visible;
            $entity->save();
        }

        $message = __('financial_doctor_payouts.flash.settings_updated');

        return $request->wantsJson()
            ? response()->json(['message' => $message, 'data' => ['doctor_payouts_visible' => $visible]])
            : back()->with('message', $message);
    }
}
