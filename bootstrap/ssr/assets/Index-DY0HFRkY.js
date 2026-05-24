import { ref, computed, onMounted, onBeforeUnmount, reactive, watch, mergeProps, withCtx, createVNode, withDirectives, withKeys, vModelText, openBlock, createBlock, createCommentVNode, createTextVNode, vModelRadio, Fragment, renderList, toDisplayString, vModelSelect, vShow, withModifiers, Teleport, nextTick, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderAttr, ssrRenderClass, ssrRenderStyle, ssrIncludeBooleanAttr, ssrLooseEqual, ssrLooseContain, ssrRenderList, ssrInterpolate, ssrRenderTeleport } from "vue/server-renderer";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-CYjDf0Y-.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
import "@inertiajs/vue3";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    entity: { type: Object, required: true },
    doctors: { type: Array, default: () => [] },
    patients: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
    ai: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const patients = ref(props.patients || []);
    const selectedPatient = ref(null);
    const examUrls = ref({});
    const brokenUrls = ref({});
    const urlsLoading = ref(false);
    const loading = ref(false);
    const search = ref("");
    const period = ref("hoje");
    const laterality = ref("");
    const doctorId = ref("");
    const examTypeId = ref("");
    const examStatus = ref("");
    const showFilters = ref(false);
    const selectedExamIds = ref([]);
    const showViewerModal = ref(false);
    const viewerExams = ref([]);
    const viewerPanelCount = ref(1);
    const viewerActivePanel = ref(0);
    const viewerPanelExams = ref([null, null, null, null]);
    const viewerPanelUrls = ref([null, null, null, null]);
    const viewerPanelLoading = ref([false, false, false, false]);
    const viewerPanelBroken = ref([false, false, false, false]);
    const viewerPanelFlipped = ref([false, false, false, false]);
    const viewerLaserMode = ref(false);
    const viewerFitMode = ref(false);
    const viewerAllMode = ref(false);
    const viewerSplitMode = ref(false);
    const viewerLensActive = ref(false);
    const viewerLensVisible = ref(false);
    const viewerLensX = ref(0);
    const viewerLensY = ref(0);
    const viewerZoom = ref(3);
    const _viewerW = ref(0);
    const _viewerH = ref(0);
    const _lensImgX = ref(0);
    const _lensImgY = ref(0);
    const _lensUrl = ref(null);
    const showPrintModal = ref(false);
    const printExams = ref([]);
    const printCols = ref(2);
    const printOrientation = ref("portrait");
    const availableExamTypes = computed(() => {
      const map = /* @__PURE__ */ new Map();
      for (const p of patients.value) {
        for (const e of p.exams) {
          if (e.exam_type && !map.has(e.exam_id)) {
            map.set(e.exam_id, { id: e.exam_id, name: e.exam_type.name });
          }
        }
      }
      return [...map.values()].sort((a, b) => (a.name ?? "").localeCompare(b.name ?? ""));
    });
    function examMatchesFilters(e) {
      if (laterality.value) {
        if (laterality.value === "ao") {
          if (e.laterality === 1 || e.laterality === 2) return false;
        } else {
          const target = laterality.value === "od" ? 1 : 2;
          if (e.laterality !== target) return false;
        }
      }
      if (examTypeId.value && String(e.exam_id) !== String(examTypeId.value)) return false;
      if (examStatus.value && deriveStatus(e) !== examStatus.value) return false;
      return true;
    }
    function deriveStatus(exam) {
      if (exam.active === false || exam.active === 0) return "cancelado";
      if (!exam.archive) return "solicitado";
      return "realizado";
    }
    const filteredPatients = computed(() => {
      let list = patients.value;
      const q = search.value.trim().toLowerCase();
      if (q) {
        list = list.filter(
          (p) => {
            var _a;
            return (((_a = p.person) == null ? void 0 : _a.full_name) ?? p.full_name ?? "").toLowerCase().includes(q) || (p.code ?? "").toLowerCase().includes(q);
          }
        );
      }
      if (laterality.value || examTypeId.value || examStatus.value) {
        list = list.filter((p) => p.exams.some((e) => examMatchesFilters(e)));
      }
      return list;
    });
    const filteredExams = computed(() => {
      if (!selectedPatient.value) return [];
      return selectedPatient.value.exams.filter((e) => examMatchesFilters(e));
    });
    const groupedExams = computed(() => {
      var _a;
      const groups = [];
      const seen = {};
      for (const exam of filteredExams.value) {
        const date = ((_a = exam.created_at) == null ? void 0 : _a.substring(0, 10)) ?? "unknown";
        const equipId = exam.entity_integrator_equipment_id ?? "";
        const typeId = exam.exam_id ?? "";
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
      return selectedPatient.value.exams.filter((e) => selectedExamIds.value.includes(e.id));
    });
    const totalExams = computed(() => patients.value.reduce((s, p) => {
      var _a;
      return s + (((_a = p.exams) == null ? void 0 : _a.length) ?? 0);
    }, 0));
    const viewerActivePanelIndex = computed(() => {
      const exam = viewerPanelExams.value[viewerActivePanel.value];
      if (!exam) return -1;
      return viewerExams.value.findIndex((e) => e.id === exam.id);
    });
    const viewerPanelGridStyle = computed(() => {
      const base = "display:grid;gap:2px;padding:2px;overflow:hidden;min-height:0;";
      return base + `grid-template-columns:repeat(${viewerPanelCount.value},1fr);`;
    });
    const viewerLensStyle = computed(() => {
      const url = _lensUrl.value || viewerPanelUrls.value[viewerActivePanel.value];
      if (!viewerLensVisible.value || !url) return "display:none;";
      const lensSize = 360;
      const bW = _viewerW.value * viewerZoom.value;
      const bH = _viewerH.value * viewerZoom.value;
      const lookX = _lensImgX.value ?? viewerLensX.value;
      const lookY = _lensImgY.value ?? viewerLensY.value;
      const bX = -(lookX * viewerZoom.value - lensSize / 2);
      const bY = -(lookY * viewerZoom.value - lensSize / 2);
      return `position:fixed;left:${viewerLensX.value}px;top:${viewerLensY.value}px;width:${lensSize}px;height:${lensSize}px;border-radius:50%;border:2px solid rgba(255,255,255,0.8);transform:translate(-50%,-50%);pointer-events:none;background-image:url(${url});background-repeat:no-repeat;background-size:${bW}px ${bH}px;background-position:${bX}px ${bY}px;box-shadow:0 0 0 1px rgba(0,0,0,0.5);z-index:10000;`;
    });
    function initials(name) {
      if (!name) return "?";
      const parts = String(name).trim().split(" ").filter(Boolean);
      return parts.length >= 2 ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase() : parts[0][0].toUpperCase();
    }
    function avatarColor(name) {
      const palette = ["#3b82f6", "#8b5cf6", "#06b6d4", "#10b981", "#f59e0b", "#ef4444", "#ec4899", "#6366f1"];
      if (!name) return palette[0];
      let h = 0;
      for (const c of String(name)) h = c.charCodeAt(0) + ((h << 5) - h);
      return palette[Math.abs(h) % palette.length];
    }
    function latLabel(v) {
      return { 1: "OD", 2: "OE" }[v] ?? "AO";
    }
    function formatDateFull(ymd) {
      if (!ymd || ymd === "unknown") return "—";
      const [y, m, d] = String(ymd).split("-");
      return `${d}/${m}/${y}`;
    }
    function formatDateTime(dt) {
      if (!dt) return "—";
      const d = new Date(dt);
      if (isNaN(d.getTime())) return String(dt);
      return d.toLocaleDateString("pt-BR") + " " + d.toLocaleTimeString("pt-BR", { hour: "2-digit", minute: "2-digit", second: "2-digit" });
    }
    function isSelected(examId) {
      return selectedExamIds.value.includes(examId);
    }
    function toggleExamSelection(examId) {
      const idx = selectedExamIds.value.indexOf(examId);
      if (idx >= 0) selectedExamIds.value.splice(idx, 1);
      else selectedExamIds.value.push(examId);
    }
    function groupLatMatching(group, lat) {
      if (lat === "all") return group.exams;
      if (lat === "od") return group.exams.filter((e) => e.laterality === 1);
      if (lat === "oe") return group.exams.filter((e) => e.laterality === 2);
      return group.exams.filter((e) => e.laterality !== 1 && e.laterality !== 2);
    }
    function groupLatActive(group, lat) {
      const matching = groupLatMatching(group, lat);
      return matching.length > 0 && matching.every((e) => selectedExamIds.value.includes(e.id));
    }
    function selectExamByLaterality(group, lat) {
      const matching = groupLatMatching(group, lat);
      if (!matching.length) return;
      const allSelected = matching.every((e) => selectedExamIds.value.includes(e.id));
      if (allSelected) {
        selectedExamIds.value = selectedExamIds.value.filter((id) => !matching.find((e) => e.id === id));
      } else {
        const toAdd = matching.filter((e) => !selectedExamIds.value.includes(e.id)).map((e) => e.id);
        selectedExamIds.value = [...selectedExamIds.value, ...toAdd];
      }
    }
    async function selectPatient(patient) {
      selectedPatient.value = patient;
      examUrls.value = {};
      brokenUrls.value = {};
      selectedExamIds.value = [];
      urlsLoading.value = true;
      try {
        const url = props.urls.patient_urls.replace("__ID__", patient.id);
        const res = await fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" } });
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
      search.value = "";
      await fetchPatients();
    }
    function clearFilters() {
      search.value = "";
      laterality.value = "";
      examTypeId.value = "";
      examStatus.value = "";
    }
    async function fetchPatients() {
      loading.value = true;
      try {
        const params = new URLSearchParams({ period: period.value });
        if (doctorId.value) params.append("doctor_id", doctorId.value);
        const res = await fetch(`${props.urls.search}?${params}`, {
          headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" }
        });
        const data = await res.json();
        patients.value = data.patients ?? [];
      } catch (e) {
        console.error("Erro ao buscar pacientes:", e);
      } finally {
        loading.value = false;
      }
    }
    function openViewerModal(exams, startIndex = 0) {
      if (!exams || exams.length === 0) return;
      viewerExams.value = exams;
      viewerPanelExams.value = [null, null, null, null];
      viewerPanelUrls.value = [null, null, null, null];
      viewerPanelLoading.value = [false, false, false, false];
      viewerPanelBroken.value = [false, false, false, false];
      viewerPanelFlipped.value = [false, false, false, false];
      viewerActivePanel.value = 0;
      viewerAllMode.value = false;
      viewerSplitMode.value = false;
      viewerLaserMode.value = false;
      viewerFitMode.value = false;
      viewerLensActive.value = false;
      viewerLensVisible.value = false;
      viewerPanelCount.value = 1;
      showViewerModal.value = true;
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
      const exams = [...viewerPanelExams.value];
      const urls = [...viewerPanelUrls.value];
      const loadingArr = [...viewerPanelLoading.value];
      const broken = [...viewerPanelBroken.value];
      exams[pi] = exam;
      urls[pi] = null;
      loadingArr[pi] = false;
      broken[pi] = false;
      viewerPanelExams.value = exams;
      viewerPanelUrls.value = urls;
      viewerPanelLoading.value = loadingArr;
      viewerPanelBroken.value = broken;
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
      const broken = [...viewerPanelBroken.value];
      const urls = [...viewerPanelUrls.value];
      loadingArr[pi] = false;
      broken[pi] = true;
      urls[pi] = null;
      viewerPanelLoading.value = loadingArr;
      viewerPanelBroken.value = broken;
      viewerPanelUrls.value = urls;
    }
    function setViewerPanelCount(n) {
      viewerPanelCount.value = n;
      viewerSplitMode.value = false;
      for (let i = 0; i < n; i++) {
        if (!viewerPanelExams.value[i] && viewerExams.value[i]) {
          setPanelExam(i, viewerExams.value[i]);
        }
      }
    }
    function viewerToggleAll() {
      if (viewerAllMode.value) {
        viewerAllMode.value = false;
        viewerPanelExams.value = [null, null, null, null];
        viewerPanelUrls.value = [null, null, null, null];
        viewerPanelLoading.value = [false, false, false, false];
        viewerPanelBroken.value = [false, false, false, false];
        viewerPanelCount.value = 0;
        nextTick(() => {
          if (viewerSplitMode.value) {
            viewerPanelCount.value = 2;
            const od = viewerExams.value.find((e) => e.laterality === 1);
            const oe = viewerExams.value.find((e) => e.laterality === 2);
            if (od) setPanelExam(0, od);
            if (oe) setPanelExam(1, oe);
          } else {
            viewerPanelCount.value = 1;
            if (viewerExams.value[0]) setPanelExam(0, viewerExams.value[0]);
          }
        });
      } else {
        viewerAllMode.value = true;
        viewerPanelExams.value = [null, null, null, null];
        viewerPanelUrls.value = [null, null, null, null];
        viewerPanelLoading.value = [false, false, false, false];
        viewerPanelBroken.value = [false, false, false, false];
      }
    }
    function viewerSplitOdOs() {
      if (viewerSplitMode.value) {
        viewerSplitMode.value = false;
        if (!viewerAllMode.value) {
          viewerPanelCount.value = 1;
          viewerPanelExams.value = [null, null, null, null];
          viewerPanelUrls.value = [null, null, null, null];
          viewerPanelLoading.value = [false, false, false, false];
          viewerPanelBroken.value = [false, false, false, false];
          if (viewerExams.value[0]) setPanelExam(0, viewerExams.value[0]);
        }
      } else {
        viewerSplitMode.value = true;
        if (!viewerAllMode.value) {
          viewerPanelCount.value = 2;
          const od = viewerExams.value.find((e) => e.laterality === 1);
          const oe = viewerExams.value.find((e) => e.laterality === 2);
          if (od) setPanelExam(0, od);
          if (oe) setPanelExam(1, oe);
        }
      }
    }
    function allGridExams() {
      if (viewerSplitMode.value) {
        return viewerExams.value.filter((e) => e.laterality === 1 || e.laterality === 2);
      }
      return viewerExams.value;
    }
    function panelStripExams(pi) {
      const exam = viewerPanelExams.value[pi];
      if (!exam) return viewerExams.value;
      const lat = exam.laterality;
      if (lat === 1 || lat === 2) {
        return viewerExams.value.filter((e) => e.laterality === lat);
      }
      return viewerExams.value;
    }
    function toggleAllFlip() {
      viewerLaserMode.value = !viewerLaserMode.value;
      viewerPanelFlipped.value = [
        viewerLaserMode.value,
        viewerLaserMode.value,
        viewerLaserMode.value,
        viewerLaserMode.value
      ];
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
      const img = container.querySelector("img");
      if (!img) {
        viewerLensVisible.value = false;
        return;
      }
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
      const img = container.querySelector("img");
      if (!img) {
        viewerLensVisible.value = false;
        return;
      }
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
    function onKeyDown(e) {
      if (!showViewerModal.value) return;
      if (e.key === "Escape") {
        showViewerModal.value = false;
      }
      if (e.key === "ArrowLeft") {
        viewerPrev();
      }
      if (e.key === "ArrowRight") {
        viewerNext();
      }
    }
    function onPrintKey(e) {
      if (showPrintModal.value && e.key === "Escape") showPrintModal.value = false;
    }
    onMounted(() => {
      window.addEventListener("keydown", onKeyDown);
      window.addEventListener("keydown", onPrintKey);
    });
    onBeforeUnmount(() => {
      window.removeEventListener("keydown", onKeyDown);
      window.removeEventListener("keydown", onPrintKey);
    });
    const aiEnabled = computed(() => {
      var _a;
      return !!((_a = props.ai) == null ? void 0 : _a.enabled);
    });
    const aiWorkflows = computed(() => {
      var _a;
      return Array.isArray((_a = props.ai) == null ? void 0 : _a.workflows) ? props.ai.workflows : [];
    });
    const aiLabels = computed(() => {
      var _a;
      return ((_a = props.ai) == null ? void 0 : _a.labels) ?? {};
    });
    const aiLabel = (key, fallback = "") => {
      var _a;
      return ((_a = aiLabels.value) == null ? void 0 : _a[key]) ?? fallback;
    };
    const aiPatients = computed(() => patients.value.map((p) => {
      var _a;
      return {
        id: p.id,
        name: ((_a = p.person) == null ? void 0 : _a.full_name) ?? p.full_name,
        code: p.code
      };
    }));
    const aiSelectedPatient = computed(() => {
      var _a;
      if (!selectedPatient.value) return null;
      return {
        id: selectedPatient.value.id,
        name: ((_a = selectedPatient.value.person) == null ? void 0 : _a.full_name) ?? selectedPatient.value.full_name ?? "—",
        code: selectedPatient.value.code ?? ""
      };
    });
    const aiShowPatientSelector = computed(() => !aiSelectedPatient.value);
    const aiModalOpen = ref(false);
    const aiEstimating = ref(false);
    const aiSubmitting = ref(false);
    const aiEstimate = ref(null);
    const aiBalance = reactive({ available: "—", reserved: "—" });
    const aiAlert = reactive({ type: "", message: "" });
    const aiForm = reactive({
      workflow: aiWorkflows.value[0] ?? "exam_assistant",
      risk_level: "medium",
      patient_id: "",
      user_prompt: "",
      system_prompt: aiLabel("system_prompt_default", "Você é um assistente de apoio clínico. Nunca emita decisão final."),
      max_output_tokens: 700
    });
    const aiMode = computed(() => aiForm.workflow === "consensus_review" ? "consensus" : "validated");
    watch(
      () => [aiForm.workflow, aiForm.risk_level, aiForm.patient_id, aiForm.user_prompt, aiForm.system_prompt, aiForm.max_output_tokens],
      () => {
        aiEstimate.value = null;
      }
    );
    watch(
      () => {
        var _a;
        return ((_a = aiSelectedPatient.value) == null ? void 0 : _a.id) ?? null;
      },
      (patientId) => {
        if (patientId) {
          aiForm.patient_id = patientId;
          return;
        }
        if (aiForm.patient_id && !aiPatients.value.some((p) => p.id === aiForm.patient_id)) {
          aiForm.patient_id = "";
        }
      },
      { immediate: true }
    );
    watch(
      () => aiPatients.value.map((p) => p.id).join("|"),
      () => {
        var _a;
        if ((_a = aiSelectedPatient.value) == null ? void 0 : _a.id) return;
        if (aiForm.patient_id && !aiPatients.value.some((p) => p.id === aiForm.patient_id)) {
          aiForm.patient_id = "";
        }
      }
    );
    function openAiModal() {
      var _a;
      if ((_a = aiSelectedPatient.value) == null ? void 0 : _a.id) {
        aiForm.patient_id = aiSelectedPatient.value.id;
      }
      aiModalOpen.value = true;
    }
    function closeAiModal() {
      aiModalOpen.value = false;
    }
    function setAiAlert(type, message) {
      aiAlert.type = type;
      aiAlert.message = message;
    }
    function clearAiAlert() {
      aiAlert.type = "";
      aiAlert.message = "";
    }
    function aiWorkflowLabel(workflow) {
      if (workflow === "exam_assistant") return aiLabel("workflow_exam_assistant", "Assistente de exame");
      if (workflow === "consensus_review") return aiLabel("workflow_consensus_review", "Revisão de consistência");
      return workflow;
    }
    function aiPayload() {
      return {
        workflow: aiForm.workflow,
        mode: aiMode.value,
        risk_level: aiForm.risk_level,
        patient_id: aiForm.patient_id || null,
        medical_record_id: null,
        user_prompt: aiForm.user_prompt,
        system_prompt: aiForm.system_prompt,
        context: { specialty: "ophthalmology", source: "eye_images_top_modal" },
        attachments: [],
        expects_json: false,
        max_output_tokens: Number(aiForm.max_output_tokens || 700)
      };
    }
    async function estimateAiRun() {
      var _a, _b, _c, _d;
      clearAiAlert();
      if (!aiForm.user_prompt || aiForm.user_prompt.trim().length < 12) {
        setAiAlert("warning", aiLabel("prompt_min_chars", "O prompt clínico deve ter pelo menos 12 caracteres."));
        return;
      }
      aiEstimating.value = true;
      try {
        const { data } = await window.axios.post(route("panel.ai-runs.estimate"), aiPayload());
        aiEstimate.value = (data == null ? void 0 : data.estimate) ?? null;
        aiBalance.available = ((_a = data == null ? void 0 : data.balance) == null ? void 0 : _a.available) ?? "—";
        aiBalance.reserved = ((_b = data == null ? void 0 : data.balance) == null ? void 0 : _b.reserved) ?? "—";
      } catch (error) {
        setAiAlert("danger", ((_d = (_c = error == null ? void 0 : error.response) == null ? void 0 : _c.data) == null ? void 0 : _d.message) ?? aiLabel("estimate_failed", "Falha ao estimar custo."));
      } finally {
        aiEstimating.value = false;
      }
    }
    async function submitAiRun() {
      var _a, _b;
      clearAiAlert();
      if (!aiEstimate.value) {
        setAiAlert("warning", aiLabel("estimate", "Estime antes de executar."));
        return;
      }
      aiSubmitting.value = true;
      try {
        await window.axios.post(route("panel.ai-runs.store"), aiPayload());
        setAiAlert("success", aiLabel("run_created_waiting_review", "Execução criada e enviada para revisão médica."));
        aiForm.user_prompt = "";
        aiEstimate.value = null;
      } catch (error) {
        setAiAlert("danger", ((_b = (_a = error == null ? void 0 : error.response) == null ? void 0 : _a.data) == null ? void 0 : _b.message) ?? aiLabel("run_create_failed", "Falha ao criar execução."));
      } finally {
        aiSubmitting.value = false;
      }
    }
    const printEntity = computed(() => props.entity ?? {});
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Imagens oftálmicas",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        "top-actions": withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            if (aiEnabled.value) {
              _push2(`<div class="header-item d-none d-sm-flex me-2" data-v-45ceec4f${_scopeId}><button class="btn btn-liner-gradient d-inline-flex align-items-center gap-1" type="button"${ssrRenderAttr("title", aiLabel("assistance_button", "Assistente de IA"))} data-v-45ceec4f${_scopeId}><i class="ti ti-robot fs-16" data-v-45ceec4f${_scopeId}></i><span class="fw-medium" data-v-45ceec4f${_scopeId}>${ssrInterpolate(aiLabel("assistance_button", "Assistente de IA"))}</span><i class="ti ti-chevron-down fs-12 opacity-75" data-v-45ceec4f${_scopeId}></i></button></div>`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              aiEnabled.value ? (openBlock(), createBlock("div", {
                key: 0,
                class: "header-item d-none d-sm-flex me-2"
              }, [
                createVNode("button", {
                  class: "btn btn-liner-gradient d-inline-flex align-items-center gap-1",
                  type: "button",
                  title: aiLabel("assistance_button", "Assistente de IA"),
                  onClick: openAiModal
                }, [
                  createVNode("i", { class: "ti ti-robot fs-16" }),
                  createVNode("span", { class: "fw-medium" }, toDisplayString(aiLabel("assistance_button", "Assistente de IA")), 1),
                  createVNode("i", { class: "ti ti-chevron-down fs-12 opacity-75" })
                ], 8, ["title"])
              ])) : createCommentVNode("", true)
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b, _c, _d, _e, _f, _g, _h, _i;
          if (_push2) {
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: "Imagens oftálmicas",
              subtitle: `${totalExams.value} exames`
            }, null, _parent2, _scopeId));
            _push2(`<div class="card mb-3" data-v-45ceec4f${_scopeId}><div class="card-body py-2 px-3" data-v-45ceec4f${_scopeId}><div class="row g-2 align-items-center" data-v-45ceec4f${_scopeId}><div class="col-12 col-sm-4 col-md-3" data-v-45ceec4f${_scopeId}><div class="input-group input-group-sm" data-v-45ceec4f${_scopeId}><span class="input-group-text" data-v-45ceec4f${_scopeId}><i class="fa fa-search" data-v-45ceec4f${_scopeId}></i></span><input type="text" class="form-control" placeholder="Buscar paciente..."${ssrRenderAttr("value", search.value)} data-v-45ceec4f${_scopeId}>`);
            if (search.value) {
              _push2(`<button class="btn btn-outline-secondary" type="button" data-v-45ceec4f${_scopeId}><i class="fa fa-times" data-v-45ceec4f${_scopeId}></i></button>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div><div class="col-6 col-sm-3 col-md-2" data-v-45ceec4f${_scopeId}><select class="form-select form-select-sm"${ssrRenderAttr("value", period.value)} data-v-45ceec4f${_scopeId}><option value="hoje" data-v-45ceec4f${_scopeId}>Hoje</option><option value="7" data-v-45ceec4f${_scopeId}>Últimos 7 dias</option><option value="15" data-v-45ceec4f${_scopeId}>Últimos 15 dias</option><option value="30" data-v-45ceec4f${_scopeId}>Últimos 30 dias</option><option value="90" data-v-45ceec4f${_scopeId}>Últimos 90 dias</option></select></div><div class="col-6 col-sm-auto" data-v-45ceec4f${_scopeId}><button type="button" class="${ssrRenderClass([showFilters.value ? "btn-primary" : "btn-outline-secondary", "btn btn-sm"])}" data-v-45ceec4f${_scopeId}><i class="fa fa-filter me-1" data-v-45ceec4f${_scopeId}></i>Filtros `);
            if (!showFilters.value) {
              _push2(`<i class="fa fa-chevron-down ms-1" data-v-45ceec4f${_scopeId}></i>`);
            } else {
              _push2(`<i class="fa fa-chevron-up ms-1" data-v-45ceec4f${_scopeId}></i>`);
            }
            _push2(`</button></div><div class="col col-md d-flex justify-content-end" data-v-45ceec4f${_scopeId}><button type="button" class="btn btn-primary btn-sm" data-v-45ceec4f${_scopeId}><i class="fa fa-plus" data-v-45ceec4f${_scopeId}></i> Novo </button></div></div><div class="row g-2 mt-1 pt-2 border-top align-items-center" style="${ssrRenderStyle(showFilters.value ? null : { display: "none" })}" data-v-45ceec4f${_scopeId}><div class="col-auto" data-v-45ceec4f${_scopeId}><div class="d-flex align-items-center gap-2" data-v-45ceec4f${_scopeId}><span class="text-muted small fw-semibold" style="${ssrRenderStyle({ "white-space": "nowrap" })}" data-v-45ceec4f${_scopeId}>Olho</span><div class="btn-group btn-group-sm" role="group" data-v-45ceec4f${_scopeId}><input type="radio" class="btn-check" name="f-lat" id="f-lat-all" value=""${ssrIncludeBooleanAttr(ssrLooseEqual(laterality.value, "")) ? " checked" : ""} data-v-45ceec4f${_scopeId}><label class="btn btn-outline-secondary" for="f-lat-all" data-v-45ceec4f${_scopeId}>Todos</label><input type="radio" class="btn-check" name="f-lat" id="f-lat-od" value="od"${ssrIncludeBooleanAttr(ssrLooseEqual(laterality.value, "od")) ? " checked" : ""} data-v-45ceec4f${_scopeId}><label class="btn btn-outline-primary" for="f-lat-od" data-v-45ceec4f${_scopeId}>OD</label><input type="radio" class="btn-check" name="f-lat" id="f-lat-oe" value="oe"${ssrIncludeBooleanAttr(ssrLooseEqual(laterality.value, "oe")) ? " checked" : ""} data-v-45ceec4f${_scopeId}><label class="btn btn-outline-danger" for="f-lat-oe" data-v-45ceec4f${_scopeId}>OE</label><input type="radio" class="btn-check" name="f-lat" id="f-lat-ao" value="ao"${ssrIncludeBooleanAttr(ssrLooseEqual(laterality.value, "ao")) ? " checked" : ""} data-v-45ceec4f${_scopeId}><label class="btn btn-outline-dark" for="f-lat-ao" data-v-45ceec4f${_scopeId}>AO</label></div></div></div><div class="col-12 col-sm-6 col-md-3" data-v-45ceec4f${_scopeId}><select class="form-select form-select-sm" data-v-45ceec4f${_scopeId}><option value="" data-v-45ceec4f${ssrIncludeBooleanAttr(Array.isArray(examTypeId.value) ? ssrLooseContain(examTypeId.value, "") : ssrLooseEqual(examTypeId.value, "")) ? " selected" : ""}${_scopeId}>Todos os exames</option><!--[-->`);
            ssrRenderList(availableExamTypes.value, (t) => {
              _push2(`<option${ssrRenderAttr("value", t.id)} data-v-45ceec4f${ssrIncludeBooleanAttr(Array.isArray(examTypeId.value) ? ssrLooseContain(examTypeId.value, t.id) : ssrLooseEqual(examTypeId.value, t.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(t.name)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-12 col-sm-6 col-md-2" data-v-45ceec4f${_scopeId}><select class="form-select form-select-sm" data-v-45ceec4f${_scopeId}><option value="" data-v-45ceec4f${ssrIncludeBooleanAttr(Array.isArray(examStatus.value) ? ssrLooseContain(examStatus.value, "") : ssrLooseEqual(examStatus.value, "")) ? " selected" : ""}${_scopeId}>Todos status</option><option value="solicitado" data-v-45ceec4f${ssrIncludeBooleanAttr(Array.isArray(examStatus.value) ? ssrLooseContain(examStatus.value, "solicitado") : ssrLooseEqual(examStatus.value, "solicitado")) ? " selected" : ""}${_scopeId}>Solicitado</option><option value="realizado" data-v-45ceec4f${ssrIncludeBooleanAttr(Array.isArray(examStatus.value) ? ssrLooseContain(examStatus.value, "realizado") : ssrLooseEqual(examStatus.value, "realizado")) ? " selected" : ""}${_scopeId}>Realizado</option><option value="laudado" data-v-45ceec4f${ssrIncludeBooleanAttr(Array.isArray(examStatus.value) ? ssrLooseContain(examStatus.value, "laudado") : ssrLooseEqual(examStatus.value, "laudado")) ? " selected" : ""}${_scopeId}>Laudado</option><option value="cancelado" data-v-45ceec4f${ssrIncludeBooleanAttr(Array.isArray(examStatus.value) ? ssrLooseContain(examStatus.value, "cancelado") : ssrLooseEqual(examStatus.value, "cancelado")) ? " selected" : ""}${_scopeId}>Cancelado</option></select></div><div class="col-12 col-sm-6 col-md-3" data-v-45ceec4f${_scopeId}><select class="form-select form-select-sm"${ssrRenderAttr("value", doctorId.value)} data-v-45ceec4f${_scopeId}><option value="" data-v-45ceec4f${_scopeId}>Todos médicos</option><!--[-->`);
            ssrRenderList(__props.doctors, (d) => {
              _push2(`<option${ssrRenderAttr("value", d.id)} data-v-45ceec4f${_scopeId}>${ssrInterpolate(d.name)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-auto" data-v-45ceec4f${_scopeId}><button type="button" class="btn btn-sm btn-outline-secondary" data-v-45ceec4f${_scopeId}><i class="fa fa-times me-1" data-v-45ceec4f${_scopeId}></i> Limpar </button></div></div></div></div><div class="row" data-v-45ceec4f${_scopeId}><div class="col-xs-12 col-sm-3 col-md-3 col-lg-3" data-v-45ceec4f${_scopeId}><div class="card panel-info" data-v-45ceec4f${_scopeId}><div class="card-body p-2" data-v-45ceec4f${_scopeId}><h6 class="font-bold text-uppercase px-1 mb-1 mt-3" data-v-45ceec4f${_scopeId}>Pacientes</h6><hr class="mt-0 mb-2" data-v-45ceec4f${_scopeId}>`);
            if (loading.value) {
              _push2(`<div class="text-center py-3" data-v-45ceec4f${_scopeId}><div class="spinner-border spinner-border-sm text-info" role="status" data-v-45ceec4f${_scopeId}></div></div>`);
            } else {
              _push2(`<div style="${ssrRenderStyle({ "max-height": "520px", "overflow-y": "auto", "overflow-x": "hidden" })}" data-v-45ceec4f${_scopeId}>`);
              if (filteredPatients.value.length === 0) {
                _push2(`<p class="text-muted text-center small py-3 mb-0" data-v-45ceec4f${_scopeId}> Nenhum paciente encontrado. </p>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<!--[-->`);
              ssrRenderList(filteredPatients.value, (patient) => {
                var _a2, _b2, _c2, _d2;
                _push2(`<div class="${ssrRenderClass([{ "patient-item-active": ((_a2 = selectedPatient.value) == null ? void 0 : _a2.id) === patient.id }, "d-flex align-items-center gap-2 px-1 py-1 rounded mb-1 patient-item"])}" data-v-45ceec4f${_scopeId}><div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 text-white fw-bold" style="${ssrRenderStyle({ background: avatarColor(((_b2 = patient.person) == null ? void 0 : _b2.full_name) ?? patient.full_name), width: "30px", height: "30px", fontSize: ".62rem" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(initials(((_c2 = patient.person) == null ? void 0 : _c2.full_name) ?? patient.full_name))}</div><div class="flex-grow-1 min-w-0" data-v-45ceec4f${_scopeId}><div class="text-truncate fw-semibold" style="${ssrRenderStyle({ "font-size": ".75rem", "line-height": "1.2" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(((_d2 = patient.person) == null ? void 0 : _d2.full_name) ?? patient.full_name ?? "—")}</div><div class="text-muted" style="${ssrRenderStyle({ "font-size": ".65rem", "line-height": "1.2" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(patient.code)}</div></div><span class="badge bg-primary rounded-pill flex-shrink-0" style="${ssrRenderStyle({ "font-size": ".6rem" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(patient.exams.length)}</span></div>`);
              });
              _push2(`<!--]--></div>`);
            }
            if (!loading.value) {
              _push2(`<div class="text-muted px-1 mt-1" style="${ssrRenderStyle({ "font-size": ".65rem" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(filteredPatients.value.length)} paciente(s) </div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div></div><div class="col-xs-12 col-sm-9 col-md-9 col-lg-9" data-v-45ceec4f${_scopeId}><div class="card" data-v-45ceec4f${_scopeId}><h5 class="card-header d-flex align-items-center gap-2" data-v-45ceec4f${_scopeId}>`);
            if (!selectedPatient.value) {
              _push2(`<span data-v-45ceec4f${_scopeId}>Selecione um paciente</span>`);
            } else {
              _push2(`<span class="d-flex align-items-center gap-2 w-100" data-v-45ceec4f${_scopeId}><button type="button" class="btn btn-outline-secondary btn-sm" data-v-45ceec4f${_scopeId}><i class="fa fa-arrow-left" data-v-45ceec4f${_scopeId}></i></button><span data-v-45ceec4f${_scopeId}><span data-v-45ceec4f${_scopeId}>${ssrInterpolate(((_a = selectedPatient.value.person) == null ? void 0 : _a.full_name) ?? selectedPatient.value.full_name)}</span><small class="text-muted fw-normal ms-2" style="${ssrRenderStyle({ "font-size": ".72rem" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(selectedPatient.value.code)}</small></span><div class="flex-grow-1" data-v-45ceec4f${_scopeId}></div><a${ssrRenderAttr("href", `/panel/patients/${selectedPatient.value.id}/medicalrecords`)} target="_blank" class="btn btn-outline-primary btn-sm" style="${ssrRenderStyle({ "font-size": ".72rem" })}" data-v-45ceec4f${_scopeId}> Prontuário <i class="fa fa-external-link ms-1" data-v-45ceec4f${_scopeId}></i></a></span>`);
            }
            _push2(`</h5>`);
            if (selectedPatient.value) {
              _push2(`<div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom bg-body-secondary" data-v-45ceec4f${_scopeId}><button type="button" class="btn btn-sm btn-outline-primary"${ssrIncludeBooleanAttr(selectedExamIds.value.length === 0) ? " disabled" : ""} data-v-45ceec4f${_scopeId}><i class="fa fa-images me-1" data-v-45ceec4f${_scopeId}></i>Visualizar selecionados `);
              if (selectedExamIds.value.length > 0) {
                _push2(`<span class="badge bg-primary ms-1" data-v-45ceec4f${_scopeId}>${ssrInterpolate(selectedExamIds.value.length)}</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</button><button type="button" class="btn btn-sm btn-outline-secondary" data-v-45ceec4f${_scopeId}><i class="fa fa-th me-1" data-v-45ceec4f${_scopeId}></i>Visualizar todos </button><div class="vr opacity-25" data-v-45ceec4f${_scopeId}></div><button type="button" class="btn btn-sm btn-outline-dark" data-v-45ceec4f${_scopeId}><i class="fa fa-print me-1" data-v-45ceec4f${_scopeId}></i>Imprimir </button><div class="flex-grow-1" data-v-45ceec4f${_scopeId}></div>`);
              if (selectedExamIds.value.length > 0) {
                _push2(`<span class="text-muted" style="${ssrRenderStyle({ "font-size": ".7rem" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(selectedExamIds.value.length)} selecionado(s) </span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="card-body" data-v-45ceec4f${_scopeId}>`);
            if (!selectedPatient.value) {
              _push2(`<div class="text-center py-5 text-muted" data-v-45ceec4f${_scopeId}><i class="ti ti-eye" style="${ssrRenderStyle({ "font-size": "3rem", "opacity": ".3" })}" data-v-45ceec4f${_scopeId}></i><p class="mt-3 mb-0" data-v-45ceec4f${_scopeId}>Selecione um paciente para ver os exames.</p></div>`);
            } else {
              _push2(`<div class="row g-0" style="${ssrRenderStyle({ "min-height": "480px" })}" data-v-45ceec4f${_scopeId}><div class="col-12 border-end pe-0" data-v-45ceec4f${_scopeId}>`);
              if (urlsLoading.value) {
                _push2(`<div class="text-center py-3" data-v-45ceec4f${_scopeId}><div class="spinner-border spinner-border-sm text-secondary" role="status" data-v-45ceec4f${_scopeId}></div><p class="text-muted small mt-1 mb-0" data-v-45ceec4f${_scopeId}>Carregando imagens...</p></div>`);
              } else if (filteredExams.value.length === 0) {
                _push2(`<p class="text-muted text-center small py-4" data-v-45ceec4f${_scopeId}> Nenhum exame encontrado. </p>`);
              } else {
                _push2(`<div style="${ssrRenderStyle({ "max-height": "620px", "overflow-y": "auto", "overflow-x": "hidden" })}" data-v-45ceec4f${_scopeId}><!--[-->`);
                ssrRenderList(groupedExams.value, (group) => {
                  var _a2;
                  _push2(`<div class="mb-1" data-v-45ceec4f${_scopeId}><div class="px-2 py-1 d-flex align-items-center gap-1 flex-wrap bg-body-tertiary text-body border-bottom fw-semibold" style="${ssrRenderStyle({ "font-size": ".7rem", "row-gap": "3px" })}" data-v-45ceec4f${_scopeId}><span data-v-45ceec4f${_scopeId}>${ssrInterpolate(formatDateFull(group.date))}</span>`);
                  if (group.equipment) {
                    _push2(`<span class="d-flex align-items-center gap-1" data-v-45ceec4f${_scopeId}><span class="opacity-50" data-v-45ceec4f${_scopeId}>:</span><span data-v-45ceec4f${_scopeId}>${ssrInterpolate(group.equipment.name)}</span></span>`);
                  } else {
                    _push2(`<!---->`);
                  }
                  _push2(`<div class="flex-grow-1" data-v-45ceec4f${_scopeId}></div><div class="btn-group btn-group-sm" role="group" data-v-45ceec4f${_scopeId}><button type="button" class="${ssrRenderClass([groupLatActive(group, "od") ? "btn-primary" : "btn-outline-primary", "btn py-0 px-2"])}" style="${ssrRenderStyle({ "font-size": ".6rem" })}" data-v-45ceec4f${_scopeId}>OD</button><button type="button" class="${ssrRenderClass([groupLatActive(group, "oe") ? "btn-danger" : "btn-outline-danger", "btn py-0 px-2"])}" style="${ssrRenderStyle({ "font-size": ".6rem" })}" data-v-45ceec4f${_scopeId}>OE</button><button type="button" class="${ssrRenderClass([groupLatActive(group, "ao") ? "btn-secondary" : "btn-outline-secondary", "btn py-0 px-2"])}" style="${ssrRenderStyle({ "font-size": ".6rem" })}" data-v-45ceec4f${_scopeId}>AO</button><button type="button" class="${ssrRenderClass([groupLatActive(group, "all") ? "btn-secondary" : "btn-outline-secondary", "btn py-0 px-2"])}" style="${ssrRenderStyle({ "font-size": ".6rem" })}" data-v-45ceec4f${_scopeId}>Todos</button></div><div class="vr opacity-25 mx-1" data-v-45ceec4f${_scopeId}></div><button type="button" class="btn btn-sm py-0 px-2 btn-outline-secondary" style="${ssrRenderStyle({ "font-size": ".6rem" })}" title="Upload de imagem" data-v-45ceec4f${_scopeId}><i class="fa fa-upload me-1" data-v-45ceec4f${_scopeId}></i>Upload </button><button type="button" class="btn btn-sm py-0 px-2 btn-outline-secondary" style="${ssrRenderStyle({ "font-size": ".6rem" })}" title="Download das imagens" data-v-45ceec4f${_scopeId}><i class="fa fa-download me-1" data-v-45ceec4f${_scopeId}></i>Download </button></div><div class="px-2 py-1 bg-body-secondary text-body-secondary border-bottom" style="${ssrRenderStyle({ "font-size": ".68rem" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(((_a2 = group.examType) == null ? void 0 : _a2.name) || "Exame")}</div><div class="d-flex flex-wrap gap-2 p-2 bg-dark" data-v-45ceec4f${_scopeId}><!--[-->`);
                  ssrRenderList(group.exams, (exam) => {
                    var _a3;
                    _push2(`<div class="position-relative" style="${ssrRenderStyle({ "cursor": "pointer", "flex-shrink": "0" })}" data-v-45ceec4f${_scopeId}><span class="${ssrRenderClass([{
                      "bg-primary": exam.laterality === 1,
                      "bg-danger": exam.laterality === 2,
                      "bg-secondary": exam.laterality !== 1 && exam.laterality !== 2
                    }, "position-absolute top-0 end-0 rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"])}" style="${ssrRenderStyle({ "width": "22px", "height": "22px", "font-size": ".55rem", "z-index": "1", "margin": "3px" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(latLabel(exam.laterality))}</span><span class="position-absolute bottom-0 start-0 d-flex align-items-center justify-content-center" style="${ssrRenderStyle({ "z-index": "2", "margin": "3px" })}" data-v-45ceec4f${_scopeId}><span class="${ssrRenderClass([isSelected(exam.id) ? "bg-primary" : "bg-dark border border-secondary", "rounded d-flex align-items-center justify-content-center"])}" style="${ssrRenderStyle({ "width": "16px", "height": "16px" })}" data-v-45ceec4f${_scopeId}><i class="fa fa-check text-white" style="${ssrRenderStyle([
                      { "font-size": ".5rem" },
                      isSelected(exam.id) ? null : { display: "none" }
                    ])}" data-v-45ceec4f${_scopeId}></i></span></span>`);
                    if (examUrls.value[exam.id] && !brokenUrls.value[exam.id]) {
                      _push2(`<img${ssrRenderAttr("src", examUrls.value[exam.id])}${ssrRenderAttr("alt", (_a3 = exam.exam_type) == null ? void 0 : _a3.name)} width="100" height="76" style="${ssrRenderStyle(`object-fit:cover;display:block;border-radius:4px;outline:${isSelected(exam.id) ? "2px solid #6ea8fe" : "2px solid transparent"};transition:outline .1s;`)}" data-v-45ceec4f${_scopeId}>`);
                    } else {
                      _push2(`<div class="d-flex align-items-center justify-content-center rounded" style="${ssrRenderStyle(`width:100px;height:76px;background:#3a3c42;border-radius:4px;outline:${isSelected(exam.id) ? "2px solid #6ea8fe" : "2px solid transparent"};transition:outline .1s;`)}" data-v-45ceec4f${_scopeId}><i class="ti ti-photo-off" style="${ssrRenderStyle({ "font-size": "1.4rem", "color": "#555" })}" data-v-45ceec4f${_scopeId}></i></div>`);
                    }
                    _push2(`</div>`);
                  });
                  _push2(`<!--]--></div></div>`);
                });
                _push2(`<!--]--></div>`);
              }
              _push2(`</div></div>`);
            }
            _push2(`</div></div></div></div>`);
            ssrRenderTeleport(_push2, (_push3) => {
              _push3(`<div style="${ssrRenderStyle([
                { "position": "fixed", "inset": "0", "z-index": "9998", "background": "#0a0a0a", "display": "flex", "flex-direction": "column", "overflow": "hidden" },
                showViewerModal.value ? null : { display: "none" }
              ])}" data-v-45ceec4f${_scopeId}><div class="d-flex align-items-center gap-1 px-3 py-2 flex-shrink-0 flex-wrap" style="${ssrRenderStyle({ "background": "#111", "border-bottom": "1px solid #222", "row-gap": "4px" })}" data-v-45ceec4f${_scopeId}><div class="btn-group btn-group-sm" role="group" data-v-45ceec4f${_scopeId}><!--[-->`);
              ssrRenderList([1, 2, 3, 4], (n) => {
                _push3(`<button type="button" class="${ssrRenderClass([viewerPanelCount.value === n ? "btn-primary" : "btn-outline-secondary", "btn fw-semibold"])}" style="${ssrRenderStyle({ "min-width": "26px", "font-size": ".72rem" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(n)}</button>`);
              });
              _push3(`<!--]--></div><div class="vr opacity-25 mx-1" data-v-45ceec4f${_scopeId}></div><button type="button" class="${ssrRenderClass([viewerAllMode.value ? "btn-info text-dark" : "btn-outline-secondary", "btn btn-sm fw-semibold"])}" style="${ssrRenderStyle({ "font-size": ".72rem" })}" data-v-45ceec4f${_scopeId}>All</button><div class="vr opacity-25 mx-1" data-v-45ceec4f${_scopeId}></div><button type="button" class="${ssrRenderClass([viewerLensActive.value ? "btn-warning text-dark" : "btn-outline-secondary", "btn btn-sm fw-semibold"])}" style="${ssrRenderStyle({ "font-size": ".72rem" })}" data-v-45ceec4f${_scopeId}><i class="fa fa-search-plus" data-v-45ceec4f${_scopeId}></i> Lens </button>`);
              if (viewerLensActive.value) {
                _push3(`<div class="d-flex align-items-center gap-1" data-v-45ceec4f${_scopeId}><button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" data-v-45ceec4f${_scopeId}><i class="fa fa-minus" style="${ssrRenderStyle({ "font-size": ".65rem" })}" data-v-45ceec4f${_scopeId}></i></button><span style="${ssrRenderStyle({ "color": "#fff", "font-size": ".78rem", "font-weight": "600", "min-width": "48px", "text-align": "center", "display": "inline-block" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(viewerZoom.value.toFixed(1))}x </span><button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" data-v-45ceec4f${_scopeId}><i class="fa fa-plus" style="${ssrRenderStyle({ "font-size": ".65rem" })}" data-v-45ceec4f${_scopeId}></i></button></div>`);
              } else {
                _push3(`<!---->`);
              }
              _push3(`<div class="vr opacity-25 mx-1" data-v-45ceec4f${_scopeId}></div><button type="button" class="${ssrRenderClass([viewerFitMode.value ? "btn-light text-dark" : "btn-outline-secondary", "btn btn-sm fw-semibold"])}" style="${ssrRenderStyle({ "font-size": ".72rem" })}" title="Ajustar à área de visualização" data-v-45ceec4f${_scopeId}><i class="fa fa-compress-arrows-alt me-1" data-v-45ceec4f${_scopeId}></i>Fit </button><button type="button" class="${ssrRenderClass([viewerSplitMode.value ? "btn-info text-dark" : "btn-outline-secondary", "btn btn-sm fw-semibold"])}" style="${ssrRenderStyle({ "font-size": ".72rem" })}" data-v-45ceec4f${_scopeId}>OD|OE</button><button type="button" class="${ssrRenderClass([viewerLaserMode.value ? "btn-success" : "btn-outline-secondary", "btn btn-sm fw-semibold"])}" style="${ssrRenderStyle({ "font-size": ".72rem" })}" title="Inverter imagem verticalmente" data-v-45ceec4f${_scopeId}><i class="fa fa-undo me-1" data-v-45ceec4f${_scopeId}></i>Laser </button><div class="vr opacity-25 mx-1" data-v-45ceec4f${_scopeId}></div><div class="flex-grow-1" data-v-45ceec4f${_scopeId}></div><button type="button" class="btn btn-sm btn-outline-danger" data-v-45ceec4f${_scopeId}><i class="fa fa-times" data-v-45ceec4f${_scopeId}></i></button></div><div class="flex-grow-1" style="${ssrRenderStyle([
                viewerPanelGridStyle.value,
                !viewerAllMode.value ? null : { display: "none" }
              ])}" data-v-45ceec4f${_scopeId}><!--[-->`);
              ssrRenderList(viewerPanelCount.value, (pi) => {
                var _a2, _b2;
                _push3(`<div class="position-relative d-flex flex-column" style="${ssrRenderStyle(`background:#111;border-radius:3px;min-height:0;cursor:pointer;overflow:hidden;outline:${viewerActivePanel.value === pi - 1 ? "2px solid #0d6efd" : "1px solid #2a2a2a"};`)}" data-v-45ceec4f${_scopeId}><div class="${ssrRenderClass([viewerFitMode.value ? "align-items-start justify-content-center" : "align-items-center justify-content-center", "flex-grow-1 position-relative d-flex"])}" style="${ssrRenderStyle((viewerFitMode.value ? "height:84vh;min-height:0;overflow-y:auto;overflow-x:hidden;" : "min-height:0;overflow:hidden;") + (viewerLensActive.value && viewerPanelUrls.value[pi - 1] && !viewerPanelBroken.value[pi - 1] ? "cursor:none;" : ""))}" data-v-45ceec4f${_scopeId}><div class="text-center text-white position-absolute" style="${ssrRenderStyle([
                  { "z-index": "5" },
                  viewerPanelLoading.value[pi - 1] ? null : { display: "none" }
                ])}" data-v-45ceec4f${_scopeId}><div class="spinner-border spinner-border-sm text-light" role="status" data-v-45ceec4f${_scopeId}></div></div><div class="text-center text-muted" style="${ssrRenderStyle(!viewerPanelExams.value[pi - 1] && !viewerPanelLoading.value[pi - 1] ? null : { display: "none" })}" data-v-45ceec4f${_scopeId}><i class="ti ti-photo" style="${ssrRenderStyle({ "font-size": "2.5rem", "opacity": ".12" })}" data-v-45ceec4f${_scopeId}></i><p class="mt-1 mb-0" style="${ssrRenderStyle({ "font-size": ".65rem", "opacity": ".35" })}" data-v-45ceec4f${_scopeId}>Painel ${ssrInterpolate(pi)}</p></div><div class="text-center text-muted" style="${ssrRenderStyle(viewerPanelExams.value[pi - 1] && !viewerPanelUrls.value[pi - 1] && !viewerPanelLoading.value[pi - 1] && !viewerPanelBroken.value[pi - 1] ? null : { display: "none" })}" data-v-45ceec4f${_scopeId}><i class="ti ti-photo-off" style="${ssrRenderStyle({ "font-size": "2rem", "opacity": ".3" })}" data-v-45ceec4f${_scopeId}></i><p class="mt-1 mb-0" style="${ssrRenderStyle({ "font-size": ".65rem" })}" data-v-45ceec4f${_scopeId}>Sem imagem</p></div><div class="text-center text-muted" style="${ssrRenderStyle(viewerPanelBroken.value[pi - 1] ? null : { display: "none" })}" data-v-45ceec4f${_scopeId}><i class="ti ti-photo-off" style="${ssrRenderStyle({ "font-size": "2rem", "opacity": ".3" })}" data-v-45ceec4f${_scopeId}></i><p class="mt-1 mb-0" style="${ssrRenderStyle({ "font-size": ".65rem" })}" data-v-45ceec4f${_scopeId}>Arquivo não encontrado</p></div><img${ssrRenderAttr("src", viewerPanelUrls.value[pi - 1] ?? "")} alt="Exame" style="${ssrRenderStyle([
                  (viewerFitMode.value ? "width:100%;height:auto;max-width:100%;max-height:none;flex-shrink:0;" : "width:100%;height:84vh;object-fit:contain;") + "display:block;user-select:none;" + (viewerPanelFlipped.value[pi - 1] ? "transform:scaleY(-1);" : ""),
                  viewerPanelUrls.value[pi - 1] && !viewerPanelLoading.value[pi - 1] && !viewerPanelBroken.value[pi - 1] ? null : { display: "none" }
                ])}" data-v-45ceec4f${_scopeId}></div><div class="d-flex align-items-center gap-1 px-2 flex-shrink-0" style="${ssrRenderStyle({ "background": "#0d0d0d", "font-size": ".6rem", "min-height": "22px", "border-top": "1px solid #1a1a1a" })}" data-v-45ceec4f${_scopeId}>`);
                if (viewerPanelExams.value[pi - 1]) {
                  _push3(`<span class="d-flex align-items-center gap-1 overflow-hidden w-100" data-v-45ceec4f${_scopeId}><span class="${ssrRenderClass([{
                    "bg-primary": viewerPanelExams.value[pi - 1].laterality === 1,
                    "bg-danger": viewerPanelExams.value[pi - 1].laterality === 2,
                    "bg-secondary": viewerPanelExams.value[pi - 1].laterality !== 1 && viewerPanelExams.value[pi - 1].laterality !== 2
                  }, "badge flex-shrink-0"])}" style="${ssrRenderStyle({ "font-size": ".5rem" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(latLabel(viewerPanelExams.value[pi - 1].laterality))}</span><span class="text-secondary text-truncate" data-v-45ceec4f${_scopeId}>${ssrInterpolate(((_a2 = viewerPanelExams.value[pi - 1].exam_type) == null ? void 0 : _a2.name) ?? "—")}</span><span class="text-secondary opacity-50 flex-shrink-0 ms-auto" data-v-45ceec4f${_scopeId}>${ssrInterpolate(formatDateFull((_b2 = viewerPanelExams.value[pi - 1].created_at) == null ? void 0 : _b2.substring(0, 10)))}</span></span>`);
                } else {
                  _push3(`<span class="text-secondary" style="${ssrRenderStyle({ "opacity": ".3" })}" data-v-45ceec4f${_scopeId}>Painel ${ssrInterpolate(pi)}</span>`);
                }
                _push3(`</div><div class="flex-shrink-0 d-flex align-items-center gap-1 overflow-x-auto overflow-y-hidden py-1 px-1" style="${ssrRenderStyle(`background:#0d0d0d;height:80px;border-top:1px solid ${viewerActivePanel.value === pi - 1 ? "#0d6efd" : "#222"};`)}" data-v-45ceec4f${_scopeId}><!--[-->`);
                ssrRenderList(panelStripExams(pi - 1), (exam) => {
                  var _a3;
                  _push3(`<div style="${ssrRenderStyle({ "flex-shrink": "0", "cursor": "pointer" })}" data-v-45ceec4f${_scopeId}><div class="position-relative rounded overflow-hidden" style="${ssrRenderStyle(`width:64px;height:64px;outline:${((_a3 = viewerPanelExams.value[pi - 1]) == null ? void 0 : _a3.id) === exam.id ? "2px solid #0d6efd" : "1px solid #2a2a2a"};`)}" data-v-45ceec4f${_scopeId}><span class="${ssrRenderClass([{
                    "bg-primary": exam.laterality === 1,
                    "bg-danger": exam.laterality === 2,
                    "bg-secondary": exam.laterality !== 1 && exam.laterality !== 2
                  }, "position-absolute top-0 end-0 rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"])}" style="${ssrRenderStyle({ "width": "14px", "height": "14px", "font-size": ".4rem", "z-index": "1", "margin": "2px" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(latLabel(exam.laterality))}</span>`);
                  if (examUrls.value[exam.id] && !brokenUrls.value[exam.id]) {
                    _push3(`<img${ssrRenderAttr("src", examUrls.value[exam.id])} style="${ssrRenderStyle({ "width": "64px", "height": "64px", "object-fit": "cover", "display": "block" })}" data-v-45ceec4f${_scopeId}>`);
                  } else {
                    _push3(`<div class="w-100 h-100 d-flex align-items-center justify-content-center" style="${ssrRenderStyle({ "background": "#1a1a1a" })}" data-v-45ceec4f${_scopeId}><i class="ti ti-photo-off" style="${ssrRenderStyle({ "color": "#444", "font-size": ".9rem" })}" data-v-45ceec4f${_scopeId}></i></div>`);
                  }
                  _push3(`</div></div>`);
                });
                _push3(`<!--]--></div></div>`);
              });
              _push3(`<!--]--></div><div id="viewImages" class="ei-scroll" style="${ssrRenderStyle([
                { "position": "absolute", "top": "50px", "left": "0", "right": "0", "bottom": "0", "overflow-y": "auto", "overflow-x": "hidden", "background": "#0a0a0a", "padding": "4px" },
                viewerAllMode.value ? null : { display: "none" }
              ])}" data-v-45ceec4f${_scopeId}><div style="${ssrRenderStyle(`display:grid;grid-template-columns:repeat(${viewerSplitMode.value ? 2 : viewerPanelCount.value},1fr);gap:4px;`)}" data-v-45ceec4f${_scopeId}><!--[-->`);
              ssrRenderList(allGridExams(), (exam) => {
                var _a2;
                _push3(`<div class="position-relative" style="${ssrRenderStyle({ "background": "#111", "border-radius": "3px", "overflow": "hidden", "display": "flex", "flex-direction": "column" })}" data-v-45ceec4f${_scopeId}><div class="d-flex align-items-center gap-2 px-2 py-1" style="${ssrRenderStyle({ "background": "#0d0d0d", "border-bottom": "1px solid #1a1a1a", "font-size": ".65rem" })}" data-v-45ceec4f${_scopeId}><span class="${ssrRenderClass([{
                  "bg-primary": exam.laterality === 1,
                  "bg-danger": exam.laterality === 2,
                  "bg-secondary": exam.laterality !== 1 && exam.laterality !== 2
                }, "badge flex-shrink-0"])}" style="${ssrRenderStyle({ "font-size": ".5rem" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(latLabel(exam.laterality))}</span><span class="text-secondary text-truncate" data-v-45ceec4f${_scopeId}>${ssrInterpolate(((_a2 = exam.exam_type) == null ? void 0 : _a2.name) ?? "—")}</span><span class="text-secondary opacity-50 flex-shrink-0 ms-auto" data-v-45ceec4f${_scopeId}>${ssrInterpolate(formatDateTime(exam.created_at))}</span></div><div class="position-relative" style="${ssrRenderStyle(viewerLensActive.value && examUrls.value[exam.id] && !brokenUrls.value[exam.id] ? "cursor:none;" : "")}" data-v-45ceec4f${_scopeId}>`);
                if (examUrls.value[exam.id] && !brokenUrls.value[exam.id]) {
                  _push3(`<img${ssrRenderAttr("src", examUrls.value[exam.id])} style="${ssrRenderStyle("width:100%;height:auto;display:block;user-select:none;" + (viewerLaserMode.value ? "transform:scaleY(-1);" : ""))}" data-v-45ceec4f${_scopeId}>`);
                } else {
                  _push3(`<div class="d-flex align-items-center justify-content-center" style="${ssrRenderStyle({ "width": "100%", "aspect-ratio": "4/3", "background": "#1a1a1a" })}" data-v-45ceec4f${_scopeId}><i class="ti ti-photo-off" style="${ssrRenderStyle({ "color": "#444", "font-size": "2rem" })}" data-v-45ceec4f${_scopeId}></i></div>`);
                }
                _push3(`</div></div>`);
              });
              _push3(`<!--]--></div></div><div style="${ssrRenderStyle([
                viewerLensStyle.value,
                viewerLensActive.value && viewerLensVisible.value ? null : { display: "none" }
              ])}" data-v-45ceec4f${_scopeId}></div></div>`);
            }, "body", false, _parent2);
            ssrRenderTeleport(_push2, (_push3) => {
              var _a2;
              _push3(`<div style="${ssrRenderStyle([
                { "position": "fixed", "inset": "0", "z-index": "9999", "display": "flex", "flex-direction": "column" },
                showPrintModal.value ? null : { display: "none" }
              ])}" data-v-45ceec4f${_scopeId}><div class="d-flex align-items-center gap-1 px-3 py-2 flex-shrink-0" style="${ssrRenderStyle({ "background": "#2c2c2c", "color": "#fff" })}" data-v-45ceec4f${_scopeId}><div class="btn-group btn-group-sm me-2" role="group" data-v-45ceec4f${_scopeId}><!--[-->`);
              ssrRenderList([1, 2, 4, 6, 9, 12, 16], (n) => {
                _push3(`<button type="button" class="${ssrRenderClass([printCols.value === n ? "btn-light" : "btn-outline-secondary", "btn btn-sm"])}" style="${ssrRenderStyle({ "font-size": ".72rem", "min-width": "28px" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(n)}</button>`);
              });
              _push3(`<!--]--></div><div class="vr opacity-25 mx-1" data-v-45ceec4f${_scopeId}></div><button type="button" class="${ssrRenderClass([printOrientation.value === "portrait" ? "btn-light" : "btn-outline-secondary", "btn btn-sm"])}" style="${ssrRenderStyle({ "font-size": ".72rem" })}" data-v-45ceec4f${_scopeId}><i class="fa fa-file me-1" data-v-45ceec4f${_scopeId}></i>Retrato </button><button type="button" class="${ssrRenderClass([printOrientation.value === "landscape" ? "btn-light" : "btn-outline-secondary", "btn btn-sm"])}" style="${ssrRenderStyle({ "font-size": ".72rem" })}" data-v-45ceec4f${_scopeId}><i class="fa fa-file me-1" style="${ssrRenderStyle({ "transform": "rotate(90deg)", "display": "inline-block" })}" data-v-45ceec4f${_scopeId}></i>Paisagem </button><div class="vr opacity-25 mx-1" data-v-45ceec4f${_scopeId}></div><button type="button" class="btn btn-sm btn-warning text-dark fw-semibold" style="${ssrRenderStyle({ "font-size": ".72rem" })}" data-v-45ceec4f${_scopeId}><i class="fa fa-print me-1" data-v-45ceec4f${_scopeId}></i>Imprimir </button><button type="button" class="btn btn-sm btn-outline-secondary ms-auto" style="${ssrRenderStyle({ "font-size": ".72rem" })}" data-v-45ceec4f${_scopeId}><i class="fa fa-times me-1" data-v-45ceec4f${_scopeId}></i>Fechar </button></div><div class="flex-grow-1 overflow-auto" style="${ssrRenderStyle({ "background": "#888" })}" data-v-45ceec4f${_scopeId}><div id="ei-print-content" class="${ssrRenderClass([printOrientation.value === "landscape" ? "ei-landscape" : "ei-portrait", "mx-auto my-3 bg-white shadow"])}" style="${ssrRenderStyle({ "width": "210mm", "min-height": "297mm", "padding": "12mm", "box-sizing": "border-box" })}" data-v-45ceec4f${_scopeId}><div class="d-flex justify-content-between align-items-start mb-3 pb-2" style="${ssrRenderStyle({ "border-bottom": "2px solid #1a6fc4" })}" data-v-45ceec4f${_scopeId}><div data-v-45ceec4f${_scopeId}><div style="${ssrRenderStyle({ "font-size": "1.1rem", "font-weight": "700", "color": "#1a6fc4" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(printEntity.value.name)}</div>`);
              if (printEntity.value.address) {
                _push3(`<div style="${ssrRenderStyle({ "font-size": ".72rem", "color": "#555" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(printEntity.value.address)}</div>`);
              } else {
                _push3(`<!---->`);
              }
              if (printEntity.value.email) {
                _push3(`<div style="${ssrRenderStyle({ "font-size": ".72rem", "color": "#555" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(printEntity.value.email)}</div>`);
              } else {
                _push3(`<!---->`);
              }
              if (printEntity.value.telephone || printEntity.value.cellphone) {
                _push3(`<div style="${ssrRenderStyle({ "font-size": ".72rem", "color": "#555" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate([printEntity.value.telephone, printEntity.value.cellphone].filter(Boolean).join(" | "))}</div>`);
              } else {
                _push3(`<!---->`);
              }
              _push3(`</div><div class="text-end" data-v-45ceec4f${_scopeId}><div style="${ssrRenderStyle({ "font-size": ".72rem", "color": "#555" })}" data-v-45ceec4f${_scopeId}>Data do relatório</div><div style="${ssrRenderStyle({ "font-size": ".85rem", "font-weight": "600" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate((/* @__PURE__ */ new Date()).toLocaleDateString("pt-BR"))}</div></div></div>`);
              if (selectedPatient.value) {
                _push3(`<div class="mb-3 p-2 rounded" style="${ssrRenderStyle({ "background": "#f0f4ff", "font-size": ".78rem" })}" data-v-45ceec4f${_scopeId}><strong data-v-45ceec4f${_scopeId}>${ssrInterpolate(((_a2 = selectedPatient.value.person) == null ? void 0 : _a2.full_name) ?? selectedPatient.value.full_name)}</strong><span class="ms-2 text-muted" data-v-45ceec4f${_scopeId}>${ssrInterpolate(selectedPatient.value.code)}</span></div>`);
              } else {
                _push3(`<!---->`);
              }
              _push3(`<div style="${ssrRenderStyle(`display:grid;grid-template-columns:repeat(${printCols.value},1fr);gap:8px;`)}" data-v-45ceec4f${_scopeId}><!--[-->`);
              ssrRenderList(printExams.value, (exam) => {
                var _a3;
                _push3(`<div style="${ssrRenderStyle({ "break-inside": "avoid" })}" data-v-45ceec4f${_scopeId}><div class="text-center mb-1" style="${ssrRenderStyle({ "font-size": ".65rem", "color": "#333", "font-weight": "600" })}" data-v-45ceec4f${_scopeId}>${ssrInterpolate(((_a3 = exam.exam_type) == null ? void 0 : _a3.name) ?? "Exame")} - ${ssrInterpolate(latLabel(exam.laterality))} - ${ssrInterpolate(formatDateTime(exam.created_at))}</div>`);
                if (examUrls.value[exam.id] && !brokenUrls.value[exam.id]) {
                  _push3(`<img${ssrRenderAttr("src", examUrls.value[exam.id])} style="${ssrRenderStyle({ "width": "100%", "height": "auto", "display": "block", "border": "1px solid #ddd" })}" data-v-45ceec4f${_scopeId}>`);
                } else {
                  _push3(`<div class="d-flex align-items-center justify-content-center" style="${ssrRenderStyle({ "width": "100%", "aspect-ratio": "4/3", "background": "#eee", "border": "1px solid #ddd" })}" data-v-45ceec4f${_scopeId}><i class="ti ti-photo-off" style="${ssrRenderStyle({ "font-size": "2rem", "color": "#aaa" })}" data-v-45ceec4f${_scopeId}></i></div>`);
                }
                _push3(`</div>`);
              });
              _push3(`<!--]--></div></div></div></div>`);
            }, "body", false, _parent2);
            if (aiModalOpen.value) {
              _push2(`<div class="modal d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.55)" })}" data-v-45ceec4f${_scopeId}><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" data-v-45ceec4f${_scopeId}><div class="modal-content" data-v-45ceec4f${_scopeId}><div class="modal-header" data-v-45ceec4f${_scopeId}><h5 class="modal-title" data-v-45ceec4f${_scopeId}><i class="ti ti-robot me-1 text-info" data-v-45ceec4f${_scopeId}></i> ${ssrInterpolate(aiLabel("title", "Assistente de IA"))}</h5><button type="button" class="btn-close" data-v-45ceec4f${_scopeId}></button></div><div class="modal-body" data-v-45ceec4f${_scopeId}><div class="d-flex flex-wrap gap-3 mb-3 small text-muted" data-v-45ceec4f${_scopeId}><span data-v-45ceec4f${_scopeId}><strong data-v-45ceec4f${_scopeId}>${ssrInterpolate(aiBalance.available)}</strong> ${ssrInterpolate(aiLabel("credits_available", "Créditos disponíveis"))}</span><span class="text-warning" data-v-45ceec4f${_scopeId}><strong data-v-45ceec4f${_scopeId}>${ssrInterpolate(aiBalance.reserved)}</strong> ${ssrInterpolate(aiLabel("credits_reserved", "Reservados"))}</span></div><div class="alert alert-info py-2 small" data-v-45ceec4f${_scopeId}><i class="ti ti-info-circle me-1" data-v-45ceec4f${_scopeId}></i>${ssrInterpolate(aiLabel("support_notice", "A IA é apoio clínico. A decisão final é sempre do médico responsável."))}</div>`);
              if (aiAlert.message) {
                _push2(`<div class="${ssrRenderClass(`alert alert-${aiAlert.type}`)}" role="alert" data-v-45ceec4f${_scopeId}>${ssrInterpolate(aiAlert.message)}</div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<div class="row g-2 mb-3" data-v-45ceec4f${_scopeId}><div class="col-md-6" data-v-45ceec4f${_scopeId}><label class="form-label small" data-v-45ceec4f${_scopeId}>${ssrInterpolate(aiLabel("workflow", "Workflow"))}</label><select class="form-select form-select-sm" data-v-45ceec4f${_scopeId}><!--[-->`);
              ssrRenderList(aiWorkflows.value, (workflow) => {
                _push2(`<option${ssrRenderAttr("value", workflow)} data-v-45ceec4f${ssrIncludeBooleanAttr(Array.isArray(aiForm.workflow) ? ssrLooseContain(aiForm.workflow, workflow) : ssrLooseEqual(aiForm.workflow, workflow)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(aiWorkflowLabel(workflow))}</option>`);
              });
              _push2(`<!--]--></select></div><div class="col-md-6" data-v-45ceec4f${_scopeId}><label class="form-label small" data-v-45ceec4f${_scopeId}>${ssrInterpolate(aiLabel("risk", "Risco"))}</label><select class="form-select form-select-sm" data-v-45ceec4f${_scopeId}><option value="low" data-v-45ceec4f${ssrIncludeBooleanAttr(Array.isArray(aiForm.risk_level) ? ssrLooseContain(aiForm.risk_level, "low") : ssrLooseEqual(aiForm.risk_level, "low")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(aiLabel("risk_low", "Baixo"))}</option><option value="medium" data-v-45ceec4f${ssrIncludeBooleanAttr(Array.isArray(aiForm.risk_level) ? ssrLooseContain(aiForm.risk_level, "medium") : ssrLooseEqual(aiForm.risk_level, "medium")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(aiLabel("risk_medium", "Médio"))}</option><option value="high" data-v-45ceec4f${ssrIncludeBooleanAttr(Array.isArray(aiForm.risk_level) ? ssrLooseContain(aiForm.risk_level, "high") : ssrLooseEqual(aiForm.risk_level, "high")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(aiLabel("risk_high", "Alto"))}</option></select></div></div>`);
              if (aiShowPatientSelector.value) {
                _push2(`<div class="mb-3" data-v-45ceec4f${_scopeId}><label class="form-label small" data-v-45ceec4f${_scopeId}>${ssrInterpolate(aiLabel("patient_optional", "Patient (optional)"))}</label><select class="form-select form-select-sm" data-v-45ceec4f${_scopeId}><option value="" data-v-45ceec4f${ssrIncludeBooleanAttr(Array.isArray(aiForm.patient_id) ? ssrLooseContain(aiForm.patient_id, "") : ssrLooseEqual(aiForm.patient_id, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(aiLabel("select_placeholder", "Select"))}</option><!--[-->`);
                ssrRenderList(aiPatients.value, (p) => {
                  _push2(`<option${ssrRenderAttr("value", p.id)} data-v-45ceec4f${ssrIncludeBooleanAttr(Array.isArray(aiForm.patient_id) ? ssrLooseContain(aiForm.patient_id, p.id) : ssrLooseEqual(aiForm.patient_id, p.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(p.name)} (${ssrInterpolate(p.code)})</option>`);
                });
                _push2(`<!--]--></select></div>`);
              } else {
                _push2(`<div class="mb-3" data-v-45ceec4f${_scopeId}><label class="form-label small" data-v-45ceec4f${_scopeId}>${ssrInterpolate(aiLabel("patient_optional", "Patient (optional)"))}</label><div class="form-control form-control-sm bg-light d-flex align-items-center justify-content-between" data-v-45ceec4f${_scopeId}><span class="text-truncate" data-v-45ceec4f${_scopeId}>${ssrInterpolate((_b = aiSelectedPatient.value) == null ? void 0 : _b.name)}`);
                if ((_c = aiSelectedPatient.value) == null ? void 0 : _c.code) {
                  _push2(`<span data-v-45ceec4f${_scopeId}> (${ssrInterpolate((_d = aiSelectedPatient.value) == null ? void 0 : _d.code)})</span>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</span><span class="badge bg-success-subtle text-success ms-2" data-v-45ceec4f${_scopeId}>Auto</span></div></div>`);
              }
              _push2(`<div class="mb-3" data-v-45ceec4f${_scopeId}><label class="form-label fw-semibold" data-v-45ceec4f${_scopeId}>${ssrInterpolate(aiLabel("clinical_prompt", "Prompt clínico"))}</label><textarea class="form-control" rows="5" minlength="12" maxlength="30000"${ssrRenderAttr("placeholder", aiLabel("clinical_prompt_placeholder", "Descreva o contexto e objetivo clínico."))} data-v-45ceec4f${_scopeId}>${ssrInterpolate(aiForm.user_prompt)}</textarea></div><div class="mb-3" data-v-45ceec4f${_scopeId}><label class="form-label small" data-v-45ceec4f${_scopeId}>${ssrInterpolate(aiLabel("system_prompt", "System prompt"))}</label><textarea class="form-control form-control-sm" rows="2" data-v-45ceec4f${_scopeId}>${ssrInterpolate(aiForm.system_prompt)}</textarea></div>`);
              if (aiEstimate.value) {
                _push2(`<div class="border rounded p-2 bg-light small" data-v-45ceec4f${_scopeId}><div class="d-flex justify-content-between align-items-center" data-v-45ceec4f${_scopeId}><span data-v-45ceec4f${_scopeId}><strong data-v-45ceec4f${_scopeId}>${ssrInterpolate(aiLabel("estimated_credits", "Créditos estimados"))}:</strong> ${ssrInterpolate(aiEstimate.value.normalized_credits ?? "—")}</span><span class="text-muted" data-v-45ceec4f${_scopeId}>${ssrInterpolate(aiWorkflowLabel(aiEstimate.value.workflow))}</span></div></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><div class="modal-footer" data-v-45ceec4f${_scopeId}><button type="button" class="btn btn-outline-secondary btn-sm" data-v-45ceec4f${_scopeId}>${ssrInterpolate(aiLabel("close", "Close"))}</button><button type="button" class="btn btn-outline-info btn-sm"${ssrIncludeBooleanAttr(aiEstimating.value) ? " disabled" : ""} data-v-45ceec4f${_scopeId}>`);
              if (aiEstimating.value) {
                _push2(`<span class="spinner-border spinner-border-sm me-1" data-v-45ceec4f${_scopeId}></span>`);
              } else {
                _push2(`<i class="ti ti-calculator me-1" data-v-45ceec4f${_scopeId}></i>`);
              }
              _push2(`${ssrInterpolate(aiLabel("estimate", "Estimar Custo"))}</button><button type="button" class="btn btn-success btn-sm"${ssrIncludeBooleanAttr(aiSubmitting.value || !aiEstimate.value) ? " disabled" : ""} data-v-45ceec4f${_scopeId}>`);
              if (aiSubmitting.value) {
                _push2(`<span class="spinner-border spinner-border-sm me-1" data-v-45ceec4f${_scopeId}></span>`);
              } else {
                _push2(`<i class="ti ti-player-play me-1" data-v-45ceec4f${_scopeId}></i>`);
              }
              _push2(`${ssrInterpolate(aiLabel("run", "Executar IA"))}</button></div></div></div></div>`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              createVNode(_sfc_main$1, {
                title: "Imagens oftálmicas",
                subtitle: `${totalExams.value} exames`
              }, null, 8, ["subtitle"]),
              createVNode("div", { class: "card mb-3" }, [
                createVNode("div", { class: "card-body py-2 px-3" }, [
                  createVNode("div", { class: "row g-2 align-items-center" }, [
                    createVNode("div", { class: "col-12 col-sm-4 col-md-3" }, [
                      createVNode("div", { class: "input-group input-group-sm" }, [
                        createVNode("span", { class: "input-group-text" }, [
                          createVNode("i", { class: "fa fa-search" })
                        ]),
                        withDirectives(createVNode("input", {
                          type: "text",
                          class: "form-control",
                          placeholder: "Buscar paciente...",
                          "onUpdate:modelValue": ($event) => search.value = $event,
                          onKeydown: withKeys(($event) => search.value = "", ["escape"])
                        }, null, 40, ["onUpdate:modelValue", "onKeydown"]), [
                          [vModelText, search.value]
                        ]),
                        search.value ? (openBlock(), createBlock("button", {
                          key: 0,
                          class: "btn btn-outline-secondary",
                          type: "button",
                          onClick: ($event) => search.value = ""
                        }, [
                          createVNode("i", { class: "fa fa-times" })
                        ], 8, ["onClick"])) : createCommentVNode("", true)
                      ])
                    ]),
                    createVNode("div", { class: "col-6 col-sm-3 col-md-2" }, [
                      createVNode("select", {
                        class: "form-select form-select-sm",
                        value: period.value,
                        onChange: ($event) => changePeriod($event.target.value)
                      }, [
                        createVNode("option", { value: "hoje" }, "Hoje"),
                        createVNode("option", { value: "7" }, "Últimos 7 dias"),
                        createVNode("option", { value: "15" }, "Últimos 15 dias"),
                        createVNode("option", { value: "30" }, "Últimos 30 dias"),
                        createVNode("option", { value: "90" }, "Últimos 90 dias")
                      ], 40, ["value", "onChange"])
                    ]),
                    createVNode("div", { class: "col-6 col-sm-auto" }, [
                      createVNode("button", {
                        type: "button",
                        class: ["btn btn-sm", showFilters.value ? "btn-primary" : "btn-outline-secondary"],
                        onClick: ($event) => showFilters.value = !showFilters.value
                      }, [
                        createVNode("i", { class: "fa fa-filter me-1" }),
                        createTextVNode("Filtros "),
                        !showFilters.value ? (openBlock(), createBlock("i", {
                          key: 0,
                          class: "fa fa-chevron-down ms-1"
                        })) : (openBlock(), createBlock("i", {
                          key: 1,
                          class: "fa fa-chevron-up ms-1"
                        }))
                      ], 10, ["onClick"])
                    ]),
                    createVNode("div", { class: "col col-md d-flex justify-content-end" }, [
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-primary btn-sm"
                      }, [
                        createVNode("i", { class: "fa fa-plus" }),
                        createTextVNode(" Novo ")
                      ])
                    ])
                  ]),
                  withDirectives(createVNode("div", { class: "row g-2 mt-1 pt-2 border-top align-items-center" }, [
                    createVNode("div", { class: "col-auto" }, [
                      createVNode("div", { class: "d-flex align-items-center gap-2" }, [
                        createVNode("span", {
                          class: "text-muted small fw-semibold",
                          style: { "white-space": "nowrap" }
                        }, "Olho"),
                        createVNode("div", {
                          class: "btn-group btn-group-sm",
                          role: "group"
                        }, [
                          withDirectives(createVNode("input", {
                            type: "radio",
                            class: "btn-check",
                            name: "f-lat",
                            id: "f-lat-all",
                            value: "",
                            "onUpdate:modelValue": ($event) => laterality.value = $event
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelRadio, laterality.value]
                          ]),
                          createVNode("label", {
                            class: "btn btn-outline-secondary",
                            for: "f-lat-all"
                          }, "Todos"),
                          withDirectives(createVNode("input", {
                            type: "radio",
                            class: "btn-check",
                            name: "f-lat",
                            id: "f-lat-od",
                            value: "od",
                            "onUpdate:modelValue": ($event) => laterality.value = $event
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelRadio, laterality.value]
                          ]),
                          createVNode("label", {
                            class: "btn btn-outline-primary",
                            for: "f-lat-od"
                          }, "OD"),
                          withDirectives(createVNode("input", {
                            type: "radio",
                            class: "btn-check",
                            name: "f-lat",
                            id: "f-lat-oe",
                            value: "oe",
                            "onUpdate:modelValue": ($event) => laterality.value = $event
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelRadio, laterality.value]
                          ]),
                          createVNode("label", {
                            class: "btn btn-outline-danger",
                            for: "f-lat-oe"
                          }, "OE"),
                          withDirectives(createVNode("input", {
                            type: "radio",
                            class: "btn-check",
                            name: "f-lat",
                            id: "f-lat-ao",
                            value: "ao",
                            "onUpdate:modelValue": ($event) => laterality.value = $event
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelRadio, laterality.value]
                          ]),
                          createVNode("label", {
                            class: "btn btn-outline-dark",
                            for: "f-lat-ao"
                          }, "AO")
                        ])
                      ])
                    ]),
                    createVNode("div", { class: "col-12 col-sm-6 col-md-3" }, [
                      withDirectives(createVNode("select", {
                        class: "form-select form-select-sm",
                        "onUpdate:modelValue": ($event) => examTypeId.value = $event
                      }, [
                        createVNode("option", { value: "" }, "Todos os exames"),
                        (openBlock(true), createBlock(Fragment, null, renderList(availableExamTypes.value, (t) => {
                          return openBlock(), createBlock("option", {
                            key: t.id,
                            value: t.id
                          }, toDisplayString(t.name), 9, ["value"]);
                        }), 128))
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, examTypeId.value]
                      ])
                    ]),
                    createVNode("div", { class: "col-12 col-sm-6 col-md-2" }, [
                      withDirectives(createVNode("select", {
                        class: "form-select form-select-sm",
                        "onUpdate:modelValue": ($event) => examStatus.value = $event
                      }, [
                        createVNode("option", { value: "" }, "Todos status"),
                        createVNode("option", { value: "solicitado" }, "Solicitado"),
                        createVNode("option", { value: "realizado" }, "Realizado"),
                        createVNode("option", { value: "laudado" }, "Laudado"),
                        createVNode("option", { value: "cancelado" }, "Cancelado")
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, examStatus.value]
                      ])
                    ]),
                    createVNode("div", { class: "col-12 col-sm-6 col-md-3" }, [
                      createVNode("select", {
                        class: "form-select form-select-sm",
                        value: doctorId.value,
                        onChange: ($event) => setDoctor($event.target.value)
                      }, [
                        createVNode("option", { value: "" }, "Todos médicos"),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.doctors, (d) => {
                          return openBlock(), createBlock("option", {
                            key: d.id,
                            value: d.id
                          }, toDisplayString(d.name), 9, ["value"]);
                        }), 128))
                      ], 40, ["value", "onChange"])
                    ]),
                    createVNode("div", { class: "col-auto" }, [
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-sm btn-outline-secondary",
                        onClick: clearFilters
                      }, [
                        createVNode("i", { class: "fa fa-times me-1" }),
                        createTextVNode(" Limpar ")
                      ])
                    ])
                  ], 512), [
                    [vShow, showFilters.value]
                  ])
                ])
              ]),
              createVNode("div", { class: "row" }, [
                createVNode("div", { class: "col-xs-12 col-sm-3 col-md-3 col-lg-3" }, [
                  createVNode("div", { class: "card panel-info" }, [
                    createVNode("div", { class: "card-body p-2" }, [
                      createVNode("h6", { class: "font-bold text-uppercase px-1 mb-1 mt-3" }, "Pacientes"),
                      createVNode("hr", { class: "mt-0 mb-2" }),
                      loading.value ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "text-center py-3"
                      }, [
                        createVNode("div", {
                          class: "spinner-border spinner-border-sm text-info",
                          role: "status"
                        })
                      ])) : (openBlock(), createBlock("div", {
                        key: 1,
                        style: { "max-height": "520px", "overflow-y": "auto", "overflow-x": "hidden" }
                      }, [
                        filteredPatients.value.length === 0 ? (openBlock(), createBlock("p", {
                          key: 0,
                          class: "text-muted text-center small py-3 mb-0"
                        }, " Nenhum paciente encontrado. ")) : createCommentVNode("", true),
                        (openBlock(true), createBlock(Fragment, null, renderList(filteredPatients.value, (patient) => {
                          var _a2, _b2, _c2, _d2;
                          return openBlock(), createBlock("div", {
                            key: patient.id,
                            class: ["d-flex align-items-center gap-2 px-1 py-1 rounded mb-1 patient-item", { "patient-item-active": ((_a2 = selectedPatient.value) == null ? void 0 : _a2.id) === patient.id }],
                            onClick: ($event) => selectPatient(patient)
                          }, [
                            createVNode("div", {
                              class: "rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 text-white fw-bold",
                              style: { background: avatarColor(((_b2 = patient.person) == null ? void 0 : _b2.full_name) ?? patient.full_name), width: "30px", height: "30px", fontSize: ".62rem" }
                            }, toDisplayString(initials(((_c2 = patient.person) == null ? void 0 : _c2.full_name) ?? patient.full_name)), 5),
                            createVNode("div", { class: "flex-grow-1 min-w-0" }, [
                              createVNode("div", {
                                class: "text-truncate fw-semibold",
                                style: { "font-size": ".75rem", "line-height": "1.2" }
                              }, toDisplayString(((_d2 = patient.person) == null ? void 0 : _d2.full_name) ?? patient.full_name ?? "—"), 1),
                              createVNode("div", {
                                class: "text-muted",
                                style: { "font-size": ".65rem", "line-height": "1.2" }
                              }, toDisplayString(patient.code), 1)
                            ]),
                            createVNode("span", {
                              class: "badge bg-primary rounded-pill flex-shrink-0",
                              style: { "font-size": ".6rem" }
                            }, toDisplayString(patient.exams.length), 1)
                          ], 10, ["onClick"]);
                        }), 128))
                      ])),
                      !loading.value ? (openBlock(), createBlock("div", {
                        key: 2,
                        class: "text-muted px-1 mt-1",
                        style: { "font-size": ".65rem" }
                      }, toDisplayString(filteredPatients.value.length) + " paciente(s) ", 1)) : createCommentVNode("", true)
                    ])
                  ])
                ]),
                createVNode("div", { class: "col-xs-12 col-sm-9 col-md-9 col-lg-9" }, [
                  createVNode("div", { class: "card" }, [
                    createVNode("h5", { class: "card-header d-flex align-items-center gap-2" }, [
                      !selectedPatient.value ? (openBlock(), createBlock("span", { key: 0 }, "Selecione um paciente")) : (openBlock(), createBlock("span", {
                        key: 1,
                        class: "d-flex align-items-center gap-2 w-100"
                      }, [
                        createVNode("button", {
                          type: "button",
                          class: "btn btn-outline-secondary btn-sm",
                          onClick: ($event) => {
                            selectedPatient.value = null;
                            selectedExamIds.value = [];
                          }
                        }, [
                          createVNode("i", { class: "fa fa-arrow-left" })
                        ], 8, ["onClick"]),
                        createVNode("span", null, [
                          createVNode("span", null, toDisplayString(((_e = selectedPatient.value.person) == null ? void 0 : _e.full_name) ?? selectedPatient.value.full_name), 1),
                          createVNode("small", {
                            class: "text-muted fw-normal ms-2",
                            style: { "font-size": ".72rem" }
                          }, toDisplayString(selectedPatient.value.code), 1)
                        ]),
                        createVNode("div", { class: "flex-grow-1" }),
                        createVNode("a", {
                          href: `/panel/patients/${selectedPatient.value.id}/medicalrecords`,
                          target: "_blank",
                          class: "btn btn-outline-primary btn-sm",
                          style: { "font-size": ".72rem" }
                        }, [
                          createTextVNode(" Prontuário "),
                          createVNode("i", { class: "fa fa-external-link ms-1" })
                        ], 8, ["href"])
                      ]))
                    ]),
                    selectedPatient.value ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "d-flex align-items-center gap-2 px-3 py-2 border-bottom bg-body-secondary"
                    }, [
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-sm btn-outline-primary",
                        disabled: selectedExamIds.value.length === 0,
                        onClick: ($event) => openViewerModal(selectedExamsData.value)
                      }, [
                        createVNode("i", { class: "fa fa-images me-1" }),
                        createTextVNode("Visualizar selecionados "),
                        selectedExamIds.value.length > 0 ? (openBlock(), createBlock("span", {
                          key: 0,
                          class: "badge bg-primary ms-1"
                        }, toDisplayString(selectedExamIds.value.length), 1)) : createCommentVNode("", true)
                      ], 8, ["disabled", "onClick"]),
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-sm btn-outline-secondary",
                        onClick: ($event) => openViewerModal(selectedPatient.value.exams)
                      }, [
                        createVNode("i", { class: "fa fa-th me-1" }),
                        createTextVNode("Visualizar todos ")
                      ], 8, ["onClick"]),
                      createVNode("div", { class: "vr opacity-25" }),
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-sm btn-outline-dark",
                        onClick: ($event) => openPrintModal(selectedPatient.value.exams, false)
                      }, [
                        createVNode("i", { class: "fa fa-print me-1" }),
                        createTextVNode("Imprimir ")
                      ], 8, ["onClick"]),
                      createVNode("div", { class: "flex-grow-1" }),
                      selectedExamIds.value.length > 0 ? (openBlock(), createBlock("span", {
                        key: 0,
                        class: "text-muted",
                        style: { "font-size": ".7rem" }
                      }, toDisplayString(selectedExamIds.value.length) + " selecionado(s) ", 1)) : createCommentVNode("", true)
                    ])) : createCommentVNode("", true),
                    createVNode("div", { class: "card-body" }, [
                      !selectedPatient.value ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "text-center py-5 text-muted"
                      }, [
                        createVNode("i", {
                          class: "ti ti-eye",
                          style: { "font-size": "3rem", "opacity": ".3" }
                        }),
                        createVNode("p", { class: "mt-3 mb-0" }, "Selecione um paciente para ver os exames.")
                      ])) : (openBlock(), createBlock("div", {
                        key: 1,
                        class: "row g-0",
                        style: { "min-height": "480px" }
                      }, [
                        createVNode("div", { class: "col-12 border-end pe-0" }, [
                          urlsLoading.value ? (openBlock(), createBlock("div", {
                            key: 0,
                            class: "text-center py-3"
                          }, [
                            createVNode("div", {
                              class: "spinner-border spinner-border-sm text-secondary",
                              role: "status"
                            }),
                            createVNode("p", { class: "text-muted small mt-1 mb-0" }, "Carregando imagens...")
                          ])) : filteredExams.value.length === 0 ? (openBlock(), createBlock("p", {
                            key: 1,
                            class: "text-muted text-center small py-4"
                          }, " Nenhum exame encontrado. ")) : (openBlock(), createBlock("div", {
                            key: 2,
                            style: { "max-height": "620px", "overflow-y": "auto", "overflow-x": "hidden" }
                          }, [
                            (openBlock(true), createBlock(Fragment, null, renderList(groupedExams.value, (group) => {
                              var _a2;
                              return openBlock(), createBlock("div", {
                                key: group.key,
                                class: "mb-1"
                              }, [
                                createVNode("div", {
                                  class: "px-2 py-1 d-flex align-items-center gap-1 flex-wrap bg-body-tertiary text-body border-bottom fw-semibold",
                                  style: { "font-size": ".7rem", "row-gap": "3px" }
                                }, [
                                  createVNode("span", null, toDisplayString(formatDateFull(group.date)), 1),
                                  group.equipment ? (openBlock(), createBlock("span", {
                                    key: 0,
                                    class: "d-flex align-items-center gap-1"
                                  }, [
                                    createVNode("span", { class: "opacity-50" }, ":"),
                                    createVNode("span", null, toDisplayString(group.equipment.name), 1)
                                  ])) : createCommentVNode("", true),
                                  createVNode("div", { class: "flex-grow-1" }),
                                  createVNode("div", {
                                    class: "btn-group btn-group-sm",
                                    role: "group"
                                  }, [
                                    createVNode("button", {
                                      type: "button",
                                      class: ["btn py-0 px-2", groupLatActive(group, "od") ? "btn-primary" : "btn-outline-primary"],
                                      style: { "font-size": ".6rem" },
                                      onClick: withModifiers(($event) => selectExamByLaterality(group, "od"), ["stop"])
                                    }, "OD", 10, ["onClick"]),
                                    createVNode("button", {
                                      type: "button",
                                      class: ["btn py-0 px-2", groupLatActive(group, "oe") ? "btn-danger" : "btn-outline-danger"],
                                      style: { "font-size": ".6rem" },
                                      onClick: withModifiers(($event) => selectExamByLaterality(group, "oe"), ["stop"])
                                    }, "OE", 10, ["onClick"]),
                                    createVNode("button", {
                                      type: "button",
                                      class: ["btn py-0 px-2", groupLatActive(group, "ao") ? "btn-secondary" : "btn-outline-secondary"],
                                      style: { "font-size": ".6rem" },
                                      onClick: withModifiers(($event) => selectExamByLaterality(group, "ao"), ["stop"])
                                    }, "AO", 10, ["onClick"]),
                                    createVNode("button", {
                                      type: "button",
                                      class: ["btn py-0 px-2", groupLatActive(group, "all") ? "btn-secondary" : "btn-outline-secondary"],
                                      style: { "font-size": ".6rem" },
                                      onClick: withModifiers(($event) => selectExamByLaterality(group, "all"), ["stop"])
                                    }, "Todos", 10, ["onClick"])
                                  ]),
                                  createVNode("div", { class: "vr opacity-25 mx-1" }),
                                  createVNode("button", {
                                    type: "button",
                                    class: "btn btn-sm py-0 px-2 btn-outline-secondary",
                                    style: { "font-size": ".6rem" },
                                    title: "Upload de imagem"
                                  }, [
                                    createVNode("i", { class: "fa fa-upload me-1" }),
                                    createTextVNode("Upload ")
                                  ]),
                                  createVNode("button", {
                                    type: "button",
                                    class: "btn btn-sm py-0 px-2 btn-outline-secondary",
                                    style: { "font-size": ".6rem" },
                                    title: "Download das imagens"
                                  }, [
                                    createVNode("i", { class: "fa fa-download me-1" }),
                                    createTextVNode("Download ")
                                  ])
                                ]),
                                createVNode("div", {
                                  class: "px-2 py-1 bg-body-secondary text-body-secondary border-bottom",
                                  style: { "font-size": ".68rem" }
                                }, toDisplayString(((_a2 = group.examType) == null ? void 0 : _a2.name) || "Exame"), 1),
                                createVNode("div", { class: "d-flex flex-wrap gap-2 p-2 bg-dark" }, [
                                  (openBlock(true), createBlock(Fragment, null, renderList(group.exams, (exam) => {
                                    var _a3;
                                    return openBlock(), createBlock("div", {
                                      key: exam.id,
                                      class: "position-relative",
                                      style: { "cursor": "pointer", "flex-shrink": "0" },
                                      onClick: ($event) => toggleExamSelection(exam.id)
                                    }, [
                                      createVNode("span", {
                                        class: ["position-absolute top-0 end-0 rounded-circle d-flex align-items-center justify-content-center text-white fw-bold", {
                                          "bg-primary": exam.laterality === 1,
                                          "bg-danger": exam.laterality === 2,
                                          "bg-secondary": exam.laterality !== 1 && exam.laterality !== 2
                                        }],
                                        style: { "width": "22px", "height": "22px", "font-size": ".55rem", "z-index": "1", "margin": "3px" }
                                      }, toDisplayString(latLabel(exam.laterality)), 3),
                                      createVNode("span", {
                                        class: "position-absolute bottom-0 start-0 d-flex align-items-center justify-content-center",
                                        style: { "z-index": "2", "margin": "3px" }
                                      }, [
                                        createVNode("span", {
                                          class: ["rounded d-flex align-items-center justify-content-center", isSelected(exam.id) ? "bg-primary" : "bg-dark border border-secondary"],
                                          style: { "width": "16px", "height": "16px" }
                                        }, [
                                          withDirectives(createVNode("i", {
                                            class: "fa fa-check text-white",
                                            style: { "font-size": ".5rem" }
                                          }, null, 512), [
                                            [vShow, isSelected(exam.id)]
                                          ])
                                        ], 2)
                                      ]),
                                      examUrls.value[exam.id] && !brokenUrls.value[exam.id] ? (openBlock(), createBlock("img", {
                                        key: 0,
                                        src: examUrls.value[exam.id],
                                        alt: (_a3 = exam.exam_type) == null ? void 0 : _a3.name,
                                        width: "100",
                                        height: "76",
                                        style: `object-fit:cover;display:block;border-radius:4px;outline:${isSelected(exam.id) ? "2px solid #6ea8fe" : "2px solid transparent"};transition:outline .1s;`,
                                        onError: ($event) => brokenUrls.value = { ...brokenUrls.value, [exam.id]: true }
                                      }, null, 44, ["src", "alt", "onError"])) : (openBlock(), createBlock("div", {
                                        key: 1,
                                        class: "d-flex align-items-center justify-content-center rounded",
                                        style: `width:100px;height:76px;background:#3a3c42;border-radius:4px;outline:${isSelected(exam.id) ? "2px solid #6ea8fe" : "2px solid transparent"};transition:outline .1s;`
                                      }, [
                                        createVNode("i", {
                                          class: "ti ti-photo-off",
                                          style: { "font-size": "1.4rem", "color": "#555" }
                                        })
                                      ], 4))
                                    ], 8, ["onClick"]);
                                  }), 128))
                                ])
                              ]);
                            }), 128))
                          ]))
                        ])
                      ]))
                    ])
                  ])
                ])
              ]),
              (openBlock(), createBlock(Teleport, { to: "body" }, [
                withDirectives(createVNode("div", { style: { "position": "fixed", "inset": "0", "z-index": "9998", "background": "#0a0a0a", "display": "flex", "flex-direction": "column", "overflow": "hidden" } }, [
                  createVNode("div", {
                    class: "d-flex align-items-center gap-1 px-3 py-2 flex-shrink-0 flex-wrap",
                    style: { "background": "#111", "border-bottom": "1px solid #222", "row-gap": "4px" }
                  }, [
                    createVNode("div", {
                      class: "btn-group btn-group-sm",
                      role: "group"
                    }, [
                      (openBlock(), createBlock(Fragment, null, renderList([1, 2, 3, 4], (n) => {
                        return createVNode("button", {
                          key: n,
                          type: "button",
                          class: ["btn fw-semibold", viewerPanelCount.value === n ? "btn-primary" : "btn-outline-secondary"],
                          style: { "min-width": "26px", "font-size": ".72rem" },
                          onClick: ($event) => setViewerPanelCount(n)
                        }, toDisplayString(n), 11, ["onClick"]);
                      }), 64))
                    ]),
                    createVNode("div", { class: "vr opacity-25 mx-1" }),
                    createVNode("button", {
                      type: "button",
                      class: ["btn btn-sm fw-semibold", viewerAllMode.value ? "btn-info text-dark" : "btn-outline-secondary"],
                      style: { "font-size": ".72rem" },
                      onClick: viewerToggleAll
                    }, "All", 2),
                    createVNode("div", { class: "vr opacity-25 mx-1" }),
                    createVNode("button", {
                      type: "button",
                      class: ["btn btn-sm fw-semibold", viewerLensActive.value ? "btn-warning text-dark" : "btn-outline-secondary"],
                      style: { "font-size": ".72rem" },
                      onClick: toggleLens
                    }, [
                      createVNode("i", { class: "fa fa-search-plus" }),
                      createTextVNode(" Lens ")
                    ], 2),
                    viewerLensActive.value ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "d-flex align-items-center gap-1"
                    }, [
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-sm btn-outline-secondary py-0 px-1",
                        onClick: ($event) => adjustZoom(-0.5)
                      }, [
                        createVNode("i", {
                          class: "fa fa-minus",
                          style: { "font-size": ".65rem" }
                        })
                      ], 8, ["onClick"]),
                      createVNode("span", { style: { "color": "#fff", "font-size": ".78rem", "font-weight": "600", "min-width": "48px", "text-align": "center", "display": "inline-block" } }, toDisplayString(viewerZoom.value.toFixed(1)) + "x ", 1),
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-sm btn-outline-secondary py-0 px-1",
                        onClick: ($event) => adjustZoom(0.5)
                      }, [
                        createVNode("i", {
                          class: "fa fa-plus",
                          style: { "font-size": ".65rem" }
                        })
                      ], 8, ["onClick"])
                    ])) : createCommentVNode("", true),
                    createVNode("div", { class: "vr opacity-25 mx-1" }),
                    createVNode("button", {
                      type: "button",
                      class: ["btn btn-sm fw-semibold", viewerFitMode.value ? "btn-light text-dark" : "btn-outline-secondary"],
                      style: { "font-size": ".72rem" },
                      onClick: ($event) => viewerFitMode.value = !viewerFitMode.value,
                      title: "Ajustar à área de visualização"
                    }, [
                      createVNode("i", { class: "fa fa-compress-arrows-alt me-1" }),
                      createTextVNode("Fit ")
                    ], 10, ["onClick"]),
                    createVNode("button", {
                      type: "button",
                      class: ["btn btn-sm fw-semibold", viewerSplitMode.value ? "btn-info text-dark" : "btn-outline-secondary"],
                      style: { "font-size": ".72rem" },
                      onClick: viewerSplitOdOs
                    }, "OD|OE", 2),
                    createVNode("button", {
                      type: "button",
                      class: ["btn btn-sm fw-semibold", viewerLaserMode.value ? "btn-success" : "btn-outline-secondary"],
                      style: { "font-size": ".72rem" },
                      onClick: toggleAllFlip,
                      title: "Inverter imagem verticalmente"
                    }, [
                      createVNode("i", { class: "fa fa-undo me-1" }),
                      createTextVNode("Laser ")
                    ], 2),
                    createVNode("div", { class: "vr opacity-25 mx-1" }),
                    createVNode("div", { class: "flex-grow-1" }),
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-sm btn-outline-danger",
                      onClick: ($event) => showViewerModal.value = false
                    }, [
                      createVNode("i", { class: "fa fa-times" })
                    ], 8, ["onClick"])
                  ]),
                  withDirectives(createVNode("div", {
                    class: "flex-grow-1",
                    style: viewerPanelGridStyle.value
                  }, [
                    (openBlock(true), createBlock(Fragment, null, renderList(viewerPanelCount.value, (pi) => {
                      var _a2, _b2;
                      return openBlock(), createBlock("div", {
                        key: pi,
                        class: "position-relative d-flex flex-column",
                        style: `background:#111;border-radius:3px;min-height:0;cursor:pointer;overflow:hidden;outline:${viewerActivePanel.value === pi - 1 ? "2px solid #0d6efd" : "1px solid #2a2a2a"};`,
                        onClick: ($event) => viewerActivePanel.value = pi - 1
                      }, [
                        createVNode("div", {
                          class: ["flex-grow-1 position-relative d-flex", viewerFitMode.value ? "align-items-start justify-content-center" : "align-items-center justify-content-center"],
                          style: (viewerFitMode.value ? "height:84vh;min-height:0;overflow-y:auto;overflow-x:hidden;" : "min-height:0;overflow:hidden;") + (viewerLensActive.value && viewerPanelUrls.value[pi - 1] && !viewerPanelBroken.value[pi - 1] ? "cursor:none;" : ""),
                          onMousemove: withModifiers(($event) => onViewerLensMove($event, pi - 1), ["stop"]),
                          onMouseleave: withModifiers(onPanelLeave, ["stop"]),
                          onMouseenter: withModifiers(($event) => onPanelEnter(pi - 1), ["stop"]),
                          onWheel: withModifiers(($event) => onPanelWheel($event, pi - 1), ["prevent", "stop"])
                        }, [
                          withDirectives(createVNode("div", {
                            class: "text-center text-white position-absolute",
                            style: { "z-index": "5" }
                          }, [
                            createVNode("div", {
                              class: "spinner-border spinner-border-sm text-light",
                              role: "status"
                            })
                          ], 512), [
                            [vShow, viewerPanelLoading.value[pi - 1]]
                          ]),
                          withDirectives(createVNode("div", { class: "text-center text-muted" }, [
                            createVNode("i", {
                              class: "ti ti-photo",
                              style: { "font-size": "2.5rem", "opacity": ".12" }
                            }),
                            createVNode("p", {
                              class: "mt-1 mb-0",
                              style: { "font-size": ".65rem", "opacity": ".35" }
                            }, "Painel " + toDisplayString(pi), 1)
                          ], 512), [
                            [vShow, !viewerPanelExams.value[pi - 1] && !viewerPanelLoading.value[pi - 1]]
                          ]),
                          withDirectives(createVNode("div", { class: "text-center text-muted" }, [
                            createVNode("i", {
                              class: "ti ti-photo-off",
                              style: { "font-size": "2rem", "opacity": ".3" }
                            }),
                            createVNode("p", {
                              class: "mt-1 mb-0",
                              style: { "font-size": ".65rem" }
                            }, "Sem imagem")
                          ], 512), [
                            [vShow, viewerPanelExams.value[pi - 1] && !viewerPanelUrls.value[pi - 1] && !viewerPanelLoading.value[pi - 1] && !viewerPanelBroken.value[pi - 1]]
                          ]),
                          withDirectives(createVNode("div", { class: "text-center text-muted" }, [
                            createVNode("i", {
                              class: "ti ti-photo-off",
                              style: { "font-size": "2rem", "opacity": ".3" }
                            }),
                            createVNode("p", {
                              class: "mt-1 mb-0",
                              style: { "font-size": ".65rem" }
                            }, "Arquivo não encontrado")
                          ], 512), [
                            [vShow, viewerPanelBroken.value[pi - 1]]
                          ]),
                          withDirectives(createVNode("img", {
                            src: viewerPanelUrls.value[pi - 1] ?? "",
                            alt: "Exame",
                            style: (viewerFitMode.value ? "width:100%;height:auto;max-width:100%;max-height:none;flex-shrink:0;" : "width:100%;height:84vh;object-fit:contain;") + "display:block;user-select:none;" + (viewerPanelFlipped.value[pi - 1] ? "transform:scaleY(-1);" : ""),
                            onLoad: ($event) => setPanelLoaded(pi - 1),
                            onError: ($event) => setPanelError(pi - 1)
                          }, null, 44, ["src", "onLoad", "onError"]), [
                            [vShow, viewerPanelUrls.value[pi - 1] && !viewerPanelLoading.value[pi - 1] && !viewerPanelBroken.value[pi - 1]]
                          ])
                        ], 46, ["onMousemove", "onMouseenter", "onWheel"]),
                        createVNode("div", {
                          class: "d-flex align-items-center gap-1 px-2 flex-shrink-0",
                          style: { "background": "#0d0d0d", "font-size": ".6rem", "min-height": "22px", "border-top": "1px solid #1a1a1a" }
                        }, [
                          viewerPanelExams.value[pi - 1] ? (openBlock(), createBlock("span", {
                            key: 0,
                            class: "d-flex align-items-center gap-1 overflow-hidden w-100"
                          }, [
                            createVNode("span", {
                              class: ["badge flex-shrink-0", {
                                "bg-primary": viewerPanelExams.value[pi - 1].laterality === 1,
                                "bg-danger": viewerPanelExams.value[pi - 1].laterality === 2,
                                "bg-secondary": viewerPanelExams.value[pi - 1].laterality !== 1 && viewerPanelExams.value[pi - 1].laterality !== 2
                              }],
                              style: { "font-size": ".5rem" }
                            }, toDisplayString(latLabel(viewerPanelExams.value[pi - 1].laterality)), 3),
                            createVNode("span", { class: "text-secondary text-truncate" }, toDisplayString(((_a2 = viewerPanelExams.value[pi - 1].exam_type) == null ? void 0 : _a2.name) ?? "—"), 1),
                            createVNode("span", { class: "text-secondary opacity-50 flex-shrink-0 ms-auto" }, toDisplayString(formatDateFull((_b2 = viewerPanelExams.value[pi - 1].created_at) == null ? void 0 : _b2.substring(0, 10))), 1)
                          ])) : (openBlock(), createBlock("span", {
                            key: 1,
                            class: "text-secondary",
                            style: { "opacity": ".3" }
                          }, "Painel " + toDisplayString(pi), 1))
                        ]),
                        createVNode("div", {
                          class: "flex-shrink-0 d-flex align-items-center gap-1 overflow-x-auto overflow-y-hidden py-1 px-1",
                          style: `background:#0d0d0d;height:80px;border-top:1px solid ${viewerActivePanel.value === pi - 1 ? "#0d6efd" : "#222"};`,
                          onClick: withModifiers(() => {
                          }, ["stop"])
                        }, [
                          (openBlock(true), createBlock(Fragment, null, renderList(panelStripExams(pi - 1), (exam) => {
                            var _a3;
                            return openBlock(), createBlock("div", {
                              key: `tn-${pi}-${exam.id}`,
                              style: { "flex-shrink": "0", "cursor": "pointer" },
                              onClick: withModifiers(($event) => {
                                setPanelExam(pi - 1, exam);
                                viewerActivePanel.value = pi - 1;
                              }, ["stop"])
                            }, [
                              createVNode("div", {
                                class: "position-relative rounded overflow-hidden",
                                style: `width:64px;height:64px;outline:${((_a3 = viewerPanelExams.value[pi - 1]) == null ? void 0 : _a3.id) === exam.id ? "2px solid #0d6efd" : "1px solid #2a2a2a"};`
                              }, [
                                createVNode("span", {
                                  class: ["position-absolute top-0 end-0 rounded-circle d-flex align-items-center justify-content-center text-white fw-bold", {
                                    "bg-primary": exam.laterality === 1,
                                    "bg-danger": exam.laterality === 2,
                                    "bg-secondary": exam.laterality !== 1 && exam.laterality !== 2
                                  }],
                                  style: { "width": "14px", "height": "14px", "font-size": ".4rem", "z-index": "1", "margin": "2px" }
                                }, toDisplayString(latLabel(exam.laterality)), 3),
                                examUrls.value[exam.id] && !brokenUrls.value[exam.id] ? (openBlock(), createBlock("img", {
                                  key: 0,
                                  src: examUrls.value[exam.id],
                                  style: { "width": "64px", "height": "64px", "object-fit": "cover", "display": "block" },
                                  onError: ($event) => brokenUrls.value = { ...brokenUrls.value, [exam.id]: true }
                                }, null, 40, ["src", "onError"])) : (openBlock(), createBlock("div", {
                                  key: 1,
                                  class: "w-100 h-100 d-flex align-items-center justify-content-center",
                                  style: { "background": "#1a1a1a" }
                                }, [
                                  createVNode("i", {
                                    class: "ti ti-photo-off",
                                    style: { "color": "#444", "font-size": ".9rem" }
                                  })
                                ]))
                              ], 4)
                            ], 8, ["onClick"]);
                          }), 128))
                        ], 12, ["onClick"])
                      ], 12, ["onClick"]);
                    }), 128))
                  ], 4), [
                    [vShow, !viewerAllMode.value]
                  ]),
                  withDirectives(createVNode("div", {
                    id: "viewImages",
                    class: "ei-scroll",
                    style: { "position": "absolute", "top": "50px", "left": "0", "right": "0", "bottom": "0", "overflow-y": "auto", "overflow-x": "hidden", "background": "#0a0a0a", "padding": "4px" }
                  }, [
                    createVNode("div", {
                      style: `display:grid;grid-template-columns:repeat(${viewerSplitMode.value ? 2 : viewerPanelCount.value},1fr);gap:4px;`
                    }, [
                      (openBlock(true), createBlock(Fragment, null, renderList(allGridExams(), (exam) => {
                        var _a2;
                        return openBlock(), createBlock("div", {
                          key: `all-grid-${exam.id}`,
                          class: "position-relative",
                          style: { "background": "#111", "border-radius": "3px", "overflow": "hidden", "display": "flex", "flex-direction": "column" }
                        }, [
                          createVNode("div", {
                            class: "d-flex align-items-center gap-2 px-2 py-1",
                            style: { "background": "#0d0d0d", "border-bottom": "1px solid #1a1a1a", "font-size": ".65rem" }
                          }, [
                            createVNode("span", {
                              class: ["badge flex-shrink-0", {
                                "bg-primary": exam.laterality === 1,
                                "bg-danger": exam.laterality === 2,
                                "bg-secondary": exam.laterality !== 1 && exam.laterality !== 2
                              }],
                              style: { "font-size": ".5rem" }
                            }, toDisplayString(latLabel(exam.laterality)), 3),
                            createVNode("span", { class: "text-secondary text-truncate" }, toDisplayString(((_a2 = exam.exam_type) == null ? void 0 : _a2.name) ?? "—"), 1),
                            createVNode("span", { class: "text-secondary opacity-50 flex-shrink-0 ms-auto" }, toDisplayString(formatDateTime(exam.created_at)), 1)
                          ]),
                          createVNode("div", {
                            class: "position-relative",
                            style: viewerLensActive.value && examUrls.value[exam.id] && !brokenUrls.value[exam.id] ? "cursor:none;" : "",
                            onMousemove: withModifiers(($event) => onAllLensMove($event, exam), ["stop"]),
                            onMouseleave: withModifiers(($event) => viewerLensVisible.value = false, ["stop"]),
                            onMouseenter: withModifiers(($event) => onAllEnter(exam), ["stop"]),
                            onWheel: ($event) => onAllWheel($event)
                          }, [
                            examUrls.value[exam.id] && !brokenUrls.value[exam.id] ? (openBlock(), createBlock("img", {
                              key: 0,
                              src: examUrls.value[exam.id],
                              style: "width:100%;height:auto;display:block;user-select:none;" + (viewerLaserMode.value ? "transform:scaleY(-1);" : ""),
                              onError: ($event) => brokenUrls.value = { ...brokenUrls.value, [exam.id]: true }
                            }, null, 44, ["src", "onError"])) : (openBlock(), createBlock("div", {
                              key: 1,
                              class: "d-flex align-items-center justify-content-center",
                              style: { "width": "100%", "aspect-ratio": "4/3", "background": "#1a1a1a" }
                            }, [
                              createVNode("i", {
                                class: "ti ti-photo-off",
                                style: { "color": "#444", "font-size": "2rem" }
                              })
                            ]))
                          ], 44, ["onMousemove", "onMouseleave", "onMouseenter", "onWheel"])
                        ]);
                      }), 128))
                    ], 4)
                  ], 512), [
                    [vShow, viewerAllMode.value]
                  ]),
                  withDirectives(createVNode("div", { style: viewerLensStyle.value }, null, 4), [
                    [vShow, viewerLensActive.value && viewerLensVisible.value]
                  ])
                ], 512), [
                  [vShow, showViewerModal.value]
                ])
              ])),
              (openBlock(), createBlock(Teleport, { to: "body" }, [
                withDirectives(createVNode("div", { style: { "position": "fixed", "inset": "0", "z-index": "9999", "display": "flex", "flex-direction": "column" } }, [
                  createVNode("div", {
                    class: "d-flex align-items-center gap-1 px-3 py-2 flex-shrink-0",
                    style: { "background": "#2c2c2c", "color": "#fff" }
                  }, [
                    createVNode("div", {
                      class: "btn-group btn-group-sm me-2",
                      role: "group"
                    }, [
                      (openBlock(), createBlock(Fragment, null, renderList([1, 2, 4, 6, 9, 12, 16], (n) => {
                        return createVNode("button", {
                          key: n,
                          type: "button",
                          class: ["btn btn-sm", printCols.value === n ? "btn-light" : "btn-outline-secondary"],
                          style: { "font-size": ".72rem", "min-width": "28px" },
                          onClick: ($event) => printCols.value = n
                        }, toDisplayString(n), 11, ["onClick"]);
                      }), 64))
                    ]),
                    createVNode("div", { class: "vr opacity-25 mx-1" }),
                    createVNode("button", {
                      type: "button",
                      class: ["btn btn-sm", printOrientation.value === "portrait" ? "btn-light" : "btn-outline-secondary"],
                      style: { "font-size": ".72rem" },
                      onClick: ($event) => printOrientation.value = "portrait"
                    }, [
                      createVNode("i", { class: "fa fa-file me-1" }),
                      createTextVNode("Retrato ")
                    ], 10, ["onClick"]),
                    createVNode("button", {
                      type: "button",
                      class: ["btn btn-sm", printOrientation.value === "landscape" ? "btn-light" : "btn-outline-secondary"],
                      style: { "font-size": ".72rem" },
                      onClick: ($event) => printOrientation.value = "landscape"
                    }, [
                      createVNode("i", {
                        class: "fa fa-file me-1",
                        style: { "transform": "rotate(90deg)", "display": "inline-block" }
                      }),
                      createTextVNode("Paisagem ")
                    ], 10, ["onClick"]),
                    createVNode("div", { class: "vr opacity-25 mx-1" }),
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-sm btn-warning text-dark fw-semibold",
                      style: { "font-size": ".72rem" },
                      onClick: printReport
                    }, [
                      createVNode("i", { class: "fa fa-print me-1" }),
                      createTextVNode("Imprimir ")
                    ]),
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-sm btn-outline-secondary ms-auto",
                      style: { "font-size": ".72rem" },
                      onClick: ($event) => showPrintModal.value = false
                    }, [
                      createVNode("i", { class: "fa fa-times me-1" }),
                      createTextVNode("Fechar ")
                    ], 8, ["onClick"])
                  ]),
                  createVNode("div", {
                    class: "flex-grow-1 overflow-auto",
                    style: { "background": "#888" }
                  }, [
                    createVNode("div", {
                      id: "ei-print-content",
                      class: [printOrientation.value === "landscape" ? "ei-landscape" : "ei-portrait", "mx-auto my-3 bg-white shadow"],
                      style: { "width": "210mm", "min-height": "297mm", "padding": "12mm", "box-sizing": "border-box" }
                    }, [
                      createVNode("div", {
                        class: "d-flex justify-content-between align-items-start mb-3 pb-2",
                        style: { "border-bottom": "2px solid #1a6fc4" }
                      }, [
                        createVNode("div", null, [
                          createVNode("div", { style: { "font-size": "1.1rem", "font-weight": "700", "color": "#1a6fc4" } }, toDisplayString(printEntity.value.name), 1),
                          printEntity.value.address ? (openBlock(), createBlock("div", {
                            key: 0,
                            style: { "font-size": ".72rem", "color": "#555" }
                          }, toDisplayString(printEntity.value.address), 1)) : createCommentVNode("", true),
                          printEntity.value.email ? (openBlock(), createBlock("div", {
                            key: 1,
                            style: { "font-size": ".72rem", "color": "#555" }
                          }, toDisplayString(printEntity.value.email), 1)) : createCommentVNode("", true),
                          printEntity.value.telephone || printEntity.value.cellphone ? (openBlock(), createBlock("div", {
                            key: 2,
                            style: { "font-size": ".72rem", "color": "#555" }
                          }, toDisplayString([printEntity.value.telephone, printEntity.value.cellphone].filter(Boolean).join(" | ")), 1)) : createCommentVNode("", true)
                        ]),
                        createVNode("div", { class: "text-end" }, [
                          createVNode("div", { style: { "font-size": ".72rem", "color": "#555" } }, "Data do relatório"),
                          createVNode("div", { style: { "font-size": ".85rem", "font-weight": "600" } }, toDisplayString((/* @__PURE__ */ new Date()).toLocaleDateString("pt-BR")), 1)
                        ])
                      ]),
                      selectedPatient.value ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "mb-3 p-2 rounded",
                        style: { "background": "#f0f4ff", "font-size": ".78rem" }
                      }, [
                        createVNode("strong", null, toDisplayString(((_f = selectedPatient.value.person) == null ? void 0 : _f.full_name) ?? selectedPatient.value.full_name), 1),
                        createVNode("span", { class: "ms-2 text-muted" }, toDisplayString(selectedPatient.value.code), 1)
                      ])) : createCommentVNode("", true),
                      createVNode("div", {
                        style: `display:grid;grid-template-columns:repeat(${printCols.value},1fr);gap:8px;`
                      }, [
                        (openBlock(true), createBlock(Fragment, null, renderList(printExams.value, (exam) => {
                          var _a2;
                          return openBlock(), createBlock("div", {
                            key: exam.id,
                            style: { "break-inside": "avoid" }
                          }, [
                            createVNode("div", {
                              class: "text-center mb-1",
                              style: { "font-size": ".65rem", "color": "#333", "font-weight": "600" }
                            }, toDisplayString(((_a2 = exam.exam_type) == null ? void 0 : _a2.name) ?? "Exame") + " - " + toDisplayString(latLabel(exam.laterality)) + " - " + toDisplayString(formatDateTime(exam.created_at)), 1),
                            examUrls.value[exam.id] && !brokenUrls.value[exam.id] ? (openBlock(), createBlock("img", {
                              key: 0,
                              src: examUrls.value[exam.id],
                              style: { "width": "100%", "height": "auto", "display": "block", "border": "1px solid #ddd" },
                              onError: ($event) => brokenUrls.value = { ...brokenUrls.value, [exam.id]: true }
                            }, null, 40, ["src", "onError"])) : (openBlock(), createBlock("div", {
                              key: 1,
                              class: "d-flex align-items-center justify-content-center",
                              style: { "width": "100%", "aspect-ratio": "4/3", "background": "#eee", "border": "1px solid #ddd" }
                            }, [
                              createVNode("i", {
                                class: "ti ti-photo-off",
                                style: { "font-size": "2rem", "color": "#aaa" }
                              })
                            ]))
                          ]);
                        }), 128))
                      ], 4)
                    ], 2)
                  ])
                ], 512), [
                  [vShow, showPrintModal.value]
                ])
              ])),
              aiModalOpen.value ? (openBlock(), createBlock("div", {
                key: 0,
                class: "modal d-block",
                tabindex: "-1",
                style: { "background": "rgba(0,0,0,.55)" },
                onClick: withModifiers(closeAiModal, ["self"])
              }, [
                createVNode("div", { class: "modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" }, [
                  createVNode("div", { class: "modal-content" }, [
                    createVNode("div", { class: "modal-header" }, [
                      createVNode("h5", { class: "modal-title" }, [
                        createVNode("i", { class: "ti ti-robot me-1 text-info" }),
                        createTextVNode(" " + toDisplayString(aiLabel("title", "Assistente de IA")), 1)
                      ]),
                      createVNode("button", {
                        type: "button",
                        class: "btn-close",
                        onClick: closeAiModal
                      })
                    ]),
                    createVNode("div", { class: "modal-body" }, [
                      createVNode("div", { class: "d-flex flex-wrap gap-3 mb-3 small text-muted" }, [
                        createVNode("span", null, [
                          createVNode("strong", null, toDisplayString(aiBalance.available), 1),
                          createTextVNode(" " + toDisplayString(aiLabel("credits_available", "Créditos disponíveis")), 1)
                        ]),
                        createVNode("span", { class: "text-warning" }, [
                          createVNode("strong", null, toDisplayString(aiBalance.reserved), 1),
                          createTextVNode(" " + toDisplayString(aiLabel("credits_reserved", "Reservados")), 1)
                        ])
                      ]),
                      createVNode("div", { class: "alert alert-info py-2 small" }, [
                        createVNode("i", { class: "ti ti-info-circle me-1" }),
                        createTextVNode(toDisplayString(aiLabel("support_notice", "A IA é apoio clínico. A decisão final é sempre do médico responsável.")), 1)
                      ]),
                      aiAlert.message ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: `alert alert-${aiAlert.type}`,
                        role: "alert"
                      }, toDisplayString(aiAlert.message), 3)) : createCommentVNode("", true),
                      createVNode("div", { class: "row g-2 mb-3" }, [
                        createVNode("div", { class: "col-md-6" }, [
                          createVNode("label", { class: "form-label small" }, toDisplayString(aiLabel("workflow", "Workflow")), 1),
                          withDirectives(createVNode("select", {
                            "onUpdate:modelValue": ($event) => aiForm.workflow = $event,
                            class: "form-select form-select-sm"
                          }, [
                            (openBlock(true), createBlock(Fragment, null, renderList(aiWorkflows.value, (workflow) => {
                              return openBlock(), createBlock("option", {
                                key: workflow,
                                value: workflow
                              }, toDisplayString(aiWorkflowLabel(workflow)), 9, ["value"]);
                            }), 128))
                          ], 8, ["onUpdate:modelValue"]), [
                            [vModelSelect, aiForm.workflow]
                          ])
                        ]),
                        createVNode("div", { class: "col-md-6" }, [
                          createVNode("label", { class: "form-label small" }, toDisplayString(aiLabel("risk", "Risco")), 1),
                          withDirectives(createVNode("select", {
                            "onUpdate:modelValue": ($event) => aiForm.risk_level = $event,
                            class: "form-select form-select-sm"
                          }, [
                            createVNode("option", { value: "low" }, toDisplayString(aiLabel("risk_low", "Baixo")), 1),
                            createVNode("option", { value: "medium" }, toDisplayString(aiLabel("risk_medium", "Médio")), 1),
                            createVNode("option", { value: "high" }, toDisplayString(aiLabel("risk_high", "Alto")), 1)
                          ], 8, ["onUpdate:modelValue"]), [
                            [vModelSelect, aiForm.risk_level]
                          ])
                        ])
                      ]),
                      aiShowPatientSelector.value ? (openBlock(), createBlock("div", {
                        key: 1,
                        class: "mb-3"
                      }, [
                        createVNode("label", { class: "form-label small" }, toDisplayString(aiLabel("patient_optional", "Patient (optional)")), 1),
                        withDirectives(createVNode("select", {
                          "onUpdate:modelValue": ($event) => aiForm.patient_id = $event,
                          class: "form-select form-select-sm"
                        }, [
                          createVNode("option", { value: "" }, toDisplayString(aiLabel("select_placeholder", "Select")), 1),
                          (openBlock(true), createBlock(Fragment, null, renderList(aiPatients.value, (p) => {
                            return openBlock(), createBlock("option", {
                              key: p.id,
                              value: p.id
                            }, toDisplayString(p.name) + " (" + toDisplayString(p.code) + ")", 9, ["value"]);
                          }), 128))
                        ], 8, ["onUpdate:modelValue"]), [
                          [vModelSelect, aiForm.patient_id]
                        ])
                      ])) : (openBlock(), createBlock("div", {
                        key: 2,
                        class: "mb-3"
                      }, [
                        createVNode("label", { class: "form-label small" }, toDisplayString(aiLabel("patient_optional", "Patient (optional)")), 1),
                        createVNode("div", { class: "form-control form-control-sm bg-light d-flex align-items-center justify-content-between" }, [
                          createVNode("span", { class: "text-truncate" }, [
                            createTextVNode(toDisplayString((_g = aiSelectedPatient.value) == null ? void 0 : _g.name), 1),
                            ((_h = aiSelectedPatient.value) == null ? void 0 : _h.code) ? (openBlock(), createBlock("span", { key: 0 }, " (" + toDisplayString((_i = aiSelectedPatient.value) == null ? void 0 : _i.code) + ")", 1)) : createCommentVNode("", true)
                          ]),
                          createVNode("span", { class: "badge bg-success-subtle text-success ms-2" }, "Auto")
                        ])
                      ])),
                      createVNode("div", { class: "mb-3" }, [
                        createVNode("label", { class: "form-label fw-semibold" }, toDisplayString(aiLabel("clinical_prompt", "Prompt clínico")), 1),
                        withDirectives(createVNode("textarea", {
                          "onUpdate:modelValue": ($event) => aiForm.user_prompt = $event,
                          class: "form-control",
                          rows: "5",
                          minlength: "12",
                          maxlength: "30000",
                          placeholder: aiLabel("clinical_prompt_placeholder", "Descreva o contexto e objetivo clínico.")
                        }, null, 8, ["onUpdate:modelValue", "placeholder"]), [
                          [vModelText, aiForm.user_prompt]
                        ])
                      ]),
                      createVNode("div", { class: "mb-3" }, [
                        createVNode("label", { class: "form-label small" }, toDisplayString(aiLabel("system_prompt", "System prompt")), 1),
                        withDirectives(createVNode("textarea", {
                          "onUpdate:modelValue": ($event) => aiForm.system_prompt = $event,
                          class: "form-control form-control-sm",
                          rows: "2"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelText, aiForm.system_prompt]
                        ])
                      ]),
                      aiEstimate.value ? (openBlock(), createBlock("div", {
                        key: 3,
                        class: "border rounded p-2 bg-light small"
                      }, [
                        createVNode("div", { class: "d-flex justify-content-between align-items-center" }, [
                          createVNode("span", null, [
                            createVNode("strong", null, toDisplayString(aiLabel("estimated_credits", "Créditos estimados")) + ":", 1),
                            createTextVNode(" " + toDisplayString(aiEstimate.value.normalized_credits ?? "—"), 1)
                          ]),
                          createVNode("span", { class: "text-muted" }, toDisplayString(aiWorkflowLabel(aiEstimate.value.workflow)), 1)
                        ])
                      ])) : createCommentVNode("", true)
                    ]),
                    createVNode("div", { class: "modal-footer" }, [
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-outline-secondary btn-sm",
                        onClick: closeAiModal
                      }, toDisplayString(aiLabel("close", "Close")), 1),
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-outline-info btn-sm",
                        disabled: aiEstimating.value,
                        onClick: estimateAiRun
                      }, [
                        aiEstimating.value ? (openBlock(), createBlock("span", {
                          key: 0,
                          class: "spinner-border spinner-border-sm me-1"
                        })) : (openBlock(), createBlock("i", {
                          key: 1,
                          class: "ti ti-calculator me-1"
                        })),
                        createTextVNode(toDisplayString(aiLabel("estimate", "Estimar Custo")), 1)
                      ], 8, ["disabled"]),
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-success btn-sm",
                        disabled: aiSubmitting.value || !aiEstimate.value,
                        onClick: submitAiRun
                      }, [
                        aiSubmitting.value ? (openBlock(), createBlock("span", {
                          key: 0,
                          class: "spinner-border spinner-border-sm me-1"
                        })) : (openBlock(), createBlock("i", {
                          key: 1,
                          class: "ti ti-player-play me-1"
                        })),
                        createTextVNode(toDisplayString(aiLabel("run", "Executar IA")), 1)
                      ], 8, ["disabled"])
                    ])
                  ])
                ])
              ])) : createCommentVNode("", true)
            ];
          }
        }),
        _: 1
      }, _parent));
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/EyeImages/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const Index = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-45ceec4f"]]);
export {
  Index as default
};
