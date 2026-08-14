<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserPreference;
use Illuminate\Http\{JsonResponse, Request};

/**
 * Preferências pessoais do usuário (item MELHORIA "mais humano" — ver
 * UserPreference). Um único endpoint genérico de merge parcial em vez de
 * uma rota por preferência: cada widget de personalização (ordem do
 * Dashboard, atalhos favoritos, e futuramente toggle de notícias/playlist)
 * chama o mesmo PATCH com só a chave que mudou.
 */
class PreferencesController extends Controller
{
    /**
     * Allowlist de chaves aceitas no bag. Único ponto a tocar quando uma
     * nova preferência entrar (ex.: `news_enabled`, `playlist_url`) —
     * evita que o client grave uma chave/payload arbitrário.
     */
    private const ALLOWED_KEYS = [
        'dashboard_widget_order',
        'favorite_shortcuts',
    ];

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'dashboard_widget_order'      => ['sometimes', 'array'],
            'dashboard_widget_order.*'    => ['string'],
            'favorite_shortcuts'          => ['sometimes', 'array'],
            'favorite_shortcuts.*.key'    => ['required_with:favorite_shortcuts', 'string'],
            'favorite_shortcuts.*.hidden' => ['sometimes', 'boolean'],
        ]);

        // Request::only() já filtra pra só as chaves permitidas — qualquer
        // outra coisa no body é ignorada silenciosamente (não é erro do
        // client, só não faz parte do bag ainda).
        $partial = $request->only(self::ALLOWED_KEYS);

        if (empty($partial)) {
            return response()->json([
                'message' => 'Nenhuma preferência reconhecida enviada.',
            ], 422);
        }

        $pref = UserPreference::mergeFor($request->user(), $partial);

        return response()->json(['data' => $pref->data]);
    }
}
