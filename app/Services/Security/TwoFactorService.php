<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use PragmaRX\Google2FA\Google2FA;

/**
 * Serviço de Two-Factor Authentication (TOTP) para admins SaaS.
 *
 * Fluxo:
 *  1) generateSecret($user) — cria secret bruto, salva em DB (criptografado
 *     via cast), retorna o secret + QR code SVG para o front renderizar.
 *     two_factor_confirmed_at fica NULL (setup pendente).
 *
 *  2) confirm($user, $code) — usuário digita um código do app; se válido,
 *     gera 10 recovery codes (hashed bcrypt), marca confirmed_at, retorna
 *     os recovery codes em CLEAR (única exibição, padrão Fortify).
 *
 *  3) verify($user, $code) — middleware EnsureTwoFactor chama este método
 *     com o código digitado. Aceita TOTP válido OU recovery code não usado.
 *
 *  4) disable($user) — limpa secret + recovery + confirmed_at.
 *     Requer reason no audit log (chamador implementa).
 *
 * Defesas:
 *  - Window de 1 (≈ 60s tolerância de clock skew). Mais larga abre janela.
 *  - Recovery codes hashed individualmente com bcrypt. Atacante com DB
 *    não consegue listar os códigos.
 *  - Recovery code consumido é removido (uso único).
 */
