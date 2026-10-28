import { configureEcho } from '@laravel/echo-vue';

/**
 * Laravel Echo → Reverb (WebSocket). Usado pelo progresso das importações
 * em tempo real (composables/useImportProgress.js) — sem polling HTTP.
 *
 * Sem VITE_REVERB_APP_KEY (ambiente sem Reverb configurado) o Echo não é
 * configurado e as telas mostram só o estado do carregamento da página.
 * Canais privados autenticam em /broadcasting/auth com a sessão + CSRF
 * (meta csrf-token, lida pelo próprio Echo).
 */
if (import.meta.env.VITE_REVERB_APP_KEY) {
    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';

    configureEcho({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT) || 80,
        wssPort: Number(import.meta.env.VITE_REVERB_PORT) || 443,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
