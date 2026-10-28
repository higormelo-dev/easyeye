<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Partner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsPartner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        $partner = Partner::where('user_id', Auth::id())
            ->where('status', 'active')
            ->first();

        if (! $partner) {
            abort(403, 'Acesso restrito ao portal de parceiros.');
        }

        session(['portal_partner_id' => $partner->id]);

        return $next($request);
    }
}