class TwoFactorService
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * Gera (ou regera) o secret e retorna dados para o front renderizar o QR code.
     * NÃO marca como confirmado — o usuário precisa validar com confirm().
     *
     * @return array{secret: string, qr_svg: string, otpauth: string}
     */
    public function generateSecret(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey(32);

        // CRÍTICO: gravamos via DB::table (não via Eloquent save) para BYPASSAR
        // o dirty-check do Eloquent, que decifra o valor ORIGINAL para comparar
        // com o novo. Se o valor original estiver corrompido (APP_KEY antiga,
        // estado inconsistente), o save() explodiria com DecryptException
        // ANTES de conseguir gravar o novo secret.
        //
        // Encriptação manual via Crypt::encryptString — mesmo formato do cast
        // 'encrypted' (Laravel serializa+criptografa o valor), portanto leituras
        // futuras via cast continuam funcionando normalmente.
        $this->rawUpdateTwoFactorFields($user, [
            'two_factor_secret'         => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
        ]);

        // Refresh do model em memória para refletir o estado pós-update.
        // Importante: leituras subsequentes não devem ver dados stale.
        $user->refresh();

        $appName = (string) config('app.name', 'EasyEye');
        $otpauth = $this->google2fa->getQRCodeUrl($appName, $user->email, $secret);

        $renderer = new ImageRenderer(
            new RendererStyle(220),
            new SvgImageBackEnd(),
        );
        $writer = new Writer($renderer);
        $qrSvg  = $writer->writeString($otpauth);

        return [
            'secret'  => $secret,
            'qr_svg'  => $qrSvg,
            'otpauth' => $otpauth,
        ];
    }

    /**
     * Confirma o setup: usuário digitou um código válido após cadastrar.
     * Gera os recovery codes, marca confirmed_at.
     * Retorna os recovery codes em texto puro (única exibição).
     *
     * @return array{success: bool, recovery_codes?: list<string>}
     */
    public function confirm(User $user, string $code): array
    {
        $secret = $this->safeDecryptSecret($user);

        if ($secret === null) {
            return ['success' => false];
        }

        $valid = $this->google2fa->verifyKey(
            $secret,
            $this->sanitize($code),
            window: 1,
        );

        if (! $valid) {
            return ['success' => false];
        }

        // Gera 10 recovery codes legíveis (formato XXXX-XXXX, base32).
        $plainCodes  = collect(range(1, 10))->map(fn () => $this->newRecoveryCode())->all();
        $hashedCodes = array_map(static fn (string $c) => Hash::make($c), $plainCodes);

        // Raw update — mesmo motivo de generateSecret(): evitar dirty-check do
        // Eloquent que decifra valor original antes de aceitar o novo.
        $this->rawUpdateTwoFactorFields($user, [
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($hashedCodes, JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at'   => now(),
        ]);

        $user->refresh();

        return ['success' => true, 'recovery_codes' => $plainCodes];
    }

    /**
     * Verifica um código (TOTP ou recovery). Usado pelo middleware.
     * Recovery code consumido é removido (uso único).
     */
    public function verify(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        $sanitized = $this->sanitize($code);

        // Tenta TOTP primeiro (caso comum).
        if (preg_match('/^\d{6}$/', $sanitized)) {
            $secret = $this->safeDecryptSecret($user);

            if ($secret === null) {
                return false;
            }

            return (bool) $this->google2fa->verifyKey($secret, $sanitized, window: 1);
        }

        // Recovery code (formato XXXX-XXXX, 8 chars + hífen).
        return $this->consumeRecoveryCode($user, $sanitized);
    }

    /**
     * Desabilita 2FA (limpa todos os campos). O chamador é responsável por
     * registrar o audit_log com reason — isso aqui é só a operação de DB.
     */
    public function disable(User $user): void
    {
        $this->rawUpdateTwoFactorFields($user, [
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
        ]);

        $user->refresh();
    }

    /**
     * Regenera o conjunto de recovery codes (mantém o secret TOTP).
     * Retorna os novos códigos em clear (uma única exibição).
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $plainCodes  = collect(range(1, 10))->map(fn () => $this->newRecoveryCode())->all();
        $hashedCodes = array_map(static fn (string $c) => Hash::make($c), $plainCodes);

        $this->rawUpdateTwoFactorFields($user, [
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($hashedCodes, JSON_THROW_ON_ERROR)),
        ]);

        $user->refresh();

        return $plainCodes;
    }

    /**
     * Consome (remove) um recovery code se ele bater com a lista hashada.
     */
    private function consumeRecoveryCode(User $user, string $code): bool
    {
        try {
            $raw = $user->two_factor_recovery_codes;
        } catch (DecryptException $e) {
            $this->logCorruption($user, 'two_factor_recovery_codes', $e);
            return false;
        }

        if (! $raw) {
            return false;
        }

        try {
            $codes = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! is_array($codes)) {
            return false;
        }

        foreach ($codes as $idx => $hash) {
            if (Hash::check($code, $hash)) {
                unset($codes[$idx]);
                $this->rawUpdateTwoFactorFields($user, [
                    'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($codes), JSON_THROW_ON_ERROR)),
                ]);
                $user->refresh();

                return true;
            }
        }

        return false;
    }

    private function newRecoveryCode(): string
    {
        // 8 caracteres alfanuméricos, separados por hífen (legível ao copiar).
        return Str::upper(Str::random(4) . '-' . Str::random(4));
    }

    private function sanitize(string $code): string
    {
        // Remove espaços e mantém o hífen para recovery codes.
        return preg_replace('/\s+/', '', mb_strtoupper($code, 'UTF-8'));
    }

    /**
     * Acessa `two_factor_secret` decifrando o valor. Retorna null se:
     *   - O campo está vazio (2FA não configurado).
     *   - O valor está corrompido (APP_KEY mudou, valor inserido fora do cast).
     *
     * Defesa em profundidade: NUNCA propaga DecryptException — o middleware
     * de auth não pode quebrar o request inteiro só porque um secret antigo
     * ficou inutilizável. Tratamos como "não habilitado" e logamos para o
     * admin investigar/limpar o registro.
     */
    private function safeDecryptSecret(User $user): ?string
    {
        try {
            $secret = $user->two_factor_secret;
        } catch (DecryptException $e) {
            $this->logCorruption($user, 'two_factor_secret', $e);
            return null;
        }

        return $secret !== null && $secret !== '' ? $secret : null;
    }

    private function logCorruption(User $user, string $field, DecryptException $e): void
    {
        Log::warning('TwoFactor: secret corrompido (DecryptException). Tratando como não habilitado.', [
            'user_id' => $user->id ?? null,
            'field'   => $field,
            'error'   => $e->getMessage(),
        ]);
    }

    /**
     * Grava campos do 2FA via raw query, BYPASSANDO o ciclo save() do Eloquent.
     *
     * Motivo: o `Model::save()` chama `getDirty()` internamente, que por sua vez
     * roda `originalIsEquivalent()` em cada attribute. Para fields com cast
     * `encrypted`, isso DECIFRA o valor ORIGINAL para comparar com o novo. Se
     * o valor antigo no DB estiver corrompido (APP_KEY trocada, valor inserido
     * fora do cast, double-encrypt), a save() explode com DecryptException ANTES
     * mesmo de tentar gravar o valor novo válido — efetivamente travando o usuário.
     *
     * Esta função grava direto via QueryBuilder, sem casts, sem observers,
     * sem audit (já que o domínio de 2FA tem seu próprio audit via AuditLogger
     * no controller, com reason). Os valores DEVEM vir já cifrados quando a
     * coluna espera `encrypted` (usar Crypt::encryptString() no chamador).
     *
     * @param array<string, mixed> $fields
     */
    private function rawUpdateTwoFactorFields(User $user, array $fields): void
    {
        $allowed = ['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'];

        $payload = array_intersect_key($fields, array_flip($allowed));

        if ($payload === []) {
            return;
        }

        $payload['updated_at'] = now();

        DB::table('users')->where('id', $user->id)->update($payload);
    }
}
