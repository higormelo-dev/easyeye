<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Str;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Configuração do Painel de Chamadas (TV da sala de espera) — opcional por
 * clínica. Rotas dentro do grupo `permission:settings.manage` (admin da
 * clínica; permissão customizada também libera).
 *
 * Ao ativar pela primeira vez gera o token público da TV — a URL é aleatória
 * e não enumerável; regenerar o token invalida a URL antiga (caso vaze).
 */
class CallPanelController extends Controller
{
    public function index(): InertiaResponse
    {
        $entity = Entity::findOrFail(session('selected_entity_id'));

        return Inertia::render('Panel/Settings/CallPanel/Index', [
            'enabled'   => (bool) $entity->call_panel_enabled,
            'panel_url' => $entity->call_panel_token
                ? route('call-panel.show', $entity->call_panel_token)
                : null,
            't' => trans('schedules'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled'          => ['required', 'boolean'],
            'regenerate_token' => ['sometimes', 'boolean'],
        ]);

        $entity = Entity::findOrFail(session('selected_entity_id'));

        $entity->call_panel_enabled = $data['enabled'];

        if ($data['enabled'] && (! $entity->call_panel_token || ! empty($data['regenerate_token']))) {
            $entity->call_panel_token = Str::random(48);
        }

        $entity->save();

        return response()->json([
            'message'   => __('schedules.call_panel_saved'),
            'enabled'   => (bool) $entity->call_panel_enabled,
            'panel_url' => $entity->call_panel_token
                ? route('call-panel.show', $entity->call_panel_token)
                : null,
        ]);
    }
}
