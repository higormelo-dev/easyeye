<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $locales = SetLocale::getSupportedLocales();
        $currentLocale = app()->getLocale();

        return [
            ...parent::share($request),
            'locale'   => $currentLocale,
            'locales'  => collect($locales)->map(fn ($l, $code) => [
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
        ];
    }
}
