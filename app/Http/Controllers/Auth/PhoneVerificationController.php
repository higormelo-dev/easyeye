<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PhoneVerificationService;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Verificação do WhatsApp do responsável (código OTP) — par do fluxo de
 * verificação de e-mail (verification.*). Rotas autenticadas simples (sem
 * exigir entity na sessão): a verificação pertence ao USUÁRIO, não à clínica.
 *
 * Rate limiting nas rotas (routes/auth.php): reenvio 3/10min, confirmação
 * 6/min — além do MAX_ATTEMPTS por código dentro do service.
 */
class PhoneVerificationController extends Controller
{
    public function __construct(private readonly PhoneVerificationService $service)
    {
    }

    /**
     * Tela de confirmação do WhatsApp — etapa do onboarding entre a
     * verificação de e-mail e o painel (gate phone.verified). Mesmo shell
     * guest-app do verify-email.
     */
    public function show(Request $request): InertiaResponse|RedirectResponse
    {
        $user = $request->user();

        // Nada a verificar (sem telefone ou já confirmado) → segue o fluxo.
        if (blank($user->phone) || $user->phone_verified_at !== null) {
            return redirect()->intended(route('panel.dashboard', absolute: false));
        }

        // Número mascarado: só DDD + últimos 2 dígitos — a tela é pós-login,
        // mas não precisa expor o número completo (shoulder surfing).
        $digits = (string) $user->phone;
        $masked = strlen($digits) >= 6
            ? sprintf('(%s) *****-**%s', substr($digits, 0, 2), substr($digits, -2))
            : '*******';

        return Inertia::render('Auth/VerifyPhone', [
            'appName'     => config('app.name', 'EasyEye'),
            'maskedPhone' => $masked,
        ])->rootView('guest-app');
    }

    /**
     * (Re)envia o código para o número cadastrado no registro.
     */
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->phone_verified_at !== null) {
            return response()->json(['message' => __('auth.phone_verification.already_verified'), 'verified' => true]);
        }

        $sent = $this->service->sendCode($user);

        return response()->json([
            'message' => $sent
                ? __('auth.phone_verification.code_sent')
                : __('auth.phone_verification.unavailable'),
            'sent' => $sent,
        ], $sent ? 200 : 503);
    }

    /**
     * Confirma o código digitado pelo usuário.
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $ok = $this->service->verify($request->user(), $validated['code']);

        if (! $ok) {
            return response()->json(['message' => __('auth.phone_verification.invalid_code')], 422);
        }

        return response()->json(['message' => __('auth.phone_verification.verified'), 'verified' => true]);
    }
}
