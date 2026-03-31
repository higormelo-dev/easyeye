<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'inertia';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            // Dados do usuário autenticado (disponível em todas as páginas React)
            'auth' => fn () => $request->user() ? [
                'user' => $request->user()->only('id', 'name', 'email'),
            ] : null,

            // Entidade selecionada (clínica ativa)
            'entity' => fn () => session('entity')
                ? session('entity')->only('id', 'name', 'code')
                : null,

            // Flash messages do Laravel (success, error, warning)
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
            ],

            // Locale atual para i18n no React
            'locale' => fn () => app()->getLocale(),

            // Nome da aplicação
            'appName' => config('app.name', 'EasyEye'),

            // Flag: true = clínica cliente; false = painel SaaS manager
            'isClient' => fn () => (bool) session('selected_entity_is_client'),

            // Papel do usuário na entidade atual (admin, doctor, secretary, etc.)
            'userRule' => fn () => session('selected_entity_user_rule'),
        ];
    }
}
