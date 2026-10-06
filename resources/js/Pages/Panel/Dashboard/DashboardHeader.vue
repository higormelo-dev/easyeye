<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Cabeçalho do Dashboard: saudação, data de hoje no idioma do usuário, o
 * "posto de trabalho" do perfil (Recepção, Gestão, Financeiro, Meu
 * consultório) e as ações primárias dele — só as que o usuário pode abrir
 * (`access`, mesmas regras das rotas). À direita, o "ao vivo" (polling de 30 s
 * + Atualizar) e o slot "Personalizar".
 */
const props = defineProps({
    profile: { type: String, default: 'user' },
    access: { type: Object, default: () => ({}) },
    // Médico: "Iniciar atendimento" quando há paciente pronto (attend_url) e
    // o card "Próximo paciente" (que já tem o botão) está oculto.
    nextPatient: { type: Object, default: null },
    nextVisible: { type: Boolean, default: false },
    doctorMissing: { type: Boolean, default: false },
    isRefreshing: { type: Boolean, default: false },
    lastUpdated: { type: Date, default: () => new Date() },
    t: { type: Object, required: true },
});

const emit = defineEmits(['refresh']);

const page = usePage();
const { locale } = useLocaleFormat();

const user = computed(() => page.props.auth?.user ?? {});
const entity = computed(() => page.props.auth?.entity ?? {});
// Nome em CAIXA ALTA (cadastro antigo) vira "Helena", não "HELENA".
const capitalize = (word) =>
    word ? word.charAt(0).toLocaleUpperCase(locale.value) + word.slice(1).toLocaleLowerCase(locale.value) : '';
const TITLES = /^(dr|dra|prof|profa|sr|sra)\.?$/i;

// "Dra. Ana Lúcia Prado" → "Dra. Ana" (o título não vira o primeiro nome).
const firstName = computed(() => {
    const words = String(user.value.name ?? '')
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    if (!words.length) return '';
    if (TITLES.test(words[0]) && words[1]) return `${capitalize(words[0])} ${capitalize(words[1])}`;

    return capitalize(words[0]);
});

// Relógio: saudação e data acompanham a virada do turno/dia com a tela aberta.
const now = ref(new Date());
let clock = null;
onMounted(() => {
    clock = setInterval(() => {
        now.value = new Date();
    }, 60_000);
});
onBeforeUnmount(() => clearInterval(clock));

const greeting = computed(() => {
    const hour = now.value.getHours();
    const key = hour < 12 ? 'greeting_morning' : hour < 18 ? 'greeting_afternoon' : 'greeting_evening';

    return props.t[key] ?? '';
});

const todayLabel = computed(() => {
    const text = new Intl.DateTimeFormat(locale.value, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(now.value);

    return text.charAt(0).toLocaleUpperCase(locale.value) + text.slice(1);
});

const ROLES = {
    doctor: { key: 'role_doctor', icon: 'ti ti-stethoscope' },
    secretary: { key: 'role_secretary', icon: 'ti ti-headset' },
    admin: { key: 'role_admin', icon: 'ti ti-chart-dots-3' },
    financial: { key: 'role_financial', icon: 'ti ti-report-money' },
    user: { key: 'role_user', icon: 'ti ti-layout-dashboard' },
};
const role = computed(() => ROLES[props.profile] ?? ROLES.user);

// Ações primárias do posto de trabalho (a primeira em destaque).
const actions = computed(() => {
    const a = props.access;
    const list = [];
    const newSchedule = () =>
        a.schedules && { key: 'new_schedule', icon: 'ti ti-calendar-plus', label: props.t.action_new_schedule, url: route('panel.schedules.index', { new: 1 }) };
    const newPatient = () =>
        a.patients && { key: 'new_patient', icon: 'ti ti-user-plus', label: props.t.btn_new_patient, url: route('panel.patients.index', { new: 1 }) };
    const bi = () => a.financial && { key: 'bi', icon: 'ti ti-chart-bar', label: props.t.action_open_bi, url: route('panel.financial.bi.index') };

    switch (props.profile) {
        case 'doctor':
            if (props.nextPatient?.attend_url && !props.nextVisible) {
                list.push({ key: 'attend', icon: 'ti ti-player-play', label: props.t.btn_start_attendance, url: props.nextPatient.attend_url });
            }
            if (a.schedules && !props.doctorMissing) {
                list.push({ key: 'my_schedule', icon: 'ti ti-calendar', label: props.t.action_my_schedule, url: route('panel.schedules.index') });
            }
            break;
        case 'secretary':
            list.push(newSchedule(), newPatient());
            break;
        case 'admin':
            list.push(newSchedule(), newPatient(), bi());
            break;
        case 'financial':
            if (a.financial) {
                list.push({ key: 'new_cash_entry', icon: 'ti ti-plus', label: props.t.action_new_cash_entry, url: route('panel.financial.cash-flow.index', { new: 1 }) });
            }
            list.push(bi());
            break;
    }

    return list.filter(Boolean);
});

const updatedAt = computed(() =>
    new Intl.DateTimeFormat(locale.value, { hour: '2-digit', minute: '2-digit' }).format(props.lastUpdated),
);
</script>

<template>
    <header class="db-header">
        <!-- data-tour: âncoras do tour guiado (lang/*/tour.php → pages.panel.dashboard) -->
        <div class="db-header__main" data-tour="dashboard-welcome">
            <div class="db-header__eyebrow">
                <span class="db-role" data-test="dashboard-role">
                    <i :class="role.icon" aria-hidden="true"></i>{{ t[role.key] }}
                </span>
                <span class="db-header__date">{{ todayLabel }}</span>
            </div>
            <h1 class="db-header__title">
                {{ greeting }}<template v-if="firstName">, {{ firstName }}</template>
            </h1>
            <p v-if="entity.name" class="db-header__entity">{{ entity.name }}</p>
        </div>

        <div class="db-header__side">
            <div class="db-header__tools">
                <div class="db-live" data-tour="dashboard-live" aria-live="polite">
                    <span class="db-live__dot" aria-hidden="true"></span>
                    <span class="db-live__text">
                        <template v-if="isRefreshing">{{ t.live_refreshing }}</template>
                        <template v-else>{{ t.live_label }} · {{ t.last_updated_at }} {{ updatedAt }}</template>
                    </span>
                    <button
                        type="button"
                        class="db-icon-btn"
                        :disabled="isRefreshing"
                        :title="t.btn_refresh_hint"
                        :aria-label="t.btn_refresh"
                        data-test="dashboard-refresh"
                        @click="emit('refresh')"
                    >
                        <i class="ti ti-refresh" :class="{ 'db-spin': isRefreshing }" aria-hidden="true"></i>
                    </button>
                </div>
                <slot name="tools" />
            </div>

            <div v-if="actions.length" class="db-header__actions">
                <a
                    v-for="(action, index) in actions"
                    :key="action.key"
                    :href="action.url"
                    :data-action="action.key"
                    :class="['btn btn-sm', index === 0 ? 'btn-primary' : 'btn-outline-secondary db-btn-soft']"
                >
                    <i :class="action.icon" class="me-1" aria-hidden="true"></i>{{ action.label }}
                </a>
            </div>
        </div>
    </header>
</template>
