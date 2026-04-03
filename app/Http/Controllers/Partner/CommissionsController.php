<?php

declare(strict_types=1);

namespace App\Http\Controllers\Partner;

use App\Enums\CommissionStatus;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use Illuminate\Contracts\View\View;

class CommissionsController extends Controller
{
    public function index(): View
    {
        $partner = Partner::findOrFail(session('portal_partner_id'));

        $commissions = $partner->commissions()
            ->with('entity')
            ->orderByDesc('created_at')
            ->paginate(20);

        $pendingTotal = (float) $partner->commissions()->where('status', CommissionStatus::Pending)->sum('amount');
        $paidTotal    = (float) $partner->commissions()->where('status', CommissionStatus::Paid)->sum('amount');

        return view('portal.commissions.index', compact('partner', 'commissions', 'pendingTotal', 'paidTotal'));
    }
}
