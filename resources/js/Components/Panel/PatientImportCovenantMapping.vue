<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { latestRequest, isAbortError } from '@/Support/latestRequest.js';

const props = defineProps({
    importId: { type: String, required: true },
    items: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    permissions: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
    mode: { type: String, default: 'preview' },
    t: { type: Object, default: () => ({}) },
    busy: { type: Boolean, default: false },
    filesExpired: { type: Boolean, default: false },
    namesCapped: { type: Boolean, default: false },
    reportAvailable: { type: Boolean, default: true },
});
const emit = defineEmits(['saved', 'reprocessed']);
const uid = `import-mapping-${useId()}`;
const form = useForm({ mappings: [] });
const rows = ref([]),
    selected = ref(new Set()),
    expanded = ref(new Set()),
    dirty = ref(new Set());
const drafts = reactive(Object.create(null)),
    rowErrors = reactive(Object.create(null)),
    plans = reactive(Object.create(null));
const covenantOptions = ref([]);
const planPins = new Map();
const confirmedClears = ref(new Set());
const bulk = reactive({ action: 'map', covenant_id: '', covenant_plan_id: null });
const filter = ref('pending'),
    search = ref(''),
    saving = ref(false),
    notice = ref(''),
    confirmation = ref(null);
const controlRefs = new Map(),
    requests = new Map();
const working = computed(() => saving.value || props.busy);
const pendingStatuses = ['unmatched', 'inactive', 'invalid_mapping'];
const pending = computed(() => rows.value.filter((row) => pendingStatuses.includes(row.status)));
const found = computed(() => rows.value.filter((row) => !pendingStatuses.includes(row.status)));
const visible = computed(() =>
    (filter.value === 'pending' ? pending.value : filter.value === 'found' ? found.value : rows.value).filter((row) =>
        row.source_name.toLocaleLowerCase().includes(search.value.toLocaleLowerCase()),
    ),
);
const selectedPending = computed(
    () => pending.value.filter((row) => selected.value.has(row.source_name_normalized)).length,
);
const hasChanges = computed(() => dirty.value.size > 0);
const canReprocess = computed(
    () =>
        !working.value &&
        !props.filesExpired &&
        props.reportAvailable &&
        props.permissions.can_reprocess &&
        props.totals.failed_rows > 0,
);
const actions = computed(() => [
    'map',
    ...(props.permissions.can_create_covenant ? ['create'] : []),
    'particular',
    'clear',
]);
const tr = (key, args = {}) =>
    Object.entries(args).reduce(
        (text, [name, value]) => text.replaceAll(`:${name}`, String(value)),
        props.t[key] ?? key,
    );
