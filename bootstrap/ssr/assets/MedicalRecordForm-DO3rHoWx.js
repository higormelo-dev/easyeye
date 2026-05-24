import { ref, onMounted, nextTick, onBeforeUnmount, watch, mergeProps, useSSRContext, computed, reactive, unref } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderList, ssrRenderAttr, ssrRenderClass, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderStyle, ssrRenderTeleport, ssrRenderComponent } from "vue/server-renderer";
import { useForm } from "@inertiajs/vue3";
import _sfc_main$2 from "./PdfPreviewModal-BGdxaBML.js";
import MedicalRecordFileUploadModal from "./MedicalRecordFileUploadModal-MXGxLznw.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main$1 = {
  __name: "TinyMceEditor",
  __ssrInlineRender: true,
  props: {
    modelValue: { type: String, default: "" },
    height: { type: [Number, String], default: 320 },
    placeholder: { type: String, default: "" },
    disabled: { type: Boolean, default: false },
    /** Toolbar customizada — se vazio usa default clínico (bold/lista/link/undo). */
    toolbar: { type: String, default: "" }
  },
  emits: ["update:modelValue"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const textareaRef = ref(null);
    let editorInstance = null;
    let syncing = false;
    function ensureTinyMceLoaded() {
      return new Promise((resolve, reject) => {
        if (window.tinymce) return resolve(window.tinymce);
        if (window.__tinymceLoading) {
          window.__tinymceLoading.then(resolve, reject);
          return;
        }
        const promise = new Promise((res, rej) => {
          const s = document.createElement("script");
          s.src = "/vendor/tinymce/tinymce.min.js";
          s.referrerPolicy = "origin";
          s.onload = () => res(window.tinymce);
          s.onerror = () => rej(new Error("Falha ao carregar TinyMCE"));
          document.head.appendChild(s);
        });
        window.__tinymceLoading = promise;
        promise.then(resolve, reject);
      });
    }
    async function initEditor() {
      const tinymce = await ensureTinyMceLoaded();
      if (!textareaRef.value) return;
      const defaultToolbar = "undo redo | blocks | bold italic underline | bullist numlist | alignleft aligncenter alignright | link | removeformat | code";
      const editors = await tinymce.init({
        target: textareaRef.value,
        height: typeof props.height === "number" ? props.height : Number(props.height) || 320,
        menubar: false,
        statusbar: false,
        branding: false,
        promotion: false,
        license_key: "gpl",
        base_url: "/vendor/tinymce",
        suffix: ".min",
        language: "pt_BR",
        language_url: "/vendor/tinymce-langs/langs7/pt_BR.js",
        placeholder: props.placeholder,
        plugins: ["lists", "link", "code", "autolink"],
        toolbar: props.toolbar || defaultToolbar,
        content_style: 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;font-size:.9rem;line-height:1.5;color:#1f2937;padding:.6rem;}',
        skin: "oxide",
        content_css: "default",
        setup: (editor) => {
          editor.on("init", () => {
            editor.setContent(props.modelValue ?? "");
            if (props.disabled) editor.mode.set("readonly");
          });
          editor.on("input change keyup undo redo", () => {
            if (syncing) return;
            const html = editor.getContent();
            emit("update:modelValue", html);
          });
        }
      });
      editorInstance = Array.isArray(editors) ? editors[0] : editors;
    }
    onMounted(async () => {
      await nextTick();
      initEditor();
    });
    onBeforeUnmount(() => {
      if (editorInstance) {
        try {
          editorInstance.remove();
        } catch (e) {
        }
        editorInstance = null;
      }
    });
    watch(() => props.modelValue, (v) => {
      if (!editorInstance) return;
      const current = editorInstance.getContent();
      if (current === (v ?? "")) return;
      syncing = true;
      editorInstance.setContent(v ?? "");
      syncing = false;
    });
    watch(() => props.disabled, (d) => {
      if (!editorInstance) return;
      editorInstance.mode.set(d ? "readonly" : "design");
    });
    return (_ctx, _push, _parent, _attrs) => {
      let _temp0;
      _push(`<textarea${ssrRenderAttrs(_temp0 = mergeProps({
        ref_key: "textareaRef",
        ref: textareaRef
      }, _attrs), "textarea")}>${ssrInterpolate("value" in _temp0 ? _temp0.value : "")}</textarea>`);
    };
  }
};
const _sfc_setup$1 = _sfc_main$1.setup;
_sfc_main$1.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/TinyMceEditor.vue");
  return _sfc_setup$1 ? _sfc_setup$1(props, ctx) : void 0;
};
const maxMedicines = 5;
const maxProcSolicitations = 10;
const _sfc_main = {
  __name: "MedicalRecordForm",
  __ssrInlineRender: true,
  props: {
    patient: { type: Object, required: true },
    medicalrecord: { type: Object, default: null },
    doctors: { type: Array, default: () => [] },
    currentDoctorId: { type: String, default: null },
    canChooseDoctor: { type: Boolean, default: false },
    isDoctor: { type: Boolean, default: false },
    isEdit: { type: Boolean, default: false },
    catalogs: { type: Object, required: true },
    urls: { type: Object, required: true },
    storage: { type: Object, default: () => ({
      used_bytes: 0,
      limit_bytes: 0,
      limit_gb: 0,
      is_unlimited: false,
      percent: 0,
      remaining_bytes: null,
      max_file_size_bytes: 10485760,
      max_files_per_batch: 10,
      accept: ".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx",
      accept_mimes: ["jpg", "jpeg", "png", "gif", "webp", "pdf", "doc", "docx"]
    }) },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    var _a, _b, _c, _d;
    const props = __props;
    const r = props.medicalrecord;
    const isLocked = computed(() => Boolean(r == null ? void 0 : r.is_locked));
    const visualAcuityTypes = computed(() => props.catalogs.visual_acuity_types ?? []);
    const colorVisionTypes = computed(() => props.catalogs.color_vision_types ?? []);
    const coverTestTypes = computed(() => props.catalogs.cover_test_types ?? []);
    const nearPointTypes = computed(() => props.catalogs.near_point_types ?? []);
    const additionTypes = computed(() => props.catalogs.addition_types ?? []);
    const lenses = computed(() => props.catalogs.lenses ?? []);
    const examReports = computed(() => props.catalogs.exam_reports ?? []);
    const documentationTemplates = computed(() => normalizeDocTemplates(props.catalogs.available_templates ?? []));
    const form = useForm({
      doctor_id: (r == null ? void 0 : r.doctor_id) ?? props.currentDoctorId ?? "",
      // Anamnese
      main_complaint: (r == null ? void 0 : r.main_complaint) ?? "",
      hda: (r == null ? void 0 : r.hda) ?? "",
      diabetic: (r == null ? void 0 : r.diabetic) ?? false,
      diabetic_family: (r == null ? void 0 : r.diabetic_family) ?? false,
      hypertensive: (r == null ? void 0 : r.hypertensive) ?? false,
      hypertensive_family: (r == null ? void 0 : r.hypertensive_family) ?? false,
      glaucomatous: (r == null ? void 0 : r.glaucomatous) ?? false,
      glaucomatous_family: (r == null ? void 0 : r.glaucomatous_family) ?? false,
      others_history: (r == null ? void 0 : r.others_history) ?? "",
      ocular_surgical_history: (r == null ? void 0 : r.ocular_surgical_history) ?? "",
      medications_in_use: (r == null ? void 0 : r.medications_in_use) ?? "",
      // Selects clínicos
      visual_acuity_type_id: (r == null ? void 0 : r.visual_acuity_type_id) ?? "",
      visual_acuity_without_correction_right_id: (r == null ? void 0 : r.visual_acuity_without_correction_right_id) ?? "",
      visual_acuity_without_correction_left_id: (r == null ? void 0 : r.visual_acuity_without_correction_left_id) ?? "",
      visual_acuity_with_correction_right_id: (r == null ? void 0 : r.visual_acuity_with_correction_right_id) ?? "",
      visual_acuity_with_correction_left_id: (r == null ? void 0 : r.visual_acuity_with_correction_left_id) ?? "",
      near_point_convergence_id: (r == null ? void 0 : r.near_point_convergence_id) ?? "",
      cover_test_type_id: (r == null ? void 0 : r.cover_test_type_id) ?? "",
      color_vision_type_id: (r == null ? void 0 : r.color_vision_type_id) ?? "",
      addition_type_id: (r == null ? void 0 : r.addition_type_id) ?? "",
      lens_away_id: (r == null ? void 0 : r.lens_away_id) ?? "",
      lens_near_id: (r == null ? void 0 : r.lens_near_id) ?? "",
      // Refração dinâmica
      dynamic_spherical_right: (r == null ? void 0 : r.dynamic_spherical_right) ?? "0.00",
      dynamic_spherical_left: (r == null ? void 0 : r.dynamic_spherical_left) ?? "0.00",
      dynamic_cylindrical_right: (r == null ? void 0 : r.dynamic_cylindrical_right) ?? "0.00",
      dynamic_cylindrical_left: (r == null ? void 0 : r.dynamic_cylindrical_left) ?? "0.00",
      dynamic_axis_right: (r == null ? void 0 : r.dynamic_axis_right) ?? "",
      dynamic_axis_left: (r == null ? void 0 : r.dynamic_axis_left) ?? "",
      // Refração estática
      static_spherical_right: (r == null ? void 0 : r.static_spherical_right) ?? "0.00",
      static_spherical_left: (r == null ? void 0 : r.static_spherical_left) ?? "0.00",
      static_cylindrical_right: (r == null ? void 0 : r.static_cylindrical_right) ?? "0.00",
      static_cylindrical_left: (r == null ? void 0 : r.static_cylindrical_left) ?? "0.00",
      static_axis_right: (r == null ? void 0 : r.static_axis_right) ?? "",
      static_axis_left: (r == null ? void 0 : r.static_axis_left) ?? "",
      // Exame físico
      ocular_motility: (r == null ? void 0 : r.ocular_motility) ?? "",
      tonometer_right: (r == null ? void 0 : r.tonometer_right) ?? "",
      tonometer_left: (r == null ? void 0 : r.tonometer_left) ?? "",
      tonometer_time: (r == null ? void 0 : r.tonometer_time) ?? "",
      pachymetry_right: (r == null ? void 0 : r.pachymetry_right) ?? "",
      pachymetry_left: (r == null ? void 0 : r.pachymetry_left) ?? "",
      gonioscopy_right: (r == null ? void 0 : r.gonioscopy_right) ?? "",
      gonioscopy_left: (r == null ? void 0 : r.gonioscopy_left) ?? "",
      // Achados
      biomicroscopy_right: (r == null ? void 0 : r.biomicroscopy_right) ?? ((_a = props.t) == null ? void 0 : _a.biomicroscopy_ph) ?? "",
      biomicroscopy_left: (r == null ? void 0 : r.biomicroscopy_left) ?? ((_b = props.t) == null ? void 0 : _b.biomicroscopy_ph) ?? "",
      fundoscopy_right: (r == null ? void 0 : r.fundoscopy_right) ?? ((_c = props.t) == null ? void 0 : _c.fundoscopy_ph) ?? "",
      fundoscopy_left: (r == null ? void 0 : r.fundoscopy_left) ?? ((_d = props.t) == null ? void 0 : _d.fundoscopy_ph) ?? "",
      observation_general: (r == null ? void 0 : r.observation_general) ?? "",
      observation_of_lenses: (r == null ? void 0 : r.observation_of_lenses) ?? "",
      // Diagnóstico & conduta
      diagnosis_cids: (r == null ? void 0 : r.diagnosis_cids) ?? [],
      clinical_conduct: (r == null ? void 0 : r.clinical_conduct) ?? "",
      follow_up_days: (r == null ? void 0 : r.follow_up_days) ?? "",
      // Vínculo opcional com agenda
      schedule_id: ""
    });
    const validationRules = ref({});
    const clientErrors = ref({});
    const hasClientErrors = ref(false);
    const showOthersHistory = ref(Boolean(r == null ? void 0 : r.others_history));
    const othersHistoryInput = ref(null);
    const tonometryPdfSrc = ref("");
    const tonometryStampedTime = ref((r == null ? void 0 : r.tonometer_time) ?? "");
    const liveTime = ref("");
    let _liveTimeInterval = null;
    const presbyopiaAddition = ref(0);
    const presbyopiaObsForm = reactive({ content: "" });
    const documentations = ref((r == null ? void 0 : r.documentations) ?? []);
    const docForm = reactive({
      report_setting_content_id: "",
      title: "",
      content: "",
      exam_type: "",
      exam_subtype: "",
      exam_label: ""
    });
    const docTemplates = ref(documentationTemplates.value);
    const docSaving = ref(false);
    const quickActionBusy = ref(false);
    const prescription = ref([]);
    const medicineLists = ref("");
    const medSearchQuery = ref("");
    const medSearchResults = ref([]);
    const medSearchOpen = ref(false);
    const medSearchLoading = ref(false);
    const procSelected = ref([]);
    const indSelected = ref([]);
    const procedureLists = ref("");
    const procSearchQuery = ref("");
    const procSearchResults = ref([]);
    const procSearchOpen = ref(false);
    const procSearchLoading = ref(false);
    const procTypeSelected = ref("");
    const indSearchQuery = ref("");
    const indSearchResults = ref([]);
    const indSearchOpen = ref(false);
    const indSearchLoading = ref(false);
    const attendanceForm = reactive({ content: "" });
    const medicalForm = reactive({ days: 1, date: "", content: "", daysPreview: "" });
    const cataractForm = reactive({
      eye: "right",
      template: "pre_operatorio",
      date_surgery: "",
      hour_surgery: ""
    });
    const uploadedFiles = ref((r == null ? void 0 : r.files) ?? []);
    const showUploadModal = ref(false);
    const storageState = ref({ ...props.storage });
    const pdfPreviewUrl = ref("");
    const pdfPreviewTitle = ref("");
    const showPdfPreview = ref(false);
    const showDocumentationsModal = ref(false);
    const showDocModal = ref(false);
    const showMedicationModal = ref(false);
    const showProcedureModal = ref(false);
    const showCataractModal = ref(false);
    const showAttendanceCertModal = ref(false);
    const showMedicalCertModal = ref(false);
    const showExamHubModal = ref(false);
    const showTonometryModal = ref(false);
    const showPresbyopiaObsModal = ref(false);
    const cidQuery = ref("");
    const cidResults = ref([]);
    const cidOpen = ref(false);
    const cidSearching = ref(false);
    const cidActiveIndex = ref(-1);
    const selectedCids = ref(Array.isArray(r == null ? void 0 : r.diagnosis_cids) ? [...r.diagnosis_cids] : []);
    onMounted(async () => {
      const tick = () => {
        liveTime.value = (/* @__PURE__ */ new Date()).toTimeString().slice(0, 8);
      };
      tick();
      _liveTimeInterval = setInterval(tick, 1e3);
      await fetchValidationRules();
      const params = new URLSearchParams(window.location.search);
      const sid = params.get("schedule_id");
      if (sid) form.schedule_id = sid;
    });
    onBeforeUnmount(() => {
      if (_liveTimeInterval) clearInterval(_liveTimeInterval);
    });
    watch(showOthersHistory, async (v) => {
      var _a2;
      if (v) {
        await nextTick();
        (_a2 = othersHistoryInput.value) == null ? void 0 : _a2.focus();
      }
    });
    watch(selectedCids, (v) => {
      form.diagnosis_cids = v;
    }, { deep: true });
    const i18n = computed(() => props.t ?? {});
    function tt(key, fallback = "") {
      var _a2;
      return ((_a2 = i18n.value) == null ? void 0 : _a2[key]) ?? fallback;
    }
    function normalizeDocTemplates(payload) {
      if (Array.isArray(payload)) return payload;
      if (!payload || typeof payload !== "object") return [];
      return Object.entries(payload).map(([id, group]) => ({
        report_setting_id: group.report_setting_id ?? id,
        report_setting_title: group.report_setting_title ?? group.title ?? "",
        contents: Array.isArray(group.contents) ? group.contents : []
      }));
    }
    function csrf() {
      var _a2;
      return ((_a2 = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a2.content) ?? "";
    }
    async function fetchValidationRules() {
      if (!props.urls.validation_rules) return;
      try {
        const res = await fetch(props.urls.validation_rules, {
          headers: { Accept: "application/json", "X-CSRF-TOKEN": csrf() }
        });
        if (!res.ok) return;
        const data = await res.json();
        validationRules.value = (data == null ? void 0 : data.rules) ?? {};
      } catch (e) {
        console.warn("[F9] failed to load validation rules:", e);
      }
    }
    function closePdfPreview() {
      showPdfPreview.value = false;
      pdfPreviewUrl.value = "";
      pdfPreviewTitle.value = "";
    }
    function onFileUploaded(file) {
      if ((file == null ? void 0 : file.id) && !uploadedFiles.value.some((f) => f.id === file.id)) {
        uploadedFiles.value.push(file);
      }
    }
    function onStorageUpdated(state) {
      storageState.value = { ...storageState.value, ...state };
    }
    const serializedCids = computed(() => JSON.stringify(selectedCids.value));
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[--><form class="pmr-form" enctype="multipart/form-data" novalidate>`);
      if (hasClientErrors.value) {
        _push(`<div class="alert alert-danger m-3 mb-0" role="alert"><h6 class="alert-heading mb-1"><i class="fas fa-exclamation-triangle me-1"></i>${ssrInterpolate(tt("client_errors_title", "Erros de validação"))}</h6><ul class="mb-0 small ps-3"><!--[-->`);
        ssrRenderList(clientErrors.value, (msgs, field) => {
          _push(`<!--[--><!--[-->`);
          ssrRenderList(msgs, (m, i) => {
            _push(`<li>${ssrInterpolate(m)}</li>`);
          });
          _push(`<!--]--><!--]-->`);
        });
        _push(`<!--]--></ul></div>`);
      } else {
        _push(`<!---->`);
      }
      if (Object.keys(unref(form).errors).length) {
        _push(`<div class="alert alert-danger m-3 mb-0" role="alert"><h6 class="alert-heading mb-1"><i class="fas fa-exclamation-triangle me-1"></i>${ssrInterpolate(tt("server_errors_title", "Erros do servidor"))}</h6><ul class="mb-0 small ps-3"><!--[-->`);
        ssrRenderList(unref(form).errors, (msg, field) => {
          _push(`<li>${ssrInterpolate(msg)}</li>`);
        });
        _push(`<!--]--></ul></div>`);
      } else {
        _push(`<!---->`);
      }
      if (!__props.isEdit) {
        _push(`<div class="alert alert-info d-flex align-items-start gap-2 m-3 mb-0" role="alert"><i class="fas fa-info-circle mt-1 flex-shrink-0"></i><span class="small"> Preencha pelo menos a <strong>${ssrInterpolate(tt("complaint", "Queixa principal"))}</strong> e clique em <strong>Salvar</strong> para começar a editar o prontuário. </span></div>`);
      } else {
        _push(`<!---->`);
      }
      if (!__props.canChooseDoctor && __props.currentDoctorId) {
        _push(`<input type="hidden" name="doctor_id"${ssrRenderAttr("value", unref(form).doctor_id)}>`);
      } else {
        _push(`<div class="pmr-section px-3 pt-2"><div class="row g-2"><div class="col-12 col-md-4 col-lg-3"><label class="pmr-label">${ssrInterpolate(tt("doctor", "Médico"))}</label><select name="doctor_id" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.doctor_id }, "form-select form-select-sm"])}"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).doctor_id) ? ssrLooseContain(unref(form).doctor_id, "") : ssrLooseEqual(unref(form).doctor_id, "")) ? " selected" : ""}>${ssrInterpolate(tt("select", "Selecione"))}</option><!--[-->`);
        ssrRenderList(__props.doctors, (d) => {
          _push(`<option${ssrRenderAttr("value", d.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).doctor_id) ? ssrLooseContain(unref(form).doctor_id, d.id) : ssrLooseEqual(unref(form).doctor_id, d.id)) ? " selected" : ""}>${ssrInterpolate(d.name)}</option>`);
        });
        _push(`<!--]--></select></div></div></div>`);
      }
      _push(`<div class="pmr-section pmr-top-strip px-3 pt-2 pb-0 bg-white"><div class="row g-2 align-items-start"><div class="col-12 col-lg-8"><label class="pmr-label">${ssrInterpolate(tt("complaint", "Queixa principal"))}</label><input${ssrRenderAttr("value", unref(form).main_complaint)} type="text" name="main_complaint" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.main_complaint }, "form-control form-control-sm"])}"${ssrRenderAttr("placeholder", tt("complaint_ph", "Descreva a queixa principal..."))}${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></div><div class="col-12 col-lg-4 pmr-risk-wrap"><div class="d-flex align-items-baseline flex-wrap gap-2 mb-1"><label class="pmr-label mb-0">${ssrInterpolate(tt("clinical_history", "Antecedentes clínicos"))}</label><span class="pmr-risk-legend d-inline-flex align-items-center gap-2 flex-wrap"><span class="d-inline-flex align-items-center gap-1"><span class="pmr-risk-dot pmr-risk-dot--self"></span><small class="text-muted">${ssrInterpolate(tt("self", "Próprio"))} = paciente tem</small></span><span class="text-muted small">·</span><span class="d-inline-flex align-items-center gap-1"><span class="pmr-risk-dot pmr-risk-dot--family"></span><small class="text-muted">${ssrInterpolate(tt("family", "Familiar"))} = histórico</small></span></span></div><div class="row g-1 pmr-risk-grid"><!--[-->`);
      ssrRenderList(["diabetic", "hypertensive", "glaucomatous"], (flag) => {
        _push(`<div class="col-4 pmr-risk-item"><label class="pmr-label text-center d-block pmr-risk-title">${ssrInterpolate(tt(flag, flag.charAt(0).toUpperCase() + flag.slice(1)))}</label><div class="pmr-risk-switches"><div class="${ssrRenderClass([{ "pmr-risk-switch--on": unref(form)[flag] }, "form-check form-switch pmr-risk-switch pmr-risk-self mb-0"])}"><input${ssrIncludeBooleanAttr(Array.isArray(unref(form)[flag]) ? ssrLooseContain(unref(form)[flag], 1) : unref(form)[flag]) ? " checked" : ""} type="checkbox" class="form-check-input" role="switch"${ssrRenderAttr("id", `risk-${flag}-self`)}${ssrRenderAttr("name", flag)}${ssrRenderAttr("value", 1)}${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}><label class="form-check-label pmr-risk-switch-label"${ssrRenderAttr("for", `risk-${flag}-self`)}>${ssrInterpolate(tt("self", "Próprio"))}</label></div><div class="${ssrRenderClass([{ "pmr-risk-switch--on": unref(form)[`${flag}_family`] }, "form-check form-switch pmr-risk-switch pmr-risk-family mb-0"])}"><input${ssrIncludeBooleanAttr(Array.isArray(unref(form)[`${flag}_family`]) ? ssrLooseContain(unref(form)[`${flag}_family`], 1) : unref(form)[`${flag}_family`]) ? " checked" : ""} type="checkbox" class="form-check-input" role="switch"${ssrRenderAttr("id", `risk-${flag}-family`)}${ssrRenderAttr("name", `${flag}_family`)}${ssrRenderAttr("value", 1)}${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}><label class="form-check-label pmr-risk-switch-label"${ssrRenderAttr("for", `risk-${flag}-family`)}>${ssrInterpolate(tt("family", "Familiar"))}</label></div></div>`);
        if (flag === "glaucomatous") {
          _push(`<div class="text-center mt-1"><button type="button" class="btn btn-link p-0 pmr-toggle-label text-decoration-none" style="${ssrRenderStyle({ "font-size": ".68rem" })}">`);
          if (!showOthersHistory.value) {
            _push(`<i class="fas fa-plus-circle fa-xs me-1"></i>`);
          } else {
            _push(`<i class="fas fa-minus-circle fa-xs me-1"></i>`);
          }
          _push(` ${ssrInterpolate(tt("others", "Outros"))}</button></div>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</div>`);
      });
      _push(`<!--]--></div></div></div><div class="row g-2 mt-1" style="${ssrRenderStyle(showOthersHistory.value ? null : { display: "none" })}"><div class="col-12"><input${ssrRenderAttr("value", unref(form).others_history)} type="text" name="others_history" class="form-control form-control-sm"${ssrRenderAttr("placeholder", tt("others_history_ph", "Outros antecedentes clínicos"))}${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></div></div></div><div class="row g-2 px-3 pt-1 pb-1 pmr-main-columns"><div class="col-12 col-lg-6 pe-lg-2"><div class="pmr-main-panel"><div class="pmr-section mb-1"><div class="row g-2"><div class="col-4"><label class="pmr-label">${ssrInterpolate(tt("chromatic_vision", "Vis. cromática"))}</label><select name="color_vision_type_id" class="form-select form-select-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).color_vision_type_id) ? ssrLooseContain(unref(form).color_vision_type_id, "") : ssrLooseEqual(unref(form).color_vision_type_id, "")) ? " selected" : ""}>—</option><!--[-->`);
      ssrRenderList(colorVisionTypes.value, (item) => {
        _push(`<option${ssrRenderAttr("value", item.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).color_vision_type_id) ? ssrLooseContain(unref(form).color_vision_type_id, item.id) : ssrLooseEqual(unref(form).color_vision_type_id, item.id)) ? " selected" : ""}>${ssrInterpolate(item.name)}</option>`);
      });
      _push(`<!--]--></select></div><div class="col-4"><label class="pmr-label">${ssrInterpolate(tt("near_point", "PPC"))}</label><select name="near_point_convergence_id" class="form-select form-select-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).near_point_convergence_id) ? ssrLooseContain(unref(form).near_point_convergence_id, "") : ssrLooseEqual(unref(form).near_point_convergence_id, "")) ? " selected" : ""}>—</option><!--[-->`);
      ssrRenderList(nearPointTypes.value, (item) => {
        _push(`<option${ssrRenderAttr("value", item.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).near_point_convergence_id) ? ssrLooseContain(unref(form).near_point_convergence_id, item.id) : ssrLooseEqual(unref(form).near_point_convergence_id, item.id)) ? " selected" : ""}>${ssrInterpolate(item.name)}</option>`);
      });
      _push(`<!--]--></select></div><div class="col-4"><label class="pmr-label">${ssrInterpolate(tt("cover_test", "Cover test"))}</label><select name="cover_test_type_id" class="form-select form-select-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).cover_test_type_id) ? ssrLooseContain(unref(form).cover_test_type_id, "") : ssrLooseEqual(unref(form).cover_test_type_id, "")) ? " selected" : ""}>—</option><!--[-->`);
      ssrRenderList(coverTestTypes.value, (item) => {
        _push(`<option${ssrRenderAttr("value", item.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).cover_test_type_id) ? ssrLooseContain(unref(form).cover_test_type_id, item.id) : ssrLooseEqual(unref(form).cover_test_type_id, item.id)) ? " selected" : ""}>${ssrInterpolate(item.name)}</option>`);
      });
      _push(`<!--]--></select></div></div></div><div class="pmr-section mb-1"><div class="row g-2"><div class="col-6"><label class="pmr-label">${ssrInterpolate(tt("av_without", "A/V sem correção"))}</label><div class="d-flex gap-1"><div class="input-group input-group-sm"><span class="input-group-text pmr-eye-badge">OD</span><select name="visual_acuity_without_correction_right_id" class="form-select form-select-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).visual_acuity_without_correction_right_id) ? ssrLooseContain(unref(form).visual_acuity_without_correction_right_id, "") : ssrLooseEqual(unref(form).visual_acuity_without_correction_right_id, "")) ? " selected" : ""}>—</option><!--[-->`);
      ssrRenderList(visualAcuityTypes.value, (item) => {
        _push(`<option${ssrRenderAttr("value", item.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).visual_acuity_without_correction_right_id) ? ssrLooseContain(unref(form).visual_acuity_without_correction_right_id, item.id) : ssrLooseEqual(unref(form).visual_acuity_without_correction_right_id, item.id)) ? " selected" : ""}>${ssrInterpolate(item.name)}</option>`);
      });
      _push(`<!--]--></select></div><div class="input-group input-group-sm"><span class="input-group-text pmr-eye-badge">OE</span><select name="visual_acuity_without_correction_left_id" class="form-select form-select-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).visual_acuity_without_correction_left_id) ? ssrLooseContain(unref(form).visual_acuity_without_correction_left_id, "") : ssrLooseEqual(unref(form).visual_acuity_without_correction_left_id, "")) ? " selected" : ""}>—</option><!--[-->`);
      ssrRenderList(visualAcuityTypes.value, (item) => {
        _push(`<option${ssrRenderAttr("value", item.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).visual_acuity_without_correction_left_id) ? ssrLooseContain(unref(form).visual_acuity_without_correction_left_id, item.id) : ssrLooseEqual(unref(form).visual_acuity_without_correction_left_id, item.id)) ? " selected" : ""}>${ssrInterpolate(item.name)}</option>`);
      });
      _push(`<!--]--></select></div></div></div><div class="col-6"><label class="pmr-label">${ssrInterpolate(tt("tonometry", "Tonometria"))} `);
      if (tonometryStampedTime.value) {
        _push(`<span class="ms-1 fw-bold" style="${ssrRenderStyle({ "color": "#03a9f3", "font-size": ".7rem" })}">${ssrInterpolate(tonometryStampedTime.value)}</span>`);
      } else {
        _push(`<span class="ms-1 text-muted" style="${ssrRenderStyle({ "font-size": ".7rem" })}">${ssrInterpolate(liveTime.value)}</span>`);
      }
      _push(`</label><div class="d-flex gap-1 align-items-center"><div class="input-group input-group-sm" style="${ssrRenderStyle({ "max-width": "90px" })}"><span class="input-group-text pmr-eye-badge">OD</span><input${ssrRenderAttr("value", unref(form).tonometer_right)} type="number" name="tonometer_right" step="0.5" min="0" class="form-control form-control-sm text-center" placeholder="00"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></div><div class="input-group input-group-sm" style="${ssrRenderStyle({ "max-width": "90px" })}"><span class="input-group-text pmr-eye-badge">OE</span><input${ssrRenderAttr("value", unref(form).tonometer_left)} type="number" name="tonometer_left" step="0.5" min="0" class="form-control form-control-sm text-center" placeholder="00"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></div><input type="hidden" name="tonometer_time"${ssrRenderAttr("value", tonometryStampedTime.value || unref(form).tonometer_time)}>`);
      if (__props.isDoctor) {
        _push(`<button type="button" class="btn btn-pink btn-sm flex-shrink-0"${ssrRenderAttr("title", tt("print_tonometry", "Imprimir tonometria"))}><i class="fas fa-print"></i></button>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div></div></div></div><div class="pmr-section mb-1"><label class="pmr-label">${ssrInterpolate(tt("dynamic", "Dinâmica"))}</label><table class="pmr-table"><thead><tr><th style="${ssrRenderStyle({ "width": "36px" })}"></th><th>${ssrInterpolate(tt("spherical", "Esf."))}</th><th>${ssrInterpolate(tt("cylindrical", "Cil."))}</th><th>${ssrInterpolate(tt("axis", "Eixo"))}</th></tr></thead><tbody><tr><td class="pmr-od">OD</td><td><input${ssrRenderAttr("value", unref(form).dynamic_spherical_right)} type="text" inputmode="decimal" name="dynamic_spherical_right" placeholder="0.00"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></td><td><input${ssrRenderAttr("value", unref(form).dynamic_cylindrical_right)} type="text" inputmode="decimal" name="dynamic_cylindrical_right" placeholder="0.00"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></td><td><input${ssrRenderAttr("value", unref(form).dynamic_axis_right)} type="text" inputmode="numeric" name="dynamic_axis_right" placeholder="0º"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></td></tr><tr><td class="pmr-od">OE</td><td><input${ssrRenderAttr("value", unref(form).dynamic_spherical_left)} type="text" inputmode="decimal" name="dynamic_spherical_left" placeholder="0.00"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></td><td><input${ssrRenderAttr("value", unref(form).dynamic_cylindrical_left)} type="text" inputmode="decimal" name="dynamic_cylindrical_left" placeholder="0.00"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></td><td><input${ssrRenderAttr("value", unref(form).dynamic_axis_left)} type="text" inputmode="numeric" name="dynamic_axis_left" placeholder="0º"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></td></tr></tbody></table></div><div class="pmr-section mb-1"><label class="pmr-label">${ssrInterpolate(tt("static", "Estática"))}</label><table class="pmr-table"><thead><tr><th style="${ssrRenderStyle({ "width": "36px" })}"></th><th>${ssrInterpolate(tt("spherical", "Esf."))}</th><th>${ssrInterpolate(tt("cylindrical", "Cil."))}</th><th>${ssrInterpolate(tt("axis", "Eixo"))}</th></tr></thead><tbody><tr><td class="pmr-od">OD</td><td><input${ssrRenderAttr("value", unref(form).static_spherical_right)} type="text" inputmode="decimal" name="static_spherical_right" placeholder="0.00"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></td><td><input${ssrRenderAttr("value", unref(form).static_cylindrical_right)} type="text" inputmode="decimal" name="static_cylindrical_right" placeholder="0.00"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></td><td><input${ssrRenderAttr("value", unref(form).static_axis_right)} type="text" inputmode="numeric" name="static_axis_right" placeholder="0º"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></td></tr><tr><td class="pmr-od">OE</td><td><input${ssrRenderAttr("value", unref(form).static_spherical_left)} type="text" inputmode="decimal" name="static_spherical_left" placeholder="0.00"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></td><td><input${ssrRenderAttr("value", unref(form).static_cylindrical_left)} type="text" inputmode="decimal" name="static_cylindrical_left" placeholder="0.00"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></td><td><input${ssrRenderAttr("value", unref(form).static_axis_left)} type="text" inputmode="numeric" name="static_axis_left" placeholder="0º"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></td></tr></tbody></table></div><div class="pmr-section mb-1"><div class="row g-2"><div class="col-6"><label class="pmr-label">${ssrInterpolate(tt("pachymetry", "Paquimetria"))}</label><div class="d-flex gap-1"><div class="input-group input-group-sm"><span class="input-group-text pmr-eye-badge">OD</span><input${ssrRenderAttr("value", unref(form).pachymetry_right)} type="number" name="pachymetry_right" step="1" min="0" class="form-control form-control-sm text-center" placeholder="μm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></div><div class="input-group input-group-sm"><span class="input-group-text pmr-eye-badge">OE</span><input${ssrRenderAttr("value", unref(form).pachymetry_left)} type="number" name="pachymetry_left" step="1" min="0" class="form-control form-control-sm text-center" placeholder="μm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></div></div></div><div class="col-6"><label class="pmr-label">${ssrInterpolate(tt("gonioscopy", "Gonioscopia"))}</label><div class="d-flex gap-1"><div class="input-group input-group-sm"><span class="input-group-text pmr-eye-badge">OD</span><input${ssrRenderAttr("value", unref(form).gonioscopy_right)} type="text" name="gonioscopy_right" class="form-control form-control-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></div><div class="input-group input-group-sm"><span class="input-group-text pmr-eye-badge">OE</span><input${ssrRenderAttr("value", unref(form).gonioscopy_left)} type="text" name="gonioscopy_left" class="form-control form-control-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></div></div></div></div></div></div></div><div class="col-12 col-lg-6 ps-lg-2"><div class="pmr-main-panel"><div class="pmr-section mb-1"><div class="row g-2 align-items-end"><div class="col-3"><label class="pmr-label">${ssrInterpolate(tt("addition", "Adição"))}</label><select name="addition_type_id" class="form-select form-select-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).addition_type_id) ? ssrLooseContain(unref(form).addition_type_id, "") : ssrLooseEqual(unref(form).addition_type_id, "")) ? " selected" : ""}>${ssrInterpolate(tt("select", "Selecione"))}</option><!--[-->`);
      ssrRenderList(additionTypes.value, (item) => {
        _push(`<option${ssrRenderAttr("value", item.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).addition_type_id) ? ssrLooseContain(unref(form).addition_type_id, item.id) : ssrLooseEqual(unref(form).addition_type_id, item.id)) ? " selected" : ""}>${ssrInterpolate(item.name)}</option>`);
      });
      _push(`<!--]--></select></div><div class="col-3"><label class="pmr-label">${ssrInterpolate(tt("lens_away", "Longe"))}</label><select name="lens_away_id" class="form-select form-select-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).lens_away_id) ? ssrLooseContain(unref(form).lens_away_id, "") : ssrLooseEqual(unref(form).lens_away_id, "")) ? " selected" : ""}>—</option><!--[-->`);
      ssrRenderList(lenses.value, (item) => {
        _push(`<option${ssrRenderAttr("value", item.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).lens_away_id) ? ssrLooseContain(unref(form).lens_away_id, item.id) : ssrLooseEqual(unref(form).lens_away_id, item.id)) ? " selected" : ""}>${ssrInterpolate(item.name)}</option>`);
      });
      _push(`<!--]--></select></div><div class="col-3"><label class="pmr-label">${ssrInterpolate(tt("lens_near", "Perto"))}</label><select name="lens_near_id" class="form-select form-select-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).lens_near_id) ? ssrLooseContain(unref(form).lens_near_id, "") : ssrLooseEqual(unref(form).lens_near_id, "")) ? " selected" : ""}>—</option><!--[-->`);
      ssrRenderList(lenses.value, (item) => {
        _push(`<option${ssrRenderAttr("value", item.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).lens_near_id) ? ssrLooseContain(unref(form).lens_near_id, item.id) : ssrLooseEqual(unref(form).lens_near_id, item.id)) ? " selected" : ""}>${ssrInterpolate(item.name)}</option>`);
      });
      _push(`<!--]--></select></div><div class="col-3 d-flex gap-1"><input${ssrRenderAttr("value", presbyopiaAddition.value)} type="number" step="0.25" class="form-control form-control-sm" placeholder="Add."${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}><button type="button" class="btn btn-outline-secondary btn-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}${ssrRenderAttr("title", tt("calc", "Calcular presbiopia"))}><i class="fas fa-pencil-alt"></i></button>`);
      if (__props.isEdit && __props.isDoctor) {
        _push(`<div class="btn-group" role="group"><button type="button" class="btn btn-pink btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"${ssrIncludeBooleanAttr(quickActionBusy.value || isLocked.value) ? " disabled" : ""}${ssrRenderAttr("title", tt("lens_prescription", "Receituário de óculos"))}><i class="fas fa-print"></i></button><ul class="dropdown-menu dropdown-menu-end"><li><button type="button" class="dropdown-item">Dinâmica</button></li><li><button type="button" class="dropdown-item">Estática</button></li><li><button type="button" class="dropdown-item">Presb. dinâmica</button></li><li><button type="button" class="dropdown-item">Presbiopia</button></li></ul></div>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div></div></div><div class="pmr-section mb-1"><label class="pmr-label">${ssrInterpolate(tt("av_with", "A/V com correção"))}</label><div class="d-flex gap-1"><div class="input-group input-group-sm"><span class="input-group-text pmr-eye-badge">OD</span><select name="visual_acuity_with_correction_right_id" class="form-select form-select-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).visual_acuity_with_correction_right_id) ? ssrLooseContain(unref(form).visual_acuity_with_correction_right_id, "") : ssrLooseEqual(unref(form).visual_acuity_with_correction_right_id, "")) ? " selected" : ""}>—</option><!--[-->`);
      ssrRenderList(visualAcuityTypes.value, (item) => {
        _push(`<option${ssrRenderAttr("value", item.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).visual_acuity_with_correction_right_id) ? ssrLooseContain(unref(form).visual_acuity_with_correction_right_id, item.id) : ssrLooseEqual(unref(form).visual_acuity_with_correction_right_id, item.id)) ? " selected" : ""}>${ssrInterpolate(item.name)}</option>`);
      });
      _push(`<!--]--></select></div><div class="input-group input-group-sm"><span class="input-group-text pmr-eye-badge">OE</span><select name="visual_acuity_with_correction_left_id" class="form-select form-select-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).visual_acuity_with_correction_left_id) ? ssrLooseContain(unref(form).visual_acuity_with_correction_left_id, "") : ssrLooseEqual(unref(form).visual_acuity_with_correction_left_id, "")) ? " selected" : ""}>—</option><!--[-->`);
      ssrRenderList(visualAcuityTypes.value, (item) => {
        _push(`<option${ssrRenderAttr("value", item.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).visual_acuity_with_correction_left_id) ? ssrLooseContain(unref(form).visual_acuity_with_correction_left_id, item.id) : ssrLooseEqual(unref(form).visual_acuity_with_correction_left_id, item.id)) ? " selected" : ""}>${ssrInterpolate(item.name)}</option>`);
      });
      _push(`<!--]--></select></div></div></div><div class="pmr-section mb-1"><label class="pmr-label">${ssrInterpolate(tt("biomicroscopy", "Biomicroscopia"))}</label><div class="d-flex gap-1 mb-1"><span class="pmr-eye-inline">OD</span><input${ssrRenderAttr("value", unref(form).biomicroscopy_right)} type="text" name="biomicroscopy_right" class="form-control form-control-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></div><div class="d-flex gap-1"><span class="pmr-eye-inline">OE</span><input${ssrRenderAttr("value", unref(form).biomicroscopy_left)} type="text" name="biomicroscopy_left" class="form-control form-control-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></div></div><div class="pmr-section mb-1"><label class="pmr-label">${ssrInterpolate(tt("fundoscopy", "Fundoscopia"))}</label><div class="d-flex gap-1 mb-1"><span class="pmr-eye-inline">OD</span><input${ssrRenderAttr("value", unref(form).fundoscopy_right)} type="text" name="fundoscopy_right" class="form-control form-control-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></div><div class="d-flex gap-1"><span class="pmr-eye-inline">OE</span><input${ssrRenderAttr("value", unref(form).fundoscopy_left)} type="text" name="fundoscopy_left" class="form-control form-control-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></div></div><div class="pmr-section mb-1"><label class="pmr-label">${ssrInterpolate(tt("general_obs", "Observações"))}</label><textarea name="observation_general" rows="2" class="form-control form-control-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}>${ssrInterpolate(unref(form).observation_general)}</textarea></div></div></div></div><div class="px-3 pb-2"><div class="pmr-collapse-toggle mb-2" data-bs-toggle="collapse" data-bs-target="#pmr-extra-fields" role="button"><i class="fas fa-chevron-down me-1 pmr-collapse-icon"></i><span class="pmr-label mb-0 d-inline">${ssrInterpolate(tt("extra_fields", "Campos adicionais"))}</span></div><div id="pmr-extra-fields" class="collapse"><div class="row g-2 mb-2"><div class="col-12"><label class="pmr-label">${ssrInterpolate(tt("hda", "HDA"))}</label><textarea name="hda" rows="2" class="form-control form-control-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}${ssrRenderAttr("placeholder", tt("hda_ph", "História da doença atual"))}>${ssrInterpolate(unref(form).hda)}</textarea></div><div class="col-12 col-md-6"><label class="pmr-label">${ssrInterpolate(tt("ocular_surgical_history", "Histórico cirúrgico ocular"))}</label><textarea name="ocular_surgical_history" rows="2" class="form-control form-control-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}>${ssrInterpolate(unref(form).ocular_surgical_history)}</textarea></div><div class="col-12 col-md-6"><label class="pmr-label">${ssrInterpolate(tt("medications_in_use", "Medicações em uso"))}</label><textarea name="medications_in_use" rows="2" class="form-control form-control-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}>${ssrInterpolate(unref(form).medications_in_use)}</textarea></div></div><div class="row g-2 mb-2"><div class="col-12 col-md-6"><label class="pmr-label">${ssrInterpolate(tt("ocular_motility", "Motilidade ocular"))}</label><input${ssrRenderAttr("value", unref(form).ocular_motility)} type="text" name="ocular_motility" class="form-control form-control-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></div></div><div class="row g-2 mb-2"><div class="col-12 col-md-6"><label class="pmr-label">${ssrInterpolate(tt("lenses_obs", "Observação de lentes"))}</label><textarea name="observation_of_lenses" rows="2" class="form-control form-control-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}>${ssrInterpolate(unref(form).observation_of_lenses)}</textarea></div><div class="col-12"><label class="pmr-label">${ssrInterpolate(tt("cid10", "CID-10"))}</label><input type="hidden" name="diagnosis_cids"${ssrRenderAttr("value", serializedCids.value)}>`);
      if (selectedCids.value.length > 0) {
        _push(`<div class="d-flex flex-wrap gap-1 mb-1"><!--[-->`);
        ssrRenderList(selectedCids.value, (item) => {
          _push(`<span class="badge d-inline-flex align-items-center gap-1" style="${ssrRenderStyle({ "background": "#e8f4fd", "color": "#1a5c8a", "font-size": ".8rem", "font-weight": "500", "border": "1px solid #b8d9f0", "padding": ".3rem .5rem" })}"><span class="fw-semibold">${ssrInterpolate(item.code)}</span><span class="text-secondary fw-normal" style="${ssrRenderStyle({ "max-width": "260px", "overflow": "hidden", "text-overflow": "ellipsis", "white-space": "nowrap" })}">– ${ssrInterpolate(item.description)}</span><button type="button" class="btn-close btn-close-sm ms-1" style="${ssrRenderStyle({ "font-size": ".6rem" })}"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}></button></span>`);
        });
        _push(`<!--]--></div>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<div class="position-relative"><div class="input-group input-group-sm"><input${ssrRenderAttr("value", cidQuery.value)} type="text" class="form-control form-control-sm" autocomplete="off" placeholder="Buscar por código ou diagnóstico (ex: H40.1, glaucoma)…"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}>`);
      if (cidSearching.value) {
        _push(`<span class="input-group-text bg-transparent border-start-0 px-2"><span class="spinner-border spinner-border-sm text-secondary" style="${ssrRenderStyle({ "width": ".8rem", "height": ".8rem" })}"></span></span>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div>`);
      if (cidOpen.value && cidResults.value.length > 0) {
        _push(`<ul class="list-group shadow-sm position-absolute w-100" style="${ssrRenderStyle({ "z-index": "1055", "top": "100%", "max-height": "260px", "overflow-y": "auto" })}"><!--[-->`);
        ssrRenderList(cidResults.value, (item, index) => {
          _push(`<li class="${ssrRenderClass([{ active: index === cidActiveIndex.value }, "list-group-item list-group-item-action py-1 px-2"])}" style="${ssrRenderStyle({ "cursor": "pointer", "font-size": ".82rem" })}"><span class="fw-semibold me-1">${ssrInterpolate(item.code)}</span><span>– ${ssrInterpolate(item.description)}</span></li>`);
        });
        _push(`<!--]--></ul>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div></div></div><div class="row g-2 mb-2"><div class="col-12 col-md-8"><label class="pmr-label">${ssrInterpolate(tt("clinical_conduct", "Conduta clínica"))}</label><textarea name="clinical_conduct" rows="2" class="form-control form-control-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}${ssrRenderAttr("placeholder", tt("clinical_conduct_ph", "Conduta clínica..."))}>${ssrInterpolate(unref(form).clinical_conduct)}</textarea></div><div class="col-12 col-md-4"><label class="pmr-label">${ssrInterpolate(tt("follow_up_days", "Retorno"))}</label><div class="input-group input-group-sm"><input${ssrRenderAttr("value", unref(form).follow_up_days)} type="number" min="0" name="follow_up_days" class="form-control form-control-sm"${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}><span class="input-group-text">${ssrInterpolate(tt("days", "dias"))}</span></div></div></div></div></div><div class="pmr-bottom-bar px-3 py-2"><div class="d-flex flex-wrap gap-1 align-items-center">`);
      if (__props.isDoctor) {
        _push(`<!--[--><button type="button" class="btn pmr-doc-img-btn"${ssrRenderAttr("title", __props.isEdit ? "Receituário de Medicamentos" : tt("save_first", "Salve primeiro o prontuário"))}${ssrIncludeBooleanAttr(!__props.isEdit || quickActionBusy.value || isLocked.value) ? " disabled" : ""}><i class="fas fa-pills" style="${ssrRenderStyle({ "font-size": "1.6rem", "color": "#9c27b0" })}"></i><span class="pmr-doc-img-btn-label">Medicamentos</span></button><button type="button" class="btn pmr-doc-img-btn"${ssrRenderAttr("title", __props.isEdit ? "Solicitação de Procedimentos" : tt("save_first", "Salve primeiro"))}${ssrIncludeBooleanAttr(!__props.isEdit || quickActionBusy.value || isLocked.value) ? " disabled" : ""}><i class="fas fa-clipboard-list" style="${ssrRenderStyle({ "font-size": "1.6rem", "color": "#3f51b5" })}"></i><span class="pmr-doc-img-btn-label">Procedimentos</span></button><button type="button" class="btn pmr-doc-img-btn"${ssrRenderAttr("title", __props.isEdit ? "Receituário de Pterígio" : tt("save_first", "Salve primeiro"))}${ssrIncludeBooleanAttr(!__props.isEdit || quickActionBusy.value || isLocked.value) ? " disabled" : ""}><i class="fas fa-eye-low-vision" style="${ssrRenderStyle({ "font-size": "1.6rem", "color": "#ff5722" })}"></i><span class="pmr-doc-img-btn-label" style="${ssrRenderStyle({ "white-space": "normal", "line-height": "1.1" })}">Receituário<br>Pterígio</span></button><button type="button" class="btn pmr-doc-img-btn"${ssrRenderAttr("title", __props.isEdit ? "Receituário de Catarata" : tt("save_first", "Salve primeiro"))}${ssrIncludeBooleanAttr(!__props.isEdit || quickActionBusy.value || isLocked.value) ? " disabled" : ""}><i class="fas fa-eye" style="${ssrRenderStyle({ "font-size": "1.6rem", "color": "#00bcd4" })}"></i><span class="pmr-doc-img-btn-label" style="${ssrRenderStyle({ "white-space": "normal", "line-height": "1.1" })}">Receituário<br>Catarata</span></button><button type="button" class="btn pmr-doc-img-btn"${ssrRenderAttr("title", __props.isEdit ? "Teste do Olhinho" : tt("save_first", "Salve primeiro"))}${ssrIncludeBooleanAttr(!__props.isEdit || quickActionBusy.value || isLocked.value) ? " disabled" : ""}><i class="fas fa-baby" style="${ssrRenderStyle({ "font-size": "1.6rem", "color": "#e91e63" })}"></i><span class="pmr-doc-img-btn-label" style="${ssrRenderStyle({ "white-space": "normal", "line-height": "1.1" })}">Teste do<br>Olhinho</span></button><button type="button" class="btn pmr-doc-img-btn"${ssrRenderAttr("title", __props.isEdit ? "Mapeamento de Retina" : tt("save_first", "Salve primeiro"))}${ssrIncludeBooleanAttr(!__props.isEdit || quickActionBusy.value || isLocked.value) ? " disabled" : ""}><i class="fas fa-bullseye" style="${ssrRenderStyle({ "font-size": "1.6rem", "color": "#673ab7" })}"></i><span class="pmr-doc-img-btn-label" style="${ssrRenderStyle({ "white-space": "normal", "line-height": "1.1" })}">Mapeamento<br>de Retina</span></button><button type="button" class="btn pmr-doc-img-btn"${ssrRenderAttr("title", __props.isEdit ? "Atestado de Comparecimento" : tt("save_first", "Salve primeiro"))}${ssrIncludeBooleanAttr(!__props.isEdit || quickActionBusy.value || isLocked.value) ? " disabled" : ""}><i class="fas fa-user-check" style="${ssrRenderStyle({ "font-size": "1.6rem", "color": "#4caf50" })}"></i><span class="pmr-doc-img-btn-label" style="${ssrRenderStyle({ "white-space": "normal", "line-height": "1.1" })}">Atestado<br>Comparecim.</span></button><button type="button" class="btn pmr-doc-img-btn"${ssrRenderAttr("title", __props.isEdit ? "Atestado Médico" : tt("save_first", "Salve primeiro"))}${ssrIncludeBooleanAttr(!__props.isEdit || quickActionBusy.value || isLocked.value) ? " disabled" : ""}><i class="fas fa-stethoscope" style="${ssrRenderStyle({ "font-size": "1.6rem", "color": "#2196f3" })}"></i><span class="pmr-doc-img-btn-label" style="${ssrRenderStyle({ "white-space": "normal", "line-height": "1.1" })}">Atestado<br>Médico</span></button><!--]-->`);
      } else {
        _push(`<!---->`);
      }
      if (__props.isDoctor && examReports.value.length > 0) {
        _push(`<button type="button" class="btn pmr-doc-img-btn"${ssrRenderAttr("title", __props.isEdit ? tt("exam_hub_title", "Laudos de Exame") : tt("save_first", "Salve primeiro"))}${ssrIncludeBooleanAttr(!__props.isEdit || isLocked.value) ? " disabled" : ""}><i class="fas fa-microscope" style="${ssrRenderStyle({ "font-size": "1.6rem", "color": "#03a9f3" })}"></i><span class="pmr-doc-img-btn-label" style="${ssrRenderStyle({ "white-space": "normal", "line-height": "1.1" })}">Laudos<br>de Exame</span></button>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<button type="button" class="btn pmr-doc-img-btn pmr-doc-img-btn-wide"${ssrRenderAttr("title", __props.isEdit ? tt("documentations", "Documentações") : tt("save_first", "Salve primeiro"))}${ssrIncludeBooleanAttr(!__props.isEdit) ? " disabled" : ""}><i class="fas fa-folder-open" style="${ssrRenderStyle({ "font-size": "1.6rem", "color": "#0288d1" })}"></i><span class="pmr-doc-img-btn-label">${ssrInterpolate(tt("documentations", "Documentações"))}</span></button>`);
      if (__props.isDoctor && __props.isEdit) {
        _push(`<button type="button" class="btn pmr-doc-img-btn pmr-doc-annexo"${ssrRenderAttr("title", tt("upload_files", "Anexar arquivos"))}${ssrIncludeBooleanAttr(isLocked.value) ? " disabled" : ""}><i class="fas fa-paperclip" style="${ssrRenderStyle({ "font-size": "1.6rem", "color": "#607d8b" })}"></i><span class="pmr-doc-img-btn-label">Anexo</span></button>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<button type="submit" class="btn pmr-save-btn ms-auto"${ssrIncludeBooleanAttr(unref(form).processing || isLocked.value) ? " disabled" : ""}${ssrRenderAttr("title", tt("save", "Salvar"))}><i class="fas fa-check-circle"></i></button></div></div>`);
      if (__props.isEdit && uploadedFiles.value.length > 0) {
        _push(`<div class="px-3 pb-2"><div class="row g-1"><!--[-->`);
        ssrRenderList(uploadedFiles.value, (f) => {
          _push(`<div class="col-auto"><a${ssrRenderAttr("href", f.show_url)} target="_blank" class="pmr-file-thumb"${ssrRenderAttr("title", f.original_name)}>`);
          if (f.is_image) {
            _push(`<img${ssrRenderAttr("src", f.show_url)}${ssrRenderAttr("alt", f.original_name)}>`);
          } else {
            _push(`<i class="fas fa-file-alt"></i>`);
          }
          _push(`</a></div>`);
        });
        _push(`<!--]--></div></div>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</form>`);
      ssrRenderTeleport(_push, (_push2) => {
        if (showDocumentationsModal.value) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.5)" })}"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header py-2"><h6 class="modal-title"><i class="fas fa-folder-open me-2" style="${ssrRenderStyle({ "color": "#0288d1" })}"></i>${ssrInterpolate(tt("documentations", "Documentações"))}</h6><button type="button" class="btn-close"></button></div><div class="modal-body p-2"><table class="table table-sm table-hover mb-0"><thead class="table-light"><tr><th>${ssrInterpolate(tt("doc_type", "Tipo"))}</th><th>${ssrInterpolate(tt("doc_title", "Título"))}</th><th>${ssrInterpolate(tt("doc_date", "Data"))}</th><th class="text-end">${ssrInterpolate(tt("doc_actions", "Ações"))}</th></tr></thead><tbody>`);
          if (documentations.value.length === 0) {
            _push2(`<tr><td colspan="4" class="text-center text-muted small py-2">${ssrInterpolate(tt("no_documentations", "Nenhuma documentação registrada."))}</td></tr>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`<!--[-->`);
          ssrRenderList(documentations.value, (doc) => {
            _push2(`<tr><td><span class="badge bg-info-subtle text-info">${ssrInterpolate(doc.type_label)}</span></td><td>${ssrInterpolate(doc.title)}</td><td>${ssrInterpolate(doc.created_at)}</td><td class="text-end"><a${ssrRenderAttr("href", doc.pdf_url)} target="_blank" class="btn btn-outline-secondary btn-sm" title="PDF"><i class="fas fa-file-pdf"></i></a></td></tr>`);
          });
          _push2(`<!--]--></tbody></table></div><div class="modal-footer py-2 d-flex justify-content-between">`);
          if (__props.isEdit && __props.isDoctor) {
            _push2(`<button type="button" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i>${ssrInterpolate(tt("new_documentation", "Nova documentação"))}</button>`);
          } else {
            _push2(`<span></span>`);
          }
          _push2(`<button type="button" class="btn btn-secondary btn-sm">${ssrInterpolate(tt("close", "Fechar"))}</button></div></div></div></div>`);
        } else {
          _push2(`<!---->`);
        }
      }, "body", false, _parent);
      ssrRenderTeleport(_push, (_push2) => {
        if (showDocModal.value) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.5)" })}"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header py-2"><h6 class="modal-title"><i class="fas fa-file-prescription me-2"></i>${ssrInterpolate(tt("new_documentation", "Nova documentação"))}</h6><button type="button" class="btn-close"></button></div><div class="modal-body"><div class="row g-2 mb-3">`);
          if (docForm.exam_type) {
            _push2(`<div class="col-12"><span class="badge bg-info-subtle text-info"><i class="fas fa-microscope me-1"></i>${ssrInterpolate(docForm.exam_label || docForm.exam_type)}</span></div>`);
          } else {
            _push2(`<!---->`);
          }
          if (!docForm.exam_type) {
            _push2(`<!--[--><div class="col-12 col-md-6"><label class="pmr-label">${ssrInterpolate(tt("select_template", "Modelo"))}</label><select class="form-select form-select-sm"><option value=""${ssrIncludeBooleanAttr(Array.isArray(docForm.report_setting_content_id) ? ssrLooseContain(docForm.report_setting_content_id, "") : ssrLooseEqual(docForm.report_setting_content_id, "")) ? " selected" : ""}>${ssrInterpolate(tt("select", "Selecione"))}</option><!--[-->`);
            ssrRenderList(docTemplates.value, (group) => {
              _push2(`<optgroup${ssrRenderAttr("label", group.report_setting_title)}><!--[-->`);
              ssrRenderList(group.contents || [], (tpl) => {
                _push2(`<option${ssrRenderAttr("value", tpl.id)}${ssrIncludeBooleanAttr(Array.isArray(docForm.report_setting_content_id) ? ssrLooseContain(docForm.report_setting_content_id, tpl.id) : ssrLooseEqual(docForm.report_setting_content_id, tpl.id)) ? " selected" : ""}>${ssrInterpolate(tpl.label)}</option>`);
              });
              _push2(`<!--]--></optgroup>`);
            });
            _push2(`<!--]--></select></div><div class="col-12 col-md-6"><label class="pmr-label">${ssrInterpolate(tt("doc_title", "Título"))}</label><input${ssrRenderAttr("value", docForm.title)} type="text" class="form-control form-control-sm"></div><!--]-->`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div><div class="mb-0"><label class="pmr-label">${ssrInterpolate(tt("doc_content", "Conteúdo"))}</label>`);
          if (showDocModal.value) {
            _push2(ssrRenderComponent(_sfc_main$1, {
              key: `doc-${docForm.report_setting_content_id || docForm.exam_type || "new"}`,
              modelValue: docForm.content,
              "onUpdate:modelValue": ($event) => docForm.content = $event,
              height: 360,
              placeholder: tt("doc_content_ph", "Conteúdo da documentação...")
            }, null, _parent));
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div></div><div class="modal-footer py-2"><button type="button" class="btn btn-secondary btn-sm">${ssrInterpolate(tt("cancel", "Cancelar"))}</button><button type="button" class="btn btn-primary btn-sm"${ssrIncludeBooleanAttr(docSaving.value) ? " disabled" : ""}><i class="fas fa-save me-1"></i>${ssrInterpolate(tt("save_documentation", "Salvar documentação"))}</button></div></div></div></div>`);
        } else {
          _push2(`<!---->`);
        }
      }, "body", false, _parent);
      ssrRenderTeleport(_push, (_push2) => {
        if (showMedicationModal.value) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.5)" })}"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header py-2"><h6 class="modal-title"><i class="fas fa-prescription me-2" style="${ssrRenderStyle({ "color": "#e91e8c" })}"></i>Receituário de Medicamentos</h6><button type="button" class="btn-close"></button></div><div class="modal-body"><label class="pmr-label mb-1">Adicionar medicamento</label><div class="position-relative mb-2"><div class="input-group input-group-sm"><span class="input-group-text"><i class="fas fa-search"></i></span><input${ssrRenderAttr("value", medSearchQuery.value)} type="text" class="form-control form-control-sm" placeholder="Digite ao menos 2 letras…"${ssrIncludeBooleanAttr(prescription.value.length >= maxMedicines) ? " disabled" : ""}>`);
          if (medSearchLoading.value) {
            _push2(`<span class="input-group-text bg-transparent"><span class="spinner-border spinner-border-sm" style="${ssrRenderStyle({ "width": ".8rem", "height": ".8rem" })}"></span></span>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div>`);
          if (medSearchOpen.value && medSearchResults.value.length > 0) {
            _push2(`<ul class="list-group shadow-sm position-absolute w-100" style="${ssrRenderStyle({ "z-index": "1080", "top": "100%", "max-height": "280px", "overflow-y": "auto" })}"><!--[-->`);
            ssrRenderList(medSearchResults.value, (item) => {
              _push2(`<li class="list-group-item list-group-item-action py-1 px-2" style="${ssrRenderStyle({ "cursor": "pointer", "font-size": ".82rem" })}"><span class="fw-semibold">${ssrInterpolate(item.name)}</span>`);
              if (item.presentation) {
                _push2(`<span class="text-muted ms-1">(${ssrInterpolate(item.presentation)})</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</li>`);
            });
            _push2(`<!--]--></ul>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div>`);
          if (prescription.value.length >= maxMedicines) {
            _push2(`<div class="alert alert-warning py-1 px-2 small mb-2"><i class="fas fa-exclamation-triangle me-1"></i>Limite de ${ssrInterpolate(maxMedicines)} medicamentos atingido. </div>`);
          } else {
            _push2(`<!---->`);
          }
          if (prescription.value.length > 0) {
            _push2(`<div class="mb-2"><label class="pmr-label mb-1">Medicamentos da receita (${ssrInterpolate(prescription.value.length)}/${ssrInterpolate(maxMedicines)})</label><ul class="list-group"><!--[-->`);
            ssrRenderList(prescription.value, (item, idx) => {
              _push2(`<li class="list-group-item d-flex justify-content-between align-items-center py-1 px-2" style="${ssrRenderStyle({ "font-size": ".82rem" })}"><span><span class="badge bg-secondary me-1">${ssrInterpolate(idx + 1)}</span><span class="fw-semibold">${ssrInterpolate(item.name)}</span></span><button type="button" class="btn btn-sm btn-link text-danger p-0"><i class="fas fa-times"></i></button></li>`);
            });
            _push2(`<!--]--></ul></div>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`<label class="pmr-label mb-1">Conteúdo da receita</label><textarea class="form-control form-control-sm" rows="10" placeholder="Linhas formatadas aparecem aqui.">${ssrInterpolate(medicineLists.value)}</textarea></div><div class="modal-footer py-2 d-flex justify-content-between"><button type="button" class="btn btn-outline-secondary btn-sm"${ssrIncludeBooleanAttr(prescription.value.length === 0 && !medicineLists.value) ? " disabled" : ""}><i class="fas fa-eraser me-1"></i>Limpar </button><div><button type="button" class="btn btn-secondary btn-sm">${ssrInterpolate(tt("cancel", "Cancelar"))}</button><button type="button" class="btn btn-primary btn-sm"${ssrIncludeBooleanAttr(quickActionBusy.value || !medicineLists.value.trim()) ? " disabled" : ""}><i class="fas fa-print me-1"></i>Emitir Receita </button></div></div></div></div></div>`);
        } else {
          _push2(`<!---->`);
        }
      }, "body", false, _parent);
      ssrRenderTeleport(_push, (_push2) => {
        if (showProcedureModal.value) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.5)" })}"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header py-2"><h6 class="modal-title"><i class="fas fa-stethoscope me-2" style="${ssrRenderStyle({ "color": "#03a9f3" })}"></i>${ssrInterpolate(tt("procedure_title", "Solicitação de Procedimentos"))}</h6><button type="button" class="btn-close"></button></div><div class="modal-body"><div class="row g-2 mb-2"><div class="col-12 col-md-7"><label class="pmr-label mb-1">${ssrInterpolate(tt("procedure_search_label", "Procedimento"))}</label><div class="position-relative"><div class="input-group input-group-sm"><span class="input-group-text"><i class="fas fa-search"></i></span><input${ssrRenderAttr("value", procSearchQuery.value)} type="text" class="form-control form-control-sm"${ssrRenderAttr("placeholder", tt("procedure_search_ph", "Buscar procedimento..."))}${ssrIncludeBooleanAttr(procSelected.value.length + indSelected.value.length >= maxProcSolicitations) ? " disabled" : ""}>`);
          if (procSearchLoading.value) {
            _push2(`<span class="input-group-text bg-transparent"><span class="spinner-border spinner-border-sm" style="${ssrRenderStyle({ "width": ".8rem", "height": ".8rem" })}"></span></span>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div>`);
          if (procSearchOpen.value && procSearchResults.value.length > 0) {
            _push2(`<ul class="list-group shadow-sm position-absolute w-100" style="${ssrRenderStyle({ "z-index": "1080", "top": "100%", "max-height": "240px", "overflow-y": "auto" })}"><!--[-->`);
            ssrRenderList(procSearchResults.value, (item) => {
              _push2(`<li class="list-group-item list-group-item-action py-1 px-2" style="${ssrRenderStyle({ "cursor": "pointer", "font-size": ".82rem" })}"><span class="fw-semibold">${ssrInterpolate(item.name)}</span>`);
              if (item.code) {
                _push2(`<span class="text-muted ms-1 small">(${ssrInterpolate(item.code)})</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</li>`);
            });
            _push2(`<!--]--></ul>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div></div><div class="col-12 col-md-5"><label class="pmr-label mb-1">${ssrInterpolate(tt("procedure_type_label", "Tipo"))}</label><select class="form-select form-select-sm"><option value=""${ssrIncludeBooleanAttr(Array.isArray(procTypeSelected.value) ? ssrLooseContain(procTypeSelected.value, "") : ssrLooseEqual(procTypeSelected.value, "")) ? " selected" : ""}>—</option><option value="rotina"${ssrIncludeBooleanAttr(Array.isArray(procTypeSelected.value) ? ssrLooseContain(procTypeSelected.value, "rotina") : ssrLooseEqual(procTypeSelected.value, "rotina")) ? " selected" : ""}>${ssrInterpolate(tt("procedure_type_rotina", "Rotina"))}</option><option value="urgencia"${ssrIncludeBooleanAttr(Array.isArray(procTypeSelected.value) ? ssrLooseContain(procTypeSelected.value, "urgencia") : ssrLooseEqual(procTypeSelected.value, "urgencia")) ? " selected" : ""}>${ssrInterpolate(tt("procedure_type_urgencia", "Urgência"))}</option><option value="controle"${ssrIncludeBooleanAttr(Array.isArray(procTypeSelected.value) ? ssrLooseContain(procTypeSelected.value, "controle") : ssrLooseEqual(procTypeSelected.value, "controle")) ? " selected" : ""}>${ssrInterpolate(tt("procedure_type_controle", "Controle"))}</option><option value="comparativo"${ssrIncludeBooleanAttr(Array.isArray(procTypeSelected.value) ? ssrLooseContain(procTypeSelected.value, "comparativo") : ssrLooseEqual(procTypeSelected.value, "comparativo")) ? " selected" : ""}>${ssrInterpolate(tt("procedure_type_comparativo", "Comparativo"))}</option></select></div></div><label class="pmr-label mb-1">${ssrInterpolate(tt("procedure_indication_label", "Indicação"))}</label><div class="position-relative mb-2"><div class="input-group input-group-sm"><span class="input-group-text"><i class="fas fa-search"></i></span><input${ssrRenderAttr("value", indSearchQuery.value)} type="text" class="form-control form-control-sm"${ssrRenderAttr("placeholder", tt("procedure_indication_ph", "Buscar indicação..."))}${ssrIncludeBooleanAttr(procSelected.value.length + indSelected.value.length >= maxProcSolicitations) ? " disabled" : ""}>`);
          if (indSearchLoading.value) {
            _push2(`<span class="input-group-text bg-transparent"><span class="spinner-border spinner-border-sm" style="${ssrRenderStyle({ "width": ".8rem", "height": ".8rem" })}"></span></span>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div>`);
          if (indSearchOpen.value && indSearchResults.value.length > 0) {
            _push2(`<ul class="list-group shadow-sm position-absolute w-100" style="${ssrRenderStyle({ "z-index": "1080", "top": "100%", "max-height": "240px", "overflow-y": "auto" })}"><!--[-->`);
            ssrRenderList(indSearchResults.value, (item) => {
              _push2(`<li class="list-group-item list-group-item-action py-1 px-2" style="${ssrRenderStyle({ "cursor": "pointer", "font-size": ".82rem" })}">${ssrInterpolate(item.description)}</li>`);
            });
            _push2(`<!--]--></ul>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div>`);
          if (procSelected.value.length + indSelected.value.length > 0) {
            _push2(`<div class="mb-2"><label class="pmr-label mb-1">${ssrInterpolate(tt("procedure_selected", "Selecionados"))} (${ssrInterpolate(procSelected.value.length + indSelected.value.length)}/${ssrInterpolate(maxProcSolicitations)})</label><ul class="list-group"><!--[-->`);
            ssrRenderList(procSelected.value, (item, idx) => {
              _push2(`<li class="list-group-item d-flex justify-content-between align-items-center py-1 px-2" style="${ssrRenderStyle({ "font-size": ".82rem" })}"><span><span class="badge bg-info text-dark me-1">P</span><span class="fw-semibold">${ssrInterpolate(item.name)}</span>`);
              if (item.type) {
                _push2(`<span class="text-muted ms-1 small">— ${ssrInterpolate(item.type_label)}</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</span><button type="button" class="btn btn-sm btn-link text-danger p-0"><i class="fas fa-times"></i></button></li>`);
            });
            _push2(`<!--]--><!--[-->`);
            ssrRenderList(indSelected.value, (item, idx) => {
              _push2(`<li class="list-group-item d-flex justify-content-between align-items-center py-1 px-2" style="${ssrRenderStyle({ "font-size": ".82rem" })}"><span><span class="badge bg-secondary me-1">I</span>${ssrInterpolate(item.description)}</span><button type="button" class="btn btn-sm btn-link text-danger p-0"><i class="fas fa-times"></i></button></li>`);
            });
            _push2(`<!--]--></ul></div>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`<label class="pmr-label mb-1">${ssrInterpolate(tt("procedure_content", "Conteúdo"))}</label><textarea class="form-control form-control-sm" rows="8" placeholder="Linhas formatadas aparecem aqui.">${ssrInterpolate(procedureLists.value)}</textarea></div><div class="modal-footer py-2 d-flex justify-content-between"><button type="button" class="btn btn-outline-secondary btn-sm"${ssrIncludeBooleanAttr(procSelected.value.length === 0 && indSelected.value.length === 0 && !procedureLists.value) ? " disabled" : ""}><i class="fas fa-eraser me-1"></i>Limpar </button><div><button type="button" class="btn btn-secondary btn-sm">${ssrInterpolate(tt("cancel", "Cancelar"))}</button><button type="button" class="btn btn-primary btn-sm"${ssrIncludeBooleanAttr(quickActionBusy.value || !procedureLists.value.trim()) ? " disabled" : ""}><i class="fas fa-print me-1"></i>${ssrInterpolate(tt("procedure_emit", "Emitir Solicitação"))}</button></div></div></div></div></div>`);
        } else {
          _push2(`<!---->`);
        }
      }, "body", false, _parent);
      ssrRenderTeleport(_push, (_push2) => {
        if (showCataractModal.value) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.5)" })}"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header py-2"><h6 class="modal-title"><i class="fas fa-eye me-2" style="${ssrRenderStyle({ "color": "#e91e8c" })}"></i>${ssrInterpolate(tt("cataract_title", "Receituário de Catarata"))}</h6><button type="button" class="btn-close"></button></div><div class="modal-body"><label class="pmr-label mb-1">${ssrInterpolate(tt("cataract_eye_label", "Olho operado"))}</label><div class="btn-group w-100 mb-3" role="group"><input type="radio" class="btn-check" id="cataractEyeRight" value="right"${ssrIncludeBooleanAttr(ssrLooseEqual(cataractForm.eye, "right")) ? " checked" : ""}><label class="btn btn-outline-primary btn-sm" for="cataractEyeRight">${ssrInterpolate(tt("cataract_eye_right", "OD"))}</label><input type="radio" class="btn-check" id="cataractEyeLeft" value="left"${ssrIncludeBooleanAttr(ssrLooseEqual(cataractForm.eye, "left")) ? " checked" : ""}><label class="btn btn-outline-primary btn-sm" for="cataractEyeLeft">${ssrInterpolate(tt("cataract_eye_left", "OE"))}</label><input type="radio" class="btn-check" id="cataractEyeBoth" value="both"${ssrIncludeBooleanAttr(ssrLooseEqual(cataractForm.eye, "both")) ? " checked" : ""}><label class="btn btn-outline-primary btn-sm" for="cataractEyeBoth">${ssrInterpolate(tt("cataract_eye_both", "AO"))}</label></div><label class="pmr-label mb-1">${ssrInterpolate(tt("cataract_template", "Modelo"))}</label><select class="form-select form-select-sm mb-3"><option value="pre_operatorio"${ssrIncludeBooleanAttr(Array.isArray(cataractForm.template) ? ssrLooseContain(cataractForm.template, "pre_operatorio") : ssrLooseEqual(cataractForm.template, "pre_operatorio")) ? " selected" : ""}>${ssrInterpolate(tt("cataract_template_pre", "Pré-operatório"))}</option><option value="pos_operatorio"${ssrIncludeBooleanAttr(Array.isArray(cataractForm.template) ? ssrLooseContain(cataractForm.template, "pos_operatorio") : ssrLooseEqual(cataractForm.template, "pos_operatorio")) ? " selected" : ""}>${ssrInterpolate(tt("cataract_template_pos", "Pós-operatório"))}</option><option value="instrucoes_cirurgicas"${ssrIncludeBooleanAttr(Array.isArray(cataractForm.template) ? ssrLooseContain(cataractForm.template, "instrucoes_cirurgicas") : ssrLooseEqual(cataractForm.template, "instrucoes_cirurgicas")) ? " selected" : ""}>${ssrInterpolate(tt("cataract_template_inst", "Instruções cirúrgicas"))}</option></select><div class="${ssrRenderClass([{ "opacity-75": cataractForm.template !== "instrucoes_cirurgicas" }, "row g-2 mb-1"])}"><div class="col-7"><label class="pmr-label mb-1">${ssrInterpolate(tt("cataract_date", "Data"))}</label><input${ssrRenderAttr("value", cataractForm.date_surgery)} type="text" class="form-control form-control-sm" placeholder="dd/mm/aaaa" maxlength="10"></div><div class="col-5"><label class="pmr-label mb-1">${ssrInterpolate(tt("cataract_hour", "Hora"))}</label><input${ssrRenderAttr("value", cataractForm.hour_surgery)} type="time" class="form-control form-control-sm"></div></div></div><div class="modal-footer py-2"><button type="button" class="btn btn-secondary btn-sm">${ssrInterpolate(tt("cancel", "Cancelar"))}</button><button type="button" class="btn btn-primary btn-sm"${ssrIncludeBooleanAttr(quickActionBusy.value || !cataractForm.eye) ? " disabled" : ""}><i class="fas fa-print me-1"></i>${ssrInterpolate(tt("cataract_emit", "Emitir"))}</button></div></div></div></div>`);
        } else {
          _push2(`<!---->`);
        }
      }, "body", false, _parent);
      ssrRenderTeleport(_push, (_push2) => {
        if (showAttendanceCertModal.value) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.5)" })}"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header py-2"><h6 class="modal-title"><i class="fas fa-file-signature me-2" style="${ssrRenderStyle({ "color": "#03a9f3" })}"></i>${ssrInterpolate(tt("attendance_certificate_title", "Atestado de Comparecimento"))}</h6><button type="button" class="btn-close"></button></div><div class="modal-body"><label class="pmr-label mb-1">${ssrInterpolate(tt("certificate_obs_label", "Observações"))}</label>`);
          _push2(ssrRenderComponent(_sfc_main$1, {
            modelValue: attendanceForm.content,
            "onUpdate:modelValue": ($event) => attendanceForm.content = $event,
            height: 280,
            placeholder: tt("certificate_obs_ph", "Observações...")
          }, null, _parent));
          _push2(`</div><div class="modal-footer py-2"><button type="button" class="btn btn-secondary btn-sm">${ssrInterpolate(tt("cancel", "Cancelar"))}</button><button type="button" class="btn btn-primary btn-sm"${ssrIncludeBooleanAttr(quickActionBusy.value) ? " disabled" : ""}><i class="fas fa-print me-1"></i>${ssrInterpolate(tt("certificate_emit", "Emitir"))}</button></div></div></div></div>`);
        } else {
          _push2(`<!---->`);
        }
      }, "body", false, _parent);
      ssrRenderTeleport(_push, (_push2) => {
        if (showMedicalCertModal.value) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.5)" })}"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header py-2"><h6 class="modal-title"><i class="fas fa-notes-medical me-2" style="${ssrRenderStyle({ "color": "#e91e8c" })}"></i>${ssrInterpolate(tt("medical_certificate_title", "Atestado Médico"))}</h6><button type="button" class="btn-close"></button></div><div class="modal-body"><div class="row g-2 mb-2"><div class="col-7"><label class="pmr-label mb-1">${ssrInterpolate(tt("medical_cert_days_label", "Dias de afastamento"))}</label><input${ssrRenderAttr("value", medicalForm.days)} type="number" min="1" max="365" step="1" class="form-control form-control-sm"></div><div class="col-5"><label class="pmr-label mb-1">${ssrInterpolate(tt("medical_cert_date_label", "Data"))}</label><input${ssrRenderAttr("value", medicalForm.date)} type="text" class="form-control form-control-sm" placeholder="dd/mm/aaaa" maxlength="10"><button type="button" class="btn btn-link btn-sm p-0 mt-1"><small>${ssrInterpolate(tt("medical_cert_date_today", "Hoje"))}</small></button></div></div>`);
          if (medicalForm.daysPreview) {
            _push2(`<div class="alert alert-info py-2 px-2 small mb-2"><i class="fas fa-eye me-1"></i><strong>${ssrInterpolate(tt("medical_cert_days_preview", "Pré-visualização"))}:</strong> ${ssrInterpolate(medicalForm.daysPreview)}</div>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`<label class="pmr-label mb-1">${ssrInterpolate(tt("certificate_obs_label", "Observações"))}</label><textarea class="form-control form-control-sm" rows="5" maxlength="5000"${ssrRenderAttr("placeholder", tt("certificate_obs_ph", "Observações..."))}>${ssrInterpolate(medicalForm.content)}</textarea><small class="text-muted d-block mt-1">${ssrInterpolate((medicalForm.content || "").length)}/5000</small></div><div class="modal-footer py-2"><button type="button" class="btn btn-secondary btn-sm">${ssrInterpolate(tt("cancel", "Cancelar"))}</button><button type="button" class="btn btn-primary btn-sm"${ssrIncludeBooleanAttr(quickActionBusy.value || !medicalForm.days || medicalForm.days < 1 || medicalForm.days > 365) ? " disabled" : ""}><i class="fas fa-print me-1"></i>${ssrInterpolate(tt("certificate_emit", "Emitir"))}</button></div></div></div></div>`);
        } else {
          _push2(`<!---->`);
        }
      }, "body", false, _parent);
      ssrRenderTeleport(_push, (_push2) => {
        if (showExamHubModal.value) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.5)" })}"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header py-2"><h6 class="modal-title"><i class="fas fa-microscope me-2" style="${ssrRenderStyle({ "color": "#03a9f3" })}"></i>${ssrInterpolate(tt("exam_hub_title", "Laudos de Exame"))}</h6><button type="button" class="btn-close"></button></div><div class="modal-body"><p class="text-muted small mb-3">${ssrInterpolate(tt("exam_hub_help", "Selecione o exame para emitir o laudo."))}</p><div class="row g-2"><!--[-->`);
          ssrRenderList(examReports.value, (exam) => {
            _push2(`<div class="col-6 col-md-4 col-lg-3">`);
            if (!exam.subtypes || exam.subtypes.length === 0) {
              _push2(`<button type="button" class="btn pmr-exam-card w-100 h-100"${ssrRenderAttr("title", exam.label)}><i class="${ssrRenderClass(`fas ${exam.icon} pmr-exam-card-icon`)}"></i><span class="pmr-exam-card-label">${ssrInterpolate(exam.label)}</span></button>`);
            } else {
              _push2(`<div class="dropdown w-100 h-100"><button type="button" class="btn pmr-exam-card pmr-exam-card-multi w-100 h-100 dropdown-toggle" data-bs-toggle="dropdown"${ssrRenderAttr("title", exam.label)}><i class="${ssrRenderClass(`fas ${exam.icon} pmr-exam-card-icon`)}"></i><span class="pmr-exam-card-label">${ssrInterpolate(exam.label)}</span></button><ul class="dropdown-menu"><!--[-->`);
              ssrRenderList(exam.subtypes, (sub) => {
                _push2(`<li><button type="button" class="dropdown-item"><i class="fas fa-angle-right me-2 text-muted small"></i>${ssrInterpolate(sub.label)}</button></li>`);
              });
              _push2(`<!--]--></ul></div>`);
            }
            _push2(`</div>`);
          });
          _push2(`<!--]--></div></div><div class="modal-footer py-2"><button type="button" class="btn btn-secondary btn-sm">${ssrInterpolate(tt("cancel", "Cancelar"))}</button></div></div></div></div>`);
        } else {
          _push2(`<!---->`);
        }
      }, "body", false, _parent);
      ssrRenderTeleport(_push, (_push2) => {
        if (showTonometryModal.value) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.5)" })}"><div class="modal-dialog modal-xl modal-dialog-centered" style="${ssrRenderStyle({ "height": "90vh" })}"><div class="modal-content" style="${ssrRenderStyle({ "height": "100%" })}"><div class="modal-header py-2"><h6 class="modal-title mb-0"><i class="fas fa-print me-2" style="${ssrRenderStyle({ "color": "#e91e8c" })}"></i>${ssrInterpolate(tt("print_tonometry", "Laudo de Tonômetria"))}</h6><button type="button" class="btn-close"></button></div><div class="modal-body p-0" style="${ssrRenderStyle({ "flex": "1", "overflow": "hidden" })}"><iframe${ssrRenderAttr("src", tonometryPdfSrc.value)} style="${ssrRenderStyle({ "width": "100%", "height": "100%", "border": "none", "display": "block" })}" title="Laudo de Tonômetria"></iframe></div></div></div></div>`);
        } else {
          _push2(`<!---->`);
        }
      }, "body", false, _parent);
      ssrRenderTeleport(_push, (_push2) => {
        if (showPresbyopiaObsModal.value) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.5)" })}"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header py-2"><h6 class="modal-title"><i class="fas fa-glasses me-2" style="${ssrRenderStyle({ "color": "#00bcd4" })}"></i>${ssrInterpolate(tt("lenses_obs", "Observação de lentes"))}</h6><button type="button" class="btn-close"></button></div><div class="modal-body"><textarea class="form-control form-control-sm" rows="6"${ssrRenderAttr("placeholder", tt("presbyopia_obs_ph", "Observações sobre as lentes..."))}>${ssrInterpolate(presbyopiaObsForm.content)}</textarea></div><div class="modal-footer py-2"><button type="button" class="btn btn-secondary btn-sm">${ssrInterpolate(tt("cancel", "Cancelar"))}</button><button type="button" class="btn btn-primary btn-sm"><i class="fas fa-check me-1"></i>${ssrInterpolate(tt("close", "Confirmar"))}</button></div></div></div></div>`);
        } else {
          _push2(`<!---->`);
        }
      }, "body", false, _parent);
      if (showPdfPreview.value) {
        _push(ssrRenderComponent(_sfc_main$2, {
          url: pdfPreviewUrl.value,
          title: pdfPreviewTitle.value,
          onClose: closePdfPreview
        }, null, _parent));
      } else {
        _push(`<!---->`);
      }
      if (__props.isEdit && __props.urls.store_file) {
        _push(ssrRenderComponent(MedicalRecordFileUploadModal, {
          show: showUploadModal.value,
          "store-url": __props.urls.store_file,
          storage: storageState.value,
          "csrf-token": csrf(),
          onClose: ($event) => showUploadModal.value = false,
          onUploaded: onFileUploaded,
          onStorageUpdated
        }, null, _parent));
      } else {
        _push(`<!---->`);
      }
      _push(`<!--]-->`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/MedicalRecords/Components/MedicalRecordForm.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
