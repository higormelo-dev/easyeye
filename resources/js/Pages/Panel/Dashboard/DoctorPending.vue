<script setup>
import { computed } from 'vue';
import PendingList from './PendingList.vue';

/**
 * "Minhas pendências" do médico: laudos de IA a revisar (só com IA na
 * clínica) e prontuários sem assinatura. Cada pendência zerada vira uma linha
 * discreta ("tudo em dia"), sem card grande vazio.
 */
const props = defineProps({
    aiWaiting: { type: Object, default: null },
    unsignedRecords: { type: Object, default: null },
    t: { type: Object, required: true },
});

const aiItems = computed(() =>
    (props.aiWaiting?.items ?? []).map((run) => ({
        id: run.id,
        title: run.patient,
        subtitle: [run.workflow, run.created].filter(Boolean).join(' · '),
        url: run.url,
    })),
);
const unsignedItems = computed(() =>
    (props.unsignedRecords?.items ?? []).map((record) => ({
        id: record.id,
        title: record.patient,
        subtitle: [record.date, record.code].filter(Boolean).join(' · '),
        url: record.url,
    })),
);
const days = computed(() => String(props.unsignedRecords?.days ?? 30));
const total = computed(() => Number(props.aiWaiting?.count ?? 0) + Number(props.unsignedRecords?.count ?? 0));
</script>

<template>
    <section class="card db-card doctor-pending" :aria-label="t.section_pending" data-section="pending">
        <div class="db-card-header">
            <h3 class="db-card-title">
                <i class="ti ti-checklist" aria-hidden="true"></i>
                {{ t.section_pending }}
                <span class="badge db-count" :class="{ 'db-count--active': total > 0 }">{{ total }}</span>
            </h3>
        </div>
        <div class="db-card-body db-pending">
            <PendingList
                v-if="aiWaiting"
                data-tour="dashboard-ai-waiting"
                :title="t.ai_waiting_title"
                icon="ti ti-sparkles"
                :count="aiWaiting.count ?? 0"
                :items="aiItems"
                :empty-text="t.ai_waiting_empty"
                :action-label="t.ai_waiting_review"
                :see-all-url="aiWaiting.count > 0 ? aiWaiting.list_url : null"
                :t="t"
            />
            <PendingList
                data-tour="dashboard-unsigned-records"
                :title="t.unsigned_title"
                icon="ti ti-signature"
                :count="unsignedRecords?.count ?? 0"
                :items="unsignedItems"
                :hint="(t.unsigned_hint ?? '').replace(':days', days)"
                :empty-text="(t.unsigned_empty ?? '').replace(':days', days)"
                :action-label="t.btn_open_record"
                :t="t"
            />
        </div>
    </section>
</template>
