<?php

namespace App\Http\Controllers\Manager;

use App\Enums\{CovenantSource, ImportStatus};
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\CovenantRequest;
use App\Models\{Covenant, CovenantImport};
use App\Services\Audit\AuditLogger;
use App\Support\{BrazilianFormat, TenantContext};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\{Inertia, Response};

/**
 * Catálogo GLOBAL de convênios (entity_id nulo) — operadoras da ANS +
 * cadastros manuais —, visível para todas as clínicas. Manager do SaaS,
 * admin only.
 *
 * Convênio da ANS: dados oficiais vêm da sincronização
 * (CovenantImportsController / covenants:sync-ans); aqui só nome de
 * exibição, cor, tabela própria e ativo. Manual: cadastro completo.
 * Exclusão só de convênio manual SEM uso — as FKs para covenants.id fazem
 * cascade em pacientes, agenda e faturamento de todas as clínicas.
 *
 * Nunca usa route model binding: o EntityScope fica inerte no manager, então
 * o binding acharia convênio de CLÍNICA também.
 */
class CovenantsController extends Controller
{
    /** Ordenação aceita pela tabela (whitelist — vai direto pro ORDER BY). */
    private const SORTS = ['name', 'ans_registry', 'ans_modality', 'uf', 'source', 'active'];

    /** Editável em convênio da ANS (o resto vem da sincronização). */
    private const ANS_EDITABLE = ['name', 'color', 'table', 'active'];

    /** Tabelas com FK para covenants.id (uso por clínicas). */
    private const USAGE_TABLES = [
        'patients', 'schedules', 'waiting_list', 'billing_batches', 'billing_claims',
        'financial_cash_entries', 'procedure_prices', 'doctor_payout_rules', 'doctor_payout_items',
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $modalities = (array) config('covenants.ans.modalities');

        $filters = [
            'search'    => $request->string('search')->trim()->value(),
            'source'    => in_array($request->input('source'), ['manual', 'ans'], true) ? $request->input('source') : '',
            'status'    => in_array($request->input('status'), ['active', 'inactive'], true) ? $request->input('status') : '',
            'modality'  => in_array($request->input('modality'), $modalities, true) ? $request->input('modality') : '',
            'uf'        => in_array($request->input('uf'), CovenantRequest::UFS, true) ? $request->input('uf') : '',
            'cancelled' => $request->boolean('cancelled'),
            'sort'      => in_array($request->input('sort'), self::SORTS, true) ? $request->input('sort') : 'name',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];

        // Planos globais ativos de cada convênio (coluna "Planos").
        $query = $this->globalCatalog()->withCount(['plans as plans_count' => fn ($q) => $q->withoutGlobalScopes()
            ->whereNull('covenant_plans.entity_id')
            ->whereNull('covenant_plans.deleted_at')
            ->where('covenant_plans.active', true)]);

        if ($filters['search'] !== '') {
            $this->applySearch($query, $filters['search']);
        }

        $query->when($filters['source'], fn (Builder $q, string $source) => $q->where('source', $source))
            ->when($filters['status'] === 'active', fn (Builder $q) => $q->where('active', true))
            ->when($filters['status'] === 'inactive', fn (Builder $q) => $q->where('active', false))
            ->when($filters['modality'], fn (Builder $q, string $modality) => $q->where('ans_modality', $modality))
            ->when($filters['uf'], fn (Builder $q, string $uf) => $q->where('uf', $uf))
            ->when($filters['cancelled'], fn (Builder $q) => $q->where('ans_status', 'cancelled'))
            ->orderBy('covenants.' . $filters['sort'], $filters['direction'])
            ->orderBy('covenants.name')
            ->orderBy('covenants.id');

        $runningImport = CovenantImport::query()
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->latest()
            ->first();

        return Inertia::render('Panel/Manager/Covenants/Index', [
            'covenants'         => $query->paginate(20)->withQueryString()->through(fn (Covenant $c) => $this->toRow($c)),
            'filters'           => $filters,
            'stats'             => fn () => $this->stats(),
            'imports'           => fn () => $this->recentImports(),
            'runningImport'     => $runningImport?->progressPayload(),
            'modalities'        => $modalities,
            'defaultModalities' => (array) config('covenants.ans.default_modalities'),
            'ufs'               => CovenantRequest::UFS,
            // Aviso da sincronização semanal (routes/console.php).
            'autoSync' => (bool) config('covenants.ans.sync_enabled'),
            't'        => trans('manager_covenants'),
            'tPlans'   => trans('covenant_plans'),
        ]);
    }

