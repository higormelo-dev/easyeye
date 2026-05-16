<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SiteController extends Controller
{
    public function index()
    {
        $plans = Plan::active()
            ->with(['features' => fn ($q) => $q->orderBy('feature')])
            ->orderBy('sort_order')
            ->get();

        return view('site.index', compact('plans'));
    }

    public function contactStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'email'     => ['required', 'email', 'max:191'],
            'phone'     => ['required', 'string', 'max:30'],
            'is_client' => ['nullable', 'string', 'max:60'],
            'role'      => ['nullable', 'string', 'max:80'],
            'segment'   => ['nullable', 'string', 'max:80'],
            'terms'     => ['accepted'],
        ]);

        Log::channel('stack')->info('contact_form_submission', [
            'name'      => $data['name'],
            'email'     => $data['email'],
            'phone'     => $data['phone'],
            'is_client' => $data['is_client'] ?? null,
            'role'      => $data['role'] ?? null,
            'segment'   => $data['segment'] ?? null,
            'ip'        => $request->ip(),
        ]);

        return response()->json(['ok' => true]);
    }
}
