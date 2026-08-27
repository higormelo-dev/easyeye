<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Jobs\WhatsApp\SendPhoneVerificationCodeJob;
use App\Models\User;
use App\Models\WhatsApp\WhatsAppSetting;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\DB;

/**
 * Verificação do WhatsApp do responsável via código OTP — análogo do fluxo
 * MustVerifyEmail, mas pelo canal WhatsApp (instância GLOBAL Z-API do SaaS).
 *
 * Objetivo de produto: contato confirmado nos dois canais (e-mail já coberto
 * pelo Registered event) para o time comercial finalizar a venda do plano e
 * ações futuras de relacionamento via WhatsApp com o responsável da empresa.
 *
 * Segurança:
 *  - Código de 6 dígitos gerado com random_int; no banco só o sha256.
 *  - TTL de 10 minutos; MAX_ATTEMPTS erros invalidam o código (força novo
 *    envio) — camada extra além do throttle das rotas.
 *  - Comparação com hash_equals (timing-safe).
 *  - Envio assíncrono (queue): indisponibilidade da Z-API NUNCA quebra o
 *    registro nem o painel.
 */
class PhoneVerificationService
{
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    /**
     * Gera e envia um novo código para o phone do usuário.
     * Retorna false sem efeitos quando não há número ou instância global
     * operacional (mock conta como operacional em dev).
     */
    public function sendCode(User $user): bool
    {
        $phone = WhatsAppService::normalizePhone($user->phone);

        if ($phone === null) {
            return false;
        }

        $setting = WhatsAppSetting::globalSetting();

        if (! $setting || ! $setting->isOperational()) {
            logger()->info('PhoneVerification: instância global de WhatsApp indisponível — envio adiado.', [
                'user_id' => $user->id,
            ]);

            return false;
        }

        $code = (string) random_int(100000, 999999);

        $user->forceFill([
            'phone_verification_code'       => hash('sha256', $code),
            'phone_verification_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'phone_verification_attempts'   => 0,
        ])->save();

        SendPhoneVerificationCodeJob::dispatch($user->id, $phone, $code);

        return true;
    }

    /**
     * Confirma o código digitado. Transação com lock: dois submits paralelos
     * não podem consumir tentativas/validar em corrida.
     */
    public function verify(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            /** @var User $fresh */
            $fresh = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($fresh->phone_verified_at !== null) {
                return true; // idempotente
            }

            if (
                $fresh->phone_verification_code === null
                || $fresh->phone_verification_expires_at === null
                || now()->greaterThan($fresh->phone_verification_expires_at)
                || $fresh->phone_verification_attempts >= self::MAX_ATTEMPTS
            ) {
                return false;
            }

            if (! hash_equals($fresh->phone_verification_code, hash('sha256', trim($code)))) {
                $fresh->increment('phone_verification_attempts');

                return false;
            }

            $fresh->forceFill([
                'phone_verified_at'             => now(),
                'phone_verification_code'       => null,
                'phone_verification_expires_at' => null,
                'phone_verification_attempts'   => 0,
            ])->save();

            return true;
        });
    }
}
