<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TermVersion;
use App\Services\TermsService;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\DB;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Reaceite dos documentos legais (gate `terms.accepted` do painel da clínica): lista as versões vigentes que o
 * usuário ainda não aceitou e grava o aceite com data, IP e navegador (TermsService::accept — ônus da prova do
 * controlador, LGPD art. 8º, §5º). Mesma regra do cadastro: só vale o aceite do conjunto EXATO de versões que a tela
 * mostrou; se outra versão foi publicada no meio, nada é gravado e a tela volta com o texto novo.
 */
class TermsAcceptanceController extends Controller
{
    public function __construct(private readonly TermsService $terms)
    {
    }

    public function show(Request $request): InertiaResponse|RedirectResponse
    {
        $pending = $this->terms->pendingAcceptances($request->user());

        if ($pending === [] || $this->impersonating()) {
            return redirect()->intended(route('panel.dashboard', absolute: false));
        }

        return Inertia::render('Auth/AcceptTerms', [
            'appName'   => config('app.name', 'EasyEye'),
            'documents' => array_map(fn (TermVersion $version) => [
                'version_id' => (string) $version->id,
                'version'    => $version->version,
                'title'      => __($version->type === 'privacy_policy' ? 'auth.register.terms_doc_privacy_policy' : 'auth.register.terms_doc_terms_of_service'),
                'url'        => route($version->type === 'privacy_policy' ? 'site.privacy' : 'site.terms'),
                'summary'    => $version->summary,
            ], $pending),
            't' => __('auth.terms_reaccept'),
        ])->rootView('guest-app');
    }

    public function store(Request $request): RedirectResponse
    {
        // Quem está "usando como" outro usuário não aceita contrato por ele.
        abort_if($this->impersonating(), 403);

        $request->validate([
            'accepted'      => ['nullable', 'boolean'],
            'version_ids'   => ['nullable', 'array', 'max:10'],
            'version_ids.*' => ['string', 'uuid'],
        ]);

        $user    = $request->user();
        $pending = $this->terms->pendingAcceptances($user);

        if ($pending === []) {
            return redirect()->intended(route('panel.dashboard', absolute: false));
        }

        if (! TermsService::sameVersionSet($request->input('version_ids'), $pending)) {
            return back()->withErrors(['accepted' => __('auth.terms_reaccept.changed')]);
        }

        if (! $request->boolean('accepted')) {
            return back()->withErrors(['accepted' => __('auth.terms_reaccept.required')]);
        }

        DB::transaction(function () use ($user, $pending): void {
            foreach ($pending as $version) {
                $this->terms->accept($user, $version);
            }
        });

        return redirect()->intended(route('panel.dashboard', absolute: false));
    }

    private function impersonating(): bool
    {
        return session()->has('impersonating.entity_user_id');
    }
}
