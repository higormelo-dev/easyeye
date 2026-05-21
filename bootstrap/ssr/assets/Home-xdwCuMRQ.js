import { ref, computed, unref, withCtx, createVNode, resolveDynamicComponent, toDisplayString, openBlock, createBlock, Fragment, renderList, createTextVNode, createCommentVNode, Transition, withModifiers, withDirectives, vModelText, vModelSelect, vModelCheckbox, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderAttr, ssrRenderList, ssrRenderVNode, ssrRenderStyle, ssrRenderClass, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual } from "vue/server-renderer";
import { Head } from "@inertiajs/vue3";
import { _ as _sfc_main$1 } from "./SiteLayout-B9fKGLYo.js";
import axios from "axios";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
const _sfc_main = {
  __name: "Home",
  __ssrInlineRender: true,
  props: {
    t: { type: Object, required: true },
    plans: { type: Array, default: () => [] },
    routes: { type: Object, required: true },
    appName: { type: String, default: "EasyEye" },
    howImageExists: { type: Boolean, default: false },
    seo: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const howActiveStep = ref(0);
    const faqOpen = ref(null);
    function toggleFaq(i) {
      faqOpen.value = faqOpen.value === i ? null : i;
    }
    const minTrial = computed(() => {
      const withTrial = props.plans.filter((p) => p.trial_days);
      return withTrial.length ? Math.min(...withTrial.map((p) => p.trial_days)) : null;
    });
    const trialDays = computed(() => {
      const withTrial = props.plans.filter((p) => p.trial_days);
      return withTrial.length ? Math.min(...withTrial.map((p) => p.trial_days)) : 14;
    });
    function formatPrice(price) {
      return Number(price).toLocaleString("pt-BR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    const contactForm = ref({
      name: "",
      email: "",
      phone: "",
      is_client: "",
      role: "",
      segment: "",
      terms: false
    });
    const contactSent = ref(false);
    const contactSending = ref(false);
    async function submitContact() {
      if (!contactForm.value.terms || contactSending.value) return;
      contactSending.value = true;
      try {
        await axios.post(props.routes.contactStore, contactForm.value);
        contactSent.value = true;
      } catch {
        contactSent.value = true;
      } finally {
        contactSending.value = false;
      }
    }
    function asset(path) {
      return "/" + path;
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), null, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<title${_scopeId}>${ssrInterpolate(__props.t.meta.title)}</title><meta name="description"${ssrRenderAttr("content", __props.t.meta.description)}${_scopeId}><link rel="canonical"${ssrRenderAttr("href", __props.seo.canonicalUrl)}${_scopeId}><!--[-->`);
            ssrRenderList(__props.seo.alternateLocales, (alt) => {
              _push2(`<link rel="alternate"${ssrRenderAttr("hreflang", alt.default ? "x-default" : alt.code)}${ssrRenderAttr("href", alt.url)}${_scopeId}>`);
            });
            _push2(`<!--]--><!--[-->`);
            ssrRenderList(__props.seo.alternateLocales, (alt) => {
              _push2(`<link rel="alternate"${ssrRenderAttr("hreflang", alt.code)}${ssrRenderAttr("href", alt.url)}${_scopeId}>`);
            });
            _push2(`<!--]--><meta property="og:title"${ssrRenderAttr("content", __props.t.meta.og_title)}${_scopeId}><meta property="og:description"${ssrRenderAttr("content", __props.t.meta.og_description)}${_scopeId}><meta property="og:type" content="website"${_scopeId}><meta property="og:url"${ssrRenderAttr("content", __props.seo.canonicalUrl)}${_scopeId}><meta property="og:image"${ssrRenderAttr("content", __props.seo.ogImage)}${_scopeId}><meta property="og:image:width" content="1200"${_scopeId}><meta property="og:image:height" content="630"${_scopeId}><meta property="og:locale"${ssrRenderAttr("content", __props.seo.currentLocale)}${_scopeId}><meta property="og:site_name"${ssrRenderAttr("content", __props.appName)}${_scopeId}><meta name="twitter:card" content="summary_large_image"${_scopeId}><meta name="twitter:title"${ssrRenderAttr("content", __props.t.meta.og_title)}${_scopeId}><meta name="twitter:description"${ssrRenderAttr("content", __props.t.meta.og_description)}${_scopeId}><meta name="twitter:image"${ssrRenderAttr("content", __props.seo.ogImage)}${_scopeId}><!--[-->`);
            ssrRenderList(__props.seo.jsonLd, (schema, i) => {
              ssrRenderVNode(_push2, createVNode(resolveDynamicComponent("script"), {
                key: i,
                type: "application/ld+json"
              }, null), _parent2, _scopeId);
            });
            _push2(`<!--]--><link rel="preconnect" href="https://fonts.googleapis.com"${_scopeId}><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="anonymous"${_scopeId}><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet"${_scopeId}><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"${_scopeId}>`);
          } else {
            return [
              createVNode("title", null, toDisplayString(__props.t.meta.title), 1),
              createVNode("meta", {
                name: "description",
                content: __props.t.meta.description
              }, null, 8, ["content"]),
              createVNode("link", {
                rel: "canonical",
                href: __props.seo.canonicalUrl
              }, null, 8, ["href"]),
              (openBlock(true), createBlock(Fragment, null, renderList(__props.seo.alternateLocales, (alt) => {
                return openBlock(), createBlock("link", {
                  key: alt.code,
                  rel: "alternate",
                  hreflang: alt.default ? "x-default" : alt.code,
                  href: alt.url
                }, null, 8, ["hreflang", "href"]);
              }), 128)),
              (openBlock(true), createBlock(Fragment, null, renderList(__props.seo.alternateLocales, (alt) => {
                return openBlock(), createBlock("link", {
                  key: "h-" + alt.code,
                  rel: "alternate",
                  hreflang: alt.code,
                  href: alt.url
                }, null, 8, ["hreflang", "href"]);
              }), 128)),
              createVNode("meta", {
                property: "og:title",
                content: __props.t.meta.og_title
              }, null, 8, ["content"]),
              createVNode("meta", {
                property: "og:description",
                content: __props.t.meta.og_description
              }, null, 8, ["content"]),
              createVNode("meta", {
                property: "og:type",
                content: "website"
              }),
              createVNode("meta", {
                property: "og:url",
                content: __props.seo.canonicalUrl
              }, null, 8, ["content"]),
              createVNode("meta", {
                property: "og:image",
                content: __props.seo.ogImage
              }, null, 8, ["content"]),
              createVNode("meta", {
                property: "og:image:width",
                content: "1200"
              }),
              createVNode("meta", {
                property: "og:image:height",
                content: "630"
              }),
              createVNode("meta", {
                property: "og:locale",
                content: __props.seo.currentLocale
              }, null, 8, ["content"]),
              createVNode("meta", {
                property: "og:site_name",
                content: __props.appName
              }, null, 8, ["content"]),
              createVNode("meta", {
                name: "twitter:card",
                content: "summary_large_image"
              }),
              createVNode("meta", {
                name: "twitter:title",
                content: __props.t.meta.og_title
              }, null, 8, ["content"]),
              createVNode("meta", {
                name: "twitter:description",
                content: __props.t.meta.og_description
              }, null, 8, ["content"]),
              createVNode("meta", {
                name: "twitter:image",
                content: __props.seo.ogImage
              }, null, 8, ["content"]),
              (openBlock(true), createBlock(Fragment, null, renderList(__props.seo.jsonLd, (schema, i) => {
                return openBlock(), createBlock(resolveDynamicComponent("script"), {
                  key: i,
                  type: "application/ld+json",
                  textContent: toDisplayString(JSON.stringify(schema))
                }, null, 8, ["textContent"]);
              }), 128)),
              createVNode("link", {
                rel: "preconnect",
                href: "https://fonts.googleapis.com"
              }),
              createVNode("link", {
                rel: "preconnect",
                href: "https://fonts.gstatic.com",
                crossorigin: "anonymous"
              }),
              createVNode("link", {
                href: "https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap",
                rel: "stylesheet"
              }),
              createVNode("link", {
                rel: "stylesheet",
                href: "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
              })
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(ssrRenderComponent(_sfc_main$1, {
        t: __props.t,
        routes: __props.routes,
        "app-name": __props.appName,
        "has-hero": true
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b, _c, _d, _e, _f, _g, _h;
          if (_push2) {
            _push2(`<section class="hero"${_scopeId}><div class="hero-blob hero-blob-1"${_scopeId}></div><div class="hero-blob hero-blob-2"${_scopeId}></div><div class="container"${_scopeId}><div class="hero-inner"${_scopeId}><div class="hero-text"${_scopeId}><div class="badge-pill"${_scopeId}><i class="bi bi-stars"${_scopeId}></i> ${ssrInterpolate(__props.t.hero.badge)}</div><h1 class="hero-title"${_scopeId}>${ssrInterpolate(__props.t.hero.title)}<br${_scopeId}><em${_scopeId}>${ssrInterpolate(__props.t.hero.title_em)}</em></h1><p class="hero-sub"${_scopeId}>${ssrInterpolate(__props.t.hero.subtitle)}</p><div class="hero-ctas"${_scopeId}><a${ssrRenderAttr("href", __props.routes.register)} class="btn btn-primary btn-lg"${_scopeId}>${ssrInterpolate(__props.t.hero.cta_primary)} <i class="bi bi-arrow-right"${_scopeId}></i></a><a href="#como-funciona" class="btn btn-outline-white btn-lg"${_scopeId}><i class="bi bi-play-circle"${_scopeId}></i> ${ssrInterpolate(__props.t.hero.cta_secondary)}</a></div><div class="hero-trust"${_scopeId}><div class="hero-trust-avatars"${_scopeId}><span${_scopeId}>JA</span><span${_scopeId}>MC</span><span${_scopeId}>RS</span><span${_scopeId}>PL</span></div><span${_scopeId}>${((_a = __props.t.hero.trust) == null ? void 0 : _a.replace(":count", "500")) ?? ""}</span></div></div><div class="hero-visual"${_scopeId}><div class="hero-float-card card-top" style="${ssrRenderStyle({ "border-left": "3px solid #06d6a0" })}"${_scopeId}><div class="icon" style="${ssrRenderStyle({ "background": "rgba(6,214,160,.1)" })}"${_scopeId}><i class="bi bi-calendar-check" style="${ssrRenderStyle({ "color": "#06d6a0" })}"${_scopeId}></i></div><div${_scopeId}><div style="${ssrRenderStyle({ "font-size": "11px", "color": "#64748b", "font-weight": "500" })}"${_scopeId}>${ssrInterpolate(__props.t.hero.card_today)}</div><div${_scopeId}>${ssrInterpolate((_b = __props.t.hero.card_appointments) == null ? void 0 : _b.replace(":count", "24"))}</div></div></div><div class="hero-mockup" style="${ssrRenderStyle({ "width": "100%" })}"${_scopeId}><div class="mockup-bar"${_scopeId}><span class="mockup-dot mockup-dot-r"${_scopeId}></span><span class="mockup-dot mockup-dot-y"${_scopeId}></span><span class="mockup-dot mockup-dot-g"${_scopeId}></span><div class="mockup-url"${_scopeId}></div></div><div class="mockup-body"${_scopeId}><div class="mockup-header-row"${_scopeId}><div class="mockup-h-block" style="${ssrRenderStyle({ "width": "140px", "background": "rgba(255,255,255,.25)" })}"${_scopeId}></div><div class="mockup-h-block" style="${ssrRenderStyle({ "width": "80px", "background": "rgba(0,180,216,.4)", "border-radius": "6px", "padding": "6px 0" })}"${_scopeId}></div></div><div class="mockup-stats"${_scopeId}><!--[-->`);
            ssrRenderList(["90px", "75px", "60px", "85px"], (w) => {
              _push2(`<div class="mockup-stat"${_scopeId}><div class="mockup-stat-val" style="${ssrRenderStyle("width:" + w)}"${_scopeId}></div><div class="mockup-stat-lbl"${_scopeId}></div></div>`);
            });
            _push2(`<!--]--></div><div class="mockup-chart"${_scopeId}><!--[-->`);
            ssrRenderList([40, 65, 50, 80, 70, 55, 90, 75, 85, 60, 95, 88], (h) => {
              _push2(`<div class="mockup-bar-item" style="${ssrRenderStyle("height:" + h + "%")}"${_scopeId}></div>`);
            });
            _push2(`<!--]--></div><!--[-->`);
            ssrRenderList(4, (i) => {
              _push2(`<div class="mockup-table-row"${_scopeId}><div class="mockup-avatar"${_scopeId}></div><div class="mockup-line" style="${ssrRenderStyle("width:" + (40 + i * 10) + "%")}"${_scopeId}></div><div class="mockup-line-sm"${_scopeId}></div></div>`);
            });
            _push2(`<!--]--></div></div><div class="hero-float-card card-bottom" style="${ssrRenderStyle({ "border-left": "3px solid #f97316" })}"${_scopeId}><div class="icon" style="${ssrRenderStyle({ "background": "rgba(249,115,22,.1)" })}"${_scopeId}><i class="bi bi-shield-check" style="${ssrRenderStyle({ "color": "#f97316" })}"${_scopeId}></i></div><div${_scopeId}><div style="${ssrRenderStyle({ "font-size": "11px", "color": "#64748b", "font-weight": "500" })}"${_scopeId}>${ssrInterpolate(__props.t.hero.card_compliance_lbl)}</div><div${_scopeId}>${ssrInterpolate(__props.t.hero.card_compliance_val)}</div></div></div></div></div></div></section><div class="metrics"${_scopeId}><div class="container"${_scopeId}><div class="metrics-grid"${_scopeId}><!--[-->`);
            ssrRenderList(__props.t.metrics, (metric) => {
              _push2(`<div class="metric-item"${_scopeId}><div class="metric-value"${_scopeId}>${ssrInterpolate(metric.value)}</div><div class="metric-label"${_scopeId}>${ssrInterpolate(metric.label)}</div></div>`);
            });
            _push2(`<!--]--></div></div></div><section class="features" id="funcionalidades"${_scopeId}><div class="container"${_scopeId}><div class="features-header"${_scopeId}><span class="section-label"${_scopeId}>${ssrInterpolate(__props.t.features.label)}</span><h2 class="section-title"${_scopeId}>${ssrInterpolate(__props.t.features.title)}</h2><p class="section-sub text-center"${_scopeId}>${ssrInterpolate(__props.t.features.subtitle)}</p></div><div class="features-grid"${_scopeId}><!--[-->`);
            ssrRenderList(__props.t.features.items, (feature) => {
              _push2(`<div class="feature-card"${_scopeId}><div class="${ssrRenderClass(["feature-icon", feature.color])}"${_scopeId}><i class="${ssrRenderClass("bi " + feature.icon)}"${_scopeId}></i></div><h3${_scopeId}>${ssrInterpolate(feature.title)}</h3><p${_scopeId}>${ssrInterpolate(feature.text)}</p></div>`);
            });
            _push2(`<!--]--></div></div></section><section class="how" id="como-funciona"${_scopeId}><div class="container"${_scopeId}><div class="how-inner"${_scopeId}><div${_scopeId}><span class="section-label"${_scopeId}>${ssrInterpolate(__props.t.how.label)}</span><h2 class="section-title"${_scopeId}>${ssrInterpolate(__props.t.how.title)}</h2><p class="section-sub" style="${ssrRenderStyle({ "margin-bottom": "36px" })}"${_scopeId}>${ssrInterpolate(__props.t.how.subtitle)}</p><div class="how-steps"${_scopeId}><!--[-->`);
            ssrRenderList(__props.t.how.steps, (step, i) => {
              _push2(`<div class="${ssrRenderClass(["how-step", { active: howActiveStep.value === i }])}"${_scopeId}><div class="how-step-num"${_scopeId}>${ssrInterpolate(i + 1)}</div><div class="how-step-content"${_scopeId}><h4${_scopeId}>${ssrInterpolate(step.title)}</h4><p${_scopeId}>${ssrInterpolate(step.text)}</p></div></div>`);
            });
            _push2(`<!--]--></div></div><div class="how-visual"${_scopeId}>`);
            if (__props.howImageExists) {
              _push2(`<img${ssrRenderAttr("src", asset("site/images/how-it-works.png"))}${ssrRenderAttr("alt", __props.t.how.screenshot_alt)}${_scopeId}>`);
            } else {
              _push2(`<div class="how-visual-placeholder"${_scopeId}><i class="bi bi-display"${_scopeId}></i><p style="${ssrRenderStyle({ "font-size": "15px" })}"${_scopeId}>${ssrInterpolate(__props.t.how.screenshot_placeholder)}</p><p style="${ssrRenderStyle({ "font-size": "13px", "margin-top": "4px" })}"${_scopeId}><code${_scopeId}>${ssrInterpolate(__props.t.how.screenshot_hint)}</code></p></div>`);
            }
            _push2(`</div></div></div></section><section class="compliance"${_scopeId}><div class="container"${_scopeId}><div class="compliance-inner"${_scopeId}><div${_scopeId}><span class="section-label"${_scopeId}>${ssrInterpolate(__props.t.compliance.label)}</span><h2 class="section-title"${_scopeId}>${ssrInterpolate(__props.t.compliance.title)}</h2><p class="section-sub"${_scopeId}>${ssrInterpolate(__props.t.compliance.subtitle)}</p></div><div class="compliance-badges"${_scopeId}><!--[-->`);
            ssrRenderList(__props.t.compliance.badges, (badge) => {
              _push2(`<div class="comp-badge"${_scopeId}><i class="${ssrRenderClass("bi " + badge.icon)}"${_scopeId}></i> ${ssrInterpolate(badge.label)}</div>`);
            });
            _push2(`<!--]--></div></div></div></section><section class="testimonials" id="depoimentos"${_scopeId}><div class="container"${_scopeId}><div class="testimonials-header"${_scopeId}><span class="section-label"${_scopeId}>${ssrInterpolate(__props.t.testimonials.label)}</span><h2 class="section-title"${_scopeId}>${ssrInterpolate(__props.t.testimonials.title)}</h2></div><div class="testimonials-grid"${_scopeId}><!--[-->`);
            ssrRenderList(__props.t.testimonials.items, (testimonial) => {
              _push2(`<div class="testimonial-card"${_scopeId}><div class="testimonial-stars"${_scopeId}><!--[-->`);
              ssrRenderList(5, (s) => {
                _push2(`<i class="${ssrRenderClass("bi " + (s <= testimonial.stars ? "bi-star-fill" : "bi-star"))}"${_scopeId}></i>`);
              });
              _push2(`<!--]--></div><p class="testimonial-text"${_scopeId}>${ssrInterpolate(testimonial.text)}</p><div class="testimonial-author"${_scopeId}><div class="testimonial-avatar"${_scopeId}>${ssrInterpolate(testimonial.initials)}</div><div${_scopeId}><div class="testimonial-name"${_scopeId}>${ssrInterpolate(testimonial.name)}</div><div class="testimonial-role"${_scopeId}>${ssrInterpolate(testimonial.role)}</div></div></div></div>`);
            });
            _push2(`<!--]--></div></div></section><section class="pricing" id="precos"${_scopeId}><div class="container"${_scopeId}><div class="pricing-header"${_scopeId}><span class="section-label"${_scopeId}>${ssrInterpolate(__props.t.pricing.label)}</span><h2 class="section-title"${_scopeId}>${ssrInterpolate(__props.t.pricing.title)}</h2><p class="section-sub text-center"${_scopeId}>${ssrInterpolate(__props.t.pricing.subtitle)} `);
            if (__props.plans.length && minTrial.value) {
              _push2(`<!--[-->${ssrInterpolate((_c = __props.t.pricing.trial_suffix) == null ? void 0 : _c.replace(":days", minTrial.value))}<!--]-->`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</p></div>`);
            if (!__props.plans.length) {
              _push2(`<div style="${ssrRenderStyle({ "text-align": "center", "padding": "60px 20px" })}"${_scopeId}><i class="bi bi-box-seam" style="${ssrRenderStyle({ "font-size": "56px", "color": "var(--border)", "display": "block", "margin-bottom": "16px" })}"${_scopeId}></i><p style="${ssrRenderStyle({ "font-size": "18px", "font-weight": "600", "color": "var(--navy)", "margin-bottom": "8px" })}"${_scopeId}>${ssrInterpolate(__props.t.pricing.empty_title)}</p><p style="${ssrRenderStyle({ "color": "var(--text-muted)", "margin-bottom": "24px" })}"${_scopeId}>${ssrInterpolate(__props.t.pricing.empty_subtitle)}</p><a href="mailto:contato@easyeye.com.br" class="btn btn-primary"${_scopeId}><i class="bi bi-chat-dots"${_scopeId}></i> ${ssrInterpolate(__props.t.pricing.contact_cta)}</a></div>`);
            } else {
              _push2(`<div class="pricing-grid"${_scopeId}><!--[-->`);
              ssrRenderList(__props.plans, (plan) => {
                var _a2, _b2;
                _push2(`<div class="${ssrRenderClass(["pricing-card", { featured: plan.is_featured }])}"${_scopeId}>`);
                if (plan.is_featured) {
                  _push2(`<div class="pricing-badge"${_scopeId}>${ssrInterpolate(__props.t.pricing.featured_badge)}</div>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`<div class="pricing-name"${_scopeId}>${ssrInterpolate(plan.name)}</div>`);
                if (plan.description) {
                  _push2(`<div class="pricing-desc"${_scopeId}>${ssrInterpolate(plan.description)}</div>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`<div class="pricing-price"${_scopeId}>`);
                if (plan.is_free) {
                  _push2(`<span class="price-value" style="${ssrRenderStyle(plan.is_featured ? "font-size:32px;color:#fff" : "font-size:32px;color:var(--navy)")}"${_scopeId}>${ssrInterpolate(__props.t.pricing.on_request)}</span>`);
                } else {
                  _push2(`<!--[--><span class="price-currency"${_scopeId}>R$</span><span class="price-value"${_scopeId}>${ssrInterpolate(formatPrice(plan.price))}</span><span class="price-period"${_scopeId}>${ssrInterpolate(plan.price_period_label)}</span><!--]-->`);
                }
                _push2(`</div>`);
                if (plan.trial_days) {
                  _push2(`<div style="${ssrRenderStyle(plan.is_featured ? "font-size:13px;margin-bottom:16px;color:rgba(255,255,255,.65);font-weight:600;" : "font-size:13px;margin-bottom:16px;color:var(--mint);font-weight:600;")}"${_scopeId}><i class="bi bi-gift"${_scopeId}></i> ${ssrInterpolate((_a2 = __props.t.pricing.trial_text) == null ? void 0 : _a2.replace(":days", plan.trial_days))}</div>`);
                } else {
                  _push2(`<!---->`);
                }
                if ((_b2 = plan.features) == null ? void 0 : _b2.length) {
                  _push2(`<ul class="pricing-features"${_scopeId}><!--[-->`);
                  ssrRenderList(plan.features, (feature) => {
                    _push2(`<li class="${ssrRenderClass({ disabled: !feature.enabled })}"${_scopeId}><i class="${ssrRenderClass("bi " + (feature.enabled ? plan.is_featured ? "bi-check-circle-fill" : "bi-check-circle-fill" : "bi-x-circle"))}" style="${ssrRenderStyle(feature.enabled && plan.is_featured ? "color:var(--mint)" : "")}"${_scopeId}></i> ${ssrInterpolate(feature.display_label)}</li>`);
                  });
                  _push2(`<!--]--></ul>`);
                } else {
                  _push2(`<!---->`);
                }
                if (plan.is_free) {
                  _push2(`<a href="mailto:contato@easyeye.com.br" class="${ssrRenderClass(["btn", plan.is_featured ? "btn-outline-white" : "btn-outline"])}" style="${ssrRenderStyle({ "width": "100%", "justify-content": "center" })}"${_scopeId}>${ssrInterpolate(__props.t.pricing.contact_cta)}</a>`);
                } else if (plan.is_featured) {
                  _push2(`<a${ssrRenderAttr("href", __props.routes.register)} class="btn btn-featured btn-primary" style="${ssrRenderStyle({ "width": "100%", "justify-content": "center" })}"${_scopeId}>${ssrInterpolate(__props.t.pricing.get_started)} <i class="bi bi-arrow-right"${_scopeId}></i></a>`);
                } else {
                  _push2(`<a${ssrRenderAttr("href", __props.routes.register)} class="btn btn-outline" style="${ssrRenderStyle({ "width": "100%", "justify-content": "center" })}"${_scopeId}>${ssrInterpolate(__props.t.pricing.get_started)}</a>`);
                }
                _push2(`</div>`);
              });
              _push2(`<!--]--></div>`);
            }
            if (__props.plans.length) {
              _push2(`<div class="pricing-credit-note"${_scopeId}><i class="bi bi-info-circle-fill"${_scopeId}></i><p${_scopeId}>${__props.t.pricing_credit_note_html ?? ""}</p></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></section><section class="faq" id="faq"${_scopeId}><div class="container"${_scopeId}><div class="faq-header"${_scopeId}><span class="section-label"${_scopeId}>${ssrInterpolate(__props.t.faq.label)}</span><h2 class="section-title"${_scopeId}>${ssrInterpolate(__props.t.faq.title)}</h2></div><div class="faq-list"${_scopeId}><!--[-->`);
            ssrRenderList(__props.t.faq.items, (faq, i) => {
              _push2(`<div class="faq-item"${_scopeId}><div class="faq-question"${_scopeId}>${ssrInterpolate(faq.q)} <i class="${ssrRenderClass("bi " + (faqOpen.value === i ? "bi-dash-lg" : "bi-plus-lg"))}"${_scopeId}></i></div>`);
              if (faqOpen.value === i) {
                _push2(`<div class="faq-answer"${_scopeId}><div class="faq-answer-inner"${_scopeId}>${ssrInterpolate(faq.a)}</div></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div>`);
            });
            _push2(`<!--]--></div></div></section><section class="contact" id="contato"${_scopeId}><div class="container"${_scopeId}><div class="contact-header"${_scopeId}><span class="section-label"${_scopeId}>${ssrInterpolate(__props.t.contact.label)}</span><h2 class="contact-headline"${_scopeId}>${ssrInterpolate(__props.t.contact.headline_pre)} <em${_scopeId}>EasyEye</em>?<br${_scopeId}> ${ssrInterpolate(__props.t.contact.headline_post)}</h2><p class="contact-subline"${_scopeId}>${ssrInterpolate(__props.t.contact.subtitle)}</p></div><div class="contact-cards"${_scopeId}><div class="contact-card"${_scopeId}><div class="contact-card-icon icon-teal"${_scopeId}><i class="bi bi-whatsapp"${_scopeId}></i></div><h3${_scopeId}>${ssrInterpolate(__props.t.contact.sales.title)}</h3><p class="contact-card-desc"${_scopeId}>${ssrInterpolate(__props.t.contact.sales.desc)}</p><div class="contact-card-meta"${_scopeId}><i class="bi bi-clock"${_scopeId}></i> ${ssrInterpolate(__props.t.contact.sales.hours)}</div><div class="contact-card-meta" style="${ssrRenderStyle({ "margin-bottom": "20px" })}"${_scopeId}><i class="bi bi-whatsapp"${_scopeId}></i> ${ssrInterpolate(__props.t.contact.sales.channel)}</div><a href="https://wa.me/5500000000000" target="_blank" rel="noopener noreferrer" class="btn btn-primary" style="${ssrRenderStyle({ "width": "100%", "justify-content": "center" })}"${_scopeId}><i class="bi bi-whatsapp"${_scopeId}></i> ${ssrInterpolate(__props.t.contact.sales.cta)}</a></div><div class="contact-card"${_scopeId}><div class="contact-card-icon icon-blue"${_scopeId}><i class="bi bi-headset"${_scopeId}></i></div><h3${_scopeId}>${ssrInterpolate(__props.t.contact.support.title)}</h3><p class="contact-card-desc"${_scopeId}>${ssrInterpolate(__props.t.contact.support.desc)}</p><div class="contact-card-meta"${_scopeId}><i class="bi bi-clock"${_scopeId}></i> ${ssrInterpolate(__props.t.contact.support.hours)}</div><div class="contact-card-meta" style="${ssrRenderStyle({ "margin-bottom": "20px" })}"${_scopeId}><i class="bi bi-envelope"${_scopeId}></i> ${ssrInterpolate(__props.t.contact.support.channel)}</div><a${ssrRenderAttr("href", "mailto:" + __props.t.contact.support.channel)} class="btn btn-outline" style="${ssrRenderStyle({ "width": "100%", "justify-content": "center" })}"${_scopeId}><i class="bi bi-envelope"${_scopeId}></i> ${ssrInterpolate(__props.t.contact.support.cta)}</a></div><div class="contact-card highlight"${_scopeId}><div class="contact-card-badge"${_scopeId}>${ssrInterpolate(__props.t.contact.trial.badge)}</div><div class="contact-card-icon" style="${ssrRenderStyle({ "background": "rgba(255,255,255,.1)", "color": "var(--teal)" })}"${_scopeId}><i class="bi bi-rocket-takeoff"${_scopeId}></i></div><h3${_scopeId}>${ssrInterpolate(__props.t.contact.trial.title)}</h3><p class="contact-card-desc"${_scopeId}>${ssrInterpolate((_d = __props.t.contact.trial.desc) == null ? void 0 : _d.replace(":days", trialDays.value))}</p><div class="contact-card-meta" style="${ssrRenderStyle({ "margin-bottom": "20px" })}"${_scopeId}><i class="bi bi-check-circle-fill"${_scopeId}></i> ${ssrInterpolate(__props.t.contact.trial.note)}</div><a${ssrRenderAttr("href", __props.routes.register)} class="btn" style="${ssrRenderStyle({ "background": "var(--teal)", "color": "#fff", "width": "100%", "justify-content": "center" })}"${_scopeId}>${ssrInterpolate(__props.t.contact.trial.cta)} <i class="bi bi-arrow-right"${_scopeId}></i></a></div></div><div class="contact-main"${_scopeId}><div class="contact-form-box"${_scopeId}>`);
            if (contactSent.value) {
              _push2(`<div class="cf-success"${_scopeId}><div class="cf-success-icon"${_scopeId}><i class="bi bi-check2-circle"${_scopeId}></i></div><h3${_scopeId}>${ssrInterpolate(__props.t.contact.form.success_title)}</h3><p${_scopeId}>${ssrInterpolate(__props.t.contact.form.success_body)}</p></div>`);
            } else {
              _push2(`<div${_scopeId}><h3${_scopeId}>${ssrInterpolate(__props.t.contact.form.title)}</h3><p${_scopeId}>${ssrInterpolate(__props.t.contact.form.subtitle)}</p><form novalidate${_scopeId}><div class="cf-row"${_scopeId}><div class="cf-group"${_scopeId}><label for="cf-name"${_scopeId}>${ssrInterpolate(__props.t.contact.form.name)} *</label><input id="cf-name"${ssrRenderAttr("value", contactForm.value.name)} type="text" class="cf-control"${ssrRenderAttr("placeholder", __props.t.contact.form.name_ph)} required${_scopeId}></div><div class="cf-group"${_scopeId}><label for="cf-email"${_scopeId}>${ssrInterpolate(__props.t.contact.form.email)} *</label><input id="cf-email"${ssrRenderAttr("value", contactForm.value.email)} type="email" class="cf-control" placeholder="seu@email.com.br" required${_scopeId}></div></div><div class="cf-group"${_scopeId}><label for="cf-phone"${_scopeId}>${ssrInterpolate(__props.t.contact.form.phone)} *</label><input id="cf-phone"${ssrRenderAttr("value", contactForm.value.phone)} type="tel" class="cf-control" placeholder="(00) 00000-0000" required${_scopeId}></div><div class="cf-row"${_scopeId}><div class="cf-group"${_scopeId}><label for="cf-client"${_scopeId}>${ssrInterpolate(__props.t.contact.form.is_client)}</label><select id="cf-client" class="cf-control"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(contactForm.value.is_client) ? ssrLooseContain(contactForm.value.is_client, "") : ssrLooseEqual(contactForm.value.is_client, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.contact.form.select)}</option><!--[-->`);
              ssrRenderList(__props.t.contact.form.is_client_opts, (opt) => {
                _push2(`<option${ssrRenderAttr("value", opt)}${ssrIncludeBooleanAttr(Array.isArray(contactForm.value.is_client) ? ssrLooseContain(contactForm.value.is_client, opt) : ssrLooseEqual(contactForm.value.is_client, opt)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(opt)}</option>`);
              });
              _push2(`<!--]--></select></div><div class="cf-group"${_scopeId}><label for="cf-role"${_scopeId}>${ssrInterpolate(__props.t.contact.form.role)}</label><select id="cf-role" class="cf-control"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(contactForm.value.role) ? ssrLooseContain(contactForm.value.role, "") : ssrLooseEqual(contactForm.value.role, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.contact.form.select)}</option><!--[-->`);
              ssrRenderList(__props.t.contact.form.role_opts, (opt) => {
                _push2(`<option${ssrRenderAttr("value", opt)}${ssrIncludeBooleanAttr(Array.isArray(contactForm.value.role) ? ssrLooseContain(contactForm.value.role, opt) : ssrLooseEqual(contactForm.value.role, opt)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(opt)}</option>`);
              });
              _push2(`<!--]--></select></div></div><div class="cf-group"${_scopeId}><label for="cf-segment"${_scopeId}>${ssrInterpolate(__props.t.contact.form.segment)}</label><select id="cf-segment" class="cf-control"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(contactForm.value.segment) ? ssrLooseContain(contactForm.value.segment, "") : ssrLooseEqual(contactForm.value.segment, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.contact.form.select)}</option><!--[-->`);
              ssrRenderList(__props.t.contact.form.segment_opts, (opt) => {
                _push2(`<option${ssrRenderAttr("value", opt)}${ssrIncludeBooleanAttr(Array.isArray(contactForm.value.segment) ? ssrLooseContain(contactForm.value.segment, opt) : ssrLooseEqual(contactForm.value.segment, opt)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(opt)}</option>`);
              });
              _push2(`<!--]--></select></div><div class="cf-check"${_scopeId}><input type="checkbox" id="cf-terms"${ssrIncludeBooleanAttr(Array.isArray(contactForm.value.terms) ? ssrLooseContain(contactForm.value.terms, null) : contactForm.value.terms) ? " checked" : ""} required${_scopeId}><label for="cf-terms"${_scopeId}>${__props.t.contact.form.terms ?? ""}</label></div><button type="submit" class="cf-submit"${ssrIncludeBooleanAttr(!contactForm.value.terms || contactSending.value) ? " disabled" : ""}${_scopeId}><i class="${ssrRenderClass("bi " + (contactSending.value ? "bi-arrow-repeat cf-spin" : "bi-send"))}"${_scopeId}></i><span${_scopeId}>${ssrInterpolate(contactSending.value ? __props.t.contact.form.sending : __props.t.contact.form.submit)}</span></button></form></div>`);
            }
            _push2(`</div><div class="contact-aside"${_scopeId}><div class="contact-aside-item"${_scopeId}><div class="contact-aside-icon icon-teal"${_scopeId}><i class="bi bi-whatsapp"${_scopeId}></i></div><div${_scopeId}><h4${_scopeId}>WhatsApp</h4><p${_scopeId}><a href="https://wa.me/5500000000000" target="_blank" rel="noopener noreferrer"${_scopeId}>(00) 00000-0000</a><br${_scopeId}> ${ssrInterpolate(__props.t.contact.sales.hours)}</p></div></div><div class="contact-aside-item"${_scopeId}><div class="contact-aside-icon icon-blue"${_scopeId}><i class="bi bi-envelope"${_scopeId}></i></div><div${_scopeId}><h4${_scopeId}>E-mail</h4><p${_scopeId}><a href="mailto:contato@easyeye.com.br"${_scopeId}>contato@easyeye.com.br</a><br${_scopeId}><a href="mailto:suporte@easyeye.com.br"${_scopeId}>suporte@easyeye.com.br</a></p></div></div><div class="contact-aside-item"${_scopeId}><div class="contact-aside-icon icon-mint"${_scopeId}><i class="bi bi-clock"${_scopeId}></i></div><div${_scopeId}><h4${_scopeId}>${ssrInterpolate(__props.t.contact.aside.hours_title)}</h4><p${_scopeId}>${ssrInterpolate(__props.t.contact.aside.hours_body)}</p></div></div><div class="contact-aside-item"${_scopeId}><div class="contact-aside-icon icon-purple"${_scopeId}><i class="bi bi-chat-dots"${_scopeId}></i></div><div${_scopeId}><h4${_scopeId}>${ssrInterpolate(__props.t.contact.aside.chat_title)}</h4><p${_scopeId}>${ssrInterpolate(__props.t.contact.aside.chat_body)}</p></div></div><hr class="contact-aside-divider"${_scopeId}><div class="contact-aside-quote"${_scopeId}><p${_scopeId}>&quot;${ssrInterpolate(__props.t.contact.aside.quote_text)}&quot;</p><span${_scopeId}>${ssrInterpolate(__props.t.contact.aside.quote_author)}</span></div></div></div><div class="contact-trust"${_scopeId}><div class="contact-trust-item"${_scopeId}><i class="bi bi-lock-fill"${_scopeId}></i> <span${_scopeId}>${ssrInterpolate(__props.t.contact.trust_ssl)}</span></div><div class="contact-trust-item"${_scopeId}><i class="bi bi-shield-fill-check"${_scopeId}></i> <span${_scopeId}>${ssrInterpolate(__props.t.contact.trust_lgpd)}</span></div><div class="contact-trust-item"${_scopeId}><i class="bi bi-patch-check-fill"${_scopeId}></i> <span${_scopeId}>${ssrInterpolate(__props.t.contact.trust_cfm)}</span></div><div class="contact-trust-item"${_scopeId}><i class="bi bi-star-fill" style="${ssrRenderStyle({ "color": "#f59e0b" })}"${_scopeId}></i> <span${_scopeId}>${ssrInterpolate(__props.t.contact.trust_nps)}</span></div></div></div></section><section class="cta-final"${_scopeId}><div class="container"${_scopeId}><h2${_scopeId}>${ssrInterpolate(__props.t.cta.title)}</h2><p${_scopeId}>${ssrInterpolate(__props.t.cta.subtitle)}</p><div class="cta-final-btns"${_scopeId}><a${ssrRenderAttr("href", __props.routes.register)} class="btn btn-primary btn-lg"${_scopeId}>${ssrInterpolate(__props.t.cta.primary)} <i class="bi bi-arrow-right"${_scopeId}></i></a><a href="mailto:contato@easyeye.com.br" class="btn btn-outline-white btn-lg"${_scopeId}><i class="bi bi-chat-dots"${_scopeId}></i> ${ssrInterpolate(__props.t.cta.secondary)}</a></div><p class="cta-note"${_scopeId}>${ssrInterpolate(__props.t.cta.note)}</p></div></section>`);
          } else {
            return [
              createVNode("section", { class: "hero" }, [
                createVNode("div", { class: "hero-blob hero-blob-1" }),
                createVNode("div", { class: "hero-blob hero-blob-2" }),
                createVNode("div", { class: "container" }, [
                  createVNode("div", { class: "hero-inner" }, [
                    createVNode("div", { class: "hero-text" }, [
                      createVNode("div", { class: "badge-pill" }, [
                        createVNode("i", { class: "bi bi-stars" }),
                        createTextVNode(" " + toDisplayString(__props.t.hero.badge), 1)
                      ]),
                      createVNode("h1", { class: "hero-title" }, [
                        createTextVNode(toDisplayString(__props.t.hero.title), 1),
                        createVNode("br"),
                        createVNode("em", null, toDisplayString(__props.t.hero.title_em), 1)
                      ]),
                      createVNode("p", { class: "hero-sub" }, toDisplayString(__props.t.hero.subtitle), 1),
                      createVNode("div", { class: "hero-ctas" }, [
                        createVNode("a", {
                          href: __props.routes.register,
                          class: "btn btn-primary btn-lg"
                        }, [
                          createTextVNode(toDisplayString(__props.t.hero.cta_primary) + " ", 1),
                          createVNode("i", { class: "bi bi-arrow-right" })
                        ], 8, ["href"]),
                        createVNode("a", {
                          href: "#como-funciona",
                          class: "btn btn-outline-white btn-lg"
                        }, [
                          createVNode("i", { class: "bi bi-play-circle" }),
                          createTextVNode(" " + toDisplayString(__props.t.hero.cta_secondary), 1)
                        ])
                      ]),
                      createVNode("div", { class: "hero-trust" }, [
                        createVNode("div", { class: "hero-trust-avatars" }, [
                          createVNode("span", null, "JA"),
                          createVNode("span", null, "MC"),
                          createVNode("span", null, "RS"),
                          createVNode("span", null, "PL")
                        ]),
                        createVNode("span", {
                          innerHTML: (_e = __props.t.hero.trust) == null ? void 0 : _e.replace(":count", "500")
                        }, null, 8, ["innerHTML"])
                      ])
                    ]),
                    createVNode("div", { class: "hero-visual" }, [
                      createVNode("div", {
                        class: "hero-float-card card-top",
                        style: { "border-left": "3px solid #06d6a0" }
                      }, [
                        createVNode("div", {
                          class: "icon",
                          style: { "background": "rgba(6,214,160,.1)" }
                        }, [
                          createVNode("i", {
                            class: "bi bi-calendar-check",
                            style: { "color": "#06d6a0" }
                          })
                        ]),
                        createVNode("div", null, [
                          createVNode("div", { style: { "font-size": "11px", "color": "#64748b", "font-weight": "500" } }, toDisplayString(__props.t.hero.card_today), 1),
                          createVNode("div", null, toDisplayString((_f = __props.t.hero.card_appointments) == null ? void 0 : _f.replace(":count", "24")), 1)
                        ])
                      ]),
                      createVNode("div", {
                        class: "hero-mockup",
                        style: { "width": "100%" }
                      }, [
                        createVNode("div", { class: "mockup-bar" }, [
                          createVNode("span", { class: "mockup-dot mockup-dot-r" }),
                          createVNode("span", { class: "mockup-dot mockup-dot-y" }),
                          createVNode("span", { class: "mockup-dot mockup-dot-g" }),
                          createVNode("div", { class: "mockup-url" })
                        ]),
                        createVNode("div", { class: "mockup-body" }, [
                          createVNode("div", { class: "mockup-header-row" }, [
                            createVNode("div", {
                              class: "mockup-h-block",
                              style: { "width": "140px", "background": "rgba(255,255,255,.25)" }
                            }),
                            createVNode("div", {
                              class: "mockup-h-block",
                              style: { "width": "80px", "background": "rgba(0,180,216,.4)", "border-radius": "6px", "padding": "6px 0" }
                            })
                          ]),
                          createVNode("div", { class: "mockup-stats" }, [
                            (openBlock(), createBlock(Fragment, null, renderList(["90px", "75px", "60px", "85px"], (w) => {
                              return createVNode("div", {
                                key: w,
                                class: "mockup-stat"
                              }, [
                                createVNode("div", {
                                  class: "mockup-stat-val",
                                  style: "width:" + w
                                }, null, 4),
                                createVNode("div", { class: "mockup-stat-lbl" })
                              ]);
                            }), 64))
                          ]),
                          createVNode("div", { class: "mockup-chart" }, [
                            (openBlock(), createBlock(Fragment, null, renderList([40, 65, 50, 80, 70, 55, 90, 75, 85, 60, 95, 88], (h) => {
                              return createVNode("div", {
                                key: h,
                                class: "mockup-bar-item",
                                style: "height:" + h + "%"
                              }, null, 4);
                            }), 64))
                          ]),
                          (openBlock(), createBlock(Fragment, null, renderList(4, (i) => {
                            return createVNode("div", {
                              key: i,
                              class: "mockup-table-row"
                            }, [
                              createVNode("div", { class: "mockup-avatar" }),
                              createVNode("div", {
                                class: "mockup-line",
                                style: "width:" + (40 + i * 10) + "%"
                              }, null, 4),
                              createVNode("div", { class: "mockup-line-sm" })
                            ]);
                          }), 64))
                        ])
                      ]),
                      createVNode("div", {
                        class: "hero-float-card card-bottom",
                        style: { "border-left": "3px solid #f97316" }
                      }, [
                        createVNode("div", {
                          class: "icon",
                          style: { "background": "rgba(249,115,22,.1)" }
                        }, [
                          createVNode("i", {
                            class: "bi bi-shield-check",
                            style: { "color": "#f97316" }
                          })
                        ]),
                        createVNode("div", null, [
                          createVNode("div", { style: { "font-size": "11px", "color": "#64748b", "font-weight": "500" } }, toDisplayString(__props.t.hero.card_compliance_lbl), 1),
                          createVNode("div", null, toDisplayString(__props.t.hero.card_compliance_val), 1)
                        ])
                      ])
                    ])
                  ])
                ])
              ]),
              createVNode("div", { class: "metrics" }, [
                createVNode("div", { class: "container" }, [
                  createVNode("div", { class: "metrics-grid" }, [
                    (openBlock(true), createBlock(Fragment, null, renderList(__props.t.metrics, (metric) => {
                      return openBlock(), createBlock("div", {
                        key: metric.value,
                        class: "metric-item"
                      }, [
                        createVNode("div", { class: "metric-value" }, toDisplayString(metric.value), 1),
                        createVNode("div", { class: "metric-label" }, toDisplayString(metric.label), 1)
                      ]);
                    }), 128))
                  ])
                ])
              ]),
              createVNode("section", {
                class: "features",
                id: "funcionalidades"
              }, [
                createVNode("div", { class: "container" }, [
                  createVNode("div", { class: "features-header" }, [
                    createVNode("span", { class: "section-label" }, toDisplayString(__props.t.features.label), 1),
                    createVNode("h2", { class: "section-title" }, toDisplayString(__props.t.features.title), 1),
                    createVNode("p", { class: "section-sub text-center" }, toDisplayString(__props.t.features.subtitle), 1)
                  ]),
                  createVNode("div", { class: "features-grid" }, [
                    (openBlock(true), createBlock(Fragment, null, renderList(__props.t.features.items, (feature) => {
                      return openBlock(), createBlock("div", {
                        key: feature.title,
                        class: "feature-card"
                      }, [
                        createVNode("div", {
                          class: ["feature-icon", feature.color]
                        }, [
                          createVNode("i", {
                            class: "bi " + feature.icon
                          }, null, 2)
                        ], 2),
                        createVNode("h3", null, toDisplayString(feature.title), 1),
                        createVNode("p", null, toDisplayString(feature.text), 1)
                      ]);
                    }), 128))
                  ])
                ])
              ]),
              createVNode("section", {
                class: "how",
                id: "como-funciona"
              }, [
                createVNode("div", { class: "container" }, [
                  createVNode("div", { class: "how-inner" }, [
                    createVNode("div", null, [
                      createVNode("span", { class: "section-label" }, toDisplayString(__props.t.how.label), 1),
                      createVNode("h2", { class: "section-title" }, toDisplayString(__props.t.how.title), 1),
                      createVNode("p", {
                        class: "section-sub",
                        style: { "margin-bottom": "36px" }
                      }, toDisplayString(__props.t.how.subtitle), 1),
                      createVNode("div", { class: "how-steps" }, [
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.t.how.steps, (step, i) => {
                          return openBlock(), createBlock("div", {
                            key: i,
                            class: ["how-step", { active: howActiveStep.value === i }],
                            onClick: ($event) => howActiveStep.value = i
                          }, [
                            createVNode("div", { class: "how-step-num" }, toDisplayString(i + 1), 1),
                            createVNode("div", { class: "how-step-content" }, [
                              createVNode("h4", null, toDisplayString(step.title), 1),
                              createVNode("p", null, toDisplayString(step.text), 1)
                            ])
                          ], 10, ["onClick"]);
                        }), 128))
                      ])
                    ]),
                    createVNode("div", { class: "how-visual" }, [
                      __props.howImageExists ? (openBlock(), createBlock("img", {
                        key: 0,
                        src: asset("site/images/how-it-works.png"),
                        alt: __props.t.how.screenshot_alt
                      }, null, 8, ["src", "alt"])) : (openBlock(), createBlock("div", {
                        key: 1,
                        class: "how-visual-placeholder"
                      }, [
                        createVNode("i", { class: "bi bi-display" }),
                        createVNode("p", { style: { "font-size": "15px" } }, toDisplayString(__props.t.how.screenshot_placeholder), 1),
                        createVNode("p", { style: { "font-size": "13px", "margin-top": "4px" } }, [
                          createVNode("code", null, toDisplayString(__props.t.how.screenshot_hint), 1)
                        ])
                      ]))
                    ])
                  ])
                ])
              ]),
              createVNode("section", { class: "compliance" }, [
                createVNode("div", { class: "container" }, [
                  createVNode("div", { class: "compliance-inner" }, [
                    createVNode("div", null, [
                      createVNode("span", { class: "section-label" }, toDisplayString(__props.t.compliance.label), 1),
                      createVNode("h2", { class: "section-title" }, toDisplayString(__props.t.compliance.title), 1),
                      createVNode("p", { class: "section-sub" }, toDisplayString(__props.t.compliance.subtitle), 1)
                    ]),
                    createVNode("div", { class: "compliance-badges" }, [
                      (openBlock(true), createBlock(Fragment, null, renderList(__props.t.compliance.badges, (badge) => {
                        return openBlock(), createBlock("div", {
                          key: badge.label,
                          class: "comp-badge"
                        }, [
                          createVNode("i", {
                            class: "bi " + badge.icon
                          }, null, 2),
                          createTextVNode(" " + toDisplayString(badge.label), 1)
                        ]);
                      }), 128))
                    ])
                  ])
                ])
              ]),
              createVNode("section", {
                class: "testimonials",
                id: "depoimentos"
              }, [
                createVNode("div", { class: "container" }, [
                  createVNode("div", { class: "testimonials-header" }, [
                    createVNode("span", { class: "section-label" }, toDisplayString(__props.t.testimonials.label), 1),
                    createVNode("h2", { class: "section-title" }, toDisplayString(__props.t.testimonials.title), 1)
                  ]),
                  createVNode("div", { class: "testimonials-grid" }, [
                    (openBlock(true), createBlock(Fragment, null, renderList(__props.t.testimonials.items, (testimonial) => {
                      return openBlock(), createBlock("div", {
                        key: testimonial.name,
                        class: "testimonial-card"
                      }, [
                        createVNode("div", { class: "testimonial-stars" }, [
                          (openBlock(), createBlock(Fragment, null, renderList(5, (s) => {
                            return createVNode("i", {
                              key: s,
                              class: "bi " + (s <= testimonial.stars ? "bi-star-fill" : "bi-star")
                            }, null, 2);
                          }), 64))
                        ]),
                        createVNode("p", { class: "testimonial-text" }, toDisplayString(testimonial.text), 1),
                        createVNode("div", { class: "testimonial-author" }, [
                          createVNode("div", { class: "testimonial-avatar" }, toDisplayString(testimonial.initials), 1),
                          createVNode("div", null, [
                            createVNode("div", { class: "testimonial-name" }, toDisplayString(testimonial.name), 1),
                            createVNode("div", { class: "testimonial-role" }, toDisplayString(testimonial.role), 1)
                          ])
                        ])
                      ]);
                    }), 128))
                  ])
                ])
              ]),
              createVNode("section", {
                class: "pricing",
                id: "precos"
              }, [
                createVNode("div", { class: "container" }, [
                  createVNode("div", { class: "pricing-header" }, [
                    createVNode("span", { class: "section-label" }, toDisplayString(__props.t.pricing.label), 1),
                    createVNode("h2", { class: "section-title" }, toDisplayString(__props.t.pricing.title), 1),
                    createVNode("p", { class: "section-sub text-center" }, [
                      createTextVNode(toDisplayString(__props.t.pricing.subtitle) + " ", 1),
                      __props.plans.length && minTrial.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                        createTextVNode(toDisplayString((_g = __props.t.pricing.trial_suffix) == null ? void 0 : _g.replace(":days", minTrial.value)), 1)
                      ], 64)) : createCommentVNode("", true)
                    ])
                  ]),
                  !__props.plans.length ? (openBlock(), createBlock("div", {
                    key: 0,
                    style: { "text-align": "center", "padding": "60px 20px" }
                  }, [
                    createVNode("i", {
                      class: "bi bi-box-seam",
                      style: { "font-size": "56px", "color": "var(--border)", "display": "block", "margin-bottom": "16px" }
                    }),
                    createVNode("p", { style: { "font-size": "18px", "font-weight": "600", "color": "var(--navy)", "margin-bottom": "8px" } }, toDisplayString(__props.t.pricing.empty_title), 1),
                    createVNode("p", { style: { "color": "var(--text-muted)", "margin-bottom": "24px" } }, toDisplayString(__props.t.pricing.empty_subtitle), 1),
                    createVNode("a", {
                      href: "mailto:contato@easyeye.com.br",
                      class: "btn btn-primary"
                    }, [
                      createVNode("i", { class: "bi bi-chat-dots" }),
                      createTextVNode(" " + toDisplayString(__props.t.pricing.contact_cta), 1)
                    ])
                  ])) : (openBlock(), createBlock("div", {
                    key: 1,
                    class: "pricing-grid"
                  }, [
                    (openBlock(true), createBlock(Fragment, null, renderList(__props.plans, (plan) => {
                      var _a2, _b2;
                      return openBlock(), createBlock("div", {
                        key: plan.id,
                        class: ["pricing-card", { featured: plan.is_featured }]
                      }, [
                        plan.is_featured ? (openBlock(), createBlock("div", {
                          key: 0,
                          class: "pricing-badge"
                        }, toDisplayString(__props.t.pricing.featured_badge), 1)) : createCommentVNode("", true),
                        createVNode("div", { class: "pricing-name" }, toDisplayString(plan.name), 1),
                        plan.description ? (openBlock(), createBlock("div", {
                          key: 1,
                          class: "pricing-desc"
                        }, toDisplayString(plan.description), 1)) : createCommentVNode("", true),
                        createVNode("div", { class: "pricing-price" }, [
                          plan.is_free ? (openBlock(), createBlock("span", {
                            key: 0,
                            class: "price-value",
                            style: plan.is_featured ? "font-size:32px;color:#fff" : "font-size:32px;color:var(--navy)"
                          }, toDisplayString(__props.t.pricing.on_request), 5)) : (openBlock(), createBlock(Fragment, { key: 1 }, [
                            createVNode("span", { class: "price-currency" }, "R$"),
                            createVNode("span", { class: "price-value" }, toDisplayString(formatPrice(plan.price)), 1),
                            createVNode("span", { class: "price-period" }, toDisplayString(plan.price_period_label), 1)
                          ], 64))
                        ]),
                        plan.trial_days ? (openBlock(), createBlock("div", {
                          key: 2,
                          style: plan.is_featured ? "font-size:13px;margin-bottom:16px;color:rgba(255,255,255,.65);font-weight:600;" : "font-size:13px;margin-bottom:16px;color:var(--mint);font-weight:600;"
                        }, [
                          createVNode("i", { class: "bi bi-gift" }),
                          createTextVNode(" " + toDisplayString((_a2 = __props.t.pricing.trial_text) == null ? void 0 : _a2.replace(":days", plan.trial_days)), 1)
                        ], 4)) : createCommentVNode("", true),
                        ((_b2 = plan.features) == null ? void 0 : _b2.length) ? (openBlock(), createBlock("ul", {
                          key: 3,
                          class: "pricing-features"
                        }, [
                          (openBlock(true), createBlock(Fragment, null, renderList(plan.features, (feature) => {
                            return openBlock(), createBlock("li", {
                              key: feature.id,
                              class: { disabled: !feature.enabled }
                            }, [
                              createVNode("i", {
                                class: "bi " + (feature.enabled ? plan.is_featured ? "bi-check-circle-fill" : "bi-check-circle-fill" : "bi-x-circle"),
                                style: feature.enabled && plan.is_featured ? "color:var(--mint)" : ""
                              }, null, 6),
                              createTextVNode(" " + toDisplayString(feature.display_label), 1)
                            ], 2);
                          }), 128))
                        ])) : createCommentVNode("", true),
                        plan.is_free ? (openBlock(), createBlock("a", {
                          key: 4,
                          href: "mailto:contato@easyeye.com.br",
                          class: ["btn", plan.is_featured ? "btn-outline-white" : "btn-outline"],
                          style: { "width": "100%", "justify-content": "center" }
                        }, toDisplayString(__props.t.pricing.contact_cta), 3)) : plan.is_featured ? (openBlock(), createBlock("a", {
                          key: 5,
                          href: __props.routes.register,
                          class: "btn btn-featured btn-primary",
                          style: { "width": "100%", "justify-content": "center" }
                        }, [
                          createTextVNode(toDisplayString(__props.t.pricing.get_started) + " ", 1),
                          createVNode("i", { class: "bi bi-arrow-right" })
                        ], 8, ["href"])) : (openBlock(), createBlock("a", {
                          key: 6,
                          href: __props.routes.register,
                          class: "btn btn-outline",
                          style: { "width": "100%", "justify-content": "center" }
                        }, toDisplayString(__props.t.pricing.get_started), 9, ["href"]))
                      ], 2);
                    }), 128))
                  ])),
                  __props.plans.length ? (openBlock(), createBlock("div", {
                    key: 2,
                    class: "pricing-credit-note"
                  }, [
                    createVNode("i", { class: "bi bi-info-circle-fill" }),
                    createVNode("p", {
                      innerHTML: __props.t.pricing_credit_note_html
                    }, null, 8, ["innerHTML"])
                  ])) : createCommentVNode("", true)
                ])
              ]),
              createVNode("section", {
                class: "faq",
                id: "faq"
              }, [
                createVNode("div", { class: "container" }, [
                  createVNode("div", { class: "faq-header" }, [
                    createVNode("span", { class: "section-label" }, toDisplayString(__props.t.faq.label), 1),
                    createVNode("h2", { class: "section-title" }, toDisplayString(__props.t.faq.title), 1)
                  ]),
                  createVNode("div", { class: "faq-list" }, [
                    (openBlock(true), createBlock(Fragment, null, renderList(__props.t.faq.items, (faq, i) => {
                      return openBlock(), createBlock("div", {
                        key: i,
                        class: "faq-item"
                      }, [
                        createVNode("div", {
                          class: "faq-question",
                          onClick: ($event) => toggleFaq(i)
                        }, [
                          createTextVNode(toDisplayString(faq.q) + " ", 1),
                          createVNode("i", {
                            class: "bi " + (faqOpen.value === i ? "bi-dash-lg" : "bi-plus-lg")
                          }, null, 2)
                        ], 8, ["onClick"]),
                        createVNode(Transition, { name: "fade" }, {
                          default: withCtx(() => [
                            faqOpen.value === i ? (openBlock(), createBlock("div", {
                              key: 0,
                              class: "faq-answer"
                            }, [
                              createVNode("div", { class: "faq-answer-inner" }, toDisplayString(faq.a), 1)
                            ])) : createCommentVNode("", true)
                          ]),
                          _: 2
                        }, 1024)
                      ]);
                    }), 128))
                  ])
                ])
              ]),
              createVNode("section", {
                class: "contact",
                id: "contato"
              }, [
                createVNode("div", { class: "container" }, [
                  createVNode("div", { class: "contact-header" }, [
                    createVNode("span", { class: "section-label" }, toDisplayString(__props.t.contact.label), 1),
                    createVNode("h2", { class: "contact-headline" }, [
                      createTextVNode(toDisplayString(__props.t.contact.headline_pre) + " ", 1),
                      createVNode("em", null, "EasyEye"),
                      createTextVNode("?"),
                      createVNode("br"),
                      createTextVNode(" " + toDisplayString(__props.t.contact.headline_post), 1)
                    ]),
                    createVNode("p", { class: "contact-subline" }, toDisplayString(__props.t.contact.subtitle), 1)
                  ]),
                  createVNode("div", { class: "contact-cards" }, [
                    createVNode("div", { class: "contact-card" }, [
                      createVNode("div", { class: "contact-card-icon icon-teal" }, [
                        createVNode("i", { class: "bi bi-whatsapp" })
                      ]),
                      createVNode("h3", null, toDisplayString(__props.t.contact.sales.title), 1),
                      createVNode("p", { class: "contact-card-desc" }, toDisplayString(__props.t.contact.sales.desc), 1),
                      createVNode("div", { class: "contact-card-meta" }, [
                        createVNode("i", { class: "bi bi-clock" }),
                        createTextVNode(" " + toDisplayString(__props.t.contact.sales.hours), 1)
                      ]),
                      createVNode("div", {
                        class: "contact-card-meta",
                        style: { "margin-bottom": "20px" }
                      }, [
                        createVNode("i", { class: "bi bi-whatsapp" }),
                        createTextVNode(" " + toDisplayString(__props.t.contact.sales.channel), 1)
                      ]),
                      createVNode("a", {
                        href: "https://wa.me/5500000000000",
                        target: "_blank",
                        rel: "noopener noreferrer",
                        class: "btn btn-primary",
                        style: { "width": "100%", "justify-content": "center" }
                      }, [
                        createVNode("i", { class: "bi bi-whatsapp" }),
                        createTextVNode(" " + toDisplayString(__props.t.contact.sales.cta), 1)
                      ])
                    ]),
                    createVNode("div", { class: "contact-card" }, [
                      createVNode("div", { class: "contact-card-icon icon-blue" }, [
                        createVNode("i", { class: "bi bi-headset" })
                      ]),
                      createVNode("h3", null, toDisplayString(__props.t.contact.support.title), 1),
                      createVNode("p", { class: "contact-card-desc" }, toDisplayString(__props.t.contact.support.desc), 1),
                      createVNode("div", { class: "contact-card-meta" }, [
                        createVNode("i", { class: "bi bi-clock" }),
                        createTextVNode(" " + toDisplayString(__props.t.contact.support.hours), 1)
                      ]),
                      createVNode("div", {
                        class: "contact-card-meta",
                        style: { "margin-bottom": "20px" }
                      }, [
                        createVNode("i", { class: "bi bi-envelope" }),
                        createTextVNode(" " + toDisplayString(__props.t.contact.support.channel), 1)
                      ]),
                      createVNode("a", {
                        href: "mailto:" + __props.t.contact.support.channel,
                        class: "btn btn-outline",
                        style: { "width": "100%", "justify-content": "center" }
                      }, [
                        createVNode("i", { class: "bi bi-envelope" }),
                        createTextVNode(" " + toDisplayString(__props.t.contact.support.cta), 1)
                      ], 8, ["href"])
                    ]),
                    createVNode("div", { class: "contact-card highlight" }, [
                      createVNode("div", { class: "contact-card-badge" }, toDisplayString(__props.t.contact.trial.badge), 1),
                      createVNode("div", {
                        class: "contact-card-icon",
                        style: { "background": "rgba(255,255,255,.1)", "color": "var(--teal)" }
                      }, [
                        createVNode("i", { class: "bi bi-rocket-takeoff" })
                      ]),
                      createVNode("h3", null, toDisplayString(__props.t.contact.trial.title), 1),
                      createVNode("p", { class: "contact-card-desc" }, toDisplayString((_h = __props.t.contact.trial.desc) == null ? void 0 : _h.replace(":days", trialDays.value)), 1),
                      createVNode("div", {
                        class: "contact-card-meta",
                        style: { "margin-bottom": "20px" }
                      }, [
                        createVNode("i", { class: "bi bi-check-circle-fill" }),
                        createTextVNode(" " + toDisplayString(__props.t.contact.trial.note), 1)
                      ]),
                      createVNode("a", {
                        href: __props.routes.register,
                        class: "btn",
                        style: { "background": "var(--teal)", "color": "#fff", "width": "100%", "justify-content": "center" }
                      }, [
                        createTextVNode(toDisplayString(__props.t.contact.trial.cta) + " ", 1),
                        createVNode("i", { class: "bi bi-arrow-right" })
                      ], 8, ["href"])
                    ])
                  ]),
                  createVNode("div", { class: "contact-main" }, [
                    createVNode("div", { class: "contact-form-box" }, [
                      contactSent.value ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "cf-success"
                      }, [
                        createVNode("div", { class: "cf-success-icon" }, [
                          createVNode("i", { class: "bi bi-check2-circle" })
                        ]),
                        createVNode("h3", null, toDisplayString(__props.t.contact.form.success_title), 1),
                        createVNode("p", null, toDisplayString(__props.t.contact.form.success_body), 1)
                      ])) : (openBlock(), createBlock("div", { key: 1 }, [
                        createVNode("h3", null, toDisplayString(__props.t.contact.form.title), 1),
                        createVNode("p", null, toDisplayString(__props.t.contact.form.subtitle), 1),
                        createVNode("form", {
                          onSubmit: withModifiers(submitContact, ["prevent"]),
                          novalidate: ""
                        }, [
                          createVNode("div", { class: "cf-row" }, [
                            createVNode("div", { class: "cf-group" }, [
                              createVNode("label", { for: "cf-name" }, toDisplayString(__props.t.contact.form.name) + " *", 1),
                              withDirectives(createVNode("input", {
                                id: "cf-name",
                                "onUpdate:modelValue": ($event) => contactForm.value.name = $event,
                                type: "text",
                                class: "cf-control",
                                placeholder: __props.t.contact.form.name_ph,
                                required: ""
                              }, null, 8, ["onUpdate:modelValue", "placeholder"]), [
                                [vModelText, contactForm.value.name]
                              ])
                            ]),
                            createVNode("div", { class: "cf-group" }, [
                              createVNode("label", { for: "cf-email" }, toDisplayString(__props.t.contact.form.email) + " *", 1),
                              withDirectives(createVNode("input", {
                                id: "cf-email",
                                "onUpdate:modelValue": ($event) => contactForm.value.email = $event,
                                type: "email",
                                class: "cf-control",
                                placeholder: "seu@email.com.br",
                                required: ""
                              }, null, 8, ["onUpdate:modelValue"]), [
                                [vModelText, contactForm.value.email]
                              ])
                            ])
                          ]),
                          createVNode("div", { class: "cf-group" }, [
                            createVNode("label", { for: "cf-phone" }, toDisplayString(__props.t.contact.form.phone) + " *", 1),
                            withDirectives(createVNode("input", {
                              id: "cf-phone",
                              "onUpdate:modelValue": ($event) => contactForm.value.phone = $event,
                              type: "tel",
                              class: "cf-control",
                              placeholder: "(00) 00000-0000",
                              required: ""
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelText, contactForm.value.phone]
                            ])
                          ]),
                          createVNode("div", { class: "cf-row" }, [
                            createVNode("div", { class: "cf-group" }, [
                              createVNode("label", { for: "cf-client" }, toDisplayString(__props.t.contact.form.is_client), 1),
                              withDirectives(createVNode("select", {
                                id: "cf-client",
                                "onUpdate:modelValue": ($event) => contactForm.value.is_client = $event,
                                class: "cf-control"
                              }, [
                                createVNode("option", { value: "" }, toDisplayString(__props.t.contact.form.select), 1),
                                (openBlock(true), createBlock(Fragment, null, renderList(__props.t.contact.form.is_client_opts, (opt) => {
                                  return openBlock(), createBlock("option", {
                                    key: opt,
                                    value: opt
                                  }, toDisplayString(opt), 9, ["value"]);
                                }), 128))
                              ], 8, ["onUpdate:modelValue"]), [
                                [vModelSelect, contactForm.value.is_client]
                              ])
                            ]),
                            createVNode("div", { class: "cf-group" }, [
                              createVNode("label", { for: "cf-role" }, toDisplayString(__props.t.contact.form.role), 1),
                              withDirectives(createVNode("select", {
                                id: "cf-role",
                                "onUpdate:modelValue": ($event) => contactForm.value.role = $event,
                                class: "cf-control"
                              }, [
                                createVNode("option", { value: "" }, toDisplayString(__props.t.contact.form.select), 1),
                                (openBlock(true), createBlock(Fragment, null, renderList(__props.t.contact.form.role_opts, (opt) => {
                                  return openBlock(), createBlock("option", {
                                    key: opt,
                                    value: opt
                                  }, toDisplayString(opt), 9, ["value"]);
                                }), 128))
                              ], 8, ["onUpdate:modelValue"]), [
                                [vModelSelect, contactForm.value.role]
                              ])
                            ])
                          ]),
                          createVNode("div", { class: "cf-group" }, [
                            createVNode("label", { for: "cf-segment" }, toDisplayString(__props.t.contact.form.segment), 1),
                            withDirectives(createVNode("select", {
                              id: "cf-segment",
                              "onUpdate:modelValue": ($event) => contactForm.value.segment = $event,
                              class: "cf-control"
                            }, [
                              createVNode("option", { value: "" }, toDisplayString(__props.t.contact.form.select), 1),
                              (openBlock(true), createBlock(Fragment, null, renderList(__props.t.contact.form.segment_opts, (opt) => {
                                return openBlock(), createBlock("option", {
                                  key: opt,
                                  value: opt
                                }, toDisplayString(opt), 9, ["value"]);
                              }), 128))
                            ], 8, ["onUpdate:modelValue"]), [
                              [vModelSelect, contactForm.value.segment]
                            ])
                          ]),
                          createVNode("div", { class: "cf-check" }, [
                            withDirectives(createVNode("input", {
                              type: "checkbox",
                              id: "cf-terms",
                              "onUpdate:modelValue": ($event) => contactForm.value.terms = $event,
                              required: ""
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelCheckbox, contactForm.value.terms]
                            ]),
                            createVNode("label", {
                              for: "cf-terms",
                              innerHTML: __props.t.contact.form.terms
                            }, null, 8, ["innerHTML"])
                          ]),
                          createVNode("button", {
                            type: "submit",
                            class: "cf-submit",
                            disabled: !contactForm.value.terms || contactSending.value
                          }, [
                            createVNode("i", {
                              class: "bi " + (contactSending.value ? "bi-arrow-repeat cf-spin" : "bi-send")
                            }, null, 2),
                            createVNode("span", null, toDisplayString(contactSending.value ? __props.t.contact.form.sending : __props.t.contact.form.submit), 1)
                          ], 8, ["disabled"])
                        ], 32)
                      ]))
                    ]),
                    createVNode("div", { class: "contact-aside" }, [
                      createVNode("div", { class: "contact-aside-item" }, [
                        createVNode("div", { class: "contact-aside-icon icon-teal" }, [
                          createVNode("i", { class: "bi bi-whatsapp" })
                        ]),
                        createVNode("div", null, [
                          createVNode("h4", null, "WhatsApp"),
                          createVNode("p", null, [
                            createVNode("a", {
                              href: "https://wa.me/5500000000000",
                              target: "_blank",
                              rel: "noopener noreferrer"
                            }, "(00) 00000-0000"),
                            createVNode("br"),
                            createTextVNode(" " + toDisplayString(__props.t.contact.sales.hours), 1)
                          ])
                        ])
                      ]),
                      createVNode("div", { class: "contact-aside-item" }, [
                        createVNode("div", { class: "contact-aside-icon icon-blue" }, [
                          createVNode("i", { class: "bi bi-envelope" })
                        ]),
                        createVNode("div", null, [
                          createVNode("h4", null, "E-mail"),
                          createVNode("p", null, [
                            createVNode("a", { href: "mailto:contato@easyeye.com.br" }, "contato@easyeye.com.br"),
                            createVNode("br"),
                            createVNode("a", { href: "mailto:suporte@easyeye.com.br" }, "suporte@easyeye.com.br")
                          ])
                        ])
                      ]),
                      createVNode("div", { class: "contact-aside-item" }, [
                        createVNode("div", { class: "contact-aside-icon icon-mint" }, [
                          createVNode("i", { class: "bi bi-clock" })
                        ]),
                        createVNode("div", null, [
                          createVNode("h4", null, toDisplayString(__props.t.contact.aside.hours_title), 1),
                          createVNode("p", null, toDisplayString(__props.t.contact.aside.hours_body), 1)
                        ])
                      ]),
                      createVNode("div", { class: "contact-aside-item" }, [
                        createVNode("div", { class: "contact-aside-icon icon-purple" }, [
                          createVNode("i", { class: "bi bi-chat-dots" })
                        ]),
                        createVNode("div", null, [
                          createVNode("h4", null, toDisplayString(__props.t.contact.aside.chat_title), 1),
                          createVNode("p", null, toDisplayString(__props.t.contact.aside.chat_body), 1)
                        ])
                      ]),
                      createVNode("hr", { class: "contact-aside-divider" }),
                      createVNode("div", { class: "contact-aside-quote" }, [
                        createVNode("p", null, '"' + toDisplayString(__props.t.contact.aside.quote_text) + '"', 1),
                        createVNode("span", null, toDisplayString(__props.t.contact.aside.quote_author), 1)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "contact-trust" }, [
                    createVNode("div", { class: "contact-trust-item" }, [
                      createVNode("i", { class: "bi bi-lock-fill" }),
                      createTextVNode(),
                      createVNode("span", null, toDisplayString(__props.t.contact.trust_ssl), 1)
                    ]),
                    createVNode("div", { class: "contact-trust-item" }, [
                      createVNode("i", { class: "bi bi-shield-fill-check" }),
                      createTextVNode(),
                      createVNode("span", null, toDisplayString(__props.t.contact.trust_lgpd), 1)
                    ]),
                    createVNode("div", { class: "contact-trust-item" }, [
                      createVNode("i", { class: "bi bi-patch-check-fill" }),
                      createTextVNode(),
                      createVNode("span", null, toDisplayString(__props.t.contact.trust_cfm), 1)
                    ]),
                    createVNode("div", { class: "contact-trust-item" }, [
                      createVNode("i", {
                        class: "bi bi-star-fill",
                        style: { "color": "#f59e0b" }
                      }),
                      createTextVNode(),
                      createVNode("span", null, toDisplayString(__props.t.contact.trust_nps), 1)
                    ])
                  ])
                ])
              ]),
              createVNode("section", { class: "cta-final" }, [
                createVNode("div", { class: "container" }, [
                  createVNode("h2", null, toDisplayString(__props.t.cta.title), 1),
                  createVNode("p", null, toDisplayString(__props.t.cta.subtitle), 1),
                  createVNode("div", { class: "cta-final-btns" }, [
                    createVNode("a", {
                      href: __props.routes.register,
                      class: "btn btn-primary btn-lg"
                    }, [
                      createTextVNode(toDisplayString(__props.t.cta.primary) + " ", 1),
                      createVNode("i", { class: "bi bi-arrow-right" })
                    ], 8, ["href"]),
                    createVNode("a", {
                      href: "mailto:contato@easyeye.com.br",
                      class: "btn btn-outline-white btn-lg"
                    }, [
                      createVNode("i", { class: "bi bi-chat-dots" }),
                      createTextVNode(" " + toDisplayString(__props.t.cta.secondary), 1)
                    ])
                  ]),
                  createVNode("p", { class: "cta-note" }, toDisplayString(__props.t.cta.note), 1)
                ])
              ])
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`<!--]-->`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Site/Home.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
