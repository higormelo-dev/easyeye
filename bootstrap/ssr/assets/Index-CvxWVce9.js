import { ref, watch, mergeProps, withCtx, unref, createVNode, createTextVNode, toDisplayString, openBlock, createBlock, Fragment, createCommentVNode, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderClass } from "vue/server-renderer";
import { router, Link } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import { _ as _sfc_main$2 } from "./SearchInput-DBX1PwVy.js";
import { _ as _sfc_main$4 } from "./TablePagination-Dj1_H7YG.js";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { A as ActionIconGroup, _ as _sfc_main$3 } from "./ActionIconGroup-Dj2wQrik.js";
import _sfc_main$5 from "./EntityUserIntegratorFormModal-CTK_SFwa.js";
import EntityUserIntegratorDetailDrawer from "./EntityUserIntegratorDetailDrawer-DqsXt0Mk.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./OffcanvasPanel-VfE7yaou.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    entity: { type: Object, required: true },
    // { id, code, name }
    items: { type: Object, required: true },
    // paginator Laravel
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const breadcrumbs = [
      { label: props.t.breadcrumb_home ?? "Dashboard", url: route("panel.dashboard"), active: false },
      { label: props.t.breadcrumb_entities ?? "Empresas", url: route("manager.entities.index"), active: false },
      { label: props.entity.name, url: "#", active: false },
      { label: props.t.breadcrumb_current ?? "Usuários Integradores", url: "#", active: true }
    ];
    const search = ref(props.filters.search ?? "");
    let searchTimer = null;
    watch(search, (val) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        router.get(
          route("manager.entities.user-integrators.index", props.entity.id),
          { search: val },
          { preserveState: true, preserveScroll: true, replace: true }
        );
      }, 400);
    });
    const formOpen = ref(false);
    const editEntityId = ref(null);
    const editDataUrl = ref("");
    const updateUrl = ref("");
    function openCreate() {
      editEntityId.value = null;
      editDataUrl.value = "";
      updateUrl.value = "";
      formOpen.value = true;
    }
    function openEdit(item) {
      editEntityId.value = item.id;
      editDataUrl.value = item.edit_data_url;
      updateUrl.value = item.update_url;
      formOpen.value = true;
    }
    function closeForm() {
      formOpen.value = false;
    }
    function onSaved() {
      formOpen.value = false;
      router.reload({ only: ["items"] });
    }
    const detailOpen = ref(false);
    const detailUrl = ref("");
    function openDetail(item) {
      detailUrl.value = item.show_url;
      detailOpen.value = true;
    }
    function closeDetail() {
      detailOpen.value = false;
    }
    function csrf() {
      var _a;
      return ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? "";
    }
    async function toggleActive(item) {
      const res = await fetch(item.activate_url, {
        method: "PATCH",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": csrf()
        },
        body: JSON.stringify({ active: !item.active })
      });
      const json = await res.json();
      toast(json.message, res.ok ? "success" : "error");
      if (res.ok) router.reload({ only: ["items"] });
    }
    async function onDelete(item) {
      if (!confirm(props.t.confirm_delete ?? "Excluir este usuário integrador?")) return;
      const res = await fetch(item.destroy_url, {
        method: "DELETE",
        headers: {
          "Accept": "application/json",
          "X-CSRF-TOKEN": csrf()
        }
      });
      const json = await res.json();
      toast(json.message, res.ok ? "success" : "error");
      if (res.ok) router.reload({ only: ["items"] });
    }
    async function onRestore(item) {
      if (!confirm(props.t.confirm_restore ?? "Restaurar este usuário integrador?")) return;
      const res = await fetch(item.restore_url, {
        method: "PUT",
        headers: {
          "Accept": "application/json",
          "X-CSRF-TOKEN": csrf()
        }
      });
      const json = await res.json();
      toast(json.message, res.ok ? "success" : "error");
      if (res.ok) router.reload({ only: ["items"] });
    }
    function toast(msg, type = "success") {
      if (!msg) return;
      if (type === "success" && window.showSuccessToast) return window.showSuccessToast(msg);
      if (type === "error" && window.showErrorToast) return window.showErrorToast(msg);
      alert(msg);
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: __props.t.page_title ?? "Usuários Integradores",
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: __props.t.page_title ?? "Usuários Integradores",
              subtitle: __props.entity.name,
              total: __props.items.total
            }, {
              actions: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(ssrRenderComponent(unref(Link), {
                    href: _ctx.route("manager.entities.index"),
                    class: "btn btn-outline-secondary btn-sm"
                  }, {
                    default: withCtx((_3, _push4, _parent4, _scopeId3) => {
                      if (_push4) {
                        _push4(`<i class="ti ti-arrow-left me-1"${_scopeId3}></i>${ssrInterpolate(__props.t.btn_back ?? "Voltar")}`);
                      } else {
                        return [
                          createVNode("i", { class: "ti ti-arrow-left me-1" }),
                          createTextVNode(toDisplayString(__props.t.btn_back ?? "Voltar"), 1)
                        ];
                      }
                    }),
                    _: 1
                  }, _parent3, _scopeId2));
                  _push3(`<button type="button" class="btn btn-primary btn-sm"${_scopeId2}><i class="ti ti-plus me-1"${_scopeId2}></i>${ssrInterpolate(__props.t.btn_new ?? "Novo")}</button>`);
                } else {
                  return [
                    createVNode(unref(Link), {
                      href: _ctx.route("manager.entities.index"),
                      class: "btn btn-outline-secondary btn-sm"
                    }, {
                      default: withCtx(() => [
                        createVNode("i", { class: "ti ti-arrow-left me-1" }),
                        createTextVNode(toDisplayString(__props.t.btn_back ?? "Voltar"), 1)
                      ]),
                      _: 1
                    }, 8, ["href"]),
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
            _push2(`</div><div class="card"${_scopeId}><div class="table-responsive"${_scopeId}><table class="table table-nowrap table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>${ssrInterpolate(__props.t.col_registered_at ?? "Cadastro")}</th><th${_scopeId}>${ssrInterpolate(__props.t.col_code ?? "Código")}</th><th${_scopeId}>${ssrInterpolate(__props.t.col_name ?? "Nome")}</th><th${_scopeId}>${ssrInterpolate(__props.t.col_email ?? "E-mail")}</th><th class="text-center"${_scopeId}>${ssrInterpolate(__props.t.col_status ?? "Status")}</th><th class="text-end"${_scopeId}>${ssrInterpolate(__props.t.col_actions ?? "Ações")}</th></tr></thead><tbody${_scopeId}>`);
            if (__props.items.data.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="6" class="text-center text-muted py-5"${_scopeId}><i class="ti ti-user-cog fs-1 d-block mb-2"${_scopeId}></i> ${ssrInterpolate(__props.t.empty_list ?? "Nenhum registro.")}</td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.items.data, (u) => {
              _push2(`<tr class="${ssrRenderClass({ "table-secondary opacity-75": u.deleted })}"${_scopeId}><td class="text-muted small"${_scopeId}>${ssrInterpolate(u.created_at)}</td><td${_scopeId}><code class="text-muted small"${_scopeId}>${ssrInterpolate(u.code)}</code></td><td class="fw-medium"${_scopeId}>${ssrInterpolate(u.name)}</td><td class="text-muted"${_scopeId}>${ssrInterpolate(u.email)}</td><td class="text-center"${_scopeId}>`);
              if (u.deleted) {
                _push2(`<span class="badge badge-soft-secondary rounded fs-13 fw-medium"${_scopeId}>${ssrInterpolate(__props.t.status_deleted ?? "Removido")}</span>`);
              } else if (u.active) {
                _push2(`<span class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium"${_scopeId}>${ssrInterpolate(__props.t.status_active ?? "Ativo")}</span>`);
              } else {
                _push2(`<span class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium"${_scopeId}>${ssrInterpolate(__props.t.status_inactive ?? "Inativo")}</span>`);
              }
              _push2(`</td><td class="text-end"${_scopeId}>`);
              _push2(ssrRenderComponent(ActionIconGroup, {
                align: "end",
                gap: "tight"
              }, {
                default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                  if (_push3) {
                    if (u.mode === "restore") {
                      _push3(ssrRenderComponent(_sfc_main$3, {
                        icon: "ti ti-recycle",
                        title: __props.t.action_restore ?? "Restaurar",
                        onClick: ($event) => onRestore(u)
                      }, null, _parent3, _scopeId2));
                    } else {
                      _push3(`<!--[-->`);
                      _push3(ssrRenderComponent(_sfc_main$3, {
                        icon: "ti ti-eye",
                        title: __props.t.action_view ?? "Ver",
                        onClick: ($event) => openDetail(u)
                      }, null, _parent3, _scopeId2));
                      _push3(ssrRenderComponent(_sfc_main$3, {
                        icon: "ti ti-stack-2",
                        title: __props.t.action_integrators ?? "Integradores",
                        variant: "info",
                        href: u.integrators_url
                      }, null, _parent3, _scopeId2));
                      _push3(ssrRenderComponent(ActionDropdown, {
                        "btn-class": "ee-action-icon ee-action-icon--default",
                        icon: "ti ti-dots-vertical"
                      }, {
                        default: withCtx((_3, _push4, _parent4, _scopeId3) => {
                          if (_push4) {
                            _push4(`<li${_scopeId3}><button class="dropdown-item rounded-1"${_scopeId3}><i class="ti ti-edit me-1"${_scopeId3}></i> ${ssrInterpolate(__props.t.action_edit ?? "Editar")}</button></li><li${_scopeId3}><button class="dropdown-item rounded-1"${_scopeId3}><i class="${ssrRenderClass(`ti me-1 ${u.active ? "ti-lock-open" : "ti-lock"}`)}"${_scopeId3}></i> ${ssrInterpolate(u.active ? __props.t.action_deactivate ?? "Desativar" : __props.t.action_activate ?? "Ativar")}</button></li><li${_scopeId3}><hr class="dropdown-divider"${_scopeId3}></li><li${_scopeId3}><button class="dropdown-item rounded-1 text-danger"${_scopeId3}><i class="ti ti-trash me-1"${_scopeId3}></i> ${ssrInterpolate(__props.t.action_delete ?? "Excluir")}</button></li>`);
                          } else {
                            return [
                              createVNode("li", null, [
                                createVNode("button", {
                                  class: "dropdown-item rounded-1",
                                  onClick: ($event) => openEdit(u)
                                }, [
                                  createVNode("i", { class: "ti ti-edit me-1" }),
                                  createTextVNode(" " + toDisplayString(__props.t.action_edit ?? "Editar"), 1)
                                ], 8, ["onClick"])
                              ]),
                              createVNode("li", null, [
                                createVNode("button", {
                                  class: "dropdown-item rounded-1",
                                  onClick: ($event) => toggleActive(u)
                                }, [
                                  createVNode("i", {
                                    class: `ti me-1 ${u.active ? "ti-lock-open" : "ti-lock"}`
                                  }, null, 2),
                                  createTextVNode(" " + toDisplayString(u.active ? __props.t.action_deactivate ?? "Desativar" : __props.t.action_activate ?? "Ativar"), 1)
                                ], 8, ["onClick"])
                              ]),
                              createVNode("li", null, [
                                createVNode("hr", { class: "dropdown-divider" })
                              ]),
                              createVNode("li", null, [
                                createVNode("button", {
                                  class: "dropdown-item rounded-1 text-danger",
                                  onClick: ($event) => onDelete(u)
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
                      u.mode === "restore" ? (openBlock(), createBlock(_sfc_main$3, {
                        key: 0,
                        icon: "ti ti-recycle",
                        title: __props.t.action_restore ?? "Restaurar",
                        onClick: ($event) => onRestore(u)
                      }, null, 8, ["title", "onClick"])) : (openBlock(), createBlock(Fragment, { key: 1 }, [
                        createVNode(_sfc_main$3, {
                          icon: "ti ti-eye",
                          title: __props.t.action_view ?? "Ver",
                          onClick: ($event) => openDetail(u)
                        }, null, 8, ["title", "onClick"]),
                        createVNode(_sfc_main$3, {
                          icon: "ti ti-stack-2",
                          title: __props.t.action_integrators ?? "Integradores",
                          variant: "info",
                          href: u.integrators_url
                        }, null, 8, ["title", "href"]),
                        createVNode(ActionDropdown, {
                          "btn-class": "ee-action-icon ee-action-icon--default",
                          icon: "ti ti-dots-vertical"
                        }, {
                          default: withCtx(() => [
                            createVNode("li", null, [
                              createVNode("button", {
                                class: "dropdown-item rounded-1",
                                onClick: ($event) => openEdit(u)
                              }, [
                                createVNode("i", { class: "ti ti-edit me-1" }),
                                createTextVNode(" " + toDisplayString(__props.t.action_edit ?? "Editar"), 1)
                              ], 8, ["onClick"])
                            ]),
                            createVNode("li", null, [
                              createVNode("button", {
                                class: "dropdown-item rounded-1",
                                onClick: ($event) => toggleActive(u)
                              }, [
                                createVNode("i", {
                                  class: `ti me-1 ${u.active ? "ti-lock-open" : "ti-lock"}`
                                }, null, 2),
                                createTextVNode(" " + toDisplayString(u.active ? __props.t.action_deactivate ?? "Desativar" : __props.t.action_activate ?? "Ativar"), 1)
                              ], 8, ["onClick"])
                            ]),
                            createVNode("li", null, [
                              createVNode("hr", { class: "dropdown-divider" })
                            ]),
                            createVNode("li", null, [
                              createVNode("button", {
                                class: "dropdown-item rounded-1 text-danger",
                                onClick: ($event) => onDelete(u)
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
              data: __props.items,
              class: "mt-3"
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$5, {
              open: formOpen.value,
              "entity-id": __props.entity.id,
              "item-id": editEntityId.value,
              "edit-data-url": editDataUrl.value,
              "update-url": updateUrl.value,
              t: __props.t,
              onClose: closeForm,
              onSaved
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(EntityUserIntegratorDetailDrawer, {
              open: detailOpen.value,
              "show-url": detailUrl.value,
              t: __props.t,
              onClose: closeDetail,
              onEdit: (item) => openEdit(item)
            }, null, _parent2, _scopeId));
            _push2(`</div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, {
                  title: __props.t.page_title ?? "Usuários Integradores",
                  subtitle: __props.entity.name,
                  total: __props.items.total
                }, {
                  actions: withCtx(() => [
                    createVNode(unref(Link), {
                      href: _ctx.route("manager.entities.index"),
                      class: "btn btn-outline-secondary btn-sm"
                    }, {
                      default: withCtx(() => [
                        createVNode("i", { class: "ti ti-arrow-left me-1" }),
                        createTextVNode(toDisplayString(__props.t.btn_back ?? "Voltar"), 1)
                      ]),
                      _: 1
                    }, 8, ["href"]),
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
                }, 8, ["title", "subtitle", "total"]),
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
                          createVNode("th", null, toDisplayString(__props.t.col_registered_at ?? "Cadastro"), 1),
                          createVNode("th", null, toDisplayString(__props.t.col_code ?? "Código"), 1),
                          createVNode("th", null, toDisplayString(__props.t.col_name ?? "Nome"), 1),
                          createVNode("th", null, toDisplayString(__props.t.col_email ?? "E-mail"), 1),
                          createVNode("th", { class: "text-center" }, toDisplayString(__props.t.col_status ?? "Status"), 1),
                          createVNode("th", { class: "text-end" }, toDisplayString(__props.t.col_actions ?? "Ações"), 1)
                        ])
                      ]),
                      createVNode("tbody", null, [
                        __props.items.data.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("td", {
                            colspan: "6",
                            class: "text-center text-muted py-5"
                          }, [
                            createVNode("i", { class: "ti ti-user-cog fs-1 d-block mb-2" }),
                            createTextVNode(" " + toDisplayString(__props.t.empty_list ?? "Nenhum registro."), 1)
                          ])
                        ])) : createCommentVNode("", true),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.items.data, (u) => {
                          return openBlock(), createBlock("tr", {
                            key: u.id,
                            class: { "table-secondary opacity-75": u.deleted }
                          }, [
                            createVNode("td", { class: "text-muted small" }, toDisplayString(u.created_at), 1),
                            createVNode("td", null, [
                              createVNode("code", { class: "text-muted small" }, toDisplayString(u.code), 1)
                            ]),
                            createVNode("td", { class: "fw-medium" }, toDisplayString(u.name), 1),
                            createVNode("td", { class: "text-muted" }, toDisplayString(u.email), 1),
                            createVNode("td", { class: "text-center" }, [
                              u.deleted ? (openBlock(), createBlock("span", {
                                key: 0,
                                class: "badge badge-soft-secondary rounded fs-13 fw-medium"
                              }, toDisplayString(__props.t.status_deleted ?? "Removido"), 1)) : u.active ? (openBlock(), createBlock("span", {
                                key: 1,
                                class: "badge badge-soft-success rounded text-success border border-success fs-13 fw-medium"
                              }, toDisplayString(__props.t.status_active ?? "Ativo"), 1)) : (openBlock(), createBlock("span", {
                                key: 2,
                                class: "badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium"
                              }, toDisplayString(__props.t.status_inactive ?? "Inativo"), 1))
                            ]),
                            createVNode("td", { class: "text-end" }, [
                              createVNode(ActionIconGroup, {
                                align: "end",
                                gap: "tight"
                              }, {
                                default: withCtx(() => [
                                  u.mode === "restore" ? (openBlock(), createBlock(_sfc_main$3, {
                                    key: 0,
                                    icon: "ti ti-recycle",
                                    title: __props.t.action_restore ?? "Restaurar",
                                    onClick: ($event) => onRestore(u)
                                  }, null, 8, ["title", "onClick"])) : (openBlock(), createBlock(Fragment, { key: 1 }, [
                                    createVNode(_sfc_main$3, {
                                      icon: "ti ti-eye",
                                      title: __props.t.action_view ?? "Ver",
                                      onClick: ($event) => openDetail(u)
                                    }, null, 8, ["title", "onClick"]),
                                    createVNode(_sfc_main$3, {
                                      icon: "ti ti-stack-2",
                                      title: __props.t.action_integrators ?? "Integradores",
                                      variant: "info",
                                      href: u.integrators_url
                                    }, null, 8, ["title", "href"]),
                                    createVNode(ActionDropdown, {
                                      "btn-class": "ee-action-icon ee-action-icon--default",
                                      icon: "ti ti-dots-vertical"
                                    }, {
                                      default: withCtx(() => [
                                        createVNode("li", null, [
                                          createVNode("button", {
                                            class: "dropdown-item rounded-1",
                                            onClick: ($event) => openEdit(u)
                                          }, [
                                            createVNode("i", { class: "ti ti-edit me-1" }),
                                            createTextVNode(" " + toDisplayString(__props.t.action_edit ?? "Editar"), 1)
                                          ], 8, ["onClick"])
                                        ]),
                                        createVNode("li", null, [
                                          createVNode("button", {
                                            class: "dropdown-item rounded-1",
                                            onClick: ($event) => toggleActive(u)
                                          }, [
                                            createVNode("i", {
                                              class: `ti me-1 ${u.active ? "ti-lock-open" : "ti-lock"}`
                                            }, null, 2),
                                            createTextVNode(" " + toDisplayString(u.active ? __props.t.action_deactivate ?? "Desativar" : __props.t.action_activate ?? "Ativar"), 1)
                                          ], 8, ["onClick"])
                                        ]),
                                        createVNode("li", null, [
                                          createVNode("hr", { class: "dropdown-divider" })
                                        ]),
                                        createVNode("li", null, [
                                          createVNode("button", {
                                            class: "dropdown-item rounded-1 text-danger",
                                            onClick: ($event) => onDelete(u)
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
                  data: __props.items,
                  class: "mt-3"
                }, null, 8, ["data"]),
                createVNode(_sfc_main$5, {
                  open: formOpen.value,
                  "entity-id": __props.entity.id,
                  "item-id": editEntityId.value,
                  "edit-data-url": editDataUrl.value,
                  "update-url": updateUrl.value,
                  t: __props.t,
                  onClose: closeForm,
                  onSaved
                }, null, 8, ["open", "entity-id", "item-id", "edit-data-url", "update-url", "t"]),
                createVNode(EntityUserIntegratorDetailDrawer, {
                  open: detailOpen.value,
                  "show-url": detailUrl.value,
                  t: __props.t,
                  onClose: closeDetail,
                  onEdit: (item) => openEdit(item)
                }, null, 8, ["open", "show-url", "t", "onEdit"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/EntityUserIntegrators/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
