<?php

namespace App\Http\Controllers\Manager;

use App\DTOs\ActionPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\EntityRequest;
use App\Models\Entity;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use Inertia\{Inertia, Response};

class EntitiesController extends Controller
{
    protected string $titleController = 'Empresas';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $search  = $request->string('search')->trim()->value();
        $sortBy  = $request->string('sort', 'created_at')->value();
        $sortDir = $request->string('direction', 'desc')->value();

        $allowedSorts = ['created_at', 'code', 'name', 'city', 'state', 'active'];
        $sortBy       = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'created_at';
        $sortDir      = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'desc';

        $query = Entity::withTrashed()
            ->select('entities.*')
            ->selectRaw(
                '(SELECT COUNT(*) FROM entity_users WHERE entity_users.entity_id = entities.id AND entity_users.deleted_at IS NULL) as entity_users_count',
            )
            ->selectRaw(
                '(SELECT COUNT(*) FROM entity_user_integrators WHERE entity_user_integrators.entity_id = entities.id AND entity_user_integrators.deleted_at IS NULL) as entity_user_integrators_count',
            )
            ->where('code', '!=', 'ENT-0000000001');

        if ($search !== '') {
            $query->where(
                fn ($q) => $q
                    ->whereLikeUnaccent('entities.name', $search)
                    ->orWhereLikeUnaccent('entities.code', $search)
                    ->orWhereLikeUnaccent('entities.city', $search),
            );
        }

        $query->orderBy("entities.{$sortBy}", $sortDir);
        $entities = $query->paginate(15)->withQueryString();

