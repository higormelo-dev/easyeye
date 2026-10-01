<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * Convites a médicos que já têm login no EasyEye (outra clínica), aguardando
 * o aceite deles. Só mostra o que a PRÓPRIA clínica digitou (nome/CRM).
 */
const props = defineProps({
    invitations: { type: Array, default: () => [] },
    // Traduções de lang/<locale>/doctors.php (colunas + seção `invitation`).
    t: { type: Object, default: () => ({}) },
});

const it = computed(() => props.t.invitation ?? {});

const { dateTime } = useLocaleFormat();

function cancel(invitation) {
    if (!window.confirm(it.value.confirm_cancel)) return;

    router.delete(route('panel.doctors.invitations.destroy', invitation.id), { preserveScroll: true });
}
</script>

<template>
    <section class="card border-0 shadow-sm mt-3" aria-labelledby="doctor-invitations-title">
        <div class="card-body">
            <h2 id="doctor-invitations-title" class="h6 fw-semibold mb-1">
                <i class="ti ti-mail-forward me-1 text-primary" aria-hidden="true"></i>{{ it.pending_title }}
            </h2>
            <p class="text-muted small mb-3">{{ it.pending_hint }}</p>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ t.col_name }}</th>
                            <th scope="col">{{ t.col_record }}</th>
                            <th scope="col">{{ it.col_sent_at }}</th>
                            <th scope="col">{{ it.col_expires_at }}</th>
                            <th scope="col" class="text-end">
                                <span class="visually-hidden">{{ it.cancel }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="invitation in invitations" :key="invitation.id">
                            <td class="fw-semibold">{{ invitation.name }}</td>
                            <td>{{ invitation.record }}</td>
                            <td>{{ dateTime(invitation.sent_at) }}</td>
                            <td>{{ dateTime(invitation.expires_at) }}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-outline-danger btn-sm" @click="cancel(invitation)">
                                    <i class="ti ti-x me-1" aria-hidden="true"></i>{{ it.cancel }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</template>
