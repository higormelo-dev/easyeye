<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Security\TwoFactorService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\{Inertia, Response as InertiaResponse};
use PragmaRX\Google2FA\Google2FA;

/**
 * Fluxo de 2FA do usuário:
 *
 *   GET  /security/two-factor/setup     → tela de setup (QR + campo código)
 *   POST /security/two-factor/setup     → gera secret novo (regen)
 *   POST /security/two-factor/confirm   → confirma código + retorna recovery codes
 *   GET  /security/two-factor/verify    → tela de verificação (login flow)
 *   POST /security/two-factor/verify    → consome código e libera sessão
 *   POST /security/two-factor/disable   → desabilita (exige reason)
 *
 * Audit: setup/confirm/disable são registrados via AuditLogger.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $service,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Tela de setup. Se o usuário já tem secret pendente (não confirmado),
     * recarrega o QR a partir dele — evita perder progresso ao refresh.
     *
     * Defesa contra DecryptException:
     *  - Usa getRawOriginal() para detectar presença sem disparar o cast.
     *  - Se houver secret pendente mas a decifração falhar (APP_KEY mudou
     *    entre ambientes), descarta e regenera. Não propaga a exceção,
     *    senão o usuário fica preso sem conseguir abrir a tela.
     */
    public function setup(Request $request): InertiaResponse
    {
        $user = Auth::user();

        $hasPendingSecret = $user->getRawOriginal('two_factor_secret') !== null
            && $user->getRawOriginal('two_factor_confirmed_at') === null;

        $setup = null;

        if ($hasPendingSecret) {
            try {
                $setup = $this->buildSetupResponseFromExisting($user);
            } catch (DecryptException $e) {
                // Secret pendente corrompido — loga, descarta e regenera.
                // O usuário recomeça do zero, que é o único caminho seguro.
                Log::warning('TwoFactor setup: secret pendente corrompido, regenerando.', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
                $setup = null;
            }
        }

        if ($setup === null) {
            $setup = $this->service->generateSecret($user);
        }

        return Inertia::render('Security/TwoFactorSetup', [
            'appName' => config('app.name', 'EasyEye'),
            'secret'  => $setup['secret'],
            'qr_svg'  => $setup['qr_svg'],
            'otpauth' => $setup['otpauth'],
            't'       => trans('two_factor'),
        ]);
    }

    /**
     * Força a regeneração do secret (descartando setup pendente).
     */
    public function regenerateSecret(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $this->service->generateSecret($user);

        return redirect()->route('security.two-factor.setup');
    }

    /**
     * Confirma o setup: usuário digita um código válido após escanear o QR.
     * Retorna os 10 recovery codes (única exibição).
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:7'],
        ]);

        $user   = Auth::user();
        $result = $this->service->confirm($user, (string) $request->input('code'));

        if (! $result['success']) {
            return response()->json([
                'message' => __('manager_hardening.two_factor_invalid'),
            ], 422);
        }

        // Marca a sessão como verificada para esta primeira vez.
        $request->session()->put('two_factor_verified_at', now()->toIso8601String());

        $this->audit->recordAdminAction(
            event: 'security.two_factor.enable',
            targetEntityId: null,
            targetUserId: (string) $user->id,
            auditableType: 'user',
            auditableId: (string) $user->id,
            reason: 'Habilitação inicial de 2FA pelo próprio usuário.',
            newValues: ['method' => 'totp'],
            request: $request,
        );

        return response()->json([
            'message'        => __('two_factor.enabled'),
            'recovery_codes' => $result['recovery_codes'],
        ]);
    }

    /**
     * Tela de verify (login flow). Aparece sempre que a sessão atual ainda
     * não tem `two_factor_verified_at`.
     */
    public function verify(Request $request): InertiaResponse
    {
        return Inertia::render('Security/TwoFactorVerify', [
            'appName' => config('app.name', 'EasyEye'),
            't'       => trans('two_factor'),
        ]);
    }

    /**
     * Verifica o código informado no login flow.
     */
    public function verifyStore(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:20'],
        ]);

        $user = Auth::user();
        $ok   = $this->service->verify($user, (string) $request->input('code'));

        if (! $ok) {
            // Audit: tentativa inválida é sinal de ataque / phishing.
            $this->audit->recordAdminAction(
                event: 'security.two_factor.verify.fail',
                targetEntityId: null,
                targetUserId: (string) $user->id,
                auditableType: 'user',
                auditableId: (string) $user->id,
                reason: 'Tentativa de verificação 2FA inválida.',
                newValues: [],
                request: $request,
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('manager_hardening.two_factor_invalid'),
                ], 422);
            }

            return back()->withErrors([
                'code' => __('manager_hardening.two_factor_invalid'),
            ]);
        }

        $request->session()->put('two_factor_verified_at', now()->toIso8601String());

        if ($request->expectsJson()) {
            return response()->json([
                'message'  => __('two_factor.verified'),
                'redirect' => $this->postVerifyRedirect(),
            ]);
        }

        return redirect()->intended($this->postVerifyRedirect());
    }

    private function postVerifyRedirect(): string
    {
        // Tenta o painel da clínica selecionada; cai para dashboard manager
        // se o usuário entrou direto no painel admin SaaS.
        $url = session()->pull('url.intended');

        if ($url) {
            return $url;
        }

        return route('panel.dashboard');
    }

    private function buildSetupResponseFromExisting($user): array
    {
        $google2fa = new Google2FA();
        $secret    = (string) $user->two_factor_secret;
        $appName   = (string) config('app.name', 'EasyEye');
        $otpauth   = $google2fa->getQRCodeUrl($appName, $user->email, $secret);

        $renderer = new ImageRenderer(
            new RendererStyle(220),
            new SvgImageBackEnd(),
        );
        $writer = new Writer($renderer);

        return [
            'secret'  => $secret,
            'qr_svg'  => $writer->writeString($otpauth),
            'otpauth' => $otpauth,
        ];
    }
}
