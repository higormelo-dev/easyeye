<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial\DoctorPayouts;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\DoctorPayouts\Concerns\AuthorizesDoctorPayouts;
use App\Http\Requests\Financial\{AllocateDoctorPayoutReceiptRequest, DoctorPayoutReasonRequest};
use App\Models\DoctorPayoutReceiptAllocation;
use App\Services\Financial\DoctorPayouts\DoctorPayoutReceiptAllocationService;
use Illuminate\Http\RedirectResponse;

/**
 * Recebimento manual do repasse (E3): alocar parte de uma receita avulsa do
 * caixa a atos e estornar alocações (admin ou financeiro, com motivo).
 */
class DoctorPayoutAllocationsController extends Controller
{
    use AuthorizesDoctorPayouts;

    public function __construct(
        private readonly DoctorPayoutReceiptAllocationService $allocations,
    ) {
    }

    public function store(AllocateDoctorPayoutReceiptRequest $request): RedirectResponse
    {
        $entity = $this->authorizeFinancial();

        $created = $this->allocations->allocate(
            (string) $entity->id,
            (string) $request->validated('cash_entry_id'),
            $request->items(),
            $request->validated('notes'),
        );

        return back()->with('message', trans_choice('financial_doctor_payouts.flash.allocation_created', $created->count(), ['count' => $created->count()]));
    }

    public function destroy(DoctorPayoutReasonRequest $request, DoctorPayoutReceiptAllocation $allocation): RedirectResponse
    {
        $this->authorizeFinancial();

        $this->allocations->reverse($allocation, (string) $request->validated('reason'), $request->user()?->getAuthIdentifier());

        return back()->with('message', __('financial_doctor_payouts.flash.allocation_reversed'));
    }
}
