<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Contato de suporte do botão no topo do painel da clínica: WhatsApp oficial
 * (config saas.support.whatsapp) com mensagem pré-preenchida ou, na falta de
 * um número válido, o e-mail de suporte (mail.support_address). Sem nenhum
 * dos dois, null — o botão não aparece.
 */
final class SupportContact
{
    /** Dígitos aceitos no número (DDI + DDD + número; E.164 tem no máximo 15). */
    private const int MIN_DIGITS = 10;

    private const int MAX_DIGITS = 15;

    /** @return array{kind: 'whatsapp'|'email', url: string, label: string}|null */
    public static function props(): ?array
    {
        $label = __('support.button_label');
        $app   = (string) config('app.name');
        $phone = preg_replace('/\D+/', '', (string) config('saas.support.whatsapp')) ?? '';

        if (strlen($phone) >= self::MIN_DIGITS && strlen($phone) <= self::MAX_DIGITS) {
            return [
                'kind'  => 'whatsapp',
                'url'   => 'https://wa.me/' . $phone . '?text=' . rawurlencode(__('support.whatsapp_message', ['app' => $app])),
                'label' => $label,
            ];
        }

        $email = trim((string) config('mail.support_address'));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            return [
                'kind'  => 'email',
                'url'   => 'mailto:' . $email . '?subject=' . rawurlencode(__('support.email_subject', ['app' => $app])),
                'label' => $label,
            ];
        }

        return null;
    }
}
