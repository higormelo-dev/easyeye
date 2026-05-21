import { reactive, ref, computed, mergeProps, withCtx, createVNode, openBlock, createBlock, createTextVNode, toDisplayString, createCommentVNode, withDirectives, Fragment, renderList, vModelSelect, vModelText, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderAttr, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderClass, ssrRenderStyle } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    balance: { type: Object, required: true },
    runs: { type: Object, required: true },
    patients: { type: Array, default: () => [] },
    medicalRecords: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    modes: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    risks: { type: Array, default: () => [] },
    workflows: { type: Array, default: () => [] },
    defaultMode: { type: String, default: "validated" },
    canConsensus: { type: Boolean, default: false },
    labels: { type: Object, default: () => ({}) },
    prefill: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    var _a, _b;
    const props = __props;
    const label = (key, fallback = "") => {
      var _a2;
      return ((_a2 = props.labels) == null ? void 0 : _a2[key]) ?? fallback;
    };
    const breadcrumbs = [
      { label: label("dashboard", "Dashboard"), url: route("panel.dashboard"), active: false },
      { label: label("title", "AI"), url: "#", active: true }
    ];
    const form = reactive({
      workflow: props.workflows[1] ?? "report_drafting",
      mode: props.defaultMode ?? "validated",
      risk_level: "medium",
      // Pré-preenche via ?medical_record_id=... quando o usuário chega pelo widget
      // do prontuário. patient_id também vem para garantir consistência.
      patient_id: ((_a = props.prefill) == null ? void 0 : _a.patient_id) ?? "",
      medical_record_id: ((_b = props.prefill) == null ? void 0 : _b.medical_record_id) ?? "",
      user_prompt: "",
      system_prompt: "Você é um assistente de apoio clínico. Nunca emita decisão final.",
      expects_json: false,
      max_output_tokens: 700
    });
    const estimating = ref(false);
    const submitting = ref(false);
    const estimate = ref(null);
    const detailLoading = ref(false);
    const selectedRun = ref(null);
    const draftOutput = ref("");
    const rejectReason = ref("");
    const statusFilter = ref(props.filters.status ?? "");
    const errorMessage = ref("");
    const successMessage = ref("");
    let messageTimer = null;
    function showError(message) {
      errorMessage.value = message;
      successMessage.value = "";
      if (messageTimer) clearTimeout(messageTimer);
      messageTimer = setTimeout(() => errorMessage.value = "", 6e3);
    }
    function showSuccess(message) {
      successMessage.value = message;
      errorMessage.value = "";
      if (messageTimer) clearTimeout(messageTimer);
      messageTimer = setTimeout(() => successMessage.value = "", 4e3);
    }
    function dismissMessage() {
      errorMessage.value = "";
      successMessage.value = "";
      if (messageTimer) clearTimeout(messageTimer);
    }
    const canApproveOrReject = computed(() => {
      var _a2;
      return ((_a2 = selectedRun.value) == null ? void 0 : _a2.status) === "waiting_approval";
    });
    const availableStatuses = computed(() => props.statuses.length ? props.statuses : ["pending", "reserved", "running", "waiting_approval", "approved", "rejected", "failed", "cancelled"]);
    const paginationLinks = computed(() => {
      var _a2;
      return Array.isArray((_a2 = props.runs) == null ? void 0 : _a2.links) ? props.runs.links : [];
    });
    const workflowLabel = (workflow) => {
      const key = `workflow_${workflow}`;
      return label(key, workflow);
    };
    const modeLabel = (mode) => {
      const key = `mode_${mode}`;
      return label(key, mode);
    };
    const riskLabel = (risk) => {
      const key = `risk_${risk}`;
      return label(key, risk);
    };
    const statusLabel = (status) => {
      const key = `status_${status}`;
      return label(key, status);
    };
    const statusClass = (status) => {
      return {
        pending: "badge bg-secondary-subtle text-secondary",
        reserved: "badge bg-info-subtle text-info",
        running: "badge bg-primary-subtle text-primary",
        waiting_approval: "badge bg-warning-subtle text-warning",
        approved: "badge bg-success-subtle text-success",
        rejected: "badge bg-danger-subtle text-danger",
        failed: "badge bg-danger-subtle text-danger",
        cancelled: "badge bg-dark-subtle text-dark"
      }[status] ?? "badge bg-light text-dark";
    };
    function filterByStatus() {
      router.get(
        route("panel.ai-runs.index"),
        { status: statusFilter.value || void 0 },
        { preserveState: true, preserveScroll: true, replace: true }
      );
    }
    function goToPage(url) {
      if (!url) return;
      router.visit(url, {
        preserveState: true,
        preserveScroll: true,
        replace: true
      });
    }
    async function estimateRun() {
      var _a2, _b2;
      estimating.value = true;
      try {
        const { data } = await window.axios.post(route("panel.ai-runs.estimate"), form);
        estimate.value = data.estimate;
      } catch (error) {
        estimate.value = null;
        showError(((_b2 = (_a2 = error == null ? void 0 : error.response) == null ? void 0 : _a2.data) == null ? void 0 : _b2.message) ?? label("error_estimate", "Falha ao estimar custo."));
      } finally {
        estimating.value = false;
      }
    }
    async function submitRun() {
      var _a2, _b2;
      submitting.value = true;
      try {
        await window.axios.post(route("panel.ai-runs.store"), form);
        router.reload({ only: ["runs", "balance"] });
        form.user_prompt = "";
        estimate.value = null;
        showSuccess(label("run_created", "Execução criada com sucesso."));
      } catch (error) {
        showError(((_b2 = (_a2 = error == null ? void 0 : error.response) == null ? void 0 : _a2.data) == null ? void 0 : _b2.message) ?? label("error_submit", "Falha ao criar execução."));
      } finally {
        submitting.value = false;
      }
    }
    async function loadRunDetail(runId) {
      var _a2, _b2;
      detailLoading.value = true;
      try {
        const { data } = await window.axios.get(route("panel.ai-runs.show", runId));
        selectedRun.value = data.data;
        draftOutput.value = data.data.final_output ?? "";
        rejectReason.value = "";
      } catch (error) {
        showError(((_b2 = (_a2 = error == null ? void 0 : error.response) == null ? void 0 : _a2.data) == null ? void 0 : _b2.message) ?? label("error_load_detail", "Falha ao carregar detalhes."));
      } finally {
        detailLoading.value = false;
      }
    }
    async function approveRun() {
      var _a2, _b2, _c;
      if (!((_a2 = selectedRun.value) == null ? void 0 : _a2.id)) return;
      try {
        await window.axios.post(route("panel.ai-runs.approve", selectedRun.value.id), {
          final_output: draftOutput.value
        });
        await loadRunDetail(selectedRun.value.id);
        router.reload({ only: ["runs"] });
        showSuccess(label("run_approved", "Execução aprovada."));
      } catch (error) {
        showError(((_c = (_b2 = error == null ? void 0 : error.response) == null ? void 0 : _b2.data) == null ? void 0 : _c.message) ?? label("error_approve", "Falha ao aprovar execução."));
      }
    }
    async function rejectRun() {
      var _a2, _b2, _c;
      if (!((_a2 = selectedRun.value) == null ? void 0 : _a2.id)) return;
      try {
        await window.axios.post(route("panel.ai-runs.reject", selectedRun.value.id), {
          reason: rejectReason.value
        });
        await loadRunDetail(selectedRun.value.id);
        router.reload({ only: ["runs"] });
        showSuccess(label("run_rejected", "Execução rejeitada."));
      } catch (error) {
        showError(((_c = (_b2 = error == null ? void 0 : error.response) == null ? void 0 : _b2.data) == null ? void 0 : _c.message) ?? label("error_reject", "Falha ao rejeitar execução."));
      }
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: label("title", "AI"),
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: label("title", "AI"),
              total: __props.runs.total,
              view: "table"
            }, null, _parent2, _scopeId));
            if (errorMessage.value) {
              _push2(`<div class="alert alert-danger alert-dismissible fade show" role="alert"${_scopeId}><i class="ti ti-alert-circle me-1"${_scopeId}></i>${ssrInterpolate(errorMessage.value)} <button type="button" class="btn-close" aria-label="Close"${_scopeId}></button></div>`);
            } else {
              _push2(`<!---->`);
            }
            if (successMessage.value) {
              _push2(`<div class="alert alert-success alert-dismissible fade show" role="alert"${_scopeId}><i class="ti ti-check me-1"${_scopeId}></i>${ssrInterpolate(successMessage.value)} <button type="button" class="btn-close" aria-label="Close"${_scopeId}></button></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="border rounded p-3 bg-white mb-3"${_scopeId}><div class="d-flex flex-wrap gap-3 align-items-center"${_scopeId}><div${_scopeId}><strong${_scopeId}>${ssrInterpolate(label("credits_available", "Créditos disponíveis"))}:</strong> ${ssrInterpolate(__props.balance.available)}</div><div${_scopeId}><strong${_scopeId}>${ssrInterpolate(label("credits_reserved", "Reservados"))}:</strong> ${ssrInterpolate(__props.balance.reserved)}</div><div${_scopeId}><strong${_scopeId}>${ssrInterpolate(label("credits_total", "Total"))}:</strong> ${ssrInterpolate(__props.balance.total)}</div></div><div class="mt-2 text-muted fs-13"${_scopeId}>${ssrInterpolate(label("support_notice"))}</div></div><div class="border rounded p-3 bg-white mb-3"${_scopeId}><h6 class="fw-semibold mb-3"${_scopeId}>${ssrInterpolate(label("subtitle"))}</h6><div class="row g-3"${_scopeId}><div class="col-md-3"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(label("workflow", "Workflow"))}</label><select class="form-select"${_scopeId}><!--[-->`);
            ssrRenderList(__props.workflows, (workflow) => {
              _push2(`<option${ssrRenderAttr("value", workflow)}${ssrIncludeBooleanAttr(Array.isArray(form.workflow) ? ssrLooseContain(form.workflow, workflow) : ssrLooseEqual(form.workflow, workflow)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(workflowLabel(workflow))}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-md-3"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(label("mode", "Modo"))}</label><select class="form-select"${_scopeId}><!--[-->`);
            ssrRenderList(__props.modes, (mode) => {
              _push2(`<option${ssrRenderAttr("value", mode)}${ssrIncludeBooleanAttr(Array.isArray(form.mode) ? ssrLooseContain(form.mode, mode) : ssrLooseEqual(form.mode, mode)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(modeLabel(mode))}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-md-3"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(label("risk", "Risco"))}</label><select class="form-select"${_scopeId}><!--[-->`);
            ssrRenderList(__props.risks, (risk) => {
              _push2(`<option${ssrRenderAttr("value", risk)}${ssrIncludeBooleanAttr(Array.isArray(form.risk_level) ? ssrLooseContain(form.risk_level, risk) : ssrLooseEqual(form.risk_level, risk)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(riskLabel(risk))}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-md-3"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(label("max_output_tokens", "Max output tokens"))}</label><input${ssrRenderAttr("value", form.max_output_tokens)} type="number" class="form-control" min="64" max="8192"${_scopeId}></div><div class="col-md-6"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(label("patient_optional", "Paciente (opcional)"))}</label><select class="form-select"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.patient_id) ? ssrLooseContain(form.patient_id, "") : ssrLooseEqual(form.patient_id, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(label("select_placeholder", "Selecionar"))}</option><!--[-->`);
            ssrRenderList(__props.patients, (patient) => {
              _push2(`<option${ssrRenderAttr("value", patient.id)}${ssrIncludeBooleanAttr(Array.isArray(form.patient_id) ? ssrLooseContain(form.patient_id, patient.id) : ssrLooseEqual(form.patient_id, patient.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(patient.name)} (${ssrInterpolate(patient.code)}) </option>`);
            });
            _push2(`<!--]--></select></div><div class="col-md-6"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(label("medical_record_optional", "Prontuário (opcional)"))}</label><select class="form-select"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.medical_record_id) ? ssrLooseContain(form.medical_record_id, "") : ssrLooseEqual(form.medical_record_id, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(label("select_placeholder", "Selecionar"))}</option><!--[-->`);
            ssrRenderList(__props.medicalRecords, (record) => {
              _push2(`<option${ssrRenderAttr("value", record.id)}${ssrIncludeBooleanAttr(Array.isArray(form.medical_record_id) ? ssrLooseContain(form.medical_record_id, record.id) : ssrLooseEqual(form.medical_record_id, record.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(record.code)} - ${ssrInterpolate(record.patient_name)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-12"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(label("system_prompt", "System prompt"))}</label><textarea rows="2" class="form-control"${_scopeId}>${ssrInterpolate(form.system_prompt)}</textarea></div><div class="col-12"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(label("clinical_prompt", "Prompt clínico"))}</label><textarea rows="4" class="form-control"${ssrRenderAttr("placeholder", label("clinical_prompt_placeholder"))}${_scopeId}>${ssrInterpolate(form.user_prompt)}</textarea></div></div><div class="d-flex flex-wrap gap-2 mt-3"${_scopeId}><button class="btn btn-outline-primary"${ssrIncludeBooleanAttr(estimating.value || !form.user_prompt) ? " disabled" : ""}${_scopeId}><i class="ti ti-calculator me-1"${_scopeId}></i>${ssrInterpolate(label("estimate"))}</button><button class="btn btn-primary"${ssrIncludeBooleanAttr(submitting.value || !form.user_prompt) ? " disabled" : ""}${_scopeId}><i class="ti ti-player-play me-1"${_scopeId}></i>${ssrInterpolate(label("run"))}</button></div>`);
            if (estimate.value) {
              _push2(`<div class="mt-3 border rounded p-2 bg-light"${_scopeId}><div${_scopeId}><strong${_scopeId}>${ssrInterpolate(label("estimated_credits", "Créditos estimados"))}:</strong> ${ssrInterpolate(estimate.value.normalized_credits)}</div><div class="text-muted fs-13"${_scopeId}>${ssrInterpolate(label("raw_cost_usd", "Custo bruto USD"))}: ${ssrInterpolate(estimate.value.raw_cost_usd)}</div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="row g-3"${_scopeId}><div class="col-lg-7"${_scopeId}><div class="border rounded p-3 bg-white"${_scopeId}><div class="d-flex justify-content-between align-items-center mb-2"${_scopeId}><h6 class="fw-semibold mb-0"${_scopeId}>${ssrInterpolate(label("runs", "Execuções"))}</h6><select class="form-select form-select-sm w-auto"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(statusFilter.value) ? ssrLooseContain(statusFilter.value, "") : ssrLooseEqual(statusFilter.value, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(label("all_statuses", "Todos status"))}</option><!--[-->`);
            ssrRenderList(availableStatuses.value, (status) => {
              _push2(`<option${ssrRenderAttr("value", status)}${ssrIncludeBooleanAttr(Array.isArray(statusFilter.value) ? ssrLooseContain(statusFilter.value, status) : ssrLooseEqual(statusFilter.value, status)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(statusLabel(status))}</option>`);
            });
            _push2(`<!--]--></select></div><div class="table-responsive"${_scopeId}><table class="table table-sm align-middle"${_scopeId}><thead${_scopeId}><tr${_scopeId}><th${_scopeId}>${ssrInterpolate(label("date", "Data"))}</th><th${_scopeId}>${ssrInterpolate(label("workflow", "Workflow"))}</th><th${_scopeId}>${ssrInterpolate(label("mode", "Modo"))}</th><th${_scopeId}>${ssrInterpolate(label("status", "Status"))}</th><th${_scopeId}>${ssrInterpolate(label("credits", "Créditos"))}</th><th${_scopeId}></th></tr></thead><tbody${_scopeId}><!--[-->`);
            ssrRenderList(__props.runs.data, (run) => {
              _push2(`<tr${_scopeId}><td${_scopeId}>${ssrInterpolate(run.created_at)}</td><td${_scopeId}>${ssrInterpolate(workflowLabel(run.workflow))}</td><td${_scopeId}>${ssrInterpolate(modeLabel(run.mode))}</td><td${_scopeId}><span class="${ssrRenderClass(statusClass(run.status))}"${_scopeId}>${ssrInterpolate(statusLabel(run.status))}</span></td><td${_scopeId}>${ssrInterpolate(run.consumed_credits)}/${ssrInterpolate(run.reserved_credits)}</td><td class="text-end"${_scopeId}><button class="btn btn-sm btn-outline-secondary"${_scopeId}><i class="ti ti-eye"${_scopeId}></i></button></td></tr>`);
            });
            _push2(`<!--]-->`);
            if (__props.runs.data.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="6" class="text-center text-muted py-3"${_scopeId}>${ssrInterpolate(label("empty_runs", "Nenhuma execução encontrada."))}</td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</tbody></table></div>`);
            if (paginationLinks.value.length > 3) {
              _push2(`<nav class="mt-2" aria-label="Paginação de execuções"${_scopeId}><ul class="pagination pagination-sm mb-0"${_scopeId}><!--[-->`);
              ssrRenderList(paginationLinks.value, (page, index) => {
                _push2(`<li class="${ssrRenderClass([{ active: page.active, disabled: !page.url }, "page-item"])}"${_scopeId}><button type="button" class="page-link"${ssrIncludeBooleanAttr(!page.url || page.active) ? " disabled" : ""}${_scopeId}>${page.label ?? ""}</button></li>`);
              });
              _push2(`<!--]--></ul></nav>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div><div class="col-lg-5"${_scopeId}><div class="border rounded p-3 bg-white" style="${ssrRenderStyle({ "min-height": "320px" })}"${_scopeId}><h6 class="fw-semibold mb-2"${_scopeId}>${ssrInterpolate(label("details", "Detalhes"))}</h6>`);
            if (detailLoading.value) {
              _push2(`<div class="text-muted"${_scopeId}>${ssrInterpolate(label("loading", "Carregando"))}...</div>`);
            } else if (!selectedRun.value) {
              _push2(`<div class="text-muted"${_scopeId}>${ssrInterpolate(label("select_run", "Selecione uma execução para visualizar."))}</div>`);
            } else {
              _push2(`<!--[--><div class="mb-2"${_scopeId}><strong${_scopeId}>${ssrInterpolate(label("status", "Status"))}:</strong> <span class="${ssrRenderClass(statusClass(selectedRun.value.status))}"${_scopeId}>${ssrInterpolate(statusLabel(selectedRun.value.status))}</span></div><div class="mb-2"${_scopeId}><strong${_scopeId}>${ssrInterpolate(label("patient", "Paciente"))}:</strong> ${ssrInterpolate(selectedRun.value.patient || "-")}</div><div class="mb-2"${_scopeId}><strong${_scopeId}>${ssrInterpolate(label("medical_record", "Prontuário"))}:</strong> ${ssrInterpolate(selectedRun.value.medical_record_code || "-")}</div><label class="form-label mt-2"${_scopeId}>${ssrInterpolate(label("editable_draft", "Rascunho editável"))}</label><textarea rows="8" class="form-control"${_scopeId}>${ssrInterpolate(draftOutput.value)}</textarea>`);
              if (canApproveOrReject.value) {
                _push2(`<div class="mt-3 d-flex gap-2"${_scopeId}><button class="btn btn-success btn-sm"${_scopeId}><i class="ti ti-check me-1"${_scopeId}></i>${ssrInterpolate(label("approve"))}</button><button class="btn btn-danger btn-sm"${_scopeId}><i class="ti ti-x me-1"${_scopeId}></i>${ssrInterpolate(label("reject"))}</button></div>`);
              } else {
                _push2(`<!---->`);
              }
              if (canApproveOrReject.value) {
                _push2(`<div class="mt-2"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(label("rejection_reason_optional", "Motivo da rejeição (opcional)"))}</label><input${ssrRenderAttr("value", rejectReason.value)} type="text" class="form-control"${_scopeId}></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<!--]-->`);
            }
            _push2(`</div></div></div></div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, {
                  title: label("title", "AI"),
                  total: __props.runs.total,
                  view: "table"
                }, null, 8, ["title", "total"]),
                errorMessage.value ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "alert alert-danger alert-dismissible fade show",
                  role: "alert"
                }, [
                  createVNode("i", { class: "ti ti-alert-circle me-1" }),
                  createTextVNode(toDisplayString(errorMessage.value) + " ", 1),
                  createVNode("button", {
                    type: "button",
                    class: "btn-close",
                    "aria-label": "Close",
                    onClick: dismissMessage
                  })
                ])) : createCommentVNode("", true),
                successMessage.value ? (openBlock(), createBlock("div", {
                  key: 1,
                  class: "alert alert-success alert-dismissible fade show",
                  role: "alert"
                }, [
                  createVNode("i", { class: "ti ti-check me-1" }),
                  createTextVNode(toDisplayString(successMessage.value) + " ", 1),
                  createVNode("button", {
                    type: "button",
                    class: "btn-close",
                    "aria-label": "Close",
                    onClick: dismissMessage
                  })
                ])) : createCommentVNode("", true),
                createVNode("div", { class: "border rounded p-3 bg-white mb-3" }, [
                  createVNode("div", { class: "d-flex flex-wrap gap-3 align-items-center" }, [
                    createVNode("div", null, [
                      createVNode("strong", null, toDisplayString(label("credits_available", "Créditos disponíveis")) + ":", 1),
                      createTextVNode(" " + toDisplayString(__props.balance.available), 1)
                    ]),
                    createVNode("div", null, [
                      createVNode("strong", null, toDisplayString(label("credits_reserved", "Reservados")) + ":", 1),
                      createTextVNode(" " + toDisplayString(__props.balance.reserved), 1)
                    ]),
                    createVNode("div", null, [
                      createVNode("strong", null, toDisplayString(label("credits_total", "Total")) + ":", 1),
                      createTextVNode(" " + toDisplayString(__props.balance.total), 1)
                    ])
                  ]),
                  createVNode("div", { class: "mt-2 text-muted fs-13" }, toDisplayString(label("support_notice")), 1)
                ]),
                createVNode("div", { class: "border rounded p-3 bg-white mb-3" }, [
                  createVNode("h6", { class: "fw-semibold mb-3" }, toDisplayString(label("subtitle")), 1),
                  createVNode("div", { class: "row g-3" }, [
                    createVNode("div", { class: "col-md-3" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(label("workflow", "Workflow")), 1),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => form.workflow = $event,
                        class: "form-select"
                      }, [
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.workflows, (workflow) => {
                          return openBlock(), createBlock("option", {
                            key: workflow,
                            value: workflow
                          }, toDisplayString(workflowLabel(workflow)), 9, ["value"]);
                        }), 128))
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, form.workflow]
                      ])
                    ]),
                    createVNode("div", { class: "col-md-3" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(label("mode", "Modo")), 1),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => form.mode = $event,
                        class: "form-select"
                      }, [
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.modes, (mode) => {
                          return openBlock(), createBlock("option", {
                            key: mode,
                            value: mode
                          }, toDisplayString(modeLabel(mode)), 9, ["value"]);
                        }), 128))
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, form.mode]
                      ])
                    ]),
                    createVNode("div", { class: "col-md-3" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(label("risk", "Risco")), 1),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => form.risk_level = $event,
                        class: "form-select"
                      }, [
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.risks, (risk) => {
                          return openBlock(), createBlock("option", {
                            key: risk,
                            value: risk
                          }, toDisplayString(riskLabel(risk)), 9, ["value"]);
                        }), 128))
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, form.risk_level]
                      ])
                    ]),
                    createVNode("div", { class: "col-md-3" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(label("max_output_tokens", "Max output tokens")), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => form.max_output_tokens = $event,
                        type: "number",
                        class: "form-control",
                        min: "64",
                        max: "8192"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [
                          vModelText,
                          form.max_output_tokens,
                          void 0,
                          { number: true }
                        ]
                      ])
                    ]),
                    createVNode("div", { class: "col-md-6" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(label("patient_optional", "Paciente (opcional)")), 1),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => form.patient_id = $event,
                        class: "form-select"
                      }, [
                        createVNode("option", { value: "" }, toDisplayString(label("select_placeholder", "Selecionar")), 1),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.patients, (patient) => {
                          return openBlock(), createBlock("option", {
                            key: patient.id,
                            value: patient.id
                          }, toDisplayString(patient.name) + " (" + toDisplayString(patient.code) + ") ", 9, ["value"]);
                        }), 128))
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, form.patient_id]
                      ])
                    ]),
                    createVNode("div", { class: "col-md-6" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(label("medical_record_optional", "Prontuário (opcional)")), 1),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => form.medical_record_id = $event,
                        class: "form-select"
                      }, [
                        createVNode("option", { value: "" }, toDisplayString(label("select_placeholder", "Selecionar")), 1),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.medicalRecords, (record) => {
                          return openBlock(), createBlock("option", {
                            key: record.id,
                            value: record.id
                          }, toDisplayString(record.code) + " - " + toDisplayString(record.patient_name), 9, ["value"]);
                        }), 128))
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, form.medical_record_id]
                      ])
                    ]),
                    createVNode("div", { class: "col-12" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(label("system_prompt", "System prompt")), 1),
                      withDirectives(createVNode("textarea", {
                        "onUpdate:modelValue": ($event) => form.system_prompt = $event,
                        rows: "2",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, form.system_prompt]
                      ])
                    ]),
                    createVNode("div", { class: "col-12" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(label("clinical_prompt", "Prompt clínico")), 1),
                      withDirectives(createVNode("textarea", {
                        "onUpdate:modelValue": ($event) => form.user_prompt = $event,
                        rows: "4",
                        class: "form-control",
                        placeholder: label("clinical_prompt_placeholder")
                      }, null, 8, ["onUpdate:modelValue", "placeholder"]), [
                        [vModelText, form.user_prompt]
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "d-flex flex-wrap gap-2 mt-3" }, [
                    createVNode("button", {
                      class: "btn btn-outline-primary",
                      disabled: estimating.value || !form.user_prompt,
                      onClick: estimateRun
                    }, [
                      createVNode("i", { class: "ti ti-calculator me-1" }),
                      createTextVNode(toDisplayString(label("estimate")), 1)
                    ], 8, ["disabled"]),
                    createVNode("button", {
                      class: "btn btn-primary",
                      disabled: submitting.value || !form.user_prompt,
                      onClick: submitRun
                    }, [
                      createVNode("i", { class: "ti ti-player-play me-1" }),
                      createTextVNode(toDisplayString(label("run")), 1)
                    ], 8, ["disabled"])
                  ]),
                  estimate.value ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "mt-3 border rounded p-2 bg-light"
                  }, [
                    createVNode("div", null, [
                      createVNode("strong", null, toDisplayString(label("estimated_credits", "Créditos estimados")) + ":", 1),
                      createTextVNode(" " + toDisplayString(estimate.value.normalized_credits), 1)
                    ]),
                    createVNode("div", { class: "text-muted fs-13" }, toDisplayString(label("raw_cost_usd", "Custo bruto USD")) + ": " + toDisplayString(estimate.value.raw_cost_usd), 1)
                  ])) : createCommentVNode("", true)
                ]),
                createVNode("div", { class: "row g-3" }, [
                  createVNode("div", { class: "col-lg-7" }, [
                    createVNode("div", { class: "border rounded p-3 bg-white" }, [
                      createVNode("div", { class: "d-flex justify-content-between align-items-center mb-2" }, [
                        createVNode("h6", { class: "fw-semibold mb-0" }, toDisplayString(label("runs", "Execuções")), 1),
                        withDirectives(createVNode("select", {
                          "onUpdate:modelValue": ($event) => statusFilter.value = $event,
                          class: "form-select form-select-sm w-auto",
                          onChange: filterByStatus
                        }, [
                          createVNode("option", { value: "" }, toDisplayString(label("all_statuses", "Todos status")), 1),
                          (openBlock(true), createBlock(Fragment, null, renderList(availableStatuses.value, (status) => {
                            return openBlock(), createBlock("option", {
                              key: status,
                              value: status
                            }, toDisplayString(statusLabel(status)), 9, ["value"]);
                          }), 128))
                        ], 40, ["onUpdate:modelValue"]), [
                          [vModelSelect, statusFilter.value]
                        ])
                      ]),
                      createVNode("div", { class: "table-responsive" }, [
                        createVNode("table", { class: "table table-sm align-middle" }, [
                          createVNode("thead", null, [
                            createVNode("tr", null, [
                              createVNode("th", null, toDisplayString(label("date", "Data")), 1),
                              createVNode("th", null, toDisplayString(label("workflow", "Workflow")), 1),
                              createVNode("th", null, toDisplayString(label("mode", "Modo")), 1),
                              createVNode("th", null, toDisplayString(label("status", "Status")), 1),
                              createVNode("th", null, toDisplayString(label("credits", "Créditos")), 1),
                              createVNode("th")
                            ])
                          ]),
                          createVNode("tbody", null, [
                            (openBlock(true), createBlock(Fragment, null, renderList(__props.runs.data, (run) => {
                              return openBlock(), createBlock("tr", {
                                key: run.id
                              }, [
                                createVNode("td", null, toDisplayString(run.created_at), 1),
                                createVNode("td", null, toDisplayString(workflowLabel(run.workflow)), 1),
                                createVNode("td", null, toDisplayString(modeLabel(run.mode)), 1),
                                createVNode("td", null, [
                                  createVNode("span", {
                                    class: statusClass(run.status)
                                  }, toDisplayString(statusLabel(run.status)), 3)
                                ]),
                                createVNode("td", null, toDisplayString(run.consumed_credits) + "/" + toDisplayString(run.reserved_credits), 1),
                                createVNode("td", { class: "text-end" }, [
                                  createVNode("button", {
                                    class: "btn btn-sm btn-outline-secondary",
                                    onClick: ($event) => loadRunDetail(run.id)
                                  }, [
                                    createVNode("i", { class: "ti ti-eye" })
                                  ], 8, ["onClick"])
                                ])
                              ]);
                            }), 128)),
                            __props.runs.data.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                              createVNode("td", {
                                colspan: "6",
                                class: "text-center text-muted py-3"
                              }, toDisplayString(label("empty_runs", "Nenhuma execução encontrada.")), 1)
                            ])) : createCommentVNode("", true)
                          ])
                        ])
                      ]),
                      paginationLinks.value.length > 3 ? (openBlock(), createBlock("nav", {
                        key: 0,
                        class: "mt-2",
                        "aria-label": "Paginação de execuções"
                      }, [
                        createVNode("ul", { class: "pagination pagination-sm mb-0" }, [
                          (openBlock(true), createBlock(Fragment, null, renderList(paginationLinks.value, (page, index) => {
                            return openBlock(), createBlock("li", {
                              key: `${index}-${page.label}`,
                              class: ["page-item", { active: page.active, disabled: !page.url }]
                            }, [
                              createVNode("button", {
                                type: "button",
                                class: "page-link",
                                disabled: !page.url || page.active,
                                onClick: ($event) => goToPage(page.url),
                                innerHTML: page.label
                              }, null, 8, ["disabled", "onClick", "innerHTML"])
                            ], 2);
                          }), 128))
                        ])
                      ])) : createCommentVNode("", true)
                    ])
                  ]),
                  createVNode("div", { class: "col-lg-5" }, [
                    createVNode("div", {
                      class: "border rounded p-3 bg-white",
                      style: { "min-height": "320px" }
                    }, [
                      createVNode("h6", { class: "fw-semibold mb-2" }, toDisplayString(label("details", "Detalhes")), 1),
                      detailLoading.value ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "text-muted"
                      }, toDisplayString(label("loading", "Carregando")) + "...", 1)) : !selectedRun.value ? (openBlock(), createBlock("div", {
                        key: 1,
                        class: "text-muted"
                      }, toDisplayString(label("select_run", "Selecione uma execução para visualizar.")), 1)) : (openBlock(), createBlock(Fragment, { key: 2 }, [
                        createVNode("div", { class: "mb-2" }, [
                          createVNode("strong", null, toDisplayString(label("status", "Status")) + ":", 1),
                          createTextVNode(),
                          createVNode("span", {
                            class: statusClass(selectedRun.value.status)
                          }, toDisplayString(statusLabel(selectedRun.value.status)), 3)
                        ]),
                        createVNode("div", { class: "mb-2" }, [
                          createVNode("strong", null, toDisplayString(label("patient", "Paciente")) + ":", 1),
                          createTextVNode(" " + toDisplayString(selectedRun.value.patient || "-"), 1)
                        ]),
                        createVNode("div", { class: "mb-2" }, [
                          createVNode("strong", null, toDisplayString(label("medical_record", "Prontuário")) + ":", 1),
                          createTextVNode(" " + toDisplayString(selectedRun.value.medical_record_code || "-"), 1)
                        ]),
                        createVNode("label", { class: "form-label mt-2" }, toDisplayString(label("editable_draft", "Rascunho editável")), 1),
                        withDirectives(createVNode("textarea", {
                          "onUpdate:modelValue": ($event) => draftOutput.value = $event,
                          rows: "8",
                          class: "form-control"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelText, draftOutput.value]
                        ]),
                        canApproveOrReject.value ? (openBlock(), createBlock("div", {
                          key: 0,
                          class: "mt-3 d-flex gap-2"
                        }, [
                          createVNode("button", {
                            class: "btn btn-success btn-sm",
                            onClick: approveRun
                          }, [
                            createVNode("i", { class: "ti ti-check me-1" }),
                            createTextVNode(toDisplayString(label("approve")), 1)
                          ]),
                          createVNode("button", {
                            class: "btn btn-danger btn-sm",
                            onClick: rejectRun
                          }, [
                            createVNode("i", { class: "ti ti-x me-1" }),
                            createTextVNode(toDisplayString(label("reject")), 1)
                          ])
                        ])) : createCommentVNode("", true),
                        canApproveOrReject.value ? (openBlock(), createBlock("div", {
                          key: 1,
                          class: "mt-2"
                        }, [
                          createVNode("label", { class: "form-label" }, toDisplayString(label("rejection_reason_optional", "Motivo da rejeição (opcional)")), 1),
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => rejectReason.value = $event,
                            type: "text",
                            class: "form-control"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelText, rejectReason.value]
                          ])
                        ])) : createCommentVNode("", true)
                      ], 64))
                    ])
                  ])
                ])
              ])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/AI/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
