<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Contracts;

use App\Models\WhatsApp\WhatsAppSetting;
use App\Services\WhatsApp\TemplateMessage;

/**
 * Provedor do WhatsApp oficial (API da Meta). Implementações: GupshupProvider
 * (Partner API da Gupshup) e MockWhatsAppProvider (dev/teste: nada sai).
 * Binding por config('whatsapp.driver') — ver AppServiceProvider.
 *
 * Toda chamada devolve array estruturado e nunca lança em falha esperada:
 *   ok          bool
 *   message_id  id da mensagem no provedor (envio); null quando o provedor
 *               aceitou (2xx) sem devolver o id — error_code no_message_id
 *   error       texto curto e saneado (nunca token/URL com segredo)
 *   error_code  código estável (connection, rate_limited, http_4xx,
 *               meta_132001, gupshup_1002, opted_out, missing_app...)
 *   retryable   true = falha transitória que com certeza não enviou (5xx,
 *               429, falha de conexão antes de enviar): o job tenta de
 *               novo; false = permanente (template inexistente, número
 *               inválido, 4xx de validação) ou ambígua (unknown_delivery:
 *               timeout de leitura — pode ter saído): sem nova tentativa.
 */
interface WhatsAppProvider
{
    public function name(): string;

    /**
     * Garante a autenticação do app no provedor (token em cache, gerado se
     * preciso) SEM enviar nada. Os jobs chamam isto ANTES de marcar a
     * mensagem como `sending`: demora/falha na autenticação (lock de outro
     * worker, token universal recusado) nunca deixa a linha presa em
     * `sending` sem que nada tenha saído. Nunca devolve o token.
     *
     * @return array{ok: bool, error?: string, error_code?: string, retryable?: bool}
     */
    public function authorize(WhatsAppSetting $setting): array;

    /**
     * Mensagem iniciada pela empresa: só template aprovado na Meta.
     *
     * @return array{ok: bool, message_id?: ?string, error?: string, error_code?: ?string, retryable?: bool}
     */
    public function sendTemplate(WhatsAppSetting $setting, string $phone, TemplateMessage $template): array;

    /**
     * Texto livre — só dentro da janela de 24h aberta pela última mensagem
     * do paciente (respostas automáticas).
     *
     * @return array{ok: bool, message_id?: ?string, error?: string, error_code?: ?string, retryable?: bool}
     */
    public function sendSessionText(WhatsAppSetting $setting, string $phone, string $text): array;

    /**
     * Saúde do app/número no provedor (teste do manager).
     *
     * @return array{ok: bool, healthy?: bool, error?: string, error_code?: string}
     */
    public function health(WhatsAppSetting $setting): array;

    /**
     * Registra (ou refaz) a assinatura de webhook do app, com o segredo
     * enviado como header em cada chamada.
     *
     * @return array{ok: bool, subscription_id?: string, error?: string, error_code?: string}
     */
    public function subscribeWebhook(WhatsAppSetting $setting, string $url, string $secret): array;
}
