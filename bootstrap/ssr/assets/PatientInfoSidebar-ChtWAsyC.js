import { computed, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderStyle, ssrInterpolate, ssrRenderAttr } from "vue/server-renderer";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "PatientInfoSidebar",
  __ssrInlineRender: true,
  props: {
    patient: { type: Object, required: true }
  },
  setup(__props) {
    const props = __props;
    const initials = computed(() => {
      const name = props.patient.full_name ?? "";
      const parts = name.trim().split(/\s+/).filter(Boolean);
      if (parts.length === 0) return "?";
      if (parts.length === 1) return parts[0][0].toUpperCase();
      return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    });
    const avatarColor = computed(() => {
      const palette = ["#3b82f6", "#8b5cf6", "#06b6d4", "#10b981", "#f59e0b", "#ef4444", "#ec4899", "#6366f1"];
      const name = props.patient.full_name ?? "";
      if (!name) return palette[0];
      let h = 0;
      for (const c of name) h = c.charCodeAt(0) + ((h << 5) - h);
      return palette[Math.abs(h) % palette.length];
    });
    const genderLabel = computed(() => {
      const g = props.patient.gender;
      if (g == null || g === "") return "—";
      const s = String(g).trim().toLowerCase();
      if (s === "1" || s === "m" || s === "masculino") return "Masculino";
      if (s === "2" || s === "f" || s === "feminino") return "Feminino";
      if (s === "3" || s === "o" || s === "outro") return "Outro";
      if (s === "9" || s === "n") return "Não informado";
      return String(g);
    });
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "card pmr-patient-card" }, _attrs))} data-v-35b6aad5><div class="card-body p-3 text-center" data-v-35b6aad5><div class="patient-avatar mx-auto mb-2 d-flex align-items-center justify-content-center text-white fw-bold" style="${ssrRenderStyle({ background: avatarColor.value })}" data-v-35b6aad5>${ssrInterpolate(initials.value)}</div><h6 class="mb-0 fw-semibold text-truncate"${ssrRenderAttr("title", __props.patient.full_name)} data-v-35b6aad5>${ssrInterpolate(__props.patient.full_name ?? "—")}</h6><small class="text-muted d-block" data-v-35b6aad5>${ssrInterpolate(__props.patient.code)}</small></div><ul class="list-group list-group-flush pmr-patient-info-list small" data-v-35b6aad5>`);
      if (__props.patient.birth_date) {
        _push(`<li class="list-group-item d-flex justify-content-between" data-v-35b6aad5><span class="text-muted" data-v-35b6aad5><i class="fas fa-birthday-cake me-1" data-v-35b6aad5></i>Nasc.</span><span class="fw-medium" data-v-35b6aad5>${ssrInterpolate(__props.patient.birth_date)} `);
        if (__props.patient.age) {
          _push(`<span class="text-muted" data-v-35b6aad5>(${ssrInterpolate(__props.patient.age)}a)</span>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</span></li>`);
      } else {
        _push(`<!---->`);
      }
      if (__props.patient.gender) {
        _push(`<li class="list-group-item d-flex justify-content-between" data-v-35b6aad5><span class="text-muted" data-v-35b6aad5><i class="fas fa-venus-mars me-1" data-v-35b6aad5></i>Sexo</span><span class="fw-medium" data-v-35b6aad5>${ssrInterpolate(genderLabel.value)}</span></li>`);
      } else {
        _push(`<!---->`);
      }
      if (__props.patient.cpf) {
        _push(`<li class="list-group-item d-flex justify-content-between" data-v-35b6aad5><span class="text-muted" data-v-35b6aad5><i class="fas fa-id-card me-1" data-v-35b6aad5></i>CPF</span><span class="fw-medium" data-v-35b6aad5>${ssrInterpolate(__props.patient.cpf)}</span></li>`);
      } else {
        _push(`<!---->`);
      }
      if (__props.patient.phone) {
        _push(`<li class="list-group-item d-flex justify-content-between" data-v-35b6aad5><span class="text-muted" data-v-35b6aad5><i class="fas fa-phone me-1" data-v-35b6aad5></i>Tel.</span><span class="fw-medium" data-v-35b6aad5>${ssrInterpolate(__props.patient.phone)}</span></li>`);
      } else {
        _push(`<!---->`);
      }
      if (__props.patient.email) {
        _push(`<li class="list-group-item d-flex flex-column" data-v-35b6aad5><span class="text-muted" data-v-35b6aad5><i class="fas fa-envelope me-1" data-v-35b6aad5></i>E-mail</span><span class="fw-medium pmr-break-all"${ssrRenderAttr("title", __props.patient.email)} data-v-35b6aad5>${ssrInterpolate(__props.patient.email)}</span></li>`);
      } else {
        _push(`<!---->`);
      }
      if (__props.patient.covenant_name) {
        _push(`<li class="list-group-item d-flex flex-column" data-v-35b6aad5><span class="text-muted" data-v-35b6aad5><i class="fas fa-handshake me-1" data-v-35b6aad5></i>Convênio</span><span class="fw-medium"${ssrRenderAttr("title", __props.patient.covenant_name)} data-v-35b6aad5>${ssrInterpolate(__props.patient.covenant_name)}</span></li>`);
      } else {
        _push(`<!---->`);
      }
      if (__props.patient.skin_type) {
        _push(`<li class="list-group-item d-flex justify-content-between" data-v-35b6aad5><span class="text-muted" data-v-35b6aad5><i class="fas fa-palette me-1" data-v-35b6aad5></i>Pele</span><span class="fw-medium" data-v-35b6aad5>${ssrInterpolate(__props.patient.skin_type)}</span></li>`);
      } else {
        _push(`<!---->`);
      }
      if (__props.patient.iris_type) {
        _push(`<li class="list-group-item d-flex justify-content-between" data-v-35b6aad5><span class="text-muted" data-v-35b6aad5><i class="fas fa-eye me-1" data-v-35b6aad5></i>Íris</span><span class="fw-medium" data-v-35b6aad5>${ssrInterpolate(__props.patient.iris_type)}</span></li>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</ul></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/MedicalRecords/Components/PatientInfoSidebar.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const PatientInfoSidebar = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-35b6aad5"]]);
export {
  PatientInfoSidebar as default
};
