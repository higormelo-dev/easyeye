<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * Convites a quem já usa o EasyEye, aguardando aceite. Mostra o e-mail que a
 * clínica digitou — exista conta ou não (a lista não revela quem tem acesso).
 */
const props = defineProps({
    invitations: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) }, // lang access_control (usa `invitation`)
});

const it = computed(() => props.t.invitation ?? {});
const { dateTime } = useLocaleFormat();

function cancel(invitation) {
    if (!window.confirm(it.value.confirm_cancel)) return;

    router.delete(route('panel.accesscontrol.users.invitations.destroy', invitation.id), { preserveScroll: true });
}
</script>

<template>
    <section class="card border-0 shadow-sm mt-3" aria-labelledby="user-invitations-title">
        <div class="card-body">
            <h2 id="user-invitations-title" class="h6 fw-semibold mb-1">
                <i class="ti ti-mail-forward me-1 text-primary" aria-hidden="true"></i>{{ it.pending_title }}
            </h2>
            <p class="text-muted small mb-3">{{ it.pending_hint }}</p>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ it.col_email }}</th>
                            <th scope="col">{{ it.col_rule }}</th>
                            <th scope="col">{{ it.col_sent_at }}</th>
                            <th scope="col">{{ it.col_expires_at }}</th>
                            <th scope="col" class="text-end">
                                <span class="visually-hidden">{{ it.cancel }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="invitation in invitations" :key="invitation.id">
                            <td class="text-break">{{ invitation.email }}</td>
                            <td>{{ invitation.rule }}</td>
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
