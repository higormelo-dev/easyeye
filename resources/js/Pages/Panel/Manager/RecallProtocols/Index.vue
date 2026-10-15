<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import { makeTx, stateBadgeClass } from '@/Support/recallRule.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * Manager → Protocolos de retorno (recall): um cartão por condição, com a versão
 * liberada e a versão em andamento. As regras só chegam às clínicas depois de validadas
 * por um Revisor Clínico (ADR 0002).
 */
defineOptions({ name: 'ManagerRecallProtocolsIndex' });

const props = defineProps({
    protocols: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
});

const tx = makeTx(() => props.t);
const { date } = useLocaleFormat();
</script>

<template>
    <AppLayout :title="tx('title')" :breadcrumbs="[]">
        <div class="container-fluid py-3">
            <PageHeader :title="tx('title')" :subtitle="tx('subtitle')" />

            <div class="alert alert-info small" role="note">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ tx('intro') }}
            </div>

            <p v-if="!protocols.length" class="text-body-secondary" data-testid="protocols-empty">{{ tx('empty') }}</p>

            <div v-else class="row g-3">
                <div v-for="protocol in protocols" :key="protocol.id" class="col-12 col-md-6 col-xl-4">
                    <article class="card h-100" :data-testid="`protocol-${protocol.key}`">
                        <div class="card-body">
                            <h2 class="h6 mb-1 text-break">
                                <Link :href="route('manager.recall-protocols.show', protocol.id)">{{
                                    protocol.title
                                }}</Link>
                            </h2>
                            <code class="small text-body-secondary">{{ protocol.key }}</code>
                            <p class="small text-body-secondary mt-2">{{ protocol.summary }}</p>

                            <dl class="small mb-0">
                                <dt>{{ tx('index.released') }}</dt>
                                <dd v-if="protocol.released" data-testid="released">
                                    v{{ protocol.released.version }}
                                    <code>{{ protocol.released.short_hash }}</code>
                                    <span v-if="protocol.released.released_at" class="text-body-secondary">
                                        · {{ tx('index.since', { date: date(protocol.released.released_at) }) }}
                                    </span>
                                    <span
                                        v-if="protocol.released.review_overdue"
                                        class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle fw-normal ms-1"
                                        data-testid="review-overdue"
                                        >{{ tx('index.review_overdue') }}</span
                                    >
                                </dd>
                                <dd v-else class="text-warning-emphasis" data-testid="no-released">
                                    <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                                    >{{ tx('index.no_released') }}
                                </dd>

                                <dt>{{ tx('index.in_progress') }}</dt>
                                <dd v-if="protocol.open" class="mb-0" data-testid="open">
                                    v{{ protocol.open.version }}
                                    <span class="badge fw-normal ms-1" :class="stateBadgeClass(protocol.open.state)">{{
                                        protocol.open.state_label
                                    }}</span>
                                </dd>
                                <dd v-else class="mb-0 text-body-secondary">—</dd>
                            </dl>
                        </div>
                        <div class="card-footer bg-transparent d-flex align-items-center gap-2">
                            <Link
                                :href="route('manager.recall-protocols.show', protocol.id)"
                                class="btn btn-outline-primary btn-sm"
                            >
                                {{ tx('index.open') }}
                            </Link>
                            <span class="ms-auto small text-body-secondary">{{
                                tx('index.versions', { count: protocol.versions_count })
                            }}</span>
                        </div>
                    </article>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
