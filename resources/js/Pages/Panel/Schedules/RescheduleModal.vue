<script setup>
import { ref, watch, nextTick, computed, onBeforeUnmount } from 'vue';
import SlotPicker from '@/Components/Panel/SlotPicker.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import { latestRequest, isAbortError } from '@/Support/latestRequest.js';
import { useModalA11y } from '@/Support/useModalA11y.js';

const props = defineProps({
    item: { type: Object, default: null },
    doctors: { type: Array, default: () => [] },
    t: { type: Object, required: true },
});
const emit = defineEmits(['close', 'rescheduled']);
const HIDDEN_BS_MODAL_EVENT = 'hidden.bs.modal';
const SHOWN_BS_MODAL_EVENT = 'shown.bs.modal';
const dateTime = ref('');
const doctorId = ref('');
const isFitIn = ref(false);
const saving = ref(false);
const errorMsg = ref('');
const modalOpen = ref(false);
const a11yOpen = ref(false);
const modalRef = ref(null);
const slotPickerRef = ref(null);
const scope = ref('one');
const seriesPreview = ref([]);
const seriesLoading = ref(false);
const seriesError = ref('');
const previewRequest = latestRequest();
const saveRequest = latestRequest();
let seriesDebounce = null;
let bsModal = null;

const seriesCount = computed(() => Number(props.item?.series_future_count ?? 0));
const canRescheduleSeries = computed(() => Boolean(props.item?.series_id) && seriesCount.value > 1);
const following = computed(() => scope.value === 'following' && canRescheduleSeries.value);
const doctorChanged = computed(() => doctorId.value !== (props.item?.doctor_id ?? ''));
const fitInChanged = computed(() => isFitIn.value !== Boolean(props.item?.is_fit_in));
const followingLabel = computed(() =>
    (props.t.series_reschedule_following ?? '').replace(':count', String(seriesCount.value)),
);
const seriesConflicts = computed(() => seriesPreview.value.filter((session) => (session.errors ?? []).length));
const conflictsLabel = computed(() =>
    (props.t.series_reschedule_conflicts ?? '').replace(':count', String(seriesConflicts.value.length)),
);
const seriesBlocked = computed(
    () =>
        following.value &&
        (seriesLoading.value ||
            Boolean(seriesError.value) ||
            seriesConflicts.value.length > 0 ||
            !seriesPreview.value.length),
);

useModalA11y(
    modalRef,
    () => a11yOpen.value,
    () => bsModal?.hide(),
);

function clearPreview() {
    clearTimeout(seriesDebounce);
    previewRequest.cancel();
    seriesPreview.value = [];
    seriesLoading.value = false;
    seriesError.value = '';
}

watch(
    () => props.item,
    async (val) => {
        clearPreview();
        saveRequest.cancel();
        scope.value = 'one';
        if (val) {
            dateTime.value = '';
            doctorId.value = val.doctor_id ?? '';
            isFitIn.value = Boolean(val.is_fit_in);
            errorMsg.value = '';
            saving.value = false;
            modalOpen.value = true;
            await nextTick();
            if (props.item !== val || !modalOpen.value) return;
            slotPickerRef.value?.reset();
            if (!bsModal) {
                // O composable compartilhado cuida do foco e Escape, inclusive sobre o drawer.
                bsModal = new window.bootstrap.Modal(modalRef.value, { focus: false, keyboard: false });
            }
            bsModal.show();
        } else {
            modalOpen.value = false;
            a11yOpen.value = false;
            bsModal?.hide();
        }
    },
);

function seriesPayload() {
    return {
        date_time: dateTime.value,
        ...(doctorChanged.value ? { doctor_id: doctorId.value || null } : {}),
        ...(fitInChanged.value ? { is_fit_in: isFitIn.value } : {}),
    };
}

