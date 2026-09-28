<?php

namespace App\Http\Controllers\Setting;

use App\DTOs\ActionPolicy;
use App\Enums\{DocumentationType, PaperSize, ReportSettingStatus};
use App\Http\Controllers\Concerns\RedirectsToListing;
use App\Http\Controllers\Controller;
use App\Models\{ReportCategory, ReportSetting};
use App\Services\ReportSettingService;
use BackedEnum;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\{Inertia, Response as InertiaResponse};
use InvalidArgumentException;

class ReportSettingsController extends Controller
{
    use RedirectsToListing;

    /**
     * Parâmetros da listagem preservados ao voltar de excluir/reimportar/
     * salvar (RedirectsToListing) — busca, filtros, ordenação e página.
     */
    private const LISTING_PARAMS = ['search', 'category', 'status', 'sort', 'direction', 'page'];

    /**
     * Colunas ordenáveis da listagem (whitelist) → coluna real no banco
     * (categoria vem do LEFT JOIN do index()).
     */
    private const SORTABLE = [
        'title'      => 'report_settings.title',
        'category'   => 'report_categories.name',
        'paper_size' => 'report_settings.paper_size',
        'updated_at' => 'report_settings.updated_at',
    ];

    /** Ordem padrão = a de sempre da tela (título A→Z). */
    private const DEFAULT_SORT = 'title';

    private const DEFAULT_DIRECTION = 'asc';

    private const STATUSES = ['all', 'active', 'inactive'];

    private const PER_PAGE = 12;

    public function __construct(
        private readonly ReportSettingService $service,
    ) {
    }

