import { ref, watch, computed, mergeProps, withCtx, createVNode, createTextVNode, toDisplayString, openBlock, createBlock, Fragment, renderList, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderAttr, ssrRenderClass, ssrRenderStyle } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import { _ as _sfc_main$2 } from "./SearchInput-DBX1PwVy.js";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { A as ActionIconGroup, _ as _sfc_main$3 } from "./ActionIconGroup-Dj2wQrik.js";
import _sfc_main$4 from "./CatalogFormModal-DTObnO2U.js";
import CatalogDetailDrawer from "./CatalogDetailDrawer-n8kb2n9f.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./OffcanvasPanel-VfE7yaou.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    meta: { type: Object, required: true },
    breadcrumbs: { type: Array, default: () => [] },
    columns: { type: Array, required: true },
    fields: { type: Array, required: true },
    crudFields: { type: Object, required: true },
    routes: { type: Object, required: true },
    // { index, store, cards }
    urlTemplates: { type: Object, required: true },
    // { show, update, destroy, restore }
    items: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const search = ref(props.filters.search ?? "");
    let searchTimer = null;
    watch(search, (val) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        router.get(props.routes.index, { search: val }, {
          preserveState: true,
          preserveScroll: true,
          replace: true
        });
      }, 400);
    });
    const formOpen = ref(false);
    const editingId = ref(null);
    function openCreate() {
      editingId.value = null;
      formOpen.value = true;
    }
    function openEdit(item) {
      editingId.value = item.id;
      formOpen.value = true;
    }
    function onSaved() {
      formOpen.value = false;
      router.reload({ only: ["items", "meta"] });
    }
    const detailOpen = ref(false);
    const detailItem = ref(null);
    function openDetail(item) {
      detailItem.value = item;
      detailOpen.value = true;
    }
    function urlFor(action, id) {
      return (props.urlTemplates[action] ?? "").replace("__ID__", id);
    }
    function csrf() {
      var _a;
      return ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? "";
    }
    function showToast(msg, type = "success") {
      if (!msg) return;
      if (type === "success" && window.showSuccessToast) return window.showSuccessToast(msg);
      if (type === "error" && window.showErrorToast) return window.showErrorToast(msg);
    }
    async function toggleActive(item) {
      const url = urlFor("update", item.id);
      const res = await fetch(url, {
        method: "POST",
        // _method=PATCH (compatível com FormRequest)
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-CSRF-TOKEN": csrf()
        },
        body: JSON.stringify({ ...crudPayloadFor(item), active: !item.active, _method: "PATCH" })
      });
      const json = await res.json();
      showToast(json.message, res.ok ? "success" : "error");
      if (res.ok) router.reload({ only: ["items"] });
    }
    function crudPayloadFor(item) {
      const payload = {};
      for (const key of Object.keys(props.crudFields)) {
        payload[key] = item[key];
      }
      return payload;
    }
    async function onDelete(item) {
      if (!confirm(props.t.confirm_delete ?? "Excluir este registro?")) return;
      const res = await fetch(urlFor("destroy", item.id), {
        method: "DELETE",
        headers: { "Accept": "application/json", "X-CSRF-TOKEN": csrf() }
      });
      const json = await res.json();
      showToast(json.message, res.ok ? "success" : "error");
      if (res.ok) router.reload({ only: ["items", "meta"] });
    }
    async function onRestore(item) {
      if (!confirm(props.t.confirm_restore ?? "Restaurar este registro?")) return;
      const res = await fetch(urlFor("restore", item.id), {
        method: "GET",
        // rota legada é GET; mantemos compatibilidade
        headers: { "Accept": "application/json", "X-CSRF-TOKEN": csrf() }
      });
      const json = await res.json();
      showToast(json.message, res.ok ? "success" : "error");
      if (res.ok) router.reload({ only: ["items", "meta"] });
    }
    function cellValue(item, col) {
      const v = item[col.key];
      if (v === null || v === void 0 || v === "") return "—";
      return v;
    }
    const totalLabel = computed(() => `${props.t.total_label ?? "Total:"} ${props.items.length}`);
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: __props.meta.title,
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: __props.meta.title,
              subtitle: totalLabel.value
            }, {
              actions: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`<button type="button" class="btn btn-primary btn-sm"${_scopeId2}><i class="ti ti-plus me-1"${_scopeId2}></i>${ssrInterpolate(__props.t.btn_new ?? "Novo")}</button>`);
                } else {
                  return [
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-primary btn-sm",
                      onClick: openCreate
                    }, [
                      createVNode("i", { class: "ti ti-plus me-1" }),
                      createTextVNode(toDisplayString(__props.t.btn_new ?? "Novo"), 1)
                    ])
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`<div class="d-flex align-items-center mb-3 gap-2 flex-wrap"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$2, {
              modelValue: search.value,
              "onUpdate:modelValue": ($event) => search.value = $event,
              placeholder: __props.t.search_placeholder ?? "Buscar...",
              style: { "min-width": "280px" }
            }, null, _parent2, _scopeId));
            _push2(`</div><div class="card"${_scopeId}><div class="table-responsive"${_scopeId}><table class="table table-nowrap table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><!--[-->`);
            ssrRenderList(__props.columns, (col) => {
              _push2(`<th${_scopeId}>${ssrInterpolate(col.label)}</th>`);
            });
            _push2(`<!--]--><th class="text-center"${_scopeId}>${ssrInterpolate(__props.t.col_status ?? "Status")}</th><th class="text-end"${_scopeId}>${ssrInterpolate(__props.t.col_actions ?? "Ações")}</th></tr></thead><tbody${_scopeId}>`);
            if (__props.items.length === 0) {
              _push2(`<tr${_scopeId}><td${ssrRenderAttr("colspan", __props.columns.length + 2)} class="text-center text-muted py-5"${_scopeId}><i class="ti ti-folder-off fs-1 d-block mb-2"${_scopeId}></i> ${ssrInterpolate(__props.t.empty_list ?? "Nenhum registro.")}</td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.items, (item) => {
              _push2(`<tr class="${ssrRenderClass({ "table-secondary opacity-75": item.deleted })}"${_scopeId}><!--[-->`);
              ssrRenderList(__props.columns, (col) => {
                _push2(`<td${_scopeId}>`);
                if (!col.type || col.type === "text") {
                  _push2(`<span class="${ssrRenderClass(col.key === "name" ? "fw-medium" : "text-muted")}"${_scopeId}>${ssrInterpolate(cellValue(item, col))}</span>`);
                } else if (col.type === "code") {
                  _push2(`<code class="text-muted small"${_scopeId}>${ssrInterpolate(cellValue(item, col))}</code>`);
                } else if (col.type === "abbrev") {
                  _push2(`<span class="badge badge-soft-info rounded fs-11 fw-medium"${_scopeId}>${ssrInterpolate(cellValue(item, col))}</span>`);
                } else if (col.type === "color") {
                  _push2(`<span class="d-inline-flex align-items-center gap-1"${_scopeId}><span class="rounded-circle border" style="${ssrRenderStyle(`background:${item[col.key] ?? "#ccc"}; width:18px; height:18px; display:inline-block;`)}"${_scopeId}></span><code class="small text-muted"${_scopeId}>${ssrInterpolate(cellValue(item, col))}</code></span>`);
                } else if (col.type === "yesno") {
                  _push2(`<span class="${ssrRenderClass(`badge rounded fs-11 ${item[col.key] ? "badge-soft-success text-success border border-success" : "badge-soft-secondary"}`)}"${_scopeId}>${ssrInterpolate(item[col.key] ? __props.t.yes ?? "Sim" : __props.t.no ?? "Não")}</span>`);
                } else if (col.type === "numeric") {
                  _push2(`<span class="font-monospace small"${_scopeId}>${ssrInterpolate(Number(item[col.key] ?? 0).toLocaleString("pt-BR"))}</span>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</td>`);
              });
              _push2(`<!--]--><td class="text-center"${_scopeId}>`);
              if (item.deleted) {
                _push2(`<span class="badge badge-soft-secondary rounded fs-13 fw-medium"${_scopeId}>${ssrInterpolate(__props.t.status_deleted ?? "Removido")}</span>`);
              } else if (item.active) {
                _push2(`<span class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium"${_scopeId}>${ssrInterpolate(__props.t.status_active ?? "Ativo")}</span>`);
              } else {
                _push2(`<span class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium"${_scopeId}>${ssrInterpolate(__props.t.status_inactive ?? "Inativo")}</span>`);
              }
              if (item.is_global) {
                _push2(`<span class="badge badge-soft-info rounded ms-1 fs-11"${ssrRenderAttr("title", __props.t.status_global)}${_scopeId}><i class="ti ti-star-filled"${_scopeId}></i></span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</td><td class="text-end"${_scopeId}>`);
              _push2(ssrRenderComponent(ActionIconGroup, {
                align: "end",
                gap: "tight"
              }, {
                default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                  if (_push3) {
                    if (item.deleted) {
                      _push3(ssrRenderComponent(_sfc_main$3, {
                        icon: "ti ti-recycle",
                        title: __props.t.action_restore ?? "Restaurar",
                        onClick: ($event) => onRestore(item)
                      }, null, _parent3, _scopeId2));
                    } else {
                      _push3(`<!--[-->`);
                      _push3(ssrRenderComponent(_sfc_main$3, {
                        icon: "ti ti-eye",
                        title: __props.t.action_view ?? "Ver",
                        onClick: ($event) => openDetail(item)
                      }, null, _parent3, _scopeId2));
                      _push3(ssrRenderComponent(ActionDropdown, {
                        "btn-class": "ee-action-icon ee-action-icon--default",
                        icon: "ti ti-dots-vertical"
                      }, {
                        default: withCtx((_3, _push4, _parent4, _scopeId3) => {
                          if (_push4) {
                            _push4(`<li${_scopeId3}><button class="dropdown-item rounded-1"${_scopeId3}><i class="ti ti-edit me-1"${_scopeId3}></i> ${ssrInterpolate(__props.t.action_edit ?? "Editar")}</button></li><li${_scopeId3}><button class="dropdown-item rounded-1"${_scopeId3}><i class="${ssrRenderClass(`ti me-1 ${item.active ? "ti-lock-open" : "ti-lock"}`)}"${_scopeId3}></i> ${ssrInterpolate(item.active ? __props.t.action_deactivate ?? "Desativar" : __props.t.action_activate ?? "Ativar")}</button></li><li${_scopeId3}><hr class="dropdown-divider"${_scopeId3}></li><li${_scopeId3}><button class="dropdown-item rounded-1 text-danger"${_scopeId3}><i class="ti ti-trash me-1"${_scopeId3}></i> ${ssrInterpolate(__props.t.action_delete ?? "Excluir")}</button></li>`);
                          } else {
                            return [
                              createVNode("li", null, [
                                createVNode("button", {
                                  class: "dropdown-item rounded-1",
                                  onClick: ($event) => openEdit(item)
                                }, [
                                  createVNode("i", { class: "ti ti-edit me-1" }),
                                  createTextVNode(" " + toDisplayString(__props.t.action_edit ?? "Editar"), 1)
                                ], 8, ["onClick"])
                              ]),
                              createVNode("li", null, [
                                createVNode("button", {
                                  class: "dropdown-item rounded-1",
                                  onClick: ($event) => toggleActive(item)
                                }, [
                                  createVNode("i", {
                                    class: `ti me-1 ${item.active ? "ti-lock-open" : "ti-lock"}`
                                  }, null, 2),
                                  createTextVNode(" " + toDisplayString(item.active ? __props.t.action_deactivate ?? "Desativar" : __props.t.action_activate ?? "Ativar"), 1)
                                ], 8, ["onClick"])
                              ]),
                              createVNode("li", null, [
                                createVNode("hr", { class: "dropdown-divider" })
                              ]),
                              createVNode("li", null, [
                                createVNode("button", {
                                  class: "dropdown-item rounded-1 text-danger",
                                  onClick: ($event) => onDelete(item)
                                }, [
                                  createVNode("i", { class: "ti ti-trash me-1" }),
                                  createTextVNode(" " + toDisplayString(__props.t.action_delete ?? "Excluir"), 1)
                                ], 8, ["onClick"])
                              ])
                            ];
                          }
                        }),
                        _: 2
                      }, _parent3, _scopeId2));
                      _push3(`<!--]-->`);
                    }
                  } else {
                    return [
                      item.deleted ? (openBlock(), createBlock(_sfc_main$3, {
                        key: 0,
                        icon: "ti ti-recycle",
                        title: __props.t.action_restore ?? "Restaurar",
                        onClick: ($event) => onRestore(item)
                      }, null, 8, ["title", "onClick"])) : (openBlock(), createBlock(Fragment, { key: 1 }, [
                        createVNode(_sfc_main$3, {
                          icon: "ti ti-eye",
                          title: __props.t.action_view ?? "Ver",
                          onClick: ($event) => openDetail(item)
                        }, null, 8, ["title", "onClick"]),
                        createVNode(ActionDropdown, {
                          "btn-class": "ee-action-icon ee-action-icon--default",
                          icon: "ti ti-dots-vertical"
                        }, {
                          default: withCtx(() => [
                            createVNode("li", null, [
                              createVNode("button", {
                                class: "dropdown-item rounded-1",
                                onClick: ($event) => openEdit(item)
                              }, [
                                createVNode("i", { class: "ti ti-edit me-1" }),
                                createTextVNode(" " + toDisplayString(__props.t.action_edit ?? "Editar"), 1)
                              ], 8, ["onClick"])
                            ]),
                            createVNode("li", null, [
                              createVNode("button", {
                                class: "dropdown-item rounded-1",
                                onClick: ($event) => toggleActive(item)
                              }, [
                                createVNode("i", {
                                  class: `ti me-1 ${item.active ? "ti-lock-open" : "ti-lock"}`
                                }, null, 2),
                                createTextVNode(" " + toDisplayString(item.active ? __props.t.action_deactivate ?? "Desativar" : __props.t.action_activate ?? "Ativar"), 1)
                              ], 8, ["onClick"])
                            ]),
                            createVNode("li", null, [
                              createVNode("hr", { class: "dropdown-divider" })
                            ]),
                            createVNode("li", null, [
                              createVNode("button", {
                                class: "dropdown-item rounded-1 text-danger",
                                onClick: ($event) => onDelete(item)
                              }, [
                                createVNode("i", { class: "ti ti-trash me-1" }),
                                createTextVNode(" " + toDisplayString(__props.t.action_delete ?? "Excluir"), 1)
                              ], 8, ["onClick"])
                            ])
                          ]),
                          _: 2
                        }, 1024)
                      ], 64))
                    ];
                  }
                }),
                _: 2
              }, _parent2, _scopeId));
              _push2(`</td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div></div>`);
            _push2(ssrRenderComponent(_sfc_main$4, {
              open: formOpen.value,
              "item-id": editingId.value,
              fields: __props.fields,
              "crud-fields": __props.crudFields,
              "url-templates": __props.urlTemplates,
              "store-url": __props.routes.store,
              t: __props.t,
              onClose: ($event) => formOpen.value = false,
              onSaved
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(CatalogDetailDrawer, {
              open: detailOpen.value,
              item: detailItem.value,
              columns: __props.columns,
              t: __props.t,
              onClose: ($event) => detailOpen.value = false,
              onEdit: (item) => {
                detailOpen.value = false;
                openEdit(item);
              }
            }, null, _parent2, _scopeId));
            _push2(`</div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, {
                  title: __props.meta.title,
                  subtitle: totalLabel.value
                }, {
                  actions: withCtx(() => [
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-primary btn-sm",
                      onClick: openCreate
                    }, [
                      createVNode("i", { class: "ti ti-plus me-1" }),
                      createTextVNode(toDisplayString(__props.t.btn_new ?? "Novo"), 1)
                    ])
                  ]),
                  _: 1
                }, 8, ["title", "subtitle"]),
                createVNode("div", { class: "d-flex align-items-center mb-3 gap-2 flex-wrap" }, [
                  createVNode(_sfc_main$2, {
                    modelValue: search.value,
                    "onUpdate:modelValue": ($event) => search.value = $event,
                    placeholder: __props.t.search_placeholder ?? "Buscar...",
                    style: { "min-width": "280px" }
                  }, null, 8, ["modelValue", "onUpdate:modelValue", "placeholder"])
                ]),
                createVNode("div", { class: "card" }, [
                  createVNode("div", { class: "table-responsive" }, [
                    createVNode("table", { class: "table table-nowrap table-hover align-middle mb-0" }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          (openBlock(true), createBlock(Fragment, null, renderList(__props.columns, (col) => {
                            return openBlock(), createBlock("th", {
                              key: col.key
                            }, toDisplayString(col.label), 1);
                          }), 128)),
                          createVNode("th", { class: "text-center" }, toDisplayString(__props.t.col_status ?? "Status"), 1),
                          createVNode("th", { class: "text-end" }, toDisplayString(__props.t.col_actions ?? "Ações"), 1)
                        ])
                      ]),
                      createVNode("tbody", null, [
                        __props.items.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("td", {
                            colspan: __props.columns.length + 2,
                            class: "text-center text-muted py-5"
                          }, [
                            createVNode("i", { class: "ti ti-folder-off fs-1 d-block mb-2" }),
                            createTextVNode(" " + toDisplayString(__props.t.empty_list ?? "Nenhum registro."), 1)
                          ], 8, ["colspan"])
                        ])) : createCommentVNode("", true),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.items, (item) => {
                          return openBlock(), createBlock("tr", {
                            key: item.id,
                            class: { "table-secondary opacity-75": item.deleted }
                          }, [
                            (openBlock(true), createBlock(Fragment, null, renderList(__props.columns, (col) => {
                              return openBlock(), createBlock("td", {
                                key: col.key
                              }, [
                                !col.type || col.type === "text" ? (openBlock(), createBlock("span", {
                                  key: 0,
                                  class: col.key === "name" ? "fw-medium" : "text-muted"
                                }, toDisplayString(cellValue(item, col)), 3)) : col.type === "code" ? (openBlock(), createBlock("code", {
                                  key: 1,
                                  class: "text-muted small"
                                }, toDisplayString(cellValue(item, col)), 1)) : col.type === "abbrev" ? (openBlock(), createBlock("span", {
                                  key: 2,
                                  class: "badge badge-soft-info rounded fs-11 fw-medium"
                                }, toDisplayString(cellValue(item, col)), 1)) : col.type === "color" ? (openBlock(), createBlock("span", {
                                  key: 3,
                                  class: "d-inline-flex align-items-center gap-1"
                                }, [
                                  createVNode("span", {
                                    class: "rounded-circle border",
                                    style: `background:${item[col.key] ?? "#ccc"}; width:18px; height:18px; display:inline-block;`
                                  }, null, 4),
                                  createVNode("code", { class: "small text-muted" }, toDisplayString(cellValue(item, col)), 1)
                                ])) : col.type === "yesno" ? (openBlock(), createBlock("span", {
                                  key: 4,
                                  class: `badge rounded fs-11 ${item[col.key] ? "badge-soft-success text-success border border-success" : "badge-soft-secondary"}`
                                }, toDisplayString(item[col.key] ? __props.t.yes ?? "Sim" : __props.t.no ?? "Não"), 3)) : col.type === "numeric" ? (openBlock(), createBlock("span", {
                                  key: 5,
                                  class: "font-monospace small"
                                }, toDisplayString(Number(item[col.key] ?? 0).toLocaleString("pt-BR")), 1)) : createCommentVNode("", true)
                              ]);
                            }), 128)),
                            createVNode("td", { class: "text-center" }, [
                              item.deleted ? (openBlock(), createBlock("span", {
                                key: 0,
                                class: "badge badge-soft-secondary rounded fs-13 fw-medium"
                              }, toDisplayString(__props.t.status_deleted ?? "Removido"), 1)) : item.active ? (openBlock(), createBlock("span", {
                                key: 1,
                                class: "badge badge-soft-success rounded text-success border border-success fs-13 fw-medium"
                              }, toDisplayString(__props.t.status_active ?? "Ativo"), 1)) : (openBlock(), createBlock("span", {
                                key: 2,
                                class: "badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium"
                              }, toDisplayString(__props.t.status_inactive ?? "Inativo"), 1)),
                              item.is_global ? (openBlock(), createBlock("span", {
                                key: 3,
                                class: "badge badge-soft-info rounded ms-1 fs-11",
                                title: __props.t.status_global
                              }, [
                                createVNode("i", { class: "ti ti-star-filled" })
                              ], 8, ["title"])) : createCommentVNode("", true)
                            ]),
                            createVNode("td", { class: "text-end" }, [
                              createVNode(ActionIconGroup, {
                                align: "end",
                                gap: "tight"
                              }, {
                                default: withCtx(() => [
                                  item.deleted ? (openBlock(), createBlock(_sfc_main$3, {
                                    key: 0,
                                    icon: "ti ti-recycle",
                                    title: __props.t.action_restore ?? "Restaurar",
                                    onClick: ($event) => onRestore(item)
                                  }, null, 8, ["title", "onClick"])) : (openBlock(), createBlock(Fragment, { key: 1 }, [
                                    createVNode(_sfc_main$3, {
                                      icon: "ti ti-eye",
                                      title: __props.t.action_view ?? "Ver",
                                      onClick: ($event) => openDetail(item)
                                    }, null, 8, ["title", "onClick"]),
                                    createVNode(ActionDropdown, {
                                      "btn-class": "ee-action-icon ee-action-icon--default",
                                      icon: "ti ti-dots-vertical"
                                    }, {
                                      default: withCtx(() => [
                                        createVNode("li", null, [
                                          createVNode("button", {
                                            class: "dropdown-item rounded-1",
                                            onClick: ($event) => openEdit(item)
                                          }, [
                                            createVNode("i", { class: "ti ti-edit me-1" }),
                                            createTextVNode(" " + toDisplayString(__props.t.action_edit ?? "Editar"), 1)
                                          ], 8, ["onClick"])
                                        ]),
                                        createVNode("li", null, [
                                          createVNode("button", {
                                            class: "dropdown-item rounded-1",
                                            onClick: ($event) => toggleActive(item)
                                          }, [
                                            createVNode("i", {
                                              class: `ti me-1 ${item.active ? "ti-lock-open" : "ti-lock"}`
                                            }, null, 2),
                                            createTextVNode(" " + toDisplayString(item.active ? __props.t.action_deactivate ?? "Desativar" : __props.t.action_activate ?? "Ativar"), 1)
                                          ], 8, ["onClick"])
                                        ]),
                                        createVNode("li", null, [
                                          createVNode("hr", { class: "dropdown-divider" })
                                        ]),
                                        createVNode("li", null, [
                                          createVNode("button", {
                                            class: "dropdown-item rounded-1 text-danger",
                                            onClick: ($event) => onDelete(item)
                                          }, [
                                            createVNode("i", { class: "ti ti-trash me-1" }),
                                            createTextVNode(" " + toDisplayString(__props.t.action_delete ?? "Excluir"), 1)
                                          ], 8, ["onClick"])
                                        ])
                                      ]),
                                      _: 2
                                    }, 1024)
                                  ], 64))
                                ]),
                                _: 2
                              }, 1024)
                            ])
                          ], 2);
                        }), 128))
                      ])
                    ])
                  ])
                ]),
                createVNode(_sfc_main$4, {
                  open: formOpen.value,
                  "item-id": editingId.value,
                  fields: __props.fields,
                  "crud-fields": __props.crudFields,
                  "url-templates": __props.urlTemplates,
                  "store-url": __props.routes.store,
                  t: __props.t,
                  onClose: ($event) => formOpen.value = false,
                  onSaved
                }, null, 8, ["open", "item-id", "fields", "crud-fields", "url-templates", "store-url", "t", "onClose"]),
                createVNode(CatalogDetailDrawer, {
                  open: detailOpen.value,
                  item: detailItem.value,
                  columns: __props.columns,
                  t: __props.t,
                  onClose: ($event) => detailOpen.value = false,
                  onEdit: (item) => {
                    detailOpen.value = false;
                    openEdit(item);
                  }
                }, null, 8, ["open", "item", "columns", "t", "onClose", "onEdit"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Settings/Catalog/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
