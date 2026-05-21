import { ref, computed, mergeProps, withCtx, unref, createVNode, createTextVNode, openBlock, createBlock, createCommentVNode, Fragment, renderList, toDisplayString, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderList, ssrInterpolate } from "vue/server-renderer";
import { Link, router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import { _ as _sfc_main$2 } from "./SearchInput-DBX1PwVy.js";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { A as ActionIconGroup, _ as _sfc_main$3 } from "./ActionIconGroup-Dj2wQrik.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    urls: { type: Object, required: true }
  },
  setup(__props) {
    const props = __props;
    const search = ref("");
    const categoryId = ref("");
    const filteredItems = computed(() => {
      const q = search.value.trim().toLowerCase();
      return props.items.filter((item) => {
        var _a;
        if (categoryId.value && item.category !== categoryId.value) {
          return false;
        }
        if (q && !((_a = item.title) == null ? void 0 : _a.toLowerCase().includes(q))) return false;
        return true;
      });
    });
    function csrf() {
      var _a;
      return ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? "";
    }
    async function onDelete(item) {
      if (!confirm(`Excluir "${item.title}"?`)) return;
      await fetch(item.destroy_url, {
        method: "DELETE",
        headers: { "Accept": "application/json", "X-CSRF-TOKEN": csrf() }
      });
      router.reload({ only: ["items"] });
    }
    async function onReimport(item) {
      if (!item.reimport_url) return;
      if (!confirm("Reimportar versão atualizada do template global? Suas alterações locais serão sobrescritas.")) return;
      await fetch(item.reimport_url, {
        method: "POST",
        headers: { "Accept": "application/json", "X-CSRF-TOKEN": csrf() }
      });
      router.reload({ only: ["items"] });
    }
    function openPreview(item) {
      window.open(item.preview_url, "_blank");
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Modelos de documentação",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: "Modelos de documentação",
              subtitle: `${__props.items.length} modelos`
            }, {
              actions: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(ssrRenderComponent(unref(Link), {
                    href: __props.urls.create,
                    class: "btn btn-primary btn-sm"
                  }, {
                    default: withCtx((_3, _push4, _parent4, _scopeId3) => {
                      if (_push4) {
                        _push4(`<i class="ti ti-plus me-1"${_scopeId3}></i>Novo modelo `);
                      } else {
                        return [
                          createVNode("i", { class: "ti ti-plus me-1" }),
                          createTextVNode("Novo modelo ")
                        ];
                      }
                    }),
                    _: 1
                  }, _parent3, _scopeId2));
                } else {
                  return [
                    createVNode(unref(Link), {
                      href: __props.urls.create,
                      class: "btn btn-primary btn-sm"
                    }, {
                      default: withCtx(() => [
                        createVNode("i", { class: "ti ti-plus me-1" }),
                        createTextVNode("Novo modelo ")
                      ]),
                      _: 1
                    }, 8, ["href"])
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`<div class="d-flex align-items-center mb-3 gap-2 flex-wrap"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$2, {
              modelValue: search.value,
              "onUpdate:modelValue": ($event) => search.value = $event,
              placeholder: "Buscar por título...",
              style: { "min-width": "280px" }
            }, null, _parent2, _scopeId));
            _push2(`</div><div class="card"${_scopeId}><div class="table-responsive"${_scopeId}><table class="table table-nowrap table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Título</th><th${_scopeId}>Categoria</th><th class="text-center"${_scopeId}>Papel</th><th class="text-center"${_scopeId}>Cabeçalho</th><th class="text-center"${_scopeId}>Assinatura</th><th class="text-center"${_scopeId}>Origem</th><th class="text-end"${_scopeId}>Ações</th></tr></thead><tbody${_scopeId}>`);
            if (filteredItems.value.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="7" class="text-center text-muted py-5"${_scopeId}><i class="ti ti-file-text fs-1 d-block mb-2 opacity-25"${_scopeId}></i> Nenhum modelo cadastrado. </td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(filteredItems.value, (item) => {
              _push2(`<tr${_scopeId}><td class="fw-medium"${_scopeId}>${ssrInterpolate(item.title)}</td><td class="text-muted"${_scopeId}>${ssrInterpolate(item.category || "—")}</td><td class="text-center"${_scopeId}><code class="small"${_scopeId}>${ssrInterpolate(item.paper_size)}</code></td><td class="text-center"${_scopeId}>`);
              if (item.show_header) {
                _push2(`<i class="ti ti-check text-success"${_scopeId}></i>`);
              } else {
                _push2(`<i class="ti ti-minus text-muted"${_scopeId}></i>`);
              }
              _push2(`</td><td class="text-center"${_scopeId}>`);
              if (item.show_signature) {
                _push2(`<i class="ti ti-check text-success"${_scopeId}></i>`);
              } else {
                _push2(`<i class="ti ti-minus text-muted"${_scopeId}></i>`);
              }
              _push2(`</td><td class="text-center"${_scopeId}>`);
              if (item.is_adopted) {
                _push2(`<span class="badge badge-soft-info rounded fs-11"${_scopeId}><i class="ti ti-cloud-download me-1"${_scopeId}></i>Adotado `);
                if (item.has_update) {
                  _push2(`<span class="badge bg-warning text-dark ms-1"${_scopeId}>Atualização disponível</span>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</span>`);
              } else {
                _push2(`<span class="badge badge-soft-secondary rounded fs-11"${_scopeId}>Próprio</span>`);
              }
              _push2(`</td><td class="text-end"${_scopeId}>`);
              _push2(ssrRenderComponent(ActionIconGroup, {
                align: "end",
                gap: "tight"
              }, {
                default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                  if (_push3) {
                    _push3(ssrRenderComponent(_sfc_main$3, {
                      icon: "ti ti-eye",
                      title: "Pré-visualizar",
                      onClick: ($event) => openPreview(item)
                    }, null, _parent3, _scopeId2));
                    _push3(ssrRenderComponent(unref(Link), {
                      href: item.edit_url,
                      class: "btn btn-sm btn-ghost",
                      title: "Editar"
                    }, {
                      default: withCtx((_3, _push4, _parent4, _scopeId3) => {
                        if (_push4) {
                          _push4(`<i class="ti ti-edit"${_scopeId3}></i>`);
                        } else {
                          return [
                            createVNode("i", { class: "ti ti-edit" })
                          ];
                        }
                      }),
                      _: 2
                    }, _parent3, _scopeId2));
                    _push3(ssrRenderComponent(ActionDropdown, {
                      "btn-class": "ee-action-icon ee-action-icon--default",
                      icon: "ti ti-dots-vertical"
                    }, {
                      default: withCtx((_3, _push4, _parent4, _scopeId3) => {
                        if (_push4) {
                          if (item.reimport_url) {
                            _push4(`<li${_scopeId3}><button class="dropdown-item rounded-1"${_scopeId3}><i class="ti ti-refresh me-1"${_scopeId3}></i>Reimportar template global </button></li>`);
                          } else {
                            _push4(`<!---->`);
                          }
                          if (item.reimport_url) {
                            _push4(`<li${_scopeId3}><hr class="dropdown-divider"${_scopeId3}></li>`);
                          } else {
                            _push4(`<!---->`);
                          }
                          _push4(`<li${_scopeId3}><button class="dropdown-item rounded-1 text-danger"${_scopeId3}><i class="ti ti-trash me-1"${_scopeId3}></i>Excluir </button></li>`);
                        } else {
                          return [
                            item.reimport_url ? (openBlock(), createBlock("li", { key: 0 }, [
                              createVNode("button", {
                                class: "dropdown-item rounded-1",
                                onClick: ($event) => onReimport(item)
                              }, [
                                createVNode("i", { class: "ti ti-refresh me-1" }),
                                createTextVNode("Reimportar template global ")
                              ], 8, ["onClick"])
                            ])) : createCommentVNode("", true),
                            item.reimport_url ? (openBlock(), createBlock("li", { key: 1 }, [
                              createVNode("hr", { class: "dropdown-divider" })
                            ])) : createCommentVNode("", true),
                            createVNode("li", null, [
                              createVNode("button", {
                                class: "dropdown-item rounded-1 text-danger",
                                onClick: ($event) => onDelete(item)
                              }, [
                                createVNode("i", { class: "ti ti-trash me-1" }),
                                createTextVNode("Excluir ")
                              ], 8, ["onClick"])
                            ])
                          ];
                        }
                      }),
                      _: 2
                    }, _parent3, _scopeId2));
                  } else {
                    return [
                      createVNode(_sfc_main$3, {
                        icon: "ti ti-eye",
                        title: "Pré-visualizar",
                        onClick: ($event) => openPreview(item)
                      }, null, 8, ["onClick"]),
                      createVNode(unref(Link), {
                        href: item.edit_url,
                        class: "btn btn-sm btn-ghost",
                        title: "Editar"
                      }, {
                        default: withCtx(() => [
                          createVNode("i", { class: "ti ti-edit" })
                        ]),
                        _: 1
                      }, 8, ["href"]),
                      createVNode(ActionDropdown, {
                        "btn-class": "ee-action-icon ee-action-icon--default",
                        icon: "ti ti-dots-vertical"
                      }, {
                        default: withCtx(() => [
                          item.reimport_url ? (openBlock(), createBlock("li", { key: 0 }, [
                            createVNode("button", {
                              class: "dropdown-item rounded-1",
                              onClick: ($event) => onReimport(item)
                            }, [
                              createVNode("i", { class: "ti ti-refresh me-1" }),
                              createTextVNode("Reimportar template global ")
                            ], 8, ["onClick"])
                          ])) : createCommentVNode("", true),
                          item.reimport_url ? (openBlock(), createBlock("li", { key: 1 }, [
                            createVNode("hr", { class: "dropdown-divider" })
                          ])) : createCommentVNode("", true),
                          createVNode("li", null, [
                            createVNode("button", {
                              class: "dropdown-item rounded-1 text-danger",
                              onClick: ($event) => onDelete(item)
                            }, [
                              createVNode("i", { class: "ti ti-trash me-1" }),
                              createTextVNode("Excluir ")
                            ], 8, ["onClick"])
                          ])
                        ]),
                        _: 2
                      }, 1024)
                    ];
                  }
                }),
                _: 2
              }, _parent2, _scopeId));
              _push2(`</td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div></div></div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, {
                  title: "Modelos de documentação",
                  subtitle: `${__props.items.length} modelos`
                }, {
                  actions: withCtx(() => [
                    createVNode(unref(Link), {
                      href: __props.urls.create,
                      class: "btn btn-primary btn-sm"
                    }, {
                      default: withCtx(() => [
                        createVNode("i", { class: "ti ti-plus me-1" }),
                        createTextVNode("Novo modelo ")
                      ]),
                      _: 1
                    }, 8, ["href"])
                  ]),
                  _: 1
                }, 8, ["subtitle"]),
                createVNode("div", { class: "d-flex align-items-center mb-3 gap-2 flex-wrap" }, [
                  createVNode(_sfc_main$2, {
                    modelValue: search.value,
                    "onUpdate:modelValue": ($event) => search.value = $event,
                    placeholder: "Buscar por título...",
                    style: { "min-width": "280px" }
                  }, null, 8, ["modelValue", "onUpdate:modelValue"])
                ]),
                createVNode("div", { class: "card" }, [
                  createVNode("div", { class: "table-responsive" }, [
                    createVNode("table", { class: "table table-nowrap table-hover align-middle mb-0" }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          createVNode("th", null, "Título"),
                          createVNode("th", null, "Categoria"),
                          createVNode("th", { class: "text-center" }, "Papel"),
                          createVNode("th", { class: "text-center" }, "Cabeçalho"),
                          createVNode("th", { class: "text-center" }, "Assinatura"),
                          createVNode("th", { class: "text-center" }, "Origem"),
                          createVNode("th", { class: "text-end" }, "Ações")
                        ])
                      ]),
                      createVNode("tbody", null, [
                        filteredItems.value.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("td", {
                            colspan: "7",
                            class: "text-center text-muted py-5"
                          }, [
                            createVNode("i", { class: "ti ti-file-text fs-1 d-block mb-2 opacity-25" }),
                            createTextVNode(" Nenhum modelo cadastrado. ")
                          ])
                        ])) : createCommentVNode("", true),
                        (openBlock(true), createBlock(Fragment, null, renderList(filteredItems.value, (item) => {
                          return openBlock(), createBlock("tr", {
                            key: item.id
                          }, [
                            createVNode("td", { class: "fw-medium" }, toDisplayString(item.title), 1),
                            createVNode("td", { class: "text-muted" }, toDisplayString(item.category || "—"), 1),
                            createVNode("td", { class: "text-center" }, [
                              createVNode("code", { class: "small" }, toDisplayString(item.paper_size), 1)
                            ]),
                            createVNode("td", { class: "text-center" }, [
                              item.show_header ? (openBlock(), createBlock("i", {
                                key: 0,
                                class: "ti ti-check text-success"
                              })) : (openBlock(), createBlock("i", {
                                key: 1,
                                class: "ti ti-minus text-muted"
                              }))
                            ]),
                            createVNode("td", { class: "text-center" }, [
                              item.show_signature ? (openBlock(), createBlock("i", {
                                key: 0,
                                class: "ti ti-check text-success"
                              })) : (openBlock(), createBlock("i", {
                                key: 1,
                                class: "ti ti-minus text-muted"
                              }))
                            ]),
                            createVNode("td", { class: "text-center" }, [
                              item.is_adopted ? (openBlock(), createBlock("span", {
                                key: 0,
                                class: "badge badge-soft-info rounded fs-11"
                              }, [
                                createVNode("i", { class: "ti ti-cloud-download me-1" }),
                                createTextVNode("Adotado "),
                                item.has_update ? (openBlock(), createBlock("span", {
                                  key: 0,
                                  class: "badge bg-warning text-dark ms-1"
                                }, "Atualização disponível")) : createCommentVNode("", true)
                              ])) : (openBlock(), createBlock("span", {
                                key: 1,
                                class: "badge badge-soft-secondary rounded fs-11"
                              }, "Próprio"))
                            ]),
                            createVNode("td", { class: "text-end" }, [
                              createVNode(ActionIconGroup, {
                                align: "end",
                                gap: "tight"
                              }, {
                                default: withCtx(() => [
                                  createVNode(_sfc_main$3, {
                                    icon: "ti ti-eye",
                                    title: "Pré-visualizar",
                                    onClick: ($event) => openPreview(item)
                                  }, null, 8, ["onClick"]),
                                  createVNode(unref(Link), {
                                    href: item.edit_url,
                                    class: "btn btn-sm btn-ghost",
                                    title: "Editar"
                                  }, {
                                    default: withCtx(() => [
                                      createVNode("i", { class: "ti ti-edit" })
                                    ]),
                                    _: 1
                                  }, 8, ["href"]),
                                  createVNode(ActionDropdown, {
                                    "btn-class": "ee-action-icon ee-action-icon--default",
                                    icon: "ti ti-dots-vertical"
                                  }, {
                                    default: withCtx(() => [
                                      item.reimport_url ? (openBlock(), createBlock("li", { key: 0 }, [
                                        createVNode("button", {
                                          class: "dropdown-item rounded-1",
                                          onClick: ($event) => onReimport(item)
                                        }, [
                                          createVNode("i", { class: "ti ti-refresh me-1" }),
                                          createTextVNode("Reimportar template global ")
                                        ], 8, ["onClick"])
                                      ])) : createCommentVNode("", true),
                                      item.reimport_url ? (openBlock(), createBlock("li", { key: 1 }, [
                                        createVNode("hr", { class: "dropdown-divider" })
                                      ])) : createCommentVNode("", true),
                                      createVNode("li", null, [
                                        createVNode("button", {
                                          class: "dropdown-item rounded-1 text-danger",
                                          onClick: ($event) => onDelete(item)
                                        }, [
                                          createVNode("i", { class: "ti ti-trash me-1" }),
                                          createTextVNode("Excluir ")
                                        ], 8, ["onClick"])
                                      ])
                                    ]),
                                    _: 2
                                  }, 1024)
                                ]),
                                _: 2
                              }, 1024)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Settings/ReportSettings/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
