<script setup>
import { ref, watch } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';
import AppLayout       from '@/Layouts/AppLayout.vue';
import PageHeader      from '@/Components/Panel/PageHeader.vue';
import SearchInput     from '@/Components/Panel/SearchInput.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup  from '@/Components/Panel/ActionIconGroup.vue';

/**
 * Lista de usuários de uma empresa cliente (visão Manager SaaS).
 * Permite ao admin SaaS iniciar uma sessão de impersonação para suporte.
 *
 * Padrão: sem cards (uso pontual de suporte) — só tabela paginada server-side.
 * Confirmação antes da impersonação evita cliques acidentais.
 */
const props = defineProps({
    entity:          { type: Object, required: true },   // { id, code, name }
    users:           { type: Object, required: true },   // paginator Laravel
    filters:         { type: Object, default: () => ({}) },
    isImpersonating: { type: Boolean, default: false },
    t:               { type: Object, default: () => ({}) },
});

// ── Breadcrumbs ──────────────────────────────────────────────────────────────
const breadcrumbs = [
    { label: props.t.breadcrumb_home ?? 'Dashboard', url: route('panel.dashboard'), active: false },
    { label: props.t.breadcrumb_entities ?? 'Empresas', url: route('manager.entities.index'), active: false },
    { label: props.entity.name, url: '#', active: false },
    { label: props.t.breadcrumb_current ?? 'Usuários', url: '#', active: true },
];

// ── Search server-side ───────────────────────────────────────────────────────
const search = ref(props.filters.search ?? '');
let searchTimer = null;

watch(search, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            route('manager.entities.users', props.entity.id),
            { search: val },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 400);
});

// ── Impersonação ─────────────────────────────────────────────────────────────
// Usa useForm() para POST com CSRF automático e tratamento de erro Inertia.
const impersonateForm = useForm({});
const pendingUser = ref(null);

function askImpersonate(user) {
    if (!user.can_impersonate) return;
    pendingUser.value = user;
}

function cancelImpersonate() {
    pendingUser.value = null;
}

function confirmImpersonate() {
    if (!pendingUser.value?.impersonate_url) return;
    impersonateForm.post(pendingUser.value.impersonate_url, {
        preserveScroll: false,
        onFinish: () => { pendingUser.value = null; },
    });
}
</script>

<template>
    <AppLayout :title="t.page_title ?? 'Usuários'" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader
                :title="t.page_title ?? 'Usuários'"
                :subtitle="entity.name"
                :total="users.total"
            >
                <template #actions>
                    <Link
                        :href="route('manager.entities.index')"
                        class="btn btn-outline-secondary btn-sm"
                    >
                        <i class="ti ti-arrow-left me-1"></i>{{ t.btn_back ?? 'Voltar' }}
                    </Link>
                </template>
            </PageHeader>

            <!-- Aviso quando admin já está impersonando alguém -->
            <div v-if="isImpersonating" class="alert alert-warning py-2 mb-3 small">
                <i class="ti ti-alert-triangle me-1"></i>
                Você já está em sessão de impersonação. Encerre a sessão atual antes de iniciar outra.
            </div>

            <!-- Toolbar: busca -->
            <div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
                <SearchInput
                    v-model="search"
                    :placeholder="t.search_placeholder ?? 'Buscar...'"
                    style="min-width: 280px;"
                />
            </div>

            <!-- Tabela -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>{{ t.col_registered_at ?? 'Cadastro' }}</th>
                                <th>{{ t.col_code ?? 'Código' }}</th>
                                <th>{{ t.col_name ?? 'Nome' }}</th>
                                <th>{{ t.col_email ?? 'E-mail' }}</th>
                                <th>{{ t.col_rule ?? 'Papel' }}</th>
                                <th class="text-center">{{ t.col_status ?? 'Status' }}</th>
                                <th class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="users.data.length === 0">
                                <td colspan="7" class="text-center text-muted py-5">
                                    <i class="ti ti-user-off fs-1 d-block mb-2"></i>
                                    {{ t.empty_list ?? 'Nenhum usuário encontrado.' }}
                                </td>
                            </tr>
                            <tr
                                v-for="u in users.data"
                                :key="u.id"
                                :class="{ 'table-secondary opacity-75': u.deleted }"
                            >
                                <td class="text-muted small">{{ u.created_at }}</td>
                                <td><code class="text-muted small">{{ u.code }}</code></td>
                                <td class="fw-medium">{{ u.name }}</td>
                                <td class="text-muted">{{ u.email }}</td>
                                <td>
                                    <span v-if="u.rule" class="badge bg-light text-dark border">{{ u.rule }}</span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td class="text-center">
                                    <span
                                        v-if="u.deleted"
                                        class="badge badge-soft-secondary rounded fs-13 fw-medium"
                                    >{{ t.status_deleted ?? 'Removido' }}</span>
                                    <span
                                        v-else-if="u.active"
                                        class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium"
                                    >{{ t.status_active ?? 'Ativo' }}</span>
                                    <span
                                        v-else
                                        class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium"
                                    >{{ t.status_inactive ?? 'Inativo' }}</span>
                                </td>
                                <td class="text-end">
                                    <ActionIconGroup align="end" gap="tight">
                                        <ActionIconButton
                                            icon="ti ti-user-cog"
                                            :title="u.can_impersonate
                                                ? (t.action_impersonate ?? 'Entrar como este usuário')
                                                : (t.action_impersonate_disabled ?? 'Não é possível impersonar')"
                                            variant="info"
                                            :disabled="!u.can_impersonate"
                                            @click="askImpersonate(u)"
                                        />
                                    </ActionIconGroup>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <TablePagination
                :data="users"
                class="mt-3"
            />

            <!-- Modal de confirmação de impersonação -->
            <div
                v-if="pendingUser"
                class="modal d-block"
                tabindex="-1"
                style="background: rgba(0,0,0,.45);"
                @click.self="cancelImpersonate"
            >
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="ti ti-user-cog me-1 text-info"></i>
                                {{ (t.confirm_impersonate_title ?? 'Entrar como :name?').replace(':name', pendingUser.name) }}
                            </h5>
                            <button type="button" class="btn-close" @click="cancelImpersonate"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-2">{{ t.confirm_impersonate_text ?? 'Você assumirá temporariamente o contexto desta clínica.' }}</p>
                            <div class="small text-muted">
                                <strong>{{ t.col_email ?? 'E-mail' }}:</strong> {{ pendingUser.email }}<br>
                                <strong>{{ t.col_rule ?? 'Papel' }}:</strong> {{ pendingUser.rule || '—' }}
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button
                                type="button"
                                class="btn btn-outline-secondary btn-sm"
                                :disabled="impersonateForm.processing"
                                @click="cancelImpersonate"
                            >
                                {{ t.confirm_impersonate_no ?? 'Cancelar' }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-info text-white btn-sm"
                                :disabled="impersonateForm.processing"
                                @click="confirmImpersonate"
                            >
                                <span v-if="impersonateForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ti ti-check me-1"></i>
                                {{ t.confirm_impersonate_yes ?? 'Sim, continuar' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
