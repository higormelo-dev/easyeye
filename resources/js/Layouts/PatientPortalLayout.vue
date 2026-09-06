<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

/**
 * Layout do Portal do Paciente (/meus-documentos). Chrome próprio, simples:
 *  - Sem seletor de entidade (o paciente vê N clínicas de uma vez, não uma
 *    "entidade ativa" como no painel staff).
 *  - Navbar mínima: nome do paciente + logout.
 *
 * Mesmo espírito do PortalLayout (parceiros), adaptado para o guard "patient".
 */
const props = defineProps({
    patientName: { type: String, default: '' },
});

const page = usePage();
const flash = computed(() => page.props?.flash ?? {});
const flashSuccess = computed(() => flash.value?.success);
const flashError = computed(() => flash.value?.error);

const showUserMenu = ref(false);
const userMenuEl = ref(null);

function handleClickOutside(e) {
    if (userMenuEl.value && !userMenuEl.value.contains(e.target)) {
        showUserMenu.value = false;
    }
}

onMounted(() => document.addEventListener('click', handleClickOutside));
onUnmounted(() => document.removeEventListener('click', handleClickOutside));
</script>

<template>
    <div class="bg-light min-vh-100">
        <!-- Navbar -->
        <nav
            class="navbar navbar-expand navbar-dark py-2"
            style="background: linear-gradient(135deg, #0f766e 0%, #0369a1 100%);"
        >
            <div class="container-fluid px-4">
                <span class="navbar-brand fw-semibold mb-0">
                    <i class="ti ti-heart-handshake me-2"></i>Portal do Paciente
                </span>

                <div ref="userMenuEl" class="dropdown position-relative ms-auto">
                    <button
                        type="button"
                        class="btn btn-link text-white d-flex align-items-center gap-2"
                        style="text-decoration:none;"
                        @click.stop="showUserMenu = !showUserMenu"
                    >
                        <span class="rounded-circle d-flex align-items-center justify-content-center bg-white bg-opacity-25" style="width:32px;height:32px;">
                            <i class="ti ti-user"></i>
                        </span>
                        <span class="d-none d-md-inline">{{ patientName || 'Minha conta' }}</span>
                        <i class="ti ti-chevron-down" style="font-size:12px;"></i>
                    </button>

                    <ul
                        v-if="showUserMenu"
                        class="dropdown-menu dropdown-menu-end show position-absolute"
                        style="right:0; top:100%;"
                    >
                        <li>
                            <Link
                                :href="route('patient-portal.logout')"
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
            {{ new Date().getFullYear() }} EasyEye — Portal do Paciente
        </footer>
    </div>
</template>
