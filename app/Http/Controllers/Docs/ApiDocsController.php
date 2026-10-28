<?php

namespace App\Http\Controllers\Docs;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureApiDocsAccess;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\Yaml\Yaml;

/**
 * Documentação Swagger da API de integradores (/docs/api).
 *
 * O acesso é liberado por senha única (DOCS_API_PASSWORD) com flag de sessão;
 * cada liberação é registrada em log (IP + user agent) para trilha de acesso.
 * As rotas index/spec ficam atrás do middleware EnsureApiDocsAccess.
 */
class ApiDocsController extends Controller
{
    /**
     * Formulário de senha. Fica FORA do middleware (senão geraria loop de
     * redirect), por isso repete o guard de senha não configurada.
     */
    public function showAuthForm(Request $request): View|RedirectResponse
    {
        abort_if(blank(config('docs.api.password')), 404);

        if ($request->session()->get(EnsureApiDocsAccess::SESSION_KEY, false)) {
            return redirect()->route('docs.api.index');
        }

        return view('docs.auth');
    }

    /**
     * Valida a senha e registra o acesso liberado.
     */
    public function authenticate(Request $request): RedirectResponse
    {
        abort_if(blank(config('docs.api.password')), 404);

        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! hash_equals((string) config('docs.api.password'), $validated['password'])) {
            return back()->withErrors(['password' => __('auth.failed')]);
        }

        // Previne fixation: nova sessão antes de marcar como autorizada.
        $request->session()->regenerate();
        $request->session()->put(EnsureApiDocsAccess::SESSION_KEY, true);

        Log::info('Acesso liberado à documentação da API de integradores', [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return redirect()->intended(route('docs.api.index'));
    }

    /**
     * Página do Swagger UI.
     */
    public function index(): View
    {
        return view('docs.swagger');
    }

    /**
     * Spec OpenAPI consumida pelo Swagger UI.
     *
     * O bloco `servers` do YAML é substituído pela lista do ambiente atual
     * (ver servers()) — em deploy, o server de desenvolvimento não aparece.
     */
    public function spec(): JsonResponse
    {
        $path = config('docs.api.spec_path');

        abort_unless(is_file($path), 404);

        $spec            = Yaml::parseFile($path);
        $spec['servers'] = $this->servers();

        return response()->json(
            $spec,
            200,
            ['Cache-Control' => 'no-store'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * Servers do Swagger conforme o ambiente:
     *  - local      → Desenvolvimento (APP_URL) + Homologação + Produção
     *  - production → Produção + Homologação
     *  - demais (testing = teste.easyeye.app, staging…) → Homologação + Produção
     *
     * @return array<int, array{url: string, description: string}>
     */
    private function servers(): array
    {
        $production   = config('docs.api.servers.production');
        $homologation = config('docs.api.servers.homologation');

        return match (true) {
            app()->environment('local') => [
                ['url' => rtrim((string) config('app.url'), '/'), 'description' => 'Desenvolvimento (local)'],
                $homologation,
                $production,
            ],
            app()->environment('production') => [$production, $homologation],
            default                          => [$homologation, $production],
        };
    }

    /**
     * Revoga a autorização da sessão.
     */
    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(EnsureApiDocsAccess::SESSION_KEY);

        return redirect()->route('docs.api.auth');
    }
}
