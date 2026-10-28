<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Jobs\WhatsApp\SendPhoneVerificationCodeJob;
use App\Models\User;
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppSetting};
use App\Services\WhatsApp\Providers\GupshupAuth;
use App\Services\WhatsApp\{WhatsAppAlerts, WhatsAppService};
use Illuminate\Support\Facades\{Cache, DB};

/**
 * Verificação do WhatsApp do responsável via código OTP — análogo do fluxo
 * MustVerifyEmail, mas pelo canal WhatsApp (app GLOBAL do EasyEye na Gupshup,
 * template de autenticação).
 *
 * Objetivo de produto: contato confirmado nos dois canais (e-mail já coberto
 * pelo Registered event) para o time comercial finalizar a venda do plano e
 * ações futuras de relacionamento via WhatsApp com o responsável da empresa.
 *
 * Segurança:
 *  - Código de 6 dígitos gerado com random_int; no banco só o HMAC-SHA256
 *    com a APP_KEY (sem a chave, o hash de 6 dígitos não se quebra por força
 *    bruta) — e nunca em audit_logs (User::$auditExclude). Código pendente
 *    no formato antigo (sha256 puro, gerado antes do deploy) ainda vale até
 *    expirar (10 min): comparar os dois formatos não enfraquece o novo.
 *  - TTL de 10 minutos; MAX_ATTEMPTS erros invalidam o código (força novo
 *    envio) — camada extra além do throttle das rotas.
 *  - Comparação com hash_equals (timing-safe).
 *  - Envio assíncrono (queue): indisponibilidade do WhatsApp NUNCA quebra o
 *    registro nem o painel.
 */
class PhoneVerificationService
{
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    /**
     * Falhas do lado do DESTINATÁRIO (número sem WhatsApp, descadastrado,
     * não entregável, inativo/sem opt-in na Gupshup, número inválido): o
     * usuário precisa corrigir o número — o gate segue.
     * https://partner-docs.gupshup.io/docs/error-codes ·
     * https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes.
     */
    public const RECIPIENT_FAILURES = [
        'opted_out', 'gupshup_1002', 'gupshup_1006', 'gupshup_1008',
        'meta_131009', 'meta_131021', 'meta_131026', 'meta_131049', 'meta_131050',
    ];

    /**
     * Falhas de CONFIGURAÇÃO do SaaS (lista BRANCA — código desconhecido não
     * libera o gate): app/credencial ausente ou recusada, template
     * inexistente/reprovado/pausado/desativado, app fora da Cloud API, conta
     * restrita, número do EasyEye não registrado, app em modo de teste
     * (131030: destinatário fora da lista de teste). Nem nova tentativa nem
     * novo pedido do usuário resolvem.
     */
    public const CONFIGURATION_FAILURES = [
        'missing_app', 'missing_partner_credentials', 'auth_failed',
        // Gupshup: bot/remetente divergente, template desativado/sem match,
        // parâmetro obrigatório ausente, só Cloud API, template pausado.
        'gupshup_1001', 'gupshup_1004', 'gupshup_1005', 'gupshup_1009', 'gupshup_4003', 'gupshup_4004', 'gupshup_4005',
        // Meta: autenticação/permissão, conta restrita, parâmetro ausente,
        // nome de exibição, forma de pagamento, número não registrado,
        // destinatário fora da lista do app em teste, tipo não suportado.
        'meta_0', 'meta_3', 'meta_10', 'meta_190', 'meta_368', 'meta_131005', 'meta_131008', 'meta_131030', 'meta_131031',
        'meta_131037', 'meta_131042', 'meta_131045', 'meta_131051', 'meta_133010', 'meta_135000',
        // Meta: template (parâmetros, inexistente/não aprovado, tradução,
        // política, formato, pausado, desativado).
        'meta_132000', 'meta_132001', 'meta_132005', 'meta_132007', 'meta_132012', 'meta_132015', 'meta_132016',
    ];

    /**
     * Transitórias que, ESGOTADAS as tentativas do código, mostram que o canal
     * está fora do ar por algo que só o time resolve (token universal
     * recusado, limite de UATs, saldo da carteira): o gate libera.
     */
    public const CHANNEL_FAILURES = ['universal_token_invalid', 'uat_limit', 'gupshup_1003'];

