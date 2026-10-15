<script setup>
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import { makeTx, stateBadgeClass } from '@/Support/recallRule.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * Manager → um protocolo de retorno: as versões (da mais nova para a mais antiga) e o
 * histórico de governança. "Nova versão" abre um rascunho a partir da última.
 */
defineOptions({ name: 'ManagerRecallProtocolShow' });

const props = defineProps({
    protocol: { type: Object, required: true },
    versions: { type: Array, default: () => [] },
    canStartRevision: { type: Boolean, default: false },
    events: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
});

const tx = makeTx(() => props.t);
const { date, dateTime } = useLocaleFormat();

function startRevision() {
    router.post(route('manager.recall-protocols.versions.store', props.protocol.id), {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="protocol.title" :breadcrumbs="[]">
        <div class="container-fluid py-3">
            <PageHeader :title="protocol.title" :subtitle="protocol.key">
                <template #actions>
                    <Link :href="route('manager.recall-protocols.index')" class="btn btn-light btn-sm">
                        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ tx('show.back') }}
                    </Link>
                    <span :title="canStartRevision ? '' : tx('show.new_revision_blocked')">
                        <button
                            type="button"
                            class="btn btn-primary btn-sm"
                            :disabled="!canStartRevision"
                            data-testid="new-revision"
                            @click="startRevision"
                        >
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ tx('show.new_revision') }}
                        </button>
                    </span>
                </template>
            </PageHeader>

            <p v-if="protocol.summary" class="text-body-secondary">{{ protocol.summary }}</p>

            <section class="mb-4" aria-labelledby="versions-heading">
                <h2 id="versions-heading" class="h6 fw-semibold">{{ tx('show.versions') }}</h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle" data-testid="versions-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ tx('show.columns.version') }}</th>
                                <th scope="col">{{ tx('show.columns.state') }}</th>
                                <th scope="col">{{ tx('show.columns.hash') }}</th>
                                <th scope="col">{{ tx('show.columns.frozen') }}</th>
                                <th scope="col">{{ tx('show.columns.validated') }}</th>
                                <th scope="col">{{ tx('show.columns.released') }}</th>
                                <th scope="col" class="text-end">{{ tx('show.columns.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="version in versions"
                                :key="version.id"
                                :data-testid="`version-${version.version}`"
                            >
                                <th scope="row">
                                    v{{ version.version }}
                                    <small
                                        v-if="version.parent_version"
                                        class="d-block fw-normal text-body-secondary"
                                        >{{ tx('show.from_version', { version: version.parent_version }) }}</small
                                    >
                                </th>
                                <td>
                                    <span class="badge fw-normal" :class="stateBadgeClass(version.state)">{{
                                        version.state_label
                                    }}</span>
                                </td>
                                <td>
                                    <code :title="version.content_hash">{{ version.short_hash }}</code>
                                </td>
                                <td>{{ version.frozen_at ? date(version.frozen_at) : '—' }}</td>
                                <td>{{ version.validated_at ? date(version.validated_at) : '—' }}</td>
                                <td>{{ version.released_at ? date(version.released_at) : '—' }}</td>
                                <td class="text-end">
                                    <Link
                                        :href="
                                            route('manager.recall-protocols.versions.show', [protocol.id, version.id])
                                        "
                                        class="btn btn-outline-primary btn-sm"
                                    >
                                        {{ version.state === 'draft' ? tx('show.edit') : tx('show.open') }}
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section aria-labelledby="history-heading">
                <h2 id="history-heading" class="h6 fw-semibold">{{ tx('show.history') }}</h2>
                <p v-if="!events.length" class="text-body-secondary">{{ tx('show.no_history') }}</p>
                <div v-else class="table-responsive">
                    <table class="table table-sm align-middle" data-testid="events-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ tx('show.event_columns.when') }}</th>
                                <th scope="col">{{ tx('show.event_columns.event') }}</th>
                                <th scope="col">{{ tx('show.event_columns.version') }}</th>
                                <th scope="col">{{ tx('show.event_columns.actor') }}</th>
                                <th scope="col">{{ tx('show.event_columns.reason') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="event in events" :key="event.id">
                                <td class="text-nowrap">{{ dateTime(event.created_at) }}</td>
                                <td>
                                    {{ event.label }}
                                    <small
                                        v-if="event.from_label || event.to_label"
                                        class="d-block text-body-secondary"
                                    >
                                        {{ event.from_label ?? '—' }} → {{ event.to_label ?? '—' }}
                                    </small>
                                </td>
                                <td>{{ event.version ? `v${event.version}` : '—' }}</td>
                                <td>{{ event.actor }}</td>
                                <td class="text-break">{{ event.reason ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
