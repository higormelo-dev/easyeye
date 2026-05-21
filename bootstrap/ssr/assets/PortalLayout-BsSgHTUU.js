import { computed, ref, onMounted, onUnmounted, mergeProps, unref, withCtx, createVNode, createTextVNode, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderStyle, ssrRenderComponent, ssrInterpolate, ssrRenderSlot } from "vue/server-renderer";
import { usePage, Link } from "@inertiajs/vue3";
const _sfc_main = {
  __name: "PortalLayout",
  __ssrInlineRender: true,
  props: {
    title: { type: String, default: "" }
  },
  setup(__props) {
    const page = usePage();
    const partner = computed(() => {
      var _a;
      return ((_a = page.props) == null ? void 0 : _a.partner) ?? null;
    });
    const flash = computed(() => {
      var _a;
      return ((_a = page.props) == null ? void 0 : _a.flash) ?? {};
    });
    const showUserMenu = ref(false);
    const userMenuEl = ref(null);
    function handleClickOutside(e) {
      if (userMenuEl.value && !userMenuEl.value.contains(e.target)) {
        showUserMenu.value = false;
      }
    }
    onMounted(() => document.addEventListener("click", handleClickOutside));
    onUnmounted(() => document.removeEventListener("click", handleClickOutside));
    const flashSuccess = computed(() => {
      var _a;
      return (_a = flash.value) == null ? void 0 : _a.success;
    });
    const flashError = computed(() => {
      var _a;
      return (_a = flash.value) == null ? void 0 : _a.error;
    });
    function isActive(routeNameStart) {
      var _a, _b;
      const current = ((_b = (_a = page.props) == null ? void 0 : _a.ziggy) == null ? void 0 : _b.location) ?? "";
      if (routeNameStart === "portal.dashboard") return current.includes("/portal/dashboard");
      if (routeNameStart === "portal.leads") return current.includes("/portal/leads");
      if (routeNameStart === "portal.commissions") return current.includes("/portal/commissions");
      return false;
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "bg-light min-vh-100" }, _attrs))}><nav class="navbar navbar-expand-lg navbar-dark py-2" style="${ssrRenderStyle({ "background": "linear-gradient(135deg, #26a69a 0%, #1565c0 100%)" })}"><div class="container-fluid px-4">`);
      _push(ssrRenderComponent(unref(Link), {
        href: _ctx.route("portal.dashboard"),
        class: "navbar-brand fw-semibold"
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<i class="ti ti-handshake me-2"${_scopeId}></i> Portal de Parceiros `);
          } else {
            return [
              createVNode("i", { class: "ti ti-handshake me-2" }),
              createTextVNode(" Portal de Parceiros ")
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#portalNav"><span class="navbar-toggler-icon"></span></button><div class="collapse navbar-collapse" id="portalNav"><ul class="navbar-nav me-auto"><li class="nav-item">`);
      _push(ssrRenderComponent(unref(Link), {
        href: _ctx.route("portal.dashboard"),
        class: ["nav-link", { "active fw-semibold": isActive("portal.dashboard") }]
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<i class="ti ti-dashboard me-1"${_scopeId}></i>Dashboard `);
          } else {
            return [
              createVNode("i", { class: "ti ti-dashboard me-1" }),
              createTextVNode("Dashboard ")
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`</li><li class="nav-item">`);
      _push(ssrRenderComponent(unref(Link), {
        href: _ctx.route("portal.leads.index"),
        class: ["nav-link", { "active fw-semibold": isActive("portal.leads") }]
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<i class="ti ti-users me-1"${_scopeId}></i>Meus Leads `);
          } else {
            return [
              createVNode("i", { class: "ti ti-users me-1" }),
              createTextVNode("Meus Leads ")
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`</li><li class="nav-item">`);
      _push(ssrRenderComponent(unref(Link), {
        href: _ctx.route("portal.commissions.index"),
        class: ["nav-link", { "active fw-semibold": isActive("portal.commissions") }]
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<i class="ti ti-coin me-1"${_scopeId}></i>Minhas Comissões `);
          } else {
            return [
              createVNode("i", { class: "ti ti-coin me-1" }),
              createTextVNode("Minhas Comissões ")
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`</li></ul>`);
      if (partner.value) {
        _push(`<div class="dropdown position-relative"><button type="button" class="btn btn-link text-white d-flex align-items-center gap-2" style="${ssrRenderStyle({ "text-decoration": "none" })}"><span class="rounded-circle d-flex align-items-center justify-content-center bg-white bg-opacity-25" style="${ssrRenderStyle({ "width": "32px", "height": "32px" })}"><i class="ti ti-user"></i></span><span class="d-none d-md-inline">${ssrInterpolate(partner.value.name)}</span><i class="ti ti-chevron-down" style="${ssrRenderStyle({ "font-size": "12px" })}"></i></button>`);
        if (showUserMenu.value) {
          _push(`<ul class="dropdown-menu dropdown-menu-end show position-absolute" style="${ssrRenderStyle({ "right": "0", "top": "100%" })}"><li class="dropdown-item-text small text-muted"><i class="ti ti-mail me-1"></i>${ssrInterpolate(partner.value.email)}</li>`);
          if (partner.value.code) {
            _push(`<li class="dropdown-item-text small text-muted"><i class="ti ti-id me-1"></i><code>${ssrInterpolate(partner.value.code)}</code></li>`);
          } else {
            _push(`<!---->`);
          }
          _push(`<li><hr class="dropdown-divider"></li><li>`);
          _push(ssrRenderComponent(unref(Link), {
            href: _ctx.route("logout"),
            method: "post",
            as: "button",
            class: "dropdown-item text-danger"
          }, {
            default: withCtx((_, _push2, _parent2, _scopeId) => {
              if (_push2) {
                _push2(`<i class="ti ti-logout me-1"${_scopeId}></i>Sair `);
              } else {
                return [
                  createVNode("i", { class: "ti ti-logout me-1" }),
                  createTextVNode("Sair ")
                ];
              }
            }),
            _: 1
          }, _parent));
          _push(`</li></ul>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</div>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div></div></nav><div class="container-fluid px-4 pt-3">`);
      if (flashSuccess.value) {
        _push(`<div class="alert alert-success alert-dismissible fade show"><i class="ti ti-circle-check me-1"></i>${ssrInterpolate(flashSuccess.value)} <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`);
      } else {
        _push(`<!---->`);
      }
      if (flashError.value) {
        _push(`<div class="alert alert-danger alert-dismissible fade show"><i class="ti ti-alert-triangle me-1"></i>${ssrInterpolate(flashError.value)} <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div><main class="container-fluid px-4 py-3">`);
      ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
      _push(`</main><footer class="text-center py-3 text-muted small"><i class="ti ti-copyright me-1"></i> ${ssrInterpolate((/* @__PURE__ */ new Date()).getFullYear())} EasyEye — Portal de Parceiros </footer></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Layouts/PortalLayout.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as _
};
