import { computed, ref, onMounted, onUnmounted, watch, mergeProps, unref, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderAttr, ssrRenderSlot, ssrRenderList, ssrRenderClass, ssrInterpolate, ssrRenderStyle } from "vue/server-renderer";
import { usePage } from "@inertiajs/vue3";
import { l as logoSvg, a as logoSmallSvg } from "./logo-small-Br31EOC_.js";
import { l as logoWhiteSvg } from "./logo-white-hVd1h5De.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const warnBefore = 2 * 60 * 1e3;
const _sfc_main = {
  __name: "AppLayout",
  __ssrInlineRender: true,
  props: {
    title: { type: String, default: "" },
    breadcrumbs: { type: Array, default: () => [] }
  },
  setup(__props) {
    const page = usePage();
    const auth = computed(() => page.props.auth ?? {});
    const user = computed(() => auth.value.user ?? {});
    const entity = computed(() => auth.value.entity ?? {});
    const nav = computed(() => page.props.nav ?? []);
    const locales = computed(() => page.props.locales ?? []);
    const flash = computed(() => page.props.flash ?? {});
    const entities = computed(() => auth.value.entities ?? []);
    const isDark = ref(false);
    onMounted(() => {
      isDark.value = document.documentElement.getAttribute("data-bs-theme") === "dark" || document.documentElement.getAttribute("data-sidebar") === "dark";
    });
    let warningTimer = null;
    let expireTimer = null;
    let warningShown = false;
    const lifetime = window.sessionLifetimeMs ?? 120 * 60 * 1e3;
    function resetTimers() {
      clearTimeout(warningTimer);
      clearTimeout(expireTimer);
      warningShown = false;
      warningTimer = setTimeout(showSessionWarning, lifetime - warnBefore);
      expireTimer = setTimeout(() => window.location.reload(), lifetime);
    }
    function showSessionWarning() {
      var _a, _b;
      if (warningShown || typeof Swal === "undefined") return;
      warningShown = true;
      let remaining = 120;
      Swal.fire({
        title: ((_b = (_a = window.translations) == null ? void 0 : _a.messages) == null ? void 0 : _b.session_expiring_title) ?? "Sessão expirando",
        icon: "warning",
        showCancelButton: true,
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen() {
          const interval = setInterval(() => {
            remaining--;
            if (remaining <= 0) clearInterval(interval);
          }, 1e3);
          Swal.getPopup().__countdownInterval = interval;
        },
        willClose() {
          clearInterval(Swal.getPopup().__countdownInterval);
        }
      }).then((result) => {
        if (result.isConfirmed) {
          fetch(route("session.ping"), {
            method: "POST",
            headers: { "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content }
          }).then((r) => r.ok ? resetTimers() : window.location.reload()).catch(() => window.location.reload());
        } else {
          window.location.reload();
        }
      });
    }
    const sessionEvents = ["mousemove", "keydown", "click", "scroll", "touchstart"];
    onMounted(() => {
      resetTimers();
      sessionEvents.forEach((e) => document.addEventListener(e, resetTimers, { passive: true }));
    });
    onUnmounted(() => {
      clearTimeout(warningTimer);
      clearTimeout(expireTimer);
      sessionEvents.forEach((e) => document.removeEventListener(e, resetTimers));
    });
    function isActive(matchPatterns) {
      const current = route().current();
      return matchPatterns.some((p) => {
        if (p.endsWith("*")) return current == null ? void 0 : current.startsWith(p.slice(0, -2).replace(".*", ""));
        return current === p;
      });
    }
    function isGroupActive(item) {
      if (item.match && isActive(item.match)) return true;
      if (item.children) return item.children.some((c) => c.match && isActive(c.match));
      return false;
    }
    const openMenus = ref({});
    onMounted(() => {
      nav.value.forEach((item) => {
        if (item.children && isGroupActive(item)) {
          openMenus.value[item.key] = true;
        }
      });
    });
    function isMenuOpen(item) {
      return isGroupActive(item) || !!openMenus.value[item.key];
    }
    function entityInitials(name) {
      return name.split(" ").slice(0, 2).map((w) => w[0]).join("").toUpperCase();
    }
    const flashSuccessOpen = ref(false);
    const flashErrorOpen = ref(false);
    let flashSuccessTimer = null;
    let flashErrorTimer = null;
    watch(
      () => flash.value.success,
      (val) => {
        clearTimeout(flashSuccessTimer);
        if (val) {
          flashSuccessOpen.value = true;
          flashSuccessTimer = setTimeout(() => {
            flashSuccessOpen.value = false;
          }, 6e3);
        }
      },
      { immediate: true }
    );
    watch(
      () => flash.value.error,
      (val) => {
        clearTimeout(flashErrorTimer);
        if (val) {
          flashErrorOpen.value = true;
          flashErrorTimer = setTimeout(() => {
            flashErrorOpen.value = false;
          }, 6e3);
        }
      },
      { immediate: true }
    );
    onUnmounted(() => {
      clearTimeout(flashSuccessTimer);
      clearTimeout(flashErrorTimer);
    });
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "main-wrapper" }, _attrs))} data-v-35d12ff2><header class="navbar-header" data-v-35d12ff2><div class="page-container topbar-menu" data-v-35d12ff2><div class="d-flex align-items-center gap-2" data-v-35d12ff2><a${ssrRenderAttr("href", _ctx.route("panel.dashboard"))} class="logo" data-v-35d12ff2><span class="logo-light" data-v-35d12ff2><span class="logo-lg" data-v-35d12ff2><img${ssrRenderAttr("src", unref(logoSvg))} alt="EasyEye" data-v-35d12ff2></span><span class="logo-sm" data-v-35d12ff2><img${ssrRenderAttr("src", unref(logoSmallSvg))} alt="EasyEye" data-v-35d12ff2></span></span><span class="logo-dark" data-v-35d12ff2><span class="logo-lg" data-v-35d12ff2><img${ssrRenderAttr("src", unref(logoWhiteSvg))} alt="EasyEye" data-v-35d12ff2></span></span></a><a id="mobile_btn" class="mobile-btn" href="#sidebar" data-v-35d12ff2><i class="ti ti-menu-deep fs-24" data-v-35d12ff2></i></a><button class="sidenav-toggle-btn btn border-0 p-0 active" id="toggle_btn2" data-v-35d12ff2><i class="ti ti-arrow-right" data-v-35d12ff2></i></button></div><div class="d-flex align-items-center" data-v-35d12ff2>`);
      ssrRenderSlot(_ctx.$slots, "top-actions", {}, null, _push, _parent);
      if (locales.value.length > 1) {
        _push(`<div class="header-item" data-v-35d12ff2><div class="dropdown me-2" data-v-35d12ff2><button class="topbar-link btn btn-icon dropdown-toggle drop-arrow-none" data-bs-toggle="dropdown" data-bs-offset="0,24" data-v-35d12ff2><i class="ti ti-world fs-16" data-v-35d12ff2></i></button><div class="dropdown-menu dropdown-menu-end p-2" data-v-35d12ff2><!--[-->`);
        ssrRenderList(locales.value, (locale) => {
          _push(`<a${ssrRenderAttr("href", locale.url)} class="${ssrRenderClass([{ active: locale.active }, "dropdown-item"])}" data-v-35d12ff2>${ssrInterpolate(locale.flag)} ${ssrInterpolate(locale.native)} `);
          if (locale.active) {
            _push(`<i class="ti ti-check ms-2" data-v-35d12ff2></i>`);
          } else {
            _push(`<!---->`);
          }
          _push(`</a>`);
        });
        _push(`<!--]--></div></div></div>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<div class="header-item d-none d-sm-flex me-2" data-v-35d12ff2><button class="topbar-link btn btn-icon" type="button" data-v-35d12ff2><i class="${ssrRenderClass(isDark.value ? "ti ti-sun fs-16" : "ti ti-moon fs-16")}" data-v-35d12ff2></i></button></div><div class="dropdown profile-dropdown d-flex align-items-center justify-content-center" data-v-35d12ff2><a href="#" class="topbar-link dropdown-toggle drop-arrow-none position-relative" data-bs-toggle="dropdown" data-bs-offset="0,22" data-v-35d12ff2><img${ssrRenderAttr("src", user.value.photo_url)} width="32" class="rounded-circle d-flex"${ssrRenderAttr("alt", user.value.name)} data-v-35d12ff2><span class="online text-success" data-v-35d12ff2><i class="ti ti-circle-filled d-flex bg-white rounded-circle border border-1 border-white" data-v-35d12ff2></i></span></a><div class="dropdown-menu dropdown-menu-end dropdown-menu-md p-2" data-v-35d12ff2><div class="d-flex align-items-center bg-light rounded-3 p-2 mb-2" data-v-35d12ff2><img${ssrRenderAttr("src", user.value.photo_url)} class="rounded-circle" width="42" height="42"${ssrRenderAttr("alt", user.value.name)} data-v-35d12ff2><div class="ms-2" data-v-35d12ff2><p class="fw-medium text-dark mb-0" data-v-35d12ff2>${ssrInterpolate(user.value.name)}</p><span class="d-block fs-13" data-v-35d12ff2>${ssrInterpolate(user.value.email)}</span></div></div><a${ssrRenderAttr("href", _ctx.route("panel.profile.edit"))} class="dropdown-item" data-v-35d12ff2><i class="ti ti-user-circle me-1 align-middle" data-v-35d12ff2></i><span class="align-middle" data-v-35d12ff2>Editar perfil</span></a>`);
      if (auth.value.impersonating) {
        _push(`<!--[--><div class="dropdown-divider" data-v-35d12ff2></div><button type="button" class="dropdown-item text-danger" data-v-35d12ff2><i class="ti ti-user-x me-1 align-middle" data-v-35d12ff2></i><span class="align-middle" data-v-35d12ff2>Sair da impersonação</span></button><!--]-->`);
      } else {
        _push(`<!---->`);
      }
      _push(`<div class="pt-2 mt-2 border-top" data-v-35d12ff2><button type="button" class="dropdown-item text-danger" data-v-35d12ff2><i class="ti ti-logout me-1 fs-17 align-middle" data-v-35d12ff2></i><span class="align-middle" data-v-35d12ff2>Sair</span></button></div></div></div></div></div></header><div class="sidebar" id="sidebar" data-v-35d12ff2><div class="sidebar-logo" data-v-35d12ff2><div data-v-35d12ff2><a${ssrRenderAttr("href", _ctx.route("panel.dashboard"))} class="logo logo-normal" data-v-35d12ff2><img${ssrRenderAttr("src", unref(logoSvg))} alt="EasyEye" data-v-35d12ff2></a><a${ssrRenderAttr("href", _ctx.route("panel.dashboard"))} class="logo-small" data-v-35d12ff2><img${ssrRenderAttr("src", unref(logoSmallSvg))} alt="EasyEye" data-v-35d12ff2></a><a${ssrRenderAttr("href", _ctx.route("panel.dashboard"))} class="dark-logo" data-v-35d12ff2><img${ssrRenderAttr("src", unref(logoWhiteSvg))} alt="EasyEye" data-v-35d12ff2></a></div><button class="sidenav-toggle-btn btn border-0 p-0 active" id="toggle_btn" data-v-35d12ff2><i class="ti ti-arrow-left" data-v-35d12ff2></i></button><button class="sidebar-close" data-v-35d12ff2><i class="ti ti-x align-middle" data-v-35d12ff2></i></button></div><div class="sidebar-inner" data-simplebar style="${ssrRenderStyle({ "padding-top": "45px" })}" data-v-35d12ff2><div class="sidebar-mini-avatar" data-v-35d12ff2><span class="avatar rounded-circle d-flex align-items-center justify-content-center text-white fw-bold" style="${ssrRenderStyle([{ "width": "36px", "height": "36px", "font-size": ".75rem" }, entity.value.logo_url ? { background: "transparent" } : {}])}"${ssrRenderAttr("title", entity.value.name)} data-v-35d12ff2>`);
      if (entity.value.logo_url) {
        _push(`<img${ssrRenderAttr("src", entity.value.logo_url)}${ssrRenderAttr("alt", entity.value.name)} class="rounded-circle" style="${ssrRenderStyle({ "width": "36px", "height": "36px", "object-fit": "cover" })}" data-v-35d12ff2>`);
      } else {
        _push(`<span data-v-35d12ff2>${ssrInterpolate(entityInitials(entity.value.name))}</span>`);
      }
      _push(`</span></div><div class="sidebar-top p-2 mx-3 mb-3 dropend" data-v-35d12ff2><a href="javascript:void(0);" class="drop-arrow-none" data-bs-toggle="dropdown" data-bs-auto-close="outside" data-bs-offset="0,22" aria-haspopup="false" aria-expanded="false" data-v-35d12ff2><div class="d-flex align-items-center gap-2" data-v-35d12ff2><span class="avatar rounded-circle flex-shrink-0 d-flex align-items-center justify-content-center text-white fw-bold" style="${ssrRenderStyle([{ "width": "36px", "height": "36px", "font-size": ".75rem" }, entity.value.logo_url ? { background: "transparent" } : {}])}" data-v-35d12ff2>`);
      if (entity.value.logo_url) {
        _push(`<img${ssrRenderAttr("src", entity.value.logo_url)}${ssrRenderAttr("alt", entity.value.name)} class="rounded-circle" style="${ssrRenderStyle({ "width": "36px", "height": "36px", "object-fit": "cover" })}" data-v-35d12ff2>`);
      } else {
        _push(`<span data-v-35d12ff2>${ssrInterpolate(entityInitials(entity.value.name))}</span>`);
      }
      _push(`</span><div class="overflow-hidden flex-grow-1" data-v-35d12ff2><h6 class="mb-0 text-truncate" data-v-35d12ff2>${ssrInterpolate(entity.value.name)}</h6><p class="fs-11 mb-0 text-truncate" data-v-35d12ff2>${ssrInterpolate(entity.value.plan ?? entity.value.city ?? "—")}</p></div><i class="ti ti-arrows-transfer-up flex-shrink-0" data-v-35d12ff2></i></div></a>`);
      if (entities.value.length > 1) {
        _push(`<div class="dropdown-menu dropdown-menu-lg p-2" data-v-35d12ff2><p class="text-muted small px-2 mb-1 fw-medium" data-v-35d12ff2>Trocar empresa</p><!--[-->`);
        ssrRenderList(entities.value, (e) => {
          _push(`<button type="button" class="${ssrRenderClass([{ "bg-primary bg-opacity-10": e.is_selected }, "dropdown-item d-flex align-items-center justify-content-between rounded-1 p-2"])}" data-v-35d12ff2><span class="d-flex align-items-center overflow-hidden" data-v-35d12ff2><span class="avatar avatar-xs rounded-circle flex-shrink-0 d-flex align-items-center justify-content-center text-white fw-bold me-2" style="${ssrRenderStyle({ "width": "28px", "height": "28px", "font-size": ".65rem", "background": "var(--bs-primary)" })}" data-v-35d12ff2>${ssrInterpolate(entityInitials(e.name))}</span><span class="overflow-hidden" data-v-35d12ff2><span class="fw-semibold text-dark d-block text-truncate" style="${ssrRenderStyle({ "font-size": ".8125rem" })}" data-v-35d12ff2>${ssrInterpolate(e.name)}</span><small class="text-muted d-block" style="${ssrRenderStyle({ "font-size": ".72rem" })}" data-v-35d12ff2>${ssrInterpolate(e.city || "—")}</small></span></span>`);
          if (e.is_selected) {
            _push(`<i class="ti ti-check text-primary flex-shrink-0 ms-2" data-v-35d12ff2></i>`);
          } else {
            _push(`<!---->`);
          }
          _push(`</button>`);
        });
        _push(`<!--]--></div>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div><div id="sidebar-menu" class="sidebar-menu" data-v-35d12ff2><ul data-v-35d12ff2><li class="menu-title" data-v-35d12ff2><span data-v-35d12ff2>Menu</span></li><li data-v-35d12ff2><ul data-v-35d12ff2><!--[-->`);
      ssrRenderList(nav.value, (item) => {
        _push(`<!--[-->`);
        if (item.section) {
          _push(`<li class="menu-title" data-v-35d12ff2><span data-v-35d12ff2>${ssrInterpolate(item.section)}</span></li>`);
        } else if (item.children) {
          _push(`<li class="submenu" data-v-35d12ff2><a href="#" class="${ssrRenderClass({ "subdrop active": isMenuOpen(item) })}" data-v-35d12ff2><i class="${ssrRenderClass(item.icon)}" data-v-35d12ff2></i><span data-v-35d12ff2>${ssrInterpolate(item.label)}</span><span class="menu-arrow" data-v-35d12ff2></span></a><ul style="${ssrRenderStyle(isMenuOpen(item) ? "display:block;" : "")}" data-v-35d12ff2><!--[-->`);
          ssrRenderList(item.children, (child) => {
            _push(`<li data-v-35d12ff2><a${ssrRenderAttr("href", _ctx.route(child.route))} class="${ssrRenderClass({ active: isActive(child.match) })}" data-v-35d12ff2>`);
            if (child.icon) {
              _push(`<i class="${ssrRenderClass(child.icon + " me-1")}" data-v-35d12ff2></i>`);
            } else {
              _push(`<!---->`);
            }
            _push(` ${ssrInterpolate(child.label)}</a></li>`);
          });
          _push(`<!--]--></ul></li>`);
        } else {
          _push(`<li data-v-35d12ff2><a${ssrRenderAttr("href", _ctx.route(item.route))} class="${ssrRenderClass({ active: isActive(item.match) })}" data-v-35d12ff2><i class="${ssrRenderClass(item.icon)}" data-v-35d12ff2></i><span data-v-35d12ff2>${ssrInterpolate(item.label)}</span></a></li>`);
        }
        _push(`<!--]-->`);
      });
      _push(`<!--]--></ul></li></ul></div></div></div><div class="page-wrapper" data-v-35d12ff2>`);
      if (auth.value.impersonating) {
        _push(`<div class="alert alert-warning alert-dismissible m-0 rounded-0 border-0 border-bottom d-flex align-items-center gap-2 py-2 px-3" data-v-35d12ff2><i class="ti ti-user-check fs-16" data-v-35d12ff2></i><span data-v-35d12ff2>Você está visualizando como <strong data-v-35d12ff2>${ssrInterpolate(user.value.name)}</strong> (${ssrInterpolate(user.value.email)})</span><button type="button" class="btn btn-sm btn-warning ms-auto" data-v-35d12ff2><i class="ti ti-user-x me-1" data-v-35d12ff2></i> Sair </button></div>`);
      } else {
        _push(`<!---->`);
      }
      if (flashSuccessOpen.value && flash.value.success) {
        _push(`<div class="alert alert-success m-3 mb-0 d-flex align-items-center gap-2" role="alert" data-v-35d12ff2><i class="ti ti-circle-check fs-16" data-v-35d12ff2></i><span class="flex-grow-1" data-v-35d12ff2>${ssrInterpolate(flash.value.success)}</span><button type="button" class="btn-close"${ssrRenderAttr("aria-label", "Fechar")} data-v-35d12ff2></button></div>`);
      } else {
        _push(`<!---->`);
      }
      if (flashErrorOpen.value && flash.value.error) {
        _push(`<div class="alert alert-danger m-3 mb-0 d-flex align-items-center gap-2" role="alert" data-v-35d12ff2><i class="ti ti-alert-circle fs-16" data-v-35d12ff2></i><span class="flex-grow-1" data-v-35d12ff2>${ssrInterpolate(flash.value.error)}</span><button type="button" class="btn-close"${ssrRenderAttr("aria-label", "Fechar")} data-v-35d12ff2></button></div>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<div class="content" data-v-35d12ff2>`);
      if (__props.breadcrumbs.length) {
        _push(`<nav aria-label="breadcrumb" class="d-flex justify-content-end mb-2" data-v-35d12ff2><ol class="breadcrumb mb-0 app-breadcrumb" data-v-35d12ff2><!--[-->`);
        ssrRenderList(__props.breadcrumbs, (crumb, i) => {
          _push(`<li class="${ssrRenderClass([{ active: crumb.active }, "breadcrumb-item"])}" data-v-35d12ff2>`);
          if (!crumb.active) {
            _push(`<a${ssrRenderAttr("href", crumb.url)} data-v-35d12ff2>${ssrInterpolate(crumb.label)}</a>`);
          } else {
            _push(`<span class="breadcrumb-active" data-v-35d12ff2>${ssrInterpolate(crumb.label)}</span>`);
          }
          _push(`</li>`);
        });
        _push(`<!--]--></ol></nav>`);
      } else {
        _push(`<!---->`);
      }
      ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
      _push(`</div></div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Layouts/AppLayout.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const AppLayout = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-35d12ff2"]]);
export {
  AppLayout as A
};