    public function store(CovenantRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->tenant->withoutScope(function () use ($data) {
            $covenant = new Covenant([
                'table'  => true,
                'active' => true,
                ...$data,
                'source' => CovenantSource::Manual,
            ]);
            $covenant->entity_id = null;
            $covenant->save();
        });

        return back()->with('success', __('manager_covenants.saved'));
    }

    public function update(CovenantRequest $request, string $covenant): RedirectResponse
    {
        $model = $this->globalCatalog()->findOrFail($covenant);
        $data  = $request->validated();

        if ($model->isParticular()) {
            // Regras de paciente, importação e repasse dependem dele pelo nome.
            $renaming     = array_key_exists('name', $data) && $data['name'] !== mb_strtoupper((string) $model->name, 'UTF-8');
            $deactivating = array_key_exists('active', $data) && ! $data['active'];

            abort_if($renaming || $deactivating, 422, __('manager_covenants.particular_protected'));
        }

        if ($model->source === CovenantSource::Ans) {
            abort_if(array_diff(array_keys($data), self::ANS_EDITABLE) !== [], 422, __('manager_covenants.ans_only_display'));
        }

        $this->tenant->withoutScope(fn () => $model->update($data));

        return back()->with('success', __('manager_covenants.saved'));
    }

    public function destroy(Request $request, string $covenant): RedirectResponse
    {
        $model = $this->globalCatalog()->findOrFail($covenant);

        abort_if($model->isParticular(), 422, __('manager_covenants.particular_protected'));
        abort_if($model->source === CovenantSource::Ans, 422, __('manager_covenants.ans_cannot_delete'));

        // Só o servidor sabe se alguma clínica usa: erro volta no modal da justificativa.
        if ($this->inUse((string) $model->id)) {
            throw ValidationException::withMessages(['reason' => __('manager_covenants.in_use')]);
        }

        // Mesma regra das demais exclusões do manager: justificativa + trilha.
        $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        // Soft delete (sem uso, mas o histórico de auditoria aponta pra ele),
        // junto com os planos manuais dele no catálogo global.
        $this->tenant->withoutScope(fn () => DB::transaction(function () use ($model) {
            $model->delete();
            DB::table('covenant_plans')->where('covenant_id', $model->id)->whereNull('entity_id')
                ->whereNull('deleted_at')->update(['deleted_at' => now()]);
        }));

        $this->audit->recordAdminAction(
            event: 'manager.covenant.destroy',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'covenant',
            auditableId: (string) $model->id,
            reason: trim((string) $request->input('reason')),
            newValues: ['name' => $model->name, 'ans_registry' => $model->ans_registry],
            request: $request,
        );

        return back()->with('success', __('manager_covenants.deleted'));
    }

    /** Uso do convênio pelas clínicas (gaveta de detalhes, antes de desativar). */
    public function usage(string $covenant): JsonResponse
    {
        $model = $this->globalCatalog()->findOrFail($covenant);
        $id    = (string) $model->id;

        $clinics = DB::query()
            ->fromSub(
                DB::table('patients')->select('entity_id')->where('covenant_id', $id)
                    ->union(DB::table('schedules')->select('entity_id')->where('covenant_id', $id)),
                'used',
            )
            ->count();

        return response()->json(['data' => [
            'clinics'   => $clinics,
            'patients'  => DB::table('patients')->where('covenant_id', $id)->whereNull('deleted_at')->count(),
            'schedules' => DB::table('schedules')->where('covenant_id', $id)->count(),
            'claims'    => DB::table('billing_claims')->where('covenant_id', $id)->count(),
            'prices'    => DB::table('procedure_prices')->where('covenant_id', $id)->count(),
        ]]);
    }

    /** @return Builder<Covenant> */
    private function globalCatalog(): Builder
    {
        return Covenant::withoutGlobalScopes()->whereNull('covenants.entity_id')->whereNull('covenants.deleted_at');
    }

