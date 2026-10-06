<?php

namespace App\Http\Controllers\Manager;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\Cid10CodeRequest;
use App\Models\{Cid10Code, Cid10Import};
use App\Services\Audit\AuditLogger;
use App\Services\Cid10\{Cid10Classifier, Cid10ReviewService, Cid10UsageStats};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Inertia\{Inertia, Response};

/**
 * Catálogo GLOBAL da CID-10 (busca de diagnóstico de prontuários, exames e
 * guias de todas as clínicas) — manager do SaaS, admin only.
 *
 * O manager pode tudo, com salvaguardas:
 * - descrição oficial guardada à parte (official_description) e selo
 *   "editado"; a importação nunca sobrescreve uma edição manual;
 * - código criado aqui é "personalizado" (fora da tabela oficial — TISS
 *   pode recusar);
 * - trocar o CÓDIGO ou excluir só sem uso em prontuário/exame/clínica
 *   (contagem ao vivo); excluir e mudar o texto de código oficial exigem
 *   justificativa (trilha administrativa), além da auditoria do model.
 */
class Cid10CodesController extends Controller
{
    /** Ordenação aceita pela tabela (whitelist — vai direto pro ORDER BY). */
    private const SORTS = ['code', 'description', 'category', 'chapter', 'usage'];

    public function __construct(
        private readonly Cid10UsageStats $usage,
        private readonly Cid10ReviewService $review,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $chapters = Cid10Classifier::bundled()->chapters();

        $filters = [
            'search'    => mb_substr($request->string('search')->trim()->value(), 0, 100),
            'source'    => in_array($request->input('source'), [Cid10Code::SOURCE_DATASUS, Cid10Code::SOURCE_CUSTOM], true) ? $request->input('source') : '',
            'edited'    => $request->boolean('edited'),
            'chapter'   => in_array($request->input('chapter'), array_column($chapters, 'chapter'), true) ? $request->input('chapter') : '',
            'category'  => mb_substr($request->string('category')->trim()->value(), 0, 255),
            'usage'     => in_array($request->input('usage'), ['used', 'unused'], true) ? $request->input('usage') : '',
            'sort'      => in_array($request->input('sort'), self::SORTS, true) ? $request->input('sort') : 'code',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];

        // Agregado de uso (prontuários + exames) — recalculado no máximo a cada 15 min.
        $usageAt = $this->usage->ensureFresh();

        $query = Cid10Code::query()
            ->leftJoin('cid10_usage_stats as u', 'u.code', '=', 'cid10_codes.code')
            ->select('cid10_codes.*')
            ->selectRaw('COALESCE(u.records_count, 0) AS usage_records, COALESCE(u.exams_count, 0) AS usage_exams, COALESCE(u.clinics_count, 0) AS usage_clinics, COALESCE(u.links_count, 0) AS usage_links, COALESCE(u.total_count, 0) AS usage_total')
            ->with('editor:id,name');

        if ($filters['search'] !== '') {
            $query->matching($filters['search']);
        }

        $query->when($filters['source'], fn (Builder $q, string $source) => $q->where('cid10_codes.source', $source))
            ->when($filters['edited'], fn (Builder $q) => $q->whereNotNull('cid10_codes.description_edited_at'))
            ->when($filters['chapter'], fn (Builder $q, string $chapter) => $q->where('cid10_codes.chapter', $chapter))
            ->when($filters['category'], fn (Builder $q, string $category) => $q->where('cid10_codes.category', $category))
            ->when($filters['usage'] === 'used', fn (Builder $q) => $q->where('u.total_count', '>', 0))
            ->when($filters['usage'] === 'unused', fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('u.total_count')->orWhere('u.total_count', 0)));

        match ($filters['sort']) {
            'usage'   => $query->orderByRaw('COALESCE(u.total_count, 0) ' . $filters['direction']),
            'chapter' => $query->orderByRaw($this->chapterOrder() . ' ' . $filters['direction']),
            default   => $query->orderBy('cid10_codes.' . $filters['sort'], $filters['direction']),
        };

        $query->orderBy('cid10_codes.code');

        $chapterNames = array_column($chapters, 'name', 'chapter');

        $runningImport = Cid10Import::query()
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->latest()
            ->first();

