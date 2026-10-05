<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\{Entity, PatientCall};
use App\Services\Billing\ClinicServiceGate;
use Illuminate\Http\JsonResponse;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Painel/TV público da sala de espera: exibe as chamadas de paciente da
 * clínica ("Paciente João — consultório da Dra. Ana") com aviso por voz.
 *
 * Acesso por token aleatório da clínica (não enumerável); a página só
 * recebe snapshots de nome congelados na hora da chamada — nenhum dado
 * de cadastro/prontuário passa por aqui. 404 genérico pra token inválido
 * ou recurso desativado (anti-enumeração).
 *
 * Clínica com o acesso bloqueado (ClinicServiceGate): "serviço
 * indisponível", sem nenhuma chamada no feed. A TV segue consultando e volta
 * sozinha quando o acesso volta.
 */
class CallPanelDisplayController extends Controller
{
    public function __construct(
        private readonly ClinicServiceGate $gate,
    ) {
    }

    public function show(string $token): InertiaResponse
    {
        $entity = $this->resolveEntity($token);

        return Inertia::render('CallPanel/Display', [
            'clinic'      => $entity->name,
            'feed_url'    => route('call-panel.feed', $token),
            'unavailable' => $this->gate->isBlocked($entity),
            'texts'       => trans('call_panel'),
        ]);
    }

    public function feed(string $token): JsonResponse
    {
        $entity = $this->resolveEntity($token);

        if ($this->gate->isBlocked($entity)) {
            return response()->json(['data' => [], 'unavailable' => true]);
        }

        $calls = PatientCall::query()
            ->where('entity_id', $entity->id)
            ->where('created_at', '>=', now()->subHours(4))
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (PatientCall $call) => [
                'id'        => (string) $call->id,
                'patient'   => $call->patient_name,
                'doctor'    => $call->doctor_name,
                'called_at' => $call->created_at->format('H:i'),
            ]);

        return response()->json(['data' => $calls, 'unavailable' => false]);
    }

    private function resolveEntity(string $token): Entity
    {
        $entity = Entity::query()
            ->where('call_panel_token', $token)
            ->where('call_panel_enabled', true)
            ->first();

        abort_unless((bool) $entity, 404);

        return $entity;
    }
}
