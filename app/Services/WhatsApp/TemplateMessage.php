<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Template pronto para envio (nome na Meta, idioma e valores das variáveis e
 * botões, na ordem cadastrada). Montado por WhatsAppTemplates a partir da
 * config whatsapp.templates.
 *
 * components() devolve o formato da Cloud API da Meta repassado pela
 * Gupshup no envio v3:
 *   https://partner-docs.gupshup.io/reference/post_partner-app-appid-v3-message-10 (botões quick reply)
 *   https://partner-docs.gupshup.io/reference/post_partner-app-appid-v3-message-9 (autenticação/OTP)
 *   https://developers.facebook.com/docs/whatsapp/business-management-api/message-templates/components
 */
final class TemplateMessage
{
    /**
     * @param list<string>                                                            $bodyParams
     * @param list<array{type: 'quick_reply'|'url'|'otp', index: int, value: string}> $buttons
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $language,
        public readonly array $bodyParams,
        public readonly array $buttons,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function components(): array
    {
        $components = [];

        if ($this->bodyParams !== []) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(fn (string $text) => ['type' => 'text', 'text' => $text], $this->bodyParams),
            ];
        }

        foreach ($this->buttons as $button) {
            $components[] = match ($button['type']) {
                'quick_reply' => [
                    'type'       => 'button',
                    'sub_type'   => 'quick_reply',
                    'index'      => (string) $button['index'],
                    'parameters' => [['type' => 'payload', 'payload' => $button['value']]],
                ],
                // URL com sufixo dinâmico e o botão "copiar código" do template
                // de autenticação vão no mesmo formato (sub_type url + text).
                default => [
                    'type'       => 'button',
                    'sub_type'   => 'url',
                    'index'      => (string) $button['index'],
                    'parameters' => [['type' => 'text', 'text' => $button['value']]],
                ],
            };
        }

        return $components;
    }
}