    /** Busca por nome, razão social, nome fantasia (sem acento), registro ANS ou CNPJ. */
    private function applySearch(Builder $query, string $search): void
    {
        $term   = addcslashes($search, '\\%_');
        $digits = BrazilianFormat::digits($search);
        // Registro digitado com ou sem máscara; CNPJ pode ter letras (alfanumérico).
        $document = preg_match('/^[A-Za-z0-9.\/\s-]+$/', $search) === 1 ? (string) BrazilianFormat::documentChars($search) : '';

        $query->where(function (Builder $q) use ($term, $digits, $document) {
            $q->whereLikeUnaccent('covenants.name', $term)
                ->orWhereLikeUnaccent('covenants.company_name', $term)
                ->orWhereLikeUnaccent('covenants.trade_name', $term);

            if (strlen($digits) >= 3) {
                $q->orWhere('covenants.ans_registry', 'like', $digits . '%');
            }

            if (strlen($document) >= 3 && preg_match('/\d/', $document) === 1) {
                $q->orWhere('covenants.national_registry', 'like', $document . '%');
            }
        });
    }

    private function inUse(string $id): bool
    {
        foreach (self::USAGE_TABLES as $table) {
            if (DB::table($table)->where('covenant_id', $id)->exists()) {
                return true;
            }
        }

        // Plano próprio de clínica no convênio também é uso.
        return DB::table('covenant_plans')->where('covenant_id', $id)->whereNotNull('entity_id')->exists();
    }

    /** @return array<string, mixed> */
    private function toRow(Covenant $c): array
    {
        return [
            'id'                      => $c->id,
            'code'                    => $c->code,
            'name'                    => $c->name,
            'company_name'            => $c->company_name,
            'trade_name'              => $c->trade_name,
            'national_registry'       => $c->national_registry,
            'cnpj_formatted'          => $c->national_registry ? BrazilianFormat::cnpj($c->national_registry) : null,
            'ans_registry'            => $c->ans_registry,
            'ans_modality'            => $c->ans_modality,
            'city'                    => $c->city,
            'uf'                      => $c->uf,
            'ans_registered_at'       => $c->ans_registered_at?->isoFormat('L'),
            'ans_status'              => $c->ans_status,
            'ans_cancelled_at'        => $c->ans_cancelled_at?->isoFormat('L'),
            'ans_cancellation_reason' => $c->ans_cancellation_reason,
            'source'                  => ($c->source ?? CovenantSource::Manual)->value,
            'source_label'            => ($c->source ?? CovenantSource::Manual)->label(),
            'color'                   => $c->color,
            'table'                   => (bool) $c->table,
            'active'                  => (bool) $c->active,
            'synced_at'               => $c->source_synced_at?->isoFormat('L LT'),
            'is_particular'           => $c->isParticular(),
            'plans_count'             => (int) ($c->plans_count ?? 0),
        ];
    }

    /** @return array<string, int> */
    private function stats(): array
    {
        return [
            'active'    => $this->globalCatalog()->where('active', true)->count(),
            'ans'       => $this->globalCatalog()->where('source', CovenantSource::Ans->value)->count(),
            'manual'    => $this->globalCatalog()->where('source', CovenantSource::Manual->value)->count(),
            'cancelled' => $this->globalCatalog()->where('ans_status', 'cancelled')->count(),
            'plans'     => DB::table('covenant_plans')->whereNull('entity_id')->whereNull('deleted_at')->where('active', true)->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentImports(): array
    {
        return CovenantImport::query()
            ->with('user:id,name')
            ->latest()
            ->limit(10)
            ->get()
            // Mesmo formato da barra de progresso + metadados do histórico (a
            // tela acha aqui o resultado de uma sincronização que terminou
            // antes do WebSocket conectar).
            ->map(fn (CovenantImport $i) => [
                ...$i->progressPayload(),
                'user'                    => $i->user?->name,
                'active_original_name'    => $i->active_original_name,
                'cancelled_original_name' => $i->cancelled_original_name,
                'created_at'              => $i->created_at?->isoFormat('L LT'),
                'finished_at'             => $i->finished_at?->isoFormat('L LT'),
            ])
            ->all();
    }
}