const id = (key, field) => `${uid}-${key}-${field}`;
const sourceLabel = (row) => row.source_name || tr('empty_source');
const errorText = (key) => Object.values(rowErrors[key] ?? {}).join(' ');
const requestFor = (key) => {
    if (!requests.has(key)) requests.set(key, latestRequest());
    return requests.get(key);
};
const searchUrl = (base, params = {}) => `${base}${base.includes('?') ? '&' : '?'}${new URLSearchParams(params)}`;
const remoteUrl = (base, params = {}) => `${searchUrl(base, params)}${Object.keys(params).length ? '&' : ''}q=__Q__`;
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const headers = () => ({ Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() });
const optionsFor = (row) => {
    const current = row?.covenant;
    return current?.active !== false && current?.id && !covenantOptions.value.some((option) => option.id === current.id)
        ? [...covenantOptions.value, { ...current, label: current.label ?? current.name }]
        : covenantOptions.value;
};
function pinCovenant(option) {
    if (option?.id && !covenantOptions.value.some((row) => row.id === option.id)) covenantOptions.value.push(option);
}
function pinPlan(key, option) {
    const draft = key === 'bulk' ? bulk : drafts[key];
    if (option?.id && draft.covenant_id) planPins.set(`${draft.covenant_id}:${option.id}`, option);
}
function reset(items) {
    rows.value = items.map((row) => ({ ...row }));
    for (const key of Object.keys(drafts)) delete drafts[key];
    for (const row of items)
        drafts[row.source_name_normalized] = {
            action: row.status === 'particular' ? 'particular' : 'map',
            covenant_id:
                row.status === 'invalid_mapping' || row.covenant?.active === false ? '' : (row.covenant?.id ?? ''),
            covenant_plan_id: row.status === 'invalid_mapping' ? null : (row.plan?.id ?? null),
        };
    selected.value = new Set();
    confirmedClears.value = new Set();
    dirty.value = new Set();
    expanded.value = new Set();
}
watch(() => props.items, reset, { immediate: true });
watch(
    () => props.importId,
    () => {
        for (const req of requests.values()) req.cancel();
        notice.value = '';
        planPins.clear();
        covenantOptions.value = [];
        seedCovenants();
        for (const key of Object.keys(rowErrors)) delete rowErrors[key];
    },
);
onBeforeUnmount(() => {
    for (const req of requests.values()) req.cancel();
});
async function seedCovenants() {
    if (!props.urls.covenantSearch) return;
    const request = requestFor('catalog').begin();
    try {
        const response = await fetch(props.urls.covenantSearch, {
            headers: { Accept: 'application/json' },
            signal: request.signal,
        });
        const json = await response.json();
        if (!request.current()) return;
        if (!response.ok) throw new Error();
        covenantOptions.value = json.data ?? [];
    } catch (error) {
        if (request.current() && !isAbortError(error)) notice.value = tr('search_failed');
    }
}
onMounted(seedCovenants);
async function loadPlans(key, covenantId) {
    const request = requestFor(`plans-${key}`).begin();
    plans[key] = [];
    if (!covenantId || !props.urls.covenantPlanSearch) return;
    try {
        const response = await fetch(searchUrl(props.urls.covenantPlanSearch, { covenant_id: covenantId }), {
            headers: { Accept: 'application/json' },
            signal: request.signal,
        });
        const json = await response.json();
        if (!request.current()) return;
        if (!response.ok) throw new Error();
        plans[key] = json.data ?? [];
        const row = rows.value.find((row) => row.source_name_normalized === key);
        const current = row?.covenant?.id === covenantId && row.status !== 'invalid_mapping' ? row.plan : null;
        if (current?.id && current.active !== false && !plans[key].some((plan) => plan.id === current.id))
            plans[key].push({ ...current, label: current.label ?? current.name });
        const draft = key === 'bulk' ? bulk : drafts[key];
        const pinned = planPins.get(`${covenantId}:${draft.covenant_plan_id}`);
        if (pinned && !plans[key].some((plan) => plan.id === pinned.id)) plans[key].push(pinned);
    } catch (error) {
        if (request.current() && !isAbortError(error)) notice.value = tr('search_failed');
    }
}
function targetChanged(key, value) {
    const draft = key === 'bulk' ? bulk : drafts[key];
    draft.covenant_id = value;
    draft.covenant_plan_id = null;
    if (key !== 'bulk') dirty.value.add(key);
    loadPlans(key, value);
}
function mark(key) {
    confirmedClears.value.delete(key);
    dirty.value.add(key);
    notice.value = '';
}
function toggleSelected(key, checked) {
    if (checked) selected.value.add(key);
    else selected.value.delete(key);
}
function selectPending(checked) {
    for (const row of pending.value) toggleSelected(row.source_name_normalized, checked);
}
async function edit(row) {
    const key = row.source_name_normalized;
    if (expanded.value.has(key)) expanded.value.delete(key);
    else {
        expanded.value.add(key);
        await loadPlans(key, drafts[key].covenant_id);
    }
}
function valid(draft) {
    return draft.action === 'map'
        ? !!draft.covenant_id
        : draft.action !== 'create' || props.permissions.can_create_covenant;
}
function commitBulk(keys, draft) {
    if (!valid(draft)) {
        notice.value = tr(draft.action === 'map' ? 'select_covenant' : 'create_requires_settings');
        return;
    }
    for (const key of keys) {
        drafts[key] = {
            action: draft.action,
            covenant_id: draft.action === 'map' ? draft.covenant_id : '',
            covenant_plan_id: draft.action === 'map' ? draft.covenant_plan_id || null : null,
        };
        dirty.value.add(key);
        if (draft.action === 'clear') confirmedClears.value.add(key);
        else confirmedClears.value.delete(key);
    }
    notice.value = tr('draft_applied', { count: keys.length });
}
function applyBulk() {
    if (working.value || !selected.value.size) return;
    const keys = [...selected.value];
    if (bulk.action === 'clear') confirmation.value = { kind: 'clear', keys };
    else commitBulk(keys, bulk);
}
function applyRow(key) {
    if (drafts[key].action === 'clear') confirmation.value = { kind: 'clear', keys: [key] };
    else if (!valid(drafts[key])) {
        rowErrors[key] = { covenant_id: tr('select_covenant') };
        notice.value = tr('select_covenant');
        focusRow(key, 'covenant_id');
    } else {
        dirty.value.add(key);
        notice.value = tr('draft_applied', { count: 1 });
    }
}
async function focusRow(key, field) {
    filter.value = 'all';
    search.value = '';
    expanded.value.add(key);
    await nextTick();
    const targetField = ['covenant_id', 'covenant_plan_id'].includes(field) ? field : 'action';
    if (targetField === 'covenant_id' && drafts[key].action !== 'map') {
        drafts[key].action = 'map';
        await nextTick();
    }
    const control = controlRefs.get(`${key}-${targetField}`);
    control?.focus();
}
function payload() {
    return rows.value
        .filter((row) => dirty.value.has(row.source_name_normalized))
        .map((row) => {
            const draft = drafts[row.source_name_normalized];
            return {
                source_name: row.source_name,
                action: draft.action,
                ...(draft.action === 'map'
                    ? { covenant_id: draft.covenant_id, covenant_plan_id: draft.covenant_plan_id || null }
                    : {}),
            };
        });
}
async function save() {
    if (working.value) return false;
    if (!hasChanges.value) return true;
    const mappings = payload();
    if (mappings.length > 1000) {
        notice.value = tr('too_many_mappings');
        return false;
    }
    const unconfirmed = [...dirty.value].filter(
        (key) => drafts[key].action === 'clear' && !confirmedClears.value.has(key),
    );
    if (unconfirmed.length) {
        confirmation.value = { kind: 'clear', keys: unconfirmed };
        return false;
    }
    for (const row of rows.value.filter((item) => dirty.value.has(item.source_name_normalized))) {
        if (!valid(drafts[row.source_name_normalized])) {
            rowErrors[row.source_name_normalized] = { covenant_id: tr('select_covenant') };
            notice.value = tr('select_covenant');
            confirmation.value = null;
            await focusRow(row.source_name_normalized, 'covenant_id');
            return false;
        }
    }
    form.mappings = mappings;
    form.clearErrors();
    for (const key of Object.keys(rowErrors)) delete rowErrors[key];
    saving.value = true;
    notice.value = '';
    try {
        const response = await fetch(props.urls.update, {
            method: 'PUT',
            headers: headers(),
            body: JSON.stringify({ mappings }),
        });
        const json = await response.json();
        if (!response.ok) {
            form.setError(
                Object.fromEntries(
                    Object.entries(json.errors ?? {}).map(([key, messages]) => [key, [].concat(messages)[0]]),
                ),
            );
            for (const [path, message] of Object.entries(form.errors)) {
                const match = path.match(/^mappings\.(\d+)\.(\w+)$/);
                if (!match) continue;
                const source = mappings[Number(match[1])]?.source_name;
                const row = rows.value.find((item) => item.source_name === source);
                if (row) {
                    const key = row.source_name_normalized;
                    rowErrors[key] = { ...rowErrors[key], [match[2]]: message };
                }
            }
            notice.value = Object.values(form.errors).join(' ') || json.message || tr('save_failed');
            saving.value = false;
            confirmation.value = null;
            const first = rows.value.find((row) => rowErrors[row.source_name_normalized]);
            if (first)
                await focusRow(first.source_name_normalized, Object.keys(rowErrors[first.source_name_normalized])[0]);
            return false;
        }
        reset(json.data.items);
        notice.value = tr('mapping_saved');
        emit('saved', json.data);
        return true;
    } catch {
        confirmation.value = null;
        notice.value = tr('save_failed');
        return false;
    } finally {
        saving.value = false;
    }
}
async function reprocess() {
    if (!canReprocess.value) return;
    if (!(await save())) return;
    saving.value = true;
    try {
        const response = await fetch(props.urls.reprocess, { method: 'POST', headers: headers() });
        const json = await response.json();
        if (!response.ok) {
            confirmation.value = null;
            notice.value = json.message || tr('save_failed');
            return;
        }
        confirmation.value = null;
        notice.value = tr('reprocess_started');
        emit('reprocessed', json.data);
    } catch {
        confirmation.value = null;
        notice.value = tr('save_failed');
    } finally {
        saving.value = false;
    }
}
function confirmAction() {
    if (working.value || !confirmation.value) return;
    if (confirmation.value.kind === 'reprocess') return reprocess();
    if (confirmation.value.kind === 'particular') commitBulk(confirmation.value.keys, { action: 'particular' });
    else commitBulk(confirmation.value.keys, { action: 'clear' });
    confirmation.value = null;
}
defineExpose({ save, hasChanges, unresolvedCount: computed(() => pending.value.length) });
</script>

