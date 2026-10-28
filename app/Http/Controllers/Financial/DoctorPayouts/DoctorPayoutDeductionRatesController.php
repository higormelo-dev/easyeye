<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial\DoctorPayouts;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\DoctorPayouts\Concerns\AuthorizesDoctorPayouts;
use App\Http\Requests\Financial\DoctorPayoutDeductionRateRequest;
use App\Models\DoctorPayoutDeductionRate;
use App\Services\Financial\DoctorPayouts\DoctorPayoutDeductionService;
use Illuminate\Http\RedirectResponse;

/**
 * Taxas de dedução do repasse (E4): cartão débito/crédito, imposto e taxa
 * administrativa, com vigência pela data do recebimento. Editar = excluir a
 * vigência errada e criar outra (o histórico de fechamentos guarda o retrato).
 */
class DoctorPayoutDeductionRatesController extends Controller
{
    use AuthorizesDoctorPayouts;

    public function __construct(
        private readonly DoctorPayoutDeductionService $deductions,
    ) {
    }

    public function store(DoctorPayoutDeductionRateRequest $request): RedirectResponse
    {
        $entity = $this->authorizeFinancial();

        $this->deductions->create((string) $entity->id, $request->validated());

        return back()->with('message', __('financial_doctor_payouts.flash.deduction_rate_created'));
    }

    public function destroy(DoctorPayoutDeductionRate $rate): RedirectResponse
    {
        $this->authorizeFinancial();

        $this->deductions->delete($rate);

        return back()->with('message', __('financial_doctor_payouts.flash.deduction_rate_deleted'));
    }
}
