<script setup>
import { Head } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import SiteLayout from '@/Layouts/SiteLayout.vue';

const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    t: { type: Object, default: () => ({}) },      // site translations for SiteLayout
    tAuth: { type: Object, default: () => ({}) },  // auth translations for the form
    plans: { type: Array, default: () => [] },
    trialDays: { type: Number, default: 14 },
    routes: { type: Object, default: () => ({}) },
});

// ── Step state ─────────────────────────────────────────────────
const step = ref(1);

// ── Form data ──────────────────────────────────────────────────
const form = ref({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    company_name: '',
    company_phone: '',
    company_cnpj: '',
    plan_id: '',
});

const errors = ref({});
const loading = ref(false);

// ── Máscaras BR (WhatsApp e CNPJ) ──────────────────────────────
// Formatação client-side apenas — o backend re-normaliza para dígitos
// (RegisterRequest::prepareForValidation) e valida de novo. Sem lib:
// dois campos não justificam dependência (inputmask do package.json é
// legado jQuery, nunca usado nas páginas Vue).
function maskPhone(value) {
    const d = (value || '').replace(/\D/g, '').slice(0, 11);
    if (d.length <= 2)  return d.length ? `(${d}` : '';
    if (d.length <= 6)  return `(${d.slice(0, 2)}) ${d.slice(2)}`;
    if (d.length <= 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
    return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
}

function maskCnpj(value) {
    const d = (value || '').replace(/\D/g, '').slice(0, 14);
    if (d.length <= 2)  return d;
    if (d.length <= 5)  return `${d.slice(0, 2)}.${d.slice(2)}`;
    if (d.length <= 8)  return `${d.slice(0, 2)}.${d.slice(2, 5)}.${d.slice(5)}`;
    if (d.length <= 12) return `${d.slice(0, 2)}.${d.slice(2, 5)}.${d.slice(5, 8)}/${d.slice(8)}`;
    return `${d.slice(0, 2)}.${d.slice(2, 5)}.${d.slice(5, 8)}/${d.slice(8, 12)}-${d.slice(12)}`;
}

function onPhoneInput(event) {
    form.value.company_phone = maskPhone(event.target.value);
}

function onCnpjInput(event) {
    form.value.company_cnpj = maskCnpj(event.target.value);
}

// ── Email availability ─────────────────────────────────────────
const emailAvailable = ref(null);
const emailChecking = ref(false);

async function checkEmailAvailability() {
    if (!form.value.email || !/\S+@\S+\.\S+/.test(form.value.email)) return;
    emailChecking.value = true;
    emailAvailable.value = null;
    try {
        const { data } = await axios.get('/register/check-email', { params: { email: form.value.email } });
        emailAvailable.value = data.available;
        if (!data.available) {
            errors.value.email = props.tAuth.register?.email_taken ?? 'Este e-mail já está cadastrado.';
        } else {
            delete errors.value.email;
        }
    } catch {
        emailAvailable.value = null;
    } finally {
        emailChecking.value = false;
    }
}

// ── Password strength ──────────────────────────────────────────
const strengthColors = ['', '#ef4444', '#f97316', '#eab308', '#22c55e', '#16a34a'];
const strengthLabels = computed(() => [
    '',
    props.tAuth.register?.strength_very_weak  ?? 'Muito fraca',
    props.tAuth.register?.strength_weak       ?? 'Fraca',
    props.tAuth.register?.strength_fair       ?? 'Razoável',
    props.tAuth.register?.strength_strong     ?? 'Forte',
    props.tAuth.register?.strength_very_strong ?? 'Muito forte',
]);

const passwordStrength = computed(() => {
    const p = form.value.password;
    if (!p) return 0;
    let score = 0;
    if (p.length >= 8)              score++;
    if (p.length >= 12)             score++;
    if (/[A-Z]/.test(p))           score++;
    if (/[0-9]/.test(p))           score++;
    if (/[^A-Za-z0-9]/.test(p))   score++;
    return Math.min(score, 5);
});

const passwordStrengthColor = computed(() => strengthColors[passwordStrength.value]);
const passwordStrengthLabel = computed(() => strengthLabels.value[passwordStrength.value]);

// ── Plan selection ─────────────────────────────────────────────
const selectedPlan = ref(props.plans[0]?.id ?? '');

watch(() => props.plans, (plans) => {
    if (plans.length && !selectedPlan.value) selectedPlan.value = plans[0].id;
}, { immediate: true });

const currentPlan = computed(() => props.plans.find(p => p.id === selectedPlan.value) ?? null);

function selectPlan(id) {
    selectedPlan.value = id;
    form.value.plan_id = id;
}

// ── Validation ─────────────────────────────────────────────────
const required = props.tAuth.register?.field_required ?? 'Campo obrigatório.';
const mismatch = props.tAuth.register?.passwords_mismatch ?? 'As senhas não conferem.';

function validateStep1() {
    const e = {};
    if (!form.value.name)     e.name = required;
    if (!form.value.email)    e.email = required;
    if (emailAvailable.value === false) e.email = props.tAuth.register?.email_taken;
    if (!form.value.password) e.password = required;
    if (form.value.password !== form.value.password_confirmation) e.password_confirmation = mismatch;
    errors.value = e;
    return Object.keys(e).length === 0;
}

function validateStep2() {
    const e = {};
    if (!form.value.company_name) e.company_name = required;

    // WhatsApp do responsável: obrigatório — 10-11 dígitos (DDD + número).
    // Backend valida de novo (RegisterRequest) e envia código OTP após o registro.
    const phoneDigits = (form.value.company_phone || '').replace(/\D/g, '');
    if (!phoneDigits) {
        e.company_phone = required;
    } else if (phoneDigits.length < 10 || phoneDigits.length > 11) {
        e.company_phone = props.tAuth.register?.whatsapp_invalid ?? 'Informe um número válido com DDD.';
    }

    errors.value = e;
    return Object.keys(e).length === 0;
}

function nextStep() {
    if (validateStep1()) step.value = 2;
}

function prevStep() {
    step.value = 1;
    errors.value = {};
}

// ── Submit ─────────────────────────────────────────────────────
async function submit() {
    if (!validateStep2()) return;
    loading.value = true;
    try {
        const payload = { ...form.value, plan_id: selectedPlan.value };
        const { data } = await axios.post('/register', payload);
        if (data.redirect) {
            window.location.href = data.redirect;
        }
    } catch (err) {
        if (err.response?.data?.errors) {
            errors.value = Object.fromEntries(
                Object.entries(err.response.data.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v])
            );
            if (errors.value.name || errors.value.email || errors.value.password || errors.value.password_confirmation) {
                step.value = 1;
            }
        }
    } finally {
        loading.value = false;
    }
}

