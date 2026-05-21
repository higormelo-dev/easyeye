import { ref, watch, mergeProps, withCtx, unref, createVNode, createTextVNode, toDisplayString, openBlock, createBlock, createCommentVNode, Fragment, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderClass } from "vue/server-renderer";
import { router, Link } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import { _ as _sfc_main$2 } from "./SearchInput-DBX1PwVy.js";
import { _ as _sfc_main$4 } from "./TablePagination-Dj1_H7YG.js";
import { A as ActionIconGroup, _ as _sfc_main$3 } from "./ActionIconGroup-Dj2wQrik.js";
import EntityIntegratorEquipmentDetailDrawer from "./EntityIntegratorEquipmentDetailDrawer-TTlFqa8P.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./OffcanvasPanel-VfE7yaou.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    entity: { type: Object, required: true },
    userIntegrator: { type: Object, required: true },
    integrator: { type: Object, required: true },
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const breadcrumbs = [
      { label: props.t.breadcrumb_home ?? "Dashboard", url: route("panel.dashboard"), active: false },
      { label: props.t.breadcrumb_entities ?? "Empresas", url: route("manager.entities.index"), active: false },
      { label: props.entity.name, url: "#", active: false },
      { label: props.t.breadcrumb_users ?? "Usuários Integradores", url: route("manager.entities.user-integrators.index", props.entity.id), active: false },
      { label: props.userIntegrator.name, url: "#", active: false },
      { label: props.t.breadcrumb_integrators ?? "Integradores", url: route("manager.entities.user-integrators.integrators.index", [props.entity.id, props.userIntegrator.id]), active: false },
      { label: props.integrator.name, url: "#", active: false },
      { label: props.t.breadcrumb_current ?? "Equipamentos", url: "#", active: true }
    ];
    const search = ref(props.filters.search ?? "");
    let searchTimer = null;
    watch(search, (val) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        router.get(
          route("manager.entities.user-integrators.integrators.equipments.index", [
            props.entity.id,
            props.userIntegrator.id,
            props.integrator.id
          ]),
          { search: val },
          { preserveState: true, preserveScroll: true, replace: true }
        );
      }, 400);
    });
    const detailOpen = ref(false);
    const detailUrl = ref("");
    function openDetail(item) {
      detailUrl.value = item.show_url;
      detailOpen.value = true;
    }
    function closeDetail() {
      detailOpen.value = false;
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: __props.t.page_title ?? "Equipamentos",
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: __props.t.page_title ?? "Equipamentos",
              subtitle: `${__props.integrator.name} (${__props.integrator.code})`,
              total: __props.items.total
            }, {
              actions: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(ssrRenderComponent(unref(Link), {
                    href: _ctx.route("manager.entities.user-integrators.integrators.index", [__props.entity.id, __props.userIntegrator.id]),
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
                      href: _ctx.route("manager.entities.user-integrators.integrators.index", [__props.entity.id, __props.userIntegrator.id]),
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
            _push2(`<div class="alert alert-info d-flex align-items-center small py-2 mb-3"${_scopeId}><i class="ti ti-info-circle me-2 fs-5"${_scopeId}></i><span${_scopeId}>${ssrInterpolate(__props.t.readonly_note)}</span></div><div class="d-flex align-items-center mb-3 gap-2 flex-wrap"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$2, {
              modelValue: search.value,
              "onUpdate:modelValue": ($event) => search.value = $event,
              placeholder: __props.t.search_placeholder ?? "Buscar...",
              style: { "min-width": "280px" }
            }, null, _parent2, _scopeId));
            _push2(`</div><div class="card"${_scopeId}><div class="table-responsive"${_scopeId}><table class="table table-nowrap table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>${ssrInterpolate(__props.t.col_registered_at ?? "Cadastro")}</th><th${_scopeId}>${ssrInterpolate(__props.t.col_code ?? "Código")}</th><th${_scopeId}>${ssrInterpolate(__props.t.col_name ?? "Nome")}</th><th${_scopeId}>${ssrInterpolate(__props.t.col_ip ?? "IP")}</th><th${_scopeId}>${ssrInterpolate(__props.t.col_mac ?? "MAC")}</th><th${_scopeId}>${ssrInterpolate(__props.t.col_serial ?? "Nº Série")}</th><th class="text-center"${_scopeId}>${ssrInterpolate(__props.t.col_status ?? "Status")}</th><th class="text-end"${_scopeId}>${ssrInterpolate(__props.t.col_actions ?? "Ações")}</th></tr></thead><tbody${_scopeId}>`);
            if (__props.items.data.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="8" class="text-center text-muted py-5"${_scopeId}><i class="ti ti-device-laptop fs-1 d-block mb-2"${_scopeId}></i> ${ssrInterpolate(__props.t.empty_list ?? "Nenhum registro.")}</td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.items.data, (e) => {
              _push2(`<tr class="${ssrRenderClass({ "table-secondary opacity-75": e.deleted })}"${_scopeId}><td class="text-muted small"${_scopeId}>${ssrInterpolate(e.created_at)}</td><td${_scopeId}><code class="text-muted small"${_scopeId}>${ssrInterpolate(e.code)}</code></td><td class="fw-medium"${_scopeId}>${ssrInterpolate(e.name)}</td><td${_scopeId}><code class="small"${_scopeId}>${ssrInterpolate(e.ip || "—")}</code></td><td${_scopeId}><code class="small"${_scopeId}>${ssrInterpolate(e.mac || "—")}</code></td><td${_scopeId}><code class="small"${_scopeId}>${ssrInterpolate(e.serial_number || "—")}</code></td><td class="text-center"${_scopeId}>`);
              if (e.deleted) {
                _push2(`<span class="badge badge-soft-secondary rounded fs-13 fw-medium"${_scopeId}>${ssrInterpolate(__props.t.status_deleted ?? "Removido")}</span>`);
              } else if (e.active) {
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
                      icon: "ti ti-eye",
                      title: __props.t.action_view ?? "Ver",
                      onClick: ($event) => openDetail(e)
                    }, null, _parent3, _scopeId2));
                  } else {
                    return [
                      createVNode(_sfc_main$3, {
                        icon: "ti ti-eye",
                        title: __props.t.action_view ?? "Ver",
                        onClick: ($event) => openDetail(e)
                      }, null, 8, ["title", "onClick"])
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
            _push2(ssrRenderComponent(EntityIntegratorEquipmentDetailDrawer, {
              open: detailOpen.value,
              "show-url": detailUrl.value,
              t: __props.t,
              onClose: closeDetail
            }, null, _parent2, _scopeId));
            _push2(`</div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, {
                  title: __props.t.page_title ?? "Equipamentos",
                  subtitle: `${__props.integrator.name} (${__props.integrator.code})`,
                  total: __props.items.total
                }, {
                  actions: withCtx(() => [
                    createVNode(unref(Link), {
                      href: _ctx.route("manager.entities.user-integrators.integrators.index", [__props.entity.id, __props.userIntegrator.id]),
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
                createVNode("div", { class: "alert alert-info d-flex align-items-center small py-2 mb-3" }, [
                  createVNode("i", { class: "ti ti-info-circle me-2 fs-5" }),
                  createVNode("span", null, toDisplayString(__props.t.readonly_note), 1)
                ]),
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
                          createVNode("th", null, toDisplayString(__props.t.col_ip ?? "IP"), 1),
                          createVNode("th", null, toDisplayString(__props.t.col_mac ?? "MAC"), 1),
                          createVNode("th", null, toDisplayString(__props.t.col_serial ?? "Nº Série"), 1),
                          createVNode("th", { class: "text-center" }, toDisplayString(__props.t.col_status ?? "Status"), 1),
                          createVNode("th", { class: "text-end" }, toDisplayString(__props.t.col_actions ?? "Ações"), 1)
                        ])
                      ]),
                      createVNode("tbody", null, [
                        __props.items.data.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("td", {
                            colspan: "8",
                            class: "text-center text-muted py-5"
                          }, [
                            createVNode("i", { class: "ti ti-device-laptop fs-1 d-block mb-2" }),
                            createTextVNode(" " + toDisplayString(__props.t.empty_list ?? "Nenhum registro."), 1)
                          ])
                        ])) : createCommentVNode("", true),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.items.data, (e) => {
                          return openBlock(), createBlock("tr", {
                            key: e.id,
                            class: { "table-secondary opacity-75": e.deleted }
                          }, [
                            createVNode("td", { class: "text-muted small" }, toDisplayString(e.created_at), 1),
                            createVNode("td", null, [
                              createVNode("code", { class: "text-muted small" }, toDisplayString(e.code), 1)
                            ]),
                            createVNode("td", { class: "fw-medium" }, toDisplayString(e.name), 1),
                            createVNode("td", null, [
                              createVNode("code", { class: "small" }, toDisplayString(e.ip || "—"), 1)
                            ]),
                            createVNode("td", null, [
                              createVNode("code", { class: "small" }, toDisplayString(e.mac || "—"), 1)
                            ]),
                            createVNode("td", null, [
                              createVNode("code", { class: "small" }, toDisplayString(e.serial_number || "—"), 1)
                            ]),
                            createVNode("td", { class: "text-center" }, [
                              e.deleted ? (openBlock(), createBlock("span", {
                                key: 0,
                                class: "badge badge-soft-secondary rounded fs-13 fw-medium"
                              }, toDisplayString(__props.t.status_deleted ?? "Removido"), 1)) : e.active ? (openBlock(), createBlock("span", {
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
                                    icon: "ti ti-eye",
                                    title: __props.t.action_view ?? "Ver",
                                    onClick: ($event) => openDetail(e)
                                  }, null, 8, ["title", "onClick"])
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
                createVNode(EntityIntegratorEquipmentDetailDrawer, {
                  open: detailOpen.value,
                  "show-url": detailUrl.value,
                  t: __props.t,
                  onClose: closeDetail
                }, null, 8, ["open", "show-url", "t"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/EntityIntegratorEquipments/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