    /**
     * Dá para entregar o código agora? App global ativo com app_id e:
     *  - driver gupshup: credenciais do parceiro no .env, token universal não
     *    vencido (GUPSHUP_UNIVERSAL_TOKEN_EXPIRES_AT ou o "exp" do JWT) e
     *    nenhum alerta recente de canal fora do ar (WhatsAppAlerts:
     *    token recusado, limite de UATs, saldo — 15 min, renovado a cada
     *    alerta);
     *  - driver mock (nada sai): só em APP_ENV=local ou com
     *    WHATSAPP_MOCK_REQUIRES_CODE=true (testes automatizados — phpunit.xml);
     *    nunca em produção. A homologação roda APP_ENV=testing: lá o mock NÃO
     *    exige o código (ninguém o receberia).
     */
    public static function channelAvailable(): bool
    {
        $global = WhatsAppSetting::globalSetting();

        if (! $global || ! $global->isOperational()) {
            return false;
        }

        if (config('whatsapp.driver') === 'gupshup') {
            $auth = app(GupshupAuth::class);

            if (! $auth->configured() || WhatsAppAlerts::channelDown((string) $global->app_id)) {
                return false;
            }

            $expiresAt = $auth->mode() === GupshupAuth::MODE_UNIVERSAL ? $auth->universalTokenExpiresAt() : null;

            return $expiresAt === null || $expiresAt->isFuture();
        }

        return self::mockRequiresCode();
    }

    /** Driver mock: exigir o código (que só aparece no log) só em dev local e nos testes. */
    public static function mockRequiresCode(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return app()->environment('local') || (bool) config('whatsapp.mock_requires_code', false);
    }

    /**
     * Falha permanente por configuração do SaaS (lista branca
     * CONFIGURATION_FAILURES). Transitória, ambígua (unknown_delivery), do
     * destinatário ou desconhecida NÃO contam.
     */
    public static function isConfigurationFailure(?string $errorCode): bool
    {
        return is_string($errorCode) && in_array($errorCode, self::CONFIGURATION_FAILURES, true);
    }

    /**
     * O último envio do código a este usuário falhou por configuração? Então
     * o gate libera (como quando o app global cai) e o time é alertado.
     */
    public function lastDeliveryFailedPermanently(User $user): bool
    {
        $last = WhatsAppMessage::query()
            ->where('kind', WhatsAppMessage::KIND_VERIFICATION)
            ->where('payload->user_id', (string) $user->id)
            ->orderByDesc('created_at')
            ->first(['status', 'error_code']);

        // failed só depois de esgotar as tentativas: config (lista branca)
        // ou canal fora do ar que persistiu (token, UATs, saldo).
        if (
            $last === null
            || $last->status !== WhatsAppMessage::STATUS_FAILED
            || (! self::isConfigurationFailure($last->error_code) && ! in_array($last->error_code, self::CHANNEL_FAILURES, true))
        ) {
            return false;
        }

        // O gate roda a cada request: alerta uma vez por usuário (o e-mail
        // ao time já é limitado a 1 por hora no WhatsAppAlerts).
        if (Cache::add('whatsapp:verification-undeliverable:' . $user->id, 1, now()->addDay())) {
            WhatsAppAlerts::critical('verification_undeliverable', ['error_code' => $last->error_code, 'user_id' => (string) $user->id]);
        }

        return true;
    }

    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', trim($code), (string) config('app.key'));
    }

    /**
     * Gera e envia um novo código para o phone do usuário.
     * Retorna false sem efeitos quando não há número ou o canal não está
     * disponível (channelAvailable — mock só em local/testes automatizados).
     */
    public function sendCode(User $user): bool
    {
        $phone = WhatsAppService::normalizePhone($user->phone);

        if ($phone === null) {
            return false;
        }

        if (! self::channelAvailable()) {
            logger()->info('PhoneVerification: WhatsApp do EasyEye indisponível para o código — envio adiado.', [
                'user_id' => $user->id,
            ]);

            return false;
        }

        $code = (string) random_int(100000, 999999);

        $user->forceFill([
            'phone_verification_code'       => self::hashCode($code),
            'phone_verification_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'phone_verification_attempts'   => 0,
        ])->save();

        SendPhoneVerificationCodeJob::dispatch((string) $user->id, $phone, $code, app()->getLocale());

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

            $stored = (string) $fresh->phone_verification_code;

            // Formato atual (HMAC) ou o antigo (sha256), pendente do deploy.
            if (! hash_equals($stored, self::hashCode($code)) && ! hash_equals($stored, hash('sha256', trim($code)))) {
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
