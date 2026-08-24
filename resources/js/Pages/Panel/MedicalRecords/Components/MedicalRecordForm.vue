<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import { validatePayload } from '@/utils/formRulesValidator.js';
import PdfPreviewModal from './PdfPreviewModal.vue';
import TinyMceEditor   from '@/Components/Panel/TinyMceEditor.vue';
import SearchSelect    from '@/Components/Panel/SearchSelect.vue';
import AcuitySelect    from '@/Pages/Panel/MedicalRecords/Components/AcuitySelect.vue';
import MedicalRecordFileUploadModal from './MedicalRecordFileUploadModal.vue';
import AiAssistantPanel from '@/Components/Panel/AiAssistantPanel.vue';
import { setAiContext, clearAiContext } from '@/Support/aiAssistantContext';

/**
 * MedicalRecordForm — Port fiel de _form.blade.php (1744 LOC) +
 * medicalRecordForm.js Alpine (1489 LOC) para Vue 3 + Inertia.
 *
 * Compliance:
 *   - CFM Res. 2.227/2018: registro bloqueado após assinatura (isLocked)
 *   - LGPD Art. 37: trilha de acesso registrada pelo controller via LogsDataAccess
 *   - Versionable: backend grava snapshot antes de update
 *   - Auditable: backend grava audit_logs para toda CUD
 */
const props = defineProps({
    patient:         { type: Object,  required: true },
    medicalrecord:   { type: Object,  default: null },
    doctors:         { type: Array,   default: () => [] },
    currentDoctorId: { type: String,  default: null },
    canChooseDoctor: { type: Boolean, default: false },
    isDoctor:        { type: Boolean, default: false },
    isEdit:          { type: Boolean, default: false },
    catalogs:        { type: Object,  required: true },
    urls:            { type: Object,  required: true },
    storage:         { type: Object,  default: () => ({
        used_bytes: 0, limit_bytes: 0, limit_gb: 0, is_unlimited: false,
        percent: 0, remaining_bytes: null,
        max_file_size_bytes: 10485760, max_files_per_batch: 10,
        accept: '.jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx',
        accept_mimes: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx'],
    }) },
    ai:              { type: Object,  default: () => ({ enabled: false }) },
    t:               { type: Object,  default: () => ({}) },
});

const r = props.medicalrecord;
const isLocked = computed(() => Boolean(r?.is_locked));

// Atalhos para catálogos
const visualAcuityTypes = computed(() => props.catalogs.visual_acuity_types ?? []);
const colorVisionTypes  = computed(() => props.catalogs.color_vision_types ?? []);
const coverTestTypes    = computed(() => props.catalogs.cover_test_types ?? []);
const nearPointTypes    = computed(() => props.catalogs.near_point_types ?? []);
const additionTypes     = computed(() => props.catalogs.addition_types ?? []);
const lenses            = computed(() => props.catalogs.lenses ?? []);
const examReports       = computed(() => props.catalogs.exam_reports ?? []);
const documentationTemplates = computed(() => normalizeDocTemplates(props.catalogs.available_templates ?? []));

// ──────────────────────────────────────────────────────────────────────────
// Form principal (Inertia useForm)
// ──────────────────────────────────────────────────────────────────────────
const form = useForm({
    doctor_id: r?.doctor_id ?? props.currentDoctorId ?? '',

    // Anamnese
    main_complaint:           r?.main_complaint ?? '',
    hda:                      r?.hda ?? '',
    diabetic:                 r?.diabetic ?? false,
    diabetic_family:          r?.diabetic_family ?? false,
    hypertensive:             r?.hypertensive ?? false,
    hypertensive_family:      r?.hypertensive_family ?? false,
    glaucomatous:             r?.glaucomatous ?? false,
    glaucomatous_family:      r?.glaucomatous_family ?? false,
    others_history:           r?.others_history ?? '',
    ocular_surgical_history:  r?.ocular_surgical_history ?? '',
    medications_in_use:       r?.medications_in_use ?? '',

    // Selects clínicos
    visual_acuity_type_id:                     r?.visual_acuity_type_id ?? '',
    visual_acuity_without_correction_right_id: r?.visual_acuity_without_correction_right_id ?? '',
    visual_acuity_without_correction_left_id:  r?.visual_acuity_without_correction_left_id ?? '',
    visual_acuity_with_correction_right_id:    r?.visual_acuity_with_correction_right_id ?? '',
    visual_acuity_with_correction_left_id:     r?.visual_acuity_with_correction_left_id ?? '',
    near_point_convergence_id: r?.near_point_convergence_id ?? '',
    cover_test_type_id:        r?.cover_test_type_id ?? '',
    color_vision_type_id:      r?.color_vision_type_id ?? '',
    addition_type_id:          r?.addition_type_id ?? '',
    lens_away_id:              r?.lens_away_id ?? '',
    lens_near_id:              r?.lens_near_id ?? '',

    // Refração dinâmica
    dynamic_spherical_right:   r?.dynamic_spherical_right ?? '0.00',
    dynamic_spherical_left:    r?.dynamic_spherical_left ?? '0.00',
    dynamic_cylindrical_right: r?.dynamic_cylindrical_right ?? '0.00',
    dynamic_cylindrical_left:  r?.dynamic_cylindrical_left ?? '0.00',
    dynamic_axis_right:        r?.dynamic_axis_right ?? '',
    dynamic_axis_left:         r?.dynamic_axis_left ?? '',

    // Refração estática
    static_spherical_right:    r?.static_spherical_right ?? '0.00',
    static_spherical_left:     r?.static_spherical_left ?? '0.00',
    static_cylindrical_right:  r?.static_cylindrical_right ?? '0.00',
    static_cylindrical_left:   r?.static_cylindrical_left ?? '0.00',
    static_axis_right:         r?.static_axis_right ?? '',
    static_axis_left:          r?.static_axis_left ?? '',

    // Exame físico
    ocular_motility:   r?.ocular_motility ?? '',
    tonometer_right:   r?.tonometer_right ?? '',
    tonometer_left:    r?.tonometer_left ?? '',
    tonometer_time:    r?.tonometer_time ?? '',
    pachymetry_right:  r?.pachymetry_right ?? '',
    pachymetry_left:   r?.pachymetry_left ?? '',
    gonioscopy_right:  r?.gonioscopy_right ?? '',
    gonioscopy_left:   r?.gonioscopy_left ?? '',

    // Achados
    biomicroscopy_right:   r?.biomicroscopy_right ?? props.t?.biomicroscopy_ph ?? '',
    biomicroscopy_left:    r?.biomicroscopy_left ?? props.t?.biomicroscopy_ph ?? '',
    fundoscopy_right:      r?.fundoscopy_right ?? props.t?.fundoscopy_ph ?? '',
    fundoscopy_left:       r?.fundoscopy_left ?? props.t?.fundoscopy_ph ?? '',
    observation_general:   r?.observation_general ?? '',
    observation_of_lenses: r?.observation_of_lenses ?? '',

    // Diagnóstico & conduta
    diagnosis_cids:    r?.diagnosis_cids ?? [],
    clinical_conduct:  r?.clinical_conduct ?? '',
    follow_up_days:    r?.follow_up_days ?? '',

    // Vínculo opcional com agenda
    schedule_id: '',
});

// ──────────────────────────────────────────────────────────────────────────
// UI state (não-form)
// ──────────────────────────────────────────────────────────────────────────

// F9 — Validação
const validationRules  = ref({});
const clientErrors     = ref({});
const hasClientErrors  = ref(false);

// Toggle "outros antecedentes"
const showOthersHistory = ref(Boolean(r?.others_history));
const othersHistoryInput = ref(null);

// Tonometria
const tonometryPdfSrc      = ref('');
const tonometryStampedTime = ref(
    r?.tonometer_time
        ? r.tonometer_time.slice(0, 5)
        : new Date().toTimeString().slice(0, 5)
);

// Presbiopia
const presbyopiaAddition  = ref(0);
const presbyopiaObsForm   = reactive({ content: '' });

// ──────────────────────────────────────────────────────────────────────────
// Prontuário personalizado por médico (3 modos: padrão EasyEye / meu modelo /
// texto livre). Preferência persiste no bag UserPreference (chave
// medical_record_layout) via PATCH panel.preferences.update — por USUÁRIO,
// não por clínica: o modelo de atendimento é estilo pessoal do médico.
//
// IMPORTANTE (integridade): personalização só muda EXIBIÇÃO. Seções ocultas
// usam display:none (nunca v-if) — o estado do useForm continua inteiro e o
// submit envia TODOS os campos, então dados já preenchidos num prontuário
// antigo nunca são perdidos por causa do layout do médico atual.
// ──────────────────────────────────────────────────────────────────────────
const SECTION_DEFS = [
    { key: 'cromatica_ppc_cover', col: 'left',  label: 'Visão cromática / PPC / Cover test' },
    { key: 'av_sem_tono',         col: 'left',  label: 'A/V sem correção + Tonometria' },
    { key: 'dinamica',            col: 'left',  label: 'Refração dinâmica' },
    { key: 'estatica',            col: 'left',  label: 'Refração estática' },
    { key: 'adicao',              col: 'right', label: 'Adição / Longe / Perto' },
    { key: 'av_com',              col: 'right', label: 'A/V com correção' },
    { key: 'biomicroscopia',      col: 'right', label: 'Biomicroscopia' },
    { key: 'fundoscopia',         col: 'right', label: 'Fundoscopia' },
    { key: 'obs_geral',           col: 'right', label: 'Observação geral' },
];

// Snapshot local da preferência (Inertia shared props não re-hidratam após
// PATCH via axios — mantemos o espelho atualizado aqui).
const persistedLayout = ref(usePage().props.auth?.user?.preferences?.medical_record_layout ?? null);

const recordMode = ref(persistedLayout.value?.default_mode ?? 'default'); // default | custom | free
const isCustomMode = computed(() => recordMode.value === 'custom');
const isFreeMode   = computed(() => recordMode.value === 'free');

function seedLayout() {
    const saved = persistedLayout.value?.custom ?? null;
    const buildColumn = (col) => {
        const defaults   = SECTION_DEFS.filter(s => s.col === col).map(s => s.key);
        const savedOrder = (saved?.[col] ?? []).filter(k => defaults.includes(k));
        // Seções novas (adicionadas depois do médico salvar o modelo) entram
        // no fim da coluna — nunca somem silenciosamente.
        return [...savedOrder, ...defaults.filter(k => !savedOrder.includes(k))];
    };
    return {
        left:   buildColumn('left'),
        right:  buildColumn('right'),
        hidden: (saved?.hidden ?? []).filter(k => SECTION_DEFS.some(s => s.key === k)),
    };
}
const sectionLayout = reactive(seedLayout());

function sectionStyle(key) {
    if (isFreeMode.value) return {};             // colunas inteiras já somem no modo livre
    if (!isCustomMode.value) return {};          // modo padrão EasyEye: layout intacto
    const def   = SECTION_DEFS.find(s => s.key === key);
    const order = sectionLayout[def.col].indexOf(key);
    const style = { order: order >= 0 ? order : 99 };
    if (sectionLayout.hidden.includes(key)) style.display = 'none';
    return style;
}

function moveSection(col, key, dir) {
    const arr = sectionLayout[col];
    const i   = arr.indexOf(key);
    const j   = i + dir;
    if (i < 0 || j < 0 || j >= arr.length) return;
    [arr[i], arr[j]] = [arr[j], arr[i]];
}

function toggleSection(key) {
    const i = sectionLayout.hidden.indexOf(key);
    if (i >= 0) sectionLayout.hidden.splice(i, 1);
    else sectionLayout.hidden.push(key);
}

function sectionLabel(key) {
    return SECTION_DEFS.find(s => s.key === key)?.label ?? key;
}

const showLayoutModal = ref(false);
const layoutSaving    = ref(false);
const layoutSavedFlash = ref('');

async function persistPreference(patch) {
    layoutSaving.value = true;
    try {
        const payload = {
            default_mode: patch.default_mode ?? persistedLayout.value?.default_mode ?? 'default',
        };
        const custom = patch.custom ?? persistedLayout.value?.custom ?? null;
        if (custom) payload.custom = custom;

        const { data } = await window.axios.patch(route('panel.preferences.update'), {
            medical_record_layout: payload,
        });
        persistedLayout.value = data.data?.medical_record_layout ?? payload;
        return true;
    } catch (e) {
        console.error('Failed to persist medical record layout:', e);
        return false;
    } finally {
        layoutSaving.value = false;
    }
}

async function saveMyLayout() {
    const ok = await persistPreference({
        custom: {
            left:   [...sectionLayout.left],
            right:  [...sectionLayout.right],
            hidden: [...sectionLayout.hidden],
        },
    });
    if (ok) {
        layoutSavedFlash.value = tt('layout_saved', 'Modelo salvo!');
        setTimeout(() => { layoutSavedFlash.value = ''; }, 2500);
    }
}

const isCurrentModeDefault = computed(() =>
    (persistedLayout.value?.default_mode ?? 'default') === recordMode.value);

async function saveDefaultMode() {
    const ok = await persistPreference({ default_mode: recordMode.value });
    if (ok) {
        layoutSavedFlash.value = tt('default_mode_saved', 'Definido como seu padrão!');
        setTimeout(() => { layoutSavedFlash.value = ''; }, 2500);
    }
}

// Evoluções clínicas (texto livre, append-only) — histórico por PACIENTE,
// atravessa prontuários. Carregado sob demanda ao abrir o modal.
const showEvolutionModal = ref(false);
const evolutions         = ref([]);
const evolutionsLoaded   = ref(false);
const evolutionText      = ref('');
const evolutionBusy      = ref(false);

async function openEvolutionModal() {
    showEvolutionModal.value = true;
    if (evolutionsLoaded.value || !props.urls.evolutions_index) return;
    try {
        const res = await fetch(props.urls.evolutions_index, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        if (res.ok) {
            evolutions.value = (await res.json()).data ?? [];
            evolutionsLoaded.value = true;
        }
    } catch (e) {
        console.error('Failed to load evolutions:', e);
    }
}

async function saveEvolution() {
    const content = evolutionText.value.trim();
    if (!content || evolutionBusy.value || !props.urls.evolutions_store) return;
    evolutionBusy.value = true;
    try {
        const res = await fetch(props.urls.evolutions_store, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            body: JSON.stringify({ content }),
        });
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            alert(err.message ?? tt('evolution_save_error', 'Não foi possível registrar a evolução.'));
            return;
        }
        evolutions.value.unshift(await res.json());
        evolutionText.value = '';
    } catch (e) {
        console.error('Evolution save error:', e);
    } finally {
        evolutionBusy.value = false;
    }
}

// Documentações
const documentations = ref(r?.documentations ?? []);
// Mantém a lista sincronizada após reload parcial (ex.: novo laudo de IA aprovado).
watch(() => props.medicalrecord?.documentations, (v) => { if (Array.isArray(v)) documentations.value = v; });

// ── Assistente de IA ────────────────────────────────────────────────────────
const aiEnabled  = computed(() => Boolean(props.ai?.enabled) && props.isEdit);
const aiPanelOpen = ref(false);
const aiContext = computed(() => ({
    workflow_default:  props.ai?.default_workflow ?? 'record_assist',
    patient_id:        props.patient?.id ?? null,
    medical_record_id: r?.id ?? null,
    can_insert:        !isLocked.value,
}));

function openAiPanel() { aiPanelOpen.value = true; }

