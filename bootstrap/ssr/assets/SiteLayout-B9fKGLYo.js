import { computed, ref, onMounted, onUnmounted, resolveDirective, mergeProps, unref, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderClass, ssrGetDirectiveProps, ssrRenderAttr, ssrInterpolate, ssrRenderList, ssrRenderStyle, ssrRenderSlot } from "vue/server-renderer";
import { usePage } from "@inertiajs/vue3";
import { l as logoSvg, a as logoSmallSvg } from "./logo-small-Br31EOC_.js";
import { l as logoWhiteSvg } from "./logo-white-hVd1h5De.js";
const _sfc_main = {
  __name: "SiteLayout",
  __ssrInlineRender: true,
  props: {
    t: { type: Object, required: true },
    routes: { type: Object, required: true },
    appName: { type: String, default: "EasyEye" },
    hasHero: { type: Boolean, default: true }
  },
  setup(__props) {
    const props = __props;
    const page = usePage();
    const locales = computed(() => page.props.locales ?? []);
    const currentLocaleData = computed(() => locales.value.find((l) => l.active));
    const isScrolled = ref(false);
    const langOpen = ref(false);
    const mobileOpen = ref(false);
    const langSwitcher = ref(null);
    const currentYear = (/* @__PURE__ */ new Date()).getFullYear();
    function onScroll() {
      isScrolled.value = props.hasHero ? window.scrollY > 20 : true;
    }
    function onClickOutside(e) {
      if (langSwitcher.value && !langSwitcher.value.contains(e.target)) {
        langOpen.value = false;
      }
    }
    onMounted(() => {
      if (!props.hasHero) isScrolled.value = true;
      window.addEventListener("scroll", onScroll, { passive: true });
      document.addEventListener("click", onClickOutside);
    });
    onUnmounted(() => {
      window.removeEventListener("scroll", onScroll);
      document.removeEventListener("click", onClickOutside);
    });
    return (_ctx, _push, _parent, _attrs) => {
      var _a;
      const _directive_motion = resolveDirective("motion");
      _push(`<div${ssrRenderAttrs(_attrs)}><nav id="navbar" class="${ssrRenderClass({ scrolled: isScrolled.value })}"><div class="container"><div class="nav-inner"><a${ssrRenderAttrs(mergeProps({
        href: __props.routes.siteHome,
        class: "nav-logo",
        "aria-label": __props.appName,
        initial: { opacity: 0, x: -10 },
        enter: { opacity: 1, x: 0, transition: { duration: 600, delay: 50 } }
      }, ssrGetDirectiveProps(_ctx, _directive_motion)))}><span class="nav-logo-imgs"><img${ssrRenderAttr("src", unref(logoSvg))}${ssrRenderAttr("alt", __props.appName)} class="logo-v-dark"><img${ssrRenderAttr("src", unref(logoWhiteSvg))} alt="" class="logo-v-white" aria-hidden="true"></span></a><ul class="nav-links"><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#funcionalidades")}>${ssrInterpolate(__props.t.nav.features)}</a></li><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#como-funciona")}>${ssrInterpolate(__props.t.nav.how)}</a></li><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#precos")}>${ssrInterpolate(__props.t.nav.pricing)}</a></li><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#depoimentos")}>${ssrInterpolate(__props.t.nav.testimonials)}</a></li><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#faq")}>${ssrInterpolate(__props.t.nav.faq)}</a></li><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#contato")}>${ssrInterpolate(__props.t.nav.contact)}</a></li></ul><div class="nav-right"><div class="lang-switcher"><button class="lang-btn"${ssrRenderAttr("aria-expanded", langOpen.value)}${ssrRenderAttr("title", __props.t.nav.language)}><span>${ssrInterpolate(((_a = currentLocaleData.value) == null ? void 0 : _a.flag) ?? "🌐")}</span></button>`);
      if (langOpen.value) {
        _push(`<div class="lang-dropdown"><!--[-->`);
        ssrRenderList(locales.value, (locale) => {
          _push(`<a${ssrRenderAttr("href", locale.url)} class="${ssrRenderClass(["lang-item", { active: locale.active }])}"><span>${ssrInterpolate(locale.flag)}</span> ${ssrInterpolate(locale.native)} `);
          if (locale.active) {
            _push(`<i class="bi bi-check2 check"></i>`);
          } else {
            _push(`<!---->`);
          }
          _push(`</a>`);
        });
        _push(`<!--]--></div>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div><a${ssrRenderAttrs(mergeProps({
        href: __props.routes.go,
        class: "btn btn-outline",
        style: { "padding": "10px 20px", "font-size": "14px" },
        initial: { opacity: 0, y: -8 },
        enter: { opacity: 1, y: 0, transition: { duration: 400, delay: 200 } },
        hovered: { y: -2, transition: { duration: 200 } }
      }, ssrGetDirectiveProps(_ctx, _directive_motion)))}><i class="bi bi-box-arrow-in-right"></i> ${ssrInterpolate(__props.t.nav.login)}</a><a${ssrRenderAttrs(mergeProps({
        href: __props.routes.register,
        class: "btn btn-primary",
        style: { "padding": "10px 20px", "font-size": "14px" },
        initial: { opacity: 0, y: -8 },
        enter: { opacity: 1, y: 0, transition: { duration: 400, delay: 300 } },
        hovered: { y: -2, scale: 1.03, transition: { duration: 200 } }
      }, ssrGetDirectiveProps(_ctx, _directive_motion)))}>${ssrInterpolate(__props.t.nav.get_started)} <i class="bi bi-arrow-right"></i></a></div><button class="nav-mobile-btn"${ssrRenderAttr("aria-expanded", mobileOpen.value)}><i class="${ssrRenderClass([mobileOpen.value ? "bi-x-lg" : "bi-list", "bi"])}"></i></button></div></div><div class="${ssrRenderClass([{ open: mobileOpen.value }, "nav-mobile-menu"])}"><ul><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#funcionalidades")}>${ssrInterpolate(__props.t.nav.features)}</a></li><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#como-funciona")}>${ssrInterpolate(__props.t.nav.how)}</a></li><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#precos")}>${ssrInterpolate(__props.t.nav.pricing)}</a></li><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#depoimentos")}>${ssrInterpolate(__props.t.nav.testimonials)}</a></li><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#faq")}>${ssrInterpolate(__props.t.nav.faq)}</a></li><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#contato")}>${ssrInterpolate(__props.t.nav.contact)}</a></li></ul><div class="mobile-lang"><!--[-->`);
      ssrRenderList(locales.value, (locale) => {
        _push(`<a${ssrRenderAttr("href", locale.url)} class="${ssrRenderClass({ active: locale.active })}">${ssrInterpolate(locale.flag)} ${ssrInterpolate(locale.native)}</a>`);
      });
      _push(`<!--]--></div><div class="mobile-ctas"><a${ssrRenderAttr("href", __props.routes.go)} class="btn btn-outline" style="${ssrRenderStyle({ "justify-content": "center" })}"><i class="bi bi-box-arrow-in-right"></i> ${ssrInterpolate(__props.t.nav.login)}</a><a${ssrRenderAttr("href", __props.routes.register)} class="btn btn-primary" style="${ssrRenderStyle({ "justify-content": "center" })}">${ssrInterpolate(__props.t.nav.get_started)} <i class="bi bi-arrow-right"></i></a></div></div></nav>`);
      ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
      _push(`<footer><div class="container"><div class="footer-inner"><div class="footer-brand"><a${ssrRenderAttr("href", __props.routes.siteHome)} class="nav-logo"><img${ssrRenderAttr("src", unref(logoSmallSvg))}${ssrRenderAttr("alt", __props.appName)}></a><p>${ssrInterpolate(__props.t.footer.tagline)}</p><div class="footer-social"><a href="#" aria-label="Instagram"><i class="bi bi-instagram"></i></a><a href="#" aria-label="LinkedIn"><i class="bi bi-linkedin"></i></a><a href="#" aria-label="YouTube"><i class="bi bi-youtube"></i></a><a href="#" aria-label="WhatsApp"><i class="bi bi-whatsapp"></i></a></div></div><div class="footer-col"><h5>${ssrInterpolate(__props.t.footer.product)}</h5><ul><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#funcionalidades")}>${ssrInterpolate(__props.t.nav.features)}</a></li><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#precos")}>${ssrInterpolate(__props.t.nav.pricing)}</a></li><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#como-funciona")}>${ssrInterpolate(__props.t.nav.how)}</a></li><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#depoimentos")}>${ssrInterpolate(__props.t.nav.testimonials)}</a></li><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#faq")}>${ssrInterpolate(__props.t.nav.faq)}</a></li></ul></div><div class="footer-col"><h5>${ssrInterpolate(__props.t.footer.system)}</h5><ul><li><a${ssrRenderAttr("href", __props.routes.go)}>${ssrInterpolate(__props.t.footer.login)}</a></li><li><a${ssrRenderAttr("href", __props.routes.register)}>${ssrInterpolate(__props.t.footer.register)}</a></li><li><a href="#">${ssrInterpolate(__props.t.footer.help)}</a></li><li><a href="#">${ssrInterpolate(__props.t.footer.status)}</a></li><li><a href="#">${ssrInterpolate(__props.t.footer.api)}</a></li></ul></div><div class="footer-col"><h5>${ssrInterpolate(__props.t.footer.company)}</h5><ul><li><a href="#">${ssrInterpolate(__props.t.footer.about)}</a></li><li><a href="#">${ssrInterpolate(__props.t.footer.blog)}</a></li><li><a href="#">${ssrInterpolate(__props.t.footer.partners)}</a></li><li><a${ssrRenderAttr("href", __props.routes.siteHome + "#contato")}>${ssrInterpolate(__props.t.footer.contact)}</a></li><li><a href="#">${ssrInterpolate(__props.t.footer.careers)}</a></li></ul></div></div><div class="footer-bottom"><p>${(__props.t.footer.copyright ?? "").replace(":year", unref(currentYear)).replace(":name", __props.appName) ?? ""}</p><div class="footer-legal"><a href="#">${ssrInterpolate(__props.t.footer.privacy)}</a><a href="#">${ssrInterpolate(__props.t.footer.terms)}</a><a href="#">${ssrInterpolate(__props.t.footer.lgpd)}</a></div></div></div></footer></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Layouts/SiteLayout.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as _
};
