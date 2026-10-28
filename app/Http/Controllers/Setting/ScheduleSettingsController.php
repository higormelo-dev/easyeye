<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use Illuminate\Http\{JsonResponse, Request};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Configurações › Agenda — percentual de "poucas vagas" do calendário lateral
 * da Agenda (dia com N% ou menos dos horários livres; padrão 20%). Rotas no
 * grupo `permission:settings.manage` (admin da clínica).
 */
class ScheduleSettingsController extends Controller
{
    public function index(): InertiaResponse
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));

        return Inertia::render('Panel/Settings/Schedule/Index', [
            'fewSlotsPercent' => (int) ($entity->few_slots_percent ?? 20),
            't'               => trans('schedules'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'few_slots_percent' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        $entity->update(['few_slots_percent' => $data['few_slots_percent']]);

        return response()->json([
            'message'           => __('schedules.settings_saved'),
            'few_slots_percent' => (int) $entity->few_slots_percent,
        ]);
    }
}
