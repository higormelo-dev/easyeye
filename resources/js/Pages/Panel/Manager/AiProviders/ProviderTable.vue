<script setup>
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import { providerMeta } from '../AiCreditPurchases/aiProviderMeta';
import { lgpdBadge, providerRole, providerStatus } from './providerStatus.js';

/**
 * Provedores de IA em tabela — mesmo layout das listagens do manager
 * (Empresas, Medicamentos): ver detalhes/configurar e testar conexão como
 * ações em ícone. A chave nunca aparece: só se está definida no .env.
 */
const props = defineProps({
    providers: { type: Array, default: () => [] },
    testing: { type: String, default: null }, // código em teste
    t: { type: Object, default: () => ({}) },
});

defineEmits(['view', 'test']);

const meta = (p) => providerMeta(p.code, p.label);
const status = (p) => providerStatus(p, props.t);
const role = (p) => providerRole(p, props.t);
const lgpd = (p) => lgpdBadge(p, props.t);
</script>

<template>
    <div class="table-responsive">
        <table class="table table-nowrap table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th scope="col">{{ t.provider ?? 'Provedor' }}</th>
                    <th scope="col" class="d-none d-md-table-cell">{{ t.col_key ?? 'Chave (.env)' }}</th>
                    <th scope="col" class="d-none d-lg-table-cell">{{ t.model ?? 'Modelo' }}</th>
                    <th scope="col">{{ t.col_role ?? 'Papel' }}</th>
                    <th scope="col" class="text-center">{{ t.col_status ?? 'Status' }}</th>
                    <th scope="col" class="text-center">{{ t.col_lgpd ?? 'LGPD' }}</th>
                    <th scope="col" class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in providers" :key="p.code" :data-provider-row="p.code">
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div
                                class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                :style="{ width: '32px', height: '32px', background: meta(p).bg, color: meta(p).color }"
                                aria-hidden="true"
                            >
                                <i :class="`${meta(p).icon} fs-14`"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="fw-medium lh-sm" style="font-size: 0.875rem">
                                    {{ p.label }}
                                    <i
                                        v-if="p.base_url && !p.base_url_secure"
                                        class="ti ti-lock-open text-danger ms-1"
                                        :title="t.insecure_url"
                                        data-insecure-url
                                    ></i>
                                    <span v-if="p.base_url && !p.base_url_secure" class="visually-hidden">{{
                                        t.insecure_url
                                    }}</span>
                                </div>
                                <div class="text-muted text-truncate" style="font-size: 0.75rem; max-width: 260px">
                                    <span v-if="p.compatible">{{ t.driver_compatible }} · </span>{{ p.base_url ?? '—' }}
                                </div>
                            </div>
                        </div>
                    </td>

                    <td class="d-none d-md-table-cell">
                        <span
                            v-if="p.has_key"
                            class="badge badge-soft-success rounded text-success border border-success fs-12 fw-medium"
                            :title="t.key_hint_title"
                            data-key-hint
                            ><i class="ti ti-key me-1" aria-hidden="true"></i>{{ p.key_hint }}</span
                        >
                        <span v-else class="badge badge-soft-secondary rounded fs-12 fw-medium">{{
                            t.key_missing ?? 'Não definida'
                        }}</span>
                        <div>
                            <code class="text-muted" style="font-size: 0.72rem">{{ p.key_env }}</code>
                        </div>
                    </td>

                    <td class="d-none d-lg-table-cell">
                        <code class="small">{{ p.model ?? '—' }}</code>
                        <div v-if="p.model_source" class="text-muted" style="font-size: 0.75rem">
                            {{
                                p.model_source === 'panel'
                                    ? (t.model_source_panel ?? 'Escolhido no painel')
                                    : (t.model_source_env ?? 'Padrão do .env')
                            }}
                        </div>
                    </td>

                    <td>
                        <span v-if="role(p)" class="badge badge-soft-primary rounded fs-12 fw-medium" data-role-badge>
                            <i :class="`ti ${role(p).icon} me-1`" aria-hidden="true"></i>{{ role(p).label }}
                        </span>
                        <span v-else class="text-muted" aria-hidden="true">—</span>
                    </td>

                    <td class="text-center">
                        <span
                            class="badge rounded fs-12 fw-medium"
                            :class="status(p).cls"
                            :title="status(p).hint"
                            :data-status="status(p).code"
                            ><i :class="`ti ${status(p).icon} me-1`" aria-hidden="true"></i>{{ status(p).label }}</span
                        >
                    </td>

                    <td class="text-center">
                        <span
                            v-if="lgpd(p)"
                            class="badge rounded fs-12 fw-medium"
                            :class="lgpd(p).cls"
                            :title="lgpd(p).hint"
                            :data-lgpd="lgpd(p).code"
                            ><i :class="`ti ${lgpd(p).icon} me-1`" aria-hidden="true"></i>{{ lgpd(p).label }}</span
                        >
                        <span v-else class="text-muted" aria-hidden="true">—</span>
                    </td>

                    <td class="text-end">
                        <ActionIconGroup align="end" gap="tight">
                            <ActionIconButton
                                icon="ti ti-eye"
                                :title="`${t.action_view ?? 'Ver detalhes'} — ${p.label}`"
                                :data-provider-view="p.code"
                                @click="$emit('view', p.code)"
                            />
                            <ActionIconButton
                                :icon="testing === p.code ? 'ti ti-loader-2' : 'ti ti-plug-connected'"
                                :title="`${t.test_connection ?? 'Testar conexão'} — ${p.label}`"
                                :disabled="!p.configured || testing === p.code"
                                :data-provider-test="p.code"
                                @click="$emit('test', p.code)"
                            />
                        </ActionIconGroup>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
