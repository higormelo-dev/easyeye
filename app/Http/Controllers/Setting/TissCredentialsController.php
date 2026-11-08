<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Domains\Tiss\Models\TissEntityOperatorCredential;
use App\Domains\Tiss\Services\Credentials\{TissCredentialConnectionTester, TissCredentialManager};
use App\Domains\Tiss\Services\Transport\SimulatedTransportGuard;
use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\TissCredentialRequest;
use App\Models\{Covenant, Entity};
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response};

/**
 * Financeiro > Credenciais TISS: acesso ao webservice de cada operadora, por ambiente (homologação e produção), da
 * clínica da sessão. Só o administrador da clínica (rota entity.role:admin + gate ManageTissCredentials).
 *
 * Segredos (senha, token, senha do certificado) nunca saem daqui: a tela recebe só "está salvo". Gravação, arquivos
 * e auditoria em TissCredentialManager; teste de conexão em TissCredentialConnectionTester.
 */
class TissCredentialsController extends Controller
{
    private const OPERATOR_SEARCH_LIMIT = 30;

    public function __construct(
        private readonly TissCredentialManager $manager,
        private readonly TissCredentialConnectionTester $tester,
    ) {
    }

    public function index(Request $request): Response
    {
        $entity = $this->authorizedEntity($request);

        $credentials = TissEntityOperatorCredential::query()
            ->forEntity((string) $entity->id)
            ->with('operator')
            ->get()
            ->sortBy(fn (TissEntityOperatorCredential $credential): string => mb_strtolower((string) $this->operatorName($credential)) . '|' . $credential->environment)
            ->values();

        return Inertia::render('Panel/Settings/TissCredentials/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.financial'), 'url' => '#', 'active' => false],
                ['label' => __('tiss_credentials.title'), 'url' => '#', 'active' => true],
            ],
            'credentials'  => $credentials->map(fn (TissEntityOperatorCredential $credential): array => $this->row($credential)),
            'environments' => TissEntityOperatorCredential::ENVIRONMENTS,
            'transport'    => [
                'simulated'   => SimulatedTransportGuard::simulated(),
                'driver'      => (string) (config('tiss.transport.driver') ?: '—'),
                'environment' => (string) config('tiss.transport.environment', TissEntityOperatorCredential::ENV_PRODUCTION),
                'blocked'     => SimulatedTransportGuard::blocked(),
            ],
            'routes' => [
                'store'     => route('panel.setting.tiss-credentials.store'),
                'operators' => route('panel.setting.tiss-credentials.operators') . '?q=__Q__',
            ],
            't' => trans('tiss_credentials'),
        ]);
    }

    /** Convênios com registro ANS da clínica + os globais, para escolher a operadora da nova credencial. */
    public function operators(Request $request): JsonResponse
    {
        $entity = $this->authorizedEntity($request);
        $data   = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term   = trim((string) ($data['q'] ?? ''));
        $digits = preg_replace('/\D/', '', $term) ?? '';

        $covenants = Covenant::query()
            ->where('active', true)
            ->whereNotNull('ans_registry')
            ->where('ans_registry', '<>', '')
            ->where(fn ($query) => $query->where('entity_id', $entity->id)->orWhereNull('entity_id'))
            ->when($term !== '', fn ($query) => $query->where(function ($where) use ($term, $digits): void {
                $where->whereLikeUnaccent('name', addcslashes($term, '\\%_'));

                if (strlen($digits) >= 3) {
                    $where->orWhere('ans_registry', 'like', $digits . '%');
                }
            }))
            ->orderByRaw('entity_id IS NULL')
            ->orderBy('name')
            ->limit(self::OPERATOR_SEARCH_LIMIT)
            ->get(['id', 'name', 'ans_registry']);

        return response()->json(['data' => $covenants->map(fn (Covenant $covenant): array => [
            'id'        => (string) $covenant->id,
            'label'     => (string) $covenant->name,
            'sub_label' => (string) __('tiss_credentials.status.ans', ['code' => $covenant->ans_registry]),
        ])->values()]);
    }

    public function store(TissCredentialRequest $request): RedirectResponse
    {
        $entity = $this->authorizedEntity($request);

        $this->manager->save($entity, null, $request->validated(), $request->file('certificate'), $request->file('private_key'), $request);

        return back()->with('success', __('tiss_credentials.flash.created'));
    }

    public function update(TissCredentialRequest $request, TissEntityOperatorCredential $credential): RedirectResponse
    {
        $entity = $this->authorizedEntity($request, $credential);

        $this->manager->save($entity, $credential, $request->validated(), $request->file('certificate'), $request->file('private_key'), $request);

        return back()->with('success', __('tiss_credentials.flash.updated'));
    }

    public function destroy(Request $request, TissEntityOperatorCredential $credential): RedirectResponse
    {
        $entity = $this->authorizedEntity($request, $credential);

        $this->manager->delete($entity, $credential, $request);

        return back()->with('success', __('tiss_credentials.flash.deleted'));
    }

    public function test(Request $request, TissEntityOperatorCredential $credential): RedirectResponse
    {
        $this->authorizedEntity($request, $credential);

        $test = $this->tester->test($credential, $request);
        $ok   = in_array($test['result'], ['reachable', 'responded'], true);

        return back()->with($ok ? 'success' : 'error', TissCredentialConnectionTester::describe($test));
    }

    private function authorizedEntity(Request $request, ?TissEntityOperatorCredential $credential = null): Entity
    {
        $entityId = $request->session()->get('selected_entity_id');

        abort_if($entityId === null, 403);

        $entity = Entity::query()->findOrFail($entityId);

        Gate::authorize(EntityGate::ManageTissCredentials->value, $entity);

        // O binding já filtra pela clínica da sessão; conferir de novo custa nada.
        abort_if($credential !== null && (string) $credential->entity_id !== (string) $entity->id, 404);

        return $entity;
    }

    /** Linha da tela: o que está configurado, sem nenhum segredo (nem cifrado). */
    private function row(TissEntityOperatorCredential $credential): array
    {
        $certificate = $credential->metadata['certificate'] ?? null;
        $test        = $credential->metadata['connection_test'] ?? null;
        $validTo     = filled($certificate['valid_to'] ?? null) ? Carbon::parse((string) $certificate['valid_to']) : null;

        return [
            'id'                  => (string) $credential->id,
            'operator'            => $this->operatorName($credential),
            'ans_code'            => $credential->operator?->ans_code,
            'environment'         => $credential->environment,
            'endpoint_submission' => $credential->endpoint_submission,
            'endpoint_status'     => $credential->endpoint_status,
            'effective_endpoint'  => $credential->submissionEndpoint(),
            'username'            => $credential->username,
            'has_password'        => filled($credential->password_encrypted),
            'has_token'           => filled($credential->token_encrypted),
            'has_certificate'     => filled($credential->certificate_path),
            'has_authentication'  => $credential->hasAuthentication(),
            'certificate'         => filled($credential->certificate_path) ? [
                'subject'  => $certificate['subject'] ?? null,
                'valid_to' => $validTo?->format('d/m/Y'),
                'expired'  => $validTo?->isPast() ?? false,
            ] : null,
            'ssl_enabled' => (bool) $credential->ssl_enabled,
            'active'      => (bool) $credential->active,
            'missing'     => $credential->missingForSubmission(),
            'placeholder' => $credential->isPlaceholder(),
            'last_test'   => is_array($test) && isset($test['result']) ? [
                'ok'   => in_array($test['result'], ['reachable', 'responded'], true),
                'text' => TissCredentialConnectionTester::describe($test),
                'at'   => filled($test['at'] ?? null) ? Carbon::parse((string) $test['at'])->format('d/m/Y H:i') : null,
            ] : null,
            'urls' => [
                'update'  => route('panel.setting.tiss-credentials.update', $credential),
                'destroy' => route('panel.setting.tiss-credentials.destroy', $credential),
                'test'    => route('panel.setting.tiss-credentials.test', $credential),
            ],
        ];
    }

    private function operatorName(TissEntityOperatorCredential $credential): ?string
    {
        return $credential->operator?->trade_name ?: $credential->operator?->name;
    }
}
