<script setup>
import { computed } from 'vue';

const props = defineProps({
    patient: { type: Object, required: true },
});

const initials = computed(() => {
    const name = props.patient.full_name ?? '';
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    if (parts.length === 1) return parts[0][0].toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
});

const avatarColor = computed(() => {
    const palette = ['#3b82f6', '#8b5cf6', '#06b6d4', '#10b981', '#f59e0b', '#ef4444', '#ec4899', '#6366f1'];
    const name = props.patient.full_name ?? '';
    if (!name) return palette[0];
    let h = 0;
    for (const c of name) h = c.charCodeAt(0) + ((h << 5) - h);
    return palette[Math.abs(h) % palette.length];
});

/**
 * Gênero pode vir como:
 *   - 'M' / 'F' (string ASCII) — legado
 *   - 1 / 2 (código IBGE: 1=Masculino, 2=Feminino, 3=Outro/Não-binário, 9=Não informado)
 *   - 'masculino' / 'feminino' (string PT-BR)
 *   - null
 */
const genderLabel = computed(() => {
    const g = props.patient.gender;
    if (g == null || g === '') return '—';
    const s = String(g).trim().toLowerCase();
    if (s === '1' || s === 'm' || s === 'masculino') return 'Masculino';
    if (s === '2' || s === 'f' || s === 'feminino')  return 'Feminino';
    if (s === '3' || s === 'o' || s === 'outro')     return 'Outro';
    if (s === '9' || s === 'n')                       return 'Não informado';
    return String(g);
});
</script>

<template>
    <div class="card pmr-patient-card">
        <div class="card-body p-3 text-center">
            <div class="patient-avatar mx-auto mb-2 d-flex align-items-center justify-content-center text-white fw-bold"
                 :style="{ background: avatarColor }">
                {{ initials }}
            </div>
            <h6 class="mb-0 fw-semibold" :title="patient.full_name">
                {{ patient.full_name ?? '—' }}
            </h6>
            <small class="text-muted d-block">{{ patient.code }}</small>
        </div>

        <ul class="list-group list-group-flush pmr-patient-info-list small">
            <li v-if="patient.birth_date" class="list-group-item d-flex justify-content-between">
                <span class="text-muted"><i class="fas fa-birthday-cake me-1"></i>Nasc.</span>
                <span class="fw-medium">
                    {{ patient.birth_date }}
                    <span v-if="patient.age" class="text-muted">({{ patient.age }}a)</span>
                </span>
            </li>
            <li v-if="patient.gender" class="list-group-item d-flex justify-content-between">
                <span class="text-muted"><i class="fas fa-venus-mars me-1"></i>Sexo</span>
                <span class="fw-medium">{{ genderLabel }}</span>
            </li>
            <li v-if="patient.cpf" class="list-group-item d-flex justify-content-between">
                <span class="text-muted"><i class="fas fa-id-card me-1"></i>CPF</span>
                <span class="fw-medium">{{ patient.cpf }}</span>
            </li>
            <li v-if="patient.phone" class="list-group-item d-flex justify-content-between">
                <span class="text-muted"><i class="fas fa-phone me-1"></i>Tel.</span>
                <span class="fw-medium">{{ patient.phone }}</span>
            </li>
            <li v-if="patient.email" class="list-group-item d-flex flex-column">
                <span class="text-muted"><i class="fas fa-envelope me-1"></i>E-mail</span>
                <span class="fw-medium pmr-break-all" :title="patient.email">{{ patient.email }}</span>
            </li>
            <li v-if="patient.covenant_name" class="list-group-item d-flex flex-column">
                <span class="text-muted"><i class="fas fa-handshake me-1"></i>Convênio</span>
                <span class="fw-medium" :title="patient.covenant_name">
                    {{ patient.covenant_name }}
                </span>
            </li>
            <li v-if="patient.skin_type" class="list-group-item d-flex justify-content-between">
                <span class="text-muted"><i class="fas fa-palette me-1"></i>Pele</span>
                <span class="fw-medium">{{ patient.skin_type }}</span>
            </li>
            <li v-if="patient.iris_type" class="list-group-item d-flex justify-content-between">
                <span class="text-muted"><i class="fas fa-eye me-1"></i>Íris</span>
                <span class="fw-medium">{{ patient.iris_type }}</span>
            </li>
        </ul>
    </div>
</template>

<style scoped>
/*
 * Background branco explícito em todos os níveis. Padrão visual clínico
 * (CFM): fundo neutro, lista compacta, label cinza à esquerda + valor em
 * negrito à direita. Itens longos (Convênio, E-mail) caem em duas linhas
 * via flex-column para evitar truncamento ilegível.
 */
.pmr-patient-card {
    border: 1px solid rgba(0, 0, 0, .08);
    box-shadow: 0 1px 3px rgba(0, 0, 0, .05);
    background-color: #fff;
}
.pmr-patient-card :deep(.card-body) {
    background-color: #fff;
}
.patient-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    font-size: 1.2rem;
}
.pmr-patient-info-list {
    background-color: #fff;
}
.pmr-patient-info-list .list-group-item {
    padding: .45rem .75rem;
    border-color: rgba(0, 0, 0, .05);
    background-color: #fff;
    font-size: .82rem;
    line-height: 1.3;
}
.pmr-patient-info-list .text-muted {
    font-size: .78rem;
}
.pmr-patient-info-list .fw-medium {
    word-break: break-word;
}
.pmr-break-all {
    word-break: break-all;
}
</style>
