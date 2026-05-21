import { ref, onMounted, computed, mergeProps, withCtx, unref, createVNode, createTextVNode, toDisplayString, openBlock, createBlock, createCommentVNode, Fragment, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderStyle, ssrInterpolate, ssrRenderList, ssrRenderClass, ssrRenderAttr, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { Link } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import MedicalRecordDetailDrawer from "./MedicalRecordDetailDrawer-BqvEorTq.js";
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
    t: { type: Object, default: () => ({}) }
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
            _push2(`<div class="container-fluid py-3" data-v-cc9a9c05${_scopeId}><div class="row g-3" data-v-cc9a9c05${_scopeId}><div class="col-12 col-md-3 col-lg-2" data-v-cc9a9c05${_scopeId}><div class="card patient-info-sticky" data-v-cc9a9c05${_scopeId}><div class="card-body p-3" data-v-cc9a9c05${_scopeId}><div class="text-center mb-3" data-v-cc9a9c05${_scopeId}><div class="avatar avatar-xl bg-info-subtle rounded-circle mx-auto mb-2 d-flex align-items-center justify-content-center" style="${ssrRenderStyle({ "width": "80px", "height": "80px" })}" data-v-cc9a9c05${_scopeId}><i class="ti ti-user fs-1 text-info" data-v-cc9a9c05${_scopeId}></i></div><h6 class="fw-semibold mb-0" data-v-cc9a9c05${_scopeId}>${ssrInterpolate(__props.patient.full_name)}</h6><code class="small text-muted" data-v-cc9a9c05${_scopeId}>${ssrInterpolate(__props.patient.code)}</code></div><div class="small" data-v-cc9a9c05${_scopeId}>`);
            if (__props.patient.age) {
              _push2(`<div class="mb-2" data-v-cc9a9c05${_scopeId}><i class="ti ti-cake me-1 text-muted" data-v-cc9a9c05${_scopeId}></i> ${ssrInterpolate(__props.patient.age)} anos `);
              if (__props.patient.birth_date) {
                _push2(`<span class="text-muted" data-v-cc9a9c05${_scopeId}>(${ssrInterpolate(__props.patient.birth_date)})</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div>`);
            } else {
              _push2(`<!---->`);
            }
            if (__props.patient.gender) {
              _push2(`<div class="mb-2" data-v-cc9a9c05${_scopeId}><i class="ti ti-gender-bigender me-1 text-muted" data-v-cc9a9c05${_scopeId}></i> ${ssrInterpolate(__props.patient.gender)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            if (__props.patient.cpf) {
              _push2(`<div class="mb-2" data-v-cc9a9c05${_scopeId}><i class="ti ti-id me-1 text-muted" data-v-cc9a9c05${_scopeId}></i><code data-v-cc9a9c05${_scopeId}>${ssrInterpolate(__props.patient.cpf)}</code></div>`);
            } else {
              _push2(`<!---->`);
            }
            if (__props.patient.phone) {
              _push2(`<div class="mb-2" data-v-cc9a9c05${_scopeId}><i class="ti ti-phone me-1 text-muted" data-v-cc9a9c05${_scopeId}></i> ${ssrInterpolate(__props.patient.phone)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            if (__props.patient.email) {
              _push2(`<div class="mb-2 text-break" data-v-cc9a9c05${_scopeId}><i class="ti ti-mail me-1 text-muted" data-v-cc9a9c05${_scopeId}></i> ${ssrInterpolate(__props.patient.email)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            if (__props.patient.covenant_name) {
              _push2(`<div class="mb-2" data-v-cc9a9c05${_scopeId}><i class="ti ti-medical-cross me-1 text-muted" data-v-cc9a9c05${_scopeId}></i> ${ssrInterpolate(__props.patient.covenant_name)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            if (__props.patient.skin_type) {
              _push2(`<div class="mb-2" data-v-cc9a9c05${_scopeId}><small class="text-muted" data-v-cc9a9c05${_scopeId}>Pele:</small> ${ssrInterpolate(__props.patient.skin_type)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            if (__props.patient.iris_type) {
              _push2(`<div class="mb-2" data-v-cc9a9c05${_scopeId}><small class="text-muted" data-v-cc9a9c05${_scopeId}>Íris:</small> ${ssrInterpolate(__props.patient.iris_type)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div></div></div><div class="col-12 col-md-9 col-lg-10" data-v-cc9a9c05${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: __props.t.title ?? "Prontuários",
              subtitle: total.value > 0 ? `${total.value} registros` : ""
            }, {
              actions: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(ssrRenderComponent(unref(Link), {
                    href: __props.urls.patients,
                    class: "btn btn-outline-secondary btn-sm"
                  }, {
                    default: withCtx((_3, _push4, _parent4, _scopeId3) => {
                      if (_push4) {
                        _push4(`<i class="ti ti-arrow-left me-1" data-v-cc9a9c05${_scopeId3}></i>Pacientes `);
                      } else {
                        return [
                          createVNode("i", { class: "ti ti-arrow-left me-1" }),
                          createTextVNode("Pacientes ")
                        ];
                      }
                    }),
                    _: 1
                  }, _parent3, _scopeId2));
                  _push3(ssrRenderComponent(unref(Link), {
                    href: __props.urls.create,
                    class: "btn btn-primary btn-sm"
                  }, {
                    default: withCtx((_3, _push4, _parent4, _scopeId3) => {
                      if (_push4) {
                        _push4(`<i class="ti ti-plus me-1" data-v-cc9a9c05${_scopeId3}></i>Novo prontuário `);
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
                  return [
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
                    createVNode(unref(Link), {
                      href: __props.urls.create,
                      class: "btn btn-primary btn-sm"
                    }, {
                      default: withCtx(() => [
                        createVNode("i", { class: "ti ti-plus me-1" }),
                        createTextVNode("Novo prontuário ")
                      ]),
                      _: 1
                    }, 8, ["href"])
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`<div class="card" data-v-cc9a9c05${_scopeId}><div class="card-body" data-v-cc9a9c05${_scopeId}>`);
            if (loading.value) {
              _push2(`<div class="text-center py-5" data-v-cc9a9c05${_scopeId}><div class="spinner-border text-info" data-v-cc9a9c05${_scopeId}></div></div>`);
            } else if (isEmpty.value) {
              _push2(`<div class="text-center py-5 text-muted" data-v-cc9a9c05${_scopeId}><i class="ti ti-file-text-ai fs-1 d-block mb-3 opacity-25" data-v-cc9a9c05${_scopeId}></i><p class="mb-3" data-v-cc9a9c05${_scopeId}>${ssrInterpolate(__props.t.no_records ?? "Nenhum prontuário cadastrado.")}</p>`);
              _push2(ssrRenderComponent(unref(Link), {
                href: __props.urls.create,
                class: "btn btn-primary btn-sm"
              }, {
                default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                  if (_push3) {
                    _push3(`<i class="ti ti-plus me-1" data-v-cc9a9c05${_scopeId2}></i>Criar primeiro prontuário `);
                  } else {
                    return [
                      createVNode("i", { class: "ti ti-plus me-1" }),
                      createTextVNode("Criar primeiro prontuário ")
                    ];
                  }
                }),
                _: 1
              }, _parent2, _scopeId));
              _push2(`</div>`);
            } else {
              _push2(`<div class="medical-record-timeline" data-v-cc9a9c05${_scopeId}><!--[-->`);
              ssrRenderList(records.value, (record) => {
                var _a;
                _push2(`<div class="timeline-item" data-v-cc9a9c05${_scopeId}><div class="d-flex gap-3" data-v-cc9a9c05${_scopeId}><div class="timeline-marker" data-v-cc9a9c05${_scopeId}><i class="${ssrRenderClass(`ti ${record.is_signed ? "ti-shield-check-filled text-success" : "ti-file-text text-info"} fs-4`)}" data-v-cc9a9c05${_scopeId}></i></div><div class="flex-grow-1 timeline-content" data-v-cc9a9c05${_scopeId}><div class="card border-0 shadow-sm" data-v-cc9a9c05${_scopeId}><div class="card-body" data-v-cc9a9c05${_scopeId}><div class="d-flex justify-content-between align-items-start mb-2" data-v-cc9a9c05${_scopeId}><div data-v-cc9a9c05${_scopeId}><h6 class="mb-0 fw-semibold" data-v-cc9a9c05${_scopeId}><code class="small text-muted me-2" data-v-cc9a9c05${_scopeId}>${ssrInterpolate(record.code)}</code> ${ssrInterpolate(record.created_at)}</h6>`);
                if (record.doctor_name) {
                  _push2(`<small class="text-muted" data-v-cc9a9c05${_scopeId}><i class="ti ti-stethoscope me-1" data-v-cc9a9c05${_scopeId}></i>${ssrInterpolate(record.doctor_name)}</small>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</div><div class="d-flex gap-1" data-v-cc9a9c05${_scopeId}>`);
                if (record.is_signed) {
                  _push2(`<span class="badge badge-soft-success rounded text-success border border-success fs-11"${ssrRenderAttr("title", `Assinado em ${record.signed_at}`)} data-v-cc9a9c05${_scopeId}><i class="ti ti-shield-check me-1" data-v-cc9a9c05${_scopeId}></i>Assinado </span>`);
                } else {
                  _push2(`<!---->`);
                }
                if (record.documentations_count > 0) {
                  _push2(`<span class="badge badge-soft-info rounded fs-11" data-v-cc9a9c05${_scopeId}><i class="ti ti-paperclip me-1" data-v-cc9a9c05${_scopeId}></i>${ssrInterpolate(record.documentations_count)} doc(s) </span>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</div></div>`);
                if (record.main_complaint) {
                  _push2(`<div class="mb-2 small" data-v-cc9a9c05${_scopeId}><strong class="text-muted" data-v-cc9a9c05${_scopeId}>Queixa:</strong> ${ssrInterpolate(record.main_complaint)}</div>`);
                } else {
                  _push2(`<!---->`);
                }
                if (record.clinical_conduct) {
                  _push2(`<div class="mb-2 small" data-v-cc9a9c05${_scopeId}><strong class="text-muted" data-v-cc9a9c05${_scopeId}>Conduta:</strong> ${ssrInterpolate(record.clinical_conduct)}</div>`);
                } else {
                  _push2(`<!---->`);
                }
                if (((_a = record.diagnosis_cids) == null ? void 0 : _a.length) > 0) {
                  _push2(`<div class="mb-2" data-v-cc9a9c05${_scopeId}><!--[-->`);
                  ssrRenderList(record.diagnosis_cids, (cid, idx) => {
                    _push2(`<span class="badge badge-soft-secondary me-1 fs-11" data-v-cc9a9c05${_scopeId}>${ssrInterpolate(typeof cid === "object" ? `${cid.code} ${cid.description ?? ""}` : cid)}</span>`);
                  });
                  _push2(`<!--]--></div>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`<div class="d-flex gap-1 mt-3" data-v-cc9a9c05${_scopeId}><button class="btn btn-sm btn-outline-primary" data-v-cc9a9c05${_scopeId}><i class="ti ti-eye me-1" data-v-cc9a9c05${_scopeId}></i>Ver detalhes </button><a${ssrRenderAttr("href", record.edit_url)} class="btn btn-sm btn-outline-secondary" data-v-cc9a9c05${_scopeId}><i class="ti ti-edit me-1" data-v-cc9a9c05${_scopeId}></i>${ssrInterpolate(record.is_locked ? "Ver" : "Editar")}</a><a${ssrRenderAttr("href", record.pdf_url)} target="_blank" class="btn btn-sm btn-outline-secondary" data-v-cc9a9c05${_scopeId}><i class="ti ti-file-download me-1" data-v-cc9a9c05${_scopeId}></i>PDF </a>`);
                if (!record.is_locked) {
                  _push2(`<button class="btn btn-sm btn-outline-danger ms-auto" data-v-cc9a9c05${_scopeId}><i class="ti ti-trash" data-v-cc9a9c05${_scopeId}></i></button>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</div></div></div></div></div></div>`);
              });
              _push2(`<!--]-->`);
              if (hasMore.value) {
                _push2(`<div class="text-center py-3" data-v-cc9a9c05${_scopeId}><button class="btn btn-outline-secondary btn-sm"${ssrIncludeBooleanAttr(loadingMore.value) ? " disabled" : ""} data-v-cc9a9c05${_scopeId}>`);
                if (loadingMore.value) {
                  _push2(`<span class="spinner-border spinner-border-sm me-1" data-v-cc9a9c05${_scopeId}></span>`);
                } else {
                  _push2(`<i class="ti ti-chevron-down me-1" data-v-cc9a9c05${_scopeId}></i>`);
                }
                _push2(` Carregar mais </button></div>`);
              } else if (!isEmpty.value) {
                _push2(`<div class="text-center py-3 text-muted small" data-v-cc9a9c05${_scopeId}><i class="ti ti-check me-1 text-success" data-v-cc9a9c05${_scopeId}></i>Fim da lista </div>`);
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
            _push2(`</div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode("div", { class: "row g-3" }, [
                  createVNode("div", { class: "col-12 col-md-3 col-lg-2" }, [
                    createVNode("div", { class: "card patient-info-sticky" }, [
                      createVNode("div", { class: "card-body p-3" }, [
                        createVNode("div", { class: "text-center mb-3" }, [
                          createVNode("div", {
                            class: "avatar avatar-xl bg-info-subtle rounded-circle mx-auto mb-2 d-flex align-items-center justify-content-center",
                            style: { "width": "80px", "height": "80px" }
                          }, [
                            createVNode("i", { class: "ti ti-user fs-1 text-info" })
                          ]),
                          createVNode("h6", { class: "fw-semibold mb-0" }, toDisplayString(__props.patient.full_name), 1),
                          createVNode("code", { class: "small text-muted" }, toDisplayString(__props.patient.code), 1)
                        ]),
                        createVNode("div", { class: "small" }, [
                          __props.patient.age ? (openBlock(), createBlock("div", {
                            key: 0,
                            class: "mb-2"
                          }, [
                            createVNode("i", { class: "ti ti-cake me-1 text-muted" }),
                            createTextVNode(" " + toDisplayString(__props.patient.age) + " anos ", 1),
                            __props.patient.birth_date ? (openBlock(), createBlock("span", {
                              key: 0,
                              class: "text-muted"
                            }, "(" + toDisplayString(__props.patient.birth_date) + ")", 1)) : createCommentVNode("", true)
                          ])) : createCommentVNode("", true),
                          __props.patient.gender ? (openBlock(), createBlock("div", {
                            key: 1,
                            class: "mb-2"
                          }, [
                            createVNode("i", { class: "ti ti-gender-bigender me-1 text-muted" }),
                            createTextVNode(" " + toDisplayString(__props.patient.gender), 1)
                          ])) : createCommentVNode("", true),
                          __props.patient.cpf ? (openBlock(), createBlock("div", {
                            key: 2,
                            class: "mb-2"
                          }, [
                            createVNode("i", { class: "ti ti-id me-1 text-muted" }),
                            createVNode("code", null, toDisplayString(__props.patient.cpf), 1)
                          ])) : createCommentVNode("", true),
                          __props.patient.phone ? (openBlock(), createBlock("div", {
                            key: 3,
                            class: "mb-2"
                          }, [
                            createVNode("i", { class: "ti ti-phone me-1 text-muted" }),
                            createTextVNode(" " + toDisplayString(__props.patient.phone), 1)
                          ])) : createCommentVNode("", true),
                          __props.patient.email ? (openBlock(), createBlock("div", {
                            key: 4,
                            class: "mb-2 text-break"
                          }, [
                            createVNode("i", { class: "ti ti-mail me-1 text-muted" }),
                            createTextVNode(" " + toDisplayString(__props.patient.email), 1)
                          ])) : createCommentVNode("", true),
                          __props.patient.covenant_name ? (openBlock(), createBlock("div", {
                            key: 5,
                            class: "mb-2"
                          }, [
                            createVNode("i", { class: "ti ti-medical-cross me-1 text-muted" }),
                            createTextVNode(" " + toDisplayString(__props.patient.covenant_name), 1)
                          ])) : createCommentVNode("", true),
                          __props.patient.skin_type ? (openBlock(), createBlock("div", {
                            key: 6,
                            class: "mb-2"
                          }, [
                            createVNode("small", { class: "text-muted" }, "Pele:"),
                            createTextVNode(" " + toDisplayString(__props.patient.skin_type), 1)
                          ])) : createCommentVNode("", true),
                          __props.patient.iris_type ? (openBlock(), createBlock("div", {
                            key: 7,
                            class: "mb-2"
                          }, [
                            createVNode("small", { class: "text-muted" }, "Íris:"),
                            createTextVNode(" " + toDisplayString(__props.patient.iris_type), 1)
                          ])) : createCommentVNode("", true)
                        ])
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-12 col-md-9 col-lg-10" }, [
                    createVNode(_sfc_main$1, {
                      title: __props.t.title ?? "Prontuários",
                      subtitle: total.value > 0 ? `${total.value} registros` : ""
                    }, {
                      actions: withCtx(() => [
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
                        createVNode(unref(Link), {
                          href: __props.urls.create,
                          class: "btn btn-primary btn-sm"
                        }, {
                          default: withCtx(() => [
                            createVNode("i", { class: "ti ti-plus me-1" }),
                            createTextVNode("Novo prontuário ")
                          ]),
                          _: 1
                        }, 8, ["href"])
                      ]),
                      _: 1
                    }, 8, ["title", "subtitle"]),
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
                          createVNode(unref(Link), {
                            href: __props.urls.create,
                            class: "btn btn-primary btn-sm"
                          }, {
                            default: withCtx(() => [
                              createVNode("i", { class: "ti ti-plus me-1" }),
                              createTextVNode("Criar primeiro prontuário ")
                            ]),
                            _: 1
                          }, 8, ["href"])
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
                                      createVNode("div", { class: "d-flex gap-1 mt-3" }, [
                                        createVNode("button", {
                                          class: "btn btn-sm btn-outline-primary",
                                          onClick: ($event) => openDetail(record)
                                        }, [
                                          createVNode("i", { class: "ti ti-eye me-1" }),
                                          createTextVNode("Ver detalhes ")
                                        ], 8, ["onClick"]),
                                        createVNode("a", {
                                          href: record.edit_url,
                                          class: "btn btn-sm btn-outline-secondary"
                                        }, [
                                          createVNode("i", { class: "ti ti-edit me-1" }),
                                          createTextVNode(toDisplayString(record.is_locked ? "Ver" : "Editar"), 1)
                                        ], 8, ["href"]),
                                        createVNode("a", {
                                          href: record.pdf_url,
                                          target: "_blank",
                                          class: "btn btn-sm btn-outline-secondary"
                                        }, [
                                          createVNode("i", { class: "ti ti-file-download me-1" }),
                                          createTextVNode("PDF ")
                                        ], 8, ["href"]),
                                        !record.is_locked ? (openBlock(), createBlock("button", {
                                          key: 0,
                                          class: "btn btn-sm btn-outline-danger ms-auto",
                                          onClick: ($event) => onDelete(record)
                                        }, [
                                          createVNode("i", { class: "ti ti-trash" })
                                        ], 8, ["onClick"])) : createCommentVNode("", true)
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
                }, null, 8, ["open", "record", "patient", "onClose"])
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
const Index = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-cc9a9c05"]]);
export {
  Index as default
};
