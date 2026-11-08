<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Catálogo dos modelos do WhatsApp, por idioma: texto para a Meta (com {{n}}), prévia, rodapé,
 * rótulos dos botões e exemplos das variáveis.
 */
final class WhatsAppTemplateCatalog
{
    /**
     * Modelos do WhatsApp para a tela (prévia, texto para a Meta, variáveis e
     * botões) em pt_BR e en. Fonte única: config/whatsapp.php (nome, ordem
     * das variáveis, botões, rodapé) + lang/{pt_BR,en}/whatsapp.php
     * (templates.* — o mesmo texto do histórico das mensagens).
     * Usado pela aba Modelos do Manager e pela criação por API (whatsapp:templates:submit).
     *
     * @param list<string> $locales
     *
     * @return list<array<string, mixed>>
     */
    public function entries(array $locales = ['pt_BR', 'en']): array
    {
        return collect((array) config('whatsapp.templates'))
            ->map(function (array $template, string $key) use ($locales) {
                $params  = array_values((array) ($template['body'] ?? []));
                $buttons = array_values((array) ($template['buttons'] ?? []));
                $texts   = [];

                foreach ($locales as $locale) {
                    [$body, $footer, $labels] = $this->splitTemplateText((string) __("whatsapp.templates.{$key}", [], $locale), (bool) ($template['footer'] ?? false));
                    $examples                 = (array) __('whatsapp.manager.template_examples', [], $locale);

                    $meta    = $body;
                    $preview = $body;

                    foreach ($params as $i => $param) {
                        $meta    = str_replace(":{$param}", '{{' . ($i + 1) . '}}', $meta);
                        $preview = str_replace(":{$param}", (string) ($examples[$param] ?? $param), $preview);
                    }

                    $texts[$locale] = [
                        'meta'    => $meta,
                        'preview' => $preview,
                        'footer'  => $footer,
                        'buttons' => collect($buttons)->values()->map(fn (array $button, int $i) => [
                            'type'  => $button['type'],
                            'label' => match ($button['type']) {
                                'url'   => (string) __('whatsapp.manager.template_buttons.url', [], $locale),
                                'otp'   => (string) __('whatsapp.manager.template_buttons.otp', [], $locale),
                                default => (string) ($labels[$i] ?? $button['action'] ?? ''),
                            },
                        ])->all(),
                        'params' => collect($params)->values()->map(fn (string $param, int $i) => [
                            'placeholder' => '{{' . ($i + 1) . '}}',
                            'key'         => $param,
                            'label'       => (string) __("whatsapp.manager.template_params.{$param}", [], $locale),
                            'example'     => (string) ($examples[$param] ?? ''),
                        ])->all(),
                    ];
                }

                return [
                    'key'      => $key,
                    'name'     => $template['name'],
                    'category' => $template['category'] ?? 'UTILITY',
                    'group'    => match (true) {
                        str_starts_with($key, 'saas_')                                 => 'saas',
                        in_array($key, ['verification_code', 'connection_test'], true) => 'registration',
                        default                                                        => 'patients',
                    },
                    'texts' => $texts,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Texto do histórico → [corpo, rodapé, rótulos dos botões]. Linha final
     * "[Confirmar] [Cancelar]" = botões; com rodapé, a linha antes deles é o
     * rodapé ("responda SAIR").
     *
     * @return array{0: string, 1: ?string, 2: list<string>}
     */
    private function splitTemplateText(string $text, bool $hasFooter): array
    {
        $lines  = explode("\n", trim($text));
        $labels = [];

        if ($lines !== [] && str_starts_with(trim((string) end($lines)), '[')) {
            preg_match_all('/\[([^\]]+)\]/u', (string) array_pop($lines), $matches);
            $labels = $matches[1];
        }

        $footer = $hasFooter && count($lines) > 1 ? trim((string) array_pop($lines)) : null;

        return [trim(implode("\n", $lines)), $footer, $labels];
    }
}
