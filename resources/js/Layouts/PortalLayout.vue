<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

/**
 * Layout do Portal de Parceiros (/portal/*).
 * Chrome distinto do painel clínica/manager:
 *  - Gradient teal/blue na navbar (identidade visual de partner)
 *  - Nav simples (Dashboard / Leads / Comissões)
 *  - Avatar + logout
 *
 * Compartilha CSS (vendor + system) com painel — diferente apenas o
 * wrapper, sem sidebar pesada.
 */
const props = defineProps({
    title:    { type: String, default: '' },
});

const page = usePage();
const partner = computed(() => page.props?.partner ?? null);
const flash   = computed(() => page.props?.flash ?? {});

// User dropdown
const showUserMenu = ref(false);
const userMenuEl   = ref(null);

function handleClickOutside(e) {
    if (userMenuEl.value && !userMenuEl.value.contains(e.target)) {
        showUserMenu.value = false;
    }
}

onMounted(() => document.addEventListener('click', handleClickOutside));
onUnmounted(() => document.removeEventListener('click', handleClickOutside));

const flashSuccess = computed(() => flash.value?.success);
const flashError   = computed(() => flash.value?.error);

function isActive(routeNameStart) {
    const current = page.props?.ziggy?.location ?? '';
    if (routeNameStart === 'portal.dashboard') return current.includes('/portal/dashboard');
    if (routeNameStart === 'portal.leads')     return current.includes('/portal/leads');
    if (routeNameStart === 'portal.commissions') return current.includes('/portal/commissions');
    return false;
}
</script>

<template>
    <div class="bg-light min-vh-100">
        <!-- Navbar -->
        <nav
            class="navbar navbar-expand-lg navbar-dark py-2"
            style="background: linear-gradient(135deg, #26a69a 0%, #1565c0 100%);"
        >
            <div class="container-fluid px-4">
                <Link :href="route('portal.dashboard')" class="navbar-brand fw-semibold">
                    <i class="ti ti-handshake me-2"></i>
                    Portal de Parceiros
                </Link>

                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#portalNav">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="portalNav">
                    <ul class="navbar-nav me-auto">
                        <li class="nav-item">
                            <Link
                                :href="route('portal.dashboard')"
                                :class="['nav-link', { 'active fw-semibold': isActive('portal.dashboard') }]"
                            >
                                <i class="ti ti-dashboard me-1"></i>Dashboard
                            </Link>
                        </li>
                        <li class="nav-item">
                            <Link
                                :href="route('portal.leads.index')"
                                :class="['nav-link', { 'active fw-semibold': isActive('portal.leads') }]"
                            >
                                <i class="ti ti-users me-1"></i>Meus Leads
                            </Link>
                        </li>
                        <li class="nav-item">
                            <Link
                                :href="route('portal.commissions.index')"
                                :class="['nav-link', { 'active fw-semibold': isActive('portal.commissions') }]"
                            >
                                <i class="ti ti-coin me-1"></i>Minhas Comissões
                            </Link>
                        </li>
                    </ul>

                    <!-- User menu -->
                    <div v-if="partner" ref="userMenuEl" class="dropdown position-relative">
                        <button
                            type="button"
                            class="btn btn-link text-white d-flex align-items-center gap-2"
                            style="text-decoration:none;"
                            @click.stop="showUserMenu = !showUserMenu"
                        >
                            <span class="rounded-circle d-flex align-items-center justify-content-center bg-white bg-opacity-25" style="width: 32px; height: 32px;">
                                <i class="ti ti-user"></i>
                            </span>
                            <span class="d-none d-md-inline">{{ partner.name }}</span>
                            <i class="ti ti-chevron-down" style="font-size: 12px;"></i>
                        </button>

                        <ul
                            v-if="showUserMenu"
                            class="dropdown-menu dropdown-menu-end show position-absolute"
                            style="right: 0; top: 100%;"
                        >
                            <li class="dropdown-item-text small text-muted">
                                <i class="ti ti-mail me-1"></i>{{ partner.email }}
                            </li>
                            <li v-if="partner.code" class="dropdown-item-text small text-muted">
                                <i class="ti ti-id me-1"></i><code>{{ partner.code }}</code>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <Link
                                    :href="route('logout')"
                                    method="post"
                                    as="button"
                                    class="dropdown-item text-danger"
                                >
                                    <i class="ti ti-logout me-1"></i>Sair
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Flash messages -->
        <div class="container-fluid px-4 pt-3">
            <div v-if="flashSuccess" class="alert alert-success alert-dismissible fade show">
                <i class="ti ti-circle-check me-1"></i>{{ flashSuccess }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <div v-if="flashError" class="alert alert-danger alert-dismissible fade show">
                <i class="ti ti-alert-triangle me-1"></i>{{ flashError }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>

        <!-- Slot principal -->
        <main class="container-fluid px-4 py-3">
            <slot />
        </main>

        <!-- Footer simples -->
        <footer class="text-center py-3 text-muted small">
            <i class="ti ti-copyright me-1"></i>
            {{ new Date().getFullYear() }} EasyEye — Portal de Parceiros
        </footer>
    </div>
</template>