        return Inertia::render('Panel/Manager/Entities/Index', [
            'entities' => $entities->through(fn ($e) => $this->toTableRow($e)),
            'total'    => fn () => Entity::withTrashed()->where('code', '!=', 'ENT-0000000001')->count(),
            'filters'  => $request->only(['search', 'sort', 'direction']),
            't'        => trans('manager_entities'),
        ]);
    }

    public function cards(Request $request): JsonResponse
    {
        $search  = $request->string('search')->trim()->value();
        $perPage = 12;

        $records = Entity::withTrashed()
            ->select('entities.*')
            ->selectRaw(
                '(SELECT COUNT(*) FROM entity_users WHERE entity_users.entity_id = entities.id AND entity_users.deleted_at IS NULL) as entity_users_count',
            )
            ->selectRaw(
                '(SELECT COUNT(*) FROM entity_user_integrators WHERE entity_user_integrators.entity_id = entities.id AND entity_user_integrators.deleted_at IS NULL) as entity_user_integrators_count',
            )
            ->where('code', '!=', 'ENT-0000000001')
            ->when($search, fn ($q) => $q->whereLikeUnaccent('name', $search))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $records->map(fn ($r) => [
                'id'                            => $r->id,
                'code'                          => $r->code,
                'name'                          => $r->name,
                'city'                          => $r->city,
                'state'                         => $r->state,
                'entity_users_count'            => $r->entity_users_count,
                'entity_user_integrators_count' => $r->entity_user_integrators_count,
                'users_url'                     => route('manager.entities.users', $r->id),
                'user_integrators_url'          => route('manager.entities.user-integrators.index', $r->id),
                ...ActionPolicy::forManager($r)->toArray(),
            ]),
            'meta' => [
                'total'        => $records->total(),
                'per_page'     => $records->perPage(),
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $record = Entity::withTrashed()->with('twoFactorEnabledByUser')->findOrFail($id);

        return response()->json(['data' => [
            'id'                     => $record->id,
            'code'                   => $record->code,
            'name'                   => $record->name,
            'subdomain'              => $record->subdomain,
            'email'                  => $record->email,
            'telephone'              => $record->telephone,
            'cellphone'              => $record->cellphone,
            'website'                => $record->website,
            'national_registration'  => $record->national_registration,
            'state_registration'     => $record->state_registration,
            'municipal_registration' => $record->municipal_registration,
            'schedule_interval'      => $record->schedule_interval,
            'zipcode'                => $record->zipcode,
            'address'                => $record->address,
            'number'                 => $record->number,
            'complement'             => $record->complement,
            'district'               => $record->district,
            'city'                   => $record->city,
            'state'                  => $record->state,
            'country'                => $record->country,
            'active'                 => (bool) $record->active,
            // 2FA por empresa (Hardening LGPD/CFM)
            'requires_two_factor'   => (bool) $record->requires_two_factor,
            'two_factor_enabled_at' => $record->two_factor_enabled_at?->format('d/m/Y H:i'),
            'two_factor_enabled_by' => $record->twoFactorEnabledByUser?->name,
            'deleted_at'            => $record->deleted_at?->format('d/m/Y H:i'),
            'created_at'            => $record->created_at?->format('d/m/Y H:i'),
        ]]);
    }

    public function editData(string $id): JsonResponse
    {
        $record = Entity::withTrashed()->findOrFail($id);

        return response()->json(['data' => [
            'name'                   => $record->name,
            'subdomain'              => $record->subdomain,
            'email'                  => $record->email,
            'telephone'              => $record->telephone,
            'cellphone'              => $record->cellphone,
            'national_registration'  => $record->national_registration,
            'state_registration'     => $record->state_registration,
            'municipal_registration' => $record->municipal_registration,
            'website'                => $record->website,
            'zipcode'                => $record->zipcode,
            'address'                => $record->address,
            'number'                 => $record->number,
            'complement'             => $record->complement,
            'district'               => $record->district,
            'city'                   => $record->city,
            'state'                  => $record->state,
            'country'                => $record->country,
            'schedule_interval'      => $record->schedule_interval,
            'active'                 => (bool) $record->active,
        ]]);
    }

    public function store(EntityRequest $request): Application|JsonResponse|Redirector|RedirectResponse
    {
        $record = Entity::create($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $this->titleController . ' cadastrada com sucesso.',
                'data'    => $record->toArray(),
            ]);
        }

        return redirect(route('manager.entities.index'))
            ->with('success', $this->titleController . ' cadastrada com sucesso.');
    }

    public function update(EntityRequest $request, string $id): Application|JsonResponse|Redirector|RedirectResponse
    {
        $record = Entity::findOrFail($id);
        $record->update($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $this->titleController . ' atualizada com sucesso.',
                'data'    => $record->fresh()->toArray(),
            ]);
        }

        return redirect(route('manager.entities.index'))
            ->with('success', $this->titleController . ' atualizada com sucesso.');
    }

    /**
     * Admin SaaS força/desativa 2FA em uma entity específica.
     * Reuso da mesma política do Setting/SecurityController, mas com a entity
     * vindo do param de rota (admin SaaS opera sobre QUALQUER tenant).
     * Exige reason por LGPD/CFM — auditor precisa saber por que SaaS forçou.
     */
    public function toggleTwoFactor(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason'  => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        $entity     = Entity::findOrFail($id);
        $enabled    = $request->boolean('enabled');
        $reason     = trim((string) $request->input('reason'));
        $oldEnabled = (bool) $entity->requires_two_factor;

        if ($oldEnabled === $enabled) {
            return response()->json([
                'message' => $enabled
                    ? __('manager_hardening.entity_2fa_enabled')
                    : __('manager_hardening.entity_2fa_disabled'),
                'data' => [
                    'requires_two_factor'   => $enabled,
                    'two_factor_enabled_at' => $entity->two_factor_enabled_at?->format('d/m/Y H:i'),
                ],
            ]);
        }

        $entity->update([
            'requires_two_factor'   => $enabled,
            'two_factor_enabled_at' => $enabled ? now() : null,
            'two_factor_enabled_by' => $enabled ? Auth::id() : null,
        ]);

        $this->audit->recordAdminAction(
            event: $enabled ? 'entity.two_factor.enable' : 'entity.two_factor.disable',
            targetEntityId: (string) $entity->id,
            targetUserId: null,
            auditableType: 'entity',
            auditableId: (string) $entity->id,
            reason: $reason,
            newValues: ['requires_two_factor' => $enabled, 'forced_by_saas' => true],
            request: $request,
            oldValues: ['requires_two_factor' => $oldEnabled],
        );

        return response()->json([
            'message' => $enabled
                ? __('manager_hardening.entity_2fa_enabled')
                : __('manager_hardening.entity_2fa_disabled'),
            'data' => [
                'requires_two_factor'   => $enabled,
                'two_factor_enabled_at' => $entity->fresh()->two_factor_enabled_at?->format('d/m/Y H:i'),
            ],
        ]);
    }

    public function destroy(string $id, Request $request): Application|JsonResponse|Redirector|RedirectResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        $record = Entity::findOrFail($id);
        $record->delete();

        $this->audit->recordAdminAction(
            event: 'manager.entity.destroy',
            targetEntityId: (string) $record->id,
            targetUserId: null,
            auditableType: 'entity',
            auditableId: (string) $record->id,
            reason: trim((string) $request->input('reason')),
            newValues: ['code' => $record->code, 'name' => $record->name],
            request: $request,
        );

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $this->titleController . ' removida com sucesso.',
                'deleted' => $record->toArray(),
            ]);
        }

        return redirect(route('manager.entities.index'))
            ->with('success', $this->titleController . ' removida com sucesso.');
    }

    private function toTableRow(Entity $e): array
    {
        $policy = ActionPolicy::forManager($e);

        return [
            'id'                            => $e->id,
            'code'                          => $e->code,
            'name'                          => $e->name,
            'city'                          => $e->city,
            'state'                         => $e->state,
            'email'                         => $e->email,
            'is_client'                     => $e->is_client,
            'active'                        => (bool) $e->active,
            'requires_two_factor'           => (bool) $e->requires_two_factor,
            'deleted_at'                    => $e->deleted_at,
            'created_at'                    => $e->created_at?->format('d/m/Y'),
            'entity_users_count'            => (int) $e->entity_users_count,
            'entity_user_integrators_count' => (int) $e->entity_user_integrators_count,
            'users_url'                     => route('manager.entities.users', $e->id),
            'user_integrators_url'          => route('manager.entities.user-integrators.index', $e->id),
            'mode'                          => $policy->mode,
            'deleted'                       => $policy->deleted,
        ];
    }
}