    /**
     * Listagem no padrão de Panel/Patients: busca (título/descrição, sem
     * acento), filtros de categoria e status, ordenação por whitelist e
     * paginação server-side — antes carregava todos os modelos e filtrava
     * só o título no navegador. Tabela e cards usam o MESMO paginator.
     */
    public function index(Request $request): InertiaResponse
    {
        $entityId = (string) session('selected_entity_id');
        $this->service->adoptPublishedGlobalsForEntity($entityId);

        $categories = ReportCategory::active()->ordered()->get(['id', 'name']);

        $search   = $this->queryText($request, 'search');
        $category = $this->queryText($request, 'category');
        $status   = $this->queryText($request, 'status', 'all');
        $sortBy   = $this->queryText($request, 'sort', self::DEFAULT_SORT);
        $sortDir  = $this->queryText($request, 'direction', self::DEFAULT_DIRECTION);

        // Normalizados: valor fora da lista cai no padrão (a UI mostra o que
        // foi realmente aplicado). Categoria só vale se for uma das ativas.
        $category = $categories->contains('id', $category) ? $category : '';
        $status   = in_array($status, self::STATUSES, true) ? $status : 'all';
        $sortBy   = array_key_exists($sortBy, self::SORTABLE) ? $sortBy : self::DEFAULT_SORT;
        $sortDir  = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : self::DEFAULT_DIRECTION;

        // report_categories também tem name/description/active: toda coluna
        // é qualificada e o select fica só em report_settings.*.
        $query = ReportSetting::query()
            ->select('report_settings.*')
            ->leftJoin('report_categories', 'report_categories.id', '=', 'report_settings.report_category_id')
            ->where('report_settings.entity_id', $entityId)
            // sourceSetting: hasUpdateAvailable() lia o modelo global de
            // origem com uma consulta por linha (N+1).
            ->with(['category:id,name', 'sourceSetting:id,version'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $q) use ($search): void {
                    $q->whereLikeUnaccent('report_settings.title', $search)
                        ->orWhereLikeUnaccent('report_settings.description', $search);
                });
            })
            ->when($category !== '', fn (Builder $query) => $query->where('report_settings.report_category_id', $category))
            ->when($status === 'active', fn (Builder $query) => $query->where('report_settings.active', true))
            ->when($status === 'inactive', fn (Builder $query) => $query->where('report_settings.active', false))
            // NULLS LAST: modelo sem categoria vai para o fim nos dois sentidos.
            // Coluna e direção vêm só da whitelist acima.
            ->orderByRaw(self::SORTABLE[$sortBy] . ' ' . $sortDir . ' NULLS LAST')
            ->when($sortBy !== 'title', fn (Builder $query) => $query->orderBy('report_settings.title'))
            // `id` desempata — ordem entre páginas determinística no PostgreSQL.
            ->orderBy('report_settings.id');

        $items = $this->paginateClamped($query, self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ReportSetting $r) => $this->toRow($r, $entityId));

        return Inertia::render('Panel/Settings/ReportSettings/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.settings'), 'url' => '#', 'active' => false],
                ['label' => __('report_settings.page_title'), 'url' => '#', 'active' => true],
            ],
            'categories' => $categories,
            'items'      => $items,
            'filters'    => [
                'search'    => $search,
                'category'  => $category,
                'status'    => $status,
                'sort'      => $sortBy,
                'direction' => $sortDir,
            ],
            't'    => trans('report_settings'),
            'urls' => [
                'index'  => route('panel.setting.report-settings.index'),
                'create' => route('panel.setting.report-settings.create'),
            ],
        ]);
    }

    /** Linha da tabela/card (mesmo formato nos dois modos). */
    private function toRow(ReportSetting $r, string $entityId): array
    {
        return [
            'id'             => (string) $r->id,
            'title'          => $r->title,
            'description'    => $r->description,
            'paper_size'     => $r->paper_size instanceof BackedEnum ? $r->paper_size->value : (string) $r->paper_size,
            'show_header'    => (bool) $r->show_header,
            'show_signature' => (bool) $r->show_signature,
            'show_footer'    => (bool) $r->show_footer,
            'active'         => (bool) $r->active,
            'is_adopted'     => $r->isAdopted(),
            'has_update'     => $r->hasUpdateAvailable(),
            'source_version' => $r->source_version,
            'category'       => $r->category?->name,
            // ISO 8601: a tela formata no idioma do usuário (useLocaleFormat).
            'updated_at'   => $r->updated_at?->toIso8601String(),
            'preview_url'  => route('panel.setting.report-settings.preview', $r),
            'edit_url'     => route('panel.setting.report-settings.edit', $r),
            'destroy_url'  => route('panel.setting.report-settings.destroy', $r),
            'reimport_url' => $r->isAdopted() ? route('panel.setting.report-settings.reimport', $r) : null,
            ...ActionPolicy::from($r, $entityId)->toArray(),
        ];
    }

    public function create(): InertiaResponse
    {
        $categories = ReportCategory::active()->ordered()->get(['id', 'name']);

        return Inertia::render('Panel/Settings/ReportSettings/Form', [
            'breadcrumbs'         => $this->buildBreadcrumbs(__('report_settings.form_title_create')),
            'mode'                => 'create',
            'reportSetting'       => null,
            'categories'          => $categories,
            'paper_sizes'         => array_map(fn (string $v) => ['value' => $v, 'label' => $v], PaperSize::values()),
            'documentation_types' => array_map(fn (string $v) => ['value' => $v, 'label' => $v], DocumentationType::values()),
            'urls'                => [
                'store' => route('panel.setting.report-settings.store'),
                'index' => route('panel.setting.report-settings.index'),
            ],
        ]);
    }

    /**
     * Store a new template with its contents and variables.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRequest($request);

        $setting = ReportSetting::create(array_merge(
            ['entity_id' => session('selected_entity_id')],
            $validated,
        ));

        $this->service->syncContents($setting, $request->input('contents', []));

        return $this->backToListing('message', __('report_settings.flash_saved'));
    }

    /**
     * Preview HTML do template — exibido no modal da clínica.
     * Aplica a mesma autorização de show() mas retorna HTML em vez de JSON.
     */
    public function preview(ReportSetting $reportSetting): View
    {
        $this->assertCanPreviewTemplate($reportSetting);

        $reportSetting->load([
            'activeContents' => fn ($q) => $q->orderBy('sort_order')->orderBy('label'),
        ]);

        return view('pdf.report_setting_preview_html', compact('reportSetting'));
    }

    /**
     * Show a template (JSON for modal or full view).
     */
    public function show(ReportSetting $reportSetting): JsonResponse
    {
        $this->assertCanPreviewTemplate($reportSetting);

        $reportSetting->load([
            'category',
            'contents' => fn ($q) => $q
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('label'),
        ]);

        return response()->json([
            'id'             => $reportSetting->id,
            'title'          => $reportSetting->title,
            'description'    => $reportSetting->description,
            'paper_size'     => $reportSetting->paper_size?->value ?? (string) $reportSetting->paper_size,
            'category'       => $reportSetting->category?->name,
            'version'        => $reportSetting->version,
            'show_header'    => (bool) $reportSetting->show_header,
            'show_signature' => (bool) $reportSetting->show_signature,
            'show_footer'    => (bool) $reportSetting->show_footer,
            'contents'       => $reportSetting->contents->map(fn ($content) => [
                'id'      => $content->id,
                'label'   => $content->display_label,
                'type'    => $content->type?->value ?? (string) $content->type,
                'content' => $content->content,
            ])->values(),
        ]);
    }

    public function edit(ReportSetting $reportSetting): InertiaResponse
    {
        $this->assertOwnsReportSetting($reportSetting);

        $reportSetting->load('contents.variables');
        $categories = ReportCategory::active()->ordered()->get(['id', 'name']);

        return Inertia::render('Panel/Settings/ReportSettings/Form', [
            'breadcrumbs'   => $this->buildBreadcrumbs(__('report_settings.form_title_edit')),
            'mode'          => 'edit',
            'reportSetting' => [
                'id'                 => (string) $reportSetting->id,
                'title'              => $reportSetting->title,
                'description'        => $reportSetting->description,
                'report_category_id' => $reportSetting->report_category_id,
                'paper_size'         => $reportSetting->paper_size instanceof BackedEnum
                    ? $reportSetting->paper_size->value : (string) $reportSetting->paper_size,
                'font_family'         => $reportSetting->font_family,
                'font_size'           => $reportSetting->font_size,
                'margin_top'          => (float) $reportSetting->margin_top,
                'margin_right'        => (float) $reportSetting->margin_right,
                'margin_bottom'       => (float) $reportSetting->margin_bottom,
                'margin_left'         => (float) $reportSetting->margin_left,
                'show_header'         => (bool) $reportSetting->show_header,
                'header_show_logo'    => (bool) $reportSetting->header_show_logo,
                'header_show_name'    => (bool) $reportSetting->header_show_name,
                'header_show_address' => (bool) $reportSetting->header_show_address,
                'header_show_phone'   => (bool) $reportSetting->header_show_phone,
                'show_signature'      => (bool) $reportSetting->show_signature,
                'signature_show_name' => (bool) $reportSetting->signature_show_name,
                'signature_show_crm'  => (bool) $reportSetting->signature_show_crm,
                'signature_show_rqe'  => (bool) $reportSetting->signature_show_rqe,
                'show_footer'         => (bool) $reportSetting->show_footer,
                'footer_text'         => $reportSetting->footer_text,
                'footer_show_address' => (bool) $reportSetting->footer_show_address,
                'footer_show_phone'   => (bool) $reportSetting->footer_show_phone,
                'active'              => (bool) $reportSetting->active,
                'contents'            => $reportSetting->contents->map(fn ($c) => [
                    'id'      => $c->id,
                    'type'    => $c->type instanceof BackedEnum ? $c->type->value : (string) $c->type,
                    'label'   => $c->label,
                    'content' => $c->content,
                    'active'  => (bool) $c->active,
                ])->values(),
            ],
            'categories'          => $categories,
            'paper_sizes'         => array_map(fn (string $v) => ['value' => $v, 'label' => $v], PaperSize::values()),
            'documentation_types' => array_map(fn (string $v) => ['value' => $v, 'label' => $v], DocumentationType::values()),
            'urls'                => [
                'update' => route('panel.setting.report-settings.update', $reportSetting),
                'index'  => route('panel.setting.report-settings.index'),
            ],
        ]);
    }

    /**
     * Update a template.
     */
    public function update(Request $request, ReportSetting $reportSetting): RedirectResponse
    {
        $this->assertOwnsReportSetting($reportSetting);

        $validated = $this->validateRequest($request);

        $reportSetting->update($validated);

        $this->service->syncContents($reportSetting, $request->input('contents', []));

        return $this->backToListing('message', __('report_settings.flash_updated'));
    }

    /**
     * Soft-delete a template.
     */
    public function destroy(ReportSetting $reportSetting): RedirectResponse
    {
        $this->assertOwnsReportSetting($reportSetting);

        $reportSetting->delete();

        return $this->backToListing('message', __('report_settings.flash_deleted'));
    }

    /**
     * Adota (cópia profunda) um template global para a clínica.
     */
    public function adopt(ReportSetting $reportSetting): RedirectResponse
    {
        $entityId = session('selected_entity_id');

        // Só modelo global publicado é adotável (o service recusa o resto) —
        // antes a recusa estourava como erro 500.
        try {
            $this->service->adopt($reportSetting, $entityId);
        } catch (InvalidArgumentException) {
            return $this->backToListing('error', __('report_settings.error_adopt'));
        }

        return $this->backToListing('message', __('report_settings.flash_adopted'));
    }

    /**
     * Reimporta o conteúdo atualizado de um template global.
     */
    public function reimport(ReportSetting $reportSetting): RedirectResponse
    {
        $this->assertOwnsReportSetting($reportSetting);

        // Modelo global de origem excluído: o service recusa — antes a
        // recusa estourava como erro 500 e a tela não dizia nada.
        try {
            $this->service->reimport($reportSetting);
        } catch (InvalidArgumentException) {
            return $this->backToListing('error', __('report_settings.error_reimport'));
        }

        return $this->backToListing('message', __('report_settings.flash_reimported'));
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Volta para a listagem mantendo busca/filtros/ordenação/página (quando a
     * ação saiu dela), com a mensagem de retorno (`message` ou `error`).
     */
    private function backToListing(string $flashKey, string $message): RedirectResponse
    {
        return $this->redirectToListing('panel.setting.report-settings.index', self::LISTING_PARAMS)
            ->with($flashKey, $message);
    }

    /**
     * Pagina e, se a página pedida passou da última (ex.: excluiu o único
     * modelo da última página e o redirect manteve ?page=N), usa a última
     * válida — mesmo padrão de DoctorsController::paginateClamped().
     */
    private function paginateClamped(Builder $query, int $perPage): LengthAwarePaginator
    {
        $paginator = (clone $query)->paginate($perPage);

        if ($paginator->isEmpty() && $paginator->currentPage() > 1 && $paginator->lastPage() >= 1) {
            $paginator = $query->paginate($perPage, ['*'], 'page', $paginator->lastPage());
        }

        return $paginator;
    }

    /**
     * Parâmetro de query como texto (trim). Valor não textual (ex.:
     * `?sort[]=x`) vira o padrão em vez de estourar "Array to string
     * conversion" (500).
     */
    private function queryText(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key, $default);

        return is_string($value) ? trim($value) : $default;
    }

    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'title'              => ['required', 'string', 'max:255'],
            'description'        => ['nullable', 'string', 'max:1000'],
            'report_category_id' => ['nullable', 'uuid', 'exists:report_categories,id'],
            'paper_size'         => ['nullable', 'string', 'in:' . implode(',', PaperSize::values())],
            'font_family'        => ['nullable', 'string', 'max:50'],
            'font_size'          => ['nullable', 'integer', 'min:8', 'max:24'],
            'margin_top'         => ['nullable', 'numeric', 'min:0', 'max:10'],
            'margin_right'       => ['nullable', 'numeric', 'min:0', 'max:10'],
            'margin_bottom'      => ['nullable', 'numeric', 'min:0', 'max:10'],
            'margin_left'        => ['nullable', 'numeric', 'min:0', 'max:10'],
            // Cabeçalho
            'show_header'         => ['boolean'],
            'header_show_logo'    => ['boolean'],
            'header_show_name'    => ['boolean'],
            'header_show_address' => ['boolean'],
            'header_show_phone'   => ['boolean'],
            // Assinatura
            'show_signature'      => ['boolean'],
            'signature_show_name' => ['boolean'],
            'signature_show_crm'  => ['boolean'],
            'signature_show_rqe'  => ['boolean'],
            // Rodapé
            'show_footer'         => ['boolean'],
            'footer_text'         => ['nullable', 'string', 'max:500'],
            'footer_show_address' => ['boolean'],
            'footer_show_phone'   => ['boolean'],
            'active'              => ['boolean'],
            // Templates de conteúdo
            'contents'           => ['nullable', 'array'],
            'contents.*.type'    => ['required', 'string', 'in:' . implode(',', DocumentationType::values())],
            'contents.*.label'   => ['required', 'string', 'max:255'],
            'contents.*.content' => ['required', 'string'],
            'contents.*.active'  => ['boolean'],
        ]);
    }

    private function buildBreadcrumbs(string $pageTitle): array
    {
        return [
            ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
            ['label' => __('actions.sidemenu.settings'), 'url' => '#', 'active' => false],
            ['label' => __('report_settings.page_title'), 'url' => route('panel.setting.report-settings.index'), 'active' => false],
            ['label' => $pageTitle, 'url' => '#', 'active' => true],
        ];
    }

    /**
     * Achado de segurança (auditoria panel.* IDOR): $reportSetting chega via
     * route model binding, que roda ANTES de tenant.bind — EntityScope fica
     * inerte nesse momento e o binding resolve QUALQUER template, de qualquer
     * entity (inclusive o template GLOBAL, entity_id null). Diferente de
     * assertCanPreviewTemplate() (que permite ver o global antes de adotar),
     * aqui é escrita/exclusão — só o dono (entity_id da própria clínica) pode
     * editar/excluir/reimportar seu próprio template adotado. O global nunca é
     * editável por uma clínica individual (é gerido centralmente).
     */
    private function assertOwnsReportSetting(ReportSetting $reportSetting): void
    {
        abort_unless(
            (string) ($reportSetting->entity_id ?? '') === (string) session('selected_entity_id'),
            404,
        );
    }

    private function assertCanPreviewTemplate(ReportSetting $reportSetting): void
    {
        $selectedEntityId = (string) session('selected_entity_id');
        $ownerEntityId    = (string) ($reportSetting->entity_id ?? '');

        // Modelo da própria clínica
        if ($ownerEntityId !== '' && $ownerEntityId === $selectedEntityId) {
            return;
        }

        // Modelo global publicado/ativo (pré-visualização antes da adoção)
        if ($ownerEntityId === '') {
            abort_if(! $reportSetting->active, 404);
            abort_if($reportSetting->status !== ReportSettingStatus::Published, 404);

            return;
        }

        // Modelo de outra clínica
        abort(404);
    }
}
