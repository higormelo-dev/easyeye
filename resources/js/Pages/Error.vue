<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

/**
 * Página de erro HTTP com o visual do sistema — renderizada pelo respond()
 * de bootstrap/app.php para navegação full-page em /panel/* (shell
 * panel-app) e demais rotas web. Standalone de propósito: um 404 fora de
 * grupo de rotas não passa pelo HandleInertiaRequests (sem auth/nav
 * compartilhados), então nada aqui depende de props globais.
 */
const props = defineProps({
    status: { type: Number, required: true },
});

const CONTENT = {
    401: { icon: 'ti-lock',            title: 'Sessão necessária',        text: 'Você precisa entrar no sistema para acessar esta página.' },
    403: { icon: 'ti-shield-lock',     title: 'Acesso negado',            text: 'Seu perfil não tem permissão para acessar esta área. Se você acredita que deveria ter acesso, fale com o administrador da clínica.' },
    404: { icon: 'ti-map-pin-off',     title: 'Página não encontrada',    text: 'O endereço acessado não existe ou foi movido. Confira o link ou volte para o painel.' },
    419: { icon: 'ti-clock-pause',     title: 'Sessão expirada',          text: 'Sua sessão expirou por inatividade. Recarregue a página e entre novamente.' },
    429: { icon: 'ti-hand-stop',       title: 'Muitas tentativas',        text: 'Você fez muitas requisições em pouco tempo. Aguarde alguns instantes e tente de novo.' },
    500: { icon: 'ti-alert-triangle',  title: 'Erro interno',             text: 'Algo deu errado do nosso lado. Nossa equipe já foi notificada — tente novamente em instantes.' },
    503: { icon: 'ti-tool',            title: 'Em manutenção',            text: 'O sistema está em manutenção programada. Voltamos em breve.' },
};

const info = computed(() => CONTENT[props.status] ?? CONTENT[500]);
const showReload = computed(() => [419, 429, 503].includes(props.status));

function goBack() {
    window.history.length > 1 ? window.history.back() : (window.location.href = '/panel/dashboard');
}

function reload() {
    window.location.reload();
}
</script>

<template>
    <Head :title="`${status} — ${info.title}`" />

    <div class="ee-error">
        <div class="ee-error__card">
            <div class="ee-error__icon">
                <i class="ti" :class="info.icon"></i>
            </div>
            <div class="ee-error__status">{{ status }}</div>
            <h1 class="ee-error__title">{{ info.title }}</h1>
            <p class="ee-error__text">{{ info.text }}</p>

            <div class="ee-error__actions">
                <button type="button" class="ee-error__btn ee-error__btn--ghost" @click="goBack">
                    <i class="ti ti-arrow-left"></i> Voltar
                </button>
                <button v-if="showReload" type="button" class="ee-error__btn ee-error__btn--primary" @click="reload">
                    <i class="ti ti-refresh"></i> Recarregar
                </button>
                <a v-else href="/panel/dashboard" class="ee-error__btn ee-error__btn--primary">
                    <i class="ti ti-layout-dashboard"></i> Ir para o painel
                </a>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Auto-suficiente: renderiza igual nos shells panel-app (Bootstrap) e
   app/guest (site) — respeita o dark mode do painel (data-bs-theme). */
.ee-error {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
    background: var(--bs-body-bg, #f6f8fb);
    color: var(--bs-body-color, #1d2939);
    font-family: var(--bs-body-font-family, system-ui, -apple-system, 'Segoe UI', sans-serif);
}
.ee-error__card {
    max-width: 480px;
    width: 100%;
    text-align: center;
    background: var(--bs-card-bg, #fff);
    border: 1px solid var(--bs-border-color, #e4e7ec);
    border-radius: 14px;
    padding: 3rem 2rem 2.5rem;
    box-shadow: 0 8px 30px rgba(16, 24, 40, .06);
}
.ee-error__icon {
    width: 72px;
    height: 72px;
    margin: 0 auto 1rem;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: rgba(37, 99, 235, .1);
    color: #2563eb;
    font-size: 2rem;
}
.ee-error__status {
    font-size: .8rem;
    font-weight: 700;
    letter-spacing: .2em;
    color: var(--bs-secondary-color, #667085);
}
.ee-error__title {
    font-size: 1.5rem;
    font-weight: 700;
    margin: .25rem 0 .5rem;
}
.ee-error__text {
    font-size: .95rem;
    line-height: 1.6;
    color: var(--bs-secondary-color, #475467);
    margin-bottom: 1.75rem;
}
.ee-error__actions {
    display: flex;
    gap: .75rem;
    justify-content: center;
    flex-wrap: wrap;
}
.ee-error__btn {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    padding: .55rem 1.15rem;
    border-radius: 8px;
    font-size: .9rem;
    font-weight: 600;
    text-decoration: none;
    border: 1px solid transparent;
    cursor: pointer;
    transition: filter .15s;
}
.ee-error__btn:hover { filter: brightness(.95); }
.ee-error__btn--primary { background: #2563eb; color: #fff; }
.ee-error__btn--ghost {
    background: transparent;
    color: var(--bs-body-color, #344054);
    border-color: var(--bs-border-color, #d0d5dd);
}
</style>