<template>
    <section
        data-ee-density="default"
        class="covenant-mapping card mb-3"
        :aria-labelledby="id('section', 'title')"
        :aria-busy="working"
    >
        <div class="card-body">
            <h2 :id="id('section', 'title')" class="h5">{{ tr('title') }}</h2>
            <p class="text-muted">{{ tr('subtitle') }}</p>
            <div aria-live="polite" aria-atomic="true" class="mb-2">
                <span v-if="notice" role="status">{{ notice }}</span>
            </div>
            <p v-if="namesCapped" class="alert alert-warning" role="status" data-test="names-capped">
                {{ tr('names_capped') }}
            </p>
            <p v-if="filesExpired" class="alert alert-warning">{{ tr('expired') }}</p>
            <p v-if="!reportAvailable" class="alert alert-warning">{{ tr('report_unavailable') }}</p>
            <fieldset :disabled="working || !reportAvailable">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button
                        v-for="name in ['pending', 'all', 'found']"
                        :key="name"
                        type="button"
                        class="btn btn-outline-secondary"
                        :aria-pressed="filter === name"
                        :data-filter="name"
                        @click="filter = name"
                    >
                        {{ tr(`filter_${name}`) }} ({{
                            name === 'pending' ? pending.length : name === 'found' ? found.length : rows.length
                        }})
                    </button>
                </div>
                <label :for="id('section', 'search')" class="form-label">{{ tr('search') }}</label>
                <input
                    :id="id('section', 'search')"
                    v-model="search"
                    type="search"
                    class="form-control mb-3"
                    data-test="mapping-search"
                />
                <div class="form-check mb-3">
                    <input
                        :id="id('section', 'select')"
                        type="checkbox"
                        class="form-check-input"
                        data-test="select-pending"
                        :checked="pending.length > 0 && selectedPending === pending.length"
                        :indeterminate="selectedPending > 0 && selectedPending < pending.length"
                        @change="selectPending($event.target.checked)"
                    /><label :for="id('section', 'select')" class="form-check-label">{{ tr('select_pending') }}</label>
                </div>
                <div class="border rounded p-3 mb-3">
                    <div class="row g-3">
                        <div class="col-12 col-lg-3">
                            <label :for="id('bulk', 'action')" class="form-label">{{ tr('bulk_action') }}</label
                            ><select
                                :id="id('bulk', 'action')"
                                v-model="bulk.action"
                                class="form-select"
                                data-test="bulk-action"
                            >
                                <option v-for="action in actions" :key="action" :value="action">
                                    {{ tr(`action_${action}`) }}
                                </option>
                            </select>
                        </div>
                        <template v-if="bulk.action === 'map'">
                            <div class="col-12 col-lg-4">
                                <label
                                    :id="id('bulk', 'covenant-label')"
                                    :for="id('bulk', 'covenant')"
                                    class="form-label"
                                    >{{ tr('target_covenant') }}</label
                                ><SearchSelect
                                    :id="id('bulk', 'covenant')"
                                    data-test="bulk-covenant"
                                    :model-value="bulk.covenant_id"
                                    :options="covenantOptions"
                                    label-key="label"
                                    :show-sub-label="true"
                                    :placeholder="tr('select')"
                                    :no-options-text="tr('no_options')"
                                    :no-results-text="tr('no_search_results')"
                                    :remote-search-url="remoteUrl(urls.covenantSearch)"
                                    :remote-min-chars="2"
                                    :aria-labelledby="id('bulk', 'covenant-label')"
                                    :aria-describedby="id('section', 'plan-hint')"
                                    :disabled="working"
                                    @update:model-value="targetChanged('bulk', $event)"
                                    @option-selected="pinCovenant"
                                />
                            </div>
                            <div class="col-12 col-lg-5">
                                <label :id="id('bulk', 'plan-label')" :for="id('bulk', 'plan')" class="form-label">{{
                                    tr('target_plan')
                                }}</label
                                ><SearchSelect
                                    :id="id('bulk', 'plan')"
                                    v-model="bulk.covenant_plan_id"
                                    data-test="bulk-plan"
                                    :options="plans.bulk ?? []"
                                    label-key="label"
                                    :show-sub-label="true"
                                    :placeholder="tr('select')"
                                    :no-options-text="tr('no_options')"
                                    :no-results-text="tr('no_search_results')"
                                    :remote-search-url="
                                        bulk.covenant_id
                                            ? remoteUrl(urls.covenantPlanSearch, { covenant_id: bulk.covenant_id })
                                            : ''
                                    "
                                    :aria-labelledby="id('bulk', 'plan-label')"
                                    :aria-describedby="id('section', 'plan-hint')"
                                    :disabled="working || !bulk.covenant_id"
                                    @option-selected="pinPlan('bulk', $event)"
                                />
                            </div>
                        </template>
                    </div>
                    <p v-if="bulk.action === 'particular'" class="form-text">{{ tr('particular_hint') }}</p>
                    <button
                        type="button"
                        class="btn btn-outline-primary mt-3"
                        data-test="bulk-apply"
                        :disabled="!selected.size"
                        @click="applyBulk"
                    >
                        {{ tr('bulk_apply') }} ({{ selected.size }})
                    </button>
                </div>
                <p :id="id('section', 'plan-hint')" class="form-text">{{ tr('plan_hint') }}</p>
                <p class="form-text">{{ tr('persistent_hint') }}</p>
                <p v-if="!permissions.can_create_covenant" class="form-text">{{ tr('create_requires_settings') }}</p>
                <p v-else class="form-text">{{ tr('create_hint') }}</p>
                <table class="table align-middle mapping-table">
                    <caption class="visually-hidden">
                        {{
                            tr('title')
                        }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ tr('select') }}</th>
                            <th scope="col">{{ tr('source_name') }}</th>
                            <th scope="col">{{ tr('counts') }}</th>
                            <th scope="col">{{ tr('status') }}</th>
                            <th scope="col">{{ tr('target_covenant') }}</th>
                            <th scope="col">{{ tr('edit') }}</th>
                        </tr>
                    </thead>
                    <tbody v-for="row in visible" :key="row.source_name_normalized" data-test="mapping-row">
                        <tr>
                            <td>
                                <input
                                    type="checkbox"
                                    data-test="row-select"
                                    :disabled="!row.source_name"
                                    :aria-label="`${tr('select')}: ${sourceLabel(row)}`"
                                    :checked="selected.has(row.source_name_normalized)"
                                    @change="toggleSelected(row.source_name_normalized, $event.target.checked)"
                                />
                            </td>
                            <th scope="row" class="text-break">{{ sourceLabel(row) }}</th>
                            <td>
                                {{ tr('rows', { count: row.rows })
                                }}<small v-if="row.failed_rows !== row.rows" class="d-block"
                                    >{{ totals.failed_rows_exact === false ? tr('approximate') : '' }}
                                    {{ tr('failed_rows', { count: row.failed_rows }) }}</small
                                >
                            </td>
                            <td>
                                <span
                                    class="badge"
                                    :class="{
                                        'bg-danger-subtle text-danger': ['unmatched', 'invalid_mapping'].includes(
                                            row.status,
                                        ),
                                        'bg-warning-subtle text-warning-emphasis': row.status === 'inactive',
                                        'bg-success-subtle text-success': row.status === 'found',
                                        'bg-primary-subtle text-primary': row.status === 'mapped',
                                        'bg-secondary-subtle text-secondary': row.status === 'particular',
                                    }"
                                    >{{ tr(`status_${row.status}`) }}</span
                                ><small v-if="dirty.has(row.source_name_normalized)" class="d-block">{{
                                    tr('pending_save')
                                }}</small>
                            </td>
                            <td>
                                {{
                                    row.status === 'invalid_mapping' || row.covenant?.active === false
                                        ? tr('target_unavailable_hint')
                                        : (row.covenant?.name ?? '—')
                                }}<small v-if="row.plan" class="d-block">{{ row.plan.name }}</small>
                            </td>
                            <td>
                                <button
                                    v-if="row.source_name"
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    data-test="row-edit"
                                    :aria-expanded="expanded.has(row.source_name_normalized)"
                                    :aria-controls="id(row.source_name_normalized, 'editor')"
                                    @click="edit(row)"
                                >
                                    {{ tr('edit') }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="expanded.has(row.source_name_normalized)">
                            <td colspan="6">
                                <div :id="id(row.source_name_normalized, 'editor')" class="border rounded p-3">
                                    <label :for="id(row.source_name_normalized, 'action')" class="form-label"
                                        >{{ tr('action') }}: {{ sourceLabel(row) }}</label
                                    ><select
                                        :id="id(row.source_name_normalized, 'action')"
                                        :ref="
                                            (control) =>
                                                controlRefs.set(`${row.source_name_normalized}-action`, control)
                                        "
                                        v-model="drafts[row.source_name_normalized].action"
                                        class="form-select mb-3"
                                        data-test="row-action"
                                        :aria-invalid="
                                            !!(
                                                rowErrors[row.source_name_normalized]?.action ||
                                                rowErrors[row.source_name_normalized]?.source_name
                                            )
                                        "
                                        :aria-describedby="id(row.source_name_normalized, 'errors')"
                                        @change="mark(row.source_name_normalized)"
                                    >
                                        <option v-for="action in actions" :key="action" :value="action">
                                            {{ tr(`action_${action}`) }}
                                        </option>
                                    </select>
                                    <template v-if="drafts[row.source_name_normalized].action === 'map'">
                                        <label
                                            :id="id(row.source_name_normalized, 'covenant-label')"
                                            :for="id(row.source_name_normalized, 'covenant')"
                                            class="form-label"
                                            >{{ tr('target_covenant') }}</label
                                        ><SearchSelect
                                            :id="id(row.source_name_normalized, 'covenant')"
                                            :ref="
                                                (control) =>
                                                    controlRefs.set(
                                                        `${row.source_name_normalized}-covenant_id`,
                                                        control,
                                                    )
                                            "
                                            :model-value="drafts[row.source_name_normalized].covenant_id"
                                            :options="optionsFor(row)"
                                            label-key="label"
                                            :show-sub-label="true"
                                            :placeholder="tr('select')"
                                            :no-options-text="tr('no_options')"
                                            :no-results-text="tr('no_search_results')"
                                            :remote-search-url="remoteUrl(urls.covenantSearch)"
                                            :aria-labelledby="id(row.source_name_normalized, 'covenant-label')"
                                            :aria-describedby="`${id('section', 'plan-hint')} ${id(row.source_name_normalized, 'errors')}`"
                                            :invalid="!!rowErrors[row.source_name_normalized]?.covenant_id"
                                            :disabled="working"
                                            @update:model-value="targetChanged(row.source_name_normalized, $event)"
                                            @option-selected="pinCovenant"
                                        />
                                        <label
                                            :id="id(row.source_name_normalized, 'plan-label')"
                                            :for="id(row.source_name_normalized, 'plan')"
                                            class="form-label mt-2"
                                            >{{ tr('target_plan') }}</label
                                        ><SearchSelect
                                            :id="id(row.source_name_normalized, 'plan')"
                                            :ref="
                                                (control) =>
                                                    controlRefs.set(
                                                        `${row.source_name_normalized}-covenant_plan_id`,
                                                        control,
                                                    )
                                            "
                                            v-model="drafts[row.source_name_normalized].covenant_plan_id"
                                            data-test="row-plan"
                                            :options="plans[row.source_name_normalized] ?? []"
                                            label-key="label"
                                            :show-sub-label="true"
                                            :placeholder="tr('select')"
                                            :no-options-text="tr('no_options')"
                                            :no-results-text="tr('no_search_results')"
                                            :remote-search-url="
                                                drafts[row.source_name_normalized].covenant_id
                                                    ? remoteUrl(urls.covenantPlanSearch, {
                                                          covenant_id: drafts[row.source_name_normalized].covenant_id,
                                                      })
                                                    : ''
                                            "
                                            :aria-labelledby="id(row.source_name_normalized, 'plan-label')"
                                            :aria-describedby="`${id('section', 'plan-hint')} ${id(row.source_name_normalized, 'errors')}`"
                                            :invalid="!!rowErrors[row.source_name_normalized]?.covenant_plan_id"
                                            :disabled="working || !drafts[row.source_name_normalized].covenant_id"
                                            @option-selected="pinPlan(row.source_name_normalized, $event)"
                                            @update:model-value="mark(row.source_name_normalized)"
                                        />
                                    </template>
                                    <p
                                        v-if="drafts[row.source_name_normalized].action === 'particular'"
                                        class="form-text"
                                    >
                                        {{ tr('particular_hint') }}
                                    </p>
                                    <p :id="id(row.source_name_normalized, 'errors')" class="text-danger small">
                                        {{ errorText(row.source_name_normalized) }}
                                    </p>
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary mt-2"
                                        data-test="row-apply"
                                        @click="applyRow(row.source_name_normalized)"
                                    >
                                        {{ tr('apply') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="!visible.length" class="text-muted">
                    {{ pending.length ? tr('no_results') : tr('no_pending') }}
                </p>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button
                        type="button"
                        class="btn btn-outline-secondary text-wrap"
                        :disabled="!pending.some((row) => row.status === 'unmatched')"
                        @click="
                            confirmation = {
                                kind: 'particular',
                                keys: pending
                                    .filter((row) => row.status === 'unmatched')
                                    .map((row) => row.source_name_normalized),
                            }
                        "
                    >
                        {{ tr('all_particular') }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        data-test="mapping-save"
                        :disabled="!hasChanges"
                        @click="save"
                    >
                        {{ tr('save') }}
                    </button>
                    <button
                        v-if="mode === 'reprocess'"
                        type="button"
                        class="btn btn-primary"
                        data-test="mapping-reprocess"
                        :disabled="!canReprocess"
                        @click="confirmation = { kind: 'reprocess' }"
                    >
                        {{ tr('save_and_reprocess') }}
                    </button>
                </div>
            </fieldset>
        </div>
        <CenteredModal
            density="default"
            :open="!!confirmation"
            size="md"
            :close-label="tr('cancel')"
            @close="!working && (confirmation = null)"
        >
            <template #header
                ><h2 class="h5 mb-0">
                    {{ tr(confirmation?.kind === 'reprocess' ? 'reprocess' : 'confirm') }}
                </h2></template
            >
            <p>
                {{
                    tr(
                        confirmation?.kind === 'reprocess'
                            ? 'reprocess_confirm'
                            : confirmation?.kind === 'clear'
                              ? 'clear_confirm'
                              : 'particular_confirm',
                    )
                }}
            </p>
            <template #footer
                ><button type="button" class="btn btn-light" :disabled="working" @click="confirmation = null">
                    {{ tr('cancel') }}</button
                ><button
                    type="button"
                    class="btn btn-primary"
                    data-test="mapping-confirm"
                    :disabled="working"
                    @click="confirmAction"
                >
                    {{ tr('confirm') }}
                </button></template
            >
        </CenteredModal>
    </section>
</template>
<style scoped>
.covenant-mapping :deep(:is(input, select, button):focus-visible) {
    outline: 2px solid var(--bs-primary);
    outline-offset: 3px;
}
@media (max-width: 767.98px) {
    .mapping-table,
    .mapping-table tbody,
    .mapping-table tr,
    .mapping-table td,
    .mapping-table th[scope='row'] {
        display: block;
        width: 100%;
    }
    .mapping-table thead {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip-path: inset(50%);
    }
    .mapping-table tbody {
        border: 1px solid var(--bs-border-color);
        border-radius: 0.375rem;
        margin-bottom: 1rem;
        padding: 0.5rem;
    }
    .mapping-table td,
    .mapping-table th {
        border: 0;
        overflow-wrap: anywhere;
    }
}
</style>