// Insere uma sugestão da IA num campo do prontuário (texto), com confirmação
// quando o campo já tem conteúdo. Nunca substitui sem o médico decidir.
function applyAiSuggestion({ field, value }) {
    if (isLocked.value || !value) return;
    const target = field === 'observations' ? 'observation_general' : 'clinical_conduct';
    const current = (form[target] || '').trim();
    if (current && !window.confirm(tt('ai_insert_confirm', 'O campo já tem conteúdo. Anexar a sugestão da IA ao final?'))) {
        return;
    }
    form[target] = current ? `${current}\n${value}` : value;
}

// Após aprovar um laudo de IA, recarrega o prontuário para puxar a nova documentação.
function onAiApproved() {
    router.reload({ only: ['medicalrecord'], preserveScroll: true });
}
const docForm = reactive({
    report_setting_content_id: '',
    title:        '',
    content:      '',
    exam_type:    '',
    exam_subtype: '',
    exam_label:   '',
});
const docTemplates    = ref(documentationTemplates.value);
const docSaving       = ref(false);
const quickActionBusy = ref(false);

// F5 — Medicamentos
const prescription      = ref([]);
const medicineLists     = ref('');
const medSearchQuery    = ref('');
const medSearchResults  = ref([]);
const medSearchOpen     = ref(false);
const medSearchLoading  = ref(false);
const maxMedicines      = 5;
// Busca inteligente + posologia: abas Recentes | Favoritos | Buscar, seleção
// com sugestão de posologia editável (minha posologia > genérica da base) e
// presets por médico. Ver MedicationPresetsController.
const medTab            = ref('search');   // search | recents | favorites
const medPresets        = ref({ recents: [], favorites: [] });
const selectedMed       = ref(null);
const posologyDraft     = ref('');
const posologySaving    = ref(false);
const posologySavedFlash = ref(false);

// F6 — Procedimentos + Indicações
const procSelected     = ref([]);
const indSelected      = ref([]);
const procedureLists   = ref('');
const procSearchQuery  = ref('');
const procSearchResults = ref([]);
const procSearchOpen   = ref(false);
const procSearchLoading = ref(false);
const procTypeSelected = ref('');
const indSearchQuery   = ref('');
const indSearchResults = ref([]);
const indSearchOpen    = ref(false);
const indSearchLoading = ref(false);
const maxProcSolicitations = 10;

// F7 — Atestados
const attendanceForm = reactive({ content: '' });
const medicalForm    = reactive({ days: 1, date: '', content: '', daysPreview: '' });

// F8 — Catarata
const cataractForm = reactive({
    eye:          'right',
    template:     'pre_operatorio',
    date_surgery: '',
    hour_surgery: '',
});

// Anexos — gerenciados pelo MedicalRecordFileUploadModal
const uploadedFiles    = ref(r?.files ?? []);
const showUploadModal  = ref(false);
const storageState     = ref({ ...props.storage });

// PDF preview
const pdfPreviewUrl   = ref('');
const pdfPreviewTitle = ref('');
const showPdfPreview  = ref(false);

// Modais Bootstrap genéricos (controlados por ref booleano)
const showDocumentationsModal = ref(false);
const showDocModal            = ref(false);
const showMedicationModal     = ref(false);
const showProcedureModal      = ref(false);
const showCataractModal       = ref(false);
const showAttendanceCertModal = ref(false);
const showMedicalCertModal    = ref(false);
const showExamHubModal        = ref(false);
const showTonometryModal      = ref(false);
const showPresbyopiaObsModal  = ref(false);

// CID-10 search state
const cidQuery       = ref('');
const cidResults     = ref([]);
const cidOpen        = ref(false);
const cidSearching   = ref(false);
const cidActiveIndex = ref(-1);
const selectedCids   = ref(Array.isArray(r?.diagnosis_cids) ? [...r.diagnosis_cids] : []);


// ──────────────────────────────────────────────────────────────────────────
// Lifecycle
// ──────────────────────────────────────────────────────────────────────────
onMounted(async () => {
    await fetchValidationRules();

    // schedule_id via querystring
    const params = new URLSearchParams(window.location.search);
    const sid = params.get('schedule_id');
    if (sid) form.schedule_id = sid;

    // Disponibiliza este prontuário como contexto OPCIONAL do Assistente
    // Virtual flutuante (AiFloatingAssistant, montado no AppLayout) — ainda
    // exige o médico ativar o toggle "Usar contexto desta tela" no widget
    // pra qualquer dado ser enviado. Exemplo do pedido de produto: "dentro
    // do prontuário: monte uma evolução com essas informações".
    if (r?.id) {
        setAiContext({
            patient_id: props.patient.id,
            medical_record_id: r.id,
            label: `Prontuário — ${props.patient.full_name ?? props.patient.code ?? ''}`,
        });
    }
});

onBeforeUnmount(clearAiContext);

watch(tonometryStampedTime, (v) => { form.tonometer_time = v; });

watch(showOthersHistory, async (v) => {
    if (v) {
        await nextTick();
        othersHistoryInput.value?.focus();
    }
});

watch(selectedCids, (v) => { form.diagnosis_cids = v; }, { deep: true });

// ──────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────
const i18n = computed(() => props.t ?? {});

function tt(key, fallback = '') {
    return i18n.value?.[key] ?? fallback;
}

function normalizeDocTemplates(payload) {
    if (Array.isArray(payload)) return payload;
    if (!payload || typeof payload !== 'object') return [];
    return Object.entries(payload).map(([id, group]) => ({
        report_setting_id:    group.report_setting_id ?? id,
        report_setting_title: group.report_setting_title ?? group.title ?? '',
        contents:             Array.isArray(group.contents) ? group.contents : [],
    }));
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

// ──────────────────────────────────────────────────────────────────────────
// F9 — Validação
// ──────────────────────────────────────────────────────────────────────────
async function fetchValidationRules() {
    if (!props.urls.validation_rules) return;
    try {
        const res = await fetch(props.urls.validation_rules, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        if (!res.ok) return;
        const data = await res.json();
        validationRules.value = data?.rules ?? {};
    } catch (e) {
        console.warn('[F9] failed to load validation rules:', e);
    }
}

// ──────────────────────────────────────────────────────────────────────────
// Submit (com validação F9 client-side antes)
// ──────────────────────────────────────────────────────────────────────────

/** Eixo refrativo é exibido como "0º" — precisa limpar antes de validar como número. */
const AXIS_FIELDS = [
    'dynamic_axis_right', 'dynamic_axis_left',
    'static_axis_right',  'static_axis_left',
];

/**
 * F9 — Validação client-side antes do submit (paridade com Alpine
 * `validateBeforeSubmit`). Espelha `prepareForValidation` do FormRequest:
 * limpa sufixos de unidade nos eixos refrativos. Em caso de falha, mostra
 * `hasClientErrors` no topo e cancela o submit.
 *
 * @returns {boolean} true se passou (ou se regras ainda não chegaram)
 */
function validateBeforeSubmit() {
    if (!validationRules.value || Object.keys(validationRules.value).length === 0) {
        return true; // rules ainda não chegaram → confia no servidor
    }

    // Monta payload a partir do form (useForm já tem todos os campos)
    const payload = { ...form.data() };
    for (const field of AXIS_FIELDS) {
        if (typeof payload[field] === 'string') {
            payload[field] = payload[field].replace(/[^\d-]/g, '');
        }
    }

    const i = i18n.value;
    const labels = {
        doctor_id:        i.field_doctor        ?? 'Médico',
        main_complaint:   i.field_complaint     ?? 'Queixa principal',
        pachymetry_right: i.field_pachymetry_od ?? 'Paquimetria OD',
        pachymetry_left:  i.field_pachymetry_oe ?? 'Paquimetria OE',
        follow_up_days:   i.field_follow_up     ?? 'Dias de retorno',
    };

    const result = validatePayload(payload, validationRules.value, labels);

    if (!result.valid) {
        clientErrors.value    = result.errors;
        hasClientErrors.value = true;
        nextTick(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
        return false;
    }

    clientErrors.value    = {};
    hasClientErrors.value = false;
    return true;
}

function submit() {
    if (isLocked.value) return;
    if (!validateBeforeSubmit()) return;

    const url = props.isEdit ? props.urls.update : props.urls.store;
    const method = props.isEdit ? 'put' : 'post';
    form[method](url, {
        preserveScroll: true,
        onError: () => window.scrollTo({ top: 0, behavior: 'smooth' }),
    });
}

// ──────────────────────────────────────────────────────────────────────────
// Lens auto-format
// ──────────────────────────────────────────────────────────────────────────
async function formatLens(kind, field) {
    if (!props.urls.lens_format) return;
    const value = form[field];
    if (value === '' || value == null) return;
    try {
        const res = await fetch(props.urls.lens_format, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                Accept: 'application/json',
            },
            body: JSON.stringify({ kind, value: String(value) }),
        });
        if (!res.ok) return;
        const data = await res.json();
        if (typeof data.value === 'string') form[field] = data.value;
    } catch (e) {
        console.error('Lens format error:', e);
    }
}

const LENS_ORDER = [
    'dynamic_spherical_right', 'dynamic_cylindrical_right', 'dynamic_axis_right',
    'dynamic_spherical_left',  'dynamic_cylindrical_left',  'dynamic_axis_left',
    'static_spherical_right',  'static_cylindrical_right',  'static_axis_right',
    'static_spherical_left',   'static_cylindrical_left',   'static_axis_left',
];

function focusNextLensField(currentName) {
    const idx = LENS_ORDER.indexOf(currentName);
    if (idx === -1 || idx + 1 >= LENS_ORDER.length) return;
    const next = document.querySelector(`[name="${LENS_ORDER[idx + 1]}"]`);
    if (next) {
        next.focus();
        if (typeof next.select === 'function') next.select();
    }
}

// ──────────────────────────────────────────────────────────────────────────
// Tonometria
// ──────────────────────────────────────────────────────────────────────────
function stampTonometryTime(force = false) {
    if (!force && (!form.tonometer_right || !form.tonometer_left)) return;
    if (!tonometryStampedTime.value) {
        tonometryStampedTime.value = new Date().toTimeString().slice(0, 5);
    }
    form.tonometer_time = tonometryStampedTime.value;
}

async function printTonometry() {
    const doctorIdVal = form.doctor_id || '';
    if (!doctorIdVal) {
        alert(tt('doctor_required_for_print', 'Selecione o médico responsável antes de imprimir.'));
        return;
    }
    if (!tonometryStampedTime.value) stampTonometryTime(true);
    const time = (tonometryStampedTime.value || new Date().toTimeString().slice(0, 8)).slice(0, 5);

    if (props.urls.store_tonometry) {
        try {
            const res = await fetch(props.urls.store_tonometry, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
                body: JSON.stringify({ od: form.tonometer_right, oe: form.tonometer_left, time, doctor_id: doctorIdVal }),
            });
            if (res.ok) {
                const doc = await res.json();
                documentations.value.unshift(doc);
                tonometryPdfSrc.value = doc.pdf_url;
                showTonometryModal.value = true;
                return;
            }
        } catch (e) {
            console.error('Tonometry save error:', e);
        }
    }
    const params = new URLSearchParams({ time, od: form.tonometer_right ?? '', oe: form.tonometer_left ?? '', doctor_id: doctorIdVal });
    tonometryPdfSrc.value = `${props.urls.tonometry_pdf}?${params.toString()}`;
    showTonometryModal.value = true;
}

function closeTonometry() {
    showTonometryModal.value = false;
    tonometryPdfSrc.value = '';
}

// ──────────────────────────────────────────────────────────────────────────
// Presbiopia
// ──────────────────────────────────────────────────────────────────────────
function openPresbyopiaCalc() {
    presbyopiaObsForm.content = form.observation_of_lenses ?? '';
    showPresbyopiaObsModal.value = true;
}

async function confirmPresbyopiaCalc() {
    form.observation_of_lenses = presbyopiaObsForm.content;
    showPresbyopiaObsModal.value = false;
    await calcPresbyopia();
}

async function calcPresbyopia() {
    if (!props.urls.calc_presbyopia) return;
    try {
        const res = await fetch(props.urls.calc_presbyopia, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            body: JSON.stringify({
                dynamic_spherical_right: form.dynamic_spherical_right,
                dynamic_spherical_left:  form.dynamic_spherical_left,
                addition:                presbyopiaAddition.value,
            }),
        });
        if (!res.ok) return;
        const data = await res.json();
        form.static_spherical_right = String(data.static_spherical_right ?? data.right ?? 0);
        form.static_spherical_left  = String(data.static_spherical_left  ?? data.left  ?? 0);
    } catch (e) {
        console.error('Presbyopia calc error:', e);
    }
}

// ──────────────────────────────────────────────────────────────────────────
// PDF preview
// ──────────────────────────────────────────────────────────────────────────
function openPdfPreview(url, title = '') {
    if (!url) return;
    pdfPreviewUrl.value = url;
    pdfPreviewTitle.value = title;
    showPdfPreview.value = true;
}

function closePdfPreview() {
    showPdfPreview.value = false;
    pdfPreviewUrl.value = '';
    pdfPreviewTitle.value = '';
}

// ──────────────────────────────────────────────────────────────────────────
// Quick Action
// ──────────────────────────────────────────────────────────────────────────
function buildQuickActionUrl(action) {
    return props.urls.quick_action_template?.replace('__ACTION__', action) ?? '';
}

async function issueQuickAction(action, payload = {}, { openPdf = true, preview = false } = {}) {
    const url = buildQuickActionUrl(action);
    if (!url || quickActionBusy.value) return;
    const doctorIdVal = form.doctor_id || '';
    if (!doctorIdVal) {
        alert(tt('doctor_required_for_issue', 'Selecione o médico responsável antes de emitir.'));
        return;
    }
    quickActionBusy.value = true;
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            body: JSON.stringify(payload),
        });
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            alert(err.message ?? 'Não foi possível emitir o documento.');
            return;
        }
        const doc = await res.json();
        documentations.value.unshift(doc);
        if (preview && doc.pdf_url) openPdfPreview(doc.pdf_url, doc.title || '');
        else if (openPdf && doc.pdf_url) window.open(doc.pdf_url, '_blank', 'noopener');
    } catch (e) {
        console.error('Quick action error:', e);
    } finally {
        quickActionBusy.value = false;
    }
}

function issueLensPrescription(mode) {
    if (!['dynamic', 'static', 'presbyopia_dynamic', 'presbyopia'].includes(mode)) return;
    return issueQuickAction('lens-prescription', { mode }, { preview: true });
}

// ──────────────────────────────────────────────────────────────────────────
// F4 — Documentações / Exam Hub
// ──────────────────────────────────────────────────────────────────────────
function openDocumentationsModal() {
    showDocumentationsModal.value = true;
}

async function openNewDoc() {
    resetDocForm();
    if (props.urls.templates) {
        try {
            const res = await fetch(props.urls.templates, {
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            });
            if (res.ok) docTemplates.value = normalizeDocTemplates(await res.json());
        } catch (e) {
            console.error('Failed to load templates:', e);
        }
    }
    showDocModal.value = true;
}

/**
 * Abre o modal de Documentação já pré-selecionando o primeiro template do
 * tipo informado (paridade com Alpine `openNewDocByType`).
 *
 * Útil para atalhos clínicos: chamar `openNewDocByType('receituario')`
 * carrega templates, seleciona o primeiro do tipo e pré-popula o conteúdo.
 */
async function openNewDocByType(type) {
    await openNewDoc();
    for (const group of docTemplates.value) {
        const tpl = (group.contents || []).find((c) => c.type === type);
        if (tpl) {
            docForm.report_setting_content_id = tpl.id;
            await previewTemplate();
            break;
        }
    }
}

