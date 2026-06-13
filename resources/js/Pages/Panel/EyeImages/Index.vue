<script setup>
import { ref, computed, reactive, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import AiAssistantPanel from '@/Components/Panel/AiAssistantPanel.vue';

/**
 * Eye Images — porta fiel da implementação Alpine.js original.
 * URLs S3 presigned (defesa contra IDOR). Visualizador split-panel
 * 1-4, lente, modo "All", impressão e flip vertical (Laser).
 */
const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    entity:      { type: Object, required: true },
    doctors:     { type: Array,  default: () => [] },
    patients:    { type: Array,  default: () => [] },
    urls:        { type: Object, required: true },
    ai:          { type: Object, default: () => ({}) },
    t:           { type: Object, default: () => ({}) },
});

// ── Estado principal ──────────────────────────────────────────────────────
const patients        = ref(props.patients || []);
const selectedPatient = ref(null);
const examUrls        = ref({});           // {examId: presignedUrl}
const brokenUrls      = ref({});           // {examId: true}
const urlsLoading     = ref(false);
const loading         = ref(false);

const search       = ref('');
const period       = ref('hoje');
const laterality   = ref('');              // '' | 'od' | 'oe' | 'ao'
const doctorId     = ref('');
const examTypeId   = ref('');
const examStatus   = ref('');
const showFilters  = ref(false);

const selectedExamIds = ref([]);

// ── Viewer ────────────────────────────────────────────────────────────────
const showViewerModal   = ref(false);
const viewerExams       = ref([]);
const viewerPanelCount  = ref(1);
const viewerActivePanel = ref(0);
const viewerPanelExams   = ref([null, null, null, null]);
const viewerPanelUrls    = ref([null, null, null, null]);
const viewerPanelLoading = ref([false, false, false, false]);
const viewerPanelBroken  = ref([false, false, false, false]);
const viewerPanelFlipped = ref([false, false, false, false]);

const viewerLaserMode   = ref(false);
const viewerFitMode     = ref(false);
const viewerAllMode     = ref(false);
const viewerSplitMode   = ref(false);

const viewerLensActive  = ref(false);
const viewerLensVisible = ref(false);
const viewerLensX       = ref(0);
const viewerLensY       = ref(0);
const viewerZoom        = ref(3);
const _viewerW          = ref(0);
const _viewerH          = ref(0);
const _lensImgX         = ref(0);
const _lensImgY         = ref(0);
const _lensUrl          = ref(null);

// ── Impressão ─────────────────────────────────────────────────────────────
const showPrintModal   = ref(false);
const printExams       = ref([]);
const printCols        = ref(2);
const printOrientation = ref('portrait');

// ── Computed ──────────────────────────────────────────────────────────────
const availableExamTypes = computed(() => {
    const map = new Map();
    for (const p of patients.value) {
        for (const e of p.exams) {
            if (e.exam_type && !map.has(e.exam_id)) {
                map.set(e.exam_id, { id: e.exam_id, name: e.exam_type.name });
            }
        }
    }
    return [...map.values()].sort((a, b) => (a.name ?? '').localeCompare(b.name ?? ''));
});

function examMatchesFilters(e) {
    if (laterality.value) {
        if (laterality.value === 'ao') {
            if (e.laterality === 1 || e.laterality === 2) return false;
        } else {
            const target = laterality.value === 'od' ? 1 : 2;
            if (e.laterality !== target) return false;
        }
    }
    if (examTypeId.value && String(e.exam_id) !== String(examTypeId.value)) return false;
    if (examStatus.value && deriveStatus(e) !== examStatus.value) return false;
    return true;
}

function deriveStatus(exam) {
    if (exam.active === false || exam.active === 0) return 'cancelado';
    if (!exam.archive) return 'solicitado';
    return 'realizado';
}

/**
 * Tradução do status derivado (paridade com Alpine original).
 * As strings vêm via prop `t` (lang/{locale}/eye_images.php).
 */
function statusLabel(exam) {
    const map = {
        solicitado: props.t?.status_requested ?? 'Solicitado',
        realizado:  props.t?.status_done      ?? 'Realizado',
        cancelado:  props.t?.status_cancelled ?? 'Cancelado',
    };
    return map[deriveStatus(exam)] ?? '—';
}

const filteredPatients = computed(() => {
    let list = patients.value;
    const q = search.value.trim().toLowerCase();
    if (q) {
        list = list.filter(p =>
            (p.person?.full_name ?? p.full_name ?? '').toLowerCase().includes(q) ||
            (p.code ?? '').toLowerCase().includes(q),
        );
    }
    if (laterality.value || examTypeId.value || examStatus.value) {
        list = list.filter(p => p.exams.some(e => examMatchesFilters(e)));
    }
    return list;
});

const filteredExams = computed(() => {
    if (!selectedPatient.value) return [];
    return selectedPatient.value.exams.filter(e => examMatchesFilters(e));
});

const groupedExams = computed(() => {
    const groups = [];
    const seen = {};
    for (const exam of filteredExams.value) {
        const date    = exam.created_at?.substring(0, 10) ?? 'unknown';
        const equipId = exam.entity_integrator_equipment_id ?? '';
        const typeId  = exam.exam_id ?? '';
        const key = `${date}|${equipId}|${typeId}`;
        if (!seen[key]) {
            seen[key] = { key, date, equipment: exam.equipment ?? null, examType: exam.exam_type ?? null, exams: [] };
            groups.push(seen[key]);
        }
        seen[key].exams.push(exam);
    }
    return groups.sort((a, b) => b.date.localeCompare(a.date));
});

const selectedExamsData = computed(() => {
    if (!selectedPatient.value) return [];
    return selectedPatient.value.exams.filter(e => selectedExamIds.value.includes(e.id));
});

const totalExams = computed(() => patients.value.reduce((s, p) => s + (p.exams?.length ?? 0), 0));

const viewerActivePanelIndex = computed(() => {
    const exam = viewerPanelExams.value[viewerActivePanel.value];
    if (!exam) return -1;
    return viewerExams.value.findIndex(e => e.id === exam.id);
});

const viewerPanelGridStyle = computed(() => {
    const base = 'display:grid;gap:2px;padding:2px;overflow:hidden;min-height:0;';
    return base + `grid-template-columns:repeat(${viewerPanelCount.value},1fr);`;
});

const viewerLensStyle = computed(() => {
    const url = _lensUrl.value || viewerPanelUrls.value[viewerActivePanel.value];
    if (!viewerLensVisible.value || !url) return 'display:none;';
    const lensSize = 360;
    const bW = _viewerW.value * viewerZoom.value;
    const bH = _viewerH.value * viewerZoom.value;
    const lookX = _lensImgX.value ?? viewerLensX.value;
    const lookY = _lensImgY.value ?? viewerLensY.value;
    const bX = -(lookX * viewerZoom.value - lensSize / 2);
    const bY = -(lookY * viewerZoom.value - lensSize / 2);
    return `position:fixed;left:${viewerLensX.value}px;top:${viewerLensY.value}px;` +
        `width:${lensSize}px;height:${lensSize}px;border-radius:50%;` +
        `border:2px solid rgba(255,255,255,0.8);transform:translate(-50%,-50%);` +
        `pointer-events:none;background-image:url(${url});background-repeat:no-repeat;` +
        `background-size:${bW}px ${bH}px;background-position:${bX}px ${bY}px;` +
        `box-shadow:0 0 0 1px rgba(0,0,0,0.5);z-index:10000;`;
});

// ── Helpers ───────────────────────────────────────────────────────────────
function initials(name) {
    if (!name) return '?';
    const parts = String(name).trim().split(' ').filter(Boolean);
    return parts.length >= 2
        ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
        : parts[0][0].toUpperCase();
}

function avatarColor(name) {
    const palette = ['#3b82f6', '#8b5cf6', '#06b6d4', '#10b981', '#f59e0b', '#ef4444', '#ec4899', '#6366f1'];
    if (!name) return palette[0];
    let h = 0;
    for (const c of String(name)) h = c.charCodeAt(0) + ((h << 5) - h);
    return palette[Math.abs(h) % palette.length];
}

function latLabel(v) {
    return ({ 1: 'OD', 2: 'OE' })[v] ?? 'AO';
}

/**
 * Formato curto dd/mm/aa — paridade com Alpine `formatDateShort`.
 * Útil em strips de thumbnails quando o espaço é apertado.
 */
function formatDateShort(ymd) {
    if (!ymd || ymd === 'unknown') return '—';
    const [y, m, d] = String(ymd).split('-');
    return `${d}/${m}/${y.slice(2)}`;
}

function formatDateFull(ymd) {
    if (!ymd || ymd === 'unknown') return '—';
    const [y, m, d] = String(ymd).split('-');
    return `${d}/${m}/${y}`;
}

