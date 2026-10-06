<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use InvalidArgumentException;

/**
 * Catálogo dos templates aprovados na Meta (config whatsapp.templates):
 * monta o TemplateMessage de envio, escolhe o idioma e gera o texto
 * equivalente para o histórico (whatsapp_messages.body).
 *
 * Payload dos botões de resposta rápida: identifica a MENSAGEM enviada (UUID
 * interno, não adivinhável) — nunca a consulta ou o paciente:
 *   confirm:<id> · cancel:<id> · survey:<id>:<nota>
 */
final class WhatsAppTemplates
{
    public const ACTION_CONFIRM = 'confirm';

    public const ACTION_CANCEL = 'cancel';

    public const ACTION_SURVEY = 'survey';

    /** Texto mostrado no histórico no lugar do código de verificação. */
    public const MASKED_CODE = '••••••';

    /**
     * @param array<string, scalar|null>                      $values  valores das variáveis do corpo (por nome)
     * @param array{message_id?: string, url_suffix?: string} $context
     */
    public function build(string $key, array $values, string $locale, array $context = []): TemplateMessage
    {
        $config = $this->config($key);

        $body = array_map(
            fn (string $name) => $this->text($values[$name] ?? null),
            $config['body'] ?? [],
        );

        $buttons = [];

        foreach (array_values($config['buttons'] ?? []) as $index => $button) {
            $value = match ($button['type']) {
                'quick_reply' => $this->payload($button, (string) ($context['message_id'] ?? '')),
                'url'         => (string) ($context['url_suffix'] ?? ''),
                'otp'         => (string) ($values['code'] ?? ''),
                default       => throw new InvalidArgumentException("Botão desconhecido no template {$key}."),
            };

            $buttons[] = ['type' => $button['type'], 'index' => $index, 'value' => $value];
        }

        return new TemplateMessage($key, (string) $config['name'], $this->language($locale), array_values($body), $buttons);
    }

    /**
     * Texto equivalente ao template, no idioma do envio — vai para o
     * histórico (whatsapp_messages.body). O código de verificação nunca:
     * aparece mascarado.
     *
     * @param array<string, scalar|null> $values
     */
    public function render(string $key, array $values, string $locale): string
    {
        $this->config($key);

        if (array_key_exists('code', $values)) {
            $values['code'] = self::MASKED_CODE;
        }

        $values = array_map(fn ($v) => $this->text($v), $values);

        return (string) __("whatsapp.templates.{$key}", $values, $this->language($locale) === 'en' ? 'en' : 'pt_BR');
    }

    /**
     * Idioma do template: "en" só para destinatário em inglês E com o template
     * aprovado em inglês (WHATSAPP_TEMPLATE_LANGUAGES); senão pt_BR.
     */
    public function language(string $locale): string
    {
        $available = (array) config('whatsapp.template_languages', ['pt_BR']);

        if (str_starts_with(strtolower($locale), 'en') && in_array('en', $available, true)) {
            return 'en';
        }

        return 'pt_BR';
    }

    /** @return array<string, mixed> */
    public function config(string $key): array
    {
        $config = config("whatsapp.templates.{$key}");

        if (! is_array($config) || blank($config['name'] ?? null)) {
            throw new InvalidArgumentException("Template de WhatsApp não configurado: {$key}.");
        }

        return $config;
    }

    /**
     * Lê o payload de um botão de resposta rápida devolvido no webhook.
     *
     * @return array{action: string, message_id: string, score?: int}|null
     */
    public static function parsePayload(?string $payload): ?array
    {
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

        if (preg_match("/^(confirm|cancel):({$uuid})$/i", trim((string) $payload), $m)) {
            return ['action' => strtolower($m[1]), 'message_id' => strtolower($m[2])];
        }

        if (preg_match("/^survey:({$uuid}):([1-5])$/i", trim((string) $payload), $m)) {
            return ['action' => self::ACTION_SURVEY, 'message_id' => strtolower($m[1]), 'score' => (int) $m[2]];
        }

        return null;
    }

    /** @param array<string, mixed> $button */
    private function payload(array $button, string $messageId): string
    {
        return match ($button['action'] ?? null) {
            self::ACTION_CONFIRM => self::ACTION_CONFIRM . ':' . $messageId,
            self::ACTION_CANCEL  => self::ACTION_CANCEL . ':' . $messageId,
            'score'              => self::ACTION_SURVEY . ':' . $messageId . ':' . (int) ($button['score'] ?? 0),
            default              => throw new InvalidArgumentException('Ação de botão desconhecida.'),
        };
    }

    /**
     * A Meta recusa variável vazia e quebra de linha/tab ou 4+ espaços
     * seguidos dentro do parâmetro: normaliza e usa "—" no vazio.
     */
    private function text(mixed $value): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $text !== '' ? $text : '—';
    }
}
