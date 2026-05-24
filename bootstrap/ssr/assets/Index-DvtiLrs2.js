import { ref, watch, mergeProps, withCtx, createVNode, toDisplayString, createTextVNode, withDirectives, vModelText, openBlock, createBlock, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderStyle, ssrInterpolate, ssrRenderClass, ssrRenderAttr } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import DoctorTable from "./DoctorTable-BQaDTcz6.js";
import _sfc_main$1 from "./DoctorCards-Boj1Qwn5.js";
import _sfc_main$2 from "./DoctorFormModal-D9wqcHXq.js";
import DoctorDetailDrawer from "./DoctorDetailDrawer-BWeEy27D.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./ActionDropdown-DZW_71Hn.js";
import "./ActionIconButton-BTsQtzdl.js";
import "./ActionIconGroup-B8JEjj1z.js";
import "./OffcanvasPanel-VfE7yaou.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    doctors: { type: Object, required: true },
    totalDoctors: { type: Number, default: 0 },
    genders: { type: Object, default: () => ({}) },
    maritalStatuses: { type: Object, default: () => ({}) },
    statesOfBrazil: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const view = ref(localStorage.getItem("doctors_view") ?? "table");
    function setView(v) {
      view.value = v;
      localStorage.setItem("doctors_view", v);
    }
    const search = ref(props.filters.search ?? "");
    let searchTimer = null;
    watch(search, (val) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        router.get(
          route("panel.doctors.index"),
          { search: val, sort: props.filters.sort, direction: props.filters.direction },
          { preserveState: true, preserveScroll: true, replace: true }
        );
      }, 400);
    });
    function onSort({ sort, direction }) {
      router.get(
        route("panel.doctors.index"),
        { search: search.value, sort, direction },
        { preserveState: true, preserveScroll: true }
      );
    }
    const modalOpen = ref(false);
    const editDoctorId = ref(null);
    function openCreate() {
      editDoctorId.value = null;
      modalOpen.value = true;
    }
    function openEdit(id) {
      editDoctorId.value = id;
      modalOpen.value = true;
    }
    function closeModal() {
      modalOpen.value = false;
      editDoctorId.value = null;
    }
    const detailOpen = ref(false);
    const viewDoctorId = ref(null);
    function onView(id) {
      viewDoctorId.value = id;
      detailOpen.value = true;
    }
    function closeDetail() {
      detailOpen.value = false;
      viewDoctorId.value = null;
    }
    function onDelete(id) {
      if (!confirm("Tem certeza que deseja excluir este médico?")) return;
      router.delete(route("panel.doctors.destroy", id), { preserveScroll: true });
    }
    function onToggleActive(id, currentActive) {
      router.put(
        route("panel.doctors.update", id),
        { active: !currentActive, type_method: "toggle" },
        { preserveScroll: true }
      );
    }
    const breadcrumbs = [
      { label: "Dashboard", url: route("panel.dashboard"), active: false },
      { label: "Médicos", url: "#", active: true }
    ];
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Médicos",
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div${_scopeId}><div class="d-flex align-items-center gap-2 pb-3 mb-3 border-bottom"${_scopeId}><div class="d-flex align-items-center gap-2 me-auto"${_scopeId}><h4 class="mb-0 fw-bold"${_scopeId}>Médicos</h4><span style="${ssrRenderStyle({ "font-size": ".78rem", "font-weight": "600", "color": "#0d6efd", "background": "#eff4ff", "border": "1.5px solid #0d6efd", "border-radius": "20px", "padding": "2px 12px", "white-space": "nowrap", "line-height": "1.6" })}"${_scopeId}>Total: ${ssrInterpolate(__props.doctors.total)}</span></div><div class="d-flex align-items-center gap-2"${_scopeId}><div class="bg-white border shadow-sm rounded px-1 d-flex align-items-center"${_scopeId}><button type="button" class="${ssrRenderClass([view.value === "table" ? "bg-light" : "bg-white", "rounded p-1 d-flex align-items-center border-0"])}" title="Tabela"${_scopeId}><i class="ti ti-list fs-14 text-body"${_scopeId}></i></button><button type="button" class="${ssrRenderClass([view.value === "cards" ? "bg-light" : "bg-white", "rounded p-1 d-flex align-items-center border-0"])}" title="Cards"${_scopeId}><i class="ti ti-layout-grid fs-14 text-body"${_scopeId}></i></button></div><button type="button" class="btn btn-primary fs-13 btn-md"${_scopeId}><i class="ti ti-plus me-1"${_scopeId}></i> Novo médico </button></div></div><div class="mb-3"${_scopeId}><div class="input-group input-group-sm" style="${ssrRenderStyle({ "max-width": "360px" })}"${_scopeId}><span class="input-group-text bg-white"${_scopeId}><i class="ti ti-search fs-12"${_scopeId}></i></span><input${ssrRenderAttr("value", search.value)} type="text" class="form-control border-start-0" placeholder="Buscar por nome, código ou CRM..."${_scopeId}>`);
            if (search.value) {
              _push2(`<button class="btn btn-outline-secondary border-start-0" type="button"${_scopeId}><i class="ti ti-x fs-12"${_scopeId}></i></button>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div>`);
            if (view.value === "table") {
              _push2(ssrRenderComponent(DoctorTable, {
                doctors: __props.doctors,
                filters: __props.filters,
                onSort,
                onView,
                onEdit: openEdit,
                onDelete,
                onToggleActive
              }, null, _parent2, _scopeId));
            } else {
              _push2(ssrRenderComponent(_sfc_main$1, {
                "cards-url": _ctx.route("panel.doctors.cards"),
                "initial-search": search.value,
                onView,
                onEdit: openEdit,
                onDelete,
                onToggleActive
              }, null, _parent2, _scopeId));
            }
            _push2(`</div>`);
            _push2(ssrRenderComponent(_sfc_main$2, {
              open: modalOpen.value,
              "doctor-id": editDoctorId.value,
              genders: __props.genders,
              "marital-statuses": __props.maritalStatuses,
              "states-of-brazil": __props.statesOfBrazil,
              onClose: closeModal
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(DoctorDetailDrawer, {
              open: detailOpen.value,
              "doctor-id": viewDoctorId.value,
              onClose: closeDetail
            }, null, _parent2, _scopeId));
          } else {
            return [
              createVNode("div", null, [
                createVNode("div", { class: "d-flex align-items-center gap-2 pb-3 mb-3 border-bottom" }, [
                  createVNode("div", { class: "d-flex align-items-center gap-2 me-auto" }, [
                    createVNode("h4", { class: "mb-0 fw-bold" }, "Médicos"),
                    createVNode("span", { style: { "font-size": ".78rem", "font-weight": "600", "color": "#0d6efd", "background": "#eff4ff", "border": "1.5px solid #0d6efd", "border-radius": "20px", "padding": "2px 12px", "white-space": "nowrap", "line-height": "1.6" } }, "Total: " + toDisplayString(__props.doctors.total), 1)
                  ]),
                  createVNode("div", { class: "d-flex align-items-center gap-2" }, [
                    createVNode("div", { class: "bg-white border shadow-sm rounded px-1 d-flex align-items-center" }, [
                      createVNode("button", {
                        type: "button",
                        class: ["rounded p-1 d-flex align-items-center border-0", view.value === "table" ? "bg-light" : "bg-white"],
                        onClick: ($event) => setView("table"),
                        title: "Tabela"
                      }, [
                        createVNode("i", { class: "ti ti-list fs-14 text-body" })
                      ], 10, ["onClick"]),
                      createVNode("button", {
                        type: "button",
                        class: ["rounded p-1 d-flex align-items-center border-0", view.value === "cards" ? "bg-light" : "bg-white"],
                        onClick: ($event) => setView("cards"),
                        title: "Cards"
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
                      createTextVNode(" Novo médico ")
                    ])
                  ])
                ]),
                createVNode("div", { class: "mb-3" }, [
                  createVNode("div", {
                    class: "input-group input-group-sm",
                    style: { "max-width": "360px" }
                  }, [
                    createVNode("span", { class: "input-group-text bg-white" }, [
                      createVNode("i", { class: "ti ti-search fs-12" })
                    ]),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => search.value = $event,
                      type: "text",
                      class: "form-control border-start-0",
                      placeholder: "Buscar por nome, código ou CRM..."
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelText, search.value]
                    ]),
                    search.value ? (openBlock(), createBlock("button", {
                      key: 0,
                      class: "btn btn-outline-secondary border-start-0",
                      type: "button",
                      onClick: ($event) => search.value = ""
                    }, [
                      createVNode("i", { class: "ti ti-x fs-12" })
                    ], 8, ["onClick"])) : createCommentVNode("", true)
                  ])
                ]),
                view.value === "table" ? (openBlock(), createBlock(DoctorTable, {
                  key: 0,
                  doctors: __props.doctors,
                  filters: __props.filters,
                  onSort,
                  onView,
                  onEdit: openEdit,
                  onDelete,
                  onToggleActive
                }, null, 8, ["doctors", "filters"])) : (openBlock(), createBlock(_sfc_main$1, {
                  key: 1,
                  "cards-url": _ctx.route("panel.doctors.cards"),
                  "initial-search": search.value,
                  onView,
                  onEdit: openEdit,
                  onDelete,
                  onToggleActive
                }, null, 8, ["cards-url", "initial-search"]))
              ]),
              createVNode(_sfc_main$2, {
                open: modalOpen.value,
                "doctor-id": editDoctorId.value,
                genders: __props.genders,
                "marital-statuses": __props.maritalStatuses,
                "states-of-brazil": __props.statesOfBrazil,
                onClose: closeModal
              }, null, 8, ["open", "doctor-id", "genders", "marital-statuses", "states-of-brazil"]),
              createVNode(DoctorDetailDrawer, {
                open: detailOpen.value,
                "doctor-id": viewDoctorId.value,
                onClose: closeDetail
              }, null, 8, ["open", "doctor-id"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Doctors/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
