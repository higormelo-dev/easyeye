<?php

namespace App\Http\Controllers;

use App\Enums\ScheduleSituation;
use App\Models\{Entity, Schedule, TvDisplay};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TvController extends Controller
{
    /**
     * Página inicial: formulário para digitar o código da empresa.
     */
    public function entry()
    {
        return view('tv.entry');
    }

    /**
     * Processa o código da empresa e cria uma solicitação de pareamento.
     */
    public function requestAccess(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $entity = Entity::where('code', strtoupper(trim($request->input('code'))))
            ->where('active', true)
            ->first();

        if (! $entity) {
            return response()->json(['message' => 'Código da empresa não encontrado.'], 422);
        }

        $tv = TvDisplay::create([
            'entity_id' => $entity->id,
            'name'      => 'Aguardando aprovação',
            'status'    => TvDisplay::STATUS_PENDING,
            'token'     => null,
            'pin'       => TvDisplay::generatePin(),
            'active'    => false,
        ]);

        return response()->json([
            'id'  => $tv->id,
            'pin' => $tv->pin,
        ]);
    }

    /**
     * Página de espera exibida na TV enquanto aguarda aprovação do admin.
     */
    public function wait(string $id)
    {
        abort_unless(Str::isUuid($id), 404);

        $tv = TvDisplay::where('id', $id)
            ->where('status', TvDisplay::STATUS_PENDING)
            ->firstOrFail();

        return view('tv.wait', compact('tv'));
    }

    /**
     * Endpoint de polling: retorna o status atual da solicitação.
     */
    public function pollStatus(string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['status' => 'not_found'], 404);
        }

        $tv = TvDisplay::find($id);

        if (! $tv) {
            return response()->json(['status' => 'not_found'], 404);
        }

        if ($tv->isActive() && $tv->token) {
            return response()->json([
                'status'   => 'active',
                'redirect' => route('tv.show', $tv->token),
            ]);
        }

        if ($tv->status === TvDisplay::STATUS_REVOKED || $tv->deleted_at) {
            return response()->json(['status' => 'rejected']);
        }

        return response()->json(['status' => 'pending']);
    }

    /**
     * Display principal da TV (fullscreen).
     */
    public function show(string $token)
    {
        $tv = TvDisplay::where('token', $token)
            ->where('status', TvDisplay::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $tv->update(['last_seen_at' => now()]);

        $data = $this->getWaitingList($tv->entity_id);

        return view('tv.show', [
            'tv'             => $tv,
            'active'         => $data['active'],
            'recentlyCalled' => $data['recently_called'],
        ]);
    }

    /**
     * Polling leve: retorna JSON do cache. Worker liberado em ~5ms.
     */
    public function poll(string $token): JsonResponse
    {
        $tv = TvDisplay::where('token', $token)
            ->where('status', TvDisplay::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->firstOrFail();

        return response()->json($this->getWaitingList($tv->entity_id));
    }

    private function getWaitingList(string $entityId): array
    {
        $cacheKey = "waiting_room:{$entityId}";
        $cached   = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $map = fn (Schedule $s) => [
            'id'          => $s->id,
            'name'        => $s->patient?->person?->full_name ?? $s->full_name ?? 'Paciente',
            'doctor_name' => $s->doctor?->person?->full_name ?? '-',
            'situation'   => $s->situation->value,
            'label'       => $s->situation->label(),
            'arrived_at'  => $s->arrived_at?->format('H:i'),
        ];

        $active = Schedule::query()
            ->with(['patient.person', 'doctor.person'])
            ->where('entity_id', $entityId)
            ->whereIn('situation', [
                ScheduleSituation::Waiting->value,
                ScheduleSituation::InProgress->value,
            ])
            ->whereDate('date_time', today())
            ->orderBy('arrived_at')
            ->get()
            ->map($map)
            ->values()
            ->all();

        $recentlyCalled = Schedule::query()
            ->with(['patient.person', 'doctor.person'])
            ->where('entity_id', $entityId)
            ->where('situation', ScheduleSituation::Attended->value)
            ->whereDate('date_time', today())
            ->orderByDesc('updated_at')
            ->limit(6)
            ->get()
            ->map($map)
            ->values()
            ->all();

        $result = [
            'active'          => $active,
            'recently_called' => $recentlyCalled,
        ];

        Cache::put($cacheKey, $result, now()->addSeconds(30));

        return $result;
    }
}
