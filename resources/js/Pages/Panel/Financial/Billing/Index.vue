<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

/**
 * Faturamento TISS — listas de:
 *  - Atendimentos elegíveis para faturar
 *  - Guias (claims) já abertas
 *  - Lotes (batches) gerados
 *
 * MVP da migração: read + ações inline (marcar paga/negada, submeter lote, baixar XML).
 * Lógica de seleção em massa (formar lote a partir de schedules) ainda pode
 * ser refinada — esta versão cobre a paridade visual.
 */
const props = defineProps({
    breadcrumbs:           { type: Array,  default: () => [] },
    eligibleSchedules:     { type: Array,  default: () => [] },
    claims:                { type: Array,  default: () => [] },
    batches:               { type: Array,  default: () => [] },
    covenants:             { type: Array,  default: () => [] },
    filters:               { type: Object, default: () => ({}) },
    tissVersionOptions:    { type: Array,  default: () => [] },
    tissLayoutOptions:     { type: Array,  default: () => [] },
    selectedTissVersion:   { type: String, default: '202603' },
    selectedTissLayout:    { type: String, default: '04.03.00' },
    t:                     { type: Object, default: () => ({}) },
});

const activeTab = ref('eligible');

function brl(v) {
    return 'R$ ' + Number(v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
}

const statusBadge = (s) => {
    if (s === 'paid')      return 'bg-success';
    if (s === 'denied')    return 'bg-danger';
    if (s === 'submitted') return 'bg-info';
    if (s === 'pending')   return 'bg-warning text-dark';
    if (s === 'cancelled') return 'bg-secondary';
    return 'bg-secondary';
};

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function markPaid(claim) {
    if (!confirm('Marcar esta guia como PAGA?')) return;
    const res = await fetch(claim.mark_paid_url, {
        method:  'POST',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    if (res.ok) router.reload({ only: ['claims'] });
}

async function markDenied(claim) {
    const reason = window.prompt('Motivo da negativa:');
    if (!reason) return;
    const res = await fetch(claim.mark_denied_url, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body:    JSON.stringify({ reason }),
    });
    if (res.ok) router.reload({ only: ['claims'] });
}
</script>

<template>
    <AppLayout title="Faturamento TISS" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader title="Faturamento TISS" />

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <button :class="['nav-link', { active: activeTab === 'eligible' }]" @click="activeTab = 'eligible'">
                        <i class="ti ti-list-check me-1"></i>
                        Elegíveis ({{ eligibleSchedules.length }})
                    </button>
                </li>
                <li class="nav-item">
                    <button :class="['nav-link', { active: activeTab === 'claims' }]" @click="activeTab = 'claims'">
                        <i class="ti ti-file-invoice me-1"></i>
                        Guias ({{ claims.length }})
                    </button>
                </li>
                <li class="nav-item">
                    <button :class="['nav-link', { active: activeTab === 'batches' }]" @click="activeTab = 'batches'">
                        <i class="ti ti-package me-1"></i>
                        Lotes ({{ batches.length }})
                    </button>
                </li>
            </ul>

            <!-- ELEGÍVEIS -->
            <div v-show="activeTab === 'eligible'" class="card">
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Data/hora</th>
                                <th>Paciente</th>
                                <th>Médico</th>
                                <th>Convênio</th>
                                <th>Tipo consulta</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="eligibleSchedules.length === 0">
                                <td colspan="5" class="text-center text-muted py-5">
                                    <i class="ti ti-clipboard-off fs-1 d-block mb-2"></i>
                                    Nenhum atendimento elegível para faturar no período.
                                </td>
                            </tr>
                            <tr v-for="s in eligibleSchedules" :key="s.id">
                                <td class="text-muted small">{{ s.date_time }}</td>
                                <td class="fw-medium">{{ s.patient_name || '—' }}</td>
                                <td class="text-muted">{{ s.doctor_name || '—' }}</td>
                                <td>{{ s.covenant_name || '—' }}</td>
                                <td class="text-muted">{{ s.visit_type || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- GUIAS -->
            <div v-show="activeTab === 'claims'" class="card">
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Criada em</th>
                                <th>Paciente</th>
                                <th>Médico</th>
                                <th>Convênio</th>
                                <th class="text-center">Lote</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Valor</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="claims.length === 0">
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="ti ti-file-off fs-1 d-block mb-2"></i>
                                    Nenhuma guia aberta.
                                </td>
                            </tr>
                            <tr v-for="c in claims" :key="c.id">
                                <td class="text-muted small">{{ c.created_at }}</td>
                                <td class="fw-medium">{{ c.patient_name || '—' }}</td>
                                <td class="text-muted">{{ c.doctor_name || '—' }}</td>
                                <td>{{ c.covenant_name || '—' }}</td>
                                <td class="text-center">
                                    <code v-if="c.batch_id" class="small">{{ String(c.batch_id).substring(0, 8) }}…</code>
                                    <span v-else class="text-muted small">—</span>
                                </td>
                                <td class="text-center">
                                    <span :class="`badge ${statusBadge(c.status)} fs-11`">{{ c.status }}</span>
                                </td>
                                <td class="text-end fw-bold">{{ brl(c.amount) }}</td>
                                <td class="text-end">
                                    <button
                                        v-if="c.status !== 'paid' && c.status !== 'cancelled'"
                                        class="btn btn-sm btn-outline-success me-1"
                                        :title="'Marcar como paga'"
                                        @click="markPaid(c)"
                                    >
                                        <i class="ti ti-check"></i>
                                    </button>
                                    <button
                                        v-if="c.status !== 'denied' && c.status !== 'cancelled'"
                                        class="btn btn-sm btn-outline-danger"
                                        :title="'Marcar como negada'"
                                        @click="markDenied(c)"
                                    >
                                        <i class="ti ti-x"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- LOTES -->
            <div v-show="activeTab === 'batches'" class="card">
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Criado em</th>
                                <th>Convênio</th>
                                <th class="text-center">Guias</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="batches.length === 0">
                                <td colspan="5" class="text-center text-muted py-5">
                                    <i class="ti ti-package-off fs-1 d-block mb-2"></i>
                                    Nenhum lote gerado.
                                </td>
                            </tr>
                            <tr v-for="b in batches" :key="b.id">
                                <td class="text-muted small">{{ b.created_at }}</td>
                                <td>{{ b.covenant_name || '—' }}</td>
                                <td class="text-center">
                                    <span class="badge badge-soft-info rounded fs-12">{{ b.claims_count }}</span>
                                </td>
                                <td class="text-center">
                                    <span :class="`badge ${statusBadge(b.status)} fs-11`">{{ b.status }}</span>
                                </td>
                                <td class="text-end">
                                    <a :href="b.xml_url" class="btn btn-sm btn-outline-secondary me-1" title="Baixar XML">
                                        <i class="ti ti-download"></i>
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
