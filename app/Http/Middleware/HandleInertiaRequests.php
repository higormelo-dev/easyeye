<?php

namespace App\Http\Middleware;

use App\Models\Entity;
use App\Support\PanelNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Storage, Vite};
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function rootView(Request $request): string
    {
        if ($request->routeIs('panel.*') || $request->routeIs('manager.*')) {
            return 'panel-app';
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
            ],

            'auth' => fn () => $this->authProps($request),

            'nav' => fn () => ($request->routeIs('panel.*') || $request->routeIs('manager.*')) && $request->user()
                ? PanelNavigation::build()
                : [],
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
}
