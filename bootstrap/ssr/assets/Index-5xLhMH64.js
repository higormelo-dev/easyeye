import { computed, mergeProps, withCtx, unref, createVNode, createTextVNode, toDisplayString, openBlock, createBlock, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderClass, ssrInterpolate } from "vue/server-renderer";
import { Link, router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import { _ as _sfc_main$2 } from "./ConfirmationWithReasonModal-CmfO7qbN.js";
import { u as useConfirmationWithReason } from "./useConfirmationWithReason-DDlQOe6J.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    entity: { type: Object, required: true },
    currentUser: { type: Object, required: true },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const breadcrumbs = [
      { label: "Dashboard", url: route("panel.dashboard"), active: false },
      { label: "Configurações", url: "#", active: false },
      { label: props.t.entity_2fa_section ?? "Segurança", url: "#", active: true }
    ];
    const { state: reasonModal, open: openReasonModal, close: closeReasonModal, handle: handleReasonConfirm } = useConfirmationWithReason();
    const isEnabled = computed(() => !!props.entity.requires_two_factor);
    function toggle() {
      const enabling = !isEnabled.value;
      openReasonModal({
        title: enabling ? props.t.entity_2fa_btn_enable ?? "Ativar 2FA obrigatório" : props.t.entity_2fa_btn_disable ?? "Desativar 2FA obrigatório",
        message: enabling ? props.t.entity_2fa_reason_enable ?? "" : props.t.entity_2fa_reason_disable ?? "",
        confirmVariant: enabling ? "primary" : "danger",
        async onConfirm(reason) {
          var _a;
          const res = await fetch(route("panel.setting.security.two-factor.toggle"), {
            method: "PATCH",
            headers: {
              "Content-Type": "application/json",
              "Accept": "application/json",
              "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? "",
              "X-Inertia": "true"
            },
            body: JSON.stringify({ enabled: enabling, reason })
          });
          const json = await res.json();
          if (res.ok) {
            if (window.showSuccessToast) window.showSuccessToast(json.message);
            router.reload({ only: ["entity"] });
          } else if (window.showErrorToast) {
            window.showErrorToast(json.message ?? "Erro");
          }
        }
      });
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: __props.t.entity_2fa_section ?? "Segurança",
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b, _c, _d;
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: __props.t.entity_2fa_section ?? "Segurança",
              subtitle: __props.entity.name
            }, null, _parent2, _scopeId));
            _push2(`<div class="card mb-3"${_scopeId}><div class="card-body p-4"${_scopeId}><div class="d-flex align-items-start gap-3 mb-3"${_scopeId}><div class="flex-shrink-0"${_scopeId}><i class="${ssrRenderClass([isEnabled.value ? "text-success" : "text-muted", "ti ti-shield-lock-filled fs-1"])}"${_scopeId}></i></div><div class="flex-grow-1"${_scopeId}><h5 class="fw-semibold mb-1"${_scopeId}>${ssrInterpolate(__props.t.entity_2fa_label ?? "Exigir 2FA para todos os usuários")} `);
            if (isEnabled.value) {
              _push2(`<span class="badge badge-soft-success rounded text-success border border-success ms-1"${_scopeId}>${ssrInterpolate(__props.t.status_active ?? "Ativo")}</span>`);
            } else {
              _push2(`<span class="badge badge-soft-secondary rounded ms-1"${_scopeId}>${ssrInterpolate(__props.t.status_inactive ?? "Inativo")}</span>`);
            }
            _push2(`</h5><p class="text-muted small mb-2"${_scopeId}>${ssrInterpolate(__props.t.entity_2fa_hint)}</p>`);
            if (isEnabled.value && __props.entity.two_factor_enabled_at) {
              _push2(`<p class="small text-muted mb-0"${_scopeId}><i class="ti ti-history me-1"${_scopeId}></i> ${ssrInterpolate((_b = (_a = __props.t.entity_2fa_enabled_at) == null ? void 0 : _a.replace(":date", __props.entity.two_factor_enabled_at)) == null ? void 0 : _b.replace(":user", __props.entity.two_factor_enabled_by ?? "—"))}</p>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div>`);
            if (!isEnabled.value && !__props.currentUser.has_two_factor) {
              _push2(`<div class="alert alert-warning small d-flex align-items-start mb-3"${_scopeId}><i class="ti ti-alert-triangle me-2 fs-5 mt-1"${_scopeId}></i><div${_scopeId}><strong${_scopeId}>${ssrInterpolate(__props.t.entity_2fa_warning)}</strong><div class="mt-1"${_scopeId}>`);
              _push2(ssrRenderComponent(unref(Link), {
                href: __props.currentUser.setup_url,
                class: "btn btn-sm btn-outline-warning"
              }, {
                default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                  if (_push3) {
                    _push3(`<i class="ti ti-shield-lock me-1"${_scopeId2}></i> Configurar meu 2FA primeiro `);
                  } else {
                    return [
                      createVNode("i", { class: "ti ti-shield-lock me-1" }),
                      createTextVNode(" Configurar meu 2FA primeiro ")
                    ];
                  }
                }),
                _: 1
              }, _parent2, _scopeId));
              _push2(`</div></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="d-flex justify-content-end"${_scopeId}><button type="button" class="${ssrRenderClass(`btn btn-sm ${isEnabled.value ? "btn-outline-danger" : "btn-primary"}`)}"${_scopeId}><i class="${ssrRenderClass(`ti me-1 ${isEnabled.value ? "ti-shield-off" : "ti-shield-check"}`)}"${_scopeId}></i> ${ssrInterpolate(isEnabled.value ? __props.t.entity_2fa_btn_disable : __props.t.entity_2fa_btn_enable)}</button></div></div></div>`);
            _push2(ssrRenderComponent(_sfc_main$2, {
              open: unref(reasonModal).open,
              title: unref(reasonModal).title,
              message: unref(reasonModal).message,
              "confirm-variant": unref(reasonModal).confirmVariant,
              saving: unref(reasonModal).saving,
              onClose: unref(closeReasonModal),
              onConfirm: unref(handleReasonConfirm)
            }, null, _parent2, _scopeId));
            _push2(`</div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, {
                  title: __props.t.entity_2fa_section ?? "Segurança",
                  subtitle: __props.entity.name
                }, null, 8, ["title", "subtitle"]),
                createVNode("div", { class: "card mb-3" }, [
                  createVNode("div", { class: "card-body p-4" }, [
                    createVNode("div", { class: "d-flex align-items-start gap-3 mb-3" }, [
                      createVNode("div", { class: "flex-shrink-0" }, [
                        createVNode("i", {
                          class: ["ti ti-shield-lock-filled fs-1", isEnabled.value ? "text-success" : "text-muted"]
                        }, null, 2)
                      ]),
                      createVNode("div", { class: "flex-grow-1" }, [
                        createVNode("h5", { class: "fw-semibold mb-1" }, [
                          createTextVNode(toDisplayString(__props.t.entity_2fa_label ?? "Exigir 2FA para todos os usuários") + " ", 1),
                          isEnabled.value ? (openBlock(), createBlock("span", {
                            key: 0,
                            class: "badge badge-soft-success rounded text-success border border-success ms-1"
                          }, toDisplayString(__props.t.status_active ?? "Ativo"), 1)) : (openBlock(), createBlock("span", {
                            key: 1,
                            class: "badge badge-soft-secondary rounded ms-1"
                          }, toDisplayString(__props.t.status_inactive ?? "Inativo"), 1))
                        ]),
                        createVNode("p", { class: "text-muted small mb-2" }, toDisplayString(__props.t.entity_2fa_hint), 1),
                        isEnabled.value && __props.entity.two_factor_enabled_at ? (openBlock(), createBlock("p", {
                          key: 0,
                          class: "small text-muted mb-0"
                        }, [
                          createVNode("i", { class: "ti ti-history me-1" }),
                          createTextVNode(" " + toDisplayString((_d = (_c = __props.t.entity_2fa_enabled_at) == null ? void 0 : _c.replace(":date", __props.entity.two_factor_enabled_at)) == null ? void 0 : _d.replace(":user", __props.entity.two_factor_enabled_by ?? "—")), 1)
                        ])) : createCommentVNode("", true)
                      ])
                    ]),
                    !isEnabled.value && !__props.currentUser.has_two_factor ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "alert alert-warning small d-flex align-items-start mb-3"
                    }, [
                      createVNode("i", { class: "ti ti-alert-triangle me-2 fs-5 mt-1" }),
                      createVNode("div", null, [
                        createVNode("strong", null, toDisplayString(__props.t.entity_2fa_warning), 1),
                        createVNode("div", { class: "mt-1" }, [
                          createVNode(unref(Link), {
                            href: __props.currentUser.setup_url,
                            class: "btn btn-sm btn-outline-warning"
                          }, {
                            default: withCtx(() => [
                              createVNode("i", { class: "ti ti-shield-lock me-1" }),
                              createTextVNode(" Configurar meu 2FA primeiro ")
                            ]),
                            _: 1
                          }, 8, ["href"])
                        ])
                      ])
                    ])) : createCommentVNode("", true),
                    createVNode("div", { class: "d-flex justify-content-end" }, [
                      createVNode("button", {
                        type: "button",
                        class: `btn btn-sm ${isEnabled.value ? "btn-outline-danger" : "btn-primary"}`,
                        onClick: toggle
                      }, [
                        createVNode("i", {
                          class: `ti me-1 ${isEnabled.value ? "ti-shield-off" : "ti-shield-check"}`
                        }, null, 2),
                        createTextVNode(" " + toDisplayString(isEnabled.value ? __props.t.entity_2fa_btn_disable : __props.t.entity_2fa_btn_enable), 1)
                      ], 2)
                    ])
                  ])
                ]),
                createVNode(_sfc_main$2, {
                  open: unref(reasonModal).open,
                  title: unref(reasonModal).title,
                  message: unref(reasonModal).message,
                  "confirm-variant": unref(reasonModal).confirmVariant,
                  saving: unref(reasonModal).saving,
                  onClose: unref(closeReasonModal),
                  onConfirm: unref(handleReasonConfirm)
                }, null, 8, ["open", "title", "message", "confirm-variant", "saving", "onClose", "onConfirm"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Settings/Security/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
