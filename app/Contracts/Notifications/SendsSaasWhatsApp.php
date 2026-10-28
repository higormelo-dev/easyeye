<?php

declare(strict_types=1);

namespace App\Contracts\Notifications;

/**
 * Aviso do SaaS para a clínica que também sai pelo WhatsApp (canal
 * App\Notifications\Channels\SaasWhatsAppChannel) como TEMPLATE aprovado na
 * Meta (config whatsapp.templates.saas_*): só o essencial (sem dado de
 * paciente) e o link para dentro do sistema no botão do template. Montado no
 * idioma do aviso, na hora do envio.
 */
interface SendsSaasWhatsApp
{
    /**
     * @return array{template: string, values: array<string, scalar|null>, url: string}|null
     */
    public function toSaasWhatsApp(object $notifiable): ?array;
}
