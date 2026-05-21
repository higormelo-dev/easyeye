import { ref, computed, onMounted, onBeforeUnmount, mergeProps, withCtx, unref, createVNode, createTextVNode, openBlock, createBlock, toDisplayString, createCommentVNode, Fragment, renderList, withModifiers, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderAttr, ssrInterpolate, ssrRenderClass, ssrRenderStyle, ssrRenderList, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { usePage, useForm, Link, router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Import",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    imports: { type: Array, default: () => [] },
    pending_import: { type: Object, default: null },
    preview_id: { type: String, default: null },
    plan_status: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const page = usePage();
    const uploadForm = useForm({ file: null });
    const fileInput = ref(null);
    function onFileChange(e) {
      uploadForm.file = e.target.files[0];
    }
    function submitUpload() {
      if (!uploadForm.file) return;
      uploadForm.post(props.urls.store, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
          uploadForm.reset();
          if (fileInput.value) fileInput.value.value = "";
        }
      });
    }
    const previewImport = computed(() => {
      if (!props.preview_id) return null;
      return props.imports.find((i) => i.id === props.preview_id);
    });
    function csrf() {
      var _a;
      return ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? "";
    }
    async function confirmImport(item) {
      if (!confirm(`Confirmar importação de "${item.original_name}"?`)) return;
      await fetch(item.urls.confirm, {
        method: "POST",
        headers: { "Accept": "application/json", "X-CSRF-TOKEN": csrf() }
      });
      router.reload({ only: ["imports", "pending_import", "preview_id"] });
    }
    async function cancelImport(item) {
      if (!confirm(`Cancelar e remover "${item.original_name}"?`)) return;
      await fetch(item.urls.cancel, {
        method: "DELETE",
        headers: { "Accept": "application/json", "X-CSRF-TOKEN": csrf() }
      });
      router.reload({ only: ["imports", "pending_import", "preview_id"] });
    }
    const pollingImport = ref(props.pending_import);
    let pollTimer = null;
    async function pollStatus() {
      if (!pollingImport.value || pollingImport.value.is_done) return;
      try {
        const res = await fetch(pollingImport.value.urls.status, { headers: { Accept: "application/json" } });
        const json = await res.json();
        pollingImport.value = { ...pollingImport.value, ...json };
        if (json.is_done) {
          router.reload({ only: ["imports", "pending_import"] });
        }
      } catch {
      }
    }
    onMounted(() => {
      if (pollingImport.value && !pollingImport.value.is_done) {
        pollTimer = setInterval(pollStatus, 2e3);
      }
    });
    onBeforeUnmount(() => {
      if (pollTimer) clearInterval(pollTimer);
    });
    const statusBadgeClass = (color) => `badge bg-${color} fs-11 fw-medium`;
    const flashError = computed(() => {
      var _a, _b;
      return ((_b = (_a = page.props) == null ? void 0 : _a.flash) == null ? void 0 : _b.error) ?? uploadForm.errors.file;
    });
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Importar pacientes",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b, _c, _d;
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: "Importação em massa de pacientes",
              subtitle: "Upload CSV — geração de preview antes da importação efetiva."
            }, {
              actions: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`<a${ssrRenderAttr("href", __props.urls.template)} class="btn btn-outline-secondary btn-sm"${_scopeId2}><i class="ti ti-download me-1"${_scopeId2}></i>Modelo CSV </a>`);
                  _push3(ssrRenderComponent(unref(Link), {
                    href: __props.urls.patients,
                    class: "btn btn-outline-secondary btn-sm"
                  }, {
                    default: withCtx((_3, _push4, _parent4, _scopeId3) => {
                      if (_push4) {
                        _push4(`<i class="ti ti-arrow-left me-1"${_scopeId3}></i>Pacientes `);
                      } else {
                        return [
                          createVNode("i", { class: "ti ti-arrow-left me-1" }),
                          createTextVNode("Pacientes ")
                        ];
                      }
                    }),
                    _: 1
                  }, _parent3, _scopeId2));
                } else {
                  return [
                    createVNode("a", {
                      href: __props.urls.template,
                      class: "btn btn-outline-secondary btn-sm"
                    }, [
                      createVNode("i", { class: "ti ti-download me-1" }),
                      createTextVNode("Modelo CSV ")
                    ], 8, ["href"]),
                    createVNode(unref(Link), {
                      href: __props.urls.patients,
                      class: "btn btn-outline-secondary btn-sm"
                    }, {
                      default: withCtx(() => [
                        createVNode("i", { class: "ti ti-arrow-left me-1" }),
                        createTextVNode("Pacientes ")
                      ]),
                      _: 1
                    }, 8, ["href"])
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            if ((_a = __props.plan_status) == null ? void 0 : _a.max) {
              _push2(`<div class="alert alert-info small d-flex align-items-start mb-3"${_scopeId}><i class="ti ti-info-circle me-2 fs-5 mt-1"${_scopeId}></i><div${_scopeId}> Seu plano permite até <strong${_scopeId}>${ssrInterpolate(__props.plan_status.max)}</strong> pacientes. Utilizados: <strong${_scopeId}>${ssrInterpolate(__props.plan_status.used)}</strong>. Disponíveis: <strong${_scopeId}>${ssrInterpolate(__props.plan_status.available ?? "—")}</strong>. </div></div>`);
            } else {
              _push2(`<!---->`);
            }
            if (flashError.value) {
              _push2(`<div class="alert alert-danger alert-dismissible fade show mb-3"${_scopeId}><i class="ti ti-alert-triangle me-1"${_scopeId}></i>${ssrInterpolate(flashError.value)} <button type="button" class="btn-close" data-bs-dismiss="alert"${_scopeId}></button></div>`);
            } else {
              _push2(`<!---->`);
            }
            if (pollingImport.value && !pollingImport.value.is_done) {
              _push2(`<div class="alert alert-info"${_scopeId}><div class="d-flex align-items-center mb-2"${_scopeId}><span class="spinner-border spinner-border-sm me-2"${_scopeId}></span><strong${_scopeId}>Importação em andamento — ${ssrInterpolate(pollingImport.value.original_name)}</strong><span class="${ssrRenderClass(`badge bg-${pollingImport.value.status_color} ms-2`)}"${_scopeId}>${ssrInterpolate(pollingImport.value.status_label)}</span></div><div class="progress mb-2" style="${ssrRenderStyle({ "height": "8px" })}"${_scopeId}><div class="progress-bar bg-info progress-bar-striped progress-bar-animated" style="${ssrRenderStyle(`width: ${pollingImport.value.progress}%`)}"${_scopeId}></div></div><div class="small text-muted"${_scopeId}>${ssrInterpolate(pollingImport.value.processed_rows)} de ${ssrInterpolate(pollingImport.value.total_rows)} processados — <strong class="text-success"${_scopeId}>${ssrInterpolate(pollingImport.value.imported_rows)}</strong> importados, <strong class="text-warning"${_scopeId}>${ssrInterpolate(pollingImport.value.skipped_rows)}</strong> ignorados, <strong class="text-danger"${_scopeId}>${ssrInterpolate(pollingImport.value.error_rows)}</strong> erros </div></div>`);
            } else {
              _push2(`<!---->`);
            }
            if (previewImport.value) {
              _push2(`<div class="card mb-3 border-warning"${_scopeId}><div class="card-header bg-warning-subtle"${_scopeId}><h6 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-eye me-1"${_scopeId}></i> Pré-visualização — ${ssrInterpolate(previewImport.value.original_name)}</h6></div><div class="card-body"${_scopeId}><p class="text-muted small mb-3"${_scopeId}> Revise as primeiras linhas do arquivo. Confirme para iniciar a importação ou cancele para descartar. </p>`);
              if ((_b = previewImport.value.preview) == null ? void 0 : _b.headers) {
                _push2(`<div class="table-responsive mb-3"${_scopeId}><table class="table table-sm table-bordered small"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><!--[-->`);
                ssrRenderList(previewImport.value.preview.headers, (h) => {
                  _push2(`<th${_scopeId}>${ssrInterpolate(h)}</th>`);
                });
                _push2(`<!--]--></tr></thead><tbody${_scopeId}><!--[-->`);
                ssrRenderList(previewImport.value.preview.rows ?? [], (row, ri) => {
                  _push2(`<tr${_scopeId}><!--[-->`);
                  ssrRenderList(row, (cell, ci) => {
                    _push2(`<td${_scopeId}>${ssrInterpolate(cell)}</td>`);
                  });
                  _push2(`<!--]--></tr>`);
                });
                _push2(`<!--]--></tbody></table></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<div class="d-flex gap-2 justify-content-end"${_scopeId}><button type="button" class="btn btn-outline-danger btn-sm"${_scopeId}><i class="ti ti-x me-1"${_scopeId}></i>Cancelar </button><button type="button" class="btn btn-primary btn-sm"${_scopeId}><i class="ti ti-check me-1"${_scopeId}></i>Confirmar e importar </button></div></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            if (!pollingImport.value || pollingImport.value.is_done) {
              _push2(`<div class="card mb-3"${_scopeId}><div class="card-body"${_scopeId}><h6 class="fw-semibold mb-3"${_scopeId}><i class="ti ti-upload me-1"${_scopeId}></i>Novo arquivo CSV </h6><form class="row g-2 align-items-end"${_scopeId}><div class="col-md-8"${_scopeId}><label class="form-label small"${_scopeId}>Arquivo (.csv, máx 20MB) <span class="text-danger"${_scopeId}>*</span></label><input type="file" accept=".csv,text/csv" class="${ssrRenderClass([{ "is-invalid": unref(uploadForm).errors.file }, "form-control form-control-sm"])}" required${_scopeId}>`);
              if (unref(uploadForm).errors.file) {
                _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(uploadForm).errors.file)}</div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<small class="text-muted"${_scopeId}> Separador: ponto-e-vírgula (;). Encoding UTF-8. Veja o <a${ssrRenderAttr("href", __props.urls.template)}${_scopeId}>modelo</a>. </small></div><div class="col-md-4 d-flex gap-2"${_scopeId}><button type="submit" class="btn btn-primary btn-sm w-100"${ssrIncludeBooleanAttr(!unref(uploadForm).file || unref(uploadForm).processing) ? " disabled" : ""}${_scopeId}>`);
              if (unref(uploadForm).processing) {
                _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
              } else {
                _push2(`<i class="ti ti-upload me-1"${_scopeId}></i>`);
              }
              _push2(` Enviar </button></div></form></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="card"${_scopeId}><div class="card-header bg-transparent"${_scopeId}><h6 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-history me-1"${_scopeId}></i>Histórico de importações </h6></div><div class="table-responsive"${_scopeId}><table class="table table-sm table-hover mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Data</th><th${_scopeId}>Arquivo</th><th${_scopeId}>Usuário</th><th class="text-center"${_scopeId}>Status</th><th class="text-end"${_scopeId}>Linhas</th><th class="text-end"${_scopeId}>Importadas</th><th class="text-end"${_scopeId}>Erros</th><th class="text-end"${_scopeId}>Ações</th></tr></thead><tbody${_scopeId}>`);
            if (__props.imports.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="8" class="text-center py-4 text-muted"${_scopeId}>Sem importações ainda.</td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.imports, (item) => {
              _push2(`<tr${_scopeId}><td class="small text-muted"${_scopeId}>${ssrInterpolate(item.created_at)}</td><td${_scopeId}>${ssrInterpolate(item.original_name)}</td><td class="text-muted small"${_scopeId}>${ssrInterpolate(item.user_name)}</td><td class="text-center"${_scopeId}><span class="${ssrRenderClass(statusBadgeClass(item.status_color))}"${_scopeId}>${ssrInterpolate(item.status_label)}</span></td><td class="text-end"${_scopeId}>${ssrInterpolate(item.total_rows)}</td><td class="text-end text-success"${_scopeId}>${ssrInterpolate(item.imported_rows)}</td><td class="text-end text-danger"${_scopeId}>${ssrInterpolate(item.error_rows)}</td><td class="text-end"${_scopeId}>`);
              if (item.has_errors_file && item.urls.errors) {
                _push2(`<a${ssrRenderAttr("href", item.urls.errors)} class="btn btn-sm btn-outline-secondary" title="Baixar erros"${_scopeId}><i class="ti ti-download"${_scopeId}></i></a>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div></div></div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, {
                  title: "Importação em massa de pacientes",
                  subtitle: "Upload CSV — geração de preview antes da importação efetiva."
                }, {
                  actions: withCtx(() => [
                    createVNode("a", {
                      href: __props.urls.template,
                      class: "btn btn-outline-secondary btn-sm"
                    }, [
                      createVNode("i", { class: "ti ti-download me-1" }),
                      createTextVNode("Modelo CSV ")
                    ], 8, ["href"]),
                    createVNode(unref(Link), {
                      href: __props.urls.patients,
                      class: "btn btn-outline-secondary btn-sm"
                    }, {
                      default: withCtx(() => [
                        createVNode("i", { class: "ti ti-arrow-left me-1" }),
                        createTextVNode("Pacientes ")
                      ]),
                      _: 1
                    }, 8, ["href"])
                  ]),
                  _: 1
                }),
                ((_c = __props.plan_status) == null ? void 0 : _c.max) ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "alert alert-info small d-flex align-items-start mb-3"
                }, [
                  createVNode("i", { class: "ti ti-info-circle me-2 fs-5 mt-1" }),
                  createVNode("div", null, [
                    createTextVNode(" Seu plano permite até "),
                    createVNode("strong", null, toDisplayString(__props.plan_status.max), 1),
                    createTextVNode(" pacientes. Utilizados: "),
                    createVNode("strong", null, toDisplayString(__props.plan_status.used), 1),
                    createTextVNode(". Disponíveis: "),
                    createVNode("strong", null, toDisplayString(__props.plan_status.available ?? "—"), 1),
                    createTextVNode(". ")
                  ])
                ])) : createCommentVNode("", true),
                flashError.value ? (openBlock(), createBlock("div", {
                  key: 1,
                  class: "alert alert-danger alert-dismissible fade show mb-3"
                }, [
                  createVNode("i", { class: "ti ti-alert-triangle me-1" }),
                  createTextVNode(toDisplayString(flashError.value) + " ", 1),
                  createVNode("button", {
                    type: "button",
                    class: "btn-close",
                    "data-bs-dismiss": "alert"
                  })
                ])) : createCommentVNode("", true),
                pollingImport.value && !pollingImport.value.is_done ? (openBlock(), createBlock("div", {
                  key: 2,
                  class: "alert alert-info"
                }, [
                  createVNode("div", { class: "d-flex align-items-center mb-2" }, [
                    createVNode("span", { class: "spinner-border spinner-border-sm me-2" }),
                    createVNode("strong", null, "Importação em andamento — " + toDisplayString(pollingImport.value.original_name), 1),
                    createVNode("span", {
                      class: `badge bg-${pollingImport.value.status_color} ms-2`
                    }, toDisplayString(pollingImport.value.status_label), 3)
                  ]),
                  createVNode("div", {
                    class: "progress mb-2",
                    style: { "height": "8px" }
                  }, [
                    createVNode("div", {
                      class: "progress-bar bg-info progress-bar-striped progress-bar-animated",
                      style: `width: ${pollingImport.value.progress}%`
                    }, null, 4)
                  ]),
                  createVNode("div", { class: "small text-muted" }, [
                    createTextVNode(toDisplayString(pollingImport.value.processed_rows) + " de " + toDisplayString(pollingImport.value.total_rows) + " processados — ", 1),
                    createVNode("strong", { class: "text-success" }, toDisplayString(pollingImport.value.imported_rows), 1),
                    createTextVNode(" importados, "),
                    createVNode("strong", { class: "text-warning" }, toDisplayString(pollingImport.value.skipped_rows), 1),
                    createTextVNode(" ignorados, "),
                    createVNode("strong", { class: "text-danger" }, toDisplayString(pollingImport.value.error_rows), 1),
                    createTextVNode(" erros ")
                  ])
                ])) : createCommentVNode("", true),
                previewImport.value ? (openBlock(), createBlock("div", {
                  key: 3,
                  class: "card mb-3 border-warning"
                }, [
                  createVNode("div", { class: "card-header bg-warning-subtle" }, [
                    createVNode("h6", { class: "mb-0 fw-semibold" }, [
                      createVNode("i", { class: "ti ti-eye me-1" }),
                      createTextVNode(" Pré-visualização — " + toDisplayString(previewImport.value.original_name), 1)
                    ])
                  ]),
                  createVNode("div", { class: "card-body" }, [
                    createVNode("p", { class: "text-muted small mb-3" }, " Revise as primeiras linhas do arquivo. Confirme para iniciar a importação ou cancele para descartar. "),
                    ((_d = previewImport.value.preview) == null ? void 0 : _d.headers) ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "table-responsive mb-3"
                    }, [
                      createVNode("table", { class: "table table-sm table-bordered small" }, [
                        createVNode("thead", { class: "table-light" }, [
                          createVNode("tr", null, [
                            (openBlock(true), createBlock(Fragment, null, renderList(previewImport.value.preview.headers, (h) => {
                              return openBlock(), createBlock("th", { key: h }, toDisplayString(h), 1);
                            }), 128))
                          ])
                        ]),
                        createVNode("tbody", null, [
                          (openBlock(true), createBlock(Fragment, null, renderList(previewImport.value.preview.rows ?? [], (row, ri) => {
                            return openBlock(), createBlock("tr", { key: ri }, [
                              (openBlock(true), createBlock(Fragment, null, renderList(row, (cell, ci) => {
                                return openBlock(), createBlock("td", { key: ci }, toDisplayString(cell), 1);
                              }), 128))
                            ]);
                          }), 128))
                        ])
                      ])
                    ])) : createCommentVNode("", true),
                    createVNode("div", { class: "d-flex gap-2 justify-content-end" }, [
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-outline-danger btn-sm",
                        onClick: ($event) => cancelImport(previewImport.value)
                      }, [
                        createVNode("i", { class: "ti ti-x me-1" }),
                        createTextVNode("Cancelar ")
                      ], 8, ["onClick"]),
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-primary btn-sm",
                        onClick: ($event) => confirmImport(previewImport.value)
                      }, [
                        createVNode("i", { class: "ti ti-check me-1" }),
                        createTextVNode("Confirmar e importar ")
                      ], 8, ["onClick"])
                    ])
                  ])
                ])) : createCommentVNode("", true),
                !pollingImport.value || pollingImport.value.is_done ? (openBlock(), createBlock("div", {
                  key: 4,
                  class: "card mb-3"
                }, [
                  createVNode("div", { class: "card-body" }, [
                    createVNode("h6", { class: "fw-semibold mb-3" }, [
                      createVNode("i", { class: "ti ti-upload me-1" }),
                      createTextVNode("Novo arquivo CSV ")
                    ]),
                    createVNode("form", {
                      onSubmit: withModifiers(submitUpload, ["prevent"]),
                      class: "row g-2 align-items-end"
                    }, [
                      createVNode("div", { class: "col-md-8" }, [
                        createVNode("label", { class: "form-label small" }, [
                          createTextVNode("Arquivo (.csv, máx 20MB) "),
                          createVNode("span", { class: "text-danger" }, "*")
                        ]),
                        createVNode("input", {
                          ref_key: "fileInput",
                          ref: fileInput,
                          type: "file",
                          accept: ".csv,text/csv",
                          class: ["form-control form-control-sm", { "is-invalid": unref(uploadForm).errors.file }],
                          onChange: onFileChange,
                          required: ""
                        }, null, 34),
                        unref(uploadForm).errors.file ? (openBlock(), createBlock("div", {
                          key: 0,
                          class: "invalid-feedback"
                        }, toDisplayString(unref(uploadForm).errors.file), 1)) : createCommentVNode("", true),
                        createVNode("small", { class: "text-muted" }, [
                          createTextVNode(" Separador: ponto-e-vírgula (;). Encoding UTF-8. Veja o "),
                          createVNode("a", {
                            href: __props.urls.template
                          }, "modelo", 8, ["href"]),
                          createTextVNode(". ")
                        ])
                      ]),
                      createVNode("div", { class: "col-md-4 d-flex gap-2" }, [
                        createVNode("button", {
                          type: "submit",
                          class: "btn btn-primary btn-sm w-100",
                          disabled: !unref(uploadForm).file || unref(uploadForm).processing
                        }, [
                          unref(uploadForm).processing ? (openBlock(), createBlock("span", {
                            key: 0,
                            class: "spinner-border spinner-border-sm me-1"
                          })) : (openBlock(), createBlock("i", {
                            key: 1,
                            class: "ti ti-upload me-1"
                          })),
                          createTextVNode(" Enviar ")
                        ], 8, ["disabled"])
                      ])
                    ], 32)
                  ])
                ])) : createCommentVNode("", true),
                createVNode("div", { class: "card" }, [
                  createVNode("div", { class: "card-header bg-transparent" }, [
                    createVNode("h6", { class: "mb-0 fw-semibold" }, [
                      createVNode("i", { class: "ti ti-history me-1" }),
                      createTextVNode("Histórico de importações ")
                    ])
                  ]),
                  createVNode("div", { class: "table-responsive" }, [
                    createVNode("table", { class: "table table-sm table-hover mb-0" }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          createVNode("th", null, "Data"),
                          createVNode("th", null, "Arquivo"),
                          createVNode("th", null, "Usuário"),
                          createVNode("th", { class: "text-center" }, "Status"),
                          createVNode("th", { class: "text-end" }, "Linhas"),
                          createVNode("th", { class: "text-end" }, "Importadas"),
                          createVNode("th", { class: "text-end" }, "Erros"),
                          createVNode("th", { class: "text-end" }, "Ações")
                        ])
                      ]),
                      createVNode("tbody", null, [
                        __props.imports.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("td", {
                            colspan: "8",
                            class: "text-center py-4 text-muted"
                          }, "Sem importações ainda.")
                        ])) : createCommentVNode("", true),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.imports, (item) => {
                          return openBlock(), createBlock("tr", {
                            key: item.id
                          }, [
                            createVNode("td", { class: "small text-muted" }, toDisplayString(item.created_at), 1),
                            createVNode("td", null, toDisplayString(item.original_name), 1),
                            createVNode("td", { class: "text-muted small" }, toDisplayString(item.user_name), 1),
                            createVNode("td", { class: "text-center" }, [
                              createVNode("span", {
                                class: statusBadgeClass(item.status_color)
                              }, toDisplayString(item.status_label), 3)
                            ]),
                            createVNode("td", { class: "text-end" }, toDisplayString(item.total_rows), 1),
                            createVNode("td", { class: "text-end text-success" }, toDisplayString(item.imported_rows), 1),
                            createVNode("td", { class: "text-end text-danger" }, toDisplayString(item.error_rows), 1),
                            createVNode("td", { class: "text-end" }, [
                              item.has_errors_file && item.urls.errors ? (openBlock(), createBlock("a", {
                                key: 0,
                                href: item.urls.errors,
                                class: "btn btn-sm btn-outline-secondary",
                                title: "Baixar erros"
                              }, [
                                createVNode("i", { class: "ti ti-download" })
                              ], 8, ["href"])) : createCommentVNode("", true)
                            ])
                          ]);
                        }), 128))
                      ])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Patients/Import.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
