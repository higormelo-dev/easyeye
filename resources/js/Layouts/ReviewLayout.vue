<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { makeTx } from '@/Support/recallRule.js';
import logoIconSvg from '@img/system/icons/logo.svg';

/**
 * ReviewLayout — a moldura da página PÚBLICA do Revisor Clínico (ADR 0002): quem abre o link
 * é uma pessoa de fora, sem conta, e aqui não existe menu, usuário nem clínica. Só marca,
 * idioma e tema.
 *
 * O idioma troca por um endereço próprio (`localeUrl`): o `/locale/{código}` do sistema volta
 * por `back()`, e esta página sai com Referrer-Policy: no-referrer — sem Referer não há para
 * onde voltar. O destino (`current`) vai junto.
 */
const props = defineProps({
    /** Textos da página (grupo `clinical_review.public`). */
    t: { type: Object, required: true },
    /** Qual página está aberta (show|code|done|unavailable): a troca de idioma volta para ela. */
    current: { type: String, default: 'unavailable' },
    localeUrl: { type: String, default: '' },
});

const page = usePage();
const tx = makeTx(() => props.t);

// SSR: o Inertia pré-renderiza esta moldura no Node, onde `document` não existe — nada do navegador é tocado antes de
// montar. O nome do app vem do <html data-app> (o Blade o escreve) e é lido ao montar: o HTML do servidor e a 1ª
// renderização do navegador saem IGUAIS (sem divergência na hidratação), e só então o nome real entra.
const appName = ref('EasyEye');
const locales = computed(() => page.props.locales ?? []);

// O <html lang> vem do servidor na 1ª carga; ao trocar o idioma pela página (Inertia) ele precisa seguir,
// senão o leitor de tela lê o texto em inglês com a pronúncia do português. `immediate` roda também no servidor
// (SSR), onde não há documento para escrever: lá não faz nada.
watch(
    () => page.props.locale,
    (locale) => {
        if (locale && typeof document !== 'undefined') document.documentElement.lang = String(locale).replace('_', '-');
    },
    { immediate: true },
);

// ── Tema (preferência do aparelho; é a mesma chave das telas de acesso) ─────
const isDark = ref(false);

onMounted(() => {
    appName.value = document.documentElement.dataset.app ?? 'EasyEye';
    isDark.value = document.documentElement.getAttribute('data-bs-theme') === 'dark';
});

function toggleTheme() {
    isDark.value = !isDark.value;
    const theme = isDark.value ? 'dark' : 'light';
    document.documentElement.setAttribute('data-bs-theme', theme);

    try {
        localStorage.setItem('ee-guest-theme', theme);
    } catch {
        // Navegador sem armazenamento: o tema vale só nesta página.
    }
}

// ── Idioma ──────────────────────────────────────────────────────────────────
const switching = ref(false);

function switchLocale(locale) {
    if (locale.active || switching.value || !props.localeUrl) return;

    router.post(
        props.localeUrl,
        { locale: locale.code, page: props.current },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (switching.value = true),
            onFinish: () => (switching.value = false),
        },
    );
}

// ── Atalho "ir para o conteúdo" ─────────────────────────────────────────────
function skipToMain() {
    document.getElementById('review-main')?.focus();
}

const year = new Date().getFullYear();
</script>

<template>
    <div class="review-shell min-vh-100 d-flex flex-column bg-body-tertiary" data-testid="review-layout">
        <a
            href="#review-main"
            class="visually-hidden-focusable position-absolute top-0 start-0 m-2 px-3 py-2 bg-body border rounded z-3"
            @click.prevent="skipToMain"
            >{{ tx('layout.skip') }}</a
        >

        <header class="bg-body border-bottom">
            <div class="container d-flex align-items-center gap-3 py-2" style="max-width: 960px">
                <img :src="logoIconSvg" width="32" height="32" alt="" />
                <div class="me-auto lh-sm">
                    <span class="fw-semibold text-body-emphasis">{{ appName }}</span>
                    <span class="d-block small text-body-secondary">{{ tx('layout.tagline') }}</span>
                </div>

                <div
                    v-if="locales.length > 1"
                    class="btn-group btn-group-sm"
                    role="group"
                    :aria-label="tx('layout.language')"
                >
                    <button
                        v-for="locale in locales"
                        :key="locale.code"
                        type="button"
                        class="btn"
                        :class="locale.active ? 'btn-primary' : 'btn-outline-primary'"
                        :aria-pressed="locale.active ? 'true' : 'false'"
                        :lang="locale.code.replace('_', '-')"
                        :disabled="switching"
                        :data-testid="`locale-${locale.code}`"
                        @click="switchLocale(locale)"
                    >
                        {{ locale.native }}
                    </button>
                </div>

                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    :aria-label="isDark ? tx('layout.theme_light') : tx('layout.theme_dark')"
                    data-testid="theme-toggle"
                    @click="toggleTheme"
                >
                    <i class="ti" :class="isDark ? 'ti-sun' : 'ti-moon'" aria-hidden="true"></i>
                </button>
            </div>
        </header>

        <main id="review-main" tabindex="-1" class="container flex-grow-1 py-4" style="max-width: 960px">
            <slot />
        </main>

        <footer class="bg-body border-top py-3">
            <div class="container small text-body-secondary text-center" style="max-width: 960px">
                <p class="mb-1">{{ tx('layout.footer_privacy') }}</p>
                <p class="mb-0">&copy; {{ year }} {{ appName }}</p>
            </div>
        </footer>
    </div>
