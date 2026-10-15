<script setup>
import { reactive, ref, watch, useId } from 'vue';
import { makeTx, parseCidList, cidListToText, blankTask, blankReference, cloneContent } from '@/Support/recallRule.js';

/**
 * RecallProtocolEditor — formulário estruturado do conteúdo de um protocolo de retorno
 * (rascunho). Não monta JSON na mão: cada campo do esquema v1 tem o seu controle, e o
 * servidor (ProtocolContentSchema) continua sendo quem valida — os erros dele chegam em
 * `errors`, por caminho (`content.tasks.0.interval.min`), e aparecem no campo certo.
 *
 * `v-model` é o conteúdo (objeto); o componente trabalha numa cópia e devolve cópias.
 */
const props = defineProps({
    modelValue: { type: Object, required: true },
    /** Erros do servidor, chaveados por `content.<caminho>`. */
    errors: { type: Object, default: () => ({}) },
    /** Opções do servidor: task_kinds, exam_keys, units, evidence, medications, limits. */
    options: { type: Object, required: true },
    /** Textos de lang `manager_recall`. */
    t: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
    /** A versão deriva de outra? Então a nota de mudança é obrigatória. */
    requiresChangeNote: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const uid = useId();
const tx = makeTx(() => props.t);

const local = ref(cloneContent(props.modelValue));

const cidText = reactive({ strong: '', weak: '', exclude: '' });

function syncCidText() {
    for (const list of ['strong', 'weak', 'exclude']) {
        cidText[list] = cidListToText(local.value.entry?.cid?.[list]);
    }
}

syncCidText();

watch(
    local,
    (value) => {
        emit('update:modelValue', cloneContent(value));
    },
    { deep: true },
);

watch(
    () => props.modelValue,
    (value) => {
        if (JSON.stringify(value) === JSON.stringify(local.value)) return;
        local.value = cloneContent(value);
        syncCidText();
    },
    { deep: true },
);

const error = (path) => props.errors?.[`content.${path}`] ?? '';
const fieldId = (path) => `${uid}-${String(path).replaceAll('.', '-')}`;
const describedBy = (path) => (error(path) ? `${fieldId(path)}-error` : undefined);

/** Mensagens da lista de CID: a da lista inteira e as de cada código (`entry.cid.strong.2`). */
function cidErrors(list) {
    const base = `content.entry.cid.${list}`;

    return Object.entries(props.errors ?? {})
        .filter(([path]) => path === base || path.startsWith(`${base}.`))
        .map(([, message]) => message);
}

// ── critérios de entrada ────────────────────────────────────────────────────

function onCidInput(list, event) {
    cidText[list] = event.target.value;
    local.value.entry.cid[list] = parseCidList(event.target.value);
}

function onCidBlur(list) {
    cidText[list] = cidListToText(local.value.entry.cid[list]);
}

function toggleMedication(key, checked) {
    const current = new Set(local.value.entry.medications);
    checked ? current.add(key) : current.delete(key);
    local.value.entry.medications = [...current].sort();
}

// ── tarefas ─────────────────────────────────────────────────────────────────

function addTask() {
    if (local.value.tasks.length >= props.options.limits.tasks) return;
    local.value.tasks.push(blankTask());
}

function removeTask(index) {
    local.value.tasks.splice(index, 1);
}

function moveTask(index, delta) {
    const target = index + delta;
    if (target < 0 || target >= local.value.tasks.length) return;
    const [task] = local.value.tasks.splice(index, 1);
    local.value.tasks.splice(target, 0, task);
}

function onKindChange(task) {
    task.exam_key = task.kind === 'exam' ? (task.exam_key ?? props.options.exam_keys[0]?.value ?? null) : null;
}

/** Campo numérico opcional: vazio vira null (o servidor entende "sem valor"). */
function optionalNumber(task, field, event) {
    const raw = event.target.value;
    task[field] = raw === '' ? null : Number(raw);
}

// ── referências ─────────────────────────────────────────────────────────────

function addReference() {
    if (local.value.references.length >= props.options.limits.references) return;
    local.value.references.push(blankReference());
}

function removeReference(index) {
    local.value.references.splice(index, 1);
}

function onYearInput(reference, event) {
    const raw = event.target.value;
    reference.year = raw === '' ? null : Number(raw);
}
</script>

<template>
    <fieldset :disabled="disabled" class="recall-editor border-0 p-0 m-0" data-testid="recall-editor">
        <!-- Entrada -->
        <section class="mb-4" :aria-labelledby="`${uid}-entry`">
            <h3 :id="`${uid}-entry`" class="h6 fw-semibold">{{ tx('editor.entry') }}</h3>
            <p class="small text-body-secondary">{{ tx('editor.cid_hint') }}</p>
            <div class="row g-3">
                <div v-for="list in ['strong', 'weak', 'exclude']" :key="list" class="col-lg-4">
                    <label :for="fieldId(`entry.cid.${list}`)" class="form-label">{{ tx(`editor.cid_${list}`) }}</label>
                    <textarea
                        :id="fieldId(`entry.cid.${list}`)"
                        class="form-control font-monospace"
                        rows="3"
                        :value="cidText[list]"
                        :class="{ 'is-invalid': cidErrors(list).length }"
                        :aria-invalid="cidErrors(list).length ? 'true' : undefined"
                        :aria-describedby="cidErrors(list).length ? `${fieldId(`entry.cid.${list}`)}-error` : undefined"
                        :data-testid="`cid-${list}`"
                        @input="onCidInput(list, $event)"
                        @blur="onCidBlur(list)"
                    ></textarea>
                    <div
                        v-if="cidErrors(list).length"
                        :id="`${fieldId(`entry.cid.${list}`)}-error`"
                        class="invalid-feedback d-block"
                        role="alert"
                    >
                        <div v-for="message in cidErrors(list)" :key="message">{{ message }}</div>
                    </div>
                </div>
            </div>

            <fieldset class="mt-3">
                <legend class="form-label fs-6">{{ tx('editor.medications') }}</legend>
                <div
                    v-for="medication in options.medications"
                    :key="medication.value"
                    class="form-check form-check-inline"
                >
                    <input
                        :id="fieldId(`entry.medications.${medication.value}`)"
                        type="checkbox"
                        class="form-check-input"
                        :checked="local.entry.medications.includes(medication.value)"
                        :data-testid="`medication-${medication.value}`"
                        @change="toggleMedication(medication.value, $event.target.checked)"
                    />
                    <label :for="fieldId(`entry.medications.${medication.value}`)" class="form-check-label">{{
                        medication.label
                    }}</label>
                </div>
            </fieldset>
            <div v-if="error('entry')" class="invalid-feedback d-block" role="alert" data-testid="entry-error">
                {{ error('entry') }}
            </div>
        </section>

        <!-- Tarefas -->
        <section class="mb-4" :aria-labelledby="`${uid}-tasks`">
            <div class="d-flex align-items-center gap-2 mb-1">
                <h3 :id="`${uid}-tasks`" class="h6 fw-semibold mb-0 me-auto">{{ tx('editor.tasks') }}</h3>
                <button
                    type="button"
                    class="btn btn-outline-primary btn-sm"
                    :disabled="local.tasks.length >= options.limits.tasks"
                    data-testid="add-task"
                    @click="addTask"
                >
                    <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ tx('editor.add_task') }}
                </button>
            </div>
            <p class="small text-body-secondary">{{ tx('editor.tasks_hint') }}</p>
            <div v-if="error('tasks')" class="invalid-feedback d-block mb-2" role="alert">{{ error('tasks') }}</div>

            <article
                v-for="(task, index) in local.tasks"
                :key="index"
                class="card mb-3"
                :data-testid="`task-${index}`"
                :aria-label="tx('editor.task_title', { number: index + 1 })"
            >
                <div class="card-header d-flex align-items-center gap-1 py-2">
                    <strong class="me-auto">{{ tx('editor.task_title', { number: index + 1 }) }}</strong>
                    <button
                        type="button"
                        class="btn btn-light btn-sm"
                        :disabled="index === 0"
                        :title="tx('editor.move_up')"
                        :aria-label="tx('editor.move_up')"
                        @click="moveTask(index, -1)"
                    >
                        <i class="ti ti-arrow-up" aria-hidden="true"></i>
                    </button>
                    <button
                        type="button"
                        class="btn btn-light btn-sm"
                        :disabled="index === local.tasks.length - 1"
                        :title="tx('editor.move_down')"
                        :aria-label="tx('editor.move_down')"
                        @click="moveTask(index, 1)"
                    >
                        <i class="ti ti-arrow-down" aria-hidden="true"></i>
                    </button>
                    <button
                        type="button"
                        class="btn btn-outline-danger btn-sm"
                        :title="tx('editor.remove_task')"
                        :aria-label="tx('editor.remove_task')"
                        :data-testid="`remove-task-${index}`"
                        @click="removeTask(index)"
                    >
                        <i class="ti ti-trash" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label :for="fieldId(`tasks.${index}.key`)" class="form-label">{{
                                tx('editor.task_key')
                            }}</label>
                            <input
                                :id="fieldId(`tasks.${index}.key`)"
                                v-model="task.key"
                                type="text"
                                maxlength="40"
                                class="form-control font-monospace"
                                :class="{ 'is-invalid': error(`tasks.${index}.key`) }"
                                :aria-invalid="error(`tasks.${index}.key`) ? 'true' : undefined"
                                :aria-describedby="describedBy(`tasks.${index}.key`)"
                                :data-testid="`task-${index}-key`"
                            />
                            <div
                                v-if="error(`tasks.${index}.key`)"
                                :id="`${fieldId(`tasks.${index}.key`)}-error`"
                                class="invalid-feedback d-block"
                                role="alert"
                            >
                                {{ error(`tasks.${index}.key`) }}
                            </div>
                            <div v-else class="form-text">{{ tx('editor.task_key_hint') }}</div>
                        </div>
                        <div class="col-md-4">
                            <label :for="fieldId(`tasks.${index}.kind`)" class="form-label">{{
                                tx('editor.kind')
                            }}</label>
                            <select
                                :id="fieldId(`tasks.${index}.kind`)"
                                v-model="task.kind"
                                class="form-select"
                                :class="{ 'is-invalid': error(`tasks.${index}.kind`) }"
                                @change="onKindChange(task)"
                            >
                                <option v-for="kind in options.task_kinds" :key="kind.value" :value="kind.value">
                                    {{ kind.label }}
                                </option>
                            </select>
                            <div v-if="error(`tasks.${index}.kind`)" class="invalid-feedback d-block" role="alert">
                                {{ error(`tasks.${index}.kind`) }}
                            </div>
                        </div>
                        <div v-if="task.kind === 'exam'" class="col-md-4">
                            <label :for="fieldId(`tasks.${index}.exam_key`)" class="form-label">{{
                                tx('editor.exam')
                            }}</label>
                            <select
                                :id="fieldId(`tasks.${index}.exam_key`)"
                                v-model="task.exam_key"
                                class="form-select"
                                :class="{ 'is-invalid': error(`tasks.${index}.exam_key`) }"
                                :aria-invalid="error(`tasks.${index}.exam_key`) ? 'true' : undefined"
                                :aria-describedby="describedBy(`tasks.${index}.exam_key`)"
                            >
                                <option :value="null">{{ tx('editor.select') }}</option>
                                <option v-for="exam in options.exam_keys" :key="exam.value" :value="exam.value">
                                    {{ exam.label }}
                                </option>
                            </select>
                            <div
                                v-if="error(`tasks.${index}.exam_key`)"
                                :id="`${fieldId(`tasks.${index}.exam_key`)}-error`"
                                class="invalid-feedback d-block"
                                role="alert"
                            >
                                {{ error(`tasks.${index}.exam_key`) }}
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mt-0">
                        <div class="col-6 col-md-3">
                            <label :for="fieldId(`tasks.${index}.interval.unit`)" class="form-label">{{
                                tx('editor.unit')
                            }}</label>
                            <select
                                :id="fieldId(`tasks.${index}.interval.unit`)"
                                v-model="task.interval.unit"
                                class="form-select"
                                :class="{ 'is-invalid': error(`tasks.${index}.interval.unit`) }"
                            >
                                <option v-for="unit in options.units" :key="unit.value" :value="unit.value">
                                    {{ unit.label }}
                                </option>
                            </select>
                        </div>
                        <div v-for="field in ['min', 'default', 'max']" :key="field" class="col-6 col-md-3">
                            <label :for="fieldId(`tasks.${index}.interval.${field}`)" class="form-label">{{
                                tx(`editor.interval_${field}`)
                            }}</label>
                            <input
                                :id="fieldId(`tasks.${index}.interval.${field}`)"
                                v-model.number="task.interval[field]"
                                type="number"
                                min="1"
                                class="form-control"
                                :class="{
                                    'is-invalid':
                                        error(`tasks.${index}.interval.${field}`) || error(`tasks.${index}.interval`),
                                }"
                                :aria-invalid="
                                    error(`tasks.${index}.interval.${field}`) || error(`tasks.${index}.interval`)
                                        ? 'true'
                                        : undefined
                                "
                                :aria-describedby="
                                    describedBy(`tasks.${index}.interval.${field}`) ??
                                    describedBy(`tasks.${index}.interval`)
                                "
                                :data-testid="`task-${index}-interval-${field}`"
                            />
                            <div
                                v-if="error(`tasks.${index}.interval.${field}`)"
                                :id="`${fieldId(`tasks.${index}.interval.${field}`)}-error`"
                                class="invalid-feedback d-block"
                                role="alert"
                            >
                                {{ error(`tasks.${index}.interval.${field}`) }}
                            </div>
                        </div>
                    </div>
                    <div
                        v-if="error(`tasks.${index}.interval`)"
                        :id="`${fieldId(`tasks.${index}.interval`)}-error`"
                        class="invalid-feedback d-block mt-1"
                        role="alert"
                    >
                        {{ error(`tasks.${index}.interval`) }}
                    </div>

                    <div class="row g-3 mt-0">
                        <div
                            v-for="field in ['lead_days', 'grace_days', 'lost_after_days']"
                            :key="field"
                            class="col-6 col-md-3"
                        >
                            <label :for="fieldId(`tasks.${index}.${field}`)" class="form-label">{{
                                tx(`editor.${field}`)
                            }}</label>
                            <input
                                :id="fieldId(`tasks.${index}.${field}`)"
                                v-model.number="task[field]"
                                type="number"
                                min="0"
                                class="form-control"
                                :class="{ 'is-invalid': error(`tasks.${index}.${field}`) }"
                                :aria-invalid="error(`tasks.${index}.${field}`) ? 'true' : undefined"
                                :aria-describedby="describedBy(`tasks.${index}.${field}`)"
                                :data-testid="`task-${index}-${field}`"
                            />
                            <div
                                v-if="error(`tasks.${index}.${field}`)"
                                :id="`${fieldId(`tasks.${index}.${field}`)}-error`"
                                class="invalid-feedback d-block"
                                role="alert"
                            >
                                {{ error(`tasks.${index}.${field}`) }}
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <label :for="fieldId(`tasks.${index}.starts_after_months`)" class="form-label">{{
                                tx('editor.starts_after')
                            }}</label>
                            <input
                                :id="fieldId(`tasks.${index}.starts_after_months`)"
                                :value="task.starts_after_months ?? ''"
                                type="number"
                                min="1"
                                class="form-control"
                                :class="{ 'is-invalid': error(`tasks.${index}.starts_after_months`) }"
                                :aria-invalid="error(`tasks.${index}.starts_after_months`) ? 'true' : undefined"
                                :aria-describedby="describedBy(`tasks.${index}.starts_after_months`)"
                                :data-testid="`task-${index}-starts_after_months`"
                                @input="optionalNumber(task, 'starts_after_months', $event)"
                            />
                            <div
                                v-if="error(`tasks.${index}.starts_after_months`)"
                                :id="`${fieldId(`tasks.${index}.starts_after_months`)}-error`"
                                class="invalid-feedback d-block"
                                role="alert"
                            >
                                {{ error(`tasks.${index}.starts_after_months`) }}
                            </div>
                            <div v-else class="form-text">{{ tx('editor.starts_after_hint') }}</div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-4 mt-3">
                        <div class="form-check">
                            <input
                                :id="fieldId(`tasks.${index}.recurs`)"
                                v-model="task.recurs"
                                type="checkbox"
                                class="form-check-input"
                            />
                            <label :for="fieldId(`tasks.${index}.recurs`)" class="form-check-label">{{
                                tx('editor.recurs')
                            }}</label>
                        </div>
                        <div class="form-check">
                            <input
                                :id="fieldId(`tasks.${index}.required`)"
                                v-model="task.required"
                                type="checkbox"
                                class="form-check-input"
                            />
                            <label :for="fieldId(`tasks.${index}.required`)" class="form-check-label">{{
                                tx('editor.required')
                            }}</label>
                        </div>
                    </div>
                </div>
            </article>
        </section>

        <!-- Retorno pedido pelo médico -->
        <section class="mb-4" :aria-labelledby="`${uid}-order`">
            <h3 :id="`${uid}-order`" class="h6 fw-semibold">{{ tx('editor.doctor_order') }}</h3>
            <div class="form-check">
                <input
                    :id="fieldId('doctor_order.cap_to_max')"
                    v-model="local.doctor_order.cap_to_max"
                    type="checkbox"
                    class="form-check-input"
                    data-testid="cap-to-max"
                />
                <label :for="fieldId('doctor_order.cap_to_max')" class="form-check-label">{{
                    tx('editor.cap_to_max')
                }}</label>
            </div>
        </section>

        <!-- Referências -->
        <section class="mb-4" :aria-labelledby="`${uid}-refs`">
            <div class="d-flex align-items-center gap-2 mb-2">
                <h3 :id="`${uid}-refs`" class="h6 fw-semibold mb-0 me-auto">{{ tx('editor.references') }}</h3>
                <button
                    type="button"
                    class="btn btn-outline-primary btn-sm"
                    :disabled="local.references.length >= options.limits.references"
                    data-testid="add-reference"
                    @click="addReference"
                >
                    <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ tx('editor.add_reference') }}
                </button>
            </div>
            <div v-if="error('references')" class="invalid-feedback d-block mb-2" role="alert">
                {{ error('references') }}
            </div>

            <article
                v-for="(reference, index) in local.references"
                :key="index"
                class="border rounded p-3 mb-2"
                :data-testid="`reference-${index}`"
            >
                <div class="row g-3">
                    <div class="col-12">
                        <label :for="fieldId(`references.${index}.title`)" class="form-label">{{
                            tx('editor.ref_title')
                        }}</label>
                        <textarea
                            :id="fieldId(`references.${index}.title`)"
                            v-model="reference.title"
                            rows="2"
                            maxlength="240"
                            class="form-control"
                            :class="{ 'is-invalid': error(`references.${index}.title`) }"
                            :aria-invalid="error(`references.${index}.title`) ? 'true' : undefined"
                            :aria-describedby="describedBy(`references.${index}.title`)"
                        ></textarea>
                        <div
                            v-if="error(`references.${index}.title`)"
                            :id="`${fieldId(`references.${index}.title`)}-error`"
                            class="invalid-feedback d-block"
                            role="alert"
                        >
                            {{ error(`references.${index}.title`) }}
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <label :for="fieldId(`references.${index}.year`)" class="form-label">{{
                            tx('editor.ref_year')
                        }}</label>
                        <input
                            :id="fieldId(`references.${index}.year`)"
                            :value="reference.year ?? ''"
                            type="number"
                            class="form-control"
                            :class="{ 'is-invalid': error(`references.${index}.year`) }"
                            @input="onYearInput(reference, $event)"
                        />
                        <div v-if="error(`references.${index}.year`)" class="invalid-feedback d-block" role="alert">
                            {{ error(`references.${index}.year`) }}
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <label :for="fieldId(`references.${index}.evidence`)" class="form-label">{{
                            tx('editor.ref_evidence')
                        }}</label>
                        <select
                            :id="fieldId(`references.${index}.evidence`)"
                            v-model="reference.evidence"
                            class="form-select"
                            :class="{ 'is-invalid': error(`references.${index}.evidence`) }"
                        >
                            <option v-for="level in options.evidence" :key="level.value" :value="level.value">
                                {{ level.label }}
                            </option>
                        </select>
                        <div v-if="error(`references.${index}.evidence`)" class="invalid-feedback d-block" role="alert">
                            {{ error(`references.${index}.evidence`) }}
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label :for="fieldId(`references.${index}.note`)" class="form-label">{{
                            tx('editor.ref_note')
                        }}</label>
                        <input
                            :id="fieldId(`references.${index}.note`)"
                            v-model="reference.note"
                            type="text"
                            maxlength="400"
                            class="form-control"
                            :class="{ 'is-invalid': error(`references.${index}.note`) }"
                        />
                        <div v-if="error(`references.${index}.note`)" class="invalid-feedback d-block" role="alert">
                            {{ error(`references.${index}.note`) }}
                        </div>
                    </div>
                </div>
                <button
                    type="button"
                    class="btn btn-outline-danger btn-sm mt-2"
                    :data-testid="`remove-reference-${index}`"
                    @click="removeReference(index)"
                >
                    <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ tx('editor.remove_reference') }}
                </button>
            </article>
        </section>

        <!-- Justificativa e nota de mudança -->
        <section class="mb-4">
            <label :for="fieldId('rationale')" class="form-label fw-semibold"
                >{{ tx('editor.rationale') }} <span class="text-danger" aria-hidden="true">*</span></label
            >
            <textarea
                :id="fieldId('rationale')"
                v-model="local.rationale"
                rows="5"
                :maxlength="options.limits.rationale"
                class="form-control"
                :class="{ 'is-invalid': error('rationale') }"
                :aria-invalid="error('rationale') ? 'true' : undefined"
                :aria-describedby="
                    error('rationale') ? `${fieldId('rationale')}-error` : `${fieldId('rationale')}-hint`
                "
                aria-required="true"
                data-testid="rationale"
            ></textarea>
            <div
                v-if="error('rationale')"
                :id="`${fieldId('rationale')}-error`"
                class="invalid-feedback d-block"
                role="alert"
            >
                {{ error('rationale') }}
            </div>
            <div v-else :id="`${fieldId('rationale')}-hint`" class="form-text">
                {{ tx('editor.rationale_hint') }}
                {{ tx('editor.chars_left', { count: options.limits.rationale - (local.rationale?.length ?? 0) }) }}
            </div>
        </section>

        <section class="mb-2">
            <label :for="fieldId('change_note')" class="form-label fw-semibold">
                {{ tx('editor.change_note') }}
                <span v-if="requiresChangeNote" class="text-danger" aria-hidden="true">*</span>
            </label>
            <textarea
                :id="fieldId('change_note')"
                v-model="local.change_note"
                rows="3"
                :maxlength="options.limits.change_note"
                class="form-control"
                :class="{ 'is-invalid': error('change_note') }"
                :aria-invalid="error('change_note') ? 'true' : undefined"
                :aria-describedby="
                    error('change_note') ? `${fieldId('change_note')}-error` : `${fieldId('change_note')}-hint`
                "
                :aria-required="requiresChangeNote ? 'true' : undefined"
                data-testid="change-note"
            ></textarea>
            <div
                v-if="error('change_note')"
                :id="`${fieldId('change_note')}-error`"
                class="invalid-feedback d-block"
                role="alert"
            >
                {{ error('change_note') }}
            </div>
            <div v-else :id="`${fieldId('change_note')}-hint`" class="form-text">
                {{ tx('editor.change_note_hint') }}
            </div>
        </section>
    </fieldset>
</template>
