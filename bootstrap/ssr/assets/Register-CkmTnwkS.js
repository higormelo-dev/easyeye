import { ref, computed, watch, unref, withCtx, createVNode, toDisplayString, createTextVNode, openBlock, createBlock, Fragment, Transition, withModifiers, withDirectives, vModelText, createCommentVNode, vModelDynamic, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrRenderStyle, ssrRenderDynamicModel, ssrRenderList, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { Head } from "@inertiajs/vue3";
import axios from "axios";
import { _ as _sfc_main$1 } from "./SiteLayout-B9fKGLYo.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
const _sfc_main = {
  __name: "Register",
  __ssrInlineRender: true,
  props: {
    appName: { type: String, default: "EasyEye" },
    t: { type: Object, default: () => ({}) },
    // site translations for SiteLayout
    tAuth: { type: Object, default: () => ({}) },
    // auth translations for the form
    plans: { type: Array, default: () => [] },
    trialDays: { type: Number, default: 14 },
    routes: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    var _a, _b, _c;
    const props = __props;
    const step = ref(1);
    const form = ref({
      name: "",
      email: "",
      password: "",
      password_confirmation: "",
      company_name: "",
      company_phone: "",
      company_cnpj: "",
      plan_id: ""
    });
    const errors = ref({});
    const loading = ref(false);
    const emailAvailable = ref(null);
    const emailChecking = ref(false);
    async function checkEmailAvailability() {
      var _a2;
      if (!form.value.email || !/\S+@\S+\.\S+/.test(form.value.email)) return;
      emailChecking.value = true;
      emailAvailable.value = null;
      try {
        const { data } = await axios.get("/register/check-email", { params: { email: form.value.email } });
        emailAvailable.value = data.available;
        if (!data.available) {
          errors.value.email = ((_a2 = props.tAuth.register) == null ? void 0 : _a2.email_taken) ?? "Este e-mail já está cadastrado.";
        } else {
          delete errors.value.email;
        }
      } catch {
        emailAvailable.value = null;
      } finally {
        emailChecking.value = false;
      }
    }
    const strengthColors = ["", "#ef4444", "#f97316", "#eab308", "#22c55e", "#16a34a"];
    const strengthLabels = computed(() => {
      var _a2, _b2, _c2, _d, _e;
      return [
        "",
        ((_a2 = props.tAuth.register) == null ? void 0 : _a2.strength_very_weak) ?? "Muito fraca",
        ((_b2 = props.tAuth.register) == null ? void 0 : _b2.strength_weak) ?? "Fraca",
        ((_c2 = props.tAuth.register) == null ? void 0 : _c2.strength_fair) ?? "Razoável",
        ((_d = props.tAuth.register) == null ? void 0 : _d.strength_strong) ?? "Forte",
        ((_e = props.tAuth.register) == null ? void 0 : _e.strength_very_strong) ?? "Muito forte"
      ];
    });
    const passwordStrength = computed(() => {
      const p = form.value.password;
      if (!p) return 0;
      let score = 0;
      if (p.length >= 8) score++;
      if (p.length >= 12) score++;
      if (/[A-Z]/.test(p)) score++;
      if (/[0-9]/.test(p)) score++;
      if (/[^A-Za-z0-9]/.test(p)) score++;
      return Math.min(score, 5);
    });
    const passwordStrengthColor = computed(() => strengthColors[passwordStrength.value]);
    const passwordStrengthLabel = computed(() => strengthLabels.value[passwordStrength.value]);
    const selectedPlan = ref(((_a = props.plans[0]) == null ? void 0 : _a.id) ?? "");
    watch(() => props.plans, (plans) => {
      if (plans.length && !selectedPlan.value) selectedPlan.value = plans[0].id;
    }, { immediate: true });
    const currentPlan = computed(() => props.plans.find((p) => p.id === selectedPlan.value) ?? null);
    function selectPlan(id) {
      selectedPlan.value = id;
      form.value.plan_id = id;
    }
    const required = ((_b = props.tAuth.register) == null ? void 0 : _b.field_required) ?? "Campo obrigatório.";
    const mismatch = ((_c = props.tAuth.register) == null ? void 0 : _c.passwords_mismatch) ?? "As senhas não conferem.";
    function validateStep1() {
      var _a2;
      const e = {};
      if (!form.value.name) e.name = required;
      if (!form.value.email) e.email = required;
      if (emailAvailable.value === false) e.email = (_a2 = props.tAuth.register) == null ? void 0 : _a2.email_taken;
      if (!form.value.password) e.password = required;
      if (form.value.password !== form.value.password_confirmation) e.password_confirmation = mismatch;
      errors.value = e;
      return Object.keys(e).length === 0;
    }
    function validateStep2() {
      const e = {};
      if (!form.value.company_name) e.company_name = required;
      errors.value = e;
      return Object.keys(e).length === 0;
    }
    function nextStep() {
      if (validateStep1()) step.value = 2;
    }
    function prevStep() {
      step.value = 1;
      errors.value = {};
    }
    async function submit() {
      var _a2, _b2;
      if (!validateStep2()) return;
      loading.value = true;
      try {
        const payload = { ...form.value, plan_id: selectedPlan.value };
        const { data } = await axios.post("/register", payload);
        if (data.redirect) {
          window.location.href = data.redirect;
        }
      } catch (err) {
        if ((_b2 = (_a2 = err.response) == null ? void 0 : _a2.data) == null ? void 0 : _b2.errors) {
          errors.value = Object.fromEntries(
            Object.entries(err.response.data.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v])
          );
          if (errors.value.name || errors.value.email || errors.value.password || errors.value.password_confirmation) {
            step.value = 1;
          }
        }
      } finally {
        loading.value = false;
      }
    }
    async function quickStart() {
      var _a2;
      if (!validateStep2()) return;
      selectPlan(((_a2 = props.plans[0]) == null ? void 0 : _a2.id) ?? "");
      await submit();
    }
    const showPwd1 = ref(false);
    const showPwd2 = ref(false);
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), null, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a2, _b2, _c2, _d, _e, _f, _g, _h;
          if (_push2) {
            _push2(`<title${_scopeId}>${ssrInterpolate(((_b2 = (_a2 = __props.tAuth.register) == null ? void 0 : _a2.meta_title) == null ? void 0 : _b2.replace(":app", __props.appName)) ?? "Criar conta")}</title><meta name="description"${ssrRenderAttr("content", ((_d = (_c2 = __props.tAuth.register) == null ? void 0 : _c2.meta_description) == null ? void 0 : _d.replace(":days", __props.trialDays)) ?? "")}${_scopeId}>`);
          } else {
            return [
              createVNode("title", null, toDisplayString(((_f = (_e = __props.tAuth.register) == null ? void 0 : _e.meta_title) == null ? void 0 : _f.replace(":app", __props.appName)) ?? "Criar conta"), 1),
              createVNode("meta", {
                name: "description",
                content: ((_h = (_g = __props.tAuth.register) == null ? void 0 : _g.meta_description) == null ? void 0 : _h.replace(":days", __props.trialDays)) ?? ""
              }, null, 8, ["content"])
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(ssrRenderComponent(_sfc_main$1, {
        t: __props.t,
        "app-name": __props.appName,
        "has-hero": false,
        routes: __props.routes
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a2, _b2, _c2, _d, _e, _f, _g, _h, _i, _j, _k, _l, _m, _n, _o, _p, _q, _r, _s, _t, _u, _v, _w, _x, _y, _z, _A, _B, _C, _D, _E, _F, _G, _H, _I, _J, _K, _L, _M, _N, _O, _P, _Q, _R, _S, _T, _U, _V, _W, _X, _Y, _Z, __, _$, _aa, _ba, _ca, _da, _ea, _fa, _ga, _ha, _ia, _ja, _ka, _la, _ma, _na, _oa, _pa, _qa, _ra, _sa, _ta, _ua, _va, _wa, _xa;
          if (_push2) {
            _push2(`<section class="reg-hero hero"${_scopeId}><div class="reg-hero-blob reg-hero-blob-1"${_scopeId}></div><div class="reg-hero-blob reg-hero-blob-2"${_scopeId}></div><div class="container"${_scopeId}><div class="reg-hero-inner"${_scopeId}><div class="reg-hero-badge"${_scopeId}><i class="ti ti-sparkles"${_scopeId}></i> ${ssrInterpolate(__props.trialDays)} ${ssrInterpolate((_a2 = __props.tAuth.register) == null ? void 0 : _a2.days_free)} • ${ssrInterpolate((_b2 = __props.tAuth.register) == null ? void 0 : _b2.no_card)} • ${ssrInterpolate((_c2 = __props.tAuth.register) == null ? void 0 : _c2.setup_fast)}</div><h1 class="reg-hero-title"${_scopeId}>${ssrInterpolate((_d = __props.tAuth.register) == null ? void 0 : _d.left_headline)}<br${_scopeId}><em${_scopeId}>${ssrInterpolate((_e = __props.tAuth.register) == null ? void 0 : _e.left_headline_em)}</em></h1><p class="reg-hero-sub"${_scopeId}>${ssrInterpolate((_f = __props.tAuth.register) == null ? void 0 : _f.left_sub)}</p><div class="reg-hero-metrics"${_scopeId}><div class="reg-hero-metric"${_scopeId}><span class="reg-hero-metric-val"${_scopeId}>500+</span><span class="reg-hero-metric-label"${_scopeId}>${ssrInterpolate((_g = __props.tAuth.register) == null ? void 0 : _g.metric_clinics)}</span></div><div class="reg-hero-metric"${_scopeId}><span class="reg-hero-metric-val"${_scopeId}>${ssrInterpolate(__props.trialDays)}</span><span class="reg-hero-metric-label"${_scopeId}>${ssrInterpolate((_h = __props.tAuth.register) == null ? void 0 : _h.days_free)}</span></div><div class="reg-hero-metric"${_scopeId}><span class="reg-hero-metric-val"${_scopeId}>97%</span><span class="reg-hero-metric-label"${_scopeId}>NPS</span></div></div></div></div></section><section class="reg-section"${_scopeId}><div class="container"${_scopeId}><div class="reg-layout"${_scopeId}><div class="reg-marketing"${_scopeId}><h2 class="reg-marketing-headline"${_scopeId}>${ssrInterpolate((_i = __props.tAuth.register) == null ? void 0 : _i.step1_title)}</h2><p class="reg-marketing-sub"${_scopeId}>${ssrInterpolate((_j = __props.tAuth.register) == null ? void 0 : _j.left_sub)}</p><ul class="reg-benefits"${_scopeId}><li class="reg-benefit"${_scopeId}><div class="reg-benefit-icon"${_scopeId}><i class="ti ti-gift"${_scopeId}></i></div><div class="reg-benefit-text"${_scopeId}><strong${_scopeId}>${ssrInterpolate((_l = (_k = __props.tAuth.register) == null ? void 0 : _k.benefit_trial_title) == null ? void 0 : _l.replace(":days", __props.trialDays))}</strong><span${_scopeId}>${ssrInterpolate((_m = __props.tAuth.register) == null ? void 0 : _m.benefit_trial_text)}</span></div></li><li class="reg-benefit"${_scopeId}><div class="reg-benefit-icon"${_scopeId}><i class="ti ti-bolt"${_scopeId}></i></div><div class="reg-benefit-text"${_scopeId}><strong${_scopeId}>${ssrInterpolate((_n = __props.tAuth.register) == null ? void 0 : _n.benefit_setup_title)}</strong><span${_scopeId}>${ssrInterpolate((_o = __props.tAuth.register) == null ? void 0 : _o.benefit_setup_text)}</span></div></li><li class="reg-benefit"${_scopeId}><div class="reg-benefit-icon"${_scopeId}><i class="ti ti-headset"${_scopeId}></i></div><div class="reg-benefit-text"${_scopeId}><strong${_scopeId}>${ssrInterpolate((_p = __props.tAuth.register) == null ? void 0 : _p.benefit_support_title)}</strong><span${_scopeId}>${ssrInterpolate((_q = __props.tAuth.register) == null ? void 0 : _q.benefit_support_text)}</span></div></li><li class="reg-benefit"${_scopeId}><div class="reg-benefit-icon"${_scopeId}><i class="ti ti-shield-check"${_scopeId}></i></div><div class="reg-benefit-text"${_scopeId}><strong${_scopeId}>${ssrInterpolate((_r = __props.tAuth.register) == null ? void 0 : _r.benefit_lgpd_title)}</strong><span${_scopeId}>${ssrInterpolate((_s = __props.tAuth.register) == null ? void 0 : _s.benefit_lgpd_text)}</span></div></li></ul><div class="reg-testimonial"${_scopeId}><div class="reg-testimonial-stars"${_scopeId}>★★★★★</div><p class="reg-testimonial-text"${_scopeId}>&quot;${ssrInterpolate((_t = __props.tAuth.register) == null ? void 0 : _t.testimonial_text)}&quot;</p><div class="reg-testimonial-author"${_scopeId}><div class="reg-testimonial-avatar"${_scopeId}>DR</div><div${_scopeId}><div class="reg-testimonial-name"${_scopeId}>${ssrInterpolate((_u = __props.tAuth.register) == null ? void 0 : _u.testimonial_name)}</div><div class="reg-testimonial-role"${_scopeId}>${ssrInterpolate((_v = __props.tAuth.register) == null ? void 0 : _v.testimonial_role)}</div></div></div></div><div class="reg-trust"${_scopeId}><div class="reg-trust-item"${_scopeId}><i class="ti ti-lock"${_scopeId}></i> <span${_scopeId}>SSL 256-bit</span></div><div class="reg-trust-item"${_scopeId}><i class="ti ti-shield-check"${_scopeId}></i> <span${_scopeId}>LGPD</span></div><div class="reg-trust-item"${_scopeId}><i class="ti ti-award"${_scopeId}></i> <span${_scopeId}>CFM</span></div><div class="reg-trust-item"${_scopeId}><i class="ti ti-mood-smile"${_scopeId}></i> <span${_scopeId}>97% NPS</span></div></div></div><div class="reg-card"${_scopeId}><div class="reg-card-header"${_scopeId}><div class="reg-card-title"${_scopeId}>`);
            if (step.value === 1) {
              _push2(`<span${_scopeId}>${ssrInterpolate((_w = __props.tAuth.register) == null ? void 0 : _w.step1_title)}</span>`);
            } else {
              _push2(`<span${_scopeId}>${ssrInterpolate((_x = __props.tAuth.register) == null ? void 0 : _x.step2_title)}</span>`);
            }
            _push2(`</div><div class="reg-card-subtitle"${_scopeId}>`);
            if (step.value === 1) {
              _push2(`<span${_scopeId}>${ssrInterpolate((_z = (_y = __props.tAuth.register) == null ? void 0 : _y.step1_subtitle) == null ? void 0 : _z.replace(":days", __props.trialDays))}</span>`);
            } else {
              _push2(`<span${_scopeId}>${ssrInterpolate((_A = __props.tAuth.register) == null ? void 0 : _A.step2_subtitle)}</span>`);
            }
            _push2(`</div></div><div class="step-indicator"${_scopeId}><div class="${ssrRenderClass([{ active: step.value === 1, done: step.value > 1 }, "step-item"])}"${_scopeId}><span class="step-num"${_scopeId}>`);
            if (step.value > 1) {
              _push2(`<i class="ti ti-check" style="${ssrRenderStyle({ "font-size": "13px" })}"${_scopeId}></i>`);
            } else {
              _push2(`<!--[-->1<!--]-->`);
            }
            _push2(`</span><span${_scopeId}>${ssrInterpolate((_B = __props.tAuth.register) == null ? void 0 : _B.step_personal)}</span></div><div class="step-sep"${_scopeId}></div><div class="${ssrRenderClass([{ active: step.value === 2 }, "step-item"])}"${_scopeId}><span class="step-num"${_scopeId}>2</span><span${_scopeId}>${ssrInterpolate((_C = __props.tAuth.register) == null ? void 0 : _C.step_company)}</span></div></div><div class="reg-progress"${_scopeId}><div class="reg-progress-fill" style="${ssrRenderStyle({ width: step.value === 1 ? "50%" : "100%" })}"${_scopeId}></div></div>`);
            if (step.value === 1) {
              _push2(`<div${_scopeId}><form novalidate${_scopeId}><div class="reg-field"${_scopeId}><label class="reg-label"${_scopeId}>${ssrInterpolate((_D = __props.tAuth.register) == null ? void 0 : _D.name)} <span class="req"${_scopeId}>*</span></label><input${ssrRenderAttr("value", form.value.name)} type="text" class="${ssrRenderClass([{ "is-error": errors.value.name }, "reg-input"])}" autocomplete="name" autofocus${_scopeId}>`);
              if (errors.value.name) {
                _push2(`<span class="reg-error"${_scopeId}>${ssrInterpolate(errors.value.name)}</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><div class="reg-field"${_scopeId}><label class="reg-label"${_scopeId}>${ssrInterpolate((_E = __props.tAuth.register) == null ? void 0 : _E.email)} <span class="req"${_scopeId}>*</span></label><div class="reg-input-group"${_scopeId}><input${ssrRenderAttr("value", form.value.email)} type="email" class="${ssrRenderClass([{
                "is-error": errors.value.email,
                "is-success": emailAvailable.value === true && !emailChecking.value
              }, "reg-input"])}" autocomplete="username"${_scopeId}><div class="reg-input-addon"${_scopeId}>`);
              if (emailChecking.value) {
                _push2(`<span class="ee-spin" style="${ssrRenderStyle({ "color": "#94a3b8", "display": "flex" })}"${_scopeId}><i class="ti ti-loader-2"${_scopeId}></i></span>`);
              } else if (emailAvailable.value === true) {
                _push2(`<i class="ti ti-circle-check" style="${ssrRenderStyle({ "color": "var(--mint)" })}"${_scopeId}></i>`);
              } else if (emailAvailable.value === false) {
                _push2(`<i class="ti ti-circle-x" style="${ssrRenderStyle({ "color": "#ef4444" })}"${_scopeId}></i>`);
              } else {
                _push2(`<i class="ti ti-mail" style="${ssrRenderStyle({ "color": "#94a3b8" })}"${_scopeId}></i>`);
              }
              _push2(`</div></div>`);
              if (errors.value.email) {
                _push2(`<span class="reg-error"${_scopeId}>${ssrInterpolate(errors.value.email)}</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><div class="reg-field"${_scopeId}><label class="reg-label"${_scopeId}>${ssrInterpolate((_F = __props.tAuth.register) == null ? void 0 : _F.password)} <span class="req"${_scopeId}>*</span></label><div class="reg-input-group" style="${ssrRenderStyle({ "position": "relative" })}"${_scopeId}><input${ssrRenderDynamicModel(showPwd1.value ? "text" : "password", form.value.password, null)}${ssrRenderAttr("type", showPwd1.value ? "text" : "password")} class="${ssrRenderClass([{ "is-error": errors.value.password }, "reg-input"])}" autocomplete="new-password"${_scopeId}><button type="button" class="reg-toggle-btn" tabindex="-1"${_scopeId}><i class="${ssrRenderClass(showPwd1.value ? "ti ti-eye-off" : "ti ti-eye")}"${_scopeId}></i></button></div>`);
              if (errors.value.password) {
                _push2(`<span class="reg-error"${_scopeId}>${ssrInterpolate(errors.value.password)}</span>`);
              } else {
                _push2(`<!---->`);
              }
              if (form.value.password) {
                _push2(`<div class="pwd-strength"${_scopeId}><div class="pwd-bars"${_scopeId}><!--[-->`);
                ssrRenderList(5, (i) => {
                  _push2(`<div class="pwd-bar" style="${ssrRenderStyle(i <= passwordStrength.value ? { background: passwordStrengthColor.value } : {})}"${_scopeId}></div>`);
                });
                _push2(`<!--]--></div><span class="pwd-strength-label" style="${ssrRenderStyle({ color: passwordStrengthColor.value })}"${_scopeId}>${ssrInterpolate(passwordStrengthLabel.value)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><div class="reg-field"${_scopeId}><label class="reg-label"${_scopeId}>${ssrInterpolate((_G = __props.tAuth.register) == null ? void 0 : _G.confirm_password)} <span class="req"${_scopeId}>*</span></label><div class="reg-input-group"${_scopeId}><input${ssrRenderDynamicModel(showPwd2.value ? "text" : "password", form.value.password_confirmation, null)}${ssrRenderAttr("type", showPwd2.value ? "text" : "password")} class="${ssrRenderClass([{ "is-error": errors.value.password_confirmation }, "reg-input"])}" autocomplete="new-password"${_scopeId}><button type="button" class="reg-toggle-btn" tabindex="-1"${_scopeId}><i class="${ssrRenderClass(showPwd2.value ? "ti ti-eye-off" : "ti ti-eye")}"${_scopeId}></i></button></div>`);
              if (errors.value.password_confirmation) {
                _push2(`<span class="reg-error"${_scopeId}>${ssrInterpolate(errors.value.password_confirmation)}</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><button type="submit" class="reg-btn reg-btn-primary" style="${ssrRenderStyle({ "margin-top": "8px" })}"${_scopeId}>${ssrInterpolate((_H = __props.tAuth.register) == null ? void 0 : _H.next)} <i class="ti ti-arrow-right"${_scopeId}></i></button></form><div class="reg-card-footer"${_scopeId}>${ssrInterpolate((_I = __props.tAuth.register) == null ? void 0 : _I.already_registered)} <a${ssrRenderAttr("href", __props.routes.go ?? "/go")}${_scopeId}>${ssrInterpolate((_J = __props.tAuth.register) == null ? void 0 : _J.log_in)}</a></div></div>`);
            } else {
              _push2(`<div${_scopeId}><form novalidate${_scopeId}><div class="reg-field"${_scopeId}><label class="reg-label"${_scopeId}>${ssrInterpolate((_K = __props.tAuth.register) == null ? void 0 : _K.company_name)} <span class="req"${_scopeId}>*</span></label><input${ssrRenderAttr("value", form.value.company_name)} type="text" class="${ssrRenderClass([{ "is-error": errors.value.company_name }, "reg-input"])}" autocomplete="organization"${_scopeId}>`);
              if (errors.value.company_name) {
                _push2(`<span class="reg-error"${_scopeId}>${ssrInterpolate(errors.value.company_name)}</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><div class="reg-field"${_scopeId}><label class="reg-label"${_scopeId}>${ssrInterpolate((_L = __props.tAuth.register) == null ? void 0 : _L.phone)} <span class="opt"${_scopeId}>(${ssrInterpolate((_M = __props.tAuth.register) == null ? void 0 : _M.optional)})</span></label><input${ssrRenderAttr("value", form.value.company_phone)} type="tel" class="reg-input" autocomplete="tel" placeholder="(00) 00000-0000"${_scopeId}></div><div class="reg-field"${_scopeId}><label class="reg-label"${_scopeId}>${ssrInterpolate((_N = __props.tAuth.register) == null ? void 0 : _N.cnpj)} <span class="opt"${_scopeId}>(${ssrInterpolate((_O = __props.tAuth.register) == null ? void 0 : _O.optional)})</span></label><input${ssrRenderAttr("value", form.value.company_cnpj)} type="text" class="${ssrRenderClass([{ "is-error": errors.value.company_cnpj }, "reg-input"])}" maxlength="18" placeholder="00.000.000/0000-00"${_scopeId}>`);
              if (errors.value.company_cnpj) {
                _push2(`<span class="reg-error"${_scopeId}>${ssrInterpolate(errors.value.company_cnpj)}</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div>`);
              if (__props.plans.length) {
                _push2(`<div class="reg-field"${_scopeId}><label class="reg-label"${_scopeId}>${ssrInterpolate((_P = __props.tAuth.register) == null ? void 0 : _P.choose_plan)}</label><div class="plan-grid"${_scopeId}><!--[-->`);
                ssrRenderList(__props.plans, (plan) => {
                  var _a3;
                  _push2(`<div class="${ssrRenderClass([{ selected: selectedPlan.value === plan.id }, "plan-grid-card"])}"${_scopeId}><div class="plan-grid-badge"${_scopeId}>${ssrInterpolate(__props.trialDays)} ${ssrInterpolate((_a3 = __props.tAuth.register) == null ? void 0 : _a3.days_free)}</div><div class="plan-grid-name"${_scopeId}>${ssrInterpolate(plan.name)}</div><div class="plan-grid-price"${_scopeId}> R$ ${ssrInterpolate(plan.is_free ? "0,00" : Number(plan.price).toLocaleString("pt-BR", { minimumFractionDigits: 2 }))}</div><div class="plan-grid-cycle"${_scopeId}>/ ${ssrInterpolate(plan.price_period_label)}</div></div>`);
                });
                _push2(`<!--]--></div>`);
                if (currentPlan.value && ((_Q = currentPlan.value.features) == null ? void 0 : _Q.length)) {
                  _push2(`<div class="plan-detail"${_scopeId}><ul class="plan-features"${_scopeId}><!--[-->`);
                  ssrRenderList(currentPlan.value.features, (feat) => {
                    _push2(`<li${_scopeId}>`);
                    if (feat.enabled !== void 0) {
                      _push2(`<span style="${ssrRenderStyle(feat.enabled ? "color:var(--mint)" : "color:#ef4444")}"${_scopeId}>${ssrInterpolate(feat.enabled ? "✓" : "✗")}</span>`);
                    } else {
                      _push2(`<!---->`);
                    }
                    _push2(` ${ssrInterpolate(" " + feat.display_label)}</li>`);
                  });
                  _push2(`<!--]--></ul></div>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<div class="reg-btn-row"${_scopeId}><button type="button" class="reg-btn reg-btn-secondary"${_scopeId}><i class="ti ti-arrow-left"${_scopeId}></i> ${ssrInterpolate((_R = __props.tAuth.register) == null ? void 0 : _R.back)}</button><button type="submit" class="reg-btn reg-btn-primary"${ssrIncludeBooleanAttr(loading.value) ? " disabled" : ""}${_scopeId}>`);
              if (!loading.value) {
                _push2(`<span${_scopeId}>${ssrInterpolate((_S = __props.tAuth.register) == null ? void 0 : _S.start_trial)} <i class="ti ti-rocket"${_scopeId}></i></span>`);
              } else {
                _push2(`<span${_scopeId}><i class="ti ti-loader-2 ee-spin me-1"${_scopeId}></i> ${ssrInterpolate((_T = __props.tAuth.register) == null ? void 0 : _T.processing)}</span>`);
              }
              _push2(`</button></div>`);
              if (__props.plans.length > 1) {
                _push2(`<!--[--><div class="reg-divider"${_scopeId}><span${_scopeId}>${ssrInterpolate((_U = __props.tAuth.register) == null ? void 0 : _U.or)}</span></div><button type="button" class="reg-quick-start"${ssrIncludeBooleanAttr(loading.value) ? " disabled" : ""}${_scopeId}>${ssrInterpolate((_V = __props.tAuth.register) == null ? void 0 : _V.quick_start)} ${ssrInterpolate((_W = __props.plans[0]) == null ? void 0 : _W.name)} → </button><!--]-->`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</form></div>`);
            }
            _push2(`<div class="reg-card-trust"${_scopeId}><div class="reg-card-trust-item"${_scopeId}><i class="ti ti-lock"${_scopeId}></i> <span${_scopeId}>SSL</span></div><div class="reg-card-trust-item"${_scopeId}><i class="ti ti-shield-check"${_scopeId}></i> <span${_scopeId}>LGPD</span></div><div class="reg-card-trust-item"${_scopeId}><i class="ti ti-award"${_scopeId}></i> <span${_scopeId}>CFM</span></div><div class="reg-card-trust-item"${_scopeId}><i class="ti ti-mood-smile"${_scopeId}></i> <span${_scopeId}>97% NPS</span></div></div></div></div></div></section>`);
          } else {
            return [
              createVNode("section", { class: "reg-hero hero" }, [
                createVNode("div", { class: "reg-hero-blob reg-hero-blob-1" }),
                createVNode("div", { class: "reg-hero-blob reg-hero-blob-2" }),
                createVNode("div", { class: "container" }, [
                  createVNode("div", { class: "reg-hero-inner" }, [
                    createVNode("div", { class: "reg-hero-badge" }, [
                      createVNode("i", { class: "ti ti-sparkles" }),
                      createTextVNode(" " + toDisplayString(__props.trialDays) + " " + toDisplayString((_X = __props.tAuth.register) == null ? void 0 : _X.days_free) + " • " + toDisplayString((_Y = __props.tAuth.register) == null ? void 0 : _Y.no_card) + " • " + toDisplayString((_Z = __props.tAuth.register) == null ? void 0 : _Z.setup_fast), 1)
                    ]),
                    createVNode("h1", { class: "reg-hero-title" }, [
                      createTextVNode(toDisplayString((__ = __props.tAuth.register) == null ? void 0 : __.left_headline), 1),
                      createVNode("br"),
                      createVNode("em", null, toDisplayString((_$ = __props.tAuth.register) == null ? void 0 : _$.left_headline_em), 1)
                    ]),
                    createVNode("p", { class: "reg-hero-sub" }, toDisplayString((_aa = __props.tAuth.register) == null ? void 0 : _aa.left_sub), 1),
                    createVNode("div", { class: "reg-hero-metrics" }, [
                      createVNode("div", { class: "reg-hero-metric" }, [
                        createVNode("span", { class: "reg-hero-metric-val" }, "500+"),
                        createVNode("span", { class: "reg-hero-metric-label" }, toDisplayString((_ba = __props.tAuth.register) == null ? void 0 : _ba.metric_clinics), 1)
                      ]),
                      createVNode("div", { class: "reg-hero-metric" }, [
                        createVNode("span", { class: "reg-hero-metric-val" }, toDisplayString(__props.trialDays), 1),
                        createVNode("span", { class: "reg-hero-metric-label" }, toDisplayString((_ca = __props.tAuth.register) == null ? void 0 : _ca.days_free), 1)
                      ]),
                      createVNode("div", { class: "reg-hero-metric" }, [
                        createVNode("span", { class: "reg-hero-metric-val" }, "97%"),
                        createVNode("span", { class: "reg-hero-metric-label" }, "NPS")
                      ])
                    ])
                  ])
                ])
              ]),
              createVNode("section", { class: "reg-section" }, [
                createVNode("div", { class: "container" }, [
                  createVNode("div", { class: "reg-layout" }, [
                    createVNode("div", { class: "reg-marketing" }, [
                      createVNode("h2", { class: "reg-marketing-headline" }, toDisplayString((_da = __props.tAuth.register) == null ? void 0 : _da.step1_title), 1),
                      createVNode("p", { class: "reg-marketing-sub" }, toDisplayString((_ea = __props.tAuth.register) == null ? void 0 : _ea.left_sub), 1),
                      createVNode("ul", { class: "reg-benefits" }, [
                        createVNode("li", { class: "reg-benefit" }, [
                          createVNode("div", { class: "reg-benefit-icon" }, [
                            createVNode("i", { class: "ti ti-gift" })
                          ]),
                          createVNode("div", { class: "reg-benefit-text" }, [
                            createVNode("strong", null, toDisplayString((_ga = (_fa = __props.tAuth.register) == null ? void 0 : _fa.benefit_trial_title) == null ? void 0 : _ga.replace(":days", __props.trialDays)), 1),
                            createVNode("span", null, toDisplayString((_ha = __props.tAuth.register) == null ? void 0 : _ha.benefit_trial_text), 1)
                          ])
                        ]),
                        createVNode("li", { class: "reg-benefit" }, [
                          createVNode("div", { class: "reg-benefit-icon" }, [
                            createVNode("i", { class: "ti ti-bolt" })
                          ]),
                          createVNode("div", { class: "reg-benefit-text" }, [
                            createVNode("strong", null, toDisplayString((_ia = __props.tAuth.register) == null ? void 0 : _ia.benefit_setup_title), 1),
                            createVNode("span", null, toDisplayString((_ja = __props.tAuth.register) == null ? void 0 : _ja.benefit_setup_text), 1)
                          ])
                        ]),
                        createVNode("li", { class: "reg-benefit" }, [
                          createVNode("div", { class: "reg-benefit-icon" }, [
                            createVNode("i", { class: "ti ti-headset" })
                          ]),
                          createVNode("div", { class: "reg-benefit-text" }, [
                            createVNode("strong", null, toDisplayString((_ka = __props.tAuth.register) == null ? void 0 : _ka.benefit_support_title), 1),
                            createVNode("span", null, toDisplayString((_la = __props.tAuth.register) == null ? void 0 : _la.benefit_support_text), 1)
                          ])
                        ]),
                        createVNode("li", { class: "reg-benefit" }, [
                          createVNode("div", { class: "reg-benefit-icon" }, [
                            createVNode("i", { class: "ti ti-shield-check" })
                          ]),
                          createVNode("div", { class: "reg-benefit-text" }, [
                            createVNode("strong", null, toDisplayString((_ma = __props.tAuth.register) == null ? void 0 : _ma.benefit_lgpd_title), 1),
                            createVNode("span", null, toDisplayString((_na = __props.tAuth.register) == null ? void 0 : _na.benefit_lgpd_text), 1)
                          ])
                        ])
                      ]),
                      createVNode("div", { class: "reg-testimonial" }, [
                        createVNode("div", { class: "reg-testimonial-stars" }, "★★★★★"),
                        createVNode("p", { class: "reg-testimonial-text" }, '"' + toDisplayString((_oa = __props.tAuth.register) == null ? void 0 : _oa.testimonial_text) + '"', 1),
                        createVNode("div", { class: "reg-testimonial-author" }, [
                          createVNode("div", { class: "reg-testimonial-avatar" }, "DR"),
                          createVNode("div", null, [
                            createVNode("div", { class: "reg-testimonial-name" }, toDisplayString((_pa = __props.tAuth.register) == null ? void 0 : _pa.testimonial_name), 1),
                            createVNode("div", { class: "reg-testimonial-role" }, toDisplayString((_qa = __props.tAuth.register) == null ? void 0 : _qa.testimonial_role), 1)
                          ])
                        ])
                      ]),
                      createVNode("div", { class: "reg-trust" }, [
                        createVNode("div", { class: "reg-trust-item" }, [
                          createVNode("i", { class: "ti ti-lock" }),
                          createTextVNode(),
                          createVNode("span", null, "SSL 256-bit")
                        ]),
                        createVNode("div", { class: "reg-trust-item" }, [
                          createVNode("i", { class: "ti ti-shield-check" }),
                          createTextVNode(),
                          createVNode("span", null, "LGPD")
                        ]),
                        createVNode("div", { class: "reg-trust-item" }, [
                          createVNode("i", { class: "ti ti-award" }),
                          createTextVNode(),
                          createVNode("span", null, "CFM")
                        ]),
                        createVNode("div", { class: "reg-trust-item" }, [
                          createVNode("i", { class: "ti ti-mood-smile" }),
                          createTextVNode(),
                          createVNode("span", null, "97% NPS")
                        ])
                      ])
                    ]),
                    createVNode("div", { class: "reg-card" }, [
                      createVNode("div", { class: "reg-card-header" }, [
                        createVNode("div", { class: "reg-card-title" }, [
                          step.value === 1 ? (openBlock(), createBlock("span", { key: 0 }, toDisplayString((_ra = __props.tAuth.register) == null ? void 0 : _ra.step1_title), 1)) : (openBlock(), createBlock("span", { key: 1 }, toDisplayString((_sa = __props.tAuth.register) == null ? void 0 : _sa.step2_title), 1))
                        ]),
                        createVNode("div", { class: "reg-card-subtitle" }, [
                          step.value === 1 ? (openBlock(), createBlock("span", { key: 0 }, toDisplayString((_ua = (_ta = __props.tAuth.register) == null ? void 0 : _ta.step1_subtitle) == null ? void 0 : _ua.replace(":days", __props.trialDays)), 1)) : (openBlock(), createBlock("span", { key: 1 }, toDisplayString((_va = __props.tAuth.register) == null ? void 0 : _va.step2_subtitle), 1))
                        ])
                      ]),
                      createVNode("div", { class: "step-indicator" }, [
                        createVNode("div", {
                          class: ["step-item", { active: step.value === 1, done: step.value > 1 }]
                        }, [
                          createVNode("span", { class: "step-num" }, [
                            step.value > 1 ? (openBlock(), createBlock("i", {
                              key: 0,
                              class: "ti ti-check",
                              style: { "font-size": "13px" }
                            })) : (openBlock(), createBlock(Fragment, { key: 1 }, [
                              createTextVNode("1")
                            ], 64))
                          ]),
                          createVNode("span", null, toDisplayString((_wa = __props.tAuth.register) == null ? void 0 : _wa.step_personal), 1)
                        ], 2),
                        createVNode("div", { class: "step-sep" }),
                        createVNode("div", {
                          class: ["step-item", { active: step.value === 2 }]
                        }, [
                          createVNode("span", { class: "step-num" }, "2"),
                          createVNode("span", null, toDisplayString((_xa = __props.tAuth.register) == null ? void 0 : _xa.step_company), 1)
                        ], 2)
                      ]),
                      createVNode("div", { class: "reg-progress" }, [
                        createVNode("div", {
                          class: "reg-progress-fill",
                          style: { width: step.value === 1 ? "50%" : "100%" }
                        }, null, 4)
                      ]),
                      createVNode(Transition, {
                        name: "reg-fade",
                        mode: "out-in"
                      }, {
                        default: withCtx(() => {
                          var _a3, _b3, _c3, _d2, _e2, _f2, _g2, _h2, _i2, _j2, _k2, _l2, _m2, _n2, _o2, _p2, _q2, _r2, _s2, _t2;
                          return [
                            step.value === 1 ? (openBlock(), createBlock("div", { key: "step1" }, [
                              createVNode("form", {
                                onSubmit: withModifiers(nextStep, ["prevent"]),
                                novalidate: ""
                              }, [
                                createVNode("div", { class: "reg-field" }, [
                                  createVNode("label", { class: "reg-label" }, [
                                    createTextVNode(toDisplayString((_a3 = __props.tAuth.register) == null ? void 0 : _a3.name) + " ", 1),
                                    createVNode("span", { class: "req" }, "*")
                                  ]),
                                  withDirectives(createVNode("input", {
                                    "onUpdate:modelValue": ($event) => form.value.name = $event,
                                    type: "text",
                                    class: ["reg-input", { "is-error": errors.value.name }],
                                    autocomplete: "name",
                                    autofocus: ""
                                  }, null, 10, ["onUpdate:modelValue"]), [
                                    [vModelText, form.value.name]
                                  ]),
                                  errors.value.name ? (openBlock(), createBlock("span", {
                                    key: 0,
                                    class: "reg-error"
                                  }, toDisplayString(errors.value.name), 1)) : createCommentVNode("", true)
                                ]),
                                createVNode("div", { class: "reg-field" }, [
                                  createVNode("label", { class: "reg-label" }, [
                                    createTextVNode(toDisplayString((_b3 = __props.tAuth.register) == null ? void 0 : _b3.email) + " ", 1),
                                    createVNode("span", { class: "req" }, "*")
                                  ]),
                                  createVNode("div", { class: "reg-input-group" }, [
                                    withDirectives(createVNode("input", {
                                      "onUpdate:modelValue": ($event) => form.value.email = $event,
                                      type: "email",
                                      class: ["reg-input", {
                                        "is-error": errors.value.email,
                                        "is-success": emailAvailable.value === true && !emailChecking.value
                                      }],
                                      autocomplete: "username",
                                      onBlur: checkEmailAvailability
                                    }, null, 42, ["onUpdate:modelValue"]), [
                                      [vModelText, form.value.email]
                                    ]),
                                    createVNode("div", { class: "reg-input-addon" }, [
                                      emailChecking.value ? (openBlock(), createBlock("span", {
                                        key: 0,
                                        class: "ee-spin",
                                        style: { "color": "#94a3b8", "display": "flex" }
                                      }, [
                                        createVNode("i", { class: "ti ti-loader-2" })
                                      ])) : emailAvailable.value === true ? (openBlock(), createBlock("i", {
                                        key: 1,
                                        class: "ti ti-circle-check",
                                        style: { "color": "var(--mint)" }
                                      })) : emailAvailable.value === false ? (openBlock(), createBlock("i", {
                                        key: 2,
                                        class: "ti ti-circle-x",
                                        style: { "color": "#ef4444" }
                                      })) : (openBlock(), createBlock("i", {
                                        key: 3,
                                        class: "ti ti-mail",
                                        style: { "color": "#94a3b8" }
                                      }))
                                    ])
                                  ]),
                                  errors.value.email ? (openBlock(), createBlock("span", {
                                    key: 0,
                                    class: "reg-error"
                                  }, toDisplayString(errors.value.email), 1)) : createCommentVNode("", true)
                                ]),
                                createVNode("div", { class: "reg-field" }, [
                                  createVNode("label", { class: "reg-label" }, [
                                    createTextVNode(toDisplayString((_c3 = __props.tAuth.register) == null ? void 0 : _c3.password) + " ", 1),
                                    createVNode("span", { class: "req" }, "*")
                                  ]),
                                  createVNode("div", {
                                    class: "reg-input-group",
                                    style: { "position": "relative" }
                                  }, [
                                    withDirectives(createVNode("input", {
                                      "onUpdate:modelValue": ($event) => form.value.password = $event,
                                      type: showPwd1.value ? "text" : "password",
                                      class: ["reg-input", { "is-error": errors.value.password }],
                                      autocomplete: "new-password"
                                    }, null, 10, ["onUpdate:modelValue", "type"]), [
                                      [vModelDynamic, form.value.password]
                                    ]),
                                    createVNode("button", {
                                      type: "button",
                                      class: "reg-toggle-btn",
                                      tabindex: "-1",
                                      onClick: ($event) => showPwd1.value = !showPwd1.value
                                    }, [
                                      createVNode("i", {
                                        class: showPwd1.value ? "ti ti-eye-off" : "ti ti-eye"
                                      }, null, 2)
                                    ], 8, ["onClick"])
                                  ]),
                                  errors.value.password ? (openBlock(), createBlock("span", {
                                    key: 0,
                                    class: "reg-error"
                                  }, toDisplayString(errors.value.password), 1)) : createCommentVNode("", true),
                                  form.value.password ? (openBlock(), createBlock("div", {
                                    key: 1,
                                    class: "pwd-strength"
                                  }, [
                                    createVNode("div", { class: "pwd-bars" }, [
                                      (openBlock(), createBlock(Fragment, null, renderList(5, (i) => {
                                        return createVNode("div", {
                                          key: i,
                                          class: "pwd-bar",
                                          style: i <= passwordStrength.value ? { background: passwordStrengthColor.value } : {}
                                        }, null, 4);
                                      }), 64))
                                    ]),
                                    createVNode("span", {
                                      class: "pwd-strength-label",
                                      style: { color: passwordStrengthColor.value }
                                    }, toDisplayString(passwordStrengthLabel.value), 5)
                                  ])) : createCommentVNode("", true)
                                ]),
                                createVNode("div", { class: "reg-field" }, [
                                  createVNode("label", { class: "reg-label" }, [
                                    createTextVNode(toDisplayString((_d2 = __props.tAuth.register) == null ? void 0 : _d2.confirm_password) + " ", 1),
                                    createVNode("span", { class: "req" }, "*")
                                  ]),
                                  createVNode("div", { class: "reg-input-group" }, [
                                    withDirectives(createVNode("input", {
                                      "onUpdate:modelValue": ($event) => form.value.password_confirmation = $event,
                                      type: showPwd2.value ? "text" : "password",
                                      class: ["reg-input", { "is-error": errors.value.password_confirmation }],
                                      autocomplete: "new-password"
                                    }, null, 10, ["onUpdate:modelValue", "type"]), [
                                      [vModelDynamic, form.value.password_confirmation]
                                    ]),
                                    createVNode("button", {
                                      type: "button",
                                      class: "reg-toggle-btn",
                                      tabindex: "-1",
                                      onClick: ($event) => showPwd2.value = !showPwd2.value
                                    }, [
                                      createVNode("i", {
                                        class: showPwd2.value ? "ti ti-eye-off" : "ti ti-eye"
                                      }, null, 2)
                                    ], 8, ["onClick"])
                                  ]),
                                  errors.value.password_confirmation ? (openBlock(), createBlock("span", {
                                    key: 0,
                                    class: "reg-error"
                                  }, toDisplayString(errors.value.password_confirmation), 1)) : createCommentVNode("", true)
                                ]),
                                createVNode("button", {
                                  type: "submit",
                                  class: "reg-btn reg-btn-primary",
                                  style: { "margin-top": "8px" }
                                }, [
                                  createTextVNode(toDisplayString((_e2 = __props.tAuth.register) == null ? void 0 : _e2.next) + " ", 1),
                                  createVNode("i", { class: "ti ti-arrow-right" })
                                ])
                              ], 32),
                              createVNode("div", { class: "reg-card-footer" }, [
                                createTextVNode(toDisplayString((_f2 = __props.tAuth.register) == null ? void 0 : _f2.already_registered) + " ", 1),
                                createVNode("a", {
                                  href: __props.routes.go ?? "/go"
                                }, toDisplayString((_g2 = __props.tAuth.register) == null ? void 0 : _g2.log_in), 9, ["href"])
                              ])
                            ])) : (openBlock(), createBlock("div", { key: "step2" }, [
                              createVNode("form", {
                                onSubmit: withModifiers(submit, ["prevent"]),
                                novalidate: ""
                              }, [
                                createVNode("div", { class: "reg-field" }, [
                                  createVNode("label", { class: "reg-label" }, [
                                    createTextVNode(toDisplayString((_h2 = __props.tAuth.register) == null ? void 0 : _h2.company_name) + " ", 1),
                                    createVNode("span", { class: "req" }, "*")
                                  ]),
                                  withDirectives(createVNode("input", {
                                    "onUpdate:modelValue": ($event) => form.value.company_name = $event,
                                    type: "text",
                                    class: ["reg-input", { "is-error": errors.value.company_name }],
                                    autocomplete: "organization"
                                  }, null, 10, ["onUpdate:modelValue"]), [
                                    [vModelText, form.value.company_name]
                                  ]),
                                  errors.value.company_name ? (openBlock(), createBlock("span", {
                                    key: 0,
                                    class: "reg-error"
                                  }, toDisplayString(errors.value.company_name), 1)) : createCommentVNode("", true)
                                ]),
                                createVNode("div", { class: "reg-field" }, [
                                  createVNode("label", { class: "reg-label" }, [
                                    createTextVNode(toDisplayString((_i2 = __props.tAuth.register) == null ? void 0 : _i2.phone) + " ", 1),
                                    createVNode("span", { class: "opt" }, "(" + toDisplayString((_j2 = __props.tAuth.register) == null ? void 0 : _j2.optional) + ")", 1)
                                  ]),
                                  withDirectives(createVNode("input", {
                                    "onUpdate:modelValue": ($event) => form.value.company_phone = $event,
                                    type: "tel",
                                    class: "reg-input",
                                    autocomplete: "tel",
                                    placeholder: "(00) 00000-0000"
                                  }, null, 8, ["onUpdate:modelValue"]), [
                                    [vModelText, form.value.company_phone]
                                  ])
                                ]),
                                createVNode("div", { class: "reg-field" }, [
                                  createVNode("label", { class: "reg-label" }, [
                                    createTextVNode(toDisplayString((_k2 = __props.tAuth.register) == null ? void 0 : _k2.cnpj) + " ", 1),
                                    createVNode("span", { class: "opt" }, "(" + toDisplayString((_l2 = __props.tAuth.register) == null ? void 0 : _l2.optional) + ")", 1)
                                  ]),
                                  withDirectives(createVNode("input", {
                                    "onUpdate:modelValue": ($event) => form.value.company_cnpj = $event,
                                    type: "text",
                                    class: ["reg-input", { "is-error": errors.value.company_cnpj }],
                                    maxlength: "18",
                                    placeholder: "00.000.000/0000-00"
                                  }, null, 10, ["onUpdate:modelValue"]), [
                                    [vModelText, form.value.company_cnpj]
                                  ]),
                                  errors.value.company_cnpj ? (openBlock(), createBlock("span", {
                                    key: 0,
                                    class: "reg-error"
                                  }, toDisplayString(errors.value.company_cnpj), 1)) : createCommentVNode("", true)
                                ]),
                                __props.plans.length ? (openBlock(), createBlock("div", {
                                  key: 0,
                                  class: "reg-field"
                                }, [
                                  createVNode("label", { class: "reg-label" }, toDisplayString((_m2 = __props.tAuth.register) == null ? void 0 : _m2.choose_plan), 1),
                                  createVNode("div", { class: "plan-grid" }, [
                                    (openBlock(true), createBlock(Fragment, null, renderList(__props.plans, (plan) => {
                                      var _a4;
                                      return openBlock(), createBlock("div", {
                                        key: plan.id,
                                        class: ["plan-grid-card", { selected: selectedPlan.value === plan.id }],
                                        onClick: ($event) => selectPlan(plan.id)
                                      }, [
                                        createVNode("div", { class: "plan-grid-badge" }, toDisplayString(__props.trialDays) + " " + toDisplayString((_a4 = __props.tAuth.register) == null ? void 0 : _a4.days_free), 1),
                                        createVNode("div", { class: "plan-grid-name" }, toDisplayString(plan.name), 1),
                                        createVNode("div", { class: "plan-grid-price" }, " R$ " + toDisplayString(plan.is_free ? "0,00" : Number(plan.price).toLocaleString("pt-BR", { minimumFractionDigits: 2 })), 1),
                                        createVNode("div", { class: "plan-grid-cycle" }, "/ " + toDisplayString(plan.price_period_label), 1)
                                      ], 10, ["onClick"]);
                                    }), 128))
                                  ]),
                                  currentPlan.value && ((_n2 = currentPlan.value.features) == null ? void 0 : _n2.length) ? (openBlock(), createBlock("div", {
                                    key: 0,
                                    class: "plan-detail"
                                  }, [
                                    createVNode("ul", { class: "plan-features" }, [
                                      (openBlock(true), createBlock(Fragment, null, renderList(currentPlan.value.features, (feat) => {
                                        return openBlock(), createBlock("li", {
                                          key: feat.id
                                        }, [
                                          feat.enabled !== void 0 ? (openBlock(), createBlock("span", {
                                            key: 0,
                                            style: feat.enabled ? "color:var(--mint)" : "color:#ef4444"
                                          }, toDisplayString(feat.enabled ? "✓" : "✗"), 5)) : createCommentVNode("", true),
                                          createTextVNode(" " + toDisplayString(" " + feat.display_label), 1)
                                        ]);
                                      }), 128))
                                    ])
                                  ])) : createCommentVNode("", true)
                                ])) : createCommentVNode("", true),
                                createVNode("div", { class: "reg-btn-row" }, [
                                  createVNode("button", {
                                    type: "button",
                                    class: "reg-btn reg-btn-secondary",
                                    onClick: prevStep
                                  }, [
                                    createVNode("i", { class: "ti ti-arrow-left" }),
                                    createTextVNode(" " + toDisplayString((_o2 = __props.tAuth.register) == null ? void 0 : _o2.back), 1)
                                  ]),
                                  createVNode("button", {
                                    type: "submit",
                                    class: "reg-btn reg-btn-primary",
                                    disabled: loading.value
                                  }, [
                                    !loading.value ? (openBlock(), createBlock("span", { key: 0 }, [
                                      createTextVNode(toDisplayString((_p2 = __props.tAuth.register) == null ? void 0 : _p2.start_trial) + " ", 1),
                                      createVNode("i", { class: "ti ti-rocket" })
                                    ])) : (openBlock(), createBlock("span", { key: 1 }, [
                                      createVNode("i", { class: "ti ti-loader-2 ee-spin me-1" }),
                                      createTextVNode(" " + toDisplayString((_q2 = __props.tAuth.register) == null ? void 0 : _q2.processing), 1)
                                    ]))
                                  ], 8, ["disabled"])
                                ]),
                                __props.plans.length > 1 ? (openBlock(), createBlock(Fragment, { key: 1 }, [
                                  createVNode("div", { class: "reg-divider" }, [
                                    createVNode("span", null, toDisplayString((_r2 = __props.tAuth.register) == null ? void 0 : _r2.or), 1)
                                  ]),
                                  createVNode("button", {
                                    type: "button",
                                    class: "reg-quick-start",
                                    disabled: loading.value,
                                    onClick: quickStart
                                  }, toDisplayString((_s2 = __props.tAuth.register) == null ? void 0 : _s2.quick_start) + " " + toDisplayString((_t2 = __props.plans[0]) == null ? void 0 : _t2.name) + " → ", 9, ["disabled"])
                                ], 64)) : createCommentVNode("", true)
                              ], 32)
                            ]))
                          ];
                        }),
                        _: 2
                      }, 1024),
                      createVNode("div", { class: "reg-card-trust" }, [
                        createVNode("div", { class: "reg-card-trust-item" }, [
                          createVNode("i", { class: "ti ti-lock" }),
                          createTextVNode(),
                          createVNode("span", null, "SSL")
                        ]),
                        createVNode("div", { class: "reg-card-trust-item" }, [
                          createVNode("i", { class: "ti ti-shield-check" }),
                          createTextVNode(),
                          createVNode("span", null, "LGPD")
                        ]),
                        createVNode("div", { class: "reg-card-trust-item" }, [
                          createVNode("i", { class: "ti ti-award" }),
                          createTextVNode(),
                          createVNode("span", null, "CFM")
                        ]),
                        createVNode("div", { class: "reg-card-trust-item" }, [
                          createVNode("i", { class: "ti ti-mood-smile" }),
                          createTextVNode(),
                          createVNode("span", null, "97% NPS")
                        ])
                      ])
                    ])
                  ])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Auth/Register.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
