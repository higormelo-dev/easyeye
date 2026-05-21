<script setup>
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout                   from '@/Layouts/AppLayout.vue';
import PageHeader                  from '@/Components/Panel/PageHeader.vue';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import { useConfirmationWithReason } from '@/composables/useConfirmationWithReason.js';

/**
 * Tela de configurações de segurança da empresa atual.
 *
 * Hoje cobre: 2FA obrigatório por empresa (todos os usuários).
 * Futuro: política de senha, expiração de sessão, IP allowlist etc.
 */
const props = defineProps({
    entity:      { type: Object, required: true },
    currentUser: { type: Object, required: true },
    t:           { type: Object, default: () => ({}) },
});

const breadcrumbs = [
    { label: 'Dashboard', url: route('panel.dashboard'),  active: false },
    { label: 'Configurações', url: '#',                   active: false },
    { label: props.t.entity_2fa_section ?? 'Segurança',   url: '#', active: true  },
];

const { state: reasonModal, open: openReasonModal, close: closeReasonModal, handle: handleReasonConfirm } = useConfirmationWithReason();

const isEnabled = computed(() => !!props.entity.requires_two_factor);

function toggle() {
    const enabling = !isEnabled.value;

    openReasonModal({
        title: enabling
            ? (props.t.entity_2fa_btn_enable ?? 'Ativar 2FA obrigatório')
            : (props.t.entity_2fa_btn_disable ?? 'Desativar 2FA obrigatório'),
        message: enabling
            ? (props.t.entity_2fa_reason_enable ?? '')
            : (props.t.entity_2fa_reason_disable ?? ''),
        confirmVariant: enabling ? 'primary' : 'danger',
        async onConfirm(reason) {
            const res = await fetch(route('panel.setting.security.two-factor.toggle'), {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Inertia':    'true',
                },
                body: JSON.stringify({ enabled: enabling, reason }),
            });
            const json = await res.json();
            if (res.ok) {
                if (window.showSuccessToast) window.showSuccessToast(json.message);
                router.reload({ only: ['entity'] });
            } else if (window.showErrorToast) {
                window.showErrorToast(json.message ?? 'Erro');
            }
        },
    });
}
</script>

<template>
    <AppLayout :title="t.entity_2fa_section ?? 'Segurança'" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader
                :title="t.entity_2fa_section ?? 'Segurança'"
                :subtitle="entity.name"
            />

            <!-- Card de 2FA por empresa -->
            <div class="card mb-3">
                <div class="card-body p-4">
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <div class="flex-shrink-0">
                            <i class="ti ti-shield-lock-filled fs-1"
                               :class="isEnabled ? 'text-success' : 'text-muted'"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="fw-semibold mb-1">
                                {{ t.entity_2fa_label ?? 'Exigir 2FA para todos os usuários' }}
                                <span v-if="isEnabled" class="badge badge-soft-success rounded text-success border border-success ms-1">
                                    {{ t.status_active ?? 'Ativo' }}
                                </span>
                                <span v-else class="badge badge-soft-secondary rounded ms-1">
                                    {{ t.status_inactive ?? 'Inativo' }}
                                </span>
                            </h5>
                            <p class="text-muted small mb-2">{{ t.entity_2fa_hint }}</p>

                            <p v-if="isEnabled && entity.two_factor_enabled_at" class="small text-muted mb-0">
                                <i class="ti ti-history me-1"></i>
                                {{ t.entity_2fa_enabled_at
                                    ?.replace(':date', entity.two_factor_enabled_at)
                                    ?.replace(':user', entity.two_factor_enabled_by ?? '—') }}
                            </p>
                        </div>
                    </div>

                    <!-- Aviso: admin ativando sem ter 2FA configurado -->
                    <div
                        v-if="!isEnabled && !currentUser.has_two_factor"
                        class="alert alert-warning small d-flex align-items-start mb-3"
                    >
                        <i class="ti ti-alert-triangle me-2 fs-5 mt-1"></i>
                        <div>
                            <strong>{{ t.entity_2fa_warning }}</strong>
                            <div class="mt-1">
                                <Link :href="currentUser.setup_url" class="btn btn-sm btn-outline-warning">
                                    <i class="ti ti-shield-lock me-1"></i>
                                    Configurar meu 2FA primeiro
                                </Link>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button
                            type="button"
                            :class="`btn btn-sm ${isEnabled ? 'btn-outline-danger' : 'btn-primary'}`"
                            @click="toggle"
                        >
                            <i :class="`ti me-1 ${isEnabled ? 'ti-shield-off' : 'ti-shield-check'}`"></i>
                            {{ isEnabled ? t.entity_2fa_btn_disable : t.entity_2fa_btn_enable }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Confirmação com reason (LGPD/CFM) -->
            <ConfirmationWithReasonModal
                :open="reasonModal.open"
                :title="reasonModal.title"
                :message="reasonModal.message"
                :confirm-variant="reasonModal.confirmVariant"
                :saving="reasonModal.saving"
                @close="closeReasonModal"
                @confirm="handleReasonConfirm"
            />
        </div>
    </AppLayout>
</template>
