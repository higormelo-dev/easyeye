<script setup>
import { ref, reactive, computed, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';

const props = defineProps({
    summary:           { type: Object, required: true },
    period:            { type: Object, required: true },
    expenseCategories: { type: Object, required: true },
    expenses:          { type: Array,  default: () => [] },
    ai:                { type: Object, required: true },
    t:                 { type: Object, required: true },
});

const breadcrumbs = [];

// ─────────────────────────────────────────────────────────────────────────
// Período
// ─────────────────────────────────────────────────────────────────────────
const PERIOD_PRESETS = ['this_month', '3m', '6m', '12m', 'custom'];
const loadingPeriod   = ref(false);
const customFrom      = ref(props.period.from);
const customTo        = ref(props.period.to);
const showCustomPicker = ref(props.period.preset === 'custom');

function selectPeriod(preset) {
    if (preset === 'custom') {
        showCustomPicker.value = true;
        return;
    }
    showCustomPicker.value = false;
    reloadPeriod({ preset });
}

function applyCustomPeriod() {
    if (!customFrom.value || !customTo.value) return;
    reloadPeriod({ preset: 'custom', from: customFrom.value, to: customTo.value });
}

function reloadPeriod(data) {
    loadingPeriod.value = true;
    router.reload({
        data,
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { loadingPeriod.value = false; },
    });
}

// ─────────────────────────────────────────────────────────────────────────
// Formatação
// ─────────────────────────────────────────────────────────────────────────
function brl(value) {
    return Number(value ?? 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 2 });
}
function pct(value) {
    return Number(value ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
}
function deltaClass(value) {
    if (value === null || value === undefined) return 'pf-delta--neutral';
    return value >= 0 ? 'pf-delta--up' : 'pf-delta--down';
}
function deltaIcon(value) {
    if (value === null || value === undefined) return 'ti-minus';
    return value >= 0 ? 'ti-trending-up' : 'ti-trending-down';
}

const isProfit = computed(() => props.summary.profit.amount >= 0);

const maxRevenueByPlan = computed(() =>
    Math.max(1, ...props.summary.revenue.by_plan.map(p => p.amount)));
const maxExpenseByCategory = computed(() =>
    Math.max(1, ...props.summary.expenses.by_category.map(c => c.amount)));

// ─────────────────────────────────────────────────────────────────────────
// Despesas manuais (CRUD)
// ─────────────────────────────────────────────────────────────────────────
const showExpenseModal = ref(false);
const editingExpense    = ref(null);
const expenseForm = reactive({
    category: '', description: '', amount: '', effective_at: '', recurring: false, notes: '',
});
const expenseSaving = ref(false);
const expenseErrors = ref({});

function openNewExpense() {
    editingExpense.value = null;
    Object.assign(expenseForm, {
        category: '', description: '', amount: '',
        effective_at: new Date().toISOString().slice(0, 10),
        recurring: false, notes: '',
    });
    expenseErrors.value = {};
    showExpenseModal.value = true;
}

function openEditExpense(expense) {
    editingExpense.value = expense;
    Object.assign(expenseForm, {
        category: expense.category, description: expense.description, amount: expense.amount,
        effective_at: expense.effective_at, recurring: expense.recurring, notes: expense.notes ?? '',
    });
    expenseErrors.value = {};
    showExpenseModal.value = true;
}

async function saveExpense() {
    expenseSaving.value = true;
    expenseErrors.value = {};
    try {
        if (editingExpense.value) {
            await axios.patch(route('manager.finance.expenses.update', editingExpense.value.id), expenseForm);
        } else {
            await axios.post(route('manager.finance.expenses.store'), expenseForm);
        }
        showExpenseModal.value = false;
        router.reload({ preserveScroll: true });
    } catch (e) {
        expenseErrors.value = e.response?.data?.errors ?? {};
    } finally {
        expenseSaving.value = false;
    }
}

async function deleteExpense(expense) {
    if (!window.confirm(props.t.expenses.confirm_delete)) return;
    await axios.delete(route('manager.finance.expenses.destroy', expense.id));
    router.reload({ preserveScroll: true });
}

// ─────────────────────────────────────────────────────────────────────────
// IA — Digest estruturado
// ─────────────────────────────────────────────────────────────────────────
const digestBusy   = ref(false);
const digestResult = ref(null);
const digestError  = ref('');

function aiUrl(key, id) {
    const raw = props.ai.urls[key];
    return id ? raw.replace('__ID__', id) : raw;
}

const BACKOFF_MS = [1500, 2000, 3000, 4500, 6000, 8000];

async function pollRun(runId, onDone) {
    let attempt = 0;
    const deadline = Date.now() + 120000;

    const tick = async () => {
        if (Date.now() > deadline) { onDone(null, 'timeout'); return; }
        try {
            const { data } = await axios.get(aiUrl('show', runId));
            if (['approved', 'rejected', 'failed', 'cancelled'].includes(data.status)) {
                onDone(data, data.status === 'approved' ? null : (data.error_message || data.status));
                return;
            }
            const delay = BACKOFF_MS[Math.min(attempt, BACKOFF_MS.length - 1)];
            attempt += 1;
            setTimeout(tick, delay);
        } catch (e) {
            onDone(null, e.response?.data?.message ?? 'error');
        }
    };
    await tick();
}

async function generateDigest() {
    if (digestBusy.value) return;
    digestBusy.value = true;
    digestError.value = '';
    digestResult.value = null;
    try {
        const { data } = await axios.post(aiUrl('digest'));
        await pollRun(data.run_id, (result, error) => {
            digestBusy.value = false;
            if (error) { digestError.value = props.t.ai.error; return; }
            try {
                digestResult.value = JSON.parse(result.final_output);
            } catch {
                digestError.value = props.t.ai.error;
            }
        });
    } catch (e) {
        digestBusy.value = false;
        digestError.value = e.response?.data?.message ?? props.t.ai.error;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// IA — Chat
// ─────────────────────────────────────────────────────────────────────────
function newUuid() {
    return window.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

const chatMessages = ref([]);
const chatPrompt    = ref('');
const chatBusy      = ref(false);
const chatConversationId = ref(newUuid());
const chatScroll = ref(null);

function newChatConversation() {
    chatConversationId.value = newUuid();
    chatMessages.value = [];
}

function applyChatSuggestion(text) {
    chatPrompt.value = text;
}

async function sendChat() {
    const prompt = chatPrompt.value.trim();
    if (!prompt || chatBusy.value) return;
    chatBusy.value = true;
    chatMessages.value.push({ role: 'user', content: prompt });
    chatPrompt.value = '';
    const assistantMsg = reactive({ role: 'assistant', content: '', pending: true });
    chatMessages.value.push(assistantMsg);
    nextTick(() => { if (chatScroll.value) chatScroll.value.scrollTop = chatScroll.value.scrollHeight; });

    try {
        const { data } = await axios.post(aiUrl('chat'), {
            user_prompt: prompt,
            conversation_id: chatConversationId.value,
        });
        await pollRun(data.run_id, (result, error) => {
            assistantMsg.pending = false;
            assistantMsg.content = error ? props.t.ai.error : (result?.final_output ?? '');
            chatBusy.value = false;
            nextTick(() => { if (chatScroll.value) chatScroll.value.scrollTop = chatScroll.value.scrollHeight; });
        });
    } catch (e) {
        assistantMsg.pending = false;
        assistantMsg.content = e.response?.data?.message ?? props.t.ai.error;
        chatBusy.value = false;
    }
}
</script>

<template>
    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3 pf-page">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
                <div>
                    <h4 class="fw-bold mb-0">{{ t.title }}</h4>
                    <p class="text-muted mb-0" style="font-size:.85rem;max-width:640px;">{{ t.subtitle }}</p>
                </div>

                <!-- Seletor de período -->
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="btn-group btn-group-sm" role="group">
                        <button v-for="preset in PERIOD_PRESETS" :key="preset" type="button"
                                class="btn"
                                :class="period.preset === preset ? 'btn-primary' : 'btn-outline-secondary'"
                                :disabled="loadingPeriod"
                                @click="selectPeriod(preset)">
                            {{ t.period[preset] }}
                        </button>
                    </div>
                    <span v-if="loadingPeriod" class="spinner-border spinner-border-sm text-primary"></span>
                </div>
            </div>

            <div v-if="showCustomPicker" class="d-flex align-items-end gap-2 mb-3 pf-custom-period">
                <div>
                    <label class="form-label small mb-1">{{ t.period.from }}</label>
                    <input type="date" v-model="customFrom" class="form-control form-control-sm">
                </div>
                <div>
                    <label class="form-label small mb-1">{{ t.period.to }}</label>
                    <input type="date" v-model="customTo" class="form-control form-control-sm">
                </div>
                <button type="button" class="btn btn-primary btn-sm" @click="applyCustomPeriod">{{ t.period.apply }}</button>
            </div>

            <!-- ═══════════════ KPIs ═══════════════ -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3">
                    <div class="pf-stat-card pf-stat-card--revenue">
                        <div class="pf-stat-icon"><i class="ti ti-cash"></i></div>
                        <div>
                            <div class="pf-stat-value">{{ brl(summary.revenue.gross) }}</div>
                            <div class="pf-stat-label">{{ t.kpi.revenue }}</div>
                            <div v-if="summary.revenue.delta_pct !== null" :class="['pf-delta', deltaClass(summary.revenue.delta_pct)]">
                                <i :class="'ti ' + deltaIcon(summary.revenue.delta_pct)"></i> {{ pct(Math.abs(summary.revenue.delta_pct)) }}%
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="pf-stat-card pf-stat-card--expenses">
                        <div class="pf-stat-icon"><i class="ti ti-receipt-2"></i></div>
                        <div>
                            <div class="pf-stat-value">{{ brl(summary.expenses.total) }}</div>
                            <div class="pf-stat-label">{{ t.kpi.expenses }}</div>
                            <div v-if="summary.expenses.delta_pct !== null" :class="['pf-delta', deltaClass(-summary.expenses.delta_pct)]">
                                <i :class="'ti ' + deltaIcon(-summary.expenses.delta_pct)"></i> {{ pct(Math.abs(summary.expenses.delta_pct)) }}%
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="pf-stat-card" :class="isProfit ? 'pf-stat-card--profit' : 'pf-stat-card--loss'">
                        <div class="pf-stat-icon"><i :class="isProfit ? 'ti ti-trending-up' : 'ti ti-trending-down'"></i></div>
                        <div>
                            <div class="pf-stat-value">{{ brl(summary.profit.amount) }}</div>
                            <div class="pf-stat-label">{{ isProfit ? t.kpi.profit : t.kpi.loss }} · {{ pct(summary.profit.margin) }}% {{ t.kpi.margin }}</div>
                            <div v-if="summary.profit.delta_pct !== null" :class="['pf-delta', deltaClass(summary.profit.delta_pct)]">
                                <i :class="'ti ' + deltaIcon(summary.profit.delta_pct)"></i> {{ pct(Math.abs(summary.profit.delta_pct)) }}%
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="pf-stat-card pf-stat-card--mrr">
                        <div class="pf-stat-icon"><i class="ti ti-repeat"></i></div>
                        <div>
                            <div class="pf-stat-value">{{ brl(summary.mrr.amount) }}</div>
                            <div class="pf-stat-label">{{ t.kpi.mrr }}</div>
                            <div v-if="summary.mrr.delta_pct !== null" :class="['pf-delta', deltaClass(summary.mrr.delta_pct)]">
                                <i :class="'ti ' + deltaIcon(summary.mrr.delta_pct)"></i> {{ pct(Math.abs(summary.mrr.delta_pct)) }}%
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-2">
                    <div class="pf-mini-stat"><div class="pf-mini-value">{{ brl(summary.arpu) }}</div><div class="pf-mini-label">{{ t.kpi.arpu }}</div></div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="pf-mini-stat"><div class="pf-mini-value">{{ summary.paying_clinics }}</div><div class="pf-mini-label">{{ t.kpi.paying_clinics }}</div></div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="pf-mini-stat"><div class="pf-mini-value text-success">+{{ summary.new_clinics }}</div><div class="pf-mini-label">{{ t.kpi.new_clinics }}</div></div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="pf-mini-stat"><div class="pf-mini-value" :class="summary.cancellations.count > 0 ? 'text-danger' : ''">{{ summary.cancellations.count }}</div><div class="pf-mini-label">{{ t.kpi.cancellations }}</div></div>
                </div>
                <div class="col-12 col-lg-4">
                    <div class="pf-mini-stat">
                        <div class="pf-mini-value" :class="summary.delinquency.count > 0 ? 'text-danger' : ''">
                            {{ summary.delinquency.count }} <small class="fw-normal text-muted">({{ brl(summary.delinquency.amount_at_risk) }} {{ t.kpi.at_risk }})</small>
                        </div>
                        <div class="pf-mini-label">{{ t.kpi.delinquency }}</div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════ Receita por plano / Despesas por categoria ═══════════════ -->
            <div class="row g-3 mb-3">
                <div class="col-lg-6">
                    <div class="pf-card h-100">
                        <div class="pf-card-header">{{ t.breakdown.revenue_by_plan }}</div>
                        <div class="pf-card-body">
                            <p v-if="!summary.revenue.by_plan.length" class="text-muted small mb-0">{{ t.breakdown.no_revenue }}</p>
                            <div v-for="row in summary.revenue.by_plan" :key="row.plan_name" class="pf-bar-row">
                                <div class="pf-bar-row-head">
                                    <span>{{ row.plan_name }}</span>
                                    <strong>{{ brl(row.amount) }}</strong>
                                </div>
                                <div class="pf-bar-track">
                                    <div class="pf-bar-fill pf-bar-fill--revenue" :style="`width:${(row.amount / maxRevenueByPlan) * 100}%`"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="pf-card h-100">
                        <div class="pf-card-header">{{ t.breakdown.expenses_by_category }}</div>
                        <div class="pf-card-body">
                            <p v-if="!summary.expenses.by_category.length" class="text-muted small mb-0">{{ t.breakdown.no_expenses }}</p>
                            <div v-for="row in summary.expenses.by_category" :key="row.category" class="pf-bar-row">
                                <div class="pf-bar-row-head">
                                    <span>
                                        {{ row.label }}
                                        <i v-if="row.auto" class="ti ti-bolt-filled pf-auto-icon" :title="t.breakdown.auto_hint"></i>
                                    </span>
                                    <strong>{{ brl(row.amount) }}</strong>
                                </div>
                                <div class="pf-bar-track">
                                    <div class="pf-bar-fill pf-bar-fill--expense" :style="`width:${(row.amount / maxExpenseByCategory) * 100}%`"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════ Despesas manuais ═══════════════ -->
            <div class="pf-card mb-3">
                <div class="pf-card-header d-flex align-items-center justify-content-between">
                    <span>{{ t.expenses.title }}</span>
                    <button type="button" class="btn btn-primary btn-sm" @click="openNewExpense">
                        <i class="ti ti-plus me-1"></i>{{ t.expenses.new }}
                    </button>
                </div>
                <div class="pf-card-body p-0">
                    <div v-if="!expenses.length" class="text-muted small p-3">{{ t.expenses.empty }}</div>
                    <table v-else class="table table-sm mb-0">
                        <tbody>
                            <tr v-for="expense in expenses" :key="expense.id">
                                <td class="text-muted" style="width:100px;font-size:.8rem;">{{ expense.effective_at }}</td>
                                <td><span class="badge bg-light text-dark border">{{ expense.category_label }}</span></td>
                                <td>{{ expense.description }}</td>
                                <td class="text-end fw-semibold">{{ brl(expense.amount) }}</td>
                                <td style="width:70px;" class="text-end">
                                    <button type="button" class="btn btn-sm btn-link p-1" @click="openEditExpense(expense)"><i class="ti ti-pencil"></i></button>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-1" @click="deleteExpense(expense)"><i class="ti ti-trash"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ═══════════════ IA — Digest ═══════════════ -->
            <div class="pf-card mb-3">
                <div class="pf-card-header d-flex align-items-center justify-content-between">
                    <div>
                        <div>{{ t.ai.title }}</div>
                        <div class="text-muted fw-normal" style="font-size:.78rem;">{{ t.ai.subtitle }}</div>
                    </div>
                    <button type="button" class="btn btn-sm" :class="digestResult ? 'btn-outline-primary' : 'btn-primary'"
                            :disabled="digestBusy" @click="generateDigest">
                        <span v-if="digestBusy" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="ti ti-sparkles me-1"></i>
                        {{ digestResult ? t.ai.regenerate : t.ai.generate }}
                    </button>
                </div>
                <div class="pf-card-body">
                    <div v-if="digestError" class="alert alert-danger py-2 small mb-0">{{ digestError }}</div>
                    <div v-else-if="digestBusy && !digestResult" class="text-muted small py-3 text-center">
                        <span class="spinner-border spinner-border-sm me-2"></span>{{ t.ai.thinking }}
                    </div>
                    <div v-else-if="!digestResult" class="text-muted small py-3 text-center">{{ t.ai.empty }}</div>
                    <div v-else>
                        <p class="fw-semibold mb-3">{{ digestResult.resumo }}</p>
                        <div class="row g-3">
                            <div class="col-md-6" v-for="section in [
                                { key: 'ganhando', label: t.ai.section_winning, icon: 'ti-arrow-up-circle', cls: 'pf-digest--good' },
                                { key: 'perdendo', label: t.ai.section_losing, icon: 'ti-arrow-down-circle', cls: 'pf-digest--bad' },
                                { key: 'oportunidades', label: t.ai.section_opportunities, icon: 'ti-bulb', cls: 'pf-digest--opp' },
                                { key: 'acoes_sugeridas', label: t.ai.section_actions, icon: 'ti-checklist', cls: 'pf-digest--action' },
                            ]" :key="section.key">
                                <div :class="['pf-digest-section', section.cls]">
                                    <div class="pf-digest-section-title"><i :class="'ti ' + section.icon"></i>{{ section.label }}</div>
                                    <div v-if="!digestResult[section.key]?.length" class="text-muted small">—</div>
                                    <div v-for="(item, i) in digestResult[section.key]" :key="i" class="pf-digest-item">
                                        <div class="pf-digest-item-title">{{ item.titulo }}</div>
                                        <div class="pf-digest-item-detail">{{ item.detalhe }}</div>
                                        <div v-if="item.evidencia" class="pf-digest-item-evidence">
                                            <i class="ti ti-database me-1"></i>{{ t.ai.evidence_label }}: {{ item.evidencia }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════ IA — Chat ═══════════════ -->
            <div class="pf-card mb-3">
                <div class="pf-card-header d-flex align-items-center justify-content-between">
                    <div>
                        <div>{{ t.ai.chat_title }}</div>
                        <div class="text-muted fw-normal" style="font-size:.78rem;">{{ t.ai.chat_subtitle }}</div>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" @click="newChatConversation">
                        <i class="ti ti-rotate me-1"></i>{{ t.ai.chat_new }}
                    </button>
                </div>
                <div class="pf-card-body">
                    <div ref="chatScroll" class="pf-chat-messages mb-2">
                        <div v-if="!chatMessages.length" class="text-muted small text-center py-3">{{ t.ai.chat_empty }}</div>
                        <div v-for="(msg, i) in chatMessages" :key="i" class="pf-chat-msg" :class="`pf-chat-msg--${msg.role}`">
                            <span v-if="msg.pending" class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>{{ t.ai.thinking }}</span>
                            <span v-else style="white-space:pre-wrap;">{{ msg.content }}</span>
                        </div>
                    </div>

                    <div v-if="!chatMessages.length" class="d-flex flex-wrap gap-1 mb-2">
                        <button v-for="s in t.ai.chat_suggestions" :key="s" type="button"
                                class="btn btn-sm btn-outline-secondary" style="font-size:.75rem;"
                                @click="applyChatSuggestion(s)">
                            {{ s }}
                        </button>
                    </div>

                    <div class="d-flex gap-2">
                        <input v-model="chatPrompt" type="text" class="form-control form-control-sm"
                               :placeholder="t.ai.chat_placeholder" :disabled="chatBusy"
                               @keydown.enter="sendChat">
                        <button type="button" class="btn btn-primary btn-sm" :disabled="chatBusy || !chatPrompt.trim()" @click="sendChat">
                            <i class="ti ti-send"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════ Modal de despesa ═══════════════ -->
        <Teleport to="body">
            <div v-if="showExpenseModal" class="modal fade show d-block" style="background:rgba(0,0,0,.5);" @click.self="showExpenseModal = false">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header py-2">
                            <h6 class="modal-title">{{ editingExpense ? t.expenses.edit : t.expenses.new }}</h6>
                            <button type="button" class="btn-close" @click="showExpenseModal = false"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-2">
                                <label class="form-label small">{{ t.expenses.category }}</label>
                                <select v-model="expenseForm.category" class="form-select form-select-sm" :class="{ 'is-invalid': expenseErrors.category }">
                                    <option value="" disabled>—</option>
                                    <option v-for="(label, value) in expenseCategories" :key="value" :value="value">{{ label }}</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">{{ t.expenses.description }}</label>
                                <input v-model="expenseForm.description" type="text" class="form-control form-control-sm" :class="{ 'is-invalid': expenseErrors.description }">
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small">{{ t.expenses.amount }}</label>
                                    <input v-model="expenseForm.amount" type="number" step="0.01" min="0" class="form-control form-control-sm" :class="{ 'is-invalid': expenseErrors.amount }">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">{{ t.expenses.effective_at }}</label>
                                    <input v-model="expenseForm.effective_at" type="date" class="form-control form-control-sm" :class="{ 'is-invalid': expenseErrors.effective_at }">
                                </div>
                            </div>
                            <div class="form-check mb-2">
                                <input v-model="expenseForm.recurring" type="checkbox" class="form-check-input" id="pf-recurring">
                                <label class="form-check-label small" for="pf-recurring">{{ t.expenses.recurring }}</label>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">{{ t.expenses.notes }}</label>
                                <textarea v-model="expenseForm.notes" rows="2" class="form-control form-control-sm"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer py-2">
                            <button type="button" class="btn btn-secondary btn-sm" @click="showExpenseModal = false">Cancelar</button>
                            <button type="button" class="btn btn-primary btn-sm" :disabled="expenseSaving" @click="saveExpense">
                                <span v-if="expenseSaving" class="spinner-border spinner-border-sm me-1"></span>
                                {{ t.expenses.save }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.pf-custom-period { background: #f8f9fb; border: 1px solid #e2e8f0; border-radius: .5rem; padding: .75rem 1rem; }

.pf-stat-card {
    display: flex; align-items: center; gap: .8rem;
    background: #fff; border: 1px solid #e2e8f0; border-top: 3px solid #94a3b8;
    border-radius: .75rem; padding: 1rem; height: 100%;
}
.pf-stat-card--revenue  { border-top-color: #06b6d4; }
.pf-stat-card--expenses { border-top-color: #f97316; }
.pf-stat-card--profit   { border-top-color: #16a34a; }
.pf-stat-card--loss     { border-top-color: #dc2626; }
.pf-stat-card--mrr      { border-top-color: #7c3aed; }

.pf-stat-icon {
    width: 44px; height: 44px; border-radius: .65rem; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 1.25rem;
    background: #f1f5f9; color: #475569;
}
.pf-stat-value { font-size: 1.25rem; font-weight: 800; color: #1e293b; line-height: 1.2; }
.pf-stat-label { font-size: .78rem; color: #64748b; font-weight: 600; }

.pf-delta { font-size: .72rem; font-weight: 700; display: inline-flex; align-items: center; gap: 2px; margin-top: 2px; }
.pf-delta--up      { color: #16a34a; }
.pf-delta--down    { color: #dc2626; }
.pf-delta--neutral { color: #94a3b8; }

.pf-mini-stat { background: #fff; border: 1px solid #e2e8f0; border-radius: .6rem; padding: .6rem .8rem; text-align: center; }
.pf-mini-value { font-size: 1.05rem; font-weight: 800; color: #1e293b; }
.pf-mini-label { font-size: .7rem; color: #64748b; font-weight: 600; }

.pf-card { background: #fff; border: 1px solid #e2e8f0; border-radius: .75rem; overflow: hidden; }
.pf-card-header { padding: .75rem 1rem; border-bottom: 1px solid #e2e8f0; font-weight: 700; font-size: .88rem; color: #1e293b; }
.pf-card-body { padding: 1rem; }

.pf-bar-row { margin-bottom: .85rem; }
.pf-bar-row:last-child { margin-bottom: 0; }
.pf-bar-row-head { display: flex; justify-content: space-between; font-size: .82rem; margin-bottom: 4px; }
.pf-bar-track { height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden; }
.pf-bar-fill { height: 100%; border-radius: 4px; }
.pf-bar-fill--revenue { background: linear-gradient(90deg, #06b6d4, #0891b2); }
.pf-bar-fill--expense { background: linear-gradient(90deg, #f97316, #ea580c); }
.pf-auto-icon { color: #f59e0b; font-size: .8rem; margin-left: 3px; }

.pf-digest-section { border: 1px solid #e2e8f0; border-radius: .6rem; padding: .8rem; height: 100%; }
.pf-digest--good   { border-left: 3px solid #16a34a; }
.pf-digest--bad    { border-left: 3px solid #dc2626; }
.pf-digest--opp    { border-left: 3px solid #7c3aed; }
.pf-digest--action { border-left: 3px solid #0891b2; }
.pf-digest-section-title { font-weight: 700; font-size: .82rem; margin-bottom: .5rem; display: flex; align-items: center; gap: 6px; }
.pf-digest-item { margin-bottom: .6rem; }
.pf-digest-item:last-child { margin-bottom: 0; }
.pf-digest-item-title { font-weight: 600; font-size: .82rem; color: #1e293b; }
.pf-digest-item-detail { font-size: .78rem; color: #475569; }
.pf-digest-item-evidence { font-size: .7rem; color: #7c3aed; background: #f5f3ff; border-radius: 4px; padding: 2px 6px; display: inline-block; margin-top: 3px; }

.pf-chat-messages { max-height: 320px; overflow-y: auto; display: flex; flex-direction: column; gap: .5rem; padding: .5rem; background: #f8fafc; border-radius: .5rem; }
.pf-chat-msg { max-width: 85%; padding: .5rem .7rem; border-radius: .5rem; font-size: .84rem; }
.pf-chat-msg--user { align-self: flex-end; background: #7c3aed; color: #fff; }
.pf-chat-msg--assistant { align-self: flex-start; background: #fff; border: 1px solid #e2e8f0; }
</style>
