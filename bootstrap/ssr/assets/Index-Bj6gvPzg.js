import { ref, watch, mergeProps, withCtx, unref, createVNode, createTextVNode, toDisplayString, openBlock, createBlock, createCommentVNode, Fragment, renderList, withModifiers, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderClass, ssrRenderStyle, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { router, useForm, Link } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-CYjDf0Y-.js";
import { _ as _sfc_main$2 } from "./SearchInput-DBX1PwVy.js";
import { _ as _sfc_main$4 } from "./TablePagination-Dj1_H7YG.js";
import { _ as _sfc_main$3 } from "./ActionIconButton-BTsQtzdl.js";
import { A as ActionIconGroup } from "./ActionIconGroup-B8JEjj1z.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    entity: { type: Object, required: true },
    // { id, code, name }
    users: { type: Object, required: true },
    // paginator Laravel
    filters: { type: Object, default: () => ({}) },
    isImpersonating: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const breadcrumbs = [
      { label: props.t.breadcrumb_home ?? "Dashboard", url: route("panel.dashboard"), active: false },
      { label: props.t.breadcrumb_entities ?? "Empresas", url: route("manager.entities.index"), active: false },
      { label: props.entity.name, url: "#", active: false },
      { label: props.t.breadcrumb_current ?? "Usuários", url: "#", active: true }
    ];
    const search = ref(props.filters.search ?? "");
    let searchTimer = null;
    watch(search, (val) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        router.get(
          route("manager.entities.users", props.entity.id),
          { search: val },
          { preserveState: true, preserveScroll: true, replace: true }
        );
      }, 400);
    });
    const impersonateForm = useForm({});
    const pendingUser = ref(null);
    function askImpersonate(user) {
      if (!user.can_impersonate) return;
      pendingUser.value = user;
    }
    function cancelImpersonate() {
      pendingUser.value = null;
    }
    function confirmImpersonate() {
      var _a;
      if (!((_a = pendingUser.value) == null ? void 0 : _a.impersonate_url)) return;
      impersonateForm.post(pendingUser.value.impersonate_url, {
        preserveScroll: false,
        onFinish: () => {
          pendingUser.value = null;
        }
      });
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: __props.t.page_title ?? "Usuários",
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: __props.t.page_title ?? "Usuários",
              subtitle: __props.entity.name,
              total: __props.users.total
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
                    }, 8, ["href"])
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            if (__props.isImpersonating) {
              _push2(`<div class="alert alert-warning py-2 mb-3 small"${_scopeId}><i class="ti ti-alert-triangle me-1"${_scopeId}></i> Você já está em sessão de impersonação. Encerre a sessão atual antes de iniciar outra. </div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="d-flex align-items-center mb-3 gap-2 flex-wrap"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$2, {
              modelValue: search.value,
              "onUpdate:modelValue": ($event) => search.value = $event,
              placeholder: __props.t.search_placeholder ?? "Buscar...",
              style: { "min-width": "280px" }
            }, null, _parent2, _scopeId));
            _push2(`</div><div class="card"${_scopeId}><div class="table-responsive"${_scopeId}><table class="table table-nowrap table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>${ssrInterpolate(__props.t.col_registered_at ?? "Cadastro")}</th><th${_scopeId}>${ssrInterpolate(__props.t.col_code ?? "Código")}</th><th${_scopeId}>${ssrInterpolate(__props.t.col_name ?? "Nome")}</th><th${_scopeId}>${ssrInterpolate(__props.t.col_email ?? "E-mail")}</th><th${_scopeId}>${ssrInterpolate(__props.t.col_rule ?? "Papel")}</th><th class="text-center"${_scopeId}>${ssrInterpolate(__props.t.col_status ?? "Status")}</th><th class="text-end"${_scopeId}>${ssrInterpolate(__props.t.col_actions ?? "Ações")}</th></tr></thead><tbody${_scopeId}>`);
            if (__props.users.data.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="7" class="text-center text-muted py-5"${_scopeId}><i class="ti ti-users-off fs-1 d-block mb-2"${_scopeId}></i> ${ssrInterpolate(__props.t.empty_list ?? "Nenhum usuário encontrado.")}</td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.users.data, (u) => {
              _push2(`<tr class="${ssrRenderClass({ "table-secondary opacity-75": u.deleted })}"${_scopeId}><td class="text-muted small"${_scopeId}>${ssrInterpolate(u.created_at)}</td><td${_scopeId}><code class="text-muted small"${_scopeId}>${ssrInterpolate(u.code)}</code></td><td class="fw-medium"${_scopeId}>${ssrInterpolate(u.name)}</td><td class="text-muted"${_scopeId}>${ssrInterpolate(u.email)}</td><td${_scopeId}>`);
              if (u.rule) {
                _push2(`<span class="badge bg-light text-dark border"${_scopeId}>${ssrInterpolate(u.rule)}</span>`);
              } else {
                _push2(`<span class="text-muted"${_scopeId}>—</span>`);
              }
              _push2(`</td><td class="text-center"${_scopeId}>`);
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
                    _push3(ssrRenderComponent(_sfc_main$3, {
                      icon: "ti ti-user-cog",
                      title: u.can_impersonate ? __props.t.action_impersonate ?? "Entrar como este usuário" : __props.t.action_impersonate_disabled ?? "Não é possível impersonar",
                      variant: "info",
                      disabled: !u.can_impersonate,
                      onClick: ($event) => askImpersonate(u)
                    }, null, _parent3, _scopeId2));
                  } else {
                    return [
                      createVNode(_sfc_main$3, {
                        icon: "ti ti-user-cog",
                        title: u.can_impersonate ? __props.t.action_impersonate ?? "Entrar como este usuário" : __props.t.action_impersonate_disabled ?? "Não é possível impersonar",
                        variant: "info",
                        disabled: !u.can_impersonate,
                        onClick: ($event) => askImpersonate(u)
                      }, null, 8, ["title", "disabled", "onClick"])
                    ];
                  }
                }),
                _: 2
              }, _parent2, _scopeId));
              _push2(`</td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div></div>`);
            _push2(ssrRenderComponent(_sfc_main$4, {
              data: __props.users,
              class: "mt-3"
            }, null, _parent2, _scopeId));
            if (pendingUser.value) {
              _push2(`<div class="modal d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.45)" })}"${_scopeId}><div class="modal-dialog modal-dialog-centered"${_scopeId}><div class="modal-content"${_scopeId}><div class="modal-header"${_scopeId}><h5 class="modal-title"${_scopeId}><i class="ti ti-user-cog me-1 text-info"${_scopeId}></i> ${ssrInterpolate((__props.t.confirm_impersonate_title ?? "Entrar como :name?").replace(":name", pendingUser.value.name))}</h5><button type="button" class="btn-close"${_scopeId}></button></div><div class="modal-body"${_scopeId}><p class="mb-2"${_scopeId}>${ssrInterpolate(__props.t.confirm_impersonate_text ?? "Você assumirá temporariamente o contexto desta clínica.")}</p><div class="small text-muted"${_scopeId}><strong${_scopeId}>${ssrInterpolate(__props.t.col_email ?? "E-mail")}:</strong> ${ssrInterpolate(pendingUser.value.email)}<br${_scopeId}><strong${_scopeId}>${ssrInterpolate(__props.t.col_rule ?? "Papel")}:</strong> ${ssrInterpolate(pendingUser.value.rule || "—")}</div></div><div class="modal-footer"${_scopeId}><button type="button" class="btn btn-outline-secondary btn-sm"${ssrIncludeBooleanAttr(unref(impersonateForm).processing) ? " disabled" : ""}${_scopeId}>${ssrInterpolate(__props.t.confirm_impersonate_no ?? "Cancelar")}</button><button type="button" class="btn btn-info text-white btn-sm"${ssrIncludeBooleanAttr(unref(impersonateForm).processing) ? " disabled" : ""}${_scopeId}>`);
              if (unref(impersonateForm).processing) {
                _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
              } else {
                _push2(`<i class="ti ti-check me-1"${_scopeId}></i>`);
              }
              _push2(` ${ssrInterpolate(__props.t.confirm_impersonate_yes ?? "Sim, continuar")}</button></div></div></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, {
                  title: __props.t.page_title ?? "Usuários",
                  subtitle: __props.entity.name,
                  total: __props.users.total
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
                    }, 8, ["href"])
                  ]),
                  _: 1
                }, 8, ["title", "subtitle", "total"]),
                __props.isImpersonating ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "alert alert-warning py-2 mb-3 small"
                }, [
                  createVNode("i", { class: "ti ti-alert-triangle me-1" }),
                  createTextVNode(" Você já está em sessão de impersonação. Encerre a sessão atual antes de iniciar outra. ")
                ])) : createCommentVNode("", true),
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
                          createVNode("th", null, toDisplayString(__props.t.col_rule ?? "Papel"), 1),
                          createVNode("th", { class: "text-center" }, toDisplayString(__props.t.col_status ?? "Status"), 1),
                          createVNode("th", { class: "text-end" }, toDisplayString(__props.t.col_actions ?? "Ações"), 1)
                        ])
                      ]),
                      createVNode("tbody", null, [
                        __props.users.data.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("td", {
                            colspan: "7",
                            class: "text-center text-muted py-5"
                          }, [
                            createVNode("i", { class: "ti ti-users-off fs-1 d-block mb-2" }),
                            createTextVNode(" " + toDisplayString(__props.t.empty_list ?? "Nenhum usuário encontrado."), 1)
                          ])
                        ])) : createCommentVNode("", true),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.users.data, (u) => {
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
                            createVNode("td", null, [
                              u.rule ? (openBlock(), createBlock("span", {
                                key: 0,
                                class: "badge bg-light text-dark border"
                              }, toDisplayString(u.rule), 1)) : (openBlock(), createBlock("span", {
                                key: 1,
                                class: "text-muted"
                              }, "—"))
                            ]),
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
                                  createVNode(_sfc_main$3, {
                                    icon: "ti ti-user-cog",
                                    title: u.can_impersonate ? __props.t.action_impersonate ?? "Entrar como este usuário" : __props.t.action_impersonate_disabled ?? "Não é possível impersonar",
                                    variant: "info",
                                    disabled: !u.can_impersonate,
                                    onClick: ($event) => askImpersonate(u)
                                  }, null, 8, ["title", "disabled", "onClick"])
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
                  data: __props.users,
                  class: "mt-3"
                }, null, 8, ["data"]),
                pendingUser.value ? (openBlock(), createBlock("div", {
                  key: 1,
                  class: "modal d-block",
                  tabindex: "-1",
                  style: { "background": "rgba(0,0,0,.45)" },
                  onClick: withModifiers(cancelImpersonate, ["self"])
                }, [
                  createVNode("div", { class: "modal-dialog modal-dialog-centered" }, [
                    createVNode("div", { class: "modal-content" }, [
                      createVNode("div", { class: "modal-header" }, [
                        createVNode("h5", { class: "modal-title" }, [
                          createVNode("i", { class: "ti ti-user-cog me-1 text-info" }),
                          createTextVNode(" " + toDisplayString((__props.t.confirm_impersonate_title ?? "Entrar como :name?").replace(":name", pendingUser.value.name)), 1)
                        ]),
                        createVNode("button", {
                          type: "button",
                          class: "btn-close",
                          onClick: cancelImpersonate
                        })
                      ]),
                      createVNode("div", { class: "modal-body" }, [
                        createVNode("p", { class: "mb-2" }, toDisplayString(__props.t.confirm_impersonate_text ?? "Você assumirá temporariamente o contexto desta clínica."), 1),
                        createVNode("div", { class: "small text-muted" }, [
                          createVNode("strong", null, toDisplayString(__props.t.col_email ?? "E-mail") + ":", 1),
                          createTextVNode(" " + toDisplayString(pendingUser.value.email), 1),
                          createVNode("br"),
                          createVNode("strong", null, toDisplayString(__props.t.col_rule ?? "Papel") + ":", 1),
                          createTextVNode(" " + toDisplayString(pendingUser.value.rule || "—"), 1)
                        ])
                      ]),
                      createVNode("div", { class: "modal-footer" }, [
                        createVNode("button", {
                          type: "button",
                          class: "btn btn-outline-secondary btn-sm",
                          disabled: unref(impersonateForm).processing,
                          onClick: cancelImpersonate
                        }, toDisplayString(__props.t.confirm_impersonate_no ?? "Cancelar"), 9, ["disabled"]),
                        createVNode("button", {
                          type: "button",
                          class: "btn btn-info text-white btn-sm",
                          disabled: unref(impersonateForm).processing,
                          onClick: confirmImpersonate
                        }, [
                          unref(impersonateForm).processing ? (openBlock(), createBlock("span", {
                            key: 0,
                            class: "spinner-border spinner-border-sm me-1"
                          })) : (openBlock(), createBlock("i", {
                            key: 1,
                            class: "ti ti-check me-1"
                          })),
                          createTextVNode(" " + toDisplayString(__props.t.confirm_impersonate_yes ?? "Sim, continuar"), 1)
                        ], 8, ["disabled"])
                      ])
                    ])
                  ])
                ])) : createCommentVNode("", true)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/EntityUsers/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
