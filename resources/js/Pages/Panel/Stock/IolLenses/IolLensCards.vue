<script setup>
import { computed } from 'vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Cards de lentes IOL no padrão de Patients/PatientCards: foto, modelo,
 * status, dados principais em linhas rotuladas e as mesmas ações da
 * IolLensTable. Usa o MESMO paginator da tabela (prop `items`), sem
 * endpoint extra.
 */
const props = defineProps({
    items: { type: Object, required: true }, // paginator Laravel
    t: { type: Object, default: () => ({}) },
    movementsIndexUrl: { type: String, default: '' },
});

const emit = defineEmits(['edit', 'toggleActive', 'delete']);

const { tx } = useTrans(() => props.t);
const { money, number, quantity } = useLocaleFormat();

const rows = computed(() => props.items?.data ?? []);

function isBlank(value) {
    return value === null || value === undefined || value === '' || Number.isNaN(Number(value));
}

/** Dioptria com sinal explícito (+/−), informação clínica relevante. */
function diopter(value) {
    const n = Number(value);

    return `${n > 0 ? '+' : ''}${number(n, 1)}`;
}

function diopterRange(lens) {
    if (isBlank(lens.diopter_min) || isBlank(lens.diopter_max)) return '—';

    return tx('diopter_range', { min: diopter(lens.diopter_min), max: diopter(lens.diopter_max) });
}

function stockLabel(lens) {
    if (!lens.stock) return '—';

    return [quantity(lens.stock.qty_on_hand), lens.stock.unit_label].filter(Boolean).join(' ');
}

function movementsUrl(lens) {
    const productId = lens.stock?.id ?? lens.entity_product_id;
    if (!props.movementsIndexUrl || !productId) return null;

    return `${props.movementsIndexUrl}?${new URLSearchParams({ entity_product_id: productId })}`;
}
</script>

<template>
    <div v-if="rows.length === 0" class="text-center text-muted py-5">
        <i class="ti ti-eye-off fs-1 mb-3 d-block" aria-hidden="true"></i>
        <p>{{ t.empty_list ?? 'Nenhuma lente encontrada.' }}</p>
    </div>

    <div v-else class="row g-3">
        <div v-for="lens in rows" :key="lens.id" class="col-12 col-sm-6 col-md-4 col-xl-3">
            <div class="card card-body h-100">
                <div class="d-flex align-items-center gap-3">
                    <img
                        v-if="lens.image_url"
                        :src="lens.image_url"
                        :alt="lens.model_name ?? ''"
                        class="rounded flex-shrink-0"
                        width="56"
                        height="56"
                        loading="lazy"
                        style="object-fit: cover"
                    />
                    <span
                        v-else
                        class="rounded bg-body-tertiary border d-inline-flex align-items-center justify-content-center flex-shrink-0 text-body-secondary fs-4"
                        style="width: 56px; height: 56px"
                        aria-hidden="true"
                        ><i class="ti ti-eye"></i
                    ></span>
                    <div class="min-w-0">
                        <h6 class="mb-1 fw-semibold lh-sm text-break">{{ lens.model_name }}</h6>
                        <span
                            :class="
                                lens.active
                                    ? 'badge badge-soft-success rounded text-success border border-success fs-12'
                                    : 'badge badge-soft-danger rounded text-danger border border-danger fs-12'
                            "
                            >{{ lens.active ? (t.status_active ?? 'Ativa') : (t.status_inactive ?? 'Inativa') }}</span
                        >
                    </div>
                </div>

                <!-- Linhas rotuladas no estilo de PatientCards ("Rótulo: valor"). -->
                <dl class="small text-muted mt-2 mb-1">
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_manufacturer ?? 'Fabricante' }}:</dt>
                        <dd class="mb-0 text-break">{{ lens.manufacturer ?? '—' }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_category ?? 'Tipo' }}:</dt>
                        <dd class="mb-0 text-break">{{ lens.category || '—' }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_diopters ?? 'Dioptrias' }}:</dt>
                        <dd class="mb-0">{{ diopterRange(lens) }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_price ?? 'Valor' }}:</dt>
                        <dd class="mb-0">
                            {{ isBlank(lens.price) ? (t.not_informed ?? 'Não informado') : money(lens.price) }}
                        </dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_stock ?? 'Estoque' }}:</dt>
                        <dd class="mb-0">{{ stockLabel(lens) }}</dd>
                    </div>
                </dl>

                <hr class="my-2 mt-auto" />

                <ActionIconGroup align="end" gap="tight">
                    <ActionIconButton
                        v-if="movementsUrl(lens)"
                        icon="ti ti-transfer-in"
                        :title="t.action_movements ?? 'Movimentações da lente'"
                        variant="info"
                        :inertia-href="movementsUrl(lens)"
                    />
                    <ActionDropdown
                        :title="t.more_actions ?? 'Mais ações'"
                        btn-class="ee-action-icon ee-action-icon--default"
                        icon="ti ti-dots-vertical"
                    >
                        <li>
                            <button type="button" class="dropdown-item rounded-1" @click="emit('edit', lens)">
                                <i class="ti ti-edit me-1" aria-hidden="true"></i> {{ t.action_edit ?? 'Editar' }}
                            </button>
                        </li>
                        <li>
                            <button type="button" class="dropdown-item rounded-1" @click="emit('toggleActive', lens)">
                                <i
                                    :class="`ti me-1 ${lens.active ? 'ti-lock-open' : 'ti-lock'}`"
                                    aria-hidden="true"
                                ></i>
                                {{
                                    lens.active ? (t.action_deactivate ?? 'Desativar') : (t.action_activate ?? 'Ativar')
                                }}
                            </button>
                        </li>
                        <li><hr class="dropdown-divider" /></li>
                        <li>
                            <button
                                type="button"
                                class="dropdown-item rounded-1 text-danger"
                                @click="emit('delete', lens)"
                            >
                                <i class="ti ti-trash me-1" aria-hidden="true"></i> {{ t.action_delete ?? 'Excluir' }}
                            </button>
                        </li>
                    </ActionDropdown>
                </ActionIconGroup>
            </div>
        </div>
    </div>

    <TablePagination
        :data="items"
        :showing-from="t.pagination_showing"
        :showing-of="t.pagination_of"
        :showing-suffix="t.pagination_suffix"
        :aria-label="t.pagination_label"
        :previous-label="t.pagination_previous"
        :next-label="t.pagination_next"
    />
</template>

<style scoped>
/* Permite quebrar nomes longos de modelo ao lado da foto. */
.min-w-0 {
    min-width: 0;
}
</style>
