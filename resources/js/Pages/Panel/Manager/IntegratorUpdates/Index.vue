<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout        from '@/Layouts/AppLayout.vue';
import PageHeader       from '@/Components/Panel/PageHeader.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup  from '@/Components/Panel/ActionIconGroup.vue';

/**
 * Atualizações do Integrador (Manager SaaS): publica o instalador que os
 * desktops das clínicas recebem pelo botão "Verificar atualização".
 *
 * A assinatura vem PRONTA do scripts/sign-update.sh (repositório do
 * integrator, máquina que guarda a chave privada) — esta tela só sobe o
 * arquivo e cola a assinatura; o SaaS nunca vê a chave.
 */
const props = defineProps({
    updates: { type: Array, required: true },
});

const breadcrumbs = [
    { label: 'Dashboard',                 url: route('manager.dashboard'), active: false },
    { label: 'Atualizações do Integrador', url: '#',                       active: true  },
];

const form = useForm({
    file:      null,
    version:   '',
    platform:  'windows',
    arch:      'x86',
    signature: '',
});

const flash = computed(() => usePage().props.flash ?? {});

function submit() {
    form.post(route('manager.integrator-updates.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function toggleActive(update) {
    useForm({ active: !update.active }).patch(
        route('manager.integrator-updates.update', update.id),
        { preserveScroll: true },
    );
}
</script>

<template>
    <AppLayout title="Atualizações do Integrador" :breadcrumbs="breadcrumbs">
        <div>
            <PageHeader title="Atualizações do Integrador" :total="updates.length" />

            <div v-if="flash.success" class="alert alert-success small py-2 mb-3">
                {{ flash.success }}
            </div>

            <!-- Publicar novo build -->
            <div class="card mb-4">
                <div class="card-header fw-medium">Publicar novo build</div>
                <div class="card-body">
                    <div class="alert alert-info d-flex align-items-start small py-2 mb-3">
                        <i class="ti ti-info-circle me-2 fs-5"></i>
                        <span>
                            Antes de publicar, assine o instalador na máquina que guarda a chave
                            privada: <code>scripts/sign-update.sh &lt;arquivo.msi&gt; &lt;versão&gt; &lt;arch&gt;</code>
                            (repositório do integrator). Cole abaixo a assinatura que o script imprimir.
                            Publicar desativa automaticamente as versões anteriores da mesma
                            plataforma/arquitetura.
                        </span>
                    </div>

                    <form @submit.prevent="submit" class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Instalador (MSI)</label>
                            <input
                                type="file"
                                class="form-control"
                                :class="{ 'is-invalid': form.errors.file }"
                                accept=".msi,.exe,.AppImage,.dmg,.deb"
                                @input="form.file = $event.target.files[0]"
                            />
                            <div v-if="form.errors.file" class="invalid-feedback">{{ form.errors.file }}</div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Versão</label>
                            <input
                                v-model="form.version"
                                type="text"
                                class="form-control"
                                :class="{ 'is-invalid': form.errors.version }"
                                placeholder="0.2.0"
                            />
                            <div v-if="form.errors.version" class="invalid-feedback">{{ form.errors.version }}</div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Plataforma</label>
                            <select v-model="form.platform" class="form-select">
                                <option value="windows">Windows</option>
                                <option value="linux">Linux</option>
                                <option value="macos">macOS</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Arquitetura</label>
                            <select v-model="form.arch" class="form-select">
                                <option value="x86">x86 (32 bits)</option>
                                <option value="x86_64">x86_64 (64 bits)</option>
                                <option value="aarch64">aarch64</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Assinatura ed25519 (base64, do sign-update.sh)</label>
                            <input
                                v-model="form.signature"
                                type="text"
                                class="form-control font-monospace"
                                :class="{ 'is-invalid': form.errors.signature }"
                                placeholder="Yt7GwAggyQ…=="
                                autocomplete="off"
                                spellcheck="false"
                            />
                            <div v-if="form.errors.signature" class="invalid-feedback">{{ form.errors.signature }}</div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary" :disabled="form.processing || !form.file">
                                <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" />
                                Publicar atualização
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Builds publicados -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Publicado em</th>
                                <th>Versão</th>
                                <th>Plataforma</th>
                                <th>Arquitetura</th>
                                <th>SHA-256</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="updates.length === 0">
                                <td colspan="7" class="text-center text-muted py-5">
                                    <i class="ti ti-cloud-upload fs-1 d-block mb-2"></i>
                                    Nenhum build publicado — os desktops mostram "Nenhuma atualização publicada".
                                </td>
                            </tr>
                            <tr v-for="u in updates" :key="u.id" :class="{ 'opacity-75': !u.active }">
                                <td class="text-muted small">{{ new Date(u.created_at).toLocaleString() }}</td>
                                <td class="fw-medium">{{ u.version }}</td>
                                <td>{{ u.platform }}</td>
                                <td><code class="small">{{ u.arch }}</code></td>
                                <td><code class="small" :title="u.sha256">{{ u.sha256.slice(0, 16) }}…</code></td>
                                <td class="text-center">
                                    <span v-if="u.active"
                                          class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium">
                                        Ativo
                                    </span>
                                    <span v-else
                                          class="badge badge-soft-secondary rounded fs-13 fw-medium">
                                        Desativado
                                    </span>
                                </td>
                                <td class="text-end">
                                    <ActionIconGroup align="end" gap="tight">
                                        <ActionIconButton
                                            :icon="u.active ? 'ti ti-cloud-off' : 'ti ti-cloud-up'"
                                            :title="u.active ? 'Desativar (clientes deixam de receber)' : 'Reativar'"
                                            @click="toggleActive(u)"
                                        />
                                    </ActionIconGroup>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