/**
 * Declaração médica em branco — placeholder {{CONTEUDO_LIVRE}} resolvido
 * como vazio pelo service. Médico preenche manuscrito ou edita via PDF.
 * Paridade com Alpine `issueMedicalDeclaration`.
 */
function issueMedicalDeclaration() {
    return issueQuickAction('medical-declaration', { content: '' });
}

function resetDocForm() {
    docForm.report_setting_content_id = '';
    docForm.title        = '';
    docForm.content      = '';
    docForm.exam_type    = '';
    docForm.exam_subtype = '';
    docForm.exam_label   = '';
}

async function previewTemplate() {
    if (!docForm.report_setting_content_id || !props.urls.template_preview) return;
    try {
        const res = await fetch(props.urls.template_preview, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            body: JSON.stringify({ report_setting_content_id: docForm.report_setting_content_id }),
        });
        if (res.ok) {
            const data = await res.json();
            docForm.content = data.content ?? '';
            const tpl = docTemplates.value
                .flatMap((g) => g.contents || [])
                .find((c) => c.id === docForm.report_setting_content_id);
            if (tpl && !docForm.title) docForm.title = tpl.label;
        }
    } catch (e) {
        console.error('Template preview error:', e);
    }
}

async function saveDoc() {
    if (docSaving.value) return;
    if (docForm.exam_type) return saveExamReport();
    if (!props.urls.store_doc) return;
    docSaving.value = true;
    try {
        const res = await fetch(props.urls.store_doc, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            body: JSON.stringify({ ...docForm }),
        });
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            alert(err.message ?? 'Erro ao salvar documentação.');
            return;
        }
        const doc = await res.json();
        documentations.value.unshift(doc);
        showDocModal.value = false;
        resetDocForm();
    } catch (e) {
        console.error('Save documentation error:', e);
    } finally {
        docSaving.value = false;
    }
}

async function saveExamReport() {
    const url = buildQuickActionUrl('exam-report');
    if (!url) return;
    docSaving.value = true;
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            body: JSON.stringify({
                exam_type: docForm.exam_type,
                subtype:   docForm.exam_subtype || null,
                content:   docForm.content,
                title:     docForm.title,
            }),
        });
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            alert(err.message ?? 'Erro ao emitir o laudo.');
            return;
        }
        const doc = await res.json();
        documentations.value.unshift(doc);
        showDocModal.value = false;
        resetDocForm();
        if (doc.pdf_url) openPdfPreview(doc.pdf_url, doc.title || '');
    } catch (e) {
        console.error('Save exam report error:', e);
    } finally {
        docSaving.value = false;
    }
}

async function openExamFromHub(examType, subtype = null) {
    showExamHubModal.value = false;
    await nextTick();
    await openExam(examType, subtype);
}

async function openExam(examType, subtype = null) {
    if (!examType || !props.urls.exam_template_template) return;
    let url = props.urls.exam_template_template.replace('__EXAM__', encodeURIComponent(examType));
    if (subtype) url += (url.includes('?') ? '&' : '?') + `subtype=${encodeURIComponent(subtype)}`;
    try {
        const res = await fetch(url, { headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            alert(err.message ?? 'Não foi possível carregar o template do exame.');
            return;
        }
        const data = await res.json();
        resetDocForm();
        docForm.exam_type    = data.exam_type;
        docForm.exam_subtype = data.subtype || '';
        docForm.exam_label   = data.label || '';
        docForm.title        = data.title || data.label || '';
        docForm.content      = data.html || '';
        showDocModal.value = true;
    } catch (e) {
        console.error('Open exam template error:', e);
    }
}

// ──────────────────────────────────────────────────────────────────────────
// F5 — Medicamentos
// ──────────────────────────────────────────────────────────────────────────
function openMedicationPrescription() {
    showMedicationModal.value = true;
    selectedMed.value = null;
    posologyDraft.value = '';
    medTab.value = 'search';
    loadMedPresets();
}

async function loadMedPresets() {
    if (!props.urls.medication_presets) return;
    try {
        const res = await fetch(props.urls.medication_presets, { headers: { Accept: 'application/json' } });
        if (res.ok) medPresets.value = await res.json();
    } catch { /**/ }
}

// Posologia genérica da base (sugestão default quando o médico não tem a dele)
function genericPosology(item) {
    const usage = [item.dosage, item.frequency].filter(Boolean).join(' ')
        + (item.duration ? ` por ${item.duration}` : '');
    const lines = [];
    if (usage.trim()) lines.push(usage.trim());
    if (item.instructions) lines.push(`Obs: ${item.instructions}`);
    return lines.join('\n');
}

// Fluxo: buscar/aba → SELECIONAR → sugerir posologia (editável) → confirmar.
// Nada entra na receita sem passar pelo draft editável.
function selectMedicine(item) {
    if (!item?.id) return;
    selectedMed.value   = item;
    posologyDraft.value = item.my_posology || genericPosology(item);
    posologySavedFlash.value = false;
    medSearchOpen.value = false;
}

function cancelSelection() {
    selectedMed.value = null;
    posologyDraft.value = '';
}

async function confirmAddMedicine() {
    const item = selectedMed.value;
    if (!item) return;
    if (prescription.value.length >= maxMedicines) return;
    if (prescription.value.some((m) => m.id === item.id)) { cancelSelection(); return; }

    if (props.urls.medication_format) {
        try {
            const res = await fetch(props.urls.medication_format, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
                body: JSON.stringify({ medicine_id: item.id, posology: posologyDraft.value }),
            });
            if (res.ok) {
                const data = await res.json();
                medicineLists.value += data.line ?? '';
            }
        } catch (e) {
            console.error('Format line error:', e);
        }
    }

    prescription.value.push(item);
    medSearchQuery.value = '';
    medSearchResults.value = [];
    cancelSelection();

    // Contadores das abas Recentes/Favoritos — falha não bloqueia a receita.
    if (props.urls.medication_presets_use) {
        fetch(props.urls.medication_presets_use, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            body: JSON.stringify({ medicine_id: item.id }),
        }).then(() => loadMedPresets()).catch(() => {});
    }
}

async function saveMyPosology() {
    const item = selectedMed.value;
    if (!item || !props.urls.medication_presets_posology) return;
    posologySaving.value = true;
    try {
        const res = await fetch(props.urls.medication_presets_posology, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            body: JSON.stringify({ medicine_id: item.id, posology: posologyDraft.value }),
        });
        if (res.ok) {
            item.my_posology = posologyDraft.value.trim() || null;
            posologySavedFlash.value = true;
            setTimeout(() => { posologySavedFlash.value = false; }, 2500);
            loadMedPresets();
        }
    } finally {
        posologySaving.value = false;
    }
}

async function toggleMedFavorite(item) {
    if (!item?.id || !props.urls.medication_presets_favorite) return;
    const target = !item.is_favorite;
    item.is_favorite = target; // otimista
    try {
        await fetch(props.urls.medication_presets_favorite, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            body: JSON.stringify({ medicine_id: item.id, favorite: target }),
        });
        loadMedPresets();
    } catch {
        item.is_favorite = !target;
    }
}

