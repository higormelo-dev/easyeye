import { ssrRenderTeleport, ssrRenderStyle, ssrInterpolate, ssrRenderAttr, ssrRenderList, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { ref, computed, watch, useSSRContext } from "vue";
const _sfc_main = {
  __name: "GatewayEntityAccessModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    gateway: { type: Object, default: null },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const entities = ref([]);
    const loading = ref(false);
    const loadError = ref("");
    const search = ref("");
    const reloadPage = ref(false);
    const filtered = computed(() => {
      const q = search.value.toLowerCase().trim();
      if (!q) return entities.value;
      return entities.value.filter(
        (e) => e.name.toLowerCase().includes(q) || e.code.toLowerCase().includes(q)
      );
    });
    watch(() => props.open, async (val) => {
      if (val && props.gateway) {
        search.value = "";
        reloadPage.value = false;
        await loadEntities();
      }
      if (!val) {
        entities.value = [];
      }
    });
    async function loadEntities() {
      loading.value = true;
      loadError.value = "";
      entities.value = [];
      try {
        const res = await fetch(props.gateway.entity_access_url);
        const json = await res.json();
        if (!res.ok) throw new Error(json.message);
        entities.value = json.data ?? [];
      } catch {
        loadError.value = props.t.js_error_load_clinics ?? "Erro ao carregar clínicas.";
      } finally {
        loading.value = false;
      }
    }
    return (_ctx, _push, _parent, _attrs) => {
      ssrRenderTeleport(_push, (_push2) => {
        if (__props.open) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.4)" })}"><div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="ti ti-building-hospital me-2"></i>${ssrInterpolate(__props.t.modal_ea_title)} `);
          if (__props.gateway) {
            _push2(`<span class="text-muted fw-normal ms-1">— ${ssrInterpolate(__props.gateway.name)}</span>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</h5><button type="button" class="btn-close"></button></div><div class="modal-body"><div class="alert alert-info d-flex gap-2 align-items-start py-2 mb-3"><i class="ti ti-info-circle flex-shrink-0 mt-1"></i><div class="small">${ssrInterpolate(__props.t.modal_ea_alert)}</div></div><div class="mb-3"><input${ssrRenderAttr("value", search.value)} type="text" class="form-control form-control-sm"${ssrRenderAttr("placeholder", __props.t.modal_ea_search_ph)}></div>`);
          if (loading.value) {
            _push2(`<div class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div><p class="text-muted small mt-2 mb-0">${ssrInterpolate(__props.t.modal_ea_loading)}</p></div>`);
          } else if (loadError.value) {
            _push2(`<div class="alert alert-danger small py-2"><i class="ti ti-alert-circle me-1"></i>${ssrInterpolate(loadError.value)}</div>`);
          } else if (filtered.value.length === 0) {
            _push2(`<div class="text-center py-4 text-muted small">${ssrInterpolate(__props.t.modal_ea_empty)}</div>`);
          } else {
            _push2(`<!--[-->`);
            ssrRenderList(filtered.value, (entity) => {
              _push2(`<div class="d-flex align-items-center justify-content-between py-2 border-bottom"><div><span class="fw-semibold small">${ssrInterpolate(entity.name)}</span><span class="badge badge-soft-secondary ms-2" style="${ssrRenderStyle({ "font-size": ".7rem" })}">${ssrInterpolate(entity.code)}</span></div><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch"${ssrIncludeBooleanAttr(entity.enabled) ? " checked" : ""}${ssrRenderAttr("title", entity.enabled ? __props.t.modal_ea_disable : __props.t.modal_ea_enable)}></div></div>`);
            });
            _push2(`<!--]-->`);
          }
          _push2(`</div><div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm">${ssrInterpolate(__props.t.modal_ea_close)}</button></div></div></div></div>`);
        } else {
          _push2(`<!---->`);
        }
      }, "body", false, _parent);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Gateways/GatewayEntityAccessModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