function formatDateTime(dt) {
    if (!dt) return '—';
    const d = new Date(dt);
    if (isNaN(d.getTime())) return String(dt);
    return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

// ── Seleção ───────────────────────────────────────────────────────────────
function isSelected(examId) {
    return selectedExamIds.value.includes(examId);
}

function toggleExamSelection(examId) {
    const idx = selectedExamIds.value.indexOf(examId);
    if (idx >= 0) selectedExamIds.value.splice(idx, 1);
    else selectedExamIds.value.push(examId);
}

function groupLatMatching(group, lat) {
    if (lat === 'all') return group.exams;
    if (lat === 'od')  return group.exams.filter(e => e.laterality === 1);
    if (lat === 'oe')  return group.exams.filter(e => e.laterality === 2);
    return group.exams.filter(e => e.laterality !== 1 && e.laterality !== 2);
}

function groupLatActive(group, lat) {
    const matching = groupLatMatching(group, lat);
    return matching.length > 0 && matching.every(e => selectedExamIds.value.includes(e.id));
}

function selectExamByLaterality(group, lat) {
    const matching = groupLatMatching(group, lat);
    if (!matching.length) return;
    const allSelected = matching.every(e => selectedExamIds.value.includes(e.id));
    if (allSelected) {
        selectedExamIds.value = selectedExamIds.value.filter(id => !matching.find(e => e.id === id));
    } else {
        const toAdd = matching.filter(e => !selectedExamIds.value.includes(e.id)).map(e => e.id);
        selectedExamIds.value = [...selectedExamIds.value, ...toAdd];
    }
}

// ── Paciente / URLs ───────────────────────────────────────────────────────
async function selectPatient(patient) {
    selectedPatient.value = patient;
    examUrls.value        = {};
    brokenUrls.value      = {};
    selectedExamIds.value = [];
    urlsLoading.value     = true;
    try {
        const url = props.urls.patient_urls.replace('__ID__', patient.id);
        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
        const data = await res.json();
        examUrls.value = data.urls ?? {};
    } catch {
        examUrls.value = {};
    } finally {
        urlsLoading.value = false;
    }
}

function setDoctor(id) {
    doctorId.value = id;
    selectedPatient.value = null;
    fetchPatients();
}

async function changePeriod(p) {
    period.value = p;
    selectedPatient.value = null;
    search.value = '';
    await fetchPatients();
}

function clearFilters() {
    search.value     = '';
    laterality.value = '';
    examTypeId.value = '';
    examStatus.value = '';
}

async function fetchPatients() {
    loading.value = true;
    try {
        const params = new URLSearchParams({ period: period.value });
        if (doctorId.value) params.append('doctor_id', doctorId.value);
        const res = await fetch(`${props.urls.search}?${params}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        });
        const data = await res.json();
        patients.value = data.patients ?? [];
    } catch (e) {
        console.error('Erro ao buscar pacientes:', e);
    } finally {
        loading.value = false;
    }
}

// ── Viewer ────────────────────────────────────────────────────────────────
function openViewerModal(exams, startIndex = 0) {
    if (!exams || exams.length === 0) return;
    viewerExams.value        = exams;
    viewerPanelExams.value   = [null, null, null, null];
    viewerPanelUrls.value    = [null, null, null, null];
    viewerPanelLoading.value = [false, false, false, false];
    viewerPanelBroken.value  = [false, false, false, false];
    viewerPanelFlipped.value = [false, false, false, false];
    viewerActivePanel.value  = 0;
    viewerAllMode.value      = false;
    viewerSplitMode.value    = false;
    viewerLaserMode.value    = false;
    viewerFitMode.value      = false;
    viewerLensActive.value   = false;
    viewerLensVisible.value  = false;
    viewerPanelCount.value   = 1;
    showViewerModal.value    = true;
    setPanelExam(0, exams[startIndex]);
}

function viewerGoTo(idx) {
    const exam = viewerExams.value[idx];
    if (!exam) return;
    viewerLensVisible.value = false;
    setPanelExam(viewerActivePanel.value, exam);
}

function viewerNext() {
    const idx = viewerActivePanelIndex.value;
    if (idx >= 0 && idx < viewerExams.value.length - 1) viewerGoTo(idx + 1);
}

function viewerPrev() {
    const idx = viewerActivePanelIndex.value;
    if (idx > 0) viewerGoTo(idx - 1);
}

function setPanelExam(pi, exam) {
    const exams   = [...viewerPanelExams.value];
    const urls    = [...viewerPanelUrls.value];
    const loadingArr = [...viewerPanelLoading.value];
    const broken  = [...viewerPanelBroken.value];
    exams[pi]   = exam;
    urls[pi]    = null;
    loadingArr[pi] = false;
    broken[pi]  = false;
    viewerPanelExams.value   = exams;
    viewerPanelUrls.value    = urls;
    viewerPanelLoading.value = loadingArr;
    viewerPanelBroken.value  = broken;
    _loadPanelUrl(pi, exam);
}

function _loadPanelUrl(pi, exam) {
    if (!exam) return;
    const url = examUrls.value[exam.id] ?? null;
    if (!url) return;
    const probe = new Image();
    probe.src = url;
    const urls = [...viewerPanelUrls.value];
    urls[pi] = url;
    viewerPanelUrls.value = urls;
    if (!(probe.complete && probe.naturalWidth > 0)) {
        const loadingArr = [...viewerPanelLoading.value];
        loadingArr[pi] = true;
        viewerPanelLoading.value = loadingArr;
    }
}

function setPanelLoaded(pi) {
    const loadingArr = [...viewerPanelLoading.value];
    loadingArr[pi] = false;
    viewerPanelLoading.value = loadingArr;
}

function setPanelError(pi) {
    const loadingArr = [...viewerPanelLoading.value];
    const broken     = [...viewerPanelBroken.value];
    const urls       = [...viewerPanelUrls.value];
    loadingArr[pi] = false;
    broken[pi]     = true;
    urls[pi]       = null;
    viewerPanelLoading.value = loadingArr;
    viewerPanelBroken.value  = broken;
    viewerPanelUrls.value    = urls;
}

function setViewerPanelCount(n) {
    viewerPanelCount.value = n;
    viewerSplitMode.value  = false;
    for (let i = 0; i < n; i++) {
        if (!viewerPanelExams.value[i] && viewerExams.value[i]) {
            setPanelExam(i, viewerExams.value[i]);
        }
    }
}

function viewerToggleAll() {
    if (viewerAllMode.value) {
        viewerAllMode.value      = false;
        viewerPanelExams.value   = [null, null, null, null];
        viewerPanelUrls.value    = [null, null, null, null];
        viewerPanelLoading.value = [false, false, false, false];
        viewerPanelBroken.value  = [false, false, false, false];
        viewerPanelCount.value   = 0;
        nextTick(() => {
            if (viewerSplitMode.value) {
                viewerPanelCount.value = 2;
                const od = viewerExams.value.find(e => e.laterality === 1);
                const oe = viewerExams.value.find(e => e.laterality === 2);
                if (od) setPanelExam(0, od);
                if (oe) setPanelExam(1, oe);
            } else {
                viewerPanelCount.value = 1;
                if (viewerExams.value[0]) setPanelExam(0, viewerExams.value[0]);
            }
        });
    } else {
        viewerAllMode.value      = true;
        viewerPanelExams.value   = [null, null, null, null];
        viewerPanelUrls.value    = [null, null, null, null];
        viewerPanelLoading.value = [false, false, false, false];
        viewerPanelBroken.value  = [false, false, false, false];
    }
}

function viewerSplitOdOs() {
    if (viewerSplitMode.value) {
        viewerSplitMode.value = false;
        if (!viewerAllMode.value) {
            viewerPanelCount.value   = 1;
            viewerPanelExams.value   = [null, null, null, null];
            viewerPanelUrls.value    = [null, null, null, null];
            viewerPanelLoading.value = [false, false, false, false];
            viewerPanelBroken.value  = [false, false, false, false];
            if (viewerExams.value[0]) setPanelExam(0, viewerExams.value[0]);
        }
    } else {
        viewerSplitMode.value = true;
        if (!viewerAllMode.value) {
            viewerPanelCount.value = 2;
            const od = viewerExams.value.find(e => e.laterality === 1);
            const oe = viewerExams.value.find(e => e.laterality === 2);
            if (od) setPanelExam(0, od);
            if (oe) setPanelExam(1, oe);
        }
    }
}

function allGridExams() {
    if (viewerSplitMode.value) {
        return viewerExams.value.filter(e => e.laterality === 1 || e.laterality === 2);
    }
    return viewerExams.value;
}

function panelStripExams(pi) {
    const exam = viewerPanelExams.value[pi];
    if (!exam) return viewerExams.value;
    const lat = exam.laterality;
    if (lat === 1 || lat === 2) {
        return viewerExams.value.filter(e => e.laterality === lat);
    }
    return viewerExams.value;
}

function toggleAllFlip() {
    viewerLaserMode.value = !viewerLaserMode.value;
    viewerPanelFlipped.value = [
        viewerLaserMode.value, viewerLaserMode.value, viewerLaserMode.value, viewerLaserMode.value,
    ];
}

/**
 * Inverte verticalmente apenas o painel `pi` (sem afetar os outros).
 * Paridade com Alpine `togglePanelFlip` — útil para inverter um painel
 * isoladamente em casos clínicos específicos (ex.: laudos de retinografia
 * com orientação invertida em apenas um dos olhos).
 */
function togglePanelFlip(pi) {
    const flipped = [...viewerPanelFlipped.value];
    flipped[pi] = !flipped[pi];
    viewerPanelFlipped.value = flipped;
}

function toggleLens() {
    viewerLensActive.value = !viewerLensActive.value;
    viewerLensVisible.value = false;
}

function adjustZoom(delta) {
    viewerZoom.value = Math.min(40, Math.max(1.5, viewerZoom.value + delta));
}

function onViewerLensMove(event, pi) {
    if (!viewerLensActive.value) return;
    viewerActivePanel.value = pi;
    const url = viewerPanelUrls.value[pi];
    if (!url) return;
    const container = event.currentTarget;
    const img = container.querySelector('img');
    if (!img) { viewerLensVisible.value = false; return; }
    const r = img.getBoundingClientRect();
    const imgX = event.clientX - r.left;
    const imgY = event.clientY - r.top;
    if (imgX < 0 || imgY < 0 || imgX > r.width || imgY > r.height) {
        viewerLensVisible.value = false;
        return;
    }
    viewerLensX.value = event.clientX;
    viewerLensY.value = event.clientY;
    _viewerW.value = r.width;
    _viewerH.value = r.height;
    _lensImgX.value = imgX;
    _lensImgY.value = imgY;
    _lensUrl.value = null;
    viewerLensVisible.value = true;
}

function onAllLensMove(event, exam) {
    if (!viewerLensActive.value) return;
    const url = examUrls.value[exam.id];
    if (!url) return;
    const container = event.currentTarget;
    const img = container.querySelector('img');
    if (!img) { viewerLensVisible.value = false; return; }
    const r = img.getBoundingClientRect();
    const imgX = event.clientX - r.left;
    const imgY = event.clientY - r.top;
    if (imgX < 0 || imgY < 0 || imgX > r.width || imgY > r.height) {
        viewerLensVisible.value = false;
        return;
    }
    viewerLensX.value = event.clientX;
    viewerLensY.value = event.clientY;
    _viewerW.value = r.width;
    _viewerH.value = r.height;
    _lensImgX.value = imgX;
    _lensImgY.value = imgY;
    _lensUrl.value = url;
    viewerLensVisible.value = true;
}

function onPanelEnter(pi) {
    viewerActivePanel.value = pi;
    if (viewerLensActive.value && viewerPanelUrls.value[pi] && !viewerPanelBroken.value[pi]) {
        viewerLensVisible.value = true;
    }
}

function onPanelLeave() {
    viewerLensVisible.value = false;
}

function onPanelWheel(event, pi) {
    if (!viewerLensActive.value) return;
    viewerActivePanel.value = pi;
    const s = event.deltaY < 0 ? 0.5 : -0.5;
    adjustZoom(s);
}

function onAllWheel(event) {
    if (!viewerLensActive.value) return;
    event.preventDefault();
    event.stopPropagation();
    const s = event.deltaY < 0 ? 0.5 : -0.5;
    adjustZoom(s);
}

function onAllEnter(exam) {
    if (viewerLensActive.value && examUrls.value[exam.id] && !brokenUrls.value[exam.id]) {
        viewerLensVisible.value = true;
    }
}

// ── Impressão ─────────────────────────────────────────────────────────────
function openPrintModal(exams, autoPrint = false) {
    printExams.value = exams ?? [];
    showPrintModal.value = true;
    if (autoPrint) {
        nextTick(() => setTimeout(printReport, 300));
    }
}

function printReport() {
    window.print();
}

// ── Keyboard nav ──────────────────────────────────────────────────────────
function onKeyDown(e) {
    if (!showViewerModal.value) return;
    if (e.key === 'Escape')       { showViewerModal.value = false; }
    if (e.key === 'ArrowLeft')    { viewerPrev(); }
    if (e.key === 'ArrowRight')   { viewerNext(); }
}
function onPrintKey(e) {
    if (showPrintModal.value && e.key === 'Escape') showPrintModal.value = false;
}

onMounted(() => {
    window.addEventListener('keydown', onKeyDown);
    window.addEventListener('keydown', onPrintKey);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeyDown);
    window.removeEventListener('keydown', onPrintKey);
});

// ── IA modal (mantido do código anterior) ─────────────────────────────────
const aiEnabled   = computed(() => !!props.ai?.enabled);
const aiWorkflows = computed(() => Array.isArray(props.ai?.workflows) ? props.ai.workflows : []);
const aiLabels    = computed(() => props.ai?.labels ?? {});
const aiLabel = (key, fallback = '') => aiLabels.value?.[key] ?? fallback;

const aiPatients = computed(() => patients.value.map((p) => ({
    id: p.id, name: p.person?.full_name ?? p.full_name, code: p.code,
})));
const aiSelectedPatient = computed(() => {
    if (!selectedPatient.value) return null;
    return {
        id: selectedPatient.value.id,
        name: selectedPatient.value.person?.full_name ?? selectedPatient.value.full_name ?? '—',
        code: selectedPatient.value.code ?? '',
    };
});
const aiShowPatientSelector = computed(() => !aiSelectedPatient.value);

const aiModalOpen  = ref(false);
const aiEstimating = ref(false);
const aiSubmitting = ref(false);
const aiEstimate   = ref(null);
const aiBalance    = reactive({ available: '—', reserved: '—' });

// Motivo de o "Executar IA" estar desabilitado — exibido no rodapé + tooltip.
// A estimativa é opcional: o custo é calculado/reservado no servidor ao executar.
const aiRunDisabledReason = computed(() => {
    if (isEyeImageWorkflow.value && !aiHasSelection.value) {
        return aiLabel('run_needs_images', 'Selecione ao menos uma imagem para executar.');
    }
    return '';
});
const aiAlert      = reactive({ type: '', message: '' });
const aiForm       = reactive({
    workflow: props.ai?.default_workflow ?? aiWorkflows.value[0] ?? 'exam_assistant',
    risk_level: 'medium',
    patient_id: '',
    user_prompt: '',
    system_prompt: aiLabel('system_prompt_default', 'Você é um assistente de apoio clínico. Nunca emita decisão final.'),
    max_output_tokens: 700,
});

// Modos disponíveis vêm do backend (escalam com o nº de provedores ativos:
// Gemini-only => só Economia). Consenso continua exigindo o recurso.
const aiModes = computed(() => Array.isArray(props.ai?.modes) ? props.ai.modes : []);
const aiMode  = computed(() => {
    if (aiForm.workflow === 'consensus_review') return 'consensus';
    return aiModes.value[0]?.value ?? 'economy';
});
const isEyeImageWorkflow = computed(() => aiForm.workflow === 'eye_image_analysis');

// Estado de acompanhamento do run (polling + aprovação).
const aiRunId      = ref(null);
const aiRunStatus  = ref('');
const aiRunOutput  = ref('');
const aiActioning  = ref(false);

const aiMaxImages       = computed(() => Number(props.ai?.max_images ?? 4));
const aiSelectedCount   = computed(() => selectedExamIds.value.length);
const aiHasSelection    = computed(() => aiSelectedCount.value > 0);
// Prompt clínico padrão para análise de imagem (atinge o mínimo de 12 chars).
const aiDefaultEyePrompt = 'Analisar as imagens oculares selecionadas e descrever os achados por estrutura e lateralidade.';

watch(
    () => [aiForm.workflow, aiForm.risk_level, aiForm.patient_id, aiForm.user_prompt, aiForm.system_prompt, aiForm.max_output_tokens],
    () => { aiEstimate.value = null; },
);

watch(
    () => aiSelectedPatient.value?.id ?? null,
    (patientId) => {
        if (patientId) {
            aiForm.patient_id = patientId;
            return;
        }
        if (aiForm.patient_id && !aiPatients.value.some((p) => p.id === aiForm.patient_id)) {
            aiForm.patient_id = '';
        }
    },
    { immediate: true },
);

watch(
    () => aiPatients.value.map((p) => p.id).join('|'),
    () => {
        if (aiSelectedPatient.value?.id) return;
        if (aiForm.patient_id && !aiPatients.value.some((p) => p.id === aiForm.patient_id)) {
            aiForm.patient_id = '';
        }
    },
);

// ── Painel de IA compartilhado (substitui o modal inline) ───────────────────
const aiPanelOpen  = ref(false);
const aiViewReport = ref(null);

const aiPanelContext = computed(() => ({
    workflow_default: 'eye_image_analysis',
    patient_id:       aiSelectedPatient.value?.id ?? null,
    exam_ids:         selectedExamIds.value,
}));

function openAiModal() {
    aiViewReport.value = null;
    aiPanelOpen.value  = true;
}
function onAiApproved() {
    fetchPatients(); // atualiza badges/laudos
}
function onAiNeedsRecord(payload) {
    if (payload?.run_id) maybeOpenRecord(payload.run_id);
}
function closeAiModal() { aiModalOpen.value = false; }
function setAiAlert(type, message) { aiAlert.type = type; aiAlert.message = message; }
function clearAiAlert() { aiAlert.type = ''; aiAlert.message = ''; }

// Mensagem de erro detalhada (status + validação) para diagnóstico real.
function aiErrorMessage(error, fallback) {
    const res = error?.response;
    if (!res) return `${fallback} (sem resposta do servidor — verifique a conexão/sessão)`;
    const d = res.data ?? {};
    if (d.message) {
        const firstErr = d.errors ? Object.values(d.errors)?.[0]?.[0] : null;
        return firstErr ? `${d.message} — ${firstErr}` : d.message;
    }
    return `${fallback} (HTTP ${res.status})`;
}
function resetAiRun() {
    aiRunId.value = null;
    aiRunStatus.value = '';
    aiRunOutput.value = '';
}
function aiWorkflowLabel(workflow) {
    if (workflow === 'eye_image_analysis') return aiLabel('workflow_eye_image_analysis', 'Análise de imagem ocular');
    if (workflow === 'exam_assistant')     return aiLabel('workflow_exam_assistant', 'Assistente de exame');
    if (workflow === 'consensus_review')   return aiLabel('workflow_consensus_review', 'Revisão de consistência');
    return workflow;
}
function aiPayload() {
    return {
        workflow: aiForm.workflow, mode: aiMode.value, risk_level: aiForm.risk_level,
        patient_id: aiForm.patient_id || null, medical_record_id: null,
        user_prompt: aiForm.user_prompt, system_prompt: aiForm.system_prompt,
        context: { specialty: 'ophthalmology', source: 'eye_images_top_modal' },
        attachments: [],
        // Eye Image: envia as imagens selecionadas; o backend resolve o base64.
        exam_ids: isEyeImageWorkflow.value ? selectedExamIds.value : [],
        expects_json: false,
        max_output_tokens: Number(aiForm.max_output_tokens || 700),
    };
}
function aiValidate() {
    if (isEyeImageWorkflow.value && !aiHasSelection.value) {
        setAiAlert('warning', aiLabel('eye_image_none', 'Selecione ao menos uma imagem para analisar.'));
        return false;
    }
    if (!aiForm.user_prompt || aiForm.user_prompt.trim().length < 12) {
        setAiAlert('warning', aiLabel('prompt_min_chars', 'O prompt clínico deve ter pelo menos 12 caracteres.'));
        return false;
    }
    return true;
}
async function estimateAiRun() {
    clearAiAlert();
    if (!aiValidate()) return;
    aiEstimating.value = true;
    try {
        const { data } = await window.axios.post(route('panel.ai-runs.estimate'), aiPayload());
        aiEstimate.value    = data?.estimate ?? null;
        aiBalance.available = data?.balance?.available ?? '—';
        aiBalance.reserved  = data?.balance?.reserved ?? '—';
    } catch (error) {
        setAiAlert('danger', aiErrorMessage(error, aiLabel('estimate_failed', 'Falha ao estimar custo.')));
    } finally {
        aiEstimating.value = false;
    }
}
async function submitAiRun() {
    clearAiAlert();
    if (!aiValidate()) return;
    // Estimativa é opcional: o servidor calcula o custo e reserva os créditos no
    // store (com tratamento de saldo insuficiente). Não bloqueamos sem estimar.
    aiSubmitting.value = true;
    try {
        const { data } = await window.axios.post(route('panel.ai-runs.store'), aiPayload());
        aiRunId.value = data?.run_id ?? null;
        aiEstimate.value = null;
        if (aiRunId.value) {
            await pollAiRun(aiRunId.value);
        } else {
            setAiAlert('success', aiLabel('run_created_waiting_review', 'Execução criada e enviada para revisão médica.'));
        }
    } catch (error) {
        setAiAlert('danger', aiErrorMessage(error, aiLabel('run_create_failed', 'Falha ao criar execução.')));
    } finally {
        aiSubmitting.value = false;
    }
}
// Aguarda a execução do run (job) até ficar pronto para revisão médica.
async function pollAiRun(runId) {
    aiRunStatus.value = 'processing';
    const showUrl = (props.ai?.urls?.show ?? '').replace('__ID__', runId);
    for (let i = 0; i < 40; i++) {
        await new Promise((r) => setTimeout(r, 3000));
        try {
            const { data } = await window.axios.get(showUrl, { headers: { Accept: 'application/json' } });
            const run = data?.data ?? {};
            aiRunStatus.value = run.status ?? '';
            if (run.status === 'waiting_approval') {
                aiRunOutput.value = run.final_output ?? '';
                return;
            }
            if (['failed', 'rejected', 'cancelled'].includes(run.status)) {
                setAiAlert('danger', run.error_message ?? aiLabel('run_create_failed', 'A análise não pôde ser concluída.'));
                return;
            }
        } catch { /* mantém o polling */ }
    }
    setAiAlert('warning', aiLabel('processing', 'Processando análise...'));
}
async function actAiRun(action) {
    if (!aiRunId.value) return;
    aiActioning.value = true;
    clearAiAlert();
    try {
        const url = (props.ai?.urls?.[action] ?? '').replace('__ID__', aiRunId.value);
        const { data } = await window.axios.post(url, action === 'approve' ? { final_output: aiRunOutput.value } : {});

        // Sem prontuário do dia da consulta: pergunta ao médico se pode abrir.
        if (action === 'approve' && data?.requires_record_confirmation) {
            await maybeOpenRecord(aiRunId.value);
        }

        setAiAlert('success', action === 'approve'
            ? aiLabel('eye_image_reported', 'Laudado (IA)')
            : aiLabel('reject', 'Rejeitar'));
        resetAiRun();
        await fetchPatients(); // atualiza badges/laudos
        aiModalOpen.value = false;
    } catch (error) {
        setAiAlert('danger', error?.response?.data?.message ?? aiLabel('run_create_failed', 'Falha ao registrar a decisão.'));
    } finally {
        aiActioning.value = false;
    }
}

// Pergunta ao médico se pode abrir um prontuário do dia para receber o laudo.
async function maybeOpenRecord(runId) {
    const message = aiLabel('record_confirm_open',
        'Não há prontuário do dia da consulta. Deseja abrir um novo prontuário para registrar o laudo?');

    const confirmed = window.Swal
        ? (await window.Swal.fire({
            icon: 'question', title: message,
            showCancelButton: true,
            confirmButtonText: aiLabel('approve', 'Sim'),
            cancelButtonText: aiLabel('close', 'Não'),
        })).isConfirmed
        : window.confirm(message);

    if (!confirmed) return;

    const url = (props.ai?.urls?.record ?? '').replace('__ID__', runId);
    await window.axios.post(url, {});
}

// Abre o painel mostrando um laudo de IA já aprovado (somente leitura).
function openExistingReport(exam) {
    if (!exam?.ai_report?.content) return;
    aiViewReport.value = { content: exam.ai_report.content };
    aiPanelOpen.value  = true;
}

// Opções para SearchSelect (arrays de objetos {value,label}).
const aiWorkflowOptions = computed(() =>
    aiWorkflows.value.map((workflow) => ({ value: workflow, label: aiWorkflowLabel(workflow) })),
);
const aiPatientOptions = computed(() =>
    aiPatients.value.map((p) => ({ value: p.id, label: `${p.name} (${p.code})` })),
);

// Entidade p/ cabeçalho da impressão
const printEntity = computed(() => props.entity ?? {});
</script>

<template>
    <AppLayout title="Imagens oftálmicas" :breadcrumbs="breadcrumbs">
        <template #top-actions>
            <div v-if="aiEnabled" class="header-item d-none d-sm-flex me-2">
                <button class="btn btn-liner-gradient d-inline-flex align-items-center gap-1"
                        type="button" :title="aiLabel('assistance_button', 'Assistente de IA')"
                        @click="openAiModal">
                    <i class="ti ti-robot fs-16"></i>
                    <span class="fw-medium">{{ aiLabel('assistance_button', 'Assistente de IA') }}</span>
                    <i class="ti ti-chevron-down fs-12 opacity-75"></i>
                </button>
            </div>
        </template>

        <PageHeader title="Imagens oftálmicas" :subtitle="`${totalExams} exames`" />

        <!-- ── Card de filtros ───────────────────────────────────────────────── -->
        <div class="card mb-3">
            <div class="card-body py-2 px-3">

                <div class="row g-2 align-items-center">
                    <div class="col-12 col-sm-4 col-md-3">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fa fa-search"></i></span>
                            <input type="text" class="form-control"
                                   placeholder="Buscar paciente..."
                                   v-model="search" @keydown.escape="search = ''">
                            <button v-if="search" class="btn btn-outline-secondary" type="button"
                                    @click="search = ''">
                                <i class="fa fa-times"></i>
                            </button>
                        </div>
                    </div>

                    <div class="col-6 col-sm-3 col-md-2">
                        <SearchSelect v-model="period"
                                      :options="[
                                          {value:'hoje',label:'Hoje'},
                                          {value:'7',label:'Últimos 7 dias'},
                                          {value:'15',label:'Últimos 15 dias'},
                                          {value:'30',label:'Últimos 30 dias'},
                                          {value:'90',label:'Últimos 90 dias'},
                                      ]"
                                      :value-key="'value'" :label-key="'label'"
                                      :clearable="false"
                                      @change="changePeriod" />
                    </div>

                    <div class="col-6 col-sm-auto">
                        <button type="button" class="btn btn-sm"
                                :class="showFilters ? 'btn-primary' : 'btn-outline-secondary'"
                                @click="showFilters = !showFilters">
                            <i class="fa fa-filter me-1"></i>Filtros
                            <i class="fa fa-chevron-down ms-1" v-if="!showFilters"></i>
                            <i class="fa fa-chevron-up ms-1" v-else></i>
                        </button>
                    </div>

                    <div class="col col-md d-flex justify-content-end">
                        <button type="button" class="btn btn-primary btn-sm">
                            <i class="fa fa-plus"></i> Novo
                        </button>
                    </div>
                </div>

                <div v-show="showFilters" class="row g-2 mt-1 pt-2 border-top align-items-center">
                    <div class="col-auto">
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-muted small fw-semibold" style="white-space:nowrap;">Olho</span>
                            <div class="btn-group btn-group-sm" role="group">
                                <input type="radio" class="btn-check" name="f-lat" id="f-lat-all" value=""
                                       v-model="laterality">
                                <label class="btn btn-outline-secondary" for="f-lat-all">Todos</label>

                                <input type="radio" class="btn-check" name="f-lat" id="f-lat-od" value="od"
                                       v-model="laterality">
                                <label class="btn btn-outline-primary" for="f-lat-od">OD</label>

                                <input type="radio" class="btn-check" name="f-lat" id="f-lat-oe" value="oe"
                                       v-model="laterality">
                                <label class="btn btn-outline-danger" for="f-lat-oe">OE</label>

                                <input type="radio" class="btn-check" name="f-lat" id="f-lat-ao" value="ao"
                                       v-model="laterality">
                                <label class="btn btn-outline-dark" for="f-lat-ao">AO</label>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-md-3">
                        <SearchSelect v-model="examTypeId" :options="availableExamTypes"
                                      :placeholder="'Todos os exames'" />
                    </div>

                    <div class="col-12 col-sm-6 col-md-2">
                        <SearchSelect v-model="examStatus"
                                      :options="[
                                          {value:'solicitado',label:'Solicitado'},
                                          {value:'realizado',label:'Realizado'},
                                          {value:'laudado',label:'Laudado'},
                                          {value:'cancelado',label:'Cancelado'},
                                      ]"
                                      :value-key="'value'" :label-key="'label'"
                                      :placeholder="'Todos status'" />
                    </div>

                    <div class="col-12 col-sm-6 col-md-3">
                        <SearchSelect v-model="doctorId" :options="doctors"
                                      :placeholder="'Todos médicos'"
                                      @change="setDoctor" />
                    </div>

                    <div class="col-auto">
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="clearFilters">
                            <i class="fa fa-times me-1"></i> Limpar
                        </button>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Layout: lateral + principal ───────────────────────────────────── -->
        <div class="row">
            <!-- Lateral: pacientes -->
            <div class="col-xs-12 col-sm-3 col-md-3 col-lg-3">
                <div class="card panel-info">
                    <div class="card-body p-2">
                        <h6 class="font-bold text-uppercase px-1 mb-1 mt-3">Pacientes</h6>
                        <hr class="mt-0 mb-2">

                        <div v-if="loading" class="text-center py-3">
                            <div class="spinner-border spinner-border-sm text-info" role="status"></div>
                        </div>

                        <div v-else style="max-height:520px;overflow-y:auto;overflow-x:hidden;">
                            <p v-if="filteredPatients.length === 0" class="text-muted text-center small py-3 mb-0">
                                Nenhum paciente encontrado.
                            </p>

                            <div
                                v-for="patient in filteredPatients" :key="patient.id"
                                class="d-flex align-items-center gap-2 px-1 py-1 rounded mb-1 patient-item"
                                :class="{ 'patient-item-active': selectedPatient?.id === patient.id }"
                                @click="selectPatient(patient)"
                            >
                                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 text-white fw-bold"
                                     :style="{ background: avatarColor(patient.person?.full_name ?? patient.full_name), width: '30px', height: '30px', fontSize: '.62rem' }">
                                    {{ initials(patient.person?.full_name ?? patient.full_name) }}
                                </div>

                                <div class="flex-grow-1 min-w-0">
                                    <div class="text-truncate fw-semibold" style="font-size:.75rem; line-height:1.2;">
                                        {{ patient.person?.full_name ?? patient.full_name ?? '—' }}
                                    </div>
                                    <div class="text-muted" style="font-size:.65rem; line-height:1.2;">
                                        {{ patient.code }}
                                    </div>
                                </div>

                                <span class="badge bg-primary rounded-pill flex-shrink-0" style="font-size:.6rem;">
                                    {{ patient.exams.length }}
                                </span>
                            </div>
                        </div>

                        <div v-if="!loading" class="text-muted px-1 mt-1" style="font-size:.65rem;">
                            {{ filteredPatients.length }} paciente(s)
                        </div>
                    </div>
                </div>
            </div>

            <!-- Principal: detalhe -->
            <div class="col-xs-12 col-sm-9 col-md-9 col-lg-9">
                <div class="card">
                    <h5 class="card-header d-flex align-items-center gap-2">
                        <span v-if="!selectedPatient">Selecione um paciente</span>
                        <span v-else class="d-flex align-items-center gap-2 w-100">
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                    @click="selectedPatient = null; selectedExamIds = [];">
                                <i class="fa fa-arrow-left"></i>
                            </button>
                            <span>
                                <span>{{ selectedPatient.person?.full_name ?? selectedPatient.full_name }}</span>
                                <small class="text-muted fw-normal ms-2" style="font-size:.72rem;">
                                    {{ selectedPatient.code }}
                                </small>
                            </span>
                            <div class="flex-grow-1"></div>
                            <a :href="`/panel/patients/${selectedPatient.id}/medicalrecords`"
                               target="_blank" class="btn btn-outline-primary btn-sm" style="font-size:.72rem;">
                                Prontuário <i class="fa fa-external-link ms-1"></i>
                            </a>
                        </span>
                    </h5>

                    <!-- Barra de ações -->
                    <div v-if="selectedPatient"
                         class="d-flex align-items-center gap-2 px-3 py-2 border-bottom bg-body-secondary">
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                :disabled="selectedExamIds.length === 0"
                                @click="openViewerModal(selectedExamsData)">
                            <i class="fa fa-images me-1"></i>Visualizar selecionados
                            <span class="badge bg-primary ms-1" v-if="selectedExamIds.length > 0">
                                {{ selectedExamIds.length }}
                            </span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                @click="openViewerModal(selectedPatient.exams)">
                            <i class="fa fa-th me-1"></i>Visualizar todos
                        </button>
                        <div class="vr opacity-25"></div>
                        <button type="button" class="btn btn-sm btn-outline-dark"
                                @click="openPrintModal(selectedPatient.exams, false)">
                            <i class="fa fa-print me-1"></i>Imprimir
                        </button>
                        <div class="flex-grow-1"></div>
                        <span v-if="selectedExamIds.length > 0" class="text-muted" style="font-size:.7rem;">
                            {{ selectedExamIds.length }} selecionado(s)
                        </span>
                    </div>

                    <div class="card-body">
                        <!-- Placeholder -->
                        <div v-if="!selectedPatient" class="text-center py-5 text-muted">
                            <i class="ti ti-eye" style="font-size:3rem;opacity:.3;"></i>
                            <p class="mt-3 mb-0">Selecione um paciente para ver os exames.</p>
                        </div>

                        <!-- Detalhe paciente -->
                        <div v-else class="row g-0" style="min-height:480px;">
                            <div class="col-12 border-end pe-0">
                                <div v-if="urlsLoading" class="text-center py-3">
                                    <div class="spinner-border spinner-border-sm text-secondary" role="status"></div>
                                    <p class="text-muted small mt-1 mb-0">Carregando imagens...</p>
                                </div>

                                <p v-else-if="filteredExams.length === 0" class="text-muted text-center small py-4">
                                    Nenhum exame encontrado.
                                </p>

                                <div v-else style="max-height:620px;overflow-y:auto;overflow-x:hidden;">
                                    <div v-for="group in groupedExams" :key="group.key" class="mb-1">
                                        <!-- Header do grupo -->
                                        <div class="px-2 py-1 d-flex align-items-center gap-1 flex-wrap bg-body-tertiary text-body border-bottom fw-semibold"
                                             style="font-size:.7rem;row-gap:3px;">
                                            <span>{{ formatDateFull(group.date) }}</span>
                                            <span v-if="group.equipment" class="d-flex align-items-center gap-1">
                                                <span class="opacity-50">:</span>
                                                <span>{{ group.equipment.name }}</span>
                                            </span>

                                            <div class="flex-grow-1"></div>

                                            <div class="btn-group btn-group-sm" role="group">
                                                <button type="button" class="btn py-0 px-2"
                                                        :class="groupLatActive(group, 'od') ? 'btn-primary' : 'btn-outline-primary'"
                                                        style="font-size:.6rem;"
                                                        @click.stop="selectExamByLaterality(group, 'od')">OD</button>
                                                <button type="button" class="btn py-0 px-2"
                                                        :class="groupLatActive(group, 'oe') ? 'btn-danger' : 'btn-outline-danger'"
                                                        style="font-size:.6rem;"
                                                        @click.stop="selectExamByLaterality(group, 'oe')">OE</button>
                                                <button type="button" class="btn py-0 px-2"
                                                        :class="groupLatActive(group, 'ao') ? 'btn-secondary' : 'btn-outline-secondary'"
                                                        style="font-size:.6rem;"
                                                        @click.stop="selectExamByLaterality(group, 'ao')">AO</button>
                                                <button type="button" class="btn py-0 px-2"
                                                        :class="groupLatActive(group, 'all') ? 'btn-secondary' : 'btn-outline-secondary'"
                                                        style="font-size:.6rem;"
                                                        @click.stop="selectExamByLaterality(group, 'all')">Todos</button>
                                            </div>

                                            <div class="vr opacity-25 mx-1"></div>

                                            <button type="button" class="btn btn-sm py-0 px-2 btn-outline-secondary"
                                                    style="font-size:.6rem;" title="Upload de imagem">
                                                <i class="fa fa-upload me-1"></i>Upload
                                            </button>
                                            <button type="button" class="btn btn-sm py-0 px-2 btn-outline-secondary"
                                                    style="font-size:.6rem;" title="Download das imagens">
                                                <i class="fa fa-download me-1"></i>Download
                                            </button>
                                        </div>

                                        <!-- Subtítulo: tipo de exame -->
                                        <div class="px-2 py-1 bg-body-secondary text-body-secondary border-bottom"
                                             style="font-size:.68rem;">
                                            {{ group.examType?.name || 'Exame' }}
                                        </div>

                                        <!-- Thumbnails -->
                                        <div class="d-flex flex-wrap gap-2 p-2 bg-dark">
                                            <div v-for="exam in group.exams" :key="exam.id"
                                                 class="position-relative"
                                                 style="cursor:pointer;flex-shrink:0;"
                                                 @click="toggleExamSelection(exam.id)">

                                                <!-- Badge lateralidade -->
                                                <span class="position-absolute top-0 end-0 rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                                                      :class="{
                                                          'bg-primary': exam.laterality === 1,
                                                          'bg-danger': exam.laterality === 2,
                                                          'bg-secondary': exam.laterality !== 1 && exam.laterality !== 2
                                                      }"
                                                      style="width:22px;height:22px;font-size:.55rem;z-index:1;margin:3px;">
                                                    {{ latLabel(exam.laterality) }}
                                                </span>

                                                <!-- Checkbox seleção -->
                                                <span class="position-absolute bottom-0 start-0 d-flex align-items-center justify-content-center"
                                                      style="z-index:2;margin:3px;">
                                                    <span class="rounded d-flex align-items-center justify-content-center"
                                                          :class="isSelected(exam.id) ? 'bg-primary' : 'bg-dark border border-secondary'"
                                                          style="width:16px;height:16px;">
                                                        <i v-show="isSelected(exam.id)" class="fa fa-check text-white"
                                                           style="font-size:.5rem;"></i>
                                                    </span>
                                                </span>

                                                <!-- Badge laudo IA -->
                                                <span v-if="exam.ai_report?.approved"
                                                      class="position-absolute bottom-0 end-0 badge bg-info text-dark d-flex align-items-center"
                                                      style="z-index:2;margin:3px;font-size:.5rem;cursor:pointer;"
                                                      :title="aiLabel('eye_image_reported', 'Laudado (IA)')"
                                                      @click.stop="openExistingReport(exam)">
                                                    <i class="ti ti-robot me-1"></i>IA
                                                </span>

                                                <!-- Thumbnail com imagem -->
                                                <img v-if="examUrls[exam.id] && !brokenUrls[exam.id]"
                                                     :src="examUrls[exam.id]" :alt="exam.exam_type?.name"
                                                     width="100" height="76"
                                                     :style="`object-fit:cover;display:block;border-radius:4px;outline:${isSelected(exam.id) ? '2px solid #6ea8fe' : '2px solid transparent'};transition:outline .1s;`"
                                                     @error="brokenUrls = { ...brokenUrls, [exam.id]: true }">

                                                <div v-else
                                                     class="d-flex align-items-center justify-content-center rounded"
                                                     :style="`width:100px;height:76px;background:#3a3c42;border-radius:4px;outline:${isSelected(exam.id) ? '2px solid #6ea8fe' : '2px solid transparent'};transition:outline .1s;`">
                                                    <i class="ti ti-photo-off" style="font-size:1.4rem;color:#555;"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Modal visualizador split-panel ──────────────────────────────── -->
        <Teleport to="body">
        <div v-show="showViewerModal"
             style="position:fixed;inset:0;z-index:9998;background:#0a0a0a;display:flex;flex-direction:column;overflow:hidden;">

            <!-- Toolbar -->
            <div class="d-flex align-items-center gap-1 px-3 py-2 flex-shrink-0 flex-wrap"
                 style="background:#111;border-bottom:1px solid #222;row-gap:4px;">

                <div class="btn-group btn-group-sm" role="group">
                    <button v-for="n in [1,2,3,4]" :key="n" type="button" class="btn fw-semibold"
                            :class="viewerPanelCount === n ? 'btn-primary' : 'btn-outline-secondary'"
                            style="min-width:26px;font-size:.72rem;"
                            @click="setViewerPanelCount(n)">{{ n }}</button>
                </div>

                <div class="vr opacity-25 mx-1"></div>

                <button type="button" class="btn btn-sm fw-semibold"
                        :class="viewerAllMode ? 'btn-info text-dark' : 'btn-outline-secondary'"
                        style="font-size:.72rem;" @click="viewerToggleAll">All</button>

                <div class="vr opacity-25 mx-1"></div>

                <button type="button" class="btn btn-sm fw-semibold"
                        :class="viewerLensActive ? 'btn-warning text-dark' : 'btn-outline-secondary'"
                        style="font-size:.72rem;" @click="toggleLens">
                    <i class="fa fa-search-plus"></i> Lens
                </button>
                <div v-if="viewerLensActive" class="d-flex align-items-center gap-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                            @click="adjustZoom(-0.5)">
                        <i class="fa fa-minus" style="font-size:.65rem;"></i>
                    </button>
                    <span style="color:#fff;font-size:.78rem;font-weight:600;min-width:48px;text-align:center;display:inline-block;">
                        {{ viewerZoom.toFixed(1) }}x
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                            @click="adjustZoom(0.5)">
                        <i class="fa fa-plus" style="font-size:.65rem;"></i>
                    </button>
                </div>

                <div class="vr opacity-25 mx-1"></div>

                <button type="button" class="btn btn-sm fw-semibold"
                        :class="viewerFitMode ? 'btn-light text-dark' : 'btn-outline-secondary'"
                        style="font-size:.72rem;"
                        @click="viewerFitMode = !viewerFitMode"
                        title="Ajustar à área de visualização">
                    <i class="fa fa-compress-arrows-alt me-1"></i>Fit
                </button>

                <button type="button" class="btn btn-sm fw-semibold"
                        :class="viewerSplitMode ? 'btn-info text-dark' : 'btn-outline-secondary'"
                        style="font-size:.72rem;" @click="viewerSplitOdOs">OD|OE</button>

                <button type="button" class="btn btn-sm fw-semibold"
                        :class="viewerLaserMode ? 'btn-success' : 'btn-outline-secondary'"
                        style="font-size:.72rem;" @click="toggleAllFlip"
                        title="Inverter imagem verticalmente">
                    <i class="fa fa-undo me-1"></i>Laser
                </button>

                <div class="vr opacity-25 mx-1"></div>
                <div class="flex-grow-1"></div>

                <button type="button" class="btn btn-sm btn-outline-danger" @click="showViewerModal = false">
                    <i class="fa fa-times"></i>
                </button>
            </div>

            <!-- Corpo: painéis -->
            <div v-show="!viewerAllMode" class="flex-grow-1" :style="viewerPanelGridStyle">
                <div v-for="pi in viewerPanelCount" :key="pi"
                     class="position-relative d-flex flex-column"
                     :style="`background:#111;border-radius:3px;min-height:0;cursor:pointer;overflow:hidden;outline:${viewerActivePanel === (pi - 1) ? '2px solid #0d6efd' : '1px solid #2a2a2a'};`"
                     @click="viewerActivePanel = pi - 1">

                    <div class="flex-grow-1 position-relative d-flex"
                         :class="viewerFitMode ? 'align-items-start justify-content-center' : 'align-items-center justify-content-center'"
                         :style="(viewerFitMode ? 'height:84vh;min-height:0;overflow-y:auto;overflow-x:hidden;' : 'min-height:0;overflow:hidden;') + (viewerLensActive && viewerPanelUrls[pi - 1] && !viewerPanelBroken[pi - 1] ? 'cursor:none;' : '')"
                         @mousemove.stop="onViewerLensMove($event, pi - 1)"
                         @mouseleave.stop="onPanelLeave"
                         @mouseenter.stop="onPanelEnter(pi - 1)"
                         @wheel.prevent.stop="onPanelWheel($event, pi - 1)">

                        <div v-show="viewerPanelLoading[pi - 1]"
                             class="text-center text-white position-absolute" style="z-index:5;">
                            <div class="spinner-border spinner-border-sm text-light" role="status"></div>
                        </div>

                        <div v-show="!viewerPanelExams[pi - 1] && !viewerPanelLoading[pi - 1]"
                             class="text-center text-muted">
                            <i class="ti ti-photo" style="font-size:2.5rem;opacity:.12;"></i>
                            <p class="mt-1 mb-0" style="font-size:.65rem;opacity:.35;">Painel {{ pi }}</p>
                        </div>

                        <div v-show="viewerPanelExams[pi - 1] && !viewerPanelUrls[pi - 1] && !viewerPanelLoading[pi - 1] && !viewerPanelBroken[pi - 1]"
                             class="text-center text-muted">
                            <i class="ti ti-photo-off" style="font-size:2rem;opacity:.3;"></i>
                            <p class="mt-1 mb-0" style="font-size:.65rem;">Sem imagem</p>
                        </div>

                        <div v-show="viewerPanelBroken[pi - 1]" class="text-center text-muted">
                            <i class="ti ti-photo-off" style="font-size:2rem;opacity:.3;"></i>
                            <p class="mt-1 mb-0" style="font-size:.65rem;">Arquivo não encontrado</p>
                        </div>

                        <img v-show="viewerPanelUrls[pi - 1] && !viewerPanelLoading[pi - 1] && !viewerPanelBroken[pi - 1]"
                             :src="viewerPanelUrls[pi - 1] ?? ''" alt="Exame"
                             :style="(viewerFitMode ? 'width:100%;height:auto;max-width:100%;max-height:none;flex-shrink:0;' : 'width:100%;height:84vh;object-fit:contain;') + 'display:block;user-select:none;' + (viewerPanelFlipped[pi - 1] ? 'transform:scaleY(-1);' : '')"
                             @load="setPanelLoaded(pi - 1)"
                             @error="setPanelError(pi - 1)">
                    </div>

                    <!-- Barra de info -->
                    <div class="d-flex align-items-center gap-1 px-2 flex-shrink-0"
                         style="background:#0d0d0d;font-size:.6rem;min-height:22px;border-top:1px solid #1a1a1a;">
                        <span v-if="viewerPanelExams[pi - 1]"
                              class="d-flex align-items-center gap-1 overflow-hidden w-100">
                            <span class="badge flex-shrink-0"
                                  :class="{
                                      'bg-primary': viewerPanelExams[pi - 1].laterality === 1,
                                      'bg-danger': viewerPanelExams[pi - 1].laterality === 2,
                                      'bg-secondary': viewerPanelExams[pi - 1].laterality !== 1 && viewerPanelExams[pi - 1].laterality !== 2,
                                  }"
                                  style="font-size:.5rem;">
                                {{ latLabel(viewerPanelExams[pi - 1].laterality) }}
                            </span>
                            <span class="text-secondary text-truncate">
                                {{ viewerPanelExams[pi - 1].exam_type?.name ?? '—' }}
                            </span>
                            <span class="text-secondary opacity-50 flex-shrink-0 ms-auto">
                                {{ formatDateFull(viewerPanelExams[pi - 1].created_at?.substring(0, 10)) }}
                            </span>
                        </span>
                        <span v-else class="text-secondary" style="opacity:.3;">Painel {{ pi }}</span>
                    </div>

                    <!-- Strip de thumbnails -->
                    <div class="flex-shrink-0 d-flex align-items-center gap-1 overflow-x-auto overflow-y-hidden py-1 px-1"
                         :style="`background:#0d0d0d;height:80px;border-top:1px solid ${viewerActivePanel === (pi - 1) ? '#0d6efd' : '#222'};`"
                         @click.stop>
                        <div v-for="exam in panelStripExams(pi - 1)" :key="`tn-${pi}-${exam.id}`"
                             style="flex-shrink:0;cursor:pointer;"
                             @click.stop="setPanelExam(pi - 1, exam); viewerActivePanel = pi - 1;">
                            <div class="position-relative rounded overflow-hidden"
                                 :style="`width:64px;height:64px;outline:${viewerPanelExams[pi - 1]?.id === exam.id ? '2px solid #0d6efd' : '1px solid #2a2a2a'};`">
                                <span class="position-absolute top-0 end-0 rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                                      :class="{
                                          'bg-primary': exam.laterality === 1,
                                          'bg-danger': exam.laterality === 2,
                                          'bg-secondary': exam.laterality !== 1 && exam.laterality !== 2,
                                      }"
                                      style="width:14px;height:14px;font-size:.4rem;z-index:1;margin:2px;">
                                    {{ latLabel(exam.laterality) }}
                                </span>
                                <img v-if="examUrls[exam.id] && !brokenUrls[exam.id]"
                                     :src="examUrls[exam.id]"
                                     style="width:64px;height:64px;object-fit:cover;display:block;"
                                     @error="brokenUrls = { ...brokenUrls, [exam.id]: true }">
                                <div v-else class="w-100 h-100 d-flex align-items-center justify-content-center"
                                     style="background:#1a1a1a;">
                                    <i class="ti ti-photo-off" style="color:#444;font-size:.9rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modo "All" -->
            <div v-show="viewerAllMode" id="viewImages" class="ei-scroll"
                 style="position:absolute;top:50px;left:0;right:0;bottom:0;overflow-y:auto;overflow-x:hidden;background:#0a0a0a;padding:4px;">
                <div :style="`display:grid;grid-template-columns:repeat(${viewerSplitMode ? 2 : viewerPanelCount},1fr);gap:4px;`">
                    <div v-for="exam in allGridExams()" :key="`all-grid-${exam.id}`"
                         class="position-relative"
                         style="background:#111;border-radius:3px;overflow:hidden;display:flex;flex-direction:column;">
                        <div class="d-flex align-items-center gap-2 px-2 py-1"
                             style="background:#0d0d0d;border-bottom:1px solid #1a1a1a;font-size:.65rem;">
                            <span class="badge flex-shrink-0"
                                  :class="{
                                      'bg-primary': exam.laterality === 1,
                                      'bg-danger': exam.laterality === 2,
                                      'bg-secondary': exam.laterality !== 1 && exam.laterality !== 2,
                                  }"
                                  style="font-size:.5rem;">
                                {{ latLabel(exam.laterality) }}
                            </span>
                            <span class="text-secondary text-truncate">{{ exam.exam_type?.name ?? '—' }}</span>
                            <span class="text-secondary opacity-50 flex-shrink-0 ms-auto">
                                {{ formatDateTime(exam.created_at) }}
                            </span>
                        </div>
                        <div class="position-relative"
                             :style="viewerLensActive && examUrls[exam.id] && !brokenUrls[exam.id] ? 'cursor:none;' : ''"
                             @mousemove.stop="onAllLensMove($event, exam)"
                             @mouseleave.stop="viewerLensVisible = false"
                             @mouseenter.stop="onAllEnter(exam)"
                             @wheel="onAllWheel($event)">
                            <img v-if="examUrls[exam.id] && !brokenUrls[exam.id]"
                                 :src="examUrls[exam.id]"
                                 :style="'width:100%;height:auto;display:block;user-select:none;' + (viewerLaserMode ? 'transform:scaleY(-1);' : '')"
                                 @error="brokenUrls = { ...brokenUrls, [exam.id]: true }">
                            <div v-else class="d-flex align-items-center justify-content-center"
                                 style="width:100%;aspect-ratio:4/3;background:#1a1a1a;">
                                <i class="ti ti-photo-off" style="color:#444;font-size:2rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lupa global -->
            <div v-show="viewerLensActive && viewerLensVisible" :style="viewerLensStyle"></div>
        </div>
        </Teleport>

        <!-- ── Modal de impressão ────────────────────────────────────────────── -->
        <Teleport to="body">
        <div v-show="showPrintModal"
             style="position:fixed;inset:0;z-index:9999;display:flex;flex-direction:column;">

            <div class="d-flex align-items-center gap-1 px-3 py-2 flex-shrink-0"
                 style="background:#2c2c2c;color:#fff;">

                <div class="btn-group btn-group-sm me-2" role="group">
                    <button v-for="n in [1, 2, 4, 6, 9, 12, 16]" :key="n" type="button" class="btn btn-sm"
                            :class="printCols === n ? 'btn-light' : 'btn-outline-secondary'"
                            style="font-size:.72rem;min-width:28px;"
                            @click="printCols = n">{{ n }}</button>
                </div>

                <div class="vr opacity-25 mx-1"></div>

                <button type="button" class="btn btn-sm"
                        :class="printOrientation === 'portrait' ? 'btn-light' : 'btn-outline-secondary'"
                        style="font-size:.72rem;"
                        @click="printOrientation = 'portrait'">
                    <i class="fa fa-file me-1"></i>Retrato
                </button>
                <button type="button" class="btn btn-sm"
                        :class="printOrientation === 'landscape' ? 'btn-light' : 'btn-outline-secondary'"
                        style="font-size:.72rem;"
                        @click="printOrientation = 'landscape'">
                    <i class="fa fa-file me-1" style="transform:rotate(90deg);display:inline-block;"></i>Paisagem
                </button>

                <div class="vr opacity-25 mx-1"></div>

                <button type="button" class="btn btn-sm btn-warning text-dark fw-semibold"
                        style="font-size:.72rem;" @click="printReport">
                    <i class="fa fa-print me-1"></i>Imprimir
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto"
                        style="font-size:.72rem;" @click="showPrintModal = false">
                    <i class="fa fa-times me-1"></i>Fechar
                </button>
            </div>

            <div class="flex-grow-1 overflow-auto" style="background:#888;">
                <div id="ei-print-content"
                     :class="printOrientation === 'landscape' ? 'ei-landscape' : 'ei-portrait'"
                     class="mx-auto my-3 bg-white shadow"
                     style="width:210mm;min-height:297mm;padding:12mm;box-sizing:border-box;">

                    <!-- Cabeçalho da clínica -->
                    <div class="d-flex justify-content-between align-items-start mb-3 pb-2"
                         style="border-bottom:2px solid #1a6fc4;">
                        <div>
                            <div style="font-size:1.1rem;font-weight:700;color:#1a6fc4;">{{ printEntity.name }}</div>
                            <div v-if="printEntity.address" style="font-size:.72rem;color:#555;">
                                {{ printEntity.address }}
                            </div>
                            <div v-if="printEntity.email" style="font-size:.72rem;color:#555;">
                                {{ printEntity.email }}
                            </div>
                            <div v-if="printEntity.telephone || printEntity.cellphone"
                                 style="font-size:.72rem;color:#555;">
                                {{ [printEntity.telephone, printEntity.cellphone].filter(Boolean).join(' | ') }}
                            </div>
                        </div>
                        <div class="text-end">
                            <div style="font-size:.72rem;color:#555;">Data do relatório</div>
                            <div style="font-size:.85rem;font-weight:600;">
                                {{ new Date().toLocaleDateString('pt-BR') }}
                            </div>
                        </div>
                    </div>

                    <!-- Dados do paciente -->
                    <div v-if="selectedPatient" class="mb-3 p-2 rounded"
                         style="background:#f0f4ff;font-size:.78rem;">
                        <strong>{{ selectedPatient.person?.full_name ?? selectedPatient.full_name }}</strong>
                        <span class="ms-2 text-muted">{{ selectedPatient.code }}</span>
                    </div>

                    <!-- Grade de imagens -->
                    <div :style="`display:grid;grid-template-columns:repeat(${printCols},1fr);gap:8px;`">
                        <div v-for="exam in printExams" :key="exam.id" style="break-inside:avoid;">
                            <div class="text-center mb-1"
                                 style="font-size:.65rem;color:#333;font-weight:600;">
                                {{ exam.exam_type?.name ?? 'Exame' }} - {{ latLabel(exam.laterality) }} - {{ formatDateTime(exam.created_at) }}
                            </div>
                            <img v-if="examUrls[exam.id] && !brokenUrls[exam.id]"
                                 :src="examUrls[exam.id]"
                                 style="width:100%;height:auto;display:block;border:1px solid #ddd;"
                                 @error="brokenUrls = { ...brokenUrls, [exam.id]: true }">
                            <div v-else class="d-flex align-items-center justify-content-center"
                                 style="width:100%;aspect-ratio:4/3;background:#eee;border:1px solid #ddd;">
                                <i class="ti ti-photo-off" style="font-size:2rem;color:#aaa;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </Teleport>

        <!-- ── Modal IA ────────────────────────────────────────────────────── -->
        <div v-if="aiModalOpen" class="modal d-block" tabindex="-1"
             style="background:rgba(0,0,0,.55);" @click.self="closeAiModal">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="ti ti-robot me-1 text-info"></i>
                            {{ aiLabel('title', 'Assistente de IA') }}
                        </h5>
                        <button type="button" class="btn-close" @click="closeAiModal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="d-flex flex-wrap gap-3 mb-3 small text-muted">
                            <span><strong>{{ aiBalance.available }}</strong> {{ aiLabel('credits_available', 'Créditos disponíveis') }}</span>
                            <span class="text-warning"><strong>{{ aiBalance.reserved }}</strong> {{ aiLabel('credits_reserved', 'Reservados') }}</span>
                        </div>

                        <div class="alert alert-info py-2 small">
                            <i class="ti ti-info-circle me-1"></i>{{ aiLabel('support_notice', 'A IA é apoio clínico. A decisão final é sempre do médico responsável.') }}
                        </div>

                        <div v-if="aiAlert.message" :class="`alert alert-${aiAlert.type}`" role="alert">
                            {{ aiAlert.message }}
                        </div>

                        <!-- Eye Image: imagens selecionadas que serão analisadas -->
                        <div v-if="isEyeImageWorkflow" class="mb-3">
                            <label class="form-label small">{{ aiLabel('eye_image_selected', 'Imagens selecionadas') }}</label>
                            <div class="form-control form-control-sm bg-light d-flex align-items-center justify-content-between">
                                <span><i class="ti ti-photo me-1"></i>{{ aiSelectedCount }} / {{ aiMaxImages }}</span>
                                <span v-if="!aiHasSelection" class="text-danger small">
                                    {{ aiLabel('eye_image_none', 'Selecione ao menos uma imagem para analisar.') }}
                                </span>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small">{{ aiLabel('workflow', 'Workflow') }}</label>
                                <SearchSelect v-model="aiForm.workflow" :options="aiWorkflowOptions"
                                              :value-key="'value'" :label-key="'label'"
                                              :clearable="false" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">{{ aiLabel('risk', 'Risco') }}</label>
                                <SearchSelect v-model="aiForm.risk_level"
                                              :options="[
                                                  {value:'low',label:aiLabel('risk_low', 'Baixo')},
                                                  {value:'medium',label:aiLabel('risk_medium', 'Médio')},
                                                  {value:'high',label:aiLabel('risk_high', 'Alto')},
                                              ]"
                                              :value-key="'value'" :label-key="'label'"
                                              :clearable="false" />
                            </div>
                        </div>

                        <div v-if="aiShowPatientSelector" class="mb-3">
                            <label class="form-label small">{{ aiLabel('patient_optional', 'Patient (optional)') }}</label>
                            <SearchSelect v-model="aiForm.patient_id" :options="aiPatientOptions"
                                          :value-key="'value'" :label-key="'label'"
                                          :placeholder="aiLabel('select_placeholder', 'Select')" />
                        </div>
                        <div v-else class="mb-3">
                            <label class="form-label small">{{ aiLabel('patient_optional', 'Patient (optional)') }}</label>
                            <div class="form-control form-control-sm bg-light d-flex align-items-center justify-content-between">
                                <span class="text-truncate">
                                    {{ aiSelectedPatient?.name }}<span v-if="aiSelectedPatient?.code"> ({{ aiSelectedPatient?.code }})</span>
                                </span>
                                <span class="badge bg-success-subtle text-success ms-2">Auto</span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">{{ aiLabel('clinical_prompt', 'Prompt clínico') }}</label>
                            <textarea v-model="aiForm.user_prompt" class="form-control" rows="5"
                                      minlength="12" maxlength="30000"
                                      :placeholder="aiLabel('clinical_prompt_placeholder', 'Descreva o contexto e objetivo clínico.')"></textarea>
                        </div>

                        <!-- System prompt: na análise de imagem é forçado no servidor. -->
                        <div v-if="!isEyeImageWorkflow" class="mb-3">
                            <label class="form-label small">{{ aiLabel('system_prompt', 'System prompt') }}</label>
                            <textarea v-model="aiForm.system_prompt" class="form-control form-control-sm" rows="2"></textarea>
                        </div>

                        <div v-if="aiEstimate" class="border rounded p-2 bg-light small">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>
                                    <strong>{{ aiLabel('estimated_credits', 'Créditos estimados') }}:</strong>
                                    {{ aiEstimate.normalized_credits ?? '—' }}
                                </span>
                                <span class="text-muted">{{ aiWorkflowLabel(aiEstimate.workflow) }}</span>
                            </div>
                        </div>

                        <!-- Resultado: processamento + laudo p/ revisão médica -->
                        <div v-if="aiRunStatus === 'processing'" class="text-center py-3 text-muted small">
                            <span class="spinner-border spinner-border-sm me-1"></span>{{ aiLabel('processing', 'Processando análise...') }}
                        </div>
                        <div v-else-if="aiRunStatus === 'waiting_approval'" class="mt-3">
                            <label class="form-label fw-semibold">
                                <i class="ti ti-file-text me-1 text-info"></i>{{ aiLabel('eye_image_report', 'Laudo da IA') }}
                            </label>
                            <textarea v-model="aiRunOutput" class="form-control" rows="8"></textarea>
                            <div class="d-flex justify-content-end gap-2 mt-2">
                                <button type="button" class="btn btn-outline-danger btn-sm" :disabled="aiActioning" @click="actAiRun('reject')">
                                    <i class="ti ti-x me-1"></i>{{ aiLabel('reject', 'Rejeitar') }}
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" :disabled="aiActioning" @click="actAiRun('approve')">
                                    <span v-if="aiActioning" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="ti ti-check me-1"></i>{{ aiLabel('approve', 'Aprovar') }}
                                </button>
                            </div>
                        </div>
                        <!-- Laudo já aprovado (somente leitura) -->
                        <div v-else-if="aiRunStatus === 'approved'" class="mt-3">
                            <label class="form-label fw-semibold">
                                <i class="ti ti-robot me-1 text-info"></i>{{ aiLabel('eye_image_report', 'Laudo da IA') }}
                                <span class="badge bg-info text-dark ms-1">{{ aiLabel('eye_image_reported', 'Laudado (IA)') }}</span>
                            </label>
                            <div class="border rounded p-2 bg-light" style="white-space:pre-wrap;">{{ aiRunOutput }}</div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" @click="closeAiModal">
                            {{ aiLabel('close', 'Close') }}
                        </button>
                        <template v-if="!aiRunStatus">
                            <small v-if="aiRunDisabledReason" class="w-100 text-muted d-flex align-items-center gap-1 mb-2">
                                <i class="ti ti-info-circle"></i>{{ aiRunDisabledReason }}
                            </small>
                            <button type="button" class="btn btn-outline-info btn-sm"
                                    :disabled="aiEstimating || (isEyeImageWorkflow && !aiHasSelection)" @click="estimateAiRun">
                                <span v-if="aiEstimating" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ti ti-calculator me-1"></i>{{ aiLabel('estimate', 'Estimar Custo') }}
                            </button>
                            <button type="button" class="btn btn-success btn-sm"
                                    :disabled="aiSubmitting || (isEyeImageWorkflow && !aiHasSelection)"
                                    :title="aiRunDisabledReason" @click="submitAiRun">
                                <span v-if="aiSubmitting" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ti ti-player-play me-1"></i>{{ aiLabel('run', 'Executar IA') }}
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Painel de IA compartilhado (análise de imagem + visualização de laudo) -->
        <AiAssistantPanel
            v-if="aiEnabled"
            :open="aiPanelOpen"
            :ai="ai"
            :context="aiPanelContext"
            :view-report="aiViewReport"
            @close="aiPanelOpen = false"
            @approved="onAiApproved"
            @needs-record="onAiNeedsRecord"
        />
    </AppLayout>
</template>

<style scoped>
.patient-item {
    cursor: pointer;
    transition: background .12s;
}
.patient-item:hover { background: #f4f6fb; }
.patient-item-active { background: #e8f0fe !important; }

@media print {
    :deep(body *) { visibility: hidden !important; }
    :deep(#ei-print-content),
    :deep(#ei-print-content *) { visibility: visible !important; }
    :deep(#ei-print-content) {
        position: fixed !important; left: 0 !important; top: 0 !important;
        width: 100% !important; margin: 0 !important; box-shadow: none !important;
    }
}
.ei-landscape { width: 297mm; min-height: 210mm; }

:deep(#viewImages::-webkit-scrollbar),
:deep(.ei-scroll::-webkit-scrollbar) {
    width: 10px; height: 10px; background-color: #222;
}
:deep(#viewImages::-webkit-scrollbar-track),
:deep(.ei-scroll::-webkit-scrollbar-track) { background-color: #222; }
:deep(#viewImages::-webkit-scrollbar-thumb),
:deep(.ei-scroll::-webkit-scrollbar-thumb) {
    background-color: #555; border-radius: 5px;
}
:deep(#viewImages::-webkit-scrollbar-thumb:hover),
:deep(.ei-scroll::-webkit-scrollbar-thumb:hover) { background-color: #777; }
:deep(#viewImages),
:deep(.ei-scroll) { scrollbar-width: thin; scrollbar-color: #555 #222; }
</style>
