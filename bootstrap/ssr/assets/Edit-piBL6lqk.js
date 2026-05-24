import { unref, withCtx, createVNode, createTextVNode, toDisplayString, openBlock, createBlock, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderAttr, ssrRenderStyle } from "vue/server-renderer";
import { Head, Link } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import PatientInfoSidebar from "./PatientInfoSidebar-ChtWAsyC.js";
import _sfc_main$1 from "./MedicalRecordForm-DO3rHoWx.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./PdfPreviewModal-BGdxaBML.js";
import "./MedicalRecordFileUploadModal-MXGxLznw.js";
const _sfc_main = {
  __name: "Edit",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    patient: { type: Object, required: true },
    medicalrecord: { type: Object, required: true },
    doctors: { type: Array, default: () => [] },
    currentDoctorId: { type: String, default: null },
    canChooseDoctor: { type: Boolean, default: false },
    isDoctor: { type: Boolean, default: false },
    isEdit: { type: Boolean, default: true },
    catalogs: { type: Object, required: true },
    urls: { type: Object, required: true },
    storage: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), {
        title: __props.t.edit ?? "Editar Prontuário"
      }, null, _parent));
      _push(ssrRenderComponent(AppLayout, {
        title: __props.t.edit ?? "Editar Prontuário",
        breadcrumbs: __props.breadcrumbs
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="pmr-screen"${_scopeId}><div class="row mb-3 align-items-center"${_scopeId}><div class="col-12 col-md-auto d-flex gap-2 flex-wrap align-items-center"${_scopeId}><div class="btn-group" role="group"${_scopeId}>`);
            _push2(ssrRenderComponent(unref(Link), {
              href: __props.urls.list,
              class: "btn btn-outline-white btn-sm"
            }, {
              default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`<i class="fas fa-arrow-left me-1"${_scopeId2}></i>${ssrInterpolate(__props.t.title ?? "Prontuários")}`);
                } else {
                  return [
                    createVNode("i", { class: "fas fa-arrow-left me-1" }),
                    createTextVNode(toDisplayString(__props.t.title ?? "Prontuários"), 1)
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(ssrRenderComponent(unref(Link), {
              href: __props.urls.create,
              class: "btn btn-primary btn-sm"
            }, {
              default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`<i class="fas fa-plus me-1"${_scopeId2}></i>${ssrInterpolate(__props.t.new ?? "Novo")}`);
                } else {
                  return [
                    createVNode("i", { class: "fas fa-plus me-1" }),
                    createTextVNode(toDisplayString(__props.t.new ?? "Novo"), 1)
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`<a${ssrRenderAttr("href", __props.urls.pdf)} target="_blank" class="btn btn-outline-white btn-sm"${_scopeId}><i class="fas fa-file-pdf me-1"${_scopeId}></i>PDF </a></div></div></div><div class="row g-2"${_scopeId}><div class="col-12 col-lg-3 col-xl-2"${_scopeId}><div class="patient-info-sticky"${_scopeId}>`);
            _push2(ssrRenderComponent(PatientInfoSidebar, { patient: __props.patient }, null, _parent2, _scopeId));
            _push2(`</div></div><div class="col-12 col-lg-9 col-xl-10"${_scopeId}><div class="card pmr-content-card overflow-hidden bg-white"${_scopeId}><div class="d-flex align-items-center justify-content-between px-3 py-1 pmr-record-strip"${_scopeId}><span${_scopeId}><i class="fas fa-file-medical-alt me-1"${_scopeId}></i>${ssrInterpolate(__props.medicalrecord.code)}</span>`);
            if (__props.medicalrecord.is_locked) {
              _push2(`<span class="badge bg-warning text-dark" style="${ssrRenderStyle({ "font-size": ".65rem" })}"${_scopeId}><i class="fas fa-lock me-1"${_scopeId}></i>${ssrInterpolate(__props.t.locked ?? "Bloqueado")}</span>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              patient: __props.patient,
              medicalrecord: __props.medicalrecord,
              doctors: __props.doctors,
              "current-doctor-id": __props.currentDoctorId,
              "can-choose-doctor": __props.canChooseDoctor,
              "is-doctor": __props.isDoctor,
              "is-edit": true,
              catalogs: __props.catalogs,
              urls: __props.urls,
              storage: __props.storage,
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(`</div></div></div></div>`);
          } else {
            return [
              createVNode("div", { class: "pmr-screen" }, [
                createVNode("div", { class: "row mb-3 align-items-center" }, [
                  createVNode("div", { class: "col-12 col-md-auto d-flex gap-2 flex-wrap align-items-center" }, [
                    createVNode("div", {
                      class: "btn-group",
                      role: "group"
                    }, [
                      createVNode(unref(Link), {
                        href: __props.urls.list,
                        class: "btn btn-outline-white btn-sm"
                      }, {
                        default: withCtx(() => [
                          createVNode("i", { class: "fas fa-arrow-left me-1" }),
                          createTextVNode(toDisplayString(__props.t.title ?? "Prontuários"), 1)
                        ]),
                        _: 1
                      }, 8, ["href"]),
                      createVNode(unref(Link), {
                        href: __props.urls.create,
                        class: "btn btn-primary btn-sm"
                      }, {
                        default: withCtx(() => [
                          createVNode("i", { class: "fas fa-plus me-1" }),
                          createTextVNode(toDisplayString(__props.t.new ?? "Novo"), 1)
                        ]),
                        _: 1
                      }, 8, ["href"]),
                      createVNode("a", {
                        href: __props.urls.pdf,
                        target: "_blank",
                        class: "btn btn-outline-white btn-sm"
                      }, [
                        createVNode("i", { class: "fas fa-file-pdf me-1" }),
                        createTextVNode("PDF ")
                      ], 8, ["href"])
                    ])
                  ])
                ]),
                createVNode("div", { class: "row g-2" }, [
                  createVNode("div", { class: "col-12 col-lg-3 col-xl-2" }, [
                    createVNode("div", { class: "patient-info-sticky" }, [
                      createVNode(PatientInfoSidebar, { patient: __props.patient }, null, 8, ["patient"])
                    ])
                  ]),
                  createVNode("div", { class: "col-12 col-lg-9 col-xl-10" }, [
                    createVNode("div", { class: "card pmr-content-card overflow-hidden bg-white" }, [
                      createVNode("div", { class: "d-flex align-items-center justify-content-between px-3 py-1 pmr-record-strip" }, [
                        createVNode("span", null, [
                          createVNode("i", { class: "fas fa-file-medical-alt me-1" }),
                          createTextVNode(toDisplayString(__props.medicalrecord.code), 1)
                        ]),
                        __props.medicalrecord.is_locked ? (openBlock(), createBlock("span", {
                          key: 0,
                          class: "badge bg-warning text-dark",
                          style: { "font-size": ".65rem" }
                        }, [
                          createVNode("i", { class: "fas fa-lock me-1" }),
                          createTextVNode(toDisplayString(__props.t.locked ?? "Bloqueado"), 1)
                        ])) : createCommentVNode("", true)
                      ]),
                      createVNode(_sfc_main$1, {
                        patient: __props.patient,
                        medicalrecord: __props.medicalrecord,
                        doctors: __props.doctors,
                        "current-doctor-id": __props.currentDoctorId,
                        "can-choose-doctor": __props.canChooseDoctor,
                        "is-doctor": __props.isDoctor,
                        "is-edit": true,
                        catalogs: __props.catalogs,
                        urls: __props.urls,
                        storage: __props.storage,
                        t: __props.t
                      }, null, 8, ["patient", "medicalrecord", "doctors", "current-doctor-id", "can-choose-doctor", "is-doctor", "catalogs", "urls", "storage", "t"])
                    ])
                  ])
                ])
              ])
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`<!--]-->`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/MedicalRecords/Edit.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
