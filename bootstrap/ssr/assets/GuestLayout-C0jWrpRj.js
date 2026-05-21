import { computed, ref, onMounted, onUnmounted, mergeProps, unref, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderSlot, ssrRenderAttr, ssrRenderStyle, ssrInterpolate, ssrRenderList, ssrRenderClass } from "vue/server-renderer";
import { usePage } from "@inertiajs/vue3";
const regIllustrationImg = "/build/assets/reg-illustration-img-BuqCT9vX.png";
const logoSvg = "/build/assets/logo-CeTuDuKJ.svg";
const _sfc_main = {
  __name: "GuestLayout",
  __ssrInlineRender: true,
  props: {
    layoutMode: { type: String, default: "illustration" },
    // 'login-illustration' | 'illustration'
    title: { type: String, default: "" },
    subtitle: { type: String, default: "" },
    illustrationSrc: { type: String, default: () => regIllustrationImg },
    appName: { type: String, default: "EasyEye" }
  },
  setup(__props) {
    const props = __props;
    const page = usePage();
    const locales = computed(() => page.props.locales ?? []);
    const activeLocale = computed(() => locales.value.find((l) => l.active));
    const isLoginIllustration = computed(() => props.layoutMode === "login-illustration");
    const currentYear = (/* @__PURE__ */ new Date()).getFullYear();
    const isDark = ref(false);
    onMounted(() => {
      isDark.value = document.documentElement.getAttribute("data-bs-theme") === "dark";
    });
    const showLocale = ref(false);
    const localeEl = ref(null);
    function handleClickOutside(e) {
      if (localeEl.value && !localeEl.value.contains(e.target)) {
        showLocale.value = false;
      }
    }
    onMounted(() => document.addEventListener("click", handleClickOutside));
    onUnmounted(() => document.removeEventListener("click", handleClickOutside));
    return (_ctx, _push, _parent, _attrs) => {
      var _a, _b, _c, _d;
      _push(`<div${ssrRenderAttrs(mergeProps({
        class: ["main-wrapper auth-bg position-relative overflow-hidden min-vh-100", isLoginIllustration.value ? "ee-login-illustration-wrapper" : "ee-auth-illustration-wrapper"]
      }, _attrs))}><div class="container-fluid p-0">`);
      if (isLoginIllustration.value) {
        _push(`<div class="row g-0 min-vh-100"><div class="col-lg-7 d-none d-lg-flex align-items-center justify-content-center ee-login-illustration-side">`);
        ssrRenderSlot(_ctx.$slots, "left-panel", {}, () => {
          _push(`<img${ssrRenderAttr("src", __props.illustrationSrc)} class="img-fluid ee-login-illustration-image"${ssrRenderAttr("alt", __props.appName)}>`);
        }, _push, _parent);
        _push(`</div><div class="col-12 col-lg-5 d-flex align-items-center justify-content-center px-3 px-md-4 py-4 ee-login-form-side"><div class="w-100 ee-auth-shell ee-login-shell">`);
        ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
        _push(`<div class="border-top pt-3 mt-4 d-flex align-items-center justify-content-center gap-1">`);
        if (locales.value.length > 1) {
          _push(`<div class="position-relative"><button type="button" class="btn btn-link btn-sm text-muted text-decoration-none" style="${ssrRenderStyle({ "font-size": ".8rem" })}">${ssrInterpolate((_a = activeLocale.value) == null ? void 0 : _a.flag)} ${ssrInterpolate((_b = activeLocale.value) == null ? void 0 : _b.native)} <i class="ti ti-chevron-down" style="${ssrRenderStyle({ "font-size": "10px" })}"></i></button><div class="position-absolute bg-white border rounded shadow-sm py-1" style="${ssrRenderStyle([
            { "bottom": "calc(100% + 4px)", "right": "0", "min-width": "140px", "z-index": "200" },
            showLocale.value ? null : { display: "none" }
          ])}"><!--[-->`);
          ssrRenderList(locales.value, (locale) => {
            _push(`<a${ssrRenderAttr("href", locale.url)} style="${ssrRenderStyle({ "font-size": ".85rem", "color": "#334155" })}" class="${ssrRenderClass([{ "fw-semibold": locale.active }, "d-flex align-items-center gap-2 px-3 py-2 text-decoration-none"])}">${ssrInterpolate(locale.flag)} ${ssrInterpolate(locale.native)} `);
            if (locale.active) {
              _push(`<i class="ti ti-check ms-auto" style="${ssrRenderStyle({ "color": "var(--teal)" })}"></i>`);
            } else {
              _push(`<!---->`);
            }
            _push(`</a>`);
          });
          _push(`<!--]--></div></div>`);
        } else {
          _push(`<!---->`);
        }
        _push(`<button type="button" class="btn btn-link btn-sm text-muted p-1" style="${ssrRenderStyle({ "font-size": "1rem", "line-height": "1" })}">`);
        if (isDark.value) {
          _push(`<i class="ti ti-sun"></i>`);
        } else {
          _push(`<i class="ti ti-moon"></i>`);
        }
        _push(`</button></div><p class="text-center mt-3 mb-0" style="${ssrRenderStyle({ "font-size": ".8rem", "color": "#94a3b8" })}"> © ${ssrInterpolate(unref(currentYear))} ${ssrInterpolate(__props.appName)}</p></div></div></div>`);
      } else {
        _push(`<div class="row g-0 min-vh-100"><div class="col-lg-7 d-none d-lg-flex align-items-center justify-content-center ee-auth-illustration-side">`);
        ssrRenderSlot(_ctx.$slots, "left-panel", {}, () => {
          _push(`<img${ssrRenderAttr("src", __props.illustrationSrc)} class="img-fluid ee-auth-illustration-image"${ssrRenderAttr("alt", __props.appName)}>`);
        }, _push, _parent);
        _push(`</div><div class="col-12 col-lg-5 d-flex align-items-center justify-content-center px-3 px-md-4 py-4 ee-auth-form-side"><div class="w-100 ee-auth-shell ee-auth-illustration-shell"><div class="text-center mb-4"><a href="/login" class="d-inline-block"><img${ssrRenderAttr("src", unref(logoSvg))} class="img-fluid ee-auth-brand"${ssrRenderAttr("alt", __props.appName)}></a></div><div class="card ee-auth-illustration-card"><div class="card-body p-4 p-xl-5 ee-auth-form">`);
        if (__props.title) {
          _push(`<div class="text-center mb-4 ee-auth-header"><h4>${ssrInterpolate(__props.title)}</h4>`);
          if (__props.subtitle) {
            _push(`<p>${ssrInterpolate(__props.subtitle)}</p>`);
          } else {
            _push(`<!---->`);
          }
          _push(`</div>`);
        } else {
          _push(`<!---->`);
        }
        ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
        _push(`<div class="border-top pt-3 mt-4 d-flex align-items-center justify-content-center gap-1">`);
        if (locales.value.length > 1) {
          _push(`<div class="position-relative"><button type="button" class="btn btn-link btn-sm text-muted text-decoration-none" style="${ssrRenderStyle({ "font-size": ".8rem" })}">${ssrInterpolate((_c = activeLocale.value) == null ? void 0 : _c.flag)} ${ssrInterpolate((_d = activeLocale.value) == null ? void 0 : _d.native)} <i class="ti ti-chevron-down" style="${ssrRenderStyle({ "font-size": "10px" })}"></i></button><div class="position-absolute bg-white border rounded shadow-sm py-1" style="${ssrRenderStyle([
            { "bottom": "calc(100% + 4px)", "right": "0", "min-width": "140px", "z-index": "200" },
            showLocale.value ? null : { display: "none" }
          ])}"><!--[-->`);
          ssrRenderList(locales.value, (locale) => {
            _push(`<a${ssrRenderAttr("href", locale.url)} style="${ssrRenderStyle({ "font-size": ".85rem", "color": "#334155" })}" class="${ssrRenderClass([{ "fw-semibold": locale.active }, "d-flex align-items-center gap-2 px-3 py-2 text-decoration-none"])}">${ssrInterpolate(locale.flag)} ${ssrInterpolate(locale.native)} `);
            if (locale.active) {
              _push(`<i class="ti ti-check ms-auto" style="${ssrRenderStyle({ "color": "var(--teal)" })}"></i>`);
            } else {
              _push(`<!---->`);
            }
            _push(`</a>`);
          });
          _push(`<!--]--></div></div>`);
        } else {
          _push(`<!---->`);
        }
        _push(`<button type="button" class="btn btn-link btn-sm text-muted p-1" style="${ssrRenderStyle({ "font-size": "1rem", "line-height": "1" })}">`);
        if (isDark.value) {
          _push(`<i class="ti ti-sun"></i>`);
        } else {
          _push(`<i class="ti ti-moon"></i>`);
        }
        _push(`</button></div></div></div><p class="text-center mt-3 mb-0" style="${ssrRenderStyle({ "font-size": ".8rem", "color": "#94a3b8" })}"> © ${ssrInterpolate(unref(currentYear))} ${ssrInterpolate(__props.appName)}</p></div></div></div>`);
      }
      _push(`</div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Layouts/GuestLayout.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as _,
  logoSvg as l
};
