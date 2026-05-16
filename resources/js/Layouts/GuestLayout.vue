<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    layoutMode: { type: String, default: 'illustration' }, // 'login-illustration' | 'illustration'
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    illustrationSrc: { type: String, default: '/system/images/auth/reg-illustration-img.png' },
    appName: { type: String, default: 'EasyEye' },
});

const page = usePage();
const locales = computed(() => page.props.locales ?? []);
const activeLocale = computed(() => locales.value.find(l => l.active));
const isLoginIllustration = computed(() => props.layoutMode === 'login-illustration');
const currentYear = new Date().getFullYear();

// ── Dark mode ──────────────────────────────────────────────────
const isDark = ref(false);

onMounted(() => {
    isDark.value = document.documentElement.getAttribute('data-bs-theme') === 'dark';
});

function toggleDark() {
    isDark.value = !isDark.value;
    const theme = isDark.value ? 'dark' : 'light';
    document.documentElement.setAttribute('data-bs-theme', theme);
    localStorage.setItem('ee-guest-theme', theme);
}

// ── Locale dropdown ────────────────────────────────────────────
const showLocale = ref(false);
const localeEl = ref(null);

function handleClickOutside(e) {
    if (localeEl.value && !localeEl.value.contains(e.target)) {
        showLocale.value = false;
    }
}

onMounted(() => document.addEventListener('click', handleClickOutside));
onUnmounted(() => document.removeEventListener('click', handleClickOutside));
</script>

<template>
    <div
        class="main-wrapper auth-bg position-relative overflow-hidden min-vh-100"
        :class="isLoginIllustration ? 'ee-login-illustration-wrapper' : 'ee-auth-illustration-wrapper'"
    >
        <div class="container-fluid p-0">

            <!-- ── Login Illustration (Login page) ── -->
            <template v-if="isLoginIllustration">
                <div class="row g-0 min-vh-100">
                    <!-- Left panel -->
                    <div class="col-lg-7 d-none d-lg-flex align-items-center justify-content-center ee-login-illustration-side">
                        <slot name="left-panel">
                            <img :src="illustrationSrc" class="img-fluid ee-login-illustration-image" :alt="appName">
                        </slot>
                    </div>
                    <!-- Right: form shell (Login.vue provides its own card/logo) -->
                    <div class="col-12 col-lg-5 d-flex align-items-center justify-content-center px-3 px-md-4 py-4 ee-login-form-side">
                        <div class="w-100 ee-auth-shell ee-login-shell">
                            <slot />
                            <!-- Footer -->
                            <div class="border-top pt-3 mt-4 d-flex align-items-center justify-content-center gap-1">
                                <div v-if="locales.length > 1" class="position-relative" ref="localeEl">
                                    <button
                                        type="button"
                                        class="btn btn-link btn-sm text-muted text-decoration-none"
                                        style="font-size:.8rem;"
                                        @click.stop="showLocale = !showLocale"
                                    >
                                        {{ activeLocale?.flag }} {{ activeLocale?.native }}
                                        <i class="ti ti-chevron-down" style="font-size:10px;"></i>
                                    </button>
                                    <div
                                        v-show="showLocale"
                                        class="position-absolute bg-white border rounded shadow-sm py-1"
                                        style="bottom:calc(100% + 4px); right:0; min-width:140px; z-index:200;"
                                    >
                                        <a
                                            v-for="locale in locales"
                                            :key="locale.code"
                                            :href="locale.url"
                                            class="d-flex align-items-center gap-2 px-3 py-2 text-decoration-none"
                                            style="font-size:.85rem; color:#334155;"
                                            :class="{ 'fw-semibold': locale.active }"
                                        >
                                            {{ locale.flag }} {{ locale.native }}
                                            <i v-if="locale.active" class="ti ti-check ms-auto" style="color:var(--teal);"></i>
                                        </a>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm text-muted p-1"
                                    style="font-size:1rem; line-height:1;"
                                    @click="toggleDark"
                                >
                                    <i v-if="isDark" class="ti ti-sun"></i>
                                    <i v-else class="ti ti-moon"></i>
                                </button>
                            </div>
                            <p class="text-center mt-3 mb-0" style="font-size:.8rem; color:#94a3b8;">
                                &copy; {{ currentYear }} {{ appName }}
                            </p>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ── Illustration (all other auth pages) ── -->
            <template v-else>
                <div class="row g-0 min-vh-100">
                    <!-- Left: illustration or custom slot -->
                    <div class="col-lg-7 d-none d-lg-flex align-items-center justify-content-center ee-auth-illustration-side">
                        <slot name="left-panel">
                            <img :src="illustrationSrc" class="img-fluid ee-auth-illustration-image" :alt="appName">
                        </slot>
                    </div>
                    <!-- Right: card -->
                    <div class="col-12 col-lg-5 d-flex align-items-center justify-content-center px-3 px-md-4 py-4 ee-auth-form-side">
                        <div class="w-100 ee-auth-shell ee-auth-illustration-shell">
                            <div class="text-center mb-4">
                                <a href="/login" class="d-inline-block">
                                    <img :src="'/system/images/icons/logo.svg'" class="img-fluid ee-auth-brand" :alt="appName">
                                </a>
                            </div>
                            <div class="card ee-auth-illustration-card">
                                <div class="card-body p-4 p-xl-5 ee-auth-form">
                                    <div v-if="title" class="text-center mb-4 ee-auth-header">
                                        <h4>{{ title }}</h4>
                                        <p v-if="subtitle">{{ subtitle }}</p>
                                    </div>
                                    <slot />
                                    <div class="border-top pt-3 mt-4 d-flex align-items-center justify-content-center gap-1">
                                        <div v-if="locales.length > 1" class="position-relative" ref="localeEl">
                                            <button
                                                type="button"
                                                class="btn btn-link btn-sm text-muted text-decoration-none"
                                                style="font-size:.8rem;"
                                                @click.stop="showLocale = !showLocale"
                                            >
                                                {{ activeLocale?.flag }} {{ activeLocale?.native }}
                                                <i class="ti ti-chevron-down" style="font-size:10px;"></i>
                                            </button>
                                            <div
                                                v-show="showLocale"
                                                class="position-absolute bg-white border rounded shadow-sm py-1"
                                                style="bottom:calc(100% + 4px); right:0; min-width:140px; z-index:200;"
                                            >
                                                <a
                                                    v-for="locale in locales"
                                                    :key="locale.code"
                                                    :href="locale.url"
                                                    class="d-flex align-items-center gap-2 px-3 py-2 text-decoration-none"
                                                    style="font-size:.85rem; color:#334155;"
                                                    :class="{ 'fw-semibold': locale.active }"
                                                >
                                                    {{ locale.flag }} {{ locale.native }}
                                                    <i v-if="locale.active" class="ti ti-check ms-auto" style="color:var(--teal);"></i>
                                                </a>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            class="btn btn-link btn-sm text-muted p-1"
                                            style="font-size:1rem; line-height:1;"
                                            @click="toggleDark"
                                        >
                                            <i v-if="isDark" class="ti ti-sun"></i>
                                            <i v-else class="ti ti-moon"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p class="text-center mt-3 mb-0" style="font-size:.8rem; color:#94a3b8;">
                                &copy; {{ currentYear }} {{ appName }}
                            </p>
                        </div>
                    </div>
                </div>
            </template>

        </div>
    </div>