</template>

<style>
/*
 * Paleta da página PÚBLICA do Revisor. O template do painel usa um cinza de corpo (#6c7688) que dá
 * ~4,3:1 sobre o fundo claro — abaixo do mínimo AA (4,5:1) — e que não muda no tema escuro, além de
 * bordas de campo com ~1,2:1. Esta página é lida por quem não conhece o sistema; por isso fixa
 * cores com contraste de verdade nos dois temas (texto ≥ 4,5:1, bordas de controle ≥ 3:1) pelas
 * variáveis do Bootstrap, só dentro de `.review-shell` (nada vaza para o painel).
 */
.review-shell {
    --bs-body-color: #1f2937;
    --bs-body-color-rgb: 31, 41, 55;
    --bs-secondary-color: #4b5563;
    --bs-secondary-color-rgb: 75, 85, 99;
    --bs-emphasis-color: #0b1220;
    --bs-emphasis-color-rgb: 11, 18, 32;
    --review-control-border: #6b7280;
    --review-accent: #2e37a4;
    --review-accent-rgb: 46, 55, 164;
    --review-danger: #b42318;
    --review-warning: #b54708;

    color: var(--bs-body-color);
}

[data-bs-theme='dark'] .review-shell {
    --bs-body-color: #e5e7eb;
    --bs-body-color-rgb: 229, 231, 235;
    --bs-secondary-color: #b8c0cc;
    --bs-secondary-color-rgb: 184, 192, 204;
    --bs-emphasis-color: #fff;
    --bs-emphasis-color-rgb: 255, 255, 255;
    --review-control-border: #9ca3af;
    --review-accent: #8b9cff;
    --review-accent-rgb: 139, 156, 255;
    --review-danger: #ff8a80;
    --review-warning: #fdb022;
}

/* !important: o tema escuro do template fixa a borda dos campos; sem foco/seleção, ela tem que ser visível. */
.review-shell .form-control:not(:focus),
.review-shell .form-select:not(:focus),
.review-shell .form-check-input:not(:checked):not(:focus) {
    border-color: var(--review-control-border) !important;
}

/* Botão de contorno: o azul do tema claro some no escuro (1,4:1) — usa um tom claro do mesmo azul. */
[data-bs-theme='dark'] .review-shell .btn-outline-primary {
    --bs-btn-color: #b4bffd;
    --bs-btn-border-color: #b4bffd;
    --bs-btn-hover-color: #0b1220;
    --bs-btn-hover-bg: #b4bffd;
    --bs-btn-hover-border-color: #b4bffd;
    --bs-btn-active-color: #0b1220;
    --bs-btn-active-bg: #d4daff;
    --bs-btn-active-border-color: #d4daff;
    --bs-btn-disabled-color: #8e97c4;
    --bs-btn-disabled-border-color: #8e97c4;

    color: var(--bs-btn-color);
    border-color: var(--bs-btn-border-color);
}

[data-bs-theme='dark'] .review-shell .btn-outline-primary:hover,
[data-bs-theme='dark'] .review-shell .btn-outline-primary:active {
    color: var(--bs-btn-hover-color);
    background-color: var(--bs-btn-hover-bg);
}

/* O logotipo é escuro: no tema escuro ele some sobre o cabeçalho; em branco ele continua legível. */
[data-bs-theme='dark'] .review-shell header img {
    filter: brightness(0) invert(1);
}

/* Marcado e com foco: o azul do tema claro some no escuro (2,1:1); aqui o destaque tem 3:1 nos dois temas. */
.review-shell .form-check-input:checked {
    background-color: var(--review-accent);
    border-color: var(--review-accent);
}

.review-shell .form-control:focus,
.review-shell .form-select:focus,
.review-shell .form-check-input:focus {
    border-color: var(--review-accent);
    box-shadow: 0 0 0 0.2rem rgba(var(--review-accent-rgb), 0.4);
}

.review-shell :focus-visible {
    outline: 3px solid var(--review-accent);
    outline-offset: 2px;
}

/*
 * Avisos: o texto fica na cor do corpo (alto contraste) e a gravidade vai na borda lateral E nas
 * palavras do aviso — o template pinta o texto de .alert-danger/.alert-warning em vermelho/amarelo
 * vivos (4:1 e 1,8:1), que não servem para ler.
 */
.review-shell .review-note,
.review-shell .review-alert {
    background-color: var(--bs-body-bg);
    color: var(--bs-body-color);
}

.review-shell .review-alert {
    --review-alert-color: var(--review-danger);

    padding: 1rem;
    border: 1px solid var(--review-alert-color);
    border-left-width: 0.4rem;
    border-radius: 0.375rem;
}

.review-shell .review-alert--warning {
    --review-alert-color: var(--review-warning);
}

.review-shell .review-alert a {
    color: inherit;
    font-weight: 600;
    text-decoration: underline;
}
</style>