function schedulePreview() {
    clearPreview();
    if (!modalOpen.value || !following.value || !dateTime.value) return;
    if (!props.item?.reschedule_series_preview_url || !props.item?.reschedule_series_url) {
        seriesError.value = props.t.series_reschedule_error;
        return;
    }
    // Invalida a prévia anterior antes do debounce: ela não autoriza o novo horário.
    const req = previewRequest.begin();
    const params = new URLSearchParams({ date_time: dateTime.value });
    if (doctorChanged.value && doctorId.value) params.set('doctor_id', doctorId.value);
    if (fitInChanged.value) params.set('is_fit_in', isFitIn.value ? '1' : '0');
    const url = `${props.item.reschedule_series_preview_url}?${params}`;
    seriesLoading.value = true;
    seriesDebounce = setTimeout(async () => {
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' }, signal: req.signal });
            const json = await res.json();
            if (!req.current()) return;
            if (!res.ok) {
                seriesError.value = json.message ?? props.t.series_reschedule_error;
                return;
            }
            seriesPreview.value = Array.isArray(json.sessions) ? json.sessions : [];
            if (!seriesPreview.value.length) seriesError.value = props.t.series_reschedule_error;
        } catch (error) {
            if (req.current() && !isAbortError(error)) seriesError.value = props.t.series_reschedule_error;
        } finally {
            if (req.current()) seriesLoading.value = false;
        }
    }, 350);
}

watch([dateTime, doctorId, isFitIn, scope], schedulePreview, { flush: 'sync' });

async function onConfirm() {
    if (saving.value || !modalOpen.value || !props.item || !dateTime.value || seriesBlocked.value) return;
    const isSeries = following.value;
    const url = isSeries ? props.item.reschedule_series_url : props.item.reschedule_url;
    if (!url) return;
    const req = saveRequest.begin();
    saving.value = true;
    errorMsg.value = '';
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            signal: req.signal,
            body: JSON.stringify(
                isSeries
                    ? seriesPayload()
                    : {
                          date_time: dateTime.value,
                          doctor_id: doctorId.value || null,
                          is_fit_in: isFitIn.value,
                      },
            ),
        });
        const json = await res.json();
        if (!req.current()) return;
        if (res.ok) {
            bsModal?.hide();
            emit('rescheduled', json);
        } else {
            errorMsg.value = json.message ?? props.t.series_reschedule_error;
            if (isSeries && res.status === 409) schedulePreview();
        }
    } catch (error) {
        if (req.current() && !isAbortError(error)) errorMsg.value = props.t.series_reschedule_error;
    } finally {
        if (req.current()) saving.value = false;
    }
}

function onHidden() {
    modalOpen.value = false;
    a11yOpen.value = false;
    clearPreview();
    saveRequest.cancel();
    emit('close');
}

onBeforeUnmount(() => {
    clearPreview();
    saveRequest.cancel();
});
</script>

