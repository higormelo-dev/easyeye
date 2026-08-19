<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Models\Plan;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Log;
use Inertia\{Inertia, Response};

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
                'slug'               => $plan->slug,
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

        $currentUrl    = url('/');
        $currentLocale = app()->getLocale();

        // Alternate locales para hreflang
        $alternateLocales = [];

        foreach (SetLocale::SUPPORTED_LOCALES as $code => $meta) {
            $alternateLocales[] = [
                'code'    => str_replace('_', '-', $code),
                'url'     => $currentUrl . '?lang=' . $code,
                'default' => $code === config('app.locale', 'pt_BR'),
            ];
        }

        // JSON-LD: FAQPage
        $faqItems  = trans('site.faq.items');
        $faqJsonLd = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => collect($faqItems)->map(fn ($item) => [
                '@type'          => 'Question',
                'name'           => $item['q'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $item['a'],
                ],
            ])->toArray(),
        ];

        // JSON-LD: Organization + SoftwareApplication
        $orgJsonLd = [
            '@context'     => 'https://schema.org',
            '@type'        => 'Organization',
            'name'         => config('app.name', 'EasyEye'),
            'url'          => $currentUrl,
            'logo'         => asset('images/logo.svg'),
            'description'  => trans('site.meta.description'),
            'contactPoint' => [
                '@type'             => 'ContactPoint',
                'contactType'       => 'sales',
                'availableLanguage' => ['Portuguese', 'English'],
            ],
        ];

        $softwareJsonLd = [
            '@context'            => 'https://schema.org',
            '@type'               => 'SoftwareApplication',
            'name'                => config('app.name', 'EasyEye'),
            'applicationCategory' => 'HealthApplication',
            'operatingSystem'     => 'Web',
            'description'         => trans('site.meta.description'),
            'url'                 => $currentUrl,
            'offers'              => [
                '@type'         => 'Offer',
                'price'         => '0',
                'priceCurrency' => 'BRL',
                'description'   => trans('site.pricing.subtitle'),
            ],
        ];

        // Demonstração visual (tour do produto) — mesmo padrão do `howImageExists`
        // já existente: cada aba tem upgrade automático pra screenshot real assim
        // que o arquivo for colocado em public/site/images/, sem precisar mexer
        // no front. Valor = filemtime (truthy) usado como cache-buster `?v=` no
        // front: screenshot recapturado com o mesmo nome fura o cache do browser.
        $demoTabs   = ['prontuario', 'agenda', 'imagens', 'laudos'];
        $demoImages = collect($demoTabs)
            ->mapWithKeys(function (string $tab) {
                $path = public_path("site/images/demo-{$tab}.png");

                return [$tab => file_exists($path) ? filemtime($path) : false];
            })
            ->toArray();

        $howImagePath = public_path('site/images/how-it-works.png');

        return Inertia::render('Site/Home', [
            'plans'          => $plans,
            'appName'        => config('app.name', 'EasyEye'),
            'howImageExists' => file_exists($howImagePath) ? filemtime($howImagePath) : false,
            'demoImages'     => $demoImages,
            't'              => array_merge(trans('site'), ['pricing_credit_note_html' => $creditNote]),
            'routes'         => [
                'siteHome'     => route('site.home'),
                'register'     => route('register'),
                'go'           => route('go'),
                'contactStore' => route('contact.store'),
            ],
            'seo' => [
                'canonicalUrl'     => $currentUrl,
                'currentLocale'    => str_replace('_', '-', $currentLocale),
                'alternateLocales' => $alternateLocales,
                'ogImage'          => asset('images/og-preview.jpg'),
                'jsonLd'           => [
                    $faqJsonLd,
                    $orgJsonLd,
                    $softwareJsonLd,
                ],
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
