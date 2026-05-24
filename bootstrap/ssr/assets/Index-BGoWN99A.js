import { computed, ref, mergeProps, withCtx, createVNode, openBlock, createBlock, createTextVNode, toDisplayString, createCommentVNode, Fragment, renderList, withDirectives, vModelSelect, vModelText, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderClass, ssrRenderList, ssrIncludeBooleanAttr, ssrRenderStyle, ssrRenderAttr, ssrLooseContain, ssrLooseEqual } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-CYjDf0Y-.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    balance: { type: Object, required: true },
    creditPackages: { type: Array, default: () => [] },
    recentCreditPurchases: { type: Array, default: () => [] },
    creditPurchaseAutoCredit: { type: Boolean, default: false },
    canPurchaseCredits: { type: Boolean, default: false },
    runs: { type: Object, required: true },
    analytics: { type: Object, default: () => ({}) },
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
    const props = __props;
    const analytics = computed(() => props.analytics ?? {});
    const usagePercent = computed(() => {
      var _a, _b;
      return ((_b = (_a = analytics.value) == null ? void 0 : _a.consumed) == null ? void 0 : _b.usage_percent) ?? null;
    });
    const usageBarClass = computed(() => {
      const p = usagePercent.value ?? 0;
      if (p >= 90) return "bg-danger";
      if (p >= 70) return "bg-warning";
      return "bg-success";
    });
    function workflowDisplayLabel(workflow) {
      return label(`workflow_${workflow}`, workflow);
    }
    function modeDisplayLabel(mode) {
      return label(`mode_${mode}`, mode);
    }
    const label = (key, fallback = "") => {
      var _a;
      return ((_a = props.labels) == null ? void 0 : _a[key]) ?? fallback;
    };
    const breadcrumbs = [
      { label: label("dashboard", "Dashboard"), url: route("panel.dashboard"), active: false },
      { label: label("title", "AI"), url: "#", active: true }
    ];
    const detailLoading = ref(false);
    const selectedRun = ref(null);
    const draftOutput = ref("");
    const rejectReason = ref("");
    const statusFilter = ref(props.filters.status ?? "");
    const purchasingPackage = ref("");
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
      var _a;
      return ((_a = selectedRun.value) == null ? void 0 : _a.status) === "waiting_approval";
    });
    const availableStatuses = computed(() => props.statuses.length ? props.statuses : ["pending", "reserved", "running", "waiting_approval", "approved", "rejected", "failed", "cancelled"]);
    const paginationLinks = computed(() => {
      var _a;
      return Array.isArray((_a = props.runs) == null ? void 0 : _a.links) ? props.runs.links : [];
    });
    const workflowLabel = (workflow) => {
      const key = `workflow_${workflow}`;
      return label(key, workflow);
    };
    const modeLabel = (mode) => {
      const key = `mode_${mode}`;
      return label(key, mode);
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
    const purchaseStatusClass = (status) => {
      return {
        pending_payment: "badge bg-warning-subtle text-warning",
        credited: "badge bg-success-subtle text-success",
        cancelled: "badge bg-secondary-subtle text-secondary",
        failed: "badge bg-danger-subtle text-danger",
        refunded: "badge bg-info-subtle text-info"
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
    async function purchaseCredits(packageCode) {
      var _a, _b;
      if (!packageCode || purchasingPackage.value) return;
      purchasingPackage.value = packageCode;
      try {
        const { data } = await window.axios.post(route("panel.ai-credit-purchases.store"), {
          package_code: packageCode
        });
        router.reload({ only: ["balance", "recentCreditPurchases"] });
        showSuccess((data == null ? void 0 : data.message) ?? label("credit_purchase_pending", "Compra registrada."));
      } catch (error) {
        showError(((_b = (_a = error == null ? void 0 : error.response) == null ? void 0 : _a.data) == null ? void 0 : _b.message) ?? label("credit_purchase_unavailable", "Falha ao registrar compra."));
      } finally {
        purchasingPackage.value = "";
      }
    }
    async function loadRunDetail(runId) {
      var _a, _b;
      detailLoading.value = true;
      try {
        const { data } = await window.axios.get(route("panel.ai-runs.show", runId));
        selectedRun.value = data.data;
        draftOutput.value = data.data.final_output ?? "";
        rejectReason.value = "";
      } catch (error) {
        showError(((_b = (_a = error == null ? void 0 : error.response) == null ? void 0 : _a.data) == null ? void 0 : _b.message) ?? label("error_load_detail", "Falha ao carregar detalhes."));
      } finally {
        detailLoading.value = false;
      }
    }
    async function approveRun() {
      var _a, _b, _c;
      if (!((_a = selectedRun.value) == null ? void 0 : _a.id)) return;
      try {
        await window.axios.post(route("panel.ai-runs.approve", selectedRun.value.id), {
          final_output: draftOutput.value
        });
        await loadRunDetail(selectedRun.value.id);
        router.reload({ only: ["runs"] });
        showSuccess(label("run_approved", "Execução aprovada."));
      } catch (error) {
        showError(((_c = (_b = error == null ? void 0 : error.response) == null ? void 0 : _b.data) == null ? void 0 : _c.message) ?? label("error_approve", "Falha ao aprovar execução."));
      }
    }
    async function rejectRun() {
      var _a, _b, _c;
      if (!((_a = selectedRun.value) == null ? void 0 : _a.id)) return;
      try {
        await window.axios.post(route("panel.ai-runs.reject", selectedRun.value.id), {
          reason: rejectReason.value
        });
        await loadRunDetail(selectedRun.value.id);
        router.reload({ only: ["runs"] });
        showSuccess(label("run_rejected", "Execução rejeitada."));
      } catch (error) {
        showError(((_c = (_b = error == null ? void 0 : error.response) == null ? void 0 : _b.data) == null ? void 0 : _c.message) ?? label("error_reject", "Falha ao rejeitar execução."));
      }
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: label("title", "AI"),
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b, _c, _d, _e, _f, _g, _h, _i, _j, _k, _l, _m, _n, _o, _p, _q, _r, _s, _t;
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: label("title", "AI"),
              total: __props.runs.total
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
            _push2(`<div class="row g-3 mb-3"${_scopeId}><div class="${ssrRenderClass(__props.canPurchaseCredits ? "col-lg-4" : "col-12")}"${_scopeId}><div class="border rounded p-3 bg-white h-100"${_scopeId}><div class="d-flex flex-column gap-2"${_scopeId}><div${_scopeId}><strong${_scopeId}>${ssrInterpolate(label("credits_available", "Créditos disponíveis"))}:</strong> ${ssrInterpolate(__props.balance.available)}</div><div${_scopeId}><strong${_scopeId}>${ssrInterpolate(label("credits_reserved", "Reservados"))}:</strong> ${ssrInterpolate(__props.balance.reserved)}</div><div${_scopeId}><strong${_scopeId}>${ssrInterpolate(label("credits_total", "Total"))}:</strong> ${ssrInterpolate(__props.balance.total)}</div></div><div class="mt-2 text-muted fs-13"${_scopeId}>${ssrInterpolate(label("support_notice"))}</div></div></div>`);
            if (__props.canPurchaseCredits) {
              _push2(`<div class="col-lg-8"${_scopeId}><div class="border rounded p-3 bg-white h-100"${_scopeId}><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2"${_scopeId}><div${_scopeId}><h6 class="fw-semibold mb-1"${_scopeId}>${ssrInterpolate(label("credit_packages_title", "Pacotes de créditos IA"))}</h6><div class="text-muted fs-13"${_scopeId}>${ssrInterpolate(label("credit_packages_subtitle", "Créditos extras avulsos."))}</div></div></div><div class="table-responsive"${_scopeId}><table class="table table-sm align-middle mb-0"${_scopeId}><thead${_scopeId}><tr${_scopeId}><th${_scopeId}>${ssrInterpolate(label("credits", "Créditos"))}</th><th${_scopeId}>${ssrInterpolate(label("credit_package", "Pacote"))}</th><th${_scopeId}>${ssrInterpolate(label("amount", "Valor"))}</th><th class="text-end"${_scopeId}></th></tr></thead><tbody${_scopeId}><!--[-->`);
              ssrRenderList(__props.creditPackages, (pkg) => {
                _push2(`<tr${_scopeId}><td class="fw-semibold"${_scopeId}>${ssrInterpolate(pkg.credits)}</td><td${_scopeId}><div class="fw-medium"${_scopeId}>${ssrInterpolate(pkg.name)}</div><div class="text-muted fs-13"${_scopeId}>${ssrInterpolate(pkg.unit_price_formatted)} ${ssrInterpolate(label("credit_package_unit", "por crédito"))}</div></td><td${_scopeId}>${ssrInterpolate(pkg.price_formatted)}</td><td class="text-end"${_scopeId}><button class="${ssrRenderClass([pkg.featured ? "btn-primary" : "btn-outline-primary", "btn btn-sm"])}"${ssrIncludeBooleanAttr(!!purchasingPackage.value) ? " disabled" : ""}${_scopeId}><i class="ti ti-credit-card me-1"${_scopeId}></i> ${ssrInterpolate(__props.creditPurchaseAutoCredit ? label("credit_package_buy", "Comprar agora") : label("credit_package_request", "Solicitar compra"))}</button></td></tr>`);
              });
              _push2(`<!--]--></tbody></table></div>`);
              if (__props.recentCreditPurchases.length) {
                _push2(`<div class="mt-3"${_scopeId}><div class="fw-semibold fs-13 mb-1"${_scopeId}>${ssrInterpolate(label("credit_purchase_history", "Compras recentes"))}</div><div class="d-flex flex-wrap gap-2"${_scopeId}><!--[-->`);
                ssrRenderList(__props.recentCreditPurchases, (purchase) => {
                  _push2(`<span class="d-inline-flex align-items-center gap-2 border rounded px-2 py-1 fs-13"${_scopeId}><span${_scopeId}>${ssrInterpolate(purchase.credits)} · ${ssrInterpolate(purchase.amount_formatted)}</span><span class="${ssrRenderClass(purchaseStatusClass(purchase.status))}"${_scopeId}>${ssrInterpolate(purchase.status_label)}</span></span>`);
                });
                _push2(`<!--]--></div></div>`);
              } else {
                _push2(`<div class="mt-3 text-muted fs-13"${_scopeId}>${ssrInterpolate(label("credit_purchase_empty", "Nenhuma compra recente."))}</div>`);
              }
              _push2(`</div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
            if ((_a = analytics.value) == null ? void 0 : _a.period) {
              _push2(`<div class="border rounded p-3 bg-white mb-3"${_scopeId}><div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"${_scopeId}><h6 class="fw-semibold mb-0"${_scopeId}><i class="ti ti-chart-donut me-1 text-info"${_scopeId}></i> Consumo de ${ssrInterpolate(analytics.value.period.label)}</h6><span class="text-muted fs-13"${_scopeId}>${ssrInterpolate(analytics.value.period.start)} — ${ssrInterpolate(analytics.value.period.end)}</span></div>`);
              if (analytics.value.plan_quota > 0) {
                _push2(`<div class="mb-3"${_scopeId}><div class="d-flex justify-content-between mb-1 fs-13"${_scopeId}><span class="fw-semibold"${_scopeId}>Cota do plano</span><span class="text-muted"${_scopeId}><strong${_scopeId}>${ssrInterpolate(((_b = analytics.value.consumed) == null ? void 0 : _b.credits) ?? 0)}</strong> / ${ssrInterpolate(analytics.value.plan_quota)} créditos `);
                if (usagePercent.value !== null) {
                  _push2(`<span class="ms-1"${_scopeId}>(${ssrInterpolate(usagePercent.value)}%)</span>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</span></div><div class="progress" style="${ssrRenderStyle({ "height": "12px" })}"${_scopeId}><div class="${ssrRenderClass(["progress-bar", usageBarClass.value])}" role="progressbar" style="${ssrRenderStyle({ width: Math.min(usagePercent.value ?? 0, 100) + "%" })}"${ssrRenderAttr("aria-valuenow", usagePercent.value ?? 0)} aria-valuemin="0" aria-valuemax="100"${_scopeId}></div></div></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<div class="row g-3"${_scopeId}><div class="col-md-3"${_scopeId}><div class="border rounded p-2 text-center h-100"${_scopeId}><div class="text-muted fs-13"${_scopeId}>Execuções</div><div class="fs-3 fw-bold text-primary"${_scopeId}>${ssrInterpolate(((_c = analytics.value.consumed) == null ? void 0 : _c.runs) ?? 0)}</div></div></div><div class="col-md-3"${_scopeId}><div class="border rounded p-2 text-center h-100"${_scopeId}><div class="text-muted fs-13"${_scopeId}>Créditos consumidos</div><div class="fs-3 fw-bold text-info"${_scopeId}>${ssrInterpolate(((_d = analytics.value.consumed) == null ? void 0 : _d.credits) ?? 0)}</div></div></div><div class="col-md-3"${_scopeId}><div class="border rounded p-2 text-center h-100"${_scopeId}><div class="text-muted fs-13"${_scopeId}>Aprovados</div><div class="fs-3 fw-bold text-success"${_scopeId}>${ssrInterpolate(((_e = analytics.value.approval) == null ? void 0 : _e.approved) ?? 0)}</div></div></div><div class="col-md-3"${_scopeId}><div class="border rounded p-2 text-center h-100"${_scopeId}><div class="text-muted fs-13"${_scopeId}>Taxa de aprovação</div><div class="fs-3 fw-bold text-warning"${_scopeId}>`);
              if (((_f = analytics.value.approval) == null ? void 0 : _f.rate) !== null && ((_g = analytics.value.approval) == null ? void 0 : _g.rate) !== void 0) {
                _push2(`<!--[-->${ssrInterpolate(analytics.value.approval.rate)}% <!--]-->`);
              } else {
                _push2(`<!--[-->—<!--]-->`);
              }
              _push2(`</div></div></div><div class="col-md-6"${_scopeId}><h6 class="fw-semibold fs-13 mb-2"${_scopeId}>Por workflow</h6>`);
              if (((_h = analytics.value.by_workflow) == null ? void 0 : _h.length) === 0) {
                _push2(`<div class="text-muted fs-13"${_scopeId}>Sem execuções no período.</div>`);
              } else {
                _push2(`<ul class="list-unstyled mb-0"${_scopeId}><!--[-->`);
                ssrRenderList(analytics.value.by_workflow, (row) => {
                  _push2(`<li class="d-flex justify-content-between border-bottom py-1 fs-13"${_scopeId}><span${_scopeId}>${ssrInterpolate(workflowDisplayLabel(row.workflow))}</span><span class="text-muted"${_scopeId}>${ssrInterpolate(row.runs_count)} runs · <strong${_scopeId}>${ssrInterpolate(row.credits_total)}</strong> créditos </span></li>`);
                });
                _push2(`<!--]--></ul>`);
              }
              _push2(`</div><div class="col-md-6"${_scopeId}><h6 class="fw-semibold fs-13 mb-2"${_scopeId}>Por modo de revisão</h6>`);
              if (((_i = analytics.value.by_mode) == null ? void 0 : _i.length) === 0) {
                _push2(`<div class="text-muted fs-13"${_scopeId}>Sem execuções no período.</div>`);
              } else {
                _push2(`<ul class="list-unstyled mb-0"${_scopeId}><!--[-->`);
                ssrRenderList(analytics.value.by_mode, (row) => {
                  _push2(`<li class="d-flex justify-content-between border-bottom py-1 fs-13"${_scopeId}><span${_scopeId}>${ssrInterpolate(modeDisplayLabel(row.mode))}</span><span class="text-muted"${_scopeId}>${ssrInterpolate(row.runs_count)} runs</span></li>`);
                });
                _push2(`<!--]--></ul>`);
              }
              _push2(`</div>`);
              if ((_j = analytics.value.top_runs) == null ? void 0 : _j.length) {
                _push2(`<div class="col-12"${_scopeId}><h6 class="fw-semibold fs-13 mb-2"${_scopeId}>Top 5 execuções por custo</h6><div class="table-responsive"${_scopeId}><table class="table table-sm table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Data</th><th${_scopeId}>Workflow</th><th${_scopeId}>Paciente</th><th${_scopeId}>Solicitante</th><th class="text-end"${_scopeId}>Créditos</th></tr></thead><tbody${_scopeId}><!--[-->`);
                ssrRenderList(analytics.value.top_runs, (run) => {
                  _push2(`<tr${_scopeId}><td class="fs-13 text-muted"${_scopeId}>${ssrInterpolate(run.created_at)}</td><td class="fs-13"${_scopeId}>${ssrInterpolate(workflowDisplayLabel(run.workflow))}</td><td class="fs-13"${_scopeId}>${ssrInterpolate(run.patient ?? "—")}</td><td class="fs-13"${_scopeId}>${ssrInterpolate(run.requested_by ?? "—")}</td><td class="text-end fw-bold"${_scopeId}>${ssrInterpolate(run.consumed_credits)}</td></tr>`);
                });
                _push2(`<!--]--></tbody></table></div></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="row g-3"${_scopeId}><div class="col-lg-7"${_scopeId}><div class="border rounded p-3 bg-white"${_scopeId}><div class="d-flex justify-content-between align-items-center mb-2"${_scopeId}><h6 class="fw-semibold mb-0"${_scopeId}>${ssrInterpolate(label("runs", "Execuções"))}</h6><select class="form-select form-select-sm w-auto"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(statusFilter.value) ? ssrLooseContain(statusFilter.value, "") : ssrLooseEqual(statusFilter.value, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(label("all_statuses", "Todos status"))}</option><!--[-->`);
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
                  total: __props.runs.total
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
                createVNode("div", { class: "row g-3 mb-3" }, [
                  createVNode("div", {
                    class: __props.canPurchaseCredits ? "col-lg-4" : "col-12"
                  }, [
                    createVNode("div", { class: "border rounded p-3 bg-white h-100" }, [
                      createVNode("div", { class: "d-flex flex-column gap-2" }, [
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
                    ])
                  ], 2),
                  __props.canPurchaseCredits ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "col-lg-8"
                  }, [
                    createVNode("div", { class: "border rounded p-3 bg-white h-100" }, [
                      createVNode("div", { class: "d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2" }, [
                        createVNode("div", null, [
                          createVNode("h6", { class: "fw-semibold mb-1" }, toDisplayString(label("credit_packages_title", "Pacotes de créditos IA")), 1),
                          createVNode("div", { class: "text-muted fs-13" }, toDisplayString(label("credit_packages_subtitle", "Créditos extras avulsos.")), 1)
                        ])
                      ]),
                      createVNode("div", { class: "table-responsive" }, [
                        createVNode("table", { class: "table table-sm align-middle mb-0" }, [
                          createVNode("thead", null, [
                            createVNode("tr", null, [
                              createVNode("th", null, toDisplayString(label("credits", "Créditos")), 1),
                              createVNode("th", null, toDisplayString(label("credit_package", "Pacote")), 1),
                              createVNode("th", null, toDisplayString(label("amount", "Valor")), 1),
                              createVNode("th", { class: "text-end" })
                            ])
                          ]),
                          createVNode("tbody", null, [
                            (openBlock(true), createBlock(Fragment, null, renderList(__props.creditPackages, (pkg) => {
                              return openBlock(), createBlock("tr", {
                                key: pkg.code
                              }, [
                                createVNode("td", { class: "fw-semibold" }, toDisplayString(pkg.credits), 1),
                                createVNode("td", null, [
                                  createVNode("div", { class: "fw-medium" }, toDisplayString(pkg.name), 1),
                                  createVNode("div", { class: "text-muted fs-13" }, toDisplayString(pkg.unit_price_formatted) + " " + toDisplayString(label("credit_package_unit", "por crédito")), 1)
                                ]),
                                createVNode("td", null, toDisplayString(pkg.price_formatted), 1),
                                createVNode("td", { class: "text-end" }, [
                                  createVNode("button", {
                                    class: ["btn btn-sm", pkg.featured ? "btn-primary" : "btn-outline-primary"],
                                    disabled: !!purchasingPackage.value,
                                    onClick: ($event) => purchaseCredits(pkg.code)
                                  }, [
                                    createVNode("i", { class: "ti ti-credit-card me-1" }),
                                    createTextVNode(" " + toDisplayString(__props.creditPurchaseAutoCredit ? label("credit_package_buy", "Comprar agora") : label("credit_package_request", "Solicitar compra")), 1)
                                  ], 10, ["disabled", "onClick"])
                                ])
                              ]);
                            }), 128))
                          ])
                        ])
                      ]),
                      __props.recentCreditPurchases.length ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "mt-3"
                      }, [
                        createVNode("div", { class: "fw-semibold fs-13 mb-1" }, toDisplayString(label("credit_purchase_history", "Compras recentes")), 1),
                        createVNode("div", { class: "d-flex flex-wrap gap-2" }, [
                          (openBlock(true), createBlock(Fragment, null, renderList(__props.recentCreditPurchases, (purchase) => {
                            return openBlock(), createBlock("span", {
                              key: purchase.id,
                              class: "d-inline-flex align-items-center gap-2 border rounded px-2 py-1 fs-13"
                            }, [
                              createVNode("span", null, toDisplayString(purchase.credits) + " · " + toDisplayString(purchase.amount_formatted), 1),
                              createVNode("span", {
                                class: purchaseStatusClass(purchase.status)
                              }, toDisplayString(purchase.status_label), 3)
                            ]);
                          }), 128))
                        ])
                      ])) : (openBlock(), createBlock("div", {
                        key: 1,
                        class: "mt-3 text-muted fs-13"
                      }, toDisplayString(label("credit_purchase_empty", "Nenhuma compra recente.")), 1))
                    ])
                  ])) : createCommentVNode("", true)
                ]),
                ((_k = analytics.value) == null ? void 0 : _k.period) ? (openBlock(), createBlock("div", {
                  key: 2,
                  class: "border rounded p-3 bg-white mb-3"
                }, [
                  createVNode("div", { class: "d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2" }, [
                    createVNode("h6", { class: "fw-semibold mb-0" }, [
                      createVNode("i", { class: "ti ti-chart-donut me-1 text-info" }),
                      createTextVNode(" Consumo de " + toDisplayString(analytics.value.period.label), 1)
                    ]),
                    createVNode("span", { class: "text-muted fs-13" }, toDisplayString(analytics.value.period.start) + " — " + toDisplayString(analytics.value.period.end), 1)
                  ]),
                  analytics.value.plan_quota > 0 ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "mb-3"
                  }, [
                    createVNode("div", { class: "d-flex justify-content-between mb-1 fs-13" }, [
                      createVNode("span", { class: "fw-semibold" }, "Cota do plano"),
                      createVNode("span", { class: "text-muted" }, [
                        createVNode("strong", null, toDisplayString(((_l = analytics.value.consumed) == null ? void 0 : _l.credits) ?? 0), 1),
                        createTextVNode(" / " + toDisplayString(analytics.value.plan_quota) + " créditos ", 1),
                        usagePercent.value !== null ? (openBlock(), createBlock("span", {
                          key: 0,
                          class: "ms-1"
                        }, "(" + toDisplayString(usagePercent.value) + "%)", 1)) : createCommentVNode("", true)
                      ])
                    ]),
                    createVNode("div", {
                      class: "progress",
                      style: { "height": "12px" }
                    }, [
                      createVNode("div", {
                        class: ["progress-bar", usageBarClass.value],
                        role: "progressbar",
                        style: { width: Math.min(usagePercent.value ?? 0, 100) + "%" },
                        "aria-valuenow": usagePercent.value ?? 0,
                        "aria-valuemin": "0",
                        "aria-valuemax": "100"
                      }, null, 14, ["aria-valuenow"])
                    ])
                  ])) : createCommentVNode("", true),
                  createVNode("div", { class: "row g-3" }, [
                    createVNode("div", { class: "col-md-3" }, [
                      createVNode("div", { class: "border rounded p-2 text-center h-100" }, [
                        createVNode("div", { class: "text-muted fs-13" }, "Execuções"),
                        createVNode("div", { class: "fs-3 fw-bold text-primary" }, toDisplayString(((_m = analytics.value.consumed) == null ? void 0 : _m.runs) ?? 0), 1)
                      ])
                    ]),
                    createVNode("div", { class: "col-md-3" }, [
                      createVNode("div", { class: "border rounded p-2 text-center h-100" }, [
                        createVNode("div", { class: "text-muted fs-13" }, "Créditos consumidos"),
                        createVNode("div", { class: "fs-3 fw-bold text-info" }, toDisplayString(((_n = analytics.value.consumed) == null ? void 0 : _n.credits) ?? 0), 1)
                      ])
                    ]),
                    createVNode("div", { class: "col-md-3" }, [
                      createVNode("div", { class: "border rounded p-2 text-center h-100" }, [
                        createVNode("div", { class: "text-muted fs-13" }, "Aprovados"),
                        createVNode("div", { class: "fs-3 fw-bold text-success" }, toDisplayString(((_o = analytics.value.approval) == null ? void 0 : _o.approved) ?? 0), 1)
                      ])
                    ]),
                    createVNode("div", { class: "col-md-3" }, [
                      createVNode("div", { class: "border rounded p-2 text-center h-100" }, [
                        createVNode("div", { class: "text-muted fs-13" }, "Taxa de aprovação"),
                        createVNode("div", { class: "fs-3 fw-bold text-warning" }, [
                          ((_p = analytics.value.approval) == null ? void 0 : _p.rate) !== null && ((_q = analytics.value.approval) == null ? void 0 : _q.rate) !== void 0 ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                            createTextVNode(toDisplayString(analytics.value.approval.rate) + "% ", 1)
                          ], 64)) : (openBlock(), createBlock(Fragment, { key: 1 }, [
                            createTextVNode("—")
                          ], 64))
                        ])
                      ])
                    ]),
                    createVNode("div", { class: "col-md-6" }, [
                      createVNode("h6", { class: "fw-semibold fs-13 mb-2" }, "Por workflow"),
                      ((_r = analytics.value.by_workflow) == null ? void 0 : _r.length) === 0 ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "text-muted fs-13"
                      }, "Sem execuções no período.")) : (openBlock(), createBlock("ul", {
                        key: 1,
                        class: "list-unstyled mb-0"
                      }, [
                        (openBlock(true), createBlock(Fragment, null, renderList(analytics.value.by_workflow, (row) => {
                          return openBlock(), createBlock("li", {
                            key: row.workflow,
                            class: "d-flex justify-content-between border-bottom py-1 fs-13"
                          }, [
                            createVNode("span", null, toDisplayString(workflowDisplayLabel(row.workflow)), 1),
                            createVNode("span", { class: "text-muted" }, [
                              createTextVNode(toDisplayString(row.runs_count) + " runs · ", 1),
                              createVNode("strong", null, toDisplayString(row.credits_total), 1),
                              createTextVNode(" créditos ")
                            ])
                          ]);
                        }), 128))
                      ]))
                    ]),
                    createVNode("div", { class: "col-md-6" }, [
                      createVNode("h6", { class: "fw-semibold fs-13 mb-2" }, "Por modo de revisão"),
                      ((_s = analytics.value.by_mode) == null ? void 0 : _s.length) === 0 ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "text-muted fs-13"
                      }, "Sem execuções no período.")) : (openBlock(), createBlock("ul", {
                        key: 1,
                        class: "list-unstyled mb-0"
                      }, [
                        (openBlock(true), createBlock(Fragment, null, renderList(analytics.value.by_mode, (row) => {
                          return openBlock(), createBlock("li", {
                            key: row.mode,
                            class: "d-flex justify-content-between border-bottom py-1 fs-13"
                          }, [
                            createVNode("span", null, toDisplayString(modeDisplayLabel(row.mode)), 1),
                            createVNode("span", { class: "text-muted" }, toDisplayString(row.runs_count) + " runs", 1)
                          ]);
                        }), 128))
                      ]))
                    ]),
                    ((_t = analytics.value.top_runs) == null ? void 0 : _t.length) ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "col-12"
                    }, [
                      createVNode("h6", { class: "fw-semibold fs-13 mb-2" }, "Top 5 execuções por custo"),
                      createVNode("div", { class: "table-responsive" }, [
                        createVNode("table", { class: "table table-sm table-hover align-middle mb-0" }, [
                          createVNode("thead", { class: "table-light" }, [
                            createVNode("tr", null, [
                              createVNode("th", null, "Data"),
                              createVNode("th", null, "Workflow"),
                              createVNode("th", null, "Paciente"),
                              createVNode("th", null, "Solicitante"),
                              createVNode("th", { class: "text-end" }, "Créditos")
                            ])
                          ]),
                          createVNode("tbody", null, [
                            (openBlock(true), createBlock(Fragment, null, renderList(analytics.value.top_runs, (run) => {
                              return openBlock(), createBlock("tr", {
                                key: run.id
                              }, [
                                createVNode("td", { class: "fs-13 text-muted" }, toDisplayString(run.created_at), 1),
                                createVNode("td", { class: "fs-13" }, toDisplayString(workflowDisplayLabel(run.workflow)), 1),
                                createVNode("td", { class: "fs-13" }, toDisplayString(run.patient ?? "—"), 1),
                                createVNode("td", { class: "fs-13" }, toDisplayString(run.requested_by ?? "—"), 1),
                                createVNode("td", { class: "text-end fw-bold" }, toDisplayString(run.consumed_credits), 1)
                              ]);
                            }), 128))
                          ])
                        ])
                      ])
                    ])) : createCommentVNode("", true)
                  ])
                ])) : createCommentVNode("", true),
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