        return Inertia::render('Panel/Manager/Cid10/Index', [
            'codes'      => $query->paginate(25)->withQueryString()->through(fn (Cid10Code $c) => $this->toRow($c, $chapterNames)),
            'filters'    => $filters,
            'stats'      => fn () => $this->stats(),
            'chapters'   => fn () => array_map(fn (array $c) => [...$c, 'label' => "{$c['chapter']} – {$c['name']}"], $chapters),
            'categories' => fn () => $this->categories($filters['chapter']),
            'usageAt'    => $usageAt?->toIso8601String(),
            // Card "Registros a revisar": resumo por clínica (sem paciente);
            // a lista detalhada só vem quando a gaveta abre.
            'review'        => fn () => $this->review->summary(),
            'reviewRecords' => Inertia::optional(fn () => $this->reviewRecords()),
            'imports'       => fn () => $this->recentImports(),
            // Barra de progresso (atualizada por WebSocket no canal do import).
            'runningImport' => $runningImport?->progressPayload(),
            't'             => trans('manager_cid10'),
        ]);
    }

    public function store(Cid10CodeRequest $request): RedirectResponse
    {
        $data       = $request->validated();
        $classifier = Cid10Classifier::bundled();
        $group      = $classifier->groupOf($data['code']);

        Cid10Code::query()->create([
            'code'        => $data['code'],
            'description' => $data['description'],
            // Sem categoria: o grupo oficial da faixa, como nos códigos oficiais.
            'category'   => $data['category'] ?? $group,
            'source'     => Cid10Code::SOURCE_CUSTOM,
            'chapter'    => $classifier->chapterOf($data['code']),
            'group_name' => $group,
        ]);

        $this->usage->forget();

        return back()->with('success', __('manager_cid10.created', ['code' => $data['code']]));
    }

    public function update(Cid10CodeRequest $request, string $cid10): RedirectResponse
    {
        $model = Cid10Code::query()->findOrFail($cid10);
        $data  = $request->validated();
        $old   = $model->only(['code', 'description', 'category', 'source']);

        $codeChanged        = $data['code'] !== $model->code;
        $descriptionChanged = $data['description'] !== $model->description;
        $official           = $model->source === Cid10Code::SOURCE_DATASUS;

        if ($codeChanged) {
            $this->ensureUnused($model, 'code', 'code_in_use');
        }

        // Texto de código OFICIAL (o que todo médico vê): justificativa + trilha.
        $reason = null;

        if ($official && ($descriptionChanged || $codeChanged)) {
            $reason = $this->validatedReason($request);
        }

        $model->fill([
            'code'        => $data['code'],
            'description' => $data['description'],
            'category'    => $data['category'] ?? null,
        ]);

        if ($codeChanged) {
            // Outro código já não é o oficial: vira personalizado (o oficial
            // volta na próxima importação, se for da lista).
            $classifier = Cid10Classifier::bundled();
            $model->fill([
                'source'                => Cid10Code::SOURCE_CUSTOM,
                'official_description'  => null,
                'description_edited_at' => null,
                'description_edited_by' => null,
                'chapter'               => $classifier->chapterOf($data['code']),
                'group_name'            => $classifier->groupOf($data['code']),
            ]);
        } elseif ($official && $descriptionChanged) {
            // Voltou ao texto oficial = não é mais "editado" (a importação volta a cuidar dele).
            $backToOfficial = $data['description'] === $model->official_description;
            $model->fill([
                'description_edited_at' => $backToOfficial ? null : now(),
                'description_edited_by' => $backToOfficial ? null : $request->user()->id,
            ]);
        }

        $model->save();

        if ($reason !== null) {
            $this->audit->recordAdminAction(
                event: 'manager.cid10.update',
                targetEntityId: null,
                targetUserId: null,
                auditableType: 'cid10_code',
                auditableId: (string) $model->id,
                reason: $reason,
                newValues: $model->only(['code', 'description', 'category', 'source']),
                request: $request,
                oldValues: $old,
            );
        }

        if ($codeChanged) {
            $this->usage->forget();
        }

        return back()->with('success', __('manager_cid10.saved', ['code' => $model->code]));
    }

    public function destroy(Request $request, string $cid10): RedirectResponse
    {
        $model = Cid10Code::query()->findOrFail($cid10);

        // Uso primeiro: não adianta pedir justificativa de algo que não pode sair.
        $this->ensureUnused($model, 'code', 'delete_in_use');
        $reason = $this->validatedReason($request);

        $model->delete();
        $this->usage->forget();

        $this->audit->recordAdminAction(
            event: 'manager.cid10.destroy',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'cid10_code',
            auditableId: (string) $model->id,
            reason: $reason,
            newValues: $model->only(['code', 'description', 'category', 'source']),
            request: $request,
        );

        return back()->with('success', __('manager_cid10.deleted', ['code' => $model->code]));
    }

    /**
     * Código usado em prontuário/exame/vínculo de clínica não muda nem sai:
     * os registros guardam o código e os vínculos apontam para ele.
     */
    private function ensureUnused(Cid10Code $model, string $field, string $messageKey): void
    {
        $usage = $this->usage->liveCount($model);

        if ($usage['total'] > 0) {
            throw ValidationException::withMessages([$field => __('manager_cid10.' . $messageKey, [
                'code'    => $model->code,
                'count'   => Number::format($usage['total'], locale: app()->getLocale()),
                'records' => Number::format($usage['records'], locale: app()->getLocale()),
                'exams'   => Number::format($usage['exams'], locale: app()->getLocale()),
                'links'   => Number::format($usage['links'], locale: app()->getLocale()),
            ])]);
        }
    }

    /** Mesma regra das demais ações sensíveis do manager (mín. 20 caracteres). */
    private function validatedReason(Request $request): string
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        return trim((string) $request->input('reason'));
    }

    /** Capítulo romano → ordem numérica (I, II … XXII), para ordenar pela coluna. */
    private function chapterOrder(): string
    {
        $cases = '';

        for ($n = 1; $n <= 22; $n++) {
            $cases .= " WHEN '" . Cid10Classifier::roman($n) . "' THEN {$n}";
        }

        return "CASE cid10_codes.chapter{$cases} ELSE 99 END";
    }

    /**
     * @param array<string, string> $chapterNames
     *
     * @return array<string, mixed>
     */
    private function toRow(Cid10Code $c, array $chapterNames): array
    {
        return [
            'id'                   => $c->id,
            'code'                 => $c->code,
            'description'          => $c->description,
            'official_description' => $c->official_description,
            'category'             => $c->category,
            'group_name'           => $c->group_name,
            'chapter'              => $c->chapter,
            'chapter_name'         => $c->chapter ? ($chapterNames[$c->chapter] ?? null) : null,
            'source'               => $c->source,
            'is_custom'            => $c->isCustom(),
            'is_edited'            => $c->description_edited_at !== null,
            'edited_at'            => $c->description_edited_at?->isoFormat('L LT'),
            'edited_by'            => $c->editor?->name,
            'created_at'           => $c->created_at?->isoFormat('L'),
            'usage'                => [
                'records' => (int) $c->getAttribute('usage_records'),
                'exams'   => (int) $c->getAttribute('usage_exams'),
                'clinics' => (int) $c->getAttribute('usage_clinics'),
                'links'   => (int) $c->getAttribute('usage_links'),
                'total'   => (int) $c->getAttribute('usage_total'),
            ],
        ];
    }

    /** @return array<string, int> */
    private function stats(): array
    {
        $row = DB::table('cid10_codes')
            ->leftJoin('cid10_usage_stats as u', 'u.code', '=', 'cid10_codes.code')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN cid10_codes.source = ? THEN 1 ELSE 0 END) AS official', [Cid10Code::SOURCE_DATASUS])
            ->selectRaw('SUM(CASE WHEN cid10_codes.source = ? THEN 1 ELSE 0 END) AS custom', [Cid10Code::SOURCE_CUSTOM])
            ->selectRaw('SUM(CASE WHEN cid10_codes.description_edited_at IS NOT NULL THEN 1 ELSE 0 END) AS edited')
            ->selectRaw('SUM(CASE WHEN u.total_count > 0 THEN 1 ELSE 0 END) AS used')
            ->first();

        return [
            'total'    => (int) $row->total,
            'official' => (int) $row->official,
            'custom'   => (int) $row->custom,
            'edited'   => (int) $row->edited,
            'used'     => (int) $row->used,
            'review'   => $this->review->summary()['total'],
        ];
    }

    /** @return list<string> categorias existentes (do capítulo escolhido, se houver) */
    private function categories(string $chapter): array
    {
        return DB::table('cid10_codes')
            ->whereNotNull('category')
            ->when($chapter !== '', fn ($q) => $q->where('chapter', $chapter))
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function reviewRecords(): array
    {
        $codes = array_keys(Cid10ReviewService::AFFECTED);
        $now   = Cid10Code::query()->whereIn('code', $codes)->get(['code', 'description', 'official_description'])->keyBy('code');

        return array_map(fn (array $row) => [
            'entity'   => $row['entity'],
            'kind'     => $row['kind'],
            'code'     => $row['code'],
            'cid'      => $row['cid'],
            'text'     => $row['text'],
            'signed'   => $row['signed'],
            'old_text' => Cid10ReviewService::AFFECTED[$row['cid']] ?? null,
            'official' => $now->get($row['cid'])?->official_description ?? $now->get($row['cid'])?->description,
        ], $this->review->cachedRows());
    }

    /** @return list<array<string, mixed>> */
    private function recentImports(): array
    {
        return Cid10Import::query()
            ->with('user:id,name')
            ->latest()
            ->limit(10)
            ->get()
            // Mesmo formato da barra de progresso + metadados do histórico — a
            // tela acha aqui o resultado de uma importação que terminou antes
            // do WebSocket conectar.
            ->map(fn (Cid10Import $i) => [
                ...$i->progressPayload(),
                'user'        => $i->user?->name,
                'files'       => $i->files ?? [],
                'created_at'  => $i->created_at?->isoFormat('L LT'),
                'finished_at' => $i->finished_at?->isoFormat('L LT'),
            ])
            ->all();
    }
}
