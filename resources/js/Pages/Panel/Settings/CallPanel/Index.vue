<script setup>
import { ref } from 'vue';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

/**
 * Configurações → Painel de Chamadas (TV da sala de espera).
 * Opcional por clínica: liga/desliga o recurso e mostra a URL pública
 * pra abrir na TV. Regenerar o link invalida o anterior.
 */
const props = defineProps({
    enabled:   { type: Boolean, default: false },
    panel_url: { type: String,  default: null },
    t:         { type: Object,  default: () => ({}) },
});

const enabled  = ref(props.enabled);
const panelUrl = ref(props.panel_url);
const saving   = ref(false);
const flash    = ref('');
const copied   = ref(false);

async function save(regenerate = false) {
    saving.value = true;
    try {
        const res = await fetch(route('panel.setting.call-panel.update'), {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ enabled: enabled.value, regenerate_token: regenerate }),
        });
        const json = await res.json();
        if (res.ok) {
            panelUrl.value = json.panel_url;
            flash.value    = json.message;
            setTimeout(() => { flash.value = ''; }, 3000);
        }
    } finally {
        saving.value = false;
    }
}

async function copyUrl() {
    try {
        await navigator.clipboard.writeText(panelUrl.value);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch { /**/ }
}
</script>

<template>
    <AppLayout :title="t.call_panel_title ?? 'Painel de chamadas'" :breadcrumbs="[]">
        <div class="container-fluid py-3">
            <PageHeader
                :title="t.call_panel_title ?? 'Painel de chamadas'"
                :subtitle="t.call_panel_subtitle ?? 'Chame pacientes para o consultório em uma TV na sala de espera, com aviso por voz.'"
            />

            <div class="card" style="max-width:760px;">
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input id="cpEnabled" v-model="enabled" class="form-check-input" type="checkbox" @change="save(false)">
                        <label for="cpEnabled" class="form-check-label fw-semibold">
                            {{ t.call_panel_enable ?? 'Ativar painel de chamadas nesta clínica' }}
                        </label>
                    </div>

                    <p class="text-muted small">
                        {{ t.call_panel_hint ?? 'Com o recurso ativo, a Agenda ganha o botão "Chamar paciente". A chamada aparece na TV e é anunciada por voz (ex.: "Paciente João — Dra. Ana Lima").' }}
                    </p>

                    <div v-if="enabled && panelUrl" class="border rounded p-3 bg-light">
                        <div class="fw-semibold small mb-1">{{ t.call_panel_url ?? 'URL da TV (abra no navegador da televisão)' }}</div>
                        <code class="d-block bg-white border rounded p-2 text-break mb-2" style="font-size:.75rem;">{{ panelUrl }}</code>
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-outline-primary btn-sm" @click="copyUrl">
                                <i class="fas" :class="copied ? 'fa-check' : 'fa-copy'"></i>
                                {{ copied ? (t.copied ?? 'Copiado!') : (t.copy ?? 'Copiar link') }}
                            </button>
                            <a :href="panelUrl" target="_blank" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-external-link-alt me-1"></i>{{ t.call_panel_open ?? 'Abrir painel' }}
                            </a>
                            <button type="button" class="btn btn-outline-danger btn-sm ms-auto" :disabled="saving" @click="save(true)">
                                <i class="fas fa-rotate me-1"></i>{{ t.call_panel_regen ?? 'Gerar novo link (invalida o atual)' }}
                            </button>
                        </div>
                    </div>

                    <span v-if="flash" class="badge bg-success-subtle text-success mt-3">{{ flash }}</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
