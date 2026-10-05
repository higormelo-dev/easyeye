<?php

declare(strict_types=1);

namespace App\Contracts\Notifications;

/**
 * Aviso do SaaS para a clínica que também sai pelo WhatsApp (canal
 * App\Notifications\Channels\SaasWhatsAppChannel): texto curto, só o
 * essencial (sem dado de paciente), com link para dentro do sistema. Montado
 * no idioma do aviso, na hora do envio.
 */
interface SendsSaasWhatsApp
{
    public function toSaasWhatsApp(object $notifiable): ?string;
}
