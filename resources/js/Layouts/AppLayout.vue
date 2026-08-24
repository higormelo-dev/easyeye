<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import logoSvg from '@img/system/logo.svg';
import logoSmallSvg from '@img/system/logo-small.svg';
import logoWhiteSvg from '@img/system/logo-white.svg';
import AiFloatingAssistant from '@/Components/Panel/AiFloatingAssistant.vue';

const props = defineProps({
    title:       { type: String, default: '' },
    breadcrumbs: { type: Array,  default: () => [] },
});

const page      = usePage();
const auth      = computed(() => page.props.auth ?? {});
const user      = computed(() => auth.value.user ?? {});
const entity    = computed(() => auth.value.entity ?? {});
const nav       = computed(() => page.props.nav ?? []);
const locales   = computed(() => page.props.locales ?? []);
const flash     = computed(() => page.props.flash ?? {});
const entities  = computed(() => auth.value.entities ?? []);
const aiAssistant = computed(() => page.props.aiAssistant ?? { enabled: false });

// ── Dark mode ──────────────────────────────────────────────────────────────
const isDark = ref(false);

onMounted(() => {
    isDark.value = document.documentElement.getAttribute('data-bs-theme') === 'dark'
        || document.documentElement.getAttribute('data-sidebar') === 'dark';
});

function toggleDark() {
    isDark.value = !isDark.value;
    const theme = isDark.value ? 'dark' : 'light';
    document.documentElement.setAttribute('data-bs-theme', theme);
    try {
        localStorage.setItem('ee-panel-theme', theme);
        // Manter o config do preclinic-theme-script em sincronia — ele tem
        // precedência no próximo boot (sessionStorage __THEME_CONFIG__) e
        // reaplicaria o tema antigo por cima do que o usuário escolheu aqui.
        const raw = sessionStorage.getItem('__THEME_CONFIG__');
        if (raw) {
            const cfg = JSON.parse(raw);
            cfg.theme = theme;
            sessionStorage.setItem('__THEME_CONFIG__', JSON.stringify(cfg));
        }
        if (window.config) window.config.theme = theme;
    } catch { /* storage indisponível: tema vale só até o reload */ }
}

// ── Session timeout ────────────────────────────────────────────────────────
let warningTimer  = null;
let expireTimer   = null;
let warningShown  = false;
const lifetime    = window.sessionLifetimeMs ?? 120 * 60 * 1000;
const warnBefore  = 2 * 60 * 1000;

function resetTimers() {
    clearTimeout(warningTimer);
    clearTimeout(expireTimer);
    warningShown = false;
    warningTimer = setTimeout(showSessionWarning, lifetime - warnBefore);
    expireTimer  = setTimeout(() => window.location.reload(), lifetime);
}

function showSessionWarning() {
    if (warningShown || typeof Swal === 'undefined') return;
    warningShown = true;
    let remaining = 120;
    Swal.fire({
        title: window.translations?.messages?.session_expiring_title ?? 'Sessão expirando',
        icon: 'warning',
        showCancelButton: true,
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen() {
            const interval = setInterval(() => {
                remaining--;
                if (remaining <= 0) clearInterval(interval);
            }, 1000);
            Swal.getPopup().__countdownInterval = interval;
        },
        willClose() {
            clearInterval(Swal.getPopup().__countdownInterval);
        },
    }).then(result => {
        if (result.isConfirmed) {
            fetch(safeRoute('session.ping'), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            }).then(r => r.ok ? resetTimers() : window.location.reload())
              .catch(() => window.location.reload());
        } else {
            window.location.reload();
        }
    });
}

const sessionEvents = ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'];

onMounted(() => {
    resetTimers();
    sessionEvents.forEach(e => document.addEventListener(e, resetTimers, { passive: true }));
});

onUnmounted(() => {
    clearTimeout(warningTimer);
    clearTimeout(expireTimer);
    sessionEvents.forEach(e => document.removeEventListener(e, resetTimers));
});

// BUG — item de menu com nome de rota inexistente/desatualizado no Ziggy
// (deploy com bundle JS obsoleto, drift entre routes/web.php e
// PanelNavigation.php) fazia `route()` lançar exceção não capturada dentro
// do render do menu. Como o menu é renderizado uma única vez pro array
// inteiro, UM item quebrado derrubava a árvore de componentes inteira —
// tela fica preta/em branco (nada monta, nem header nem conteúdo). Um item
// com rota inválida agora vira link inerte (href="#") em vez de quebrar a
// página toda; erro é reportado ao Sentry para investigação.
function safeRoute(name, params) {
    try {
        return route(name, params);
    } catch (error) {
        window.Sentry?.captureException?.(error, { extra: { route: name } });
        console.error(`[AppLayout] Rota de menu inválida: "${name}"`, error);
        return '#';
    }
}