async function quickStart() {
    if (!validateStep2()) return;
    selectPlan(props.plans[0]?.id ?? '');
    await submit();
}

const showPwd1 = ref(false);
const showPwd2 = ref(false);
</script>

<template>
    <Head>
        <title>{{ tAuth.register?.meta_title?.replace(':app', appName) ?? 'Criar conta' }}</title>
        <meta name="description" :content="tAuth.register?.meta_description?.replace(':days', trialDays) ?? ''">
    </Head>

    <SiteLayout :t="t" :app-name="appName" :has-hero="false" :routes="routes">

        <!-- ═══ HERO ═══ -->
        <section class="reg-hero hero">
            <div class="reg-hero-blob reg-hero-blob-1"></div>
            <div class="reg-hero-blob reg-hero-blob-2"></div>
            <div class="container">
                <div class="reg-hero-inner">
                    <div class="reg-hero-badge">
                        <i class="ti ti-sparkles"></i>
                        {{ trialDays }} {{ tAuth.register?.days_free }}
                        &bull; {{ tAuth.register?.no_card }}
                        &bull; {{ tAuth.register?.setup_fast }}
                    </div>
                    <h1 class="reg-hero-title">
                        {{ tAuth.register?.left_headline }}<br>
                        <em>{{ tAuth.register?.left_headline_em }}</em>
                    </h1>
                    <p class="reg-hero-sub">{{ tAuth.register?.left_sub }}</p>
                    <div class="reg-hero-metrics">
                        <div class="reg-hero-metric">
                            <span class="reg-hero-metric-val">500+</span>
                            <span class="reg-hero-metric-label">{{ tAuth.register?.metric_clinics }}</span>
                        </div>
                        <div class="reg-hero-metric">
                            <span class="reg-hero-metric-val">{{ trialDays }}</span>
                            <span class="reg-hero-metric-label">{{ tAuth.register?.days_free }}</span>
                        </div>
                        <div class="reg-hero-metric">
                            <span class="reg-hero-metric-val">97%</span>
                            <span class="reg-hero-metric-label">NPS</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══ FORM SECTION ═══ -->
        <section class="reg-section">
            <div class="container">
                <div class="reg-layout">

                    <!-- ── Marketing column ── -->
                    <div class="reg-marketing">
                        <h2 class="reg-marketing-headline">{{ tAuth.register?.step1_title }}</h2>
                        <p class="reg-marketing-sub">{{ tAuth.register?.left_sub }}</p>

                        <ul class="reg-benefits">
                            <li class="reg-benefit">
                                <div class="reg-benefit-icon"><i class="ti ti-gift"></i></div>
                                <div class="reg-benefit-text">
                                    <strong>{{ tAuth.register?.benefit_trial_title?.replace(':days', trialDays) }}</strong>
                                    <span>{{ tAuth.register?.benefit_trial_text }}</span>
                                </div>
                            </li>
                            <li class="reg-benefit">
                                <div class="reg-benefit-icon"><i class="ti ti-bolt"></i></div>
                                <div class="reg-benefit-text">
                                    <strong>{{ tAuth.register?.benefit_setup_title }}</strong>
                                    <span>{{ tAuth.register?.benefit_setup_text }}</span>
                                </div>
                            </li>
                            <li class="reg-benefit">
                                <div class="reg-benefit-icon"><i class="ti ti-headset"></i></div>
                                <div class="reg-benefit-text">
                                    <strong>{{ tAuth.register?.benefit_support_title }}</strong>
                                    <span>{{ tAuth.register?.benefit_support_text }}</span>
                                </div>
                            </li>
                            <li class="reg-benefit">
                                <div class="reg-benefit-icon"><i class="ti ti-shield-check"></i></div>
                                <div class="reg-benefit-text">
                                    <strong>{{ tAuth.register?.benefit_lgpd_title }}</strong>
                                    <span>{{ tAuth.register?.benefit_lgpd_text }}</span>
                                </div>
                            </li>
                        </ul>

                        <div class="reg-testimonial">
                            <div class="reg-testimonial-stars">★★★★★</div>
                            <p class="reg-testimonial-text">"{{ tAuth.register?.testimonial_text }}"</p>
                            <div class="reg-testimonial-author">
                                <div class="reg-testimonial-avatar">DR</div>
                                <div>
                                    <div class="reg-testimonial-name">{{ tAuth.register?.testimonial_name }}</div>
                                    <div class="reg-testimonial-role">{{ tAuth.register?.testimonial_role }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="reg-trust">
                            <div class="reg-trust-item"><i class="ti ti-lock"></i> <span>SSL 256-bit</span></div>
                            <div class="reg-trust-item"><i class="ti ti-shield-check"></i> <span>LGPD</span></div>
                            <div class="reg-trust-item"><i class="ti ti-award"></i> <span>CFM</span></div>
                            <div class="reg-trust-item"><i class="ti ti-mood-smile"></i> <span>97% NPS</span></div>
                        </div>
                    </div>

                    <!-- ── Wizard card ── -->
                    <div class="reg-card">

                        <!-- Header -->
                        <div class="reg-card-header">
                            <div class="reg-card-title">
                                <span v-if="step === 1">{{ tAuth.register?.step1_title }}</span>
                                <span v-else>{{ tAuth.register?.step2_title }}</span>
                            </div>
                            <div class="reg-card-subtitle">
                                <span v-if="step === 1">{{ tAuth.register?.step1_subtitle?.replace(':days', trialDays) }}</span>
                                <span v-else>{{ tAuth.register?.step2_subtitle }}</span>
                            </div>
                        </div>

                        <!-- Step indicator -->
                        <div class="step-indicator">
                            <div class="step-item" :class="{ active: step === 1, done: step > 1 }">
                                <span class="step-num">
                                    <i v-if="step > 1" class="ti ti-check" style="font-size:13px;"></i>
                                    <template v-else>1</template>
                                </span>
                                <span>{{ tAuth.register?.step_personal }}</span>
                            </div>
                            <div class="step-sep"></div>
                            <div class="step-item" :class="{ active: step === 2 }">
                                <span class="step-num">2</span>
                                <span>{{ tAuth.register?.step_company }}</span>
                            </div>
                        </div>

                        <!-- Progress bar -->
                        <div class="reg-progress">
                            <div class="reg-progress-fill" :style="{ width: step === 1 ? '50%' : '100%' }"></div>
                        </div>

                        <!-- ══ Step 1 ══ -->
                        <Transition name="reg-fade" mode="out-in">
                            <div v-if="step === 1" key="step1">
                                <form @submit.prevent="nextStep" novalidate>

                                    <div class="reg-field">
                                        <label class="reg-label">{{ tAuth.register?.name }} <span class="req">*</span></label>
                                        <input
                                            v-model="form.name"
                                            type="text"
                                            class="reg-input"
                                            :class="{ 'is-error': errors.name }"
                                            autocomplete="name"
                                            autofocus
                                        >
                                        <span v-if="errors.name" class="reg-error">{{ errors.name }}</span>
                                    </div>

                                    <div class="reg-field">
                                        <label class="reg-label">{{ tAuth.register?.email }} <span class="req">*</span></label>
                                        <div class="reg-input-group">
                                            <input
                                                v-model="form.email"
                                                type="email"
                                                class="reg-input"
                                                :class="{
                                                    'is-error': errors.email,
                                                    'is-success': emailAvailable === true && !emailChecking,
                                                }"
                                                autocomplete="username"
                                                @blur="checkEmailAvailability"
                                            >
                                            <div class="reg-input-addon">
                                                <span v-if="emailChecking" class="ee-spin" style="color:#94a3b8; display:flex;">
                                                    <i class="ti ti-loader-2"></i>
                                                </span>
                                                <i v-else-if="emailAvailable === true" class="ti ti-circle-check" style="color:var(--mint);"></i>
                                                <i v-else-if="emailAvailable === false" class="ti ti-circle-x" style="color:#ef4444;"></i>
                                                <i v-else class="ti ti-mail" style="color:#94a3b8;"></i>
                                            </div>
                                        </div>
                                        <span v-if="errors.email" class="reg-error">{{ errors.email }}</span>
                                    </div>

                                    <div class="reg-field">
                                        <label class="reg-label">{{ tAuth.register?.password }} <span class="req">*</span></label>
                                        <div class="reg-input-group" style="position:relative;">
                                            <input
                                                v-model="form.password"
                                                :type="showPwd1 ? 'text' : 'password'"
                                                class="reg-input"
                                                :class="{ 'is-error': errors.password }"
                                                autocomplete="new-password"
                                            >
                                            <button type="button" class="reg-toggle-btn" tabindex="-1" @click="showPwd1 = !showPwd1">
                                                <i :class="showPwd1 ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                                            </button>
                                        </div>
                                        <span v-if="errors.password" class="reg-error">{{ errors.password }}</span>
                                        <div v-if="form.password" class="pwd-strength">
                                            <div class="pwd-bars">
                                                <div
                                                    v-for="i in 5"
                                                    :key="i"
                                                    class="pwd-bar"
                                                    :style="i <= passwordStrength ? { background: passwordStrengthColor } : {}"
                                                ></div>
                                            </div>
                                            <span class="pwd-strength-label" :style="{ color: passwordStrengthColor }">
                                                {{ passwordStrengthLabel }}
                                            </span>
                                        </div>
                                    </div>

                                    <div class="reg-field">
                                        <label class="reg-label">{{ tAuth.register?.confirm_password }} <span class="req">*</span></label>
                                        <div class="reg-input-group">
                                            <input
                                                v-model="form.password_confirmation"
                                                :type="showPwd2 ? 'text' : 'password'"
                                                class="reg-input"
                                                :class="{ 'is-error': errors.password_confirmation }"
                                                autocomplete="new-password"
                                            >
                                            <button type="button" class="reg-toggle-btn" tabindex="-1" @click="showPwd2 = !showPwd2">
                                                <i :class="showPwd2 ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                                            </button>
                                        </div>
                                        <span v-if="errors.password_confirmation" class="reg-error">{{ errors.password_confirmation }}</span>
                                    </div>

                                    <button type="submit" class="reg-btn reg-btn-primary" style="margin-top:8px;">
                                        {{ tAuth.register?.next }}
                                        <i class="ti ti-arrow-right"></i>
                                    </button>
                                </form>

                                <div class="reg-card-footer">
                                    {{ tAuth.register?.already_registered }}
                                    <a :href="routes.go ?? '/go'">{{ tAuth.register?.log_in }}</a>
                                </div>
                            </div>

                            <!-- ══ Step 2 ══ -->
                            <div v-else key="step2">
                                <form @submit.prevent="submit" novalidate>

                                    <div class="reg-field">
                                        <label class="reg-label">{{ tAuth.register?.company_name }} <span class="req">*</span></label>
                                        <input
                                            v-model="form.company_name"
                                            type="text"
                                            class="reg-input"
                                            :class="{ 'is-error': errors.company_name }"
                                            autocomplete="organization"
                                        >
                                        <span v-if="errors.company_name" class="reg-error">{{ errors.company_name }}</span>
                                    </div>

                                    <div class="reg-field">
                                        <label class="reg-label">
                                            {{ tAuth.register?.whatsapp ?? 'WhatsApp do responsável' }}
                                            <span class="req">*</span>
                                        </label>
                                        <input
                                            :value="form.company_phone"
                                            type="tel"
                                            class="reg-input"
                                            :class="{ 'is-error': errors.company_phone }"
                                            autocomplete="tel"
                                            placeholder="(00) 00000-0000"
                                            maxlength="15"
                                            @input="onPhoneInput"
                                        >
                                        <span v-if="errors.company_phone" class="reg-error">{{ errors.company_phone }}</span>
                                        <span v-else class="reg-hint">{{ tAuth.register?.whatsapp_hint ?? 'Enviaremos um código de confirmação por WhatsApp.' }}</span>
                                    </div>

                                    <div class="reg-field">
                                        <label class="reg-label">
                                            {{ tAuth.register?.cnpj }}
                                            <span class="opt">({{ tAuth.register?.optional }})</span>
                                        </label>
                                        <input
                                            :value="form.company_cnpj"
                                            type="text"
                                            inputmode="numeric"
                                            class="reg-input"
                                            :class="{ 'is-error': errors.company_cnpj }"
                                            maxlength="18"
                                            placeholder="00.000.000/0000-00"
                                            @input="onCnpjInput"
                                        >
                                        <span v-if="errors.company_cnpj" class="reg-error">{{ errors.company_cnpj }}</span>
                                    </div>

                                    <div v-if="plans.length" class="reg-field">
                                        <label class="reg-label">{{ tAuth.register?.choose_plan }}</label>
                                        <div class="plan-grid">
                                            <div
                                                v-for="plan in plans"
                                                :key="plan.id"
                                                class="plan-grid-card"
                                                :class="{ selected: selectedPlan === plan.id }"
                                                @click="selectPlan(plan.id)"
                                            >
                                                <div class="plan-grid-badge">{{ trialDays }} {{ tAuth.register?.days_free }}</div>
                                                <div class="plan-grid-name">{{ plan.name }}</div>
                                                <div class="plan-grid-price">
                                                    R$ {{ plan.is_free ? '0,00' : Number(plan.price).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) }}
                                                </div>
                                                <div class="plan-grid-cycle">/ {{ plan.price_period_label }}</div>
                                            </div>
                                        </div>

                                        <div v-if="currentPlan && currentPlan.features?.length" class="plan-detail">
                                            <ul class="plan-features">
                                                <li v-for="feat in currentPlan.features" :key="feat.id">
                                                    <span v-if="feat.enabled !== undefined" :style="feat.enabled ? 'color:var(--mint)' : 'color:#ef4444'">
                                                        {{ feat.enabled ? '✓' : '✗' }}
                                                    </span>
                                                    {{ ' ' + feat.display_label }}
                                                </li>
                                            </ul>
                                        </div>
                                    </div>

                                    <div class="reg-btn-row">
                                        <button type="button" class="reg-btn reg-btn-secondary" @click="prevStep">
                                            <i class="ti ti-arrow-left"></i> {{ tAuth.register?.back }}
                                        </button>
                                        <button type="submit" class="reg-btn reg-btn-primary" :disabled="loading">
                                            <span v-if="!loading">
                                                {{ tAuth.register?.start_trial }}
                                                <i class="ti ti-rocket"></i>
                                            </span>
                                            <span v-else>
                                                <i class="ti ti-loader-2 ee-spin me-1"></i>
                                                {{ tAuth.register?.processing }}
                                            </span>
                                        </button>
                                    </div>

                                    <template v-if="plans.length > 1">
                                        <div class="reg-divider"><span>{{ tAuth.register?.or }}</span></div>
                                        <button type="button" class="reg-quick-start" :disabled="loading" @click="quickStart">
                                            {{ tAuth.register?.quick_start }} {{ plans[0]?.name }} &rarr;
                                        </button>
                                    </template>

                                </form>
                            </div>
                        </Transition>

                        <div class="reg-card-trust">
                            <div class="reg-card-trust-item"><i class="ti ti-lock"></i> <span>SSL</span></div>
                            <div class="reg-card-trust-item"><i class="ti ti-shield-check"></i> <span>LGPD</span></div>
                            <div class="reg-card-trust-item"><i class="ti ti-award"></i> <span>CFM</span></div>
                            <div class="reg-card-trust-item"><i class="ti ti-mood-smile"></i> <span>97% NPS</span></div>
                        </div>

                    </div>

                </div>
            </div>
        </section>

    </SiteLayout>
