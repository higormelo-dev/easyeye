<script setup>
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, Head } from '@inertiajs/vue3';
defineOptions({ name: 'IntegratorCommandsIndex' });
const props = defineProps({
    entityId: { type: String, required: true },
    userIntegrator: { type: String, required: true },
    integrator: { type: Object, required: true },
    commands: { type: Array, default: () => [] },
    allowedTypes: { type: Array, default: () => [] },
});
const selected = ref('run_diagnostics');
const sending = ref(false);
const error = ref('');
const states = { pending: 'Pendente', completed: 'Concluído', failed: 'Falhou', expired: 'Expirado' };
function resultLabel(result) {
    if (!result) return 'Aguardando resultado';
    if (result.error_code) return `A execução falhou (${result.error_code}).`;
    if ('reloaded' in result) return 'A configuração é lida quando usada; o próximo ciclo usa os valores persistidos.';
    if ('app_version' in result)
        return `Versão ${result.app_version} · ${result.pending ?? 0} pendentes · ${result.failed ?? 0} falhas · ${result.blocked ?? 0} bloqueados`;
    return `${result.sent ?? 0} enviados · ${result.queued_files ?? 0} arquivos enfileirados · ${result.failed_or_blocked ?? 0} falhas ou bloqueios`;
}
const labels = {
    run_diagnostics: 'Diagnóstico',
    resync_now: 'Sincronizar agora',
    reload_config: 'Confirmar leitura da configuração',
};
async function send() {
    sending.value = true;
    error.value = '';
    try {
        await window.axios.post(
            `/panel/manager/entities/${props.entityId}/user-integrators/${props.userIntegrator}/integrators/${props.integrator.id}/commands`,
            { type: selected.value, timeout_minutes: 15 },
        );
        router.reload();
    } catch (e) {
        error.value = e.response?.data?.message ?? 'Não foi possível solicitar o comando.';
    } finally {
        sending.value = false;
    }
}
</script>
<template>
    <Head title="Comandos do integrador" />
    <AppLayout title="Comandos do integrador"
        ><main class="container py-4">
            <h1>Comandos — {{ integrator.name }}</h1>
            <p>
                As solicitações expiram em 15 minutos. O integrador confirma a execução e o resultado quando estiver
                online.
            </p>
            <p v-if="error" role="alert" class="alert alert-danger">{{ error }}</p>
            <div class="d-flex gap-2 mb-3">
                <select v-model="selected" aria-label="Comando" class="form-select">
                    <option v-for="type in allowedTypes" :key="type" :value="type">{{ labels[type] }}</option></select
                ><button class="btn btn-primary" :disabled="sending" @click="send">Solicitar</button
                ><button class="btn btn-outline-secondary" @click="router.reload()">Atualizar</button>
            </div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Solicitação</th>
                        <th>Estado</th>
                        <th>Prazo</th>
                        <th>Resultado</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="command in commands" :key="command.id">
                        <td>
                            {{ labels[command.type] ?? command.type }}<br /><small>{{ command.created_at }}</small>
                        </td>
                        <td>{{ states[command.status] ?? command.status }}</td>
                        <td>{{ command.expires_at }}</td>
                        <td>
                            <p>{{ resultLabel(command.result) }}</p>
                            <p v-if="command.result?.capabilities">
                                PDF: {{ command.result.capabilities.pdf ? 'disponível' : 'indisponível' }} · EMR:
                                {{ command.result.capabilities.emr ? 'disponível' : 'indisponível' }} · DICOM:
                                {{ command.result.capabilities.dicom ? 'disponível' : 'indisponível' }} · Automação:
                                {{ command.result.capabilities.rpa ? 'disponível' : 'indisponível' }}
                            </p>
                            <details v-if="command.result?.devices?.length">
                                <summary>Aparelhos no diagnóstico</summary>
                                <ul>
                                    <li v-for="device in command.result.devices" :key="device.equipment_id">
                                        Aparelho {{ device.equipment_id }} ·
                                        {{ device.issue_code ?? device.issue ?? 'Sem recusa registrada' }}
                                    </li>
                                </ul>
                            </details>
                            <small v-if="command.result?.devices_truncated"
                                >Mostrando parte de {{ command.result.total_devices }} aparelhos.</small
                            >
                        </td>
                    </tr>
                    <tr v-if="!commands.length">
                        <td colspan="4">Nenhum comando solicitado.</td>
                    </tr>
                </tbody>
            </table>
        </main></AppLayout
    >
</template>
