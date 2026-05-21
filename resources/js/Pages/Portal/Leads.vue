<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout    from '@/Layouts/PortalLayout.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';

const props = defineProps({
    leads: { type: Object, required: true },
    urls:  { type: Object, required: true },
});

const formOpen = ref(false);
const form = useForm({
    name:  '',
    email: '',
    phone: '',
    city:  '',
    state: '',
    notes: '',
});

function submit() {
    form.post(props.urls.store, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            formOpen.value = false;
        },
    });
}
</script>

<template>
    <Head title="Meus Leads — Portal de Parceiros" />

    <PortalLayout>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="fw-bold mb-0">
                <i class="ti ti-users me-1 text-primary"></i>Meus Leads
                <span class="badge bg-secondary fs-13 ms-1">{{ leads.total }}</span>
            </h4>
            <button type="button" class="btn btn-primary btn-sm" @click="formOpen = true">
                <i class="ti ti-plus me-1"></i>Indicar novo lead
            </button>
        </div>

        <!-- Tabela -->
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Data</th>
                            <th>Nome</th>
                            <th>Contato</th>
                            <th>Cidade/UF</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="leads.data.length === 0">
                            <td colspan="5" class="text-center text-muted py-5">
                                <i class="ti ti-user-off fs-1 d-block mb-2 opacity-25"></i>
                                Você ainda não indicou nenhum lead.
                                <div class="mt-2">
                                    <button type="button" class="btn btn-primary btn-sm" @click="formOpen = true">
                                        <i class="ti ti-plus me-1"></i>Indicar agora
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="l in leads.data" :key="l.id">
                            <td class="small text-muted">{{ l.created_at }}</td>
                            <td class="fw-medium">{{ l.name }}</td>
                            <td class="text-muted small">
                                <div v-if="l.email"><i class="ti ti-mail me-1"></i>{{ l.email }}</div>
                                <div v-if="l.phone"><i class="ti ti-phone me-1"></i>{{ l.phone }}</div>
                            </td>
                            <td class="text-muted small">{{ l.city_state || '—' }}</td>
                            <td class="text-center">
                                <span :class="`badge ${l.status_badge}`">{{ l.status_label }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <TablePagination :data="leads" class="mt-3" />

        <!-- Modal: novo lead -->
        <div
            v-if="formOpen"
            class="modal d-block"
            tabindex="-1"
            style="background:rgba(0,0,0,.45);"
            @click.self="formOpen = false"
        >
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="ti ti-user-plus me-1 text-primary"></i>Indicar novo lead
                        </h5>
                        <button type="button" class="btn-close" :disabled="form.processing" @click="formOpen = false"></button>
                    </div>
                    <form @submit.prevent="submit">
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Nome <span class="text-danger">*</span></label>
                                    <input v-model="form.name" type="text" maxlength="255" class="form-control"
                                           :class="{ 'is-invalid': form.errors.name }" required>
                                    <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">E-mail <span class="text-danger">*</span></label>
                                    <input v-model="form.email" type="email" maxlength="255" class="form-control"
                                           :class="{ 'is-invalid': form.errors.email }" required>
                                    <div v-if="form.errors.email" class="invalid-feedback">{{ form.errors.email }}</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Telefone</label>
                                    <input v-model="form.phone" type="text" maxlength="20" class="form-control"
                                           :class="{ 'is-invalid': form.errors.phone }">
                                </div>
                                <div class="col-md-9">
                                    <label class="form-label">Cidade</label>
                                    <input v-model="form.city" type="text" maxlength="100" class="form-control">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">UF</label>
                                    <input v-model="form.state" type="text" maxlength="2" class="form-control text-uppercase">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Observações</label>
                                    <textarea v-model="form.notes" rows="3" maxlength="500" class="form-control"></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                    :disabled="form.processing" @click="formOpen = false">
                                Cancelar
                            </button>
                            <button type="submit" class="btn btn-primary btn-sm" :disabled="form.processing">
                                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ti ti-check me-1"></i>
                                Cadastrar lead
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </PortalLayout>
</template>