</template>

<style>
.ee-auth-shell {
    max-width: 560px;
}

.ee-auth-illustration-card {
    border: 1px solid #d8dce7;
    border-radius: 10px;
    box-shadow: none;
}

.ee-auth-header h4 {
    color: #0f172a;
    font-size: 1.35rem;
    font-weight: 700;
    margin-bottom: .35rem;
}

.ee-auth-header p {
    color: #6c7688;
    font-size: .92rem;
    margin-bottom: 0;
}

.ee-auth-form .form-label {
    color: #334155;
    font-size: .88rem;
    font-weight: 600;
    margin-bottom: .45rem;
}

.ee-auth-form .form-control,
.ee-auth-form .form-select {
    border-radius: 10px;
    min-height: 42px;
}

.ee-auth-form .input-group-text {
    border-radius: 10px 0 0 10px;
}

.ee-auth-form .input-group > .form-control {
    border-radius: 0;
}

.ee-auth-form .input-group > .btn {
    border-radius: 0 10px 10px 0;
}

/* ── Layout wrappers ── */
.ee-login-illustration-wrapper {
    background: linear-gradient(90deg, #ebecf5 0 58%, #ffffff 58%);
}
.ee-auth-illustration-wrapper {
    background: linear-gradient(90deg, #ebecf5 0 58%, #ffffff 58%);
}
.ee-login-illustration-side {
    background: #ebecf5;
}
.ee-auth-illustration-side {
    background: #ebecf5;
}
.ee-login-illustration-image,
.ee-auth-illustration-image {
    width: 100%;
    max-width: 680px;
    padding: 2rem;
}
.ee-login-form-side,
.ee-auth-form-side {
    background: #ffffff;
}
.ee-login-shell {
    max-width: 600px;
}
.ee-auth-illustration-shell {
    max-width: 520px;
}
.ee-auth-brand {
    width: 160px;
}

/* ── Login card styles (used by Login.vue) ── */
.ee-login-card {
    border: 1px solid #e7ebf3;
    border-radius: 16px;
    box-shadow: 0 14px 34px rgba(10, 27, 57, 0.08);
}
.ee-login-card-body {
    padding: 2rem;
}
.ee-login-brand {
    height: 36px;
    width: auto;
}
.ee-login-title {
    font-size: 1.45rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: .25rem;
}
.ee-login-subtitle {
    color: #6c7688;
    font-size: .92rem;
    margin-bottom: 0;
}
.ee-login-forgot {
    font-size: .85rem;
    color: var(--teal);
    text-decoration: none;
}
.ee-login-forgot:hover { text-decoration: underline; }
.ee-login-submit {
    border-radius: 10px;
    min-height: 44px;
    font-weight: 600;
}
.ee-login-register {
    font-size: .9rem;
    color: #64748b;
}
.ee-login-register a { color: var(--teal); text-decoration: none; font-weight: 600; }
.ee-login-trust {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid #e2e8f0;
}
.ee-login-trust-item {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    color: #94a3b8;
}

/* ── Forgot-password dark panel ── */
.ee-fp-dark-panel {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem;
    text-align: center;
    background: linear-gradient(160deg, #0a1e4e 0%, #0f3460 60%, #0a2a5e 100%);
}

/* ── Login left panel ── */
.ee-login-panel-wrapper {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem;
    background: linear-gradient(160deg, #0a1e4e 0%, #0f3460 60%, #0a2a5e 100%);
    min-height: 100%;
    position: relative;
    overflow: hidden;
}
.ee-login-panel-wrapper::before {
    content: '';
    position: absolute;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.ee-login-panel-blob {
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
    opacity: .18;
}
.ee-login-panel-blob-1 { width: 320px; height: 320px; top: -80px; right: -60px; background: #00B4D8; }
.ee-login-panel-blob-2 { width: 220px; height: 220px; bottom: -60px; left: 5%; background: #6c63ff; }
.ee-login-panel-logo {
    height: 36px;
    width: auto;
    margin-bottom: 2rem;
    position: relative;
    z-index: 1;
}
.ee-login-panel-img {
    max-width: 320px;
    width: 80%;
    margin-bottom: 2.5rem;
    position: relative;
    z-index: 1;
}
.ee-login-features {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin-bottom: 2rem;
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 380px;
}
.ee-login-feature {
    display: flex;
    align-items: center;
    gap: 12px;
}
.ee-login-feature-ico {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: rgba(0, 180, 216, .15);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: #00B4D8;
    flex-shrink: 0;
}
.ee-login-feature span {
    font-size: 13px;
    color: rgba(255,255,255,.8);
    font-weight: 500;
}
.ee-login-quote {
    border-left: 3px solid rgba(0,180,216,.5);
    padding: 12px 16px;
    position: relative;
    z-index: 1;
    max-width: 380px;
    width: 100%;
}
.ee-login-quote p {
    font-size: 13px;
    color: rgba(255,255,255,.65);
    font-style: italic;
    margin-bottom: 6px;
    line-height: 1.6;
}
.ee-login-quote cite {
    font-size: 12px;
    color: rgba(255,255,255,.4);
    font-style: normal;
}

/* ── Responsive ── */
@media (max-width: 991.98px) {
    .ee-auth-shell { max-width: 640px; }
    .ee-login-shell { max-width: 640px; }
    .ee-auth-illustration-shell { max-width: 560px; }
    .ee-login-illustration-wrapper,
    .ee-auth-illustration-wrapper { background: #ffffff; }
}

/* ── Dark mode ── */
[data-bs-theme="dark"] .main-wrapper.auth-bg { background: #0f1117; }
[data-bs-theme="dark"] .ee-login-illustration-side,
[data-bs-theme="dark"] .ee-auth-illustration-side { background: #1a1d2e; }
[data-bs-theme="dark"] .ee-login-illustration-wrapper,
[data-bs-theme="dark"] .ee-auth-illustration-wrapper {
    background: linear-gradient(90deg, #1a1d2e 0 58%, #0f1117 58%);
}
@media (max-width: 991.98px) {
    [data-bs-theme="dark"] .ee-login-illustration-wrapper,
    [data-bs-theme="dark"] .ee-auth-illustration-wrapper { background: #0f1117; }
}
[data-bs-theme="dark"] .ee-login-form-side,
[data-bs-theme="dark"] .ee-auth-form-side { background: #0f1117; }
[data-bs-theme="dark"] .ee-auth-illustration-card,
[data-bs-theme="dark"] .ee-login-card { border-color: #2d3560; }
[data-bs-theme="dark"] .ee-auth-header h4 { color: #e2e8f0; }
[data-bs-theme="dark"] .ee-auth-header p { color: #94a3b8; }
[data-bs-theme="dark"] .ee-auth-form .form-label { color: #cbd5e1; }
[data-bs-theme="dark"] .ee-login-title { color: #e2e8f0; }
[data-bs-theme="dark"] .ee-login-subtitle { color: #94a3b8; }
</style>