// ── Sidebar active state ───────────────────────────────────────────────────
function isActive(matchPatterns) {
    // Guard: se o helper route() estiver indisponível/quebrado, item fica
    // inativo em vez de derrubar a árvore (mesma classe de bug do safeRoute).
    let current = null;
    try {
        current = route().current();
    } catch { /* menu sem estado ativo é melhor que tela em branco */ }
    return matchPatterns.some(p => {
        if (p.endsWith('*')) return current?.startsWith(p.slice(0, -2).replace('.*', ''));
        return current === p;
    });
}

function isGroupActive(item) {
    if (item.match && isActive(item.match)) return true;
    if (item.children) return item.children.some(c => c.match && isActive(c.match));
    return false;
}

// ── Sidebar submenu toggle ─────────────────────────────────────────────────
const openMenus = ref({});

onMounted(() => {
    // Pré-abre grupos com rota filha ativa
    nav.value.forEach(item => {
        if (item.children && isGroupActive(item)) {
            openMenus.value[item.key] = true;
        }
    });
});

function toggleMenu(key) {
    openMenus.value = { ...openMenus.value, [key]: !openMenus.value[key] };
}

function isMenuOpen(item) {
    return isGroupActive(item) || !!openMenus.value[item.key];
}

// ── Entity switch ──────────────────────────────────────────────────────────
function switchEntity(entityUserId) {
    router.post(safeRoute('selectentity.store'), { entity_user_id: entityUserId }, {
        preserveScroll: false,
        onSuccess: () => router.visit(safeRoute('panel.dashboard')),
    });
}

function entityInitials(name) {
    return name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase();
}

// ── Logout ─────────────────────────────────────────────────────────────────
function logout() {
    router.post(safeRoute('logout'));
}

// ── Impersonation exit ─────────────────────────────────────────────────────
function exitImpersonation() {
    router.delete(safeRoute('manager.impersonate.destroy'));
}

// ── Flash messages (Vue-controlled + auto-dismiss 6s) ─────────────────────
const flashSuccessOpen = ref(false);
const flashErrorOpen   = ref(false);
let flashSuccessTimer  = null;
let flashErrorTimer    = null;

function dismissSuccess() {
    flashSuccessOpen.value = false;
    clearTimeout(flashSuccessTimer);
}

function dismissError() {
    flashErrorOpen.value = false;
    clearTimeout(flashErrorTimer);
}

// Reabre/reseta o timer sempre que o flash muda (nova navegação Inertia)
watch(
    () => flash.value.success,
    (val) => {
        clearTimeout(flashSuccessTimer);
        if (val) {
            flashSuccessOpen.value = true;
            flashSuccessTimer = setTimeout(() => { flashSuccessOpen.value = false; }, 6000);
        }
    },
    { immediate: true },
);

watch(
    () => flash.value.error,
    (val) => {
        clearTimeout(flashErrorTimer);
        if (val) {
            flashErrorOpen.value = true;
            flashErrorTimer = setTimeout(() => { flashErrorOpen.value = false; }, 6000);
        }
    },
    { immediate: true },
);

onUnmounted(() => {
    clearTimeout(flashSuccessTimer);
    clearTimeout(flashErrorTimer);
});
</script>