</template>

<style>
/* Spinner */
.ee-spin { display: inline-block; animation: eeSpin 1s linear infinite; }
@keyframes eeSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

/* Fade transition */
.reg-fade-enter-active, .reg-fade-leave-active { transition: opacity .2s, transform .2s; }
.reg-fade-enter-from, .reg-fade-leave-to { opacity: 0; transform: translateY(4px); }

/* Hero */
.reg-hero {
    background: linear-gradient(135deg, #0F2551 0%, #1B3A6B 50%, #0F2551 100%);
    padding: 120px 0 72px;
    position: relative;
    overflow: hidden;
}
.reg-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.025'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.reg-hero-blob { position: absolute; border-radius: 50%; filter: blur(80px); opacity: .2; }
.reg-hero-blob-1 { width: 400px; height: 400px; top: -120px; right: -60px; background: var(--teal); }
.reg-hero-blob-2 { width: 280px; height: 280px; bottom: -80px; left: 8%; background: #6c63ff; }
.reg-hero-inner { position: relative; z-index: 1; text-align: center; max-width: 680px; margin: 0 auto; }
.reg-hero-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(0,180,216,.15); color: var(--teal);
    border: 1px solid rgba(0,180,216,.3);
    padding: 6px 16px; border-radius: 999px; font-size: 13px; font-weight: 600; margin-bottom: 20px;
}
.reg-hero-title { font-size: clamp(28px,4.5vw,48px); font-weight: 900; color: #fff; line-height: 1.1; letter-spacing: -.02em; margin-bottom: 16px; }
.reg-hero-title em { font-style: normal; color: var(--teal); }
.reg-hero-sub { font-size: 17px; color: rgba(255,255,255,.7); line-height: 1.7; margin-bottom: 36px; }
.reg-hero-metrics { display: inline-flex; align-items: stretch; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12); border-radius: 12px; overflow: hidden; }
.reg-hero-metric { padding: 14px 24px; text-align: center; border-right: 1px solid rgba(255,255,255,.1); }
.reg-hero-metric:last-child { border-right: none; }
.reg-hero-metric-val { display: block; font-size: 22px; font-weight: 900; color: var(--teal); line-height: 1; margin-bottom: 4px; }
.reg-hero-metric-label { font-size: 11px; color: rgba(255,255,255,.45); text-transform: uppercase; letter-spacing: .05em; font-weight: 600; }

/* Form section */
.reg-section { background: var(--bg); padding: 64px 0 80px; }
.reg-layout { display: grid; grid-template-columns: 1fr 420px; gap: 48px; align-items: start; }
.reg-marketing { padding-top: 8px; }
.reg-marketing-headline { font-size: 22px; font-weight: 800; color: var(--navy); margin-bottom: 8px; line-height: 1.25; }
.reg-marketing-sub { font-size: 15px; color: var(--text-muted); line-height: 1.65; margin-bottom: 32px; }
.reg-benefits { list-style: none; display: flex; flex-direction: column; gap: 16px; margin-bottom: 36px; }
.reg-benefit { display: flex; align-items: flex-start; gap: 14px; }
.reg-benefit-icon { width: 40px; height: 40px; border-radius: 10px; background: rgba(0,180,216,.1); display: flex; align-items: center; justify-content: center; font-size: 18px; color: var(--teal); flex-shrink: 0; margin-top: 2px; }
.reg-benefit-text strong { display: block; font-size: 14px; font-weight: 700; color: var(--navy); margin-bottom: 3px; }
.reg-benefit-text span { font-size: 13px; color: var(--text-muted); line-height: 1.55; }
.reg-testimonial { background: #fff; border: 1px solid var(--border); border-radius: var(--radius-lg, 12px); padding: 22px 24px; box-shadow: var(--shadow, 0 2px 8px rgba(0,0,0,.06)); }
.reg-testimonial-stars { color: #f59e0b; font-size: 13px; margin-bottom: 10px; letter-spacing: 2px; }
.reg-testimonial-text { font-size: 14px; color: var(--text); line-height: 1.65; font-style: italic; margin-bottom: 14px; }
.reg-testimonial-author { display: flex; align-items: center; gap: 12px; }
.reg-testimonial-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), #6c63ff); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0; }
.reg-testimonial-name { font-size: 14px; font-weight: 700; color: var(--navy); }
.reg-testimonial-role { font-size: 12px; color: var(--text-muted); }
.reg-trust { display: flex; align-items: center; gap: 20px; margin-top: 24px; flex-wrap: wrap; }
.reg-trust-item { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-muted); font-weight: 600; }
.reg-trust-item i { color: var(--mint); font-size: 14px; }

/* Card */
.reg-card { background: #fff; border-radius: var(--radius-lg, 12px); border: 1px solid var(--border); box-shadow: var(--shadow-lg, 0 8px 32px rgba(0,0,0,.1)); padding: 36px 32px; position: sticky; top: 90px; }
.reg-card-header { margin-bottom: 20px; }
.reg-card-title { font-size: 20px; font-weight: 800; color: var(--navy); margin-bottom: 4px; line-height: 1.2; }
.reg-card-subtitle { font-size: 14px; color: var(--text-muted); }

/* Step indicator */
.step-indicator { display: flex; align-items: center; margin-bottom: 10px; }
.step-item { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: var(--text-muted); }
.step-item.active { color: var(--teal); }
.step-item.done { color: var(--mint); }
.step-num { width: 28px; height: 28px; border-radius: 50%; border: 2px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; transition: all .2s; }
.step-item.active .step-num { border-color: var(--teal); color: var(--teal); background: rgba(0,180,216,.08); }
.step-item.done .step-num { border-color: var(--mint); background: var(--mint); color: #fff; }
.step-sep { flex: 1; height: 2px; background: var(--border); margin: 0 8px; }

/* Progress */
.reg-progress { height: 3px; border-radius: 4px; background: var(--border); margin-bottom: 22px; overflow: hidden; }
.reg-progress-fill { height: 100%; background: linear-gradient(90deg, var(--teal), var(--mint)); border-radius: 4px; transition: width .4s cubic-bezier(.4,0,.2,1); }

/* Fields */
.reg-field { margin-bottom: 16px; }
.reg-label { display: block; font-size: 13px; font-weight: 600; color: var(--navy); margin-bottom: 6px; }
.reg-label .req { color: #ef4444; margin-left: 2px; }
.reg-label .opt { font-weight: 400; color: var(--text-muted); font-size: 12px; margin-left: 4px; }
.reg-input { width: 100%; border: 1.5px solid var(--border); border-radius: 10px; padding: 10px 14px; font-size: 15px; color: var(--text); background: #fff; outline: none; transition: border-color .18s, box-shadow .18s; -webkit-appearance: none; }
.reg-input:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(0,180,216,.12); }
.reg-input.is-error { border-color: #ef4444; }
.reg-input.is-success { border-color: var(--mint); }
.reg-error { display: block; font-size: 12px; color: #ef4444; margin-top: 5px; }
.reg-input-group { display: flex; align-items: stretch; }
.reg-input-group .reg-input { border-radius: 10px 0 0 10px; border-right: none; flex: 1; }
.reg-input-addon { display: flex; align-items: center; justify-content: center; padding: 0 14px; border: 1.5px solid var(--border); border-left: none; border-radius: 0 10px 10px 0; background: var(--bg); color: var(--text-muted); font-size: 16px; min-width: 44px; }
.reg-toggle-btn { display: flex; align-items: center; justify-content: center; padding: 0 14px; border: 1.5px solid var(--border); border-left: none; border-radius: 0 10px 10px 0; background: var(--bg); color: var(--text-muted); font-size: 16px; cursor: pointer; transition: all .18s; min-width: 44px; }
.reg-toggle-btn:hover { background: var(--navy); color: #fff; border-color: var(--navy); }

/* Password strength */
.pwd-strength { display: flex; align-items: center; gap: 10px; margin-top: 8px; }
.pwd-bars { display: flex; gap: 4px; flex: 1; }
.pwd-bar { flex: 1; height: 4px; border-radius: 4px; background: var(--border); transition: background .25s; }
.pwd-strength-label { font-size: 12px; font-weight: 600; white-space: nowrap; min-width: 72px; text-align: right; transition: color .25s; }

/* Buttons */
.reg-btn { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 12px 24px; border-radius: 10px; font-size: 15px; font-weight: 700; cursor: pointer; border: none; transition: all .2s; }
.reg-btn-primary { background: var(--teal); color: #fff; }
.reg-btn-primary:hover:not(:disabled) { background: #0096b5; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(0,180,216,.35); }
.reg-btn-primary:disabled { opacity: .65; cursor: not-allowed; }
.reg-btn-secondary { background: var(--bg); color: var(--navy); border: 1.5px solid var(--border); flex-shrink: 0; width: auto; padding: 12px 20px; }
.reg-btn-secondary:hover { background: var(--navy); color: #fff; border-color: var(--navy); }
.reg-btn-row { display: flex; gap: 10px; margin-top: 8px; }
.reg-btn-row .reg-btn-primary { flex: 1; }
.reg-divider { position: relative; text-align: center; margin: 12px 0 8px; }
.reg-divider::before { content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 1px; background: var(--border); }
.reg-divider span { position: relative; background: #fff; padding: 0 10px; font-size: 12px; color: var(--text-muted); }
.reg-card-footer { text-align: center; margin-top: 18px; font-size: 14px; color: var(--text-muted); }
.reg-card-footer a { color: var(--teal); font-weight: 600; text-decoration: none; }
.reg-card-trust { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border); flex-wrap: wrap; }
.reg-card-trust-item { display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--text-muted); font-weight: 600; }
.reg-quick-start { background: none; border: none; cursor: pointer; font-size: 13px; color: var(--text-muted); padding: 0; transition: color .18s; display: block; text-align: center; width: 100%; margin-top: 8px; }
.reg-quick-start:hover { color: var(--teal); }

/* Plans */
.plan-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 8px; margin-bottom: 10px; }
.plan-grid-card { border: 2px solid var(--border); border-radius: 10px; padding: 12px 10px; text-align: center; cursor: pointer; transition: all .2s; background: #fff; user-select: none; }
.plan-grid-card:hover { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(0,180,216,.08); }
.plan-grid-card.selected { border-color: var(--teal); background: rgba(0,180,216,.03); box-shadow: 0 0 0 3px rgba(0,180,216,.12); }
.plan-grid-badge { display: inline-block; background: rgba(0,180,216,.1); color: var(--teal); font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 20px; margin-bottom: 5px; }
.plan-grid-name { font-size: 12px; font-weight: 800; color: var(--navy); margin-bottom: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.plan-grid-price { font-size: 15px; font-weight: 900; color: var(--teal); line-height: 1; }
.plan-grid-cycle { font-size: 10px; color: var(--text-muted); margin-top: 2px; }
.plan-detail { border: 1.5px solid rgba(0,180,216,.3); border-radius: 10px; padding: 12px 14px; background: rgba(0,180,216,.02); }
.plan-features { list-style: none; padding: 0; margin: 0; font-size: 13px; display: flex; flex-direction: column; gap: 5px; }
.plan-features li { color: var(--text-muted); }

/* Responsive */
@media (max-width: 1023px) { .reg-layout { grid-template-columns: 1fr; } .reg-marketing { order: 2; } .reg-card { position: static; order: 1; } }
@media (max-width: 640px) {
    .reg-hero { padding: 100px 0 52px; }
    .reg-hero-metrics { flex-direction: column; width: 100%; }
    .reg-hero-metric { border-right: none; border-bottom: 1px solid rgba(255,255,255,.1); }
    .reg-hero-metric:last-child { border-bottom: none; }
    .reg-section { padding: 36px 0 56px; }
    .reg-card { padding: 24px 18px; }
    .plan-grid { grid-template-columns: 1fr 1fr; }
}
</style>
