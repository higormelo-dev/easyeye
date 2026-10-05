<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Http\Middleware\SetLocale;
use App\Http\Requests\SiteContactRequest;
use App\Mail\SiteContactMessage;
use App\Models\{Plan, SubscriptionSetting};
use App\Support\Billing\PlanPricing;
use App\Support\Site\{SiteContent, SiteLinks};
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\{Log, Mail};
use Inertia\{Inertia, Response};
use RuntimeException;
use Throwable;

class SiteController extends Controller
{
    public function index(): Response
    {
        $trialDays = SubscriptionSetting::trialDays();
        $plans     = Plan::active()
            ->with(['features' => fn ($q) => $q->orderBy('feature'), 'prices'])
            ->orderBy('sort_order')
            ->get()
            // Sem ciclo à venda (ex.: plano antigo só vitalício) não há o que contratar.
            ->filter(fn (Plan $plan) => $plan->isSellable())
            ->values()
            ->map(fn (Plan $plan) => [
                'id'                 => $plan->id,
                'slug'               => $plan->slug,
                'name'               => $plan->name,
                'description'        => $plan->description,
                'price'              => $plan->price,
                'price_period_label' => $plan->pricePeriodLabel(),
                // Ciclos que o cliente pode escolher (mensal, anual...).
                'default_cycle' => $plan->defaultCycle()?->value,
                'prices'        => PlanPricing::cycles($plan),
                'trial_days'    => $trialDays,
                'register_url'  => route('register', ['plan' => $plan->id]),
                'is_featured'   => (bool) $plan->is_featured,
                'is_free'       => (float) $plan->price === 0.0,
                'features'      => $plan->features->map(fn ($f) => [
                    'id' => $f->id,
                    // Chave estável para o comparador e a disponibilidade por plano.
                    'key'           => $f->feature->value,
                    'value'         => $f->feature->isBoolean() ? $f->boolValue() : $f->intValue(),
                    'display_label' => $f->formatForDisplay(),
                    'enabled'       => $f->feature->isBoolean() ? $f->boolValue() : true,
                    // 0 créditos de IA = ausência (não "ilimitado", como nos limites).
                    'is_none' => $f->feature === FeatureKey::AiMonthlyCredits && $f->intValue() === 0,
                ])->toArray(),
            ]);

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
                'email'             => config('mail.contact_address'),
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
                // Recortes em WebP sem dados de teste (os PNG originais ficam como matriz).
                $path = public_path("site/images/demo-{$tab}.webp");

                return [$tab => file_exists($path) ? filemtime($path) : false];
            })
            ->toArray();

        $howImagePath = public_path('site/images/how-it-works.webp');
        // O prontuário demonstra a tarefa clínica destacada no hero.
        // Sem o arquivo, o hero fica só com o texto (nunca com imagem quebrada).
        $heroImagePath = public_path('site/images/hero-prontuario.webp');

        return Inertia::render('Site/Home', [
            'plans'           => $plans,
            'trialDays'       => $trialDays,
            'appName'         => config('app.name', 'EasyEye'),
            'heroImage'       => file_exists($heroImagePath) ? filemtime($heroImagePath) : false,
            'heroImageUrl'    => asset('site/images/hero-prontuario.webp'),
            'heroImageWidth'  => 1061,
            'heroImageHeight' => 857,
            'howImageExists'  => file_exists($howImagePath) ? filemtime($howImagePath) : false,
            'demoImages'      => $demoImages,
            't'               => array_merge(SiteContent::translations(), ['pricing_credit_note' => trans('subscriptions.pricing_credit_note')]),
            'routes'          => [...SiteLinks::routes(), 'contactStore' => route('contact.store')],
            'contact'         => SiteLinks::contact(),
            'seo'             => [
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

    public function contactStore(SiteContactRequest $request): JsonResponse
    {
        try {
            // Explicit SMTP avoids the default log mailer or a failover to logs:
            // neither delivers the message, and both can expose personal data.
            $sent = Mail::mailer('smtp')
                ->to(config('mail.contact_address'))
                ->send(new SiteContactMessage($request->validated()));

            if ($sent === null) {
                throw new RuntimeException('Contact delivery was cancelled.');
            }
        } catch (Throwable $exception) {
            // Do not log the payload, SMTP response, recipient or credentials.
            Log::error('contact_form_delivery_failed', ['exception_type' => $exception::class]);

            return response()->json([
                'ok'      => false,
                'message' => __('site.contact.form.errors.server'),
            ], 503);
        }

        return response()->json(['ok' => true]);
    }
}
