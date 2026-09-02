<?php

namespace App\Http\Middleware;

use App\Domains\AI\Services\AiAssistantWidgetPropsBuilder;
use App\Models\{Entity, Partner};
use App\Support\PanelNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Storage, Vite};
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function rootView(Request $request): string
    {
        if ($request->routeIs('panel.*') || $request->routeIs('manager.*')) {
            return 'panel-app';
        }

        // Fluxos de identidade/autenticação (login, register, verify, 2FA setup)
        // usam o rootView guest-app, que carrega vendor.css + system.scss —
        // necessário para o GuestLayout renderizar com Bootstrap grid + estilos
        // ee-auth-*. O rootView default 'app' carrega só site.scss (marketing).
        if ($request->routeIs('security.*')) {
            return 'guest-app';
        }

        // Portal de Parceiros: layout próprio (gradient teal/blue + nav simples).
        // Compartilha vendor + system com painel, mas tem chrome distinto.
        if ($request->routeIs('portal.*')) {
            return 'portal-app';
        }

        return 'app';
    }

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $locales       = SetLocale::getSupportedLocales();
        $currentLocale = app()->getLocale();

        return [
            ...parent::share($request),

            'ziggy' => function () use ($request) {
                return array_merge((new Ziggy())->toArray(), [
                    'location' => $request->url(),
                ]);
            },

            'locale'  => $currentLocale,
            'locales' => collect($locales)->map(fn ($l, $code) => [
                'code'   => $code,
                'flag'   => $l['flag'] ?? '🌐',
                'native' => $l['native'],
                'url'    => route('locale.switch', $code),
                'active' => $code === $currentLocale,
            ])->values()->toArray(),

            'flash' => [
                'success' => session('success'),
                'error'   => session('error'),
                'status'  => session('status'),
                // Prontuário: ação da barra a abrir após o 1º save (one-shot).
                'post_save_action' => session('post_save_action'),
            ],

            'auth' => fn () => $this->authProps($request),

            'nav' => fn () => ($request->routeIs('panel.*') || $request->routeIs('manager.*')) && $request->user()
                ? PanelNavigation::build()
                : [],

            // Widget flutuante do Assistente Virtual de IA — disponível em
            // QUALQUER tela do painel (não manager.*: gestão SaaS não é
            // rotina clínica). Lazy (fn ()) + gate interno no builder
            // (isDoctor + feature) evita custo em toda request de quem não vê o widget.
            'aiAssistant' => fn () => $request->routeIs('panel.*') && $request->user() && session('selected_entity_id')
                ? app(AiAssistantWidgetPropsBuilder::class)->build(
                    (string) session('selected_entity_id'),
                    session('selected_entity_user_rule'),
                )
                : ['enabled' => false],

            // Portal de Parceiros: partner ativo da sessão (compartilhado pelo
            // PortalLayout para mostrar nome/email/code no header).
            'partner' => fn () => $request->routeIs('portal.*')
                ? $this->portalPartnerProps()
                : null,

            // Traduções do hardening (modal de confirmação com reason etc.)
            // Carregadas em todo request para que o ConfirmationWithReasonModal
            // funcione em qualquer página sem precisar passar t={} manualmente.
            't_hardening' => fn () => trans('manager_hardening'),
        ];
    }

    private function authProps(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return [];
        }

        $selectedEntityId = session('selected_entity_id');
        $entity           = Entity::with(['subscription.plan'])->find($selectedEntityId);
        $photoPath        = 'users/' . $user->id . '.jpg';

        $entityUsers = $user->entityUsers()
            ->with('entity')
            ->where('active', true)
            ->get();

        return [
            'user' => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'photo_url' => Storage::disk('public')->exists($photoPath)
                    ? Storage::disk('public')->url($photoPath)
                    : Vite::asset('resources/img/system/team.png'),
                // Item MELHORIA "mais humano" — bag de preferências pessoais
                // (ordem do Dashboard, atalhos favoritos...). Ver UserPreference.
                'preferences' => $user->preference?->data ?? [],
                // Verificação do WhatsApp do responsável (código OTP via
                // Z-API) — alimenta o banner de confirmação do AppLayout.
                // has_phone distingue "sem número cadastrado" (nada a
                // confirmar) de "pendente de confirmação".
                'has_phone'      => filled($user->phone),
                'phone_verified' => $user->phone_verified_at !== null,
            ],
            'entity' => [
                'id'       => $selectedEntityId,
                'name'     => $entity?->name ?? config('app.name'),
                'city'     => $entity?->city ?? '',
                'plan'     => $entity?->subscription?->plan?->name,
                'logo_url' => $entity?->logo
                    ? Storage::disk('public')->url($entity->logo)
                    : null,
                'is_client' => (bool) session('selected_entity_is_client'),
                'rule'      => session('selected_entity_user_rule'),
            ],
            'entities' => $entityUsers->map(fn ($eu) => [
                'entity_user_id' => $eu->id,
                'entity_id'      => $eu->entity_id,
                'name'           => $eu->entity->name,
                'city'           => $eu->entity->city ?? '',
                'is_selected'    => $eu->entity_id === $selectedEntityId,
            ])->values()->toArray(),
            'impersonating' => session()->has('impersonating')
                ? ['original_name' => session('impersonating.original_user_name')]
                : null,
        ];
    }

    /**
     * Dados do partner ativo no portal (vem da sessão setada pelo middleware
     * EnsureIsPartner). Carregamento lazy — só executa quando o request é
     * de uma rota portal.*.
     */
    private function portalPartnerProps(): ?array
    {
        $partnerId = session('portal_partner_id');

        if (! $partnerId) {
            return null;
        }

        $partner = Partner::find($partnerId);

        if (! $partner) {
            return null;
        }

        return [
            'id'    => (string) $partner->id,
            'code'  => $partner->code,
            'name'  => $partner->name,
            'email' => $partner->email,
        ];
    }
}