<template>
    <Teleport to="body">
        <div
            id="rescheduleModal"
            ref="modalRef"
            class="modal fade"
            tabindex="-1"
            role="dialog"
            aria-modal="true"
            aria-labelledby="reschedule-title"
            @[HIDDEN_BS_MODAL_EVENT]="onHidden"
            @[SHOWN_BS_MODAL_EVENT]="a11yOpen = true"
        >
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 id="reschedule-title" class="modal-title">
                            <i class="fas fa-calendar-alt me-2 text-primary" aria-hidden="true"></i
                            >{{ t.reschedule_title }}
                        </h5>
                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            :aria-label="t.reschedule_cancel"
                        ></button>
                    </div>
                    <div class="modal-body">
                        <div v-if="errorMsg" class="alert alert-danger py-2 small" role="alert">{{ errorMsg }}</div>
                        <div v-if="item" class="mb-3 text-muted small">
                            {{ item.time }} — <strong>{{ item.name }}</strong>
                        </div>
                        <fieldset
                            v-if="canRescheduleSeries"
                            class="mb-3"
                            :disabled="saving"
                            data-test="series-reschedule-scope"
                            aria-describedby="reschedule-scope-hint"
                        >
                            <legend class="form-label fw-semibold fs-6">{{ t.series_reschedule_scope }}</legend>
                            <div class="form-check">
                                <input
                                    id="reschedule-scope-one"
                                    v-model="scope"
                                    type="radio"
                                    value="one"
                                    class="form-check-input"
                                    name="reschedule-scope"
                                />
                                <label for="reschedule-scope-one" class="form-check-label small">{{
                                    t.series_reschedule_one
                                }}</label>
                            </div>
                            <div class="form-check">
                                <input
                                    id="reschedule-scope-following"
                                    v-model="scope"
                                    type="radio"
                                    value="following"
                                    class="form-check-input"
                                    name="reschedule-scope"
                                />
                                <label for="reschedule-scope-following" class="form-check-label small">{{
                                    followingLabel
                                }}</label>
                            </div>
                            <p id="reschedule-scope-hint" class="form-text small mb-0">
                                {{ t.series_reschedule_hint }}
                            </p>
                        </fieldset>
                        <div v-if="doctors.length > 1" class="mb-3">
                            <label id="reschedule-doctor-label" class="form-label fw-semibold">{{
                                t.reschedule_doctor
                            }}</label>
                            <SearchSelect
                                v-model="doctorId"
                                :options="doctors"
                                :placeholder="t.form_select"
                                :disabled="saving"
                                :clearable="false"
                                aria-labelledby="reschedule-doctor-label"
                            />
                            <p v-if="following && doctorChanged" class="form-text small" data-test="series-doctor-hint">
                                {{ t.series_reschedule_doctor_hint }}
                            </p>
                        </div>
                        <div class="form-check mb-2">
                            <input
                                id="reschedule-fit-in"
                                v-model="isFitIn"
                                type="checkbox"
                                class="form-check-input"
                                :disabled="saving"
                            />
                            <label for="reschedule-fit-in" class="form-check-label small fw-semibold">{{
                                t.form_fit_in
                            }}</label>
                            <div class="form-text small mt-0">{{ t.form_fit_in_hint }}</div>
                            <p v-if="following && fitInChanged" class="form-text small" data-test="series-fit-in-hint">
                                {{ t.series_reschedule_fit_in_hint }}
                            </p>
                        </div>
                        <SlotPicker
                            v-if="modalOpen"
                            ref="slotPickerRef"
                            v-model="dateTime"
                            :doctor-id="doctorId"
                            :schedule-id="item?.id ?? null"
                            :allow-occupied="isFitIn"
                            :t="t"
                        />
                        <div
                            v-if="following"
                            class="mt-3"
                            data-test="series-reschedule-preview"
                            aria-live="polite"
                            aria-labelledby="reschedule-preview-title"
                            :aria-busy="seriesLoading"
                        >
                            <div id="reschedule-preview-title" class="small fw-semibold mb-1">
                                {{ t.series_reschedule_preview }}
                            </div>
                            <div v-if="seriesLoading" class="text-muted small" role="status">
                                <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span
                                >{{ t.series_reschedule_loading }}
                            </div>
                            <div v-else-if="seriesError" class="alert alert-warning py-2 small mb-2" role="alert">
                                {{ seriesError }}
                            </div>
                            <div
                                v-else-if="seriesConflicts.length"
                                class="alert alert-danger py-2 small mb-2"
                                role="alert"
                            >
                                {{ conflictsLabel }}
                            </div>
                            <ul
                                v-if="seriesPreview.length"
                                class="list-unstyled small mb-0"
                                style="max-height: 35vh; overflow-y: auto"
                            >
                                <li
                                    v-for="session in seriesPreview"
                                    :key="session.id"
                                    class="d-flex flex-wrap gap-2 py-1 border-bottom"
                                >
                                    <span class="text-nowrap"
                                        >#{{ session.session_number
                                        }}<template v-if="session.session_total"
                                            >/{{ session.session_total }}</template
                                        ></span
                                    >
                                    <span class="flex-grow-1"
                                        >{{ session.old_label }} → <strong>{{ session.new_label }}</strong></span
                                    >
                                    <span v-if="session.errors?.length" class="text-danger text-break">{{
                                        session.errors.join('; ')
                                    }}</span>
                                    <span v-else class="text-success text-nowrap">{{ t.series_ok }}</span>
                                </li>
                            </ul>
                        </div>
                        <p
                            v-if="seriesBlocked"
                            id="reschedule-blocked"
                            class="small mt-2 mb-0"
                            data-test="series-reschedule-blocked"
                        >
                            {{ t.series_reschedule_blocked }}
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            {{ t.reschedule_cancel }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-primary"
                            data-test="reschedule-confirm"
                            :disabled="saving || !dateTime || seriesBlocked"
                            :aria-busy="saving"
                            :aria-describedby="seriesBlocked ? 'reschedule-blocked' : undefined"
                            :title="seriesBlocked ? t.series_reschedule_blocked : undefined"
                            @click="onConfirm"
                        >
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                            {{ saving ? t.saving : t.reschedule_save }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
