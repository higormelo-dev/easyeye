<script setup>
import TablePagination from '@/Components/Panel/TablePagination.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { usageHint, usageLocked } from './cid10Presenter.js';

/**
 * Catálogo CID-10 em cards — mesmo layout de Manager → Medicamentos. Usa a
 * mesma página/filtros da tabela (paginação server-side do Inertia).
 */
defineProps({
    codes: { type: Object, required: true },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['view', 'edit', 'delete']);

const { number } = useLocaleFormat();
</script>

<template>
    <div v-if="codes.data.length === 0" class="text-center text-muted py-5">
        <i class="ti ti-stethoscope fs-1 mb-2 d-block" aria-hidden="true"></i>
        <p>{{ t.empty }}</p>
    </div>

    <div v-else class="row g-3">
        <div v-for="c in codes.data" :key="c.id" class="col-sm-6 col-xl-4" :data-code="c.code">
            <div class="card card-body h-100">
                <div class="d-flex align-items-start gap-3">
                    <div
                        class="rounded-circle bg-info-subtle d-flex align-items-center justify-content-center flex-shrink-0 fw-semibold text-info-emphasis"
                        style="width: 52px; height: 44px; font-size: 0.8rem"
                    >
                        {{ c.code }}
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <h6 class="mb-1 fw-semibold lh-sm">{{ c.description }}</h6>
                        <div class="d-flex flex-wrap gap-1 mb-1">
                            <span
                                v-if="c.chapter"
                                class="badge rounded fs-11 badge-soft-secondary"
                                :title="c.chapter_name"
                                >{{ c.chapter }}</span
                            >
                            <span
                                v-if="c.is_custom"
                                class="badge rounded fs-11 badge-soft-purple"
                                :title="t.custom_hint"
                                >{{ t.badge_custom }}</span
                            >
                            <span
                                v-if="c.is_edited"
                                class="badge rounded fs-11 badge-soft-warning"
                                :title="(t.edited_hint ?? '').replace(':official', c.official_description ?? '')"
                                >{{ t.badge_edited }}</span
                            >
                        </div>
                        <div v-if="c.category" class="text-muted small text-truncate">{{ c.category }}</div>
                        <div class="small mt-1" :title="usageHint(c, t, number)">
                            <i class="ti ti-building-hospital me-1 text-muted" aria-hidden="true"></i>
                            <span v-if="c.usage.total > 0">{{ usageHint(c, t, number) }}</span>
                            <span v-else class="text-muted">{{ t.usage_none }}</span>
                        </div>
                    </div>
                </div>

                <hr class="my-2 mt-auto" />

                <ActionIconGroup align="end" gap="tight">
                    <ActionIconButton icon="ti ti-eye" :title="t.action_view" @click="$emit('view', c)" />
                    <ActionIconButton icon="ti ti-edit" :title="t.edit" @click="$emit('edit', c)" />
                    <ActionIconButton
                        icon="ti ti-trash"
                        variant="danger"
                        :disabled="usageLocked(c)"
                        :title="
                            usageLocked(c)
                                ? (t.delete_blocked ?? '').replace(':count', number(c.usage.total + c.usage.links))
                                : t.delete
                        "
                        @click="$emit('delete', c)"
                    />
                </ActionIconGroup>
            </div>
        </div>
    </div>

    <TablePagination
        class="mt-3"
        :data="codes"
        :showing-from="t.showing_from"
        :showing-of="t.showing_of"
        :showing-suffix="t.showing_suffix"
        :previous-label="t.previous"
        :next-label="t.next"
    />
</template>
