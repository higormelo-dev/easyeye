<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Models\Entity;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\Auth;

class LocaleController extends Controller
{
    /**
     * Trocar o idioma do sistema e salvar na preferência do usuário
     */
    public function switch(Request $request, string $locale): RedirectResponse|JsonResponse
    {
        // Verifica se o locale é suportado
        if (! array_key_exists($locale, SetLocale::SUPPORTED_LOCALES)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => __('validation.custom.locale.unsupported'),
                ], 400);
            }

            return back()->with('error', __('validation.custom.locale.unsupported'));
        }

        // Salva o locale na sessão (efeito imediato)
        session(['locale' => $locale]);

        // Se usuário está autenticado, salva a preferência no banco
        if (Auth::check()) {
            Auth::user()->update(['locale' => $locale]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('actions.language_changed'),
                'locale'  => $locale,
            ]);
        }

        return back()->with('message', __('actions.language_changed'));
    }

    /**
     * Definir o idioma padrão da empresa (apenas para administradores)
     */
    public function setEntityLocale(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate([
            'locale' => 'required|string|in:' . implode(',', array_keys(SetLocale::SUPPORTED_LOCALES)),
        ]);

        $entityId = session('selected_entity_id');

        if (! $entityId) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => __('validation.custom.entity.not_selected'),
                ], 400);
            }

            return back()->with('error', __('validation.custom.entity.not_selected'));
        }

        $entity = Entity::find($entityId);

        if (! $entity) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => __('http-statuses.404'),
                ], 404);
            }

            return back()->with('error', __('http-statuses.404'));
        }

        $entity->update(['locale' => $request->locale]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('actions.entity_language_changed'),
                'locale'  => $request->locale,
            ]);
        }

        return back()->with('message', __('actions.entity_language_changed'));
    }

    /**
     * Limpar a preferência de idioma do usuário (usar o da empresa)
     */
    public function clearUserLocale(Request $request): RedirectResponse|JsonResponse
    {
        if (Auth::check()) {
            Auth::user()->update(['locale' => null]);
            session()->forget('locale');
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('actions.language_reset'),
            ]);
        }

        return back()->with('message', __('actions.language_reset'));
    }

    /**
     * Retorna os idiomas suportados e as configurações atuais
     */
    public function index(): JsonResponse
    {
        $data = [
            'current'         => app()->getLocale(),
            'locales'         => SetLocale::getSupportedLocales(),
            'user_locale'     => null,
            'entity_locale'   => null,
        ];

        if (Auth::check()) {
            $data['user_locale'] = Auth::user()->locale;

            $entityId = session('selected_entity_id');
            if ($entityId) {
                $entity = Entity::find($entityId);
                $data['entity_locale'] = $entity?->locale;
            }
        }

        return response()->json($data);
    }
}

