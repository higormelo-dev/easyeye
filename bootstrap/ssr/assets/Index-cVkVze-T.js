import { mergeProps, withCtx, unref, createVNode, toDisplayString, openBlock, createBlock, Fragment, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderList, ssrRenderClass, ssrInterpolate } from "vue/server-renderer";
import { Link } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    links: { type: Array, default: () => [] }
  },
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Relatórios",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, { title: "Relatórios" }, null, _parent2, _scopeId));
            _push2(`<div class="row g-3"${_scopeId}><!--[-->`);
            ssrRenderList(__props.links, (link) => {
              _push2(`<div class="col-md-4"${_scopeId}>`);
              _push2(ssrRenderComponent(unref(Link), {
                href: link.url,
                class: "card border-0 shadow-sm h-100 text-decoration-none text-reset"
              }, {
                default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                  if (_push3) {
                    _push3(`<div class="card-body d-flex align-items-center gap-3"${_scopeId2}><span class="avatar avatar-lg bg-primary-subtle rounded-circle"${_scopeId2}><i class="${ssrRenderClass(`ti ${link.icon} fs-3 text-primary`)}"${_scopeId2}></i></span><div${_scopeId2}><h6 class="fw-semibold mb-0"${_scopeId2}>${ssrInterpolate(link.title)}</h6></div></div>`);
                  } else {
                    return [
                      createVNode("div", { class: "card-body d-flex align-items-center gap-3" }, [
                        createVNode("span", { class: "avatar avatar-lg bg-primary-subtle rounded-circle" }, [
                          createVNode("i", {
                            class: `ti ${link.icon} fs-3 text-primary`
                          }, null, 2)
                        ]),
                        createVNode("div", null, [
                          createVNode("h6", { class: "fw-semibold mb-0" }, toDisplayString(link.title), 1)
                        ])
                      ])
                    ];
                  }
                }),
                _: 2
              }, _parent2, _scopeId));
              _push2(`</div>`);
            });
            _push2(`<!--]--></div></div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, { title: "Relatórios" }),
                createVNode("div", { class: "row g-3" }, [
                  (openBlock(true), createBlock(Fragment, null, renderList(__props.links, (link) => {
                    return openBlock(), createBlock("div", {
                      key: link.url,
                      class: "col-md-4"
                    }, [
                      createVNode(unref(Link), {
                        href: link.url,
                        class: "card border-0 shadow-sm h-100 text-decoration-none text-reset"
                      }, {
                        default: withCtx(() => [
                          createVNode("div", { class: "card-body d-flex align-items-center gap-3" }, [
                            createVNode("span", { class: "avatar avatar-lg bg-primary-subtle rounded-circle" }, [
                              createVNode("i", {
                                class: `ti ${link.icon} fs-3 text-primary`
                              }, null, 2)
                            ]),
                            createVNode("div", null, [
                              createVNode("h6", { class: "fw-semibold mb-0" }, toDisplayString(link.title), 1)
                            ])
                          ])
                        ]),
                        _: 2
                      }, 1032, ["href"])
                    ]);
                  }), 128))
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Reports/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
