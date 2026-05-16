<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class SiteController extends Controller
{
    public function index(): Response
    {
        $plans = Plan::active()
            ->with(['features' => fn ($q) => $q->orderBy('feature')])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $plan) => [
                'id'                 => $plan->id,
                'name'               => $plan->name,
                'description'        => $plan->description,
                'price'              => $plan->price,
                'price_period_label' => $plan->pricePeriodLabel(),
                'trial_days'         => $plan->trial_days,
                'is_featured'        => (bool) $plan->is_featured,
                'is_free'            => (float) $plan->price === 0.0,
                'features'           => $plan->features->map(fn ($f) => [
                    'id'            => $f->id,
                    'display_label' => $f->formatForDisplay(),
                    'enabled'       => $f->feature->isBoolean() ? $f->boolValue() : true,
                ])->toArray(),
            ]);

        $creditNote = __('subscriptions.pricing_credit_note.title') . ' '
            . __('subscriptions.pricing_credit_note.body') . ' '
            . __('subscriptions.pricing_credit_note.topup');

        return Inertia::render('Site/Home', [
            'plans'          => $plans,
            'appName'        => config('app.name', 'EasyEye'),
            'howImageExists' => file_exists(public_path('site/images/how-it-works.png')),
            't'              => array_merge(trans('site'), ['pricing_credit_note_html' => $creditNote]),
            'routes'         => [
                'siteHome'     => route('site.home'),
                'register'     => route('register'),
                'go'           => route('go'),
                'contactStore' => route('contact.store'),
            ],
        ]);
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