async function searchMedicines() {
    const q = (medSearchQuery.value || '').trim();
    if (q.length < 2 || !props.urls.medicine_search) {
        medSearchResults.value = []; medSearchOpen.value = false; return;
    }
    medSearchLoading.value = true;
    try {
        const res = await fetch(`${props.urls.medicine_search}?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        if (!res.ok) { medSearchResults.value = []; medSearchOpen.value = false; return; }
        medSearchResults.value = await res.json();
        medSearchOpen.value = medSearchResults.value.length > 0;
    } catch (e) {
        console.error('Medicine search error:', e);
        medSearchResults.value = []; medSearchOpen.value = false;
    } finally {
        medSearchLoading.value = false;
    }
}

function removeMedicine(idx) {
    if (idx >= 0 && idx < prescription.value.length) prescription.value.splice(idx, 1);
}

function clearMedicines() {
    prescription.value = []; medicineLists.value = ''; medSearchQuery.value = '';
    medSearchResults.value = []; medSearchOpen.value = false;
    cancelSelection();
}

function submitMedicationPrescription() {
    const content = (medicineLists.value || '').trim();
    if (!content) return;
    showMedicationModal.value = false;
    issueQuickAction('medication-prescription', { content }, { preview: true });
}

// ──────────────────────────────────────────────────────────────────────────
// F6 — Procedimentos + Indicações
// ──────────────────────────────────────────────────────────────────────────
function openProcedureSolicitation() { showProcedureModal.value = true; }

async function searchProcedures() {
    const q = (procSearchQuery.value || '').trim();
    if (q.length < 2 || !props.urls.procedure_search) {
        procSearchResults.value = []; procSearchOpen.value = false; return;
    }
    procSearchLoading.value = true;
    try {
        const res = await fetch(`${props.urls.procedure_search}?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        if (!res.ok) { procSearchResults.value = []; procSearchOpen.value = false; return; }
        procSearchResults.value = await res.json();
        procSearchOpen.value = procSearchResults.value.length > 0;
    } catch (e) {
        console.error('Procedure search error:', e);
        procSearchResults.value = []; procSearchOpen.value = false;
    } finally {
        procSearchLoading.value = false;
    }
}

async function searchIndications() {
    const q = (indSearchQuery.value || '').trim();
    if (q.length < 2 || !props.urls.indication_search) {
        indSearchResults.value = []; indSearchOpen.value = false; return;
    }
    indSearchLoading.value = true;
    try {
        const res = await fetch(`${props.urls.indication_search}?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        if (!res.ok) { indSearchResults.value = []; indSearchOpen.value = false; return; }
        indSearchResults.value = await res.json();
        indSearchOpen.value = indSearchResults.value.length > 0;
    } catch (e) {
        console.error('Indication search error:', e);
        indSearchResults.value = []; indSearchOpen.value = false;
    } finally {
        indSearchLoading.value = false;
    }
}

const TYPE_LABEL = { rotina: 'Rotina', urgencia: 'Urgência', controle: 'Controle', comparativo: 'Comparativo' };

async function addProcedure(item) {
    if (procSelected.value.length + indSelected.value.length >= maxProcSolicitations) return;
    const type = procTypeSelected.value || '';
    const idx = procSelected.value.length;
    procSelected.value.push({
        id: item.id, name: item.name, code: item.code,
        type, type_label: type ? TYPE_LABEL[type] : '',
    });
    procSearchQuery.value = ''; procSearchResults.value = []; procSearchOpen.value = false;
    await _appendSolicitationLine({ kind: 'procedure', id: item.id, type: type || null },
        () => procSelected.value.splice(idx, 1));
}

async function addIndication(item) {
    if (procSelected.value.length + indSelected.value.length >= maxProcSolicitations) return;
    const idx = indSelected.value.length;
    indSelected.value.push({ id: item.id, description: item.description });
    indSearchQuery.value = ''; indSearchResults.value = []; indSearchOpen.value = false;
    await _appendSolicitationLine({ kind: 'indication', id: item.id },
        () => indSelected.value.splice(idx, 1));
}

async function _appendSolicitationLine(payload, onError) {
    if (!props.urls.procedure_format) return;
    try {
        const res = await fetch(props.urls.procedure_format, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            body: JSON.stringify(payload),
        });
        if (!res.ok) { onError?.(); return; }
        const { line } = await res.json();
        if (!line) return;
        procedureLists.value = procedureLists.value
            ? `${procedureLists.value.trimEnd()}\n${line}\n`
            : `${line}\n`;
    } catch (e) {
        console.error('Format solicitation line error:', e);
        onError?.();
    }
}

function removeProcedure(idx) { procSelected.value.splice(idx, 1); }
function removeIndication(idx) { indSelected.value.splice(idx, 1); }

function clearProcedureSolicitation() {
    procSelected.value = []; indSelected.value = []; procedureLists.value = '';
    procSearchQuery.value = ''; indSearchQuery.value = '';
    procSearchResults.value = []; indSearchResults.value = [];
    procSearchOpen.value = false; indSearchOpen.value = false;
}

function submitProcedureSolicitation() {
    const content = (procedureLists.value || '').trim();
    if (!content) return;
    showProcedureModal.value = false;
    issueQuickAction('procedure-request', { content }, { preview: true });
}

// ──────────────────────────────────────────────────────────────────────────
// F7 — Atestados
// ──────────────────────────────────────────────────────────────────────────
function openAttendanceCertificate() {
    attendanceForm.content = '';
    showAttendanceCertModal.value = true;
}

function submitAttendanceCertificate() {
    const payload = {};
    const content = (attendanceForm.content || '').trim();
    if (content !== '') payload.content = content;
    showAttendanceCertModal.value = false;
    issueQuickAction('attendance-certificate', payload, { preview: true });
}

function openMedicalCertificate() {
    medicalForm.days = 1; medicalForm.date = ''; medicalForm.content = ''; medicalForm.daysPreview = '';
    refreshDayExtension();
    showMedicalCertModal.value = true;
}

function formatMedicalCertDate(event) {
    const digits = (event.target.value || '').replace(/\D/g, '').slice(0, 8);
    let formatted = digits;
    if (digits.length > 4) formatted = `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`;
    else if (digits.length > 2) formatted = `${digits.slice(0, 2)}/${digits.slice(2)}`;
    medicalForm.date = formatted;
}

let _dayExtensionTimer = null;
function debouncedRefreshDayExtension() {
    if (_dayExtensionTimer) clearTimeout(_dayExtensionTimer);
    _dayExtensionTimer = setTimeout(refreshDayExtension, 350);
}

async function refreshDayExtension() {
    const days = Number(medicalForm.days);
    if (!Number.isInteger(days) || days < 1 || days > 365) {
        medicalForm.daysPreview = ''; return;
    }
    if (!props.urls.day_extension_preview) return;
    try {
        const res = await fetch(props.urls.day_extension_preview, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            body: JSON.stringify({ days }),
        });
        if (!res.ok) { medicalForm.daysPreview = ''; return; }
        const data = await res.json();
        medicalForm.daysPreview = data.display || '';
    } catch (e) {
        console.error('Day extension preview error:', e);
        medicalForm.daysPreview = '';
    }
}

function submitMedicalCertificate() {
    const days = Number(medicalForm.days);
    if (!Number.isInteger(days) || days < 1 || days > 365) return;
    const payload = { days };
    const date = (medicalForm.date || '').trim();
    if (date) payload.date = date;
    const content = (medicalForm.content || '').trim();
    if (content) payload.content = content;
    showMedicalCertModal.value = false;
    issueQuickAction('medical-certificate', payload, { preview: true });
}

// ──────────────────────────────────────────────────────────────────────────
// F8 — Catarata
// ──────────────────────────────────────────────────────────────────────────
function openCataractPrescription() {
    cataractForm.eye = 'right';
    cataractForm.template = 'pre_operatorio';
    cataractForm.date_surgery = '';
    cataractForm.hour_surgery = '';
    showCataractModal.value = true;
}

function formatCataractDate(event) {
    const digits = (event.target.value || '').replace(/\D/g, '').slice(0, 8);
    let formatted = digits;
    if (digits.length > 4) formatted = `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`;
    else if (digits.length > 2) formatted = `${digits.slice(0, 2)}/${digits.slice(2)}`;
    cataractForm.date_surgery = formatted;
}

function submitCataractPrescription() {
    if (!cataractForm.eye) return;
    const payload = {
        eye:          cataractForm.eye,
        template:     cataractForm.template || 'pre_operatorio',
        date_surgery: (cataractForm.date_surgery || '').trim(),
        hour_surgery: (cataractForm.hour_surgery || '').trim(),
    };
    showCataractModal.value = false;
    issueQuickAction('cataract-prescription', payload, { preview: true });
}

// ──────────────────────────────────────────────────────────────────────────
// CID-10 picker
// ──────────────────────────────────────────────────────────────────────────
async function searchCid10() {
    const q = (cidQuery.value || '').trim();
    if (q.length < 2 || !props.urls.cid10_search) {
        cidResults.value = []; cidOpen.value = false; return;
    }
    cidSearching.value = true;
    try {
        const res = await fetch(`${props.urls.cid10_search}?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        if (!res.ok) { cidResults.value = []; cidOpen.value = false; return; }
        const list = await res.json();
        cidResults.value = list.filter((c) => !selectedCids.value.some((s) => s.code === c.code));
        cidOpen.value = cidResults.value.length > 0;
        cidActiveIndex.value = -1;
    } catch (e) {
        console.error('CID-10 search error:', e);
    } finally {
        cidSearching.value = false;
    }
}

function selectCid(item) {
    if (!selectedCids.value.some((s) => s.code === item.code)) {
        selectedCids.value.push({ code: item.code, description: item.description });
    }
    cidQuery.value = ''; cidResults.value = []; cidOpen.value = false;
}

function removeCid(code) {
    selectedCids.value = selectedCids.value.filter((s) => s.code !== code);
}

function selectActiveCid() {
    if (cidActiveIndex.value >= 0 && cidActiveIndex.value < cidResults.value.length) {
        selectCid(cidResults.value[cidActiveIndex.value]);
    }
}

// ──────────────────────────────────────────────────────────────────────────
// Anexos — delegados ao MedicalRecordFileUploadModal
// ──────────────────────────────────────────────────────────────────────────
function openUploadModal() {
    if (!props.isEdit || !props.urls.store_file) return;
    showUploadModal.value = true;
}

function onFileUploaded(file) {
    // Empilha cada arquivo retornado pelo backend conforme conclui o upload.
    if (file?.id && !uploadedFiles.value.some(f => f.id === file.id)) {
        uploadedFiles.value.push(file);
    }
}

function onStorageUpdated(state) {
    storageState.value = { ...storageState.value, ...state };
}

const serializedCids = computed(() => JSON.stringify(selectedCids.value));
</script>

<template>
    <form @submit.prevent="submit" class="pmr-form" enctype="multipart/form-data" novalidate>
        <!-- Assistente de IA -->
        <div v-if="aiEnabled" class="d-flex justify-content-end align-items-center gap-2 px-3 pt-2">
            <button type="button" class="btn btn-sm btn-info text-white d-inline-flex align-items-center gap-1"
                    @click="openAiPanel">
                <i class="ti ti-robot"></i>{{ ai?.assistant?.title ?? 'Assistente de IA' }}
            </button>
        </div>

        <AiAssistantPanel
            v-if="aiEnabled"
            :open="aiPanelOpen"
            :ai="ai"
            :context="aiContext"
            @close="aiPanelOpen = false"
            @inserted="applyAiSuggestion"
            @approved="onAiApproved"
        />

        <!-- F9 erros client -->
        <div v-if="hasClientErrors" class="alert alert-danger m-3 mb-0" role="alert">
            <h6 class="alert-heading mb-1"><i class="fas fa-exclamation-triangle me-1"></i>{{ tt('client_errors_title', 'Erros de validação') }}</h6>
            <ul class="mb-0 small ps-3">
                <template v-for="(msgs, field) in clientErrors" :key="field">
                    <li v-for="(m, i) in msgs" :key="`${field}-${i}`">{{ m }}</li>
                </template>
            </ul>
        </div>

        <!-- Erros Inertia (server) -->
        <div v-if="Object.keys(form.errors).length" class="alert alert-danger m-3 mb-0" role="alert">
            <h6 class="alert-heading mb-1"><i class="fas fa-exclamation-triangle me-1"></i>{{ tt('server_errors_title', 'Erros do servidor') }}</h6>
            <ul class="mb-0 small ps-3">
                <li v-for="(msg, field) in form.errors" :key="field">{{ msg }}</li>
            </ul>
        </div>

        <!-- Banner info CREATE -->
        <div v-if="!isEdit" class="alert alert-info d-flex align-items-start gap-2 m-3 mb-0" role="alert">
            <i class="fas fa-info-circle mt-1 flex-shrink-0"></i>
            <span class="small">
                Preencha pelo menos a <strong>{{ tt('complaint', 'Queixa principal') }}</strong>
                e clique em <strong>Salvar</strong> para começar a editar o prontuário.
            </span>
        </div>

        <!-- Médico (select se admin ou sem currentDoctor) -->
        <input v-if="!canChooseDoctor && currentDoctorId" type="hidden" name="doctor_id" :value="form.doctor_id">
        <div v-else class="pmr-section px-3 pt-2">
            <div class="row g-2">
                <div class="col-12 col-md-4 col-lg-3">
                    <label class="pmr-label">{{ tt('doctor', 'Médico') }}</label>
                    <SearchSelect v-model="form.doctor_id"
                                  :options="doctors"
                                  :placeholder="tt('select', 'Selecione')"
                                  :invalid="Boolean(form.errors.doctor_id)"
                                  :disabled="isLocked" />
                </div>
            </div>
        </div>

        <!-- Modelo de prontuário (padrão / meu modelo / texto livre) — só médico -->
        <div v-if="isDoctor" class="px-3 pt-2 d-flex align-items-center gap-2 flex-wrap pmr-mode-bar">
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn"
                        :class="recordMode === 'default' ? 'btn-primary' : 'btn-outline-secondary'"
                        @click="recordMode = 'default'">
                    <i class="fas fa-eye me-1"></i>{{ tt('mode_default', 'Padrão EasyEye') }}
                </button>
                <button type="button" class="btn"
                        :class="recordMode === 'custom' ? 'btn-primary' : 'btn-outline-secondary'"
                        @click="recordMode = 'custom'">
                    <i class="fas fa-user-pen me-1"></i>{{ tt('mode_custom', 'Meu prontuário') }}
                </button>
                <button type="button" class="btn"
                        :class="recordMode === 'free' ? 'btn-primary' : 'btn-outline-secondary'"
                        @click="recordMode = 'free'">
                    <i class="fas fa-align-left me-1"></i>{{ tt('mode_free', 'Texto livre') }}
                </button>
            </div>

            <button v-if="isCustomMode" type="button" class="btn btn-outline-primary btn-sm"
                    @click="showLayoutModal = true">
                <i class="fas fa-sliders me-1"></i>{{ tt('customize', 'Personalizar') }}
            </button>

            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none"
                    :disabled="isCurrentModeDefault || layoutSaving"
                    :title="tt('default_mode_hint', 'Novos atendimentos abrirão neste formato')"
                    @click="saveDefaultMode">
                <i :class="isCurrentModeDefault ? 'fas fa-star text-warning' : 'far fa-star'" class="me-1"></i>
                {{ isCurrentModeDefault ? tt('is_default_mode', 'Seu padrão') : tt('set_default_mode', 'Definir como meu padrão') }}
            </button>

            <span v-if="layoutSavedFlash" class="badge bg-success-subtle text-success">{{ layoutSavedFlash }}</span>
        </div>

        <!-- Queixa + Switches clínicos -->
        <div class="pmr-section pmr-top-strip px-3 pt-2 pb-0 bg-white">
            <div class="row g-2 align-items-start">
                <div class="col-12 col-xl-8">
                    <label class="pmr-label">{{ tt('complaint', 'Queixa principal') }}</label>
                    <textarea v-model="form.main_complaint" name="main_complaint" rows="4"
                              class="form-control form-control-sm"
                              :class="{ 'is-invalid': form.errors.main_complaint }"
                              :placeholder="tt('complaint_ph', 'Descreva a queixa principal...')"
                              :disabled="isLocked"></textarea>
                </div>

                <div class="col-12 col-xl-4 pmr-risk-wrap">
                    <!--
                        Título sem legenda: cores continuam Vermelho (danger, paciente TEM)
                        e Amarelo (warning, histórico familiar), mas a identificação
                        já aparece nos próprios switches (labels "Próprio"/"Familiar"),
                        então a legenda ao lado do título ficava redundante.
                    -->
                    <div class="d-flex align-items-baseline flex-wrap gap-2 mb-1">
                        <label class="pmr-label mb-0">{{ tt('clinical_history', 'Antecedentes clínicos') }}</label>
                    </div>

                    <div class="row g-1 pmr-risk-grid">
                        <template v-for="flag in ['diabetic', 'hypertensive', 'glaucomatous']" :key="flag">
                            <div class="col-4 pmr-risk-item">
                                <label class="pmr-label text-center d-block pmr-risk-title">
                                    {{ tt(flag, flag.charAt(0).toUpperCase() + flag.slice(1)) }}
                                </label>

                                <div class="pmr-risk-switches">
                                    <!-- Próprio: vermelho quando ativo (paciente tem a condição) -->
                                    <div class="form-check form-switch pmr-risk-switch pmr-risk-self mb-0"
                                         :class="{ 'pmr-risk-switch--on': form[flag] }">
                                        <input v-model="form[flag]" type="checkbox" class="form-check-input" role="switch"
                                               :id="`risk-${flag}-self`" :name="flag" :value="1" :disabled="isLocked">
                                        <label class="form-check-label pmr-risk-switch-label" :for="`risk-${flag}-self`">
                                            {{ tt('self', 'Próprio') }}
                                        </label>
                                    </div>

                                    <!-- Familiar: amarelo quando ativo (histórico hereditário) -->
                                    <div class="form-check form-switch pmr-risk-switch pmr-risk-family mb-0"
                                         :class="{ 'pmr-risk-switch--on': form[`${flag}_family`] }">
                                        <input v-model="form[`${flag}_family`]" type="checkbox" class="form-check-input" role="switch"
                                               :id="`risk-${flag}-family`" :name="`${flag}_family`" :value="1" :disabled="isLocked">
                                        <label class="form-check-label pmr-risk-switch-label" :for="`risk-${flag}-family`">
                                            {{ tt('family', 'Familiar') }}
                                        </label>
                                    </div>
                                </div>

                                <!-- Botão "+ Outros" aparece apenas sob o glaucomatous (último flag) -->
                                <div v-if="flag === 'glaucomatous'" class="text-center mt-1">
                                    <button type="button"
                                            class="btn btn-link p-0 pmr-toggle-label text-decoration-none"
                                            style="font-size:.68rem;"
                                            @click="showOthersHistory = !showOthersHistory">
                                        <i v-if="!showOthersHistory" class="fas fa-plus-circle fa-xs me-1"></i>
                                        <i v-else class="fas fa-minus-circle fa-xs me-1"></i>
                                        {{ tt('others', 'Outros') }}
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <div v-show="showOthersHistory" class="row g-2 mt-1">
                <div class="col-12">
                    <input v-model="form.others_history" ref="othersHistoryInput" type="text" name="others_history"
                           class="form-control form-control-sm"
                           :placeholder="tt('others_history_ph', 'Outros antecedentes clínicos')"
                           :disabled="isLocked">
                </div>
            </div>
        </div>

        <!-- Modo texto livre: uma área única de evolução, gravada no campo
             ESTRUTURADO observation_general do prontuário (integridade: dado
             continua no lugar certo independente do modelo usado). v-show nas
             colunas (não v-if): campos preenchidos continuam no submit. -->
        <div v-if="isFreeMode" class="px-3 pt-1 pb-2">
            <label class="pmr-label">{{ tt('free_text_label', 'Evolução / atendimento (texto livre)') }}</label>
            <textarea v-model="form.observation_general" rows="14"
                      class="form-control form-control-sm"
                      :placeholder="tt('free_text_ph', 'Descreva livremente o atendimento...')"
                      :disabled="isLocked"></textarea>
            <small class="text-muted d-block mt-1">
                <i class="fas fa-database me-1"></i>{{ tt('free_text_hint', 'Gravado no campo "Observações" do prontuário — os dados continuam estruturados no histórico do paciente.') }}
            </small>
        </div>

        <!-- Duas colunas principais -->
        <div v-show="!isFreeMode" class="row g-2 px-3 pt-1 pb-1 pmr-main-columns">
            <!-- COLUNA ESQUERDA -->
            <div class="col-12 col-xl-6 pe-xl-2">
                <div class="pmr-main-panel" :class="{ 'd-flex flex-column': isCustomMode }">

                    <!-- Vis. cromática / PPC / Cover test -->
                    <div class="pmr-section mb-1" :style="sectionStyle('cromatica_ppc_cover')">
                        <div class="row g-2">
                            <div class="col-4">
                                <label class="pmr-label">{{ tt('chromatic_vision', 'Vis. cromática') }}</label>
                                <SearchSelect v-model="form.color_vision_type_id"
                                              :options="colorVisionTypes"
                                              :placeholder="'—'" :disabled="isLocked" />
                            </div>
                            <div class="col-4">
                                <label class="pmr-label">{{ tt('near_point', 'PPC') }}</label>
                                <SearchSelect v-model="form.near_point_convergence_id"
                                              :options="nearPointTypes"
                                              :placeholder="'—'" :disabled="isLocked" />
                            </div>
                            <div class="col-4">
                                <label class="pmr-label">{{ tt('cover_test', 'Cover test') }}</label>
                                <SearchSelect v-model="form.cover_test_type_id"
                                              :options="coverTestTypes"
                                              :placeholder="'—'" :disabled="isLocked" />
                            </div>
                        </div>
                    </div>

                    <!-- A/V sem correção + Tonometria -->
                    <div class="pmr-section mb-1" :style="sectionStyle('av_sem_tono')">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="pmr-label">{{ tt('av_without', 'A/V sem correção') }}</label>
                                <div class="d-flex gap-1 flex-wrap">
                                    <div class="input-group input-group-sm flex-nowrap pmr-eye-group">
                                        <span class="input-group-text pmr-eye-badge">OD</span>
                                        <AcuitySelect v-model="form.visual_acuity_without_correction_right_id"
                                                      :options="visualAcuityTypes"
                                                      :placeholder="'—'" :disabled="isLocked" />
                                    </div>
                                    <div class="input-group input-group-sm flex-nowrap pmr-eye-group">
                                        <span class="input-group-text pmr-eye-badge">OE</span>
                                        <AcuitySelect v-model="form.visual_acuity_without_correction_left_id"
                                                      :options="visualAcuityTypes"
                                                      :placeholder="'—'" :disabled="isLocked" />
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="pmr-label">{{ tt('tonometry', 'Tonometria') }}</label>
                                <div class="d-flex gap-1 align-items-center flex-wrap">
                                    <div class="input-group input-group-sm flex-nowrap" style="max-width:90px;">
                                        <span class="input-group-text pmr-eye-badge">OD</span>
                                        <input v-model="form.tonometer_right" type="number" name="tonometer_right" step="0.5" min="0"
                                               class="form-control form-control-sm text-center"
                                               placeholder="00" :disabled="isLocked" style="min-width:0;"
                                               @click="$event.target.select()">
                                    </div>
                                    <div class="input-group input-group-sm flex-nowrap" style="max-width:90px;">
                                        <span class="input-group-text pmr-eye-badge">OE</span>
                                        <input v-model="form.tonometer_left" type="number" name="tonometer_left" step="0.5" min="0"
                                               class="form-control form-control-sm text-center"
                                               placeholder="00" :disabled="isLocked" style="min-width:0;"
                                               @click="$event.target.select()">
                                    </div>
                                    <input
                                        type="time"
                                        v-model="tonometryStampedTime"
                                        step="600"
                                        class="form-control form-control-sm"
                                        style="max-width:110px;"
                                        :disabled="isLocked"
                                    >
                                    <input type="hidden" name="tonometer_time" :value="tonometryStampedTime">
                                    <!--
                                        Impressão do laudo de tonometria: salva via storeTonometry no backend
                                        (exige IssueReport — CFM 2.227/2018). Só médico pode emitir o laudo.
                                    -->
                                    <button v-if="isDoctor" type="button" class="btn btn-pink btn-sm flex-shrink-0"
                                            :title="tt('print_tonometry', 'Imprimir tonometria')"
                                            @click="printTonometry">
                                        <i class="fas fa-print"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Dinâmica -->
                    <div class="pmr-section mb-1" :style="sectionStyle('dinamica')">
                        <label class="pmr-label">{{ tt('dynamic', 'Dinâmica') }}</label>
                        <table class="pmr-table">
                            <thead><tr><th style="width:36px;"></th><th>{{ tt('spherical','Esf.') }}</th><th>{{ tt('cylindrical','Cil.') }}</th><th>{{ tt('axis','Eixo') }}</th></tr></thead>
                            <tbody>
                                <tr>
                                    <td class="pmr-od">OD</td>
                                    <td><input v-model="form.dynamic_spherical_right" type="text" inputmode="decimal" name="dynamic_spherical_right" placeholder="0.00"
                                               :disabled="isLocked" @click="$event.target.select()"
                                               @blur="formatLens('spherical', 'dynamic_spherical_right')"
                                               @keydown.enter.prevent="formatLens('spherical', 'dynamic_spherical_right').then(() => focusNextLensField('dynamic_spherical_right'))"></td>
                                    <td><input v-model="form.dynamic_cylindrical_right" type="text" inputmode="decimal" name="dynamic_cylindrical_right" placeholder="0.00"
                                               :disabled="isLocked" @click="$event.target.select()"
                                               @blur="formatLens('cylindrical', 'dynamic_cylindrical_right')"
                                               @keydown.enter.prevent="formatLens('cylindrical', 'dynamic_cylindrical_right').then(() => focusNextLensField('dynamic_cylindrical_right'))"></td>
                                    <td><input v-model="form.dynamic_axis_right" type="text" inputmode="numeric" name="dynamic_axis_right" placeholder="0º"
                                               :disabled="isLocked" @click="$event.target.select()"
                                               @blur="formatLens('axis', 'dynamic_axis_right')"
                                               @keydown.enter.prevent="formatLens('axis', 'dynamic_axis_right').then(() => focusNextLensField('dynamic_axis_right'))"></td>
                                </tr>
                                <tr>
                                    <td class="pmr-od">OE</td>
                                    <td><input v-model="form.dynamic_spherical_left" type="text" inputmode="decimal" name="dynamic_spherical_left" placeholder="0.00"
                                               :disabled="isLocked" @click="$event.target.select()"
                                               @blur="formatLens('spherical', 'dynamic_spherical_left')"
                                               @keydown.enter.prevent="formatLens('spherical', 'dynamic_spherical_left').then(() => focusNextLensField('dynamic_spherical_left'))"></td>
                                    <td><input v-model="form.dynamic_cylindrical_left" type="text" inputmode="decimal" name="dynamic_cylindrical_left" placeholder="0.00"
                                               :disabled="isLocked" @click="$event.target.select()"
                                               @blur="formatLens('cylindrical', 'dynamic_cylindrical_left')"
                                               @keydown.enter.prevent="formatLens('cylindrical', 'dynamic_cylindrical_left').then(() => focusNextLensField('dynamic_cylindrical_left'))"></td>
                                    <td><input v-model="form.dynamic_axis_left" type="text" inputmode="numeric" name="dynamic_axis_left" placeholder="0º"
                                               :disabled="isLocked" @click="$event.target.select()"
                                               @blur="formatLens('axis', 'dynamic_axis_left')"
                                               @keydown.enter.prevent="formatLens('axis', 'dynamic_axis_left').then(() => focusNextLensField('dynamic_axis_left'))"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Estática -->
                    <div class="pmr-section mb-1" :style="sectionStyle('estatica')">
                        <label class="pmr-label">{{ tt('static', 'Estática') }}</label>
                        <table class="pmr-table">
                            <thead><tr><th style="width:36px;"></th><th>{{ tt('spherical','Esf.') }}</th><th>{{ tt('cylindrical','Cil.') }}</th><th>{{ tt('axis','Eixo') }}</th></tr></thead>
                            <tbody>
                                <tr>
                                    <td class="pmr-od">OD</td>
                                    <td><input v-model="form.static_spherical_right" type="text" inputmode="decimal" name="static_spherical_right" placeholder="0.00"
                                               :disabled="isLocked" @click="$event.target.select()"
                                               @blur="formatLens('spherical', 'static_spherical_right')"
                                               @keydown.enter.prevent="formatLens('spherical', 'static_spherical_right').then(() => focusNextLensField('static_spherical_right'))"></td>
                                    <td><input v-model="form.static_cylindrical_right" type="text" inputmode="decimal" name="static_cylindrical_right" placeholder="0.00"
                                               :disabled="isLocked" @click="$event.target.select()"
                                               @blur="formatLens('cylindrical', 'static_cylindrical_right')"
                                               @keydown.enter.prevent="formatLens('cylindrical', 'static_cylindrical_right').then(() => focusNextLensField('static_cylindrical_right'))"></td>
                                    <td><input v-model="form.static_axis_right" type="text" inputmode="numeric" name="static_axis_right" placeholder="0º"
                                               :disabled="isLocked" @click="$event.target.select()"
                                               @blur="formatLens('axis', 'static_axis_right')"
                                               @keydown.enter.prevent="formatLens('axis', 'static_axis_right').then(() => focusNextLensField('static_axis_right'))"></td>
                                </tr>
                                <tr>
                                    <td class="pmr-od">OE</td>
                                    <td><input v-model="form.static_spherical_left" type="text" inputmode="decimal" name="static_spherical_left" placeholder="0.00"
                                               :disabled="isLocked" @click="$event.target.select()"
                                               @blur="formatLens('spherical', 'static_spherical_left')"
                                               @keydown.enter.prevent="formatLens('spherical', 'static_spherical_left').then(() => focusNextLensField('static_spherical_left'))"></td>
                                    <td><input v-model="form.static_cylindrical_left" type="text" inputmode="decimal" name="static_cylindrical_left" placeholder="0.00"
                                               :disabled="isLocked" @click="$event.target.select()"
                                               @blur="formatLens('cylindrical', 'static_cylindrical_left')"
                                               @keydown.enter.prevent="formatLens('cylindrical', 'static_cylindrical_left').then(() => focusNextLensField('static_cylindrical_left'))"></td>
                                    <td><input v-model="form.static_axis_left" type="text" inputmode="numeric" name="static_axis_left" placeholder="0º"
                                               :disabled="isLocked" @click="$event.target.select()"
                                               @blur="formatLens('axis', 'static_axis_left')"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>

            <!-- COLUNA DIREITA -->
            <div class="col-12 col-xl-6 ps-xl-2">
                <div class="pmr-main-panel" :class="{ 'd-flex flex-column': isCustomMode }">

                    <!-- Adição / Longe / Perto + Calc -->
                    <div class="pmr-section mb-1" :style="sectionStyle('adicao')">
                        <div class="row g-2 align-items-end">
                            <div class="col-6">
                                <label class="pmr-label">{{ tt('addition', 'Adição') }}</label>
                                <SearchSelect v-model="form.addition_type_id"
                                              :options="additionTypes"
                                              :placeholder="tt('select', 'Selecione')" :disabled="isLocked" />
                            </div>
                            <div class="col-6">
                                <label class="pmr-label">{{ tt('lens_away', 'Longe') }}</label>
                                <SearchSelect v-model="form.lens_away_id"
                                              :options="lenses"
                                              :placeholder="'—'" :disabled="isLocked" />
                            </div>
                            <div class="col-6">
                                <label class="pmr-label">{{ tt('lens_near', 'Perto') }}</label>
                                <SearchSelect v-model="form.lens_near_id"
                                              :options="lenses"
                                              :placeholder="'—'" :disabled="isLocked" />
                            </div>
                            <div class="col-6 d-flex gap-1 align-items-end">
                                <input v-model.number="presbyopiaAddition" type="number" step="0.25"
                                       class="form-control form-control-sm" placeholder="Add." :disabled="isLocked">
                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                        :disabled="isLocked"
                                        @click="openPresbyopiaCalc"
                                        :title="tt('calc', 'Calcular presbiopia')">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                                <!--
                                    Receituário de óculos: exclusivo para médicos (CFM Res. 2.227/2018).
                                    Admin/secretária da clínica não pode emitir receituário — só visualizar.
                                -->
                                <div v-if="isEdit && isDoctor" class="btn-group" role="group">
                                    <button type="button" class="btn btn-pink btn-sm dropdown-toggle"
                                            data-bs-toggle="dropdown" aria-expanded="false"
                                            :disabled="quickActionBusy || isLocked"
                                            :title="tt('lens_prescription', 'Receituário de óculos')">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><button type="button" class="dropdown-item" @click="issueLensPrescription('dynamic')">Dinâmica</button></li>
                                        <li><button type="button" class="dropdown-item" @click="issueLensPrescription('static')">Estática</button></li>
                                        <li><button type="button" class="dropdown-item" @click="issueLensPrescription('presbyopia_dynamic')">Presb. dinâmica</button></li>
                                        <li><button type="button" class="dropdown-item" @click="issueLensPrescription('presbyopia')">Presbiopia</button></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- A/V com correção -->
                    <div class="pmr-section mb-1" :style="sectionStyle('av_com')">
                        <label class="pmr-label">{{ tt('av_with', 'A/V com correção') }}</label>
                        <div class="d-flex gap-1 flex-wrap">
                            <div class="input-group input-group-sm flex-nowrap pmr-eye-group">
                                <span class="input-group-text pmr-eye-badge">OD</span>
                                <AcuitySelect v-model="form.visual_acuity_with_correction_right_id"
                                              :options="visualAcuityTypes"
                                              :placeholder="'—'" :disabled="isLocked" />
                            </div>
                            <div class="input-group input-group-sm flex-nowrap pmr-eye-group">
                                <span class="input-group-text pmr-eye-badge">OE</span>
                                <AcuitySelect v-model="form.visual_acuity_with_correction_left_id"
                                              :options="visualAcuityTypes"
                                              :placeholder="'—'" :disabled="isLocked" />
                            </div>
                        </div>
                    </div>

                    <!-- Biomicroscopia -->
                    <div class="pmr-section mb-1" :style="sectionStyle('biomicroscopia')">
                        <label class="pmr-label">{{ tt('biomicroscopy', 'Biomicroscopia') }}</label>
                        <div class="d-flex gap-1 mb-1">
                            <span class="pmr-eye-inline">OD</span>
                            <input v-model="form.biomicroscopy_right" type="text" name="biomicroscopy_right"
                                   class="form-control form-control-sm" :disabled="isLocked"
                                   @click="$event.target.select()">
                        </div>
                        <div class="d-flex gap-1">
                            <span class="pmr-eye-inline">OE</span>
                            <input v-model="form.biomicroscopy_left" type="text" name="biomicroscopy_left"
                                   class="form-control form-control-sm" :disabled="isLocked"
                                   @click="$event.target.select()">
                        </div>
                    </div>

                    <!-- Fundoscopia -->
                    <div class="pmr-section mb-1" :style="sectionStyle('fundoscopia')">
                        <label class="pmr-label">{{ tt('fundoscopy', 'Fundoscopia') }}</label>
                        <div class="d-flex gap-1 mb-1">
                            <span class="pmr-eye-inline">OD</span>
                            <input v-model="form.fundoscopy_right" type="text" name="fundoscopy_right"
                                   class="form-control form-control-sm" :disabled="isLocked"
                                   @click="$event.target.select()">
                        </div>
                        <div class="d-flex gap-1">
                            <span class="pmr-eye-inline">OE</span>
                            <input v-model="form.fundoscopy_left" type="text" name="fundoscopy_left"
                                   class="form-control form-control-sm" :disabled="isLocked"
                                   @click="$event.target.select()">
                        </div>
                    </div>

                    <!-- Observação geral -->
                    <div class="pmr-section mb-1" :style="sectionStyle('obs_geral')">
                        <label class="pmr-label">{{ tt('general_obs', 'Observações') }}</label>
                        <textarea v-model="form.observation_general" name="observation_general" rows="2"
                                  class="form-control form-control-sm" :disabled="isLocked"></textarea>
                    </div>

                </div>
            </div>
        </div>

        <!-- Seção colapsável: HDA / Histórico / Diagnóstico / Conduta -->
        <div v-show="!isFreeMode" class="px-3 pb-2">
            <div class="pmr-collapse-toggle mb-2" data-bs-toggle="collapse" data-bs-target="#pmr-extra-fields" role="button">
                <i class="fas fa-chevron-down me-1 pmr-collapse-icon"></i>
                <span class="pmr-label mb-0 d-inline">{{ tt('extra_fields', 'Campos adicionais') }}</span>
            </div>

            <div id="pmr-extra-fields" class="collapse">
                <!-- Reorganização (ticket "Organizar HDA"): mesmos campos de
                     sempre, agrupados pelo MOMENTO da consulta — Anamnese/
                     Histórico (antes do exame) e Conclusão (fechamento).
                     Display-only: nenhum campo novo, nenhum dado muda. -->

                <!-- ══ GRUPO 1: Anamnese / Histórico ══ -->
                <div class="pmr-field-group mb-3">
                    <div class="pmr-field-group-title">
                        <i class="fas fa-comment-medical me-1"></i>{{ tt('group_anamnesis', 'Anamnese / Histórico') }}
                        <small class="text-muted fw-normal ms-1">{{ tt('group_anamnesis_hint', 'início da consulta') }}</small>
                    </div>

                <div class="row g-2 mb-2">
                    <div class="col-12">
                        <label class="pmr-label">{{ tt('hda', 'HDA') }}</label>
                        <textarea v-model="form.hda" name="hda" rows="2"
                                  class="form-control form-control-sm" :disabled="isLocked"
                                  :placeholder="tt('hda_ph', 'História da doença atual')"></textarea>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="pmr-label">{{ tt('ocular_surgical_history', 'Histórico cirúrgico ocular') }}</label>
                        <textarea v-model="form.ocular_surgical_history" name="ocular_surgical_history" rows="2"
                                  class="form-control form-control-sm" :disabled="isLocked"></textarea>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="pmr-label">{{ tt('medications_in_use', 'Medicações em uso') }}</label>
                        <textarea v-model="form.medications_in_use" name="medications_in_use" rows="2"
                                  class="form-control form-control-sm" :disabled="isLocked"></textarea>
                    </div>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-12 col-md-6">
                        <label class="pmr-label">{{ tt('ocular_motility', 'Motilidade ocular') }}</label>
                        <input v-model="form.ocular_motility" type="text" name="ocular_motility"
                               class="form-control form-control-sm" :disabled="isLocked">
                    </div>
                </div>

                <!-- Paquimetria / Gonioscopia — movidos da tela principal para cá
                     (parâmetros específicos, não precisam poluir o prontuário base) -->
                <div class="row g-2 mb-2">
                    <div class="col-12 col-md-6">
                        <label class="pmr-label">{{ tt('pachymetry', 'Paquimetria') }}</label>
                        <div class="d-flex gap-1">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text pmr-eye-badge">OD</span>
                                <input v-model="form.pachymetry_right" type="number" name="pachymetry_right" step="1" min="0"
                                       class="form-control form-control-sm text-center" placeholder="μm" :disabled="isLocked">
                            </div>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text pmr-eye-badge">OE</span>
                                <input v-model="form.pachymetry_left" type="number" name="pachymetry_left" step="1" min="0"
                                       class="form-control form-control-sm text-center" placeholder="μm" :disabled="isLocked">
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="pmr-label">{{ tt('gonioscopy', 'Gonioscopia') }}</label>
                        <div class="d-flex gap-1">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text pmr-eye-badge">OD</span>
                                <input v-model="form.gonioscopy_right" type="text" name="gonioscopy_right"
                                       class="form-control form-control-sm" :disabled="isLocked">
                            </div>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text pmr-eye-badge">OE</span>
                                <input v-model="form.gonioscopy_left" type="text" name="gonioscopy_left"
                                       class="form-control form-control-sm" :disabled="isLocked">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-12 col-md-6">
                        <label class="pmr-label">{{ tt('lenses_obs', 'Observação de lentes') }}</label>
                        <textarea v-model="form.observation_of_lenses" name="observation_of_lenses" rows="2"
                                  class="form-control form-control-sm" :disabled="isLocked"></textarea>
                    </div>
                </div>
                </div><!-- /grupo Anamnese -->

                <!-- ══ GRUPO 2: Conclusão da consulta ══ -->
                <div class="pmr-field-group">
                    <div class="pmr-field-group-title">
                        <i class="fas fa-flag-checkered me-1"></i>{{ tt('group_conclusion', 'Conclusão da consulta') }}
                        <small class="text-muted fw-normal ms-1">{{ tt('group_conclusion_hint', 'diagnóstico e fechamento') }}</small>
                    </div>

                <div class="row g-2 mb-2">
                    <div class="col-12">
                        <label class="pmr-label">{{ tt('cid10', 'CID-10') }}</label>
                        <input type="hidden" name="diagnosis_cids" :value="serializedCids">

                        <div v-if="selectedCids.length > 0" class="d-flex flex-wrap gap-1 mb-1">
                            <span v-for="item in selectedCids" :key="item.code"
                                  class="badge d-inline-flex align-items-center gap-1"
                                  style="background:#e8f4fd;color:#1a5c8a;font-size:.8rem;font-weight:500;border:1px solid #b8d9f0;padding:.3rem .5rem;">
                                <span class="fw-semibold">{{ item.code }}</span>
                                <span class="text-secondary fw-normal" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">– {{ item.description }}</span>
                                <button type="button" class="btn-close btn-close-sm ms-1" style="font-size:.6rem;"
                                        :disabled="isLocked" @click="removeCid(item.code)"></button>
                            </span>
                        </div>

                        <div class="position-relative">
                            <div class="input-group input-group-sm">
                                <input v-model="cidQuery" type="text" class="form-control form-control-sm" autocomplete="off"
                                       placeholder="Buscar por código ou diagnóstico (ex: H40.1, glaucoma)…"
                                       :disabled="isLocked"
                                       @input="searchCid10"
                                       @keydown.arrow-down.prevent="cidActiveIndex = Math.min(cidActiveIndex + 1, cidResults.length - 1)"
                                       @keydown.arrow-up.prevent="cidActiveIndex = Math.max(cidActiveIndex - 1, 0)"
                                       @keydown.enter.prevent="selectActiveCid"
                                       @keydown.escape="cidOpen = false">
                                <span v-if="cidSearching" class="input-group-text bg-transparent border-start-0 px-2">
                                    <span class="spinner-border spinner-border-sm text-secondary" style="width:.8rem;height:.8rem;"></span>
                                </span>
                            </div>
                            <ul v-if="cidOpen && cidResults.length > 0" class="list-group shadow-sm position-absolute w-100"
                                style="z-index:1055;top:100%;max-height:260px;overflow-y:auto;">
                                <li v-for="(item, index) in cidResults" :key="item.id"
                                    class="list-group-item list-group-item-action py-1 px-2"
                                    :class="{ active: index === cidActiveIndex }"
                                    style="cursor:pointer;font-size:.82rem;"
                                    @mouseenter="cidActiveIndex = index"
                                    @mousedown.prevent="selectCid(item)">
                                    <span class="fw-semibold me-1">{{ item.code }}</span>
                                    <span>– {{ item.description }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-12 col-md-8">
                        <label class="pmr-label">{{ tt('clinical_conduct', 'Conduta clínica') }}</label>
                        <textarea v-model="form.clinical_conduct" name="clinical_conduct" rows="2"
                                  class="form-control form-control-sm" :disabled="isLocked"
                                  :placeholder="tt('clinical_conduct_ph', 'Conduta clínica...')"></textarea>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="pmr-label">{{ tt('follow_up_days', 'Retorno') }}</label>
                        <div class="input-group input-group-sm">
                            <input v-model="form.follow_up_days" type="number" min="0" name="follow_up_days"
                                   class="form-control form-control-sm" :disabled="isLocked">
                            <span class="input-group-text">{{ tt('days', 'dias') }}</span>
                        </div>
                    </div>
                </div>
                </div><!-- /grupo Conclusão -->
            </div>
        </div>

        <!-- Bottom bar — quick actions + save -->
        <div class="pmr-bottom-bar px-3 py-2">
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <!--
                    Quick actions clínicas: exclusivas para médicos (CFM Res. 2.227/2018).
                    Admin/Secretária da clínica não emite receituários/atestados/laudos —
                    backend rejeita via Gate IssueReport. Esconder na UI evita o 403
                    confuso e mantém paridade com o Blade original (@if($canSeeQuickActions)).
                -->
                <template v-if="isDoctor">
                    <button type="button" class="btn pmr-doc-img-btn"
                            :title="isEdit ? 'Receituário de Medicamentos' : tt('save_first', 'Salve primeiro o prontuário')"
                            :disabled="!isEdit || quickActionBusy || isLocked"
                            @click="openMedicationPrescription">
                        <i class="fas fa-pills" style="font-size:1.6rem;color:#9c27b0;"></i>
                        <span class="pmr-doc-img-btn-label">Medicamentos</span>
                    </button>
                    <button type="button" class="btn pmr-doc-img-btn"
                            :title="isEdit ? 'Solicitação de Procedimentos' : tt('save_first', 'Salve primeiro')"
                            :disabled="!isEdit || quickActionBusy || isLocked"
                            @click="openProcedureSolicitation">
                        <i class="fas fa-clipboard-list" style="font-size:1.6rem;color:#3f51b5;"></i>
                        <span class="pmr-doc-img-btn-label">Procedimentos</span>
                    </button>
                    <button type="button" class="btn pmr-doc-img-btn"
                            :title="isEdit ? 'Receituário de Pterígio' : tt('save_first', 'Salve primeiro')"
                            :disabled="!isEdit || quickActionBusy || isLocked"
                            @click="issueQuickAction('pterygium-prescription')">
                        <i class="fas fa-eye-low-vision" style="font-size:1.6rem;color:#ff5722;"></i>
                        <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Receituário<br>Pterígio</span>
                    </button>
                    <button type="button" class="btn pmr-doc-img-btn"
                            :title="isEdit ? 'Receituário de Catarata' : tt('save_first', 'Salve primeiro')"
                            :disabled="!isEdit || quickActionBusy || isLocked"
                            @click="openCataractPrescription">
                        <i class="fas fa-eye" style="font-size:1.6rem;color:#00bcd4;"></i>
                        <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Receituário<br>Catarata</span>
                    </button>
                    <button type="button" class="btn pmr-doc-img-btn"
                            :title="isEdit ? 'Teste do Olhinho' : tt('save_first', 'Salve primeiro')"
                            :disabled="!isEdit || quickActionBusy || isLocked"
                            @click="issueQuickAction('test-eye')">
                        <i class="fas fa-baby" style="font-size:1.6rem;color:#e91e63;"></i>
                        <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Teste do<br>Olhinho</span>
                    </button>
                    <button type="button" class="btn pmr-doc-img-btn"
                            :title="isEdit ? 'Mapeamento de Retina' : tt('save_first', 'Salve primeiro')"
                            :disabled="!isEdit || quickActionBusy || isLocked"
                            @click="issueQuickAction('retinal-mapping')">
                        <i class="fas fa-bullseye" style="font-size:1.6rem;color:#673ab7;"></i>
                        <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Mapeamento<br>de Retina</span>
                    </button>
                    <button type="button" class="btn pmr-doc-img-btn"
                            :title="isEdit ? 'Atestado de Comparecimento' : tt('save_first', 'Salve primeiro')"
                            :disabled="!isEdit || quickActionBusy || isLocked"
                            @click="openAttendanceCertificate">
                        <i class="fas fa-user-check" style="font-size:1.6rem;color:#4caf50;"></i>
                        <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Atestado<br>Comparecim.</span>
                    </button>
                    <button type="button" class="btn pmr-doc-img-btn"
                            :title="isEdit ? 'Atestado Médico' : tt('save_first', 'Salve primeiro')"
                            :disabled="!isEdit || quickActionBusy || isLocked"
                            @click="openMedicalCertificate">
                        <i class="fas fa-stethoscope" style="font-size:1.6rem;color:#2196f3;"></i>
                        <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Atestado<br>Médico</span>
                    </button>
                </template>

                <button v-if="isDoctor && examReports.length > 0" type="button" class="btn pmr-doc-img-btn"
                        :title="isEdit ? tt('exam_hub_title', 'Laudos de Exame') : tt('save_first', 'Salve primeiro')"
                        :disabled="!isEdit || isLocked"
                        @click="showExamHubModal = true">
                    <i class="fas fa-microscope" style="font-size:1.6rem;color:#03a9f3;"></i>
                    <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Laudos<br>de Exame</span>
                </button>

                <!-- Evolução: histórico é por paciente, então abre mesmo em modo
                     create (leitura); gravar exige prontuário salvo + médico. -->
                <button type="button" class="btn pmr-doc-img-btn"
                        :title="tt('evolution', 'Evolução')"
                        @click="openEvolutionModal">
                    <i class="fas fa-notes-medical" style="font-size:1.6rem;color:#009688;"></i>
                    <span class="pmr-doc-img-btn-label">{{ tt('evolution', 'Evolução') }}</span>
                </button>

                <button type="button" class="btn pmr-doc-img-btn pmr-doc-img-btn-wide"
                        :title="isEdit ? tt('documentations', 'Documentações') : tt('save_first', 'Salve primeiro')"
                        :disabled="!isEdit"
                        @click="openDocumentationsModal">
                    <i class="fas fa-folder-open" style="font-size:1.6rem;color:#0288d1;"></i>
                    <span class="pmr-doc-img-btn-label">{{ tt('documentations', 'Documentações') }}</span>
                </button>

                <!-- Anexo — abre modal com drag-drop + progresso por arquivo -->
                <button v-if="isDoctor && isEdit"
                        type="button"
                        class="btn pmr-doc-img-btn pmr-doc-annexo"
                        :title="tt('upload_files', 'Anexar arquivos')"
                        :disabled="isLocked"
                        @click="openUploadModal">
                    <i class="fas fa-paperclip" style="font-size:1.6rem;color:#607d8b;"></i>
                    <span class="pmr-doc-img-btn-label">Anexo</span>
                </button>

                <button type="submit" class="btn pmr-save-btn ms-auto"
                        :disabled="form.processing || isLocked" :title="tt('save', 'Salvar')">
                    <i class="fas fa-check-circle"></i>
                </button>
            </div>
        </div>

        <!-- Lista de arquivos anexados (edit) -->
        <div v-if="isEdit && uploadedFiles.length > 0" class="px-3 pb-2">
            <div class="row g-1">
                <div v-for="f in uploadedFiles" :key="f.id" class="col-auto">
                    <a :href="f.show_url" target="_blank" class="pmr-file-thumb" :title="f.original_name">
                        <img v-if="f.is_image" :src="f.show_url" :alt="f.original_name">
                        <i v-else class="fas fa-file-alt"></i>
                    </a>
                </div>
            </div>
        </div>
    </form>

    <!-- ───────────────────────────── MODAIS ───────────────────────────── -->

    <!-- Documentações: lista -->
    <Teleport to="body">
        <div v-if="showDocumentationsModal" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);"
             @click.self="showDocumentationsModal = false">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title"><i class="fas fa-folder-open me-2" style="color:#0288d1;"></i>{{ tt('documentations', 'Documentações') }}</h6>
                        <button type="button" class="btn-close" @click="showDocumentationsModal = false"></button>
                    </div>
                    <div class="modal-body p-2">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr><th>{{ tt('doc_type','Tipo') }}</th><th>{{ tt('doc_title','Título') }}</th><th>{{ tt('doc_date','Data') }}</th><th class="text-end">{{ tt('doc_actions','Ações') }}</th></tr>
                            </thead>
                            <tbody>
                                <tr v-if="documentations.length === 0">
                                    <td colspan="4" class="text-center text-muted small py-2">{{ tt('no_documentations', 'Nenhuma documentação registrada.') }}</td>
                                </tr>
                                <tr v-for="doc in documentations" :key="doc.id">
                                    <td>
                                        <span class="badge bg-info-subtle text-info">{{ doc.type_label }}</span>
                                        <span v-if="doc.is_ai" class="badge bg-info text-dark ms-1"
                                              :title="doc.ai_workflow_label || 'Gerado por IA'">
                                            <i class="ti ti-robot me-1"></i>IA
                                        </span>
                                    </td>
                                    <td>{{ doc.title }}</td>
                                    <td>{{ doc.created_at }}</td>
                                    <td class="text-end">
                                        <a :href="doc.pdf_url" target="_blank" class="btn btn-outline-secondary btn-sm" title="PDF">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="modal-footer py-2 d-flex justify-content-between">
                        <!--
                            "Nova documentação" cria via store (exige IssueReport). Admin/secretária
                            só pode listar e baixar PDFs (read-only). Esconder o botão evita 403 ao clicar.
                        -->
                        <button v-if="isEdit && isDoctor" type="button" class="btn btn-primary btn-sm" @click="openNewDoc">
                            <i class="fas fa-plus me-1"></i>{{ tt('new_documentation', 'Nova documentação') }}
                        </button>
                        <span v-else></span>
                        <button type="button" class="btn btn-secondary btn-sm" @click="showDocumentationsModal = false">{{ tt('close','Fechar') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Personalizar meu prontuário: visibilidade + ordem das seções por coluna -->
    <Teleport to="body">
        <div v-if="showLayoutModal" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);"
             @click.self="showLayoutModal = false">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title"><i class="fas fa-sliders me-2 text-primary"></i>{{ tt('customize_title', 'Personalizar meu prontuário') }}</h6>
                        <button type="button" class="btn-close" @click="showLayoutModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-3">
                            {{ tt('customize_hint', 'Escolha quais seções aparecem e a ordem em cada coluna. Seções ocultas não perdem dados já preenchidos — apenas saem da sua visualização.') }}
                        </p>
                        <div class="row g-3">
                            <div v-for="col in ['left', 'right']" :key="col" class="col-md-6">
                                <div class="fw-semibold small mb-2">
                                    {{ col === 'left' ? tt('left_column', 'Coluna esquerda') : tt('right_column', 'Coluna direita') }}
                                </div>
                                <div v-for="(key, index) in sectionLayout[col]" :key="key"
                                     class="d-flex align-items-center gap-2 border rounded px-2 py-1 mb-1"
                                     :class="{ 'opacity-50': sectionLayout.hidden.includes(key) }">
                                    <input type="checkbox" class="form-check-input m-0"
                                           :checked="!sectionLayout.hidden.includes(key)"
                                           :id="`layout-${key}`"
                                           @change="toggleSection(key)">
                                    <label :for="`layout-${key}`" class="flex-grow-1 small mb-0" style="cursor:pointer;">
                                        {{ sectionLabel(key) }}
                                    </label>
                                    <button type="button" class="btn btn-sm btn-link p-0 px-1"
                                            :disabled="index === 0"
                                            @click="moveSection(col, key, -1)">
                                        <i class="fas fa-chevron-up"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-link p-0 px-1"
                                            :disabled="index === sectionLayout[col].length - 1"
                                            @click="moveSection(col, key, 1)">
                                        <i class="fas fa-chevron-down"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer py-2 d-flex justify-content-between">
                        <span v-if="layoutSavedFlash" class="badge bg-success-subtle text-success">{{ layoutSavedFlash }}</span>
                        <span v-else></span>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-secondary btn-sm" @click="showLayoutModal = false">
                                {{ tt('close', 'Fechar') }}
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" :disabled="layoutSaving" @click="saveMyLayout">
                                <span v-if="layoutSaving" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="fas fa-floppy-disk me-1"></i>{{ tt('save_my_layout', 'Salvar como meu modelo') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Evolução: histórico cronológico + texto livre -->
    <Teleport to="body">
        <div v-if="showEvolutionModal" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);"
             @click.self="showEvolutionModal = false">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title"><i class="fas fa-notes-medical me-2" style="color:#009688;"></i>{{ tt('evolution', 'Evolução') }}</h6>
                        <button type="button" class="btn-close" @click="showEvolutionModal = false"></button>
                    </div>
                    <div class="modal-body p-3">
                        <!-- Nova evolução: só médico, com prontuário salvo e não assinado -->
                        <div v-if="isDoctor && !isLocked" class="mb-3">
                            <label class="pmr-label">{{ tt('evolution_new', 'Nova evolução') }}</label>
                            <textarea v-model="evolutionText" rows="4" class="form-control form-control-sm"
                                      :placeholder="tt('evolution_ph', 'Descreva a evolução clínica do paciente...')"
                                      :disabled="evolutionBusy || !isEdit"></textarea>
                            <div class="d-flex justify-content-between align-items-center mt-1">
                                <small v-if="!isEdit" class="text-muted">{{ tt('save_first', 'Salve primeiro o prontuário') }}</small>
                                <span v-else></span>
                                <button type="button" class="btn btn-primary btn-sm"
                                        :disabled="!isEdit || evolutionBusy || !evolutionText.trim()"
                                        @click="saveEvolution">
                                    <span v-if="evolutionBusy" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="fas fa-plus me-1"></i>{{ tt('evolution_save', 'Registrar evolução') }}
                                </button>
                            </div>
                        </div>

                        <!-- Histórico cronológico (mais recente primeiro) -->
                        <label class="pmr-label">{{ tt('evolution_history', 'Histórico de evoluções') }}</label>
                        <div v-if="evolutions.length === 0" class="text-center text-muted small py-3">
                            {{ tt('no_evolutions', 'Nenhuma evolução registrada para este paciente.') }}
                        </div>
                        <div v-else class="d-flex flex-column gap-2">
                            <div v-for="ev in evolutions" :key="ev.id" class="border rounded p-2 bg-light">
                                <div class="d-flex justify-content-between flex-wrap gap-1 mb-1">
                                    <span class="fw-semibold" style="font-size:.82rem;color:#00695c;">
                                        <i class="fas fa-user-md me-1"></i>{{ ev.doctor_name || '—' }}
                                    </span>
                                    <span class="text-muted" style="font-size:.78rem;">
                                        <i class="far fa-clock me-1"></i>{{ ev.created_at }}
                                    </span>
                                </div>
                                <div style="font-size:.85rem;white-space:pre-wrap;">{{ ev.content }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary btn-sm" @click="showEvolutionModal = false">{{ tt('close','Fechar') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Doc modal (TinyMCE simplificado) -->
    <Teleport to="body">
        <div v-if="showDocModal" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);"
             @click.self="showDocModal = false">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title"><i class="fas fa-file-prescription me-2"></i>{{ tt('new_documentation','Nova documentação') }}</h6>
                        <button type="button" class="btn-close" @click="showDocModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-2 mb-3">
                            <div v-if="docForm.exam_type" class="col-12">
                                <span class="badge bg-info-subtle text-info">
                                    <i class="fas fa-microscope me-1"></i>{{ docForm.exam_label || docForm.exam_type }}
                                </span>
                            </div>
                            <template v-if="!docForm.exam_type">
                                <div class="col-12 col-md-6">
                                    <label class="pmr-label">{{ tt('select_template','Modelo') }}</label>
                                    <select v-model="docForm.report_setting_content_id" class="form-select form-select-sm" @change="previewTemplate">
                                        <option value="">{{ tt('select','Selecione') }}</option>
                                        <optgroup v-for="group in docTemplates" :key="group.report_setting_id" :label="group.report_setting_title">
                                            <option v-for="tpl in (group.contents || [])" :key="tpl.id" :value="tpl.id">{{ tpl.label }}</option>
                                        </optgroup>
                                    </select>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="pmr-label">{{ tt('doc_title','Título') }}</label>
                                    <input v-model="docForm.title" type="text" class="form-control form-control-sm">
                                </div>
                            </template>
                        </div>
                        <div class="mb-0">
                            <label class="pmr-label">{{ tt('doc_content','Conteúdo') }}</label>
                            <!-- TinyMCE rich-text editor (paridade com docModalEditor.js do legado) -->
                            <TinyMceEditor
                                v-if="showDocModal"
                                :key="`doc-${docForm.report_setting_content_id || docForm.exam_type || 'new'}`"
                                v-model="docForm.content"
                                :height="360"
                                :placeholder="tt('doc_content_ph', 'Conteúdo da documentação...')"
                            />
                        </div>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary btn-sm" @click="showDocModal = false">{{ tt('cancel','Cancelar') }}</button>
                        <button type="button" class="btn btn-primary btn-sm" :disabled="docSaving" @click="saveDoc">
                            <i class="fas fa-save me-1"></i>{{ tt('save_documentation','Salvar documentação') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Medicamentos modal -->
    <Teleport to="body">
        <div v-if="showMedicationModal" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);"
             @click.self="showMedicationModal = false">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title"><i class="fas fa-prescription me-2" style="color:#e91e8c;"></i>Receituário de Medicamentos</h6>
                        <button type="button" class="btn-close" @click="showMedicationModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Abas: Buscar | Recentes | Favoritos -->
                        <ul class="nav nav-pills nav-sm mb-2 gap-1">
                            <li class="nav-item">
                                <button type="button" class="nav-link py-1 px-2" style="font-size:.8rem;"
                                        :class="{ active: medTab === 'search' }" @click="medTab = 'search'">
                                    <i class="fas fa-search me-1"></i>Buscar
                                </button>
                            </li>
                            <li class="nav-item">
                                <button type="button" class="nav-link py-1 px-2" style="font-size:.8rem;"
                                        :class="{ active: medTab === 'recents' }" @click="medTab = 'recents'">
                                    <i class="fas fa-clock-rotate-left me-1"></i>Recentes
                                </button>
                            </li>
                            <li class="nav-item">
                                <button type="button" class="nav-link py-1 px-2" style="font-size:.8rem;"
                                        :class="{ active: medTab === 'favorites' }" @click="medTab = 'favorites'">
                                    <i class="fas fa-star me-1"></i>Favoritos
                                </button>
                            </li>
                        </ul>

                        <!-- Buscar -->
                        <div v-show="medTab === 'search'" class="position-relative mb-2">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input v-model="medSearchQuery" type="text" class="form-control form-control-sm"
                                       placeholder="Digite ao menos 2 letras…"
                                       :disabled="prescription.length >= maxMedicines"
                                       @input="searchMedicines">
                                <span v-if="medSearchLoading" class="input-group-text bg-transparent">
                                    <span class="spinner-border spinner-border-sm" style="width:.8rem;height:.8rem;"></span>
                                </span>
                            </div>
                            <ul v-if="medSearchOpen && medSearchResults.length > 0"
                                class="list-group shadow-sm position-absolute w-100"
                                style="z-index:1080;top:100%;max-height:280px;overflow-y:auto;">
                                <li v-for="item in medSearchResults" :key="item.id"
                                    class="list-group-item list-group-item-action py-1 px-2" style="cursor:pointer;font-size:.82rem;"
                                    @mousedown.prevent="selectMedicine(item)">
                                    <i v-if="item.is_favorite" class="fas fa-star text-warning me-1" style="font-size:.7rem;"></i>
                                    <span class="fw-semibold">{{ item.name }}</span>
                                    <span v-if="item.presentation" class="text-muted ms-1">({{ item.presentation }})</span>
                                    <span v-if="item.my_posology" class="badge bg-primary-subtle text-primary ms-1" style="font-size:.6rem;">minha posologia</span>
                                </li>
                            </ul>
                        </div>

                        <!-- Recentes / Favoritos -->
                        <div v-show="medTab !== 'search'" class="mb-2">
                            <ul v-if="(medTab === 'recents' ? medPresets.recents : medPresets.favorites).length > 0"
                                class="list-group" style="max-height:220px;overflow-y:auto;">
                                <li v-for="item in (medTab === 'recents' ? medPresets.recents : medPresets.favorites)" :key="item.id"
                                    class="list-group-item list-group-item-action d-flex align-items-center py-1 px-2"
                                    style="cursor:pointer;font-size:.82rem;"
                                    @click="selectMedicine(item)">
                                    <span class="flex-grow-1">
                                        <span class="fw-semibold">{{ item.name }}</span>
                                        <span v-if="item.presentation" class="text-muted ms-1">({{ item.presentation }})</span>
                                        <span v-if="item.my_posology" class="badge bg-primary-subtle text-primary ms-1" style="font-size:.6rem;">minha posologia</span>
                                    </span>
                                    <button type="button" class="btn btn-sm btn-link p-0"
                                            :title="item.is_favorite ? 'Remover dos favoritos' : 'Adicionar aos favoritos'"
                                            @click.stop="toggleMedFavorite(item)">
                                        <i class="fa-star" :class="item.is_favorite ? 'fas text-warning' : 'far text-muted'"></i>
                                    </button>
                                </li>
                            </ul>
                            <p v-else class="text-muted small mb-0 py-2">
                                <i class="fas fa-info-circle me-1"></i>
                                {{ medTab === 'recents' ? 'Medicamentos que você prescrever aparecem aqui.' : 'Marque a estrela num medicamento para tê-lo sempre à mão.' }}
                            </p>
                        </div>

                        <!-- Seleção: sugestão de posologia (editável — nada entra
                             na receita sem confirmação do médico) -->
                        <div v-if="selectedMed" class="border rounded p-2 mb-2 bg-light">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="fw-semibold" style="font-size:.85rem;">
                                    {{ selectedMed.name }}
                                    <span v-if="selectedMed.presentation" class="text-muted">({{ selectedMed.presentation }})</span>
                                </span>
                                <button type="button" class="btn btn-sm btn-link p-0"
                                        :title="selectedMed.is_favorite ? 'Remover dos favoritos' : 'Adicionar aos favoritos'"
                                        @click="toggleMedFavorite(selectedMed)">
                                    <i class="fa-star" :class="selectedMed.is_favorite ? 'fas text-warning' : 'far text-muted'"></i>
                                </button>
                                <button type="button" class="btn-close ms-auto" style="font-size:.6rem;" @click="cancelSelection"></button>
                            </div>
                            <label class="pmr-label mb-1" style="font-size:.72rem;">
                                Posologia sugerida — revise e edite antes de adicionar
                                <span v-if="selectedMed.my_posology" class="badge bg-primary-subtle text-primary ms-1" style="font-size:.6rem;">sua posologia salva</span>
                            </label>
                            <textarea v-model="posologyDraft" class="form-control form-control-sm" rows="3"
                                      placeholder="Dose, frequência, duração e orientações…"></textarea>
                            <div class="d-flex align-items-center gap-2 mt-2 flex-wrap">
                                <button type="button" class="btn btn-primary btn-sm" @click="confirmAddMedicine">
                                    <i class="fas fa-plus me-1"></i>Adicionar à receita
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                        :disabled="posologySaving" @click="saveMyPosology">
                                    <span v-if="posologySaving" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="fas fa-bookmark me-1"></i>Salvar como minha posologia
                                </button>
                                <span v-if="posologySavedFlash" class="badge bg-success-subtle text-success">Posologia salva!</span>
                            </div>
                        </div>

                        <div v-if="prescription.length >= maxMedicines" class="alert alert-warning py-1 px-2 small mb-2">
                            <i class="fas fa-exclamation-triangle me-1"></i>Limite de {{ maxMedicines }} medicamentos atingido.
                        </div>
                        <div v-if="prescription.length > 0" class="mb-2">
                            <label class="pmr-label mb-1">Medicamentos da receita ({{ prescription.length }}/{{ maxMedicines }})</label>
                            <ul class="list-group">
                                <li v-for="(item, idx) in prescription" :key="`${item.id}-${idx}`"
                                    class="list-group-item d-flex justify-content-between align-items-center py-1 px-2" style="font-size:.82rem;">
                                    <span><span class="badge bg-secondary me-1">{{ idx + 1 }}</span><span class="fw-semibold">{{ item.name }}</span></span>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" @click="removeMedicine(idx)"><i class="fas fa-times"></i></button>
                                </li>
                            </ul>
                        </div>
                        <label class="pmr-label mb-1">Conteúdo da receita</label>
                        <textarea v-model="medicineLists" class="form-control form-control-sm" rows="10"
                                  placeholder="Linhas formatadas aparecem aqui."></textarea>
                    </div>
                    <div class="modal-footer py-2 d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="prescription.length === 0 && !medicineLists" @click="clearMedicines">
                            <i class="fas fa-eraser me-1"></i>Limpar
                        </button>
                        <div>
                            <button type="button" class="btn btn-secondary btn-sm" @click="showMedicationModal = false">{{ tt('cancel','Cancelar') }}</button>
                            <button type="button" class="btn btn-primary btn-sm" :disabled="quickActionBusy || !medicineLists.trim()" @click="submitMedicationPrescription">
                                <i class="fas fa-print me-1"></i>Emitir Receita
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Procedimentos modal -->
    <Teleport to="body">
        <div v-if="showProcedureModal" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);"
             @click.self="showProcedureModal = false">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title"><i class="fas fa-stethoscope me-2" style="color:#03a9f3;"></i>{{ tt('procedure_title','Solicitação de Procedimentos') }}</h6>
                        <button type="button" class="btn-close" @click="showProcedureModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-2 mb-2">
                            <div class="col-12 col-md-7">
                                <label class="pmr-label mb-1">{{ tt('procedure_search_label','Procedimento') }}</label>
                                <div class="position-relative">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                        <input v-model="procSearchQuery" type="text" class="form-control form-control-sm"
                                               :placeholder="tt('procedure_search_ph','Buscar procedimento...')"
                                               :disabled="procSelected.length + indSelected.length >= maxProcSolicitations"
                                               @input="searchProcedures">
                                        <span v-if="procSearchLoading" class="input-group-text bg-transparent">
                                            <span class="spinner-border spinner-border-sm" style="width:.8rem;height:.8rem;"></span>
                                        </span>
                                    </div>
                                    <ul v-if="procSearchOpen && procSearchResults.length > 0"
                                        class="list-group shadow-sm position-absolute w-100"
                                        style="z-index:1080;top:100%;max-height:240px;overflow-y:auto;">
                                        <li v-for="item in procSearchResults" :key="item.id"
                                            class="list-group-item list-group-item-action py-1 px-2" style="cursor:pointer;font-size:.82rem;"
                                            @mousedown.prevent="addProcedure(item)">
                                            <span class="fw-semibold">{{ item.name }}</span>
                                            <span v-if="item.code" class="text-muted ms-1 small">({{ item.code }})</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            <div class="col-12 col-md-5">
                                <label class="pmr-label mb-1">{{ tt('procedure_type_label','Tipo') }}</label>
                                <SearchSelect v-model="procTypeSelected"
                                              :options="[
                                                  { value: 'rotina',      label: tt('procedure_type_rotina','Rotina') },
                                                  { value: 'urgencia',    label: tt('procedure_type_urgencia','Urgência') },
                                                  { value: 'controle',    label: tt('procedure_type_controle','Controle') },
                                                  { value: 'comparativo', label: tt('procedure_type_comparativo','Comparativo') },
                                              ]"
                                              :value-key="'value'" :label-key="'label'"
                                              :placeholder="'—'" />
                            </div>
                        </div>

                        <label class="pmr-label mb-1">{{ tt('procedure_indication_label','Indicação') }}</label>
                        <div class="position-relative mb-2">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input v-model="indSearchQuery" type="text" class="form-control form-control-sm"
                                       :placeholder="tt('procedure_indication_ph','Buscar indicação...')"
                                       :disabled="procSelected.length + indSelected.length >= maxProcSolicitations"
                                       @input="searchIndications">
                                <span v-if="indSearchLoading" class="input-group-text bg-transparent">
                                    <span class="spinner-border spinner-border-sm" style="width:.8rem;height:.8rem;"></span>
                                </span>
                            </div>
                            <ul v-if="indSearchOpen && indSearchResults.length > 0"
                                class="list-group shadow-sm position-absolute w-100"
                                style="z-index:1080;top:100%;max-height:240px;overflow-y:auto;">
                                <li v-for="item in indSearchResults" :key="item.id"
                                    class="list-group-item list-group-item-action py-1 px-2" style="cursor:pointer;font-size:.82rem;"
                                    @mousedown.prevent="addIndication(item)">{{ item.description }}</li>
                            </ul>
                        </div>

                        <div v-if="procSelected.length + indSelected.length > 0" class="mb-2">
                            <label class="pmr-label mb-1">{{ tt('procedure_selected','Selecionados') }} ({{ procSelected.length + indSelected.length }}/{{ maxProcSolicitations }})</label>
                            <ul class="list-group">
                                <li v-for="(item, idx) in procSelected" :key="`p-${item.id}-${idx}`"
                                    class="list-group-item d-flex justify-content-between align-items-center py-1 px-2" style="font-size:.82rem;">
                                    <span><span class="badge bg-info text-dark me-1">P</span><span class="fw-semibold">{{ item.name }}</span>
                                          <span v-if="item.type" class="text-muted ms-1 small">— {{ item.type_label }}</span></span>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" @click="removeProcedure(idx)"><i class="fas fa-times"></i></button>
                                </li>
                                <li v-for="(item, idx) in indSelected" :key="`i-${item.id}-${idx}`"
                                    class="list-group-item d-flex justify-content-between align-items-center py-1 px-2" style="font-size:.82rem;">
                                    <span><span class="badge bg-secondary me-1">I</span>{{ item.description }}</span>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" @click="removeIndication(idx)"><i class="fas fa-times"></i></button>
                                </li>
                            </ul>
                        </div>

                        <label class="pmr-label mb-1">{{ tt('procedure_content','Conteúdo') }}</label>
                        <textarea v-model="procedureLists" class="form-control form-control-sm" rows="8"
                                  placeholder="Linhas formatadas aparecem aqui."></textarea>
                    </div>
                    <div class="modal-footer py-2 d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                :disabled="procSelected.length === 0 && indSelected.length === 0 && !procedureLists"
                                @click="clearProcedureSolicitation">
                            <i class="fas fa-eraser me-1"></i>Limpar
                        </button>
                        <div>
                            <button type="button" class="btn btn-secondary btn-sm" @click="showProcedureModal = false">{{ tt('cancel','Cancelar') }}</button>
                            <button type="button" class="btn btn-primary btn-sm" :disabled="quickActionBusy || !procedureLists.trim()" @click="submitProcedureSolicitation">
                                <i class="fas fa-print me-1"></i>{{ tt('procedure_emit','Emitir Solicitação') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Catarata modal -->
    <Teleport to="body">
        <div v-if="showCataractModal" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);"
             @click.self="showCataractModal = false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title"><i class="fas fa-eye me-2" style="color:#e91e8c;"></i>{{ tt('cataract_title','Receituário de Catarata') }}</h6>
                        <button type="button" class="btn-close" @click="showCataractModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <label class="pmr-label mb-1">{{ tt('cataract_eye_label','Olho operado') }}</label>
                        <div class="btn-group w-100 mb-3" role="group">
                            <input type="radio" class="btn-check" id="cataractEyeRight" value="right" v-model="cataractForm.eye">
                            <label class="btn btn-outline-primary btn-sm" for="cataractEyeRight">{{ tt('cataract_eye_right','OD') }}</label>
                            <input type="radio" class="btn-check" id="cataractEyeLeft" value="left" v-model="cataractForm.eye">
                            <label class="btn btn-outline-primary btn-sm" for="cataractEyeLeft">{{ tt('cataract_eye_left','OE') }}</label>
                            <input type="radio" class="btn-check" id="cataractEyeBoth" value="both" v-model="cataractForm.eye">
                            <label class="btn btn-outline-primary btn-sm" for="cataractEyeBoth">{{ tt('cataract_eye_both','AO') }}</label>
                        </div>
                        <label class="pmr-label mb-1">{{ tt('cataract_template','Modelo') }}</label>
                        <SearchSelect v-model="cataractForm.template"
                                      class="mb-3"
                                      :options="[
                                          { value: 'pre_operatorio',        label: tt('cataract_template_pre','Pré-operatório') },
                                          { value: 'pos_operatorio',        label: tt('cataract_template_pos','Pós-operatório') },
                                          { value: 'instrucoes_cirurgicas', label: tt('cataract_template_inst','Instruções cirúrgicas') },
                                      ]"
                                      :value-key="'value'" :label-key="'label'"
                                      :clearable="false" />
                        <div class="row g-2 mb-1" :class="{ 'opacity-75': cataractForm.template !== 'instrucoes_cirurgicas' }">
                            <div class="col-7">
                                <label class="pmr-label mb-1">{{ tt('cataract_date','Data') }}</label>
                                <input v-model="cataractForm.date_surgery" type="text" class="form-control form-control-sm"
                                       placeholder="dd/mm/aaaa" maxlength="10" @input="formatCataractDate">
                            </div>
                            <div class="col-5">
                                <label class="pmr-label mb-1">{{ tt('cataract_hour','Hora') }}</label>
                                <input v-model="cataractForm.hour_surgery" type="time" class="form-control form-control-sm">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary btn-sm" @click="showCataractModal = false">{{ tt('cancel','Cancelar') }}</button>
                        <button type="button" class="btn btn-primary btn-sm" :disabled="quickActionBusy || !cataractForm.eye" @click="submitCataractPrescription">
                            <i class="fas fa-print me-1"></i>{{ tt('cataract_emit','Emitir') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Atestado de comparecimento -->
    <Teleport to="body">
        <div v-if="showAttendanceCertModal" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);"
             @click.self="showAttendanceCertModal = false">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title"><i class="fas fa-file-signature me-2" style="color:#03a9f3;"></i>{{ tt('attendance_certificate_title','Atestado de Comparecimento') }}</h6>
                        <button type="button" class="btn-close" @click="showAttendanceCertModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <label class="pmr-label mb-1">{{ tt('certificate_obs_label','Observações') }}</label>
                        <!--
                            TinyMCE rich-text para o atestado: médico pode formatar
                            o texto (negrito, listas, etc.) — o backend renderiza
                            no PDF mantendo a formatação HTML.
                            `v-if` no modal já desmonta/remonta o editor entre aberturas
                            (limpa instâncias e evita memory leak).
                        -->
                        <TinyMceEditor
                            v-model="attendanceForm.content"
                            :height="280"
                            :placeholder="tt('certificate_obs_ph','Observações...')"
                        />
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary btn-sm" @click="showAttendanceCertModal = false">{{ tt('cancel','Cancelar') }}</button>
                        <button type="button" class="btn btn-primary btn-sm" :disabled="quickActionBusy" @click="submitAttendanceCertificate">
                            <i class="fas fa-print me-1"></i>{{ tt('certificate_emit','Emitir') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Atestado médico (afastamento) -->
    <Teleport to="body">
        <div v-if="showMedicalCertModal" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);"
             @click.self="showMedicalCertModal = false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title"><i class="fas fa-notes-medical me-2" style="color:#e91e8c;"></i>{{ tt('medical_certificate_title','Atestado Médico') }}</h6>
                        <button type="button" class="btn-close" @click="showMedicalCertModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-2 mb-2">
                            <div class="col-7">
                                <label class="pmr-label mb-1">{{ tt('medical_cert_days_label','Dias de afastamento') }}</label>
                                <input v-model.number="medicalForm.days" type="number" min="1" max="365" step="1"
                                       class="form-control form-control-sm" @input="debouncedRefreshDayExtension">
                            </div>
                            <div class="col-5">
                                <label class="pmr-label mb-1">{{ tt('medical_cert_date_label','Data') }}</label>
                                <input v-model="medicalForm.date" type="text" class="form-control form-control-sm"
                                       placeholder="dd/mm/aaaa" maxlength="10" @input="formatMedicalCertDate">
                                <button type="button" class="btn btn-link btn-sm p-0 mt-1" @click="medicalForm.date = ''">
                                    <small>{{ tt('medical_cert_date_today','Hoje') }}</small>
                                </button>
                            </div>
                        </div>
                        <div v-if="medicalForm.daysPreview" class="alert alert-info py-2 px-2 small mb-2">
                            <i class="fas fa-eye me-1"></i>
                            <strong>{{ tt('medical_cert_days_preview','Pré-visualização') }}:</strong>
                            {{ medicalForm.daysPreview }}
                        </div>
                        <label class="pmr-label mb-1">{{ tt('certificate_obs_label','Observações') }}</label>
                        <textarea v-model="medicalForm.content" class="form-control form-control-sm" rows="5" maxlength="5000"
                                  :placeholder="tt('certificate_obs_ph','Observações...')"></textarea>
                        <small class="text-muted d-block mt-1">{{ (medicalForm.content || '').length }}/5000</small>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary btn-sm" @click="showMedicalCertModal = false">{{ tt('cancel','Cancelar') }}</button>
                        <button type="button" class="btn btn-primary btn-sm"
                                :disabled="quickActionBusy || !medicalForm.days || medicalForm.days < 1 || medicalForm.days > 365"
                                @click="submitMedicalCertificate">
                            <i class="fas fa-print me-1"></i>{{ tt('certificate_emit','Emitir') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Exam Hub -->
    <Teleport to="body">
        <div v-if="showExamHubModal" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);"
             @click.self="showExamHubModal = false">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title"><i class="fas fa-microscope me-2" style="color:#03a9f3;"></i>{{ tt('exam_hub_title','Laudos de Exame') }}</h6>
                        <button type="button" class="btn-close" @click="showExamHubModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-3">{{ tt('exam_hub_help','Selecione o exame para emitir o laudo.') }}</p>
                        <div class="row g-2">
                            <div v-for="exam in examReports" :key="exam.value" class="col-6 col-md-4 col-lg-3">
                                <button v-if="!exam.subtypes || exam.subtypes.length === 0"
                                        type="button" class="btn pmr-exam-card w-100 h-100"
                                        @click="openExamFromHub(exam.value)" :title="exam.label">
                                    <i :class="`fas ${exam.icon} pmr-exam-card-icon`"></i>
                                    <span class="pmr-exam-card-label">{{ exam.label }}</span>
                                </button>
                                <div v-else class="dropdown w-100 h-100">
                                    <button type="button" class="btn pmr-exam-card pmr-exam-card-multi w-100 h-100 dropdown-toggle"
                                            data-bs-toggle="dropdown" :title="exam.label">
                                        <i :class="`fas ${exam.icon} pmr-exam-card-icon`"></i>
                                        <span class="pmr-exam-card-label">{{ exam.label }}</span>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li v-for="sub in exam.subtypes" :key="sub.slug">
                                            <button type="button" class="dropdown-item" @click="openExamFromHub(exam.value, sub.slug)">
                                                <i class="fas fa-angle-right me-2 text-muted small"></i>{{ sub.label }}
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary btn-sm" @click="showExamHubModal = false">{{ tt('cancel','Cancelar') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Tonometria PDF modal -->
    <Teleport to="body">
        <div v-if="showTonometryModal" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);"
             @keydown.escape.window="closeTonometry">
            <div class="modal-dialog modal-xl modal-dialog-centered" style="height:90vh;">
                <div class="modal-content" style="height:100%;">
                    <div class="modal-header py-2">
                        <h6 class="modal-title mb-0"><i class="fas fa-print me-2" style="color:#e91e8c;"></i>{{ tt('print_tonometry','Laudo de Tonômetria') }}</h6>
                        <button type="button" class="btn-close" @click="closeTonometry"></button>
                    </div>
                    <div class="modal-body p-0" style="flex:1;overflow:hidden;">
                        <iframe :src="tonometryPdfSrc" style="width:100%;height:100%;border:none;display:block;" title="Laudo de Tonômetria"></iframe>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Presbiopia obs modal -->
    <Teleport to="body">
        <div v-if="showPresbyopiaObsModal" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);"
             @click.self="showPresbyopiaObsModal = false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title"><i class="fas fa-glasses me-2" style="color:#00bcd4;"></i>{{ tt('lenses_obs','Observação de lentes') }}</h6>
                        <button type="button" class="btn-close" @click="showPresbyopiaObsModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <textarea v-model="presbyopiaObsForm.content" class="form-control form-control-sm" rows="6"
                                  :placeholder="tt('presbyopia_obs_ph','Observações sobre as lentes...')"></textarea>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary btn-sm" @click="showPresbyopiaObsModal = false">{{ tt('cancel','Cancelar') }}</button>
                        <button type="button" class="btn btn-primary btn-sm" @click="confirmPresbyopiaCalc">
                            <i class="fas fa-check me-1"></i>{{ tt('close','Confirmar') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- PDF preview universal -->
    <PdfPreviewModal v-if="showPdfPreview" :url="pdfPreviewUrl" :title="pdfPreviewTitle" @close="closePdfPreview" />

    <!-- Modal de upload de anexos (drag-drop + progresso por arquivo) -->
    <MedicalRecordFileUploadModal
        v-if="isEdit && urls.store_file"
        :show="showUploadModal"
        :store-url="urls.store_file"
        :storage="storageState"
        :csrf-token="csrf()"
        @close="showUploadModal = false"
        @uploaded="onFileUploaded"
        @storage-updated="onStorageUpdated" />
</template>
