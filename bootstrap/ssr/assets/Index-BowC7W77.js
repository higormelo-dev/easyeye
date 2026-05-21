import { ref, watch, mergeProps, withCtx, createVNode, toDisplayString, createTextVNode, withDirectives, vModelText, openBlock, createBlock, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderStyle, ssrInterpolate, ssrRenderClass, ssrRenderAttr } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import PatientTable from "./PatientTable-CNaXFgKY.js";
import _sfc_main$1 from "./PatientCards-CWGArATn.js";
import _sfc_main$2 from "./PatientFormModal-BaTCX5_C.js";
import PatientDetailDrawer from "./PatientDetailDrawer-D23psjo3.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./ActionDropdown-DZW_71Hn.js";
import "./ActionIconGroup-Dj2wQrik.js";
import "./OffcanvasPanel-VfE7yaou.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    patients: { type: Object, required: true },
    totalPatients: { type: Number, default: 0 },
    covenants: { type: Array, default: () => [] },
    skinTypes: { type: Array, default: () => [] },
    irisTypes: { type: Array, default: () => [] },
    genders: { type: Object, default: () => ({}) },
    maritalStatuses: { type: Object, default: () => ({}) },
    statesOfBrazil: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const view = ref(localStorage.getItem("patients_view") ?? "table");
    function setView(v) {
      view.value = v;
      localStorage.setItem("patients_view", v);
    }
    const search = ref(props.filters.search ?? "");
    let searchTimer = null;
    watch(search, (val) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => performSearch(val), 400);
    });
    function performSearch(val) {
      router.get(
        route("panel.patients.index"),
        { search: val, sort: props.filters.sort, direction: props.filters.direction },
        { preserveState: true, preserveScroll: true, replace: true }
      );
    }
    function clearSearch() {
      search.value = "";
    }
    function onSort({ sort, direction }) {
      router.get(
        route("panel.patients.index"),
        { search: search.value, sort, direction },
        { preserveState: true, preserveScroll: true }
      );
    }
    const modalOpen = ref(false);
    const editPatientId = ref(null);
    function openCreate() {
      editPatientId.value = null;
      modalOpen.value = true;
    }
    function openEdit(id) {
      editPatientId.value = id;
      modalOpen.value = true;
    }
    function closeModal() {
      modalOpen.value = false;
      editPatientId.value = null;
    }
    const detailOpen = ref(false);
    const viewPatientId = ref(null);
    function onView(id) {
      viewPatientId.value = id;
      detailOpen.value = true;
    }
    function closeDetail() {
      detailOpen.value = false;
      viewPatientId.value = null;
    }
    function onDelete(id) {
      var _a, _b;
      if (!confirm(((_b = (_a = window.translations) == null ? void 0 : _a.messages) == null ? void 0 : _b.delete_confirm_text) ?? "Tem certeza que deseja excluir?")) return;
      router.delete(route("panel.patients.destroy", id), {
        preserveScroll: true
      });
    }
    function onToggleActive(id, currentActive) {
      router.put(
        route("panel.patients.update", id),
        { active: !currentActive, type_method: "toggle" },
        { preserveScroll: true }
      );
    }
    function onRestore(id) {
      router.get(route("panel.patients.restore", id), {}, { preserveScroll: true });
    }
    const breadcrumbs = [
      { label: "Dashboard", url: route("panel.dashboard"), active: false },
      { label: "Pacientes", url: "#", active: true }
    ];
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Pacientes",
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="page-patients"${_scopeId}><div class="d-flex align-items-center gap-2 pb-3 mb-3 border-bottom"${_scopeId}><div class="d-flex align-items-center gap-2 me-auto"${_scopeId}><h4 class="mb-0 fw-bold"${_scopeId}>Pacientes</h4><span style="${ssrRenderStyle({ "font-size": ".78rem", "font-weight": "600", "color": "#0d6efd", "background": "#eff4ff", "border": "1.5px solid #0d6efd", "border-radius": "20px", "padding": "2px 12px", "white-space": "nowrap", "line-height": "1.6" })}"${_scopeId}>Total: ${ssrInterpolate(__props.patients.total)}</span></div><div class="d-flex align-items-center gap-2"${_scopeId}><div class="bg-white border shadow-sm rounded px-1 d-flex align-items-center"${_scopeId}><button type="button" class="${ssrRenderClass([view.value === "table" ? "bg-light" : "bg-white", "rounded p-1 d-flex align-items-center justify-content-center border-0"])}" title="Visualização em tabela"${_scopeId}><i class="ti ti-list fs-14 text-body"${_scopeId}></i></button><button type="button" class="${ssrRenderClass([view.value === "cards" ? "bg-light" : "bg-white", "rounded p-1 d-flex align-items-center justify-content-center border-0"])}" title="Visualização em cards"${_scopeId}><i class="ti ti-layout-grid fs-14 text-body"${_scopeId}></i></button></div><button type="button" class="btn btn-primary fs-13 btn-md"${_scopeId}><i class="ti ti-plus me-1"${_scopeId}></i> Novo paciente </button></div></div><div class="d-flex align-items-center justify-content-between flex-wrap mb-3 gap-2"${_scopeId}><div class="table-search"${_scopeId}><div class="input-group input-group-sm" style="${ssrRenderStyle({ "min-width": "280px" })}"${_scopeId}><span class="input-group-text bg-white"${_scopeId}><i class="ti ti-search fs-12"${_scopeId}></i></span><input${ssrRenderAttr("value", search.value)} type="text" class="form-control border-start-0" placeholder="Buscar por nome, código ou telefone..."${_scopeId}>`);
            if (search.value) {
              _push2(`<button class="btn btn-outline-secondary border-start-0" type="button"${_scopeId}><i class="ti ti-x fs-12"${_scopeId}></i></button>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div></div>`);
            if (view.value === "table") {
              _push2(ssrRenderComponent(PatientTable, {
                patients: __props.patients,
                filters: __props.filters,
                onSort,
                onEdit: openEdit,
                onView,
                onDelete,
                onToggleActive,
                onRestore
              }, null, _parent2, _scopeId));
            } else {
              _push2(ssrRenderComponent(_sfc_main$1, {
                "cards-url": _ctx.route("panel.patients.cards"),
                "initial-search": search.value,
                onEdit: openEdit,
                onView,
                onDelete,
                onToggleActive,
                onRestore
              }, null, _parent2, _scopeId));
            }
            _push2(`</div>`);
            _push2(ssrRenderComponent(_sfc_main$2, {
              open: modalOpen.value,
              "patient-id": editPatientId.value,
              covenants: __props.covenants,
              "skin-types": __props.skinTypes,
              "iris-types": __props.irisTypes,
              genders: __props.genders,
              "marital-statuses": __props.maritalStatuses,
              "states-of-brazil": __props.statesOfBrazil,
              onClose: closeModal
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(PatientDetailDrawer, {
              open: detailOpen.value,
              "patient-id": viewPatientId.value,
              onClose: closeDetail
            }, null, _parent2, _scopeId));
          } else {
            return [
              createVNode("div", { class: "page-patients" }, [
                createVNode("div", { class: "d-flex align-items-center gap-2 pb-3 mb-3 border-bottom" }, [
                  createVNode("div", { class: "d-flex align-items-center gap-2 me-auto" }, [
                    createVNode("h4", { class: "mb-0 fw-bold" }, "Pacientes"),
                    createVNode("span", { style: { "font-size": ".78rem", "font-weight": "600", "color": "#0d6efd", "background": "#eff4ff", "border": "1.5px solid #0d6efd", "border-radius": "20px", "padding": "2px 12px", "white-space": "nowrap", "line-height": "1.6" } }, "Total: " + toDisplayString(__props.patients.total), 1)
                  ]),
                  createVNode("div", { class: "d-flex align-items-center gap-2" }, [
                    createVNode("div", { class: "bg-white border shadow-sm rounded px-1 d-flex align-items-center" }, [
                      createVNode("button", {
                        type: "button",
                        class: ["rounded p-1 d-flex align-items-center justify-content-center border-0", view.value === "table" ? "bg-light" : "bg-white"],
                        title: "Visualização em tabela",
                        onClick: ($event) => setView("table")
                      }, [
                        createVNode("i", { class: "ti ti-list fs-14 text-body" })
                      ], 10, ["onClick"]),
                      createVNode("button", {
                        type: "button",
                        class: ["rounded p-1 d-flex align-items-center justify-content-center border-0", view.value === "cards" ? "bg-light" : "bg-white"],
                        title: "Visualização em cards",
                        onClick: ($event) => setView("cards")
                      }, [
                        createVNode("i", { class: "ti ti-layout-grid fs-14 text-body" })
                      ], 10, ["onClick"])
                    ]),
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-primary fs-13 btn-md",
                      onClick: openCreate
                    }, [
                      createVNode("i", { class: "ti ti-plus me-1" }),
                      createTextVNode(" Novo paciente ")
                    ])
                  ])
                ]),
                createVNode("div", { class: "d-flex align-items-center justify-content-between flex-wrap mb-3 gap-2" }, [
                  createVNode("div", { class: "table-search" }, [
                    createVNode("div", {
                      class: "input-group input-group-sm",
                      style: { "min-width": "280px" }
                    }, [
                      createVNode("span", { class: "input-group-text bg-white" }, [
                        createVNode("i", { class: "ti ti-search fs-12" })
                      ]),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => search.value = $event,
                        type: "text",
                        class: "form-control border-start-0",
                        placeholder: "Buscar por nome, código ou telefone..."
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, search.value]
                      ]),
                      search.value ? (openBlock(), createBlock("button", {
                        key: 0,
                        class: "btn btn-outline-secondary border-start-0",
                        type: "button",
                        onClick: clearSearch
                      }, [
                        createVNode("i", { class: "ti ti-x fs-12" })
                      ])) : createCommentVNode("", true)
                    ])
                  ])
                ]),
                view.value === "table" ? (openBlock(), createBlock(PatientTable, {
                  key: 0,
                  patients: __props.patients,
                  filters: __props.filters,
                  onSort,
                  onEdit: openEdit,
                  onView,
                  onDelete,
                  onToggleActive,
                  onRestore
                }, null, 8, ["patients", "filters"])) : (openBlock(), createBlock(_sfc_main$1, {
                  key: 1,
                  "cards-url": _ctx.route("panel.patients.cards"),
                  "initial-search": search.value,
                  onEdit: openEdit,
                  onView,
                  onDelete,
                  onToggleActive,
                  onRestore
                }, null, 8, ["cards-url", "initial-search"]))
              ]),
              createVNode(_sfc_main$2, {
                open: modalOpen.value,
                "patient-id": editPatientId.value,
                covenants: __props.covenants,
                "skin-types": __props.skinTypes,
                "iris-types": __props.irisTypes,
                genders: __props.genders,
                "marital-statuses": __props.maritalStatuses,
                "states-of-brazil": __props.statesOfBrazil,
                onClose: closeModal
              }, null, 8, ["open", "patient-id", "covenants", "skin-types", "iris-types", "genders", "marital-statuses", "states-of-brazil"]),
              createVNode(PatientDetailDrawer, {
                open: detailOpen.value,
                "patient-id": viewPatientId.value,
                onClose: closeDetail
              }, null, 8, ["open", "patient-id"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Patients/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
