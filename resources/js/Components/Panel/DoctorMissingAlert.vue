<script setup>
/**
 * Aviso de perfil médico SEM cadastro de médico ativo nesta clínica
 * (App\Support\CurrentDoctor): o servidor barra os atos clínicos — emitir
 * documento, registrar prontuário, laudo — e a tela esconde as ações.
 */
defineProps({
    text: { type: String, required: true },
});
</script>

<template>
    <div class="alert alert-warning d-flex align-items-start gap-2" role="alert" data-testid="doctor-missing-alert">
        <i class="ti ti-user-exclamation fs-4 flex-shrink-0" aria-hidden="true"></i>
        <div>{{ text }}</div>
    </div>
</template>
