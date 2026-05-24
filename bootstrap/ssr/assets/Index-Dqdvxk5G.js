import { ref, onMounted, computed, mergeProps, withCtx, unref, createVNode, createTextVNode, openBlock, createBlock, createCommentVNode, toDisplayString, Fragment, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderClass, ssrRenderAttr, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { Link } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-CYjDf0Y-.js";
import { A as ActionIconGroup } from "./ActionIconGroup-B8JEjj1z.js";
import { _ as _sfc_main$2 } from "./ActionIconButton-BTsQtzdl.js";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import MedicalRecordDetailDrawer from "./MedicalRecordDetailDrawer-c4pBb2FI.js";
import PatientInfoSidebar from "./PatientInfoSidebar-ChtWAsyC.js";
import _sfc_main$3 from "./PdfPreviewModal-BGdxaBML.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./OffcanvasPanel-VfE7yaou.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    patient: { type: Object, required: true },
    urls: { type: Object, required: true },
    t: { type: Object, default: () => ({}) },
    /** Apenas médicos podem criar/editar prontuário (CFM Res. 2.227/2018). */
    isDoctor: { type: Boolean, default: false }
  },
  setup(__props) {
    const props = __props;
    const records = ref([]);
    const loading = ref(false);
    const loadingMore = ref(false);
    const hasMore = ref(true);
    const nextPage = ref(1);
    const total = ref(0);
    async function loadPage(page = 1) {
      if (page === 1) {
        loading.value = true;
        records.value = [];
      } else {
        loadingMore.value = true;
      }
      try {
        const res = await fetch(`${props.urls.ajax_list}?page=${page}&per_page=10`, {
          headers: { Accept: "application/json" }
        });
        const json = await res.json();
        if (page === 1) records.value = json.data;
        else records.value.push(...json.data);
        hasMore.value = json.has_more;
        nextPage.value = json.next_page;
        total.value = json.total;
      } finally {
        loading.value = false;
        loadingMore.value = false;
      }
    }
    onMounted(() => loadPage(1));
    const detailOpen = ref(false);
    const detailRecord = ref(null);
    function openDetail(record) {
      detailRecord.value = record;
      detailOpen.value = true;
    }
    const pdfPreviewOpen = ref(false);
    const pdfPreviewUrl = ref("");
    const pdfPreviewTitle = ref("");
    function openPdfPreview(record) {
      if (!(record == null ? void 0 : record.pdf_url)) return;
      pdfPreviewUrl.value = record.pdf_url;
      pdfPreviewTitle.value = `Prontuário ${record.code} — ${record.created_at ?? ""}`;
      pdfPreviewOpen.value = true;
    }
    function closePdfPreview() {
      pdfPreviewOpen.value = false;
      pdfPreviewUrl.value = "";
      pdfPreviewTitle.value = "";
    }
    async function onDelete(record) {
      var _a;
      if (record.is_locked) {
        alert("Prontuário assinado/bloqueado — não pode ser excluído.");
        return;
      }
      if (!confirm("Excluir este prontuário?")) return;
      const res = await fetch(record.destroy_url, {
        method: "DELETE",
        headers: {
          "Accept": "application/json",
          "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? ""
        }
      });
      if (res.ok || res.status === 302) {
        if (window.showSuccessToast) window.showSuccessToast("Prontuário removido.");
        loadPage(1);
      } else if (window.showErrorToast) {
        window.showErrorToast("Erro ao remover.");
      }
    }
    const isEmpty = computed(() => !loading.value && records.value.length === 0);
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: __props.t.title ?? "Prontuários",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3 page-medical-records" data-v-dbd2a9ec${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: `${__props.patient.full_name ?? "Paciente"} — ${__props.t.title ?? "Prontuários"}`,
              total: total.value > 0 ? total.value : null
            }, {
              actions: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`<div class="btn-group" role="group" data-v-dbd2a9ec${_scopeId2}>`);
                  _push3(ssrRenderComponent(unref(Link), {
                    href: __props.urls.patients,
                    class: "btn btn-outline-secondary btn-sm"
                  }, {
                    default: withCtx((_3, _push4, _parent4, _scopeId3) => {
                      if (_push4) {
                        _push4(`<i class="ti ti-arrow-left me-1" data-v-dbd2a9ec${_scopeId3}></i>Pacientes `);
                      } else {
                        return [
                          createVNode("i", { class: "ti ti-arrow-left me-1" }),
                          createTextVNode("Pacientes ")
                        ];
                      }
                    }),
                    _: 1
                  }, _parent3, _scopeId2));
                  if (__props.isDoctor) {
                    _push3(ssrRenderComponent(unref(Link), {
                      href: __props.urls.create,
                      class: "btn btn-primary btn-sm"
                    }, {
                      default: withCtx((_3, _push4, _parent4, _scopeId3) => {
                        if (_push4) {
                          _push4(`<i class="ti ti-plus me-1" data-v-dbd2a9ec${_scopeId3}></i>Novo prontuário `);
                        } else {
                          return [
                            createVNode("i", { class: "ti ti-plus me-1" }),
                            createTextVNode("Novo prontuário ")
                          ];
                        }
                      }),
                      _: 1
                    }, _parent3, _scopeId2));
                  } else {
                    _push3(`<!---->`);
                  }
                  _push3(`</div>`);
                } else {
                  return [
                    createVNode("div", {
                      class: "btn-group",
                      role: "group"
                    }, [
                      createVNode(unref(Link), {
                        href: __props.urls.patients,
                        class: "btn btn-outline-secondary btn-sm"
                      }, {
                        default: withCtx(() => [
                          createVNode("i", { class: "ti ti-arrow-left me-1" }),
                          createTextVNode("Pacientes ")
                        ]),
                        _: 1
                      }, 8, ["href"]),
                      __props.isDoctor ? (openBlock(), createBlock(unref(Link), {
                        key: 0,
                        href: __props.urls.create,
                        class: "btn btn-primary btn-sm"
                      }, {
                        default: withCtx(() => [
                          createVNode("i", { class: "ti ti-plus me-1" }),
                          createTextVNode("Novo prontuário ")
                        ]),
                        _: 1
                      }, 8, ["href"])) : createCommentVNode("", true)
                    ])
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`<div class="row g-3" data-v-dbd2a9ec${_scopeId}><div class="col-12 col-md-3 col-lg-2" data-v-dbd2a9ec${_scopeId}><div class="patient-info-sticky" data-v-dbd2a9ec${_scopeId}>`);
            _push2(ssrRenderComponent(PatientInfoSidebar, { patient: __props.patient }, null, _parent2, _scopeId));
            _push2(`</div></div><div class="col-12 col-md-9 col-lg-10" data-v-dbd2a9ec${_scopeId}><div class="card" data-v-dbd2a9ec${_scopeId}><div class="card-body" data-v-dbd2a9ec${_scopeId}>`);
            if (loading.value) {
              _push2(`<div class="text-center py-5" data-v-dbd2a9ec${_scopeId}><div class="spinner-border text-info" data-v-dbd2a9ec${_scopeId}></div></div>`);
            } else if (isEmpty.value) {
              _push2(`<div class="text-center py-5 text-muted" data-v-dbd2a9ec${_scopeId}><i class="ti ti-file-text-ai fs-1 d-block mb-3 opacity-25" data-v-dbd2a9ec${_scopeId}></i><p class="mb-3" data-v-dbd2a9ec${_scopeId}>${ssrInterpolate(__props.t.no_records ?? "Nenhum prontuário cadastrado.")}</p>`);
              if (__props.isDoctor) {
                _push2(ssrRenderComponent(unref(Link), {
                  href: __props.urls.create,
                  class: "btn btn-primary btn-sm"
                }, {
                  default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                    if (_push3) {
                      _push3(`<i class="ti ti-plus me-1" data-v-dbd2a9ec${_scopeId2}></i>Criar primeiro prontuário `);
                    } else {
                      return [
                        createVNode("i", { class: "ti ti-plus me-1" }),
                        createTextVNode("Criar primeiro prontuário ")
                      ];
                    }
                  }),
                  _: 1
                }, _parent2, _scopeId));
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div>`);
            } else {
              _push2(`<div class="medical-record-timeline" data-v-dbd2a9ec${_scopeId}><!--[-->`);
              ssrRenderList(records.value, (record) => {
                var _a;
                _push2(`<div class="timeline-item" data-v-dbd2a9ec${_scopeId}><div class="d-flex gap-3" data-v-dbd2a9ec${_scopeId}><div class="timeline-marker" data-v-dbd2a9ec${_scopeId}><i class="${ssrRenderClass(`ti ${record.is_signed ? "ti-shield-check-filled text-success" : "ti-file-text text-info"} fs-4`)}" data-v-dbd2a9ec${_scopeId}></i></div><div class="flex-grow-1 timeline-content" data-v-dbd2a9ec${_scopeId}><div class="card border-0 shadow-sm" data-v-dbd2a9ec${_scopeId}><div class="card-body" data-v-dbd2a9ec${_scopeId}><div class="d-flex justify-content-between align-items-start mb-2" data-v-dbd2a9ec${_scopeId}><div data-v-dbd2a9ec${_scopeId}><h6 class="mb-0 fw-semibold" data-v-dbd2a9ec${_scopeId}><code class="small text-muted me-2" data-v-dbd2a9ec${_scopeId}>${ssrInterpolate(record.code)}</code> ${ssrInterpolate(record.created_at)}</h6>`);
                if (record.doctor_name) {
                  _push2(`<small class="text-muted" data-v-dbd2a9ec${_scopeId}><i class="ti ti-stethoscope me-1" data-v-dbd2a9ec${_scopeId}></i>${ssrInterpolate(record.doctor_name)}</small>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</div><div class="d-flex gap-1" data-v-dbd2a9ec${_scopeId}>`);
                if (record.is_signed) {
                  _push2(`<span class="badge badge-soft-success rounded text-success border border-success fs-11"${ssrRenderAttr("title", `Assinado em ${record.signed_at}`)} data-v-dbd2a9ec${_scopeId}><i class="ti ti-shield-check me-1" data-v-dbd2a9ec${_scopeId}></i>Assinado </span>`);
                } else {
                  _push2(`<!---->`);
                }
                if (record.documentations_count > 0) {
                  _push2(`<span class="badge badge-soft-info rounded fs-11" data-v-dbd2a9ec${_scopeId}><i class="ti ti-paperclip me-1" data-v-dbd2a9ec${_scopeId}></i>${ssrInterpolate(record.documentations_count)} doc(s) </span>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</div></div>`);
                if (record.main_complaint) {
                  _push2(`<div class="mb-2 small" data-v-dbd2a9ec${_scopeId}><strong class="text-muted" data-v-dbd2a9ec${_scopeId}>Queixa:</strong> ${ssrInterpolate(record.main_complaint)}</div>`);
                } else {
                  _push2(`<!---->`);
                }
                if (record.clinical_conduct) {
                  _push2(`<div class="mb-2 small" data-v-dbd2a9ec${_scopeId}><strong class="text-muted" data-v-dbd2a9ec${_scopeId}>Conduta:</strong> ${ssrInterpolate(record.clinical_conduct)}</div>`);
                } else {
                  _push2(`<!---->`);
                }
                if (((_a = record.diagnosis_cids) == null ? void 0 : _a.length) > 0) {
                  _push2(`<div class="mb-2" data-v-dbd2a9ec${_scopeId}><!--[-->`);
                  ssrRenderList(record.diagnosis_cids, (cid, idx) => {
                    _push2(`<span class="badge badge-soft-secondary me-1 fs-11" data-v-dbd2a9ec${_scopeId}>${ssrInterpolate(typeof cid === "object" ? `${cid.code} ${cid.description ?? ""}` : cid)}</span>`);
                  });
                  _push2(`<!--]--></div>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`<div class="d-flex justify-content-end mt-3" data-v-dbd2a9ec${_scopeId}>`);
                _push2(ssrRenderComponent(ActionIconGroup, {
                  align: "end",
                  gap: "tight"
                }, {
                  default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                    if (_push3) {
                      _push3(ssrRenderComponent(_sfc_main$2, {
                        icon: "ti ti-eye",
                        title: "Ver detalhes",
                        variant: "default",
                        onClick: ($event) => openDetail(record)
                      }, null, _parent3, _scopeId2));
                      if (__props.isDoctor) {
                        _push3(ssrRenderComponent(_sfc_main$2, {
                          icon: record.is_locked ? "ti ti-lock" : "ti ti-edit",
                          title: record.is_locked ? "Visualizar (assinado)" : "Editar prontuário",
                          href: record.edit_url,
                          variant: "default"
                        }, null, _parent3, _scopeId2));
                      } else {
                        _push3(`<!---->`);
                      }
                      _push3(ssrRenderComponent(ActionDropdown, {
                        "btn-class": "ee-action-icon ee-action-icon--default",
                        icon: "ti ti-dots-vertical",
                        align: "right"
                      }, {
                        default: withCtx((_3, _push4, _parent4, _scopeId3) => {
                          if (_push4) {
                            _push4(`<li data-v-dbd2a9ec${_scopeId3}><button type="button" class="dropdown-item" data-v-dbd2a9ec${_scopeId3}><i class="ti ti-file-search me-2 text-secondary" data-v-dbd2a9ec${_scopeId3}></i>Visualizar PDF </button></li>`);
                            if (__props.isDoctor && !record.is_locked) {
                              _push4(`<li data-v-dbd2a9ec${_scopeId3}><hr class="dropdown-divider" data-v-dbd2a9ec${_scopeId3}></li>`);
                            } else {
                              _push4(`<!---->`);
                            }
                            if (__props.isDoctor && !record.is_locked) {
                              _push4(`<li data-v-dbd2a9ec${_scopeId3}><button type="button" class="dropdown-item text-danger" data-v-dbd2a9ec${_scopeId3}><i class="ti ti-trash me-2" data-v-dbd2a9ec${_scopeId3}></i>Excluir </button></li>`);
                            } else {
                              _push4(`<!---->`);
                            }
                          } else {
                            return [
                              createVNode("li", null, [
                                createVNode("button", {
                                  type: "button",
                                  class: "dropdown-item",
                                  onClick: ($event) => openPdfPreview(record)
                                }, [
                                  createVNode("i", { class: "ti ti-file-search me-2 text-secondary" }),
                                  createTextVNode("Visualizar PDF ")
                                ], 8, ["onClick"])
                              ]),
                              __props.isDoctor && !record.is_locked ? (openBlock(), createBlock("li", { key: 0 }, [
                                createVNode("hr", { class: "dropdown-divider" })
                              ])) : createCommentVNode("", true),
                              __props.isDoctor && !record.is_locked ? (openBlock(), createBlock("li", { key: 1 }, [
                                createVNode("button", {
                                  type: "button",
                                  class: "dropdown-item text-danger",
                                  onClick: ($event) => onDelete(record)
                                }, [
                                  createVNode("i", { class: "ti ti-trash me-2" }),
                                  createTextVNode("Excluir ")
                                ], 8, ["onClick"])
                              ])) : createCommentVNode("", true)
                            ];
                          }
                        }),
                        _: 2
                      }, _parent3, _scopeId2));
                    } else {
                      return [
                        createVNode(_sfc_main$2, {
                          icon: "ti ti-eye",
                          title: "Ver detalhes",
                          variant: "default",
                          onClick: ($event) => openDetail(record)
                        }, null, 8, ["onClick"]),
                        __props.isDoctor ? (openBlock(), createBlock(_sfc_main$2, {
                          key: 0,
                          icon: record.is_locked ? "ti ti-lock" : "ti ti-edit",
                          title: record.is_locked ? "Visualizar (assinado)" : "Editar prontuário",
                          href: record.edit_url,
                          variant: "default"
                        }, null, 8, ["icon", "title", "href"])) : createCommentVNode("", true),
                        createVNode(ActionDropdown, {
                          "btn-class": "ee-action-icon ee-action-icon--default",
                          icon: "ti ti-dots-vertical",
                          align: "right"
                        }, {
                          default: withCtx(() => [
                            createVNode("li", null, [
                              createVNode("button", {
                                type: "button",
                                class: "dropdown-item",
                                onClick: ($event) => openPdfPreview(record)
                              }, [
                                createVNode("i", { class: "ti ti-file-search me-2 text-secondary" }),
                                createTextVNode("Visualizar PDF ")
                              ], 8, ["onClick"])
                            ]),
                            __props.isDoctor && !record.is_locked ? (openBlock(), createBlock("li", { key: 0 }, [
                              createVNode("hr", { class: "dropdown-divider" })
                            ])) : createCommentVNode("", true),
                            __props.isDoctor && !record.is_locked ? (openBlock(), createBlock("li", { key: 1 }, [
                              createVNode("button", {
                                type: "button",
                                class: "dropdown-item text-danger",
                                onClick: ($event) => onDelete(record)
                              }, [
                                createVNode("i", { class: "ti ti-trash me-2" }),
                                createTextVNode("Excluir ")
                              ], 8, ["onClick"])
                            ])) : createCommentVNode("", true)
                          ]),
                          _: 2
                        }, 1024)
                      ];
                    }
                  }),
                  _: 2
                }, _parent2, _scopeId));
                _push2(`</div></div></div></div></div></div>`);
              });
              _push2(`<!--]-->`);
              if (hasMore.value) {
                _push2(`<div class="text-center py-3" data-v-dbd2a9ec${_scopeId}><button class="btn btn-outline-secondary btn-sm"${ssrIncludeBooleanAttr(loadingMore.value) ? " disabled" : ""} data-v-dbd2a9ec${_scopeId}>`);
                if (loadingMore.value) {
                  _push2(`<span class="spinner-border spinner-border-sm me-1" data-v-dbd2a9ec${_scopeId}></span>`);
                } else {
                  _push2(`<i class="ti ti-chevron-down me-1" data-v-dbd2a9ec${_scopeId}></i>`);
                }
                _push2(` Carregar mais </button></div>`);
              } else if (!isEmpty.value) {
                _push2(`<div class="text-center py-3 text-muted small" data-v-dbd2a9ec${_scopeId}><i class="ti ti-check me-1 text-success" data-v-dbd2a9ec${_scopeId}></i>Fim da lista </div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div>`);
            }
            _push2(`</div></div></div></div>`);
            _push2(ssrRenderComponent(MedicalRecordDetailDrawer, {
              open: detailOpen.value,
              record: detailRecord.value,
              patient: __props.patient,
              onClose: ($event) => detailOpen.value = false
            }, null, _parent2, _scopeId));
            if (pdfPreviewOpen.value) {
              _push2(ssrRenderComponent(_sfc_main$3, {
                url: pdfPreviewUrl.value,
                title: pdfPreviewTitle.value,
                filename: `prontuario-${__props.patient.code ?? "paciente"}`,
                onClose: closePdfPreview
              }, null, _parent2, _scopeId));
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3 page-medical-records" }, [
                createVNode(_sfc_main$1, {
                  title: `${__props.patient.full_name ?? "Paciente"} — ${__props.t.title ?? "Prontuários"}`,
                  total: total.value > 0 ? total.value : null
                }, {
                  actions: withCtx(() => [
                    createVNode("div", {
                      class: "btn-group",
                      role: "group"
                    }, [
                      createVNode(unref(Link), {
                        href: __props.urls.patients,
                        class: "btn btn-outline-secondary btn-sm"
                      }, {
                        default: withCtx(() => [
                          createVNode("i", { class: "ti ti-arrow-left me-1" }),
                          createTextVNode("Pacientes ")
                        ]),
                        _: 1
                      }, 8, ["href"]),
                      __props.isDoctor ? (openBlock(), createBlock(unref(Link), {
                        key: 0,
                        href: __props.urls.create,
                        class: "btn btn-primary btn-sm"
                      }, {
                        default: withCtx(() => [
                          createVNode("i", { class: "ti ti-plus me-1" }),
                          createTextVNode("Novo prontuário ")
                        ]),
                        _: 1
                      }, 8, ["href"])) : createCommentVNode("", true)
                    ])
                  ]),
                  _: 1
                }, 8, ["title", "total"]),
                createVNode("div", { class: "row g-3" }, [
                  createVNode("div", { class: "col-12 col-md-3 col-lg-2" }, [
                    createVNode("div", { class: "patient-info-sticky" }, [
                      createVNode(PatientInfoSidebar, { patient: __props.patient }, null, 8, ["patient"])
                    ])
                  ]),
                  createVNode("div", { class: "col-12 col-md-9 col-lg-10" }, [
                    createVNode("div", { class: "card" }, [
                      createVNode("div", { class: "card-body" }, [
                        loading.value ? (openBlock(), createBlock("div", {
                          key: 0,
                          class: "text-center py-5"
                        }, [
                          createVNode("div", { class: "spinner-border text-info" })
                        ])) : isEmpty.value ? (openBlock(), createBlock("div", {
                          key: 1,
                          class: "text-center py-5 text-muted"
                        }, [
                          createVNode("i", { class: "ti ti-file-text-ai fs-1 d-block mb-3 opacity-25" }),
                          createVNode("p", { class: "mb-3" }, toDisplayString(__props.t.no_records ?? "Nenhum prontuário cadastrado."), 1),
                          __props.isDoctor ? (openBlock(), createBlock(unref(Link), {
                            key: 0,
                            href: __props.urls.create,
                            class: "btn btn-primary btn-sm"
                          }, {
                            default: withCtx(() => [
                              createVNode("i", { class: "ti ti-plus me-1" }),
                              createTextVNode("Criar primeiro prontuário ")
                            ]),
                            _: 1
                          }, 8, ["href"])) : createCommentVNode("", true)
                        ])) : (openBlock(), createBlock("div", {
                          key: 2,
                          class: "medical-record-timeline"
                        }, [
                          (openBlock(true), createBlock(Fragment, null, renderList(records.value, (record) => {
                            var _a;
                            return openBlock(), createBlock("div", {
                              key: record.id,
                              class: "timeline-item"
                            }, [
                              createVNode("div", { class: "d-flex gap-3" }, [
                                createVNode("div", { class: "timeline-marker" }, [
                                  createVNode("i", {
                                    class: `ti ${record.is_signed ? "ti-shield-check-filled text-success" : "ti-file-text text-info"} fs-4`
                                  }, null, 2)
                                ]),
                                createVNode("div", { class: "flex-grow-1 timeline-content" }, [
                                  createVNode("div", { class: "card border-0 shadow-sm" }, [
                                    createVNode("div", { class: "card-body" }, [
                                      createVNode("div", { class: "d-flex justify-content-between align-items-start mb-2" }, [
                                        createVNode("div", null, [
                                          createVNode("h6", { class: "mb-0 fw-semibold" }, [
                                            createVNode("code", { class: "small text-muted me-2" }, toDisplayString(record.code), 1),
                                            createTextVNode(" " + toDisplayString(record.created_at), 1)
                                          ]),
                                          record.doctor_name ? (openBlock(), createBlock("small", {
                                            key: 0,
                                            class: "text-muted"
                                          }, [
                                            createVNode("i", { class: "ti ti-stethoscope me-1" }),
                                            createTextVNode(toDisplayString(record.doctor_name), 1)
                                          ])) : createCommentVNode("", true)
                                        ]),
                                        createVNode("div", { class: "d-flex gap-1" }, [
                                          record.is_signed ? (openBlock(), createBlock("span", {
                                            key: 0,
                                            class: "badge badge-soft-success rounded text-success border border-success fs-11",
                                            title: `Assinado em ${record.signed_at}`
                                          }, [
                                            createVNode("i", { class: "ti ti-shield-check me-1" }),
                                            createTextVNode("Assinado ")
                                          ], 8, ["title"])) : createCommentVNode("", true),
                                          record.documentations_count > 0 ? (openBlock(), createBlock("span", {
                                            key: 1,
                                            class: "badge badge-soft-info rounded fs-11"
                                          }, [
                                            createVNode("i", { class: "ti ti-paperclip me-1" }),
                                            createTextVNode(toDisplayString(record.documentations_count) + " doc(s) ", 1)
                                          ])) : createCommentVNode("", true)
                                        ])
                                      ]),
                                      record.main_complaint ? (openBlock(), createBlock("div", {
                                        key: 0,
                                        class: "mb-2 small"
                                      }, [
                                        createVNode("strong", { class: "text-muted" }, "Queixa:"),
                                        createTextVNode(" " + toDisplayString(record.main_complaint), 1)
                                      ])) : createCommentVNode("", true),
                                      record.clinical_conduct ? (openBlock(), createBlock("div", {
                                        key: 1,
                                        class: "mb-2 small"
                                      }, [
                                        createVNode("strong", { class: "text-muted" }, "Conduta:"),
                                        createTextVNode(" " + toDisplayString(record.clinical_conduct), 1)
                                      ])) : createCommentVNode("", true),
                                      ((_a = record.diagnosis_cids) == null ? void 0 : _a.length) > 0 ? (openBlock(), createBlock("div", {
                                        key: 2,
                                        class: "mb-2"
                                      }, [
                                        (openBlock(true), createBlock(Fragment, null, renderList(record.diagnosis_cids, (cid, idx) => {
                                          return openBlock(), createBlock("span", {
                                            key: idx,
                                            class: "badge badge-soft-secondary me-1 fs-11"
                                          }, toDisplayString(typeof cid === "object" ? `${cid.code} ${cid.description ?? ""}` : cid), 1);
                                        }), 128))
                                      ])) : createCommentVNode("", true),
                                      createVNode("div", { class: "d-flex justify-content-end mt-3" }, [
                                        createVNode(ActionIconGroup, {
                                          align: "end",
                                          gap: "tight"
                                        }, {
                                          default: withCtx(() => [
                                            createVNode(_sfc_main$2, {
                                              icon: "ti ti-eye",
                                              title: "Ver detalhes",
                                              variant: "default",
                                              onClick: ($event) => openDetail(record)
                                            }, null, 8, ["onClick"]),
                                            __props.isDoctor ? (openBlock(), createBlock(_sfc_main$2, {
                                              key: 0,
                                              icon: record.is_locked ? "ti ti-lock" : "ti ti-edit",
                                              title: record.is_locked ? "Visualizar (assinado)" : "Editar prontuário",
                                              href: record.edit_url,
                                              variant: "default"
                                            }, null, 8, ["icon", "title", "href"])) : createCommentVNode("", true),
                                            createVNode(ActionDropdown, {
                                              "btn-class": "ee-action-icon ee-action-icon--default",
                                              icon: "ti ti-dots-vertical",
                                              align: "right"
                                            }, {
                                              default: withCtx(() => [
                                                createVNode("li", null, [
                                                  createVNode("button", {
                                                    type: "button",
                                                    class: "dropdown-item",
                                                    onClick: ($event) => openPdfPreview(record)
                                                  }, [
                                                    createVNode("i", { class: "ti ti-file-search me-2 text-secondary" }),
                                                    createTextVNode("Visualizar PDF ")
                                                  ], 8, ["onClick"])
                                                ]),
                                                __props.isDoctor && !record.is_locked ? (openBlock(), createBlock("li", { key: 0 }, [
                                                  createVNode("hr", { class: "dropdown-divider" })
                                                ])) : createCommentVNode("", true),
                                                __props.isDoctor && !record.is_locked ? (openBlock(), createBlock("li", { key: 1 }, [
                                                  createVNode("button", {
                                                    type: "button",
                                                    class: "dropdown-item text-danger",
                                                    onClick: ($event) => onDelete(record)
                                                  }, [
                                                    createVNode("i", { class: "ti ti-trash me-2" }),
                                                    createTextVNode("Excluir ")
                                                  ], 8, ["onClick"])
                                                ])) : createCommentVNode("", true)
                                              ]),
                                              _: 2
                                            }, 1024)
                                          ]),
                                          _: 2
                                        }, 1024)
                                      ])
                                    ])
                                  ])
                                ])
                              ])
                            ]);
                          }), 128)),
                          hasMore.value ? (openBlock(), createBlock("div", {
                            key: 0,
                            class: "text-center py-3"
                          }, [
                            createVNode("button", {
                              class: "btn btn-outline-secondary btn-sm",
                              disabled: loadingMore.value,
                              onClick: ($event) => loadPage(nextPage.value)
                            }, [
                              loadingMore.value ? (openBlock(), createBlock("span", {
                                key: 0,
                                class: "spinner-border spinner-border-sm me-1"
                              })) : (openBlock(), createBlock("i", {
                                key: 1,
                                class: "ti ti-chevron-down me-1"
                              })),
                              createTextVNode(" Carregar mais ")
                            ], 8, ["disabled", "onClick"])
                          ])) : !isEmpty.value ? (openBlock(), createBlock("div", {
                            key: 1,
                            class: "text-center py-3 text-muted small"
                          }, [
                            createVNode("i", { class: "ti ti-check me-1 text-success" }),
                            createTextVNode("Fim da lista ")
                          ])) : createCommentVNode("", true)
                        ]))
                      ])
                    ])
                  ])
                ]),
                createVNode(MedicalRecordDetailDrawer, {
                  open: detailOpen.value,
                  record: detailRecord.value,
                  patient: __props.patient,
                  onClose: ($event) => detailOpen.value = false
                }, null, 8, ["open", "record", "patient", "onClose"]),
                pdfPreviewOpen.value ? (openBlock(), createBlock(_sfc_main$3, {
                  key: 0,
                  url: pdfPreviewUrl.value,
                  title: pdfPreviewTitle.value,
                  filename: `prontuario-${__props.patient.code ?? "paciente"}`,
                  onClose: closePdfPreview
                }, null, 8, ["url", "title", "filename"])) : createCommentVNode("", true)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/MedicalRecords/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const Index = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-dbd2a9ec"]]);
export {
  Index as default
};