<template>
    <div class="main-wrapper">

        <!-- ═══════════════════ HEADER ═══════════════════ -->
        <header class="navbar-header">
            <div class="page-container topbar-menu">
                <div class="d-flex align-items-center gap-2">
                    <a :href="safeRoute('panel.dashboard')" class="logo">
                        <span class="logo-light">
                            <span class="logo-lg"><img :src="logoSvg" alt="EasyEye"></span>
                            <span class="logo-sm"><img :src="logoSmallSvg" alt="EasyEye"></span>
                        </span>
                        <span class="logo-dark">
                            <span class="logo-lg"><img :src="logoWhiteSvg" alt="EasyEye"></span>
                        </span>
                    </a>
                    <a id="mobile_btn" class="mobile-btn" href="#sidebar">
                        <i class="ti ti-menu-deep fs-24"></i>
                    </a>
                    <button class="sidenav-toggle-btn btn border-0 p-0 active" id="toggle_btn2">
                        <i class="ti ti-arrow-right"></i>
                    </button>
                </div>

                <div class="d-flex align-items-center">
                    <!-- Contextual top actions (ex.: IA em páginas clínicas) -->
                    <slot name="top-actions" />

                    <!-- Locale selector -->
                    <div class="header-item" v-if="locales.length > 1">
                        <div class="dropdown me-2">
                            <button class="topbar-link btn btn-icon dropdown-toggle drop-arrow-none"
                                    data-bs-toggle="dropdown" data-bs-offset="0,24">
                                <i class="ti ti-world fs-16"></i>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end p-2">
                                <a v-for="locale in locales" :key="locale.code"
                                   :href="locale.url"
                                   class="dropdown-item"
                                   :class="{ active: locale.active }">
                                    {{ locale.flag }} {{ locale.native }}
                                    <i v-if="locale.active" class="ti ti-check ms-2"></i>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Dark mode -->
                    <div class="header-item d-none d-sm-flex me-2">
                        <button class="topbar-link btn btn-icon" type="button" @click="toggleDark">
                            <i :class="isDark ? 'ti ti-sun fs-16' : 'ti ti-moon fs-16'"></i>
                        </button>
                    </div>

                    <!-- User dropdown -->
                    <div class="dropdown profile-dropdown d-flex align-items-center justify-content-center">
                        <a href="#" class="topbar-link dropdown-toggle drop-arrow-none position-relative"
                           data-bs-toggle="dropdown" data-bs-offset="0,22">
                            <img :src="user.photo_url" width="32" class="rounded-circle d-flex" :alt="user.name">
                            <span class="online text-success">
                                <i class="ti ti-circle-filled d-flex bg-white rounded-circle border border-1 border-white"></i>
                            </span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end dropdown-menu-md p-2">
                            <div class="d-flex align-items-center bg-light rounded-3 p-2 mb-2">
                                <img :src="user.photo_url" class="rounded-circle" width="42" height="42" :alt="user.name">
                                <div class="ms-2">
                                    <p class="fw-medium text-dark mb-0">{{ user.name }}</p>
                                    <span class="d-block fs-13">{{ user.email }}</span>
                                </div>
                            </div>
                            <a :href="safeRoute('panel.profile.edit')" class="dropdown-item">
                                <i class="ti ti-user-circle me-1 align-middle"></i>
                                <span class="align-middle">Editar perfil</span>
                            </a>
                            <!-- Atalhos de configuração — só admin da clínica (mesmo
                                 gate do grupo Configurações da sidebar) -->
                            <template v-if="entity.is_client && entity.rule === 'admin'">
                                <div class="dropdown-divider"></div>
                                <a :href="safeRoute('panel.setting.resources.index')" class="dropdown-item">
                                    <i class="ti ti-settings me-1 align-middle"></i>
                                    <span class="align-middle">Configurações da clínica</span>
                                </a>
                                <a :href="safeRoute('panel.accesscontrol.users.index')" class="dropdown-item">
                                    <i class="ti ti-shield-lock me-1 align-middle"></i>
                                    <span class="align-middle">Usuários e permissões</span>
                                </a>
                            </template>
                            <template v-if="auth.impersonating">
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger" @click="exitImpersonation">
                                    <i class="ti ti-user-x me-1 align-middle"></i>
                                    <span class="align-middle">Sair da impersonação</span>
                                </button>
                            </template>
                            <div class="pt-2 mt-2 border-top">
                                <button type="button" class="dropdown-item text-danger" @click="logout">
                                    <i class="ti ti-logout me-1 fs-17 align-middle"></i>
                                    <span class="align-middle">Sair</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        <!-- ═══════════════════ END HEADER ═══════════════════ -->

        <!-- ═══════════════════ SIDEBAR ═══════════════════ -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-logo">
                <div>
                    <a :href="safeRoute('panel.dashboard')" class="logo logo-normal">
                        <img :src="logoSvg" alt="EasyEye">
                    </a>
                    <a :href="safeRoute('panel.dashboard')" class="logo-small">
                        <img :src="logoSmallSvg" alt="EasyEye">
                    </a>
                    <a :href="safeRoute('panel.dashboard')" class="dark-logo">
                        <img :src="logoWhiteSvg" alt="EasyEye">
                    </a>
                </div>
                <button class="sidenav-toggle-btn btn border-0 p-0 active" id="toggle_btn">
                    <i class="ti ti-arrow-left"></i>
                </button>
                <button class="sidebar-close">
                    <i class="ti ti-x align-middle"></i>
                </button>
            </div>

            <div class="sidebar-inner" data-simplebar style="padding-top:45px">

                <!-- ── Avatar compacto (mini-sidebar) ────────────────────── -->
                <div class="sidebar-mini-avatar">
                    <span class="avatar rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                          style="width:36px;height:36px;font-size:.75rem;"
                          :style="entity.logo_url ? { background: 'transparent' } : {}"
                          :title="entity.name">
                        <img v-if="entity.logo_url"
                             :src="entity.logo_url"
                             :alt="entity.name"
                             class="rounded-circle"
                             style="width:36px;height:36px;object-fit:cover;">
                        <span v-else>{{ entityInitials(entity.name) }}</span>
                    </span>
                </div>

                <!-- ── Seletor de empresa (sidebar expandido) ─────────────── -->
                <div class="sidebar-top p-2 mx-3 mb-3 dropend">
                    <a href="javascript:void(0);"
                       class="drop-arrow-none"
                       data-bs-toggle="dropdown"
                       data-bs-auto-close="outside"
                       data-bs-offset="0,22"
                       aria-haspopup="false"
                       aria-expanded="false">
                        <div class="d-flex align-items-center gap-2">
                            <!-- Logo ou iniciais -->
                            <span class="avatar rounded-circle flex-shrink-0 d-flex align-items-center justify-content-center text-white fw-bold"
                                  style="width:36px;height:36px;font-size:.75rem;"
                                  :style="entity.logo_url ? { background: 'transparent' } : {}">
                                <img v-if="entity.logo_url"
                                     :src="entity.logo_url"
                                     :alt="entity.name"
                                     class="rounded-circle"
                                     style="width:36px;height:36px;object-fit:cover;">
                                <span v-else>{{ entityInitials(entity.name) }}</span>
                            </span>
                            <div class="overflow-hidden flex-grow-1">
                                <h6 class="mb-0 text-truncate">{{ entity.name }}</h6>
                                <p class="fs-11 mb-0 text-truncate">
                                    {{ entity.plan ?? entity.city ?? '—' }}
                                </p>
                            </div>
                            <i class="ti ti-arrows-transfer-up flex-shrink-0"></i>
                        </div>
                    </a>

                    <!-- Dropdown com todas as entidades do usuário -->
                    <div v-if="entities.length > 1" class="dropdown-menu dropdown-menu-lg p-2">
                        <p class="text-muted small px-2 mb-1 fw-medium">Trocar empresa</p>
                        <button v-for="e in entities"
                                :key="e.entity_user_id"
                                type="button"
                                class="dropdown-item d-flex align-items-center justify-content-between rounded-1 p-2"
                                :class="{ 'bg-primary bg-opacity-10': e.is_selected }"
                                @click="switchEntity(e.entity_user_id)">
                            <span class="d-flex align-items-center overflow-hidden">
                                <span class="avatar avatar-xs rounded-circle flex-shrink-0 d-flex align-items-center justify-content-center text-white fw-bold me-2"
                                      style="width:28px;height:28px;font-size:.65rem;background:var(--bs-primary);">
                                    {{ entityInitials(e.name) }}
                                </span>
                                <span class="overflow-hidden">
                                    <span class="fw-semibold text-dark d-block text-truncate" style="font-size:.8125rem;">{{ e.name }}</span>
                                    <small class="text-muted d-block" style="font-size:.72rem;">{{ e.city || '—' }}</small>
                                </span>
                            </span>
                            <i v-if="e.is_selected" class="ti ti-check text-primary flex-shrink-0 ms-2"></i>
                        </button>
                    </div>
                </div>
                <!-- ── Fim seletor de empresa ───────────────────────────── -->

                <div id="sidebar-menu" class="sidebar-menu">
                    <ul>
                        <li class="menu-title"><span>Menu</span></li>
                        <li>
                            <ul>
                                <template v-for="item in nav" :key="item.key ?? item.section">
                                    <!-- Section divider -->
                                    <li v-if="item.section" class="menu-title">
                                        <span>{{ item.section }}</span>
                                    </li>

                                    <!-- Item with submenu -->
                                    <li v-else-if="item.children" class="submenu">
                                        <a href="#"
                                           :class="{ 'subdrop active': isMenuOpen(item) }"
                                           @click.prevent="toggleMenu(item.key)">
                                            <i :class="item.icon"></i>
                                            <span>{{ item.label }}</span>
                                            <span class="menu-arrow"></span>
                                        </a>
                                        <ul :style="isMenuOpen(item) ? 'display:block;' : ''">
                                            <li v-for="child in item.children" :key="child.route">
                                                <a :href="safeRoute(child.route)"
                                                   :class="{ active: isActive(child.match) }">
                                                    <i v-if="child.icon" :class="child.icon + ' me-1'"></i>
                                                    {{ child.label }}
                                                </a>
                                            </li>
                                        </ul>
                                    </li>

                                    <!-- Simple item -->
                                    <li v-else>
                                        <a :href="safeRoute(item.route)"
                                           :class="{ active: isActive(item.match) }">
                                            <i :class="item.icon"></i>
                                            <span>{{ item.label }}</span>
                                        </a>
                                    </li>
                                </template>
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <!-- ═══════════════════ END SIDEBAR ═══════════════════ -->

        <!-- ═══════════════════ PAGE WRAPPER ═══════════════════ -->
        <div class="page-wrapper">

            <!-- Impersonation banner -->
            <div v-if="auth.impersonating" class="alert alert-warning alert-dismissible m-0 rounded-0 border-0 border-bottom d-flex align-items-center gap-2 py-2 px-3">
                <i class="ti ti-user-check fs-16"></i>
                <span>Você está visualizando como <strong>{{ user.name }}</strong> ({{ user.email }})</span>
                <button type="button" class="btn btn-sm btn-warning ms-auto" @click="exitImpersonation">
                    <i class="ti ti-user-x me-1"></i> Sair
                </button>
            </div>

            <!-- Flash messages (Vue-controlled — auto-dismiss 6s + botão X funcional) -->
            <transition name="flash">
                <div v-if="flashSuccessOpen && flash.success" class="alert alert-success m-3 mb-0 d-flex align-items-center gap-2" role="alert">
                    <i class="ti ti-circle-check fs-16"></i>
                    <span class="flex-grow-1">{{ flash.success }}</span>
                    <button type="button" class="btn-close" :aria-label="'Fechar'" @click="dismissSuccess"></button>
                </div>
            </transition>
            <transition name="flash">
                <div v-if="flashErrorOpen && flash.error" class="alert alert-danger m-3 mb-0 d-flex align-items-center gap-2" role="alert">
                    <i class="ti ti-alert-circle fs-16"></i>
                    <span class="flex-grow-1">{{ flash.error }}</span>
                    <button type="button" class="btn-close" :aria-label="'Fechar'" @click="dismissError"></button>
                </div>
            </transition>

            <div class="content">
                <!-- Breadcrumbs -->
                <nav v-if="breadcrumbs.length" aria-label="breadcrumb" class="d-flex justify-content-end mb-2">
                    <ol class="breadcrumb mb-0 app-breadcrumb">
                        <li v-for="(crumb, i) in breadcrumbs" :key="i"
                            class="breadcrumb-item"
                            :class="{ active: crumb.active }">
                            <a v-if="!crumb.active" :href="crumb.url">{{ crumb.label }}</a>
                            <span v-else class="breadcrumb-active">{{ crumb.label }}</span>
                        </li>
                    </ol>
                </nav>

                <!-- Page content -->
                <slot />
            </div>
        </div>
        <!-- ═══════════════════ END PAGE WRAPPER ═══════════════════ -->

        <!-- Assistente Virtual de IA — montado uma única vez aqui (layout
             persistente do Inertia), sobrevive à navegação entre páginas do
             painel sem perder a conversa. Gate (isDoctor + feature) já vem
             resolvido do backend em page.props.aiAssistant.enabled. -->
        <AiFloatingAssistant v-if="aiAssistant.enabled" :ai="aiAssistant" />

    </div>
</template>

<style scoped>
/* Flash messages: fade + slide suave */
.flash-enter-active,
.flash-leave-active { transition: opacity .25s ease, transform .25s ease; }
.flash-enter-from,
.flash-leave-to     { opacity: 0; transform: translateY(-6px); }
</style>
