import { ref, watch, onMounted, onUnmounted, mergeProps, withCtx, createVNode, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderList, ssrRenderAttr, ssrRenderStyle, ssrInterpolate, ssrRenderComponent, ssrRenderClass } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { _ as _sfc_main$1 } from "./ActionIconButton-BTsQtzdl.js";
import { A as ActionIconGroup } from "./ActionIconGroup-B8JEjj1z.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "DoctorCards",
  __ssrInlineRender: true,
  props: {
    cardsUrl: { type: String, required: true },
    initialSearch: { type: String, default: "" }
  },
  emits: ["edit", "delete", "toggleActive"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const doctors = ref([]);
    const meta = ref({ current_page: 1, last_page: 1, total: 0 });
    const loading = ref(false);
    async function fetchCards(p = 1) {
      loading.value = true;
      try {
        const params = new URLSearchParams({ page: p, search: props.initialSearch });
        const res = await fetch(`${props.cardsUrl}?${params}`);
        const json = await res.json();
        doctors.value = json.data;
        meta.value = json.meta;
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.initialSearch, () => fetchCards(1));
    let removeSuccessListener;
    onMounted(() => {
      fetchCards(1);
      removeSuccessListener = router.on("success", () => fetchCards(meta.value.current_page));
    });
    onUnmounted(() => removeSuccessListener == null ? void 0 : removeSuccessListener());
    return (_ctx, _push, _parent, _attrs) => {
      if (loading.value) {
        _push(`<div${ssrRenderAttrs(mergeProps({ class: "text-center py-5" }, _attrs))}><div class="spinner-border text-primary" role="status"></div></div>`);
      } else {
        _push(`<!--[-->`);
        if (doctors.value.length === 0) {
          _push(`<div class="text-center text-muted py-5"><i class="ti ti-stethoscope fs-1 mb-2 d-block"></i><p>Nenhum médico encontrado.</p></div>`);
        } else {
          _push(`<div class="row g-3"><!--[-->`);
          ssrRenderList(doctors.value, (d) => {
            _push(`<div class="col-sm-6 col-md-4 col-xl-3"><div class="card card-body h-100"><div class="d-flex align-items-center gap-3 mb-2"><div class="position-relative"><img${ssrRenderAttr("src", d.photo_url)}${ssrRenderAttr("alt", d.full_name)} class="rounded-circle" style="${ssrRenderStyle({ "width": "52px", "height": "52px", "object-fit": "cover" })}">`);
            if (d.color) {
              _push(`<span class="position-absolute bottom-0 end-0 rounded-circle border border-white" style="${ssrRenderStyle({ background: d.color, width: "14px", height: "14px" })}"></span>`);
            } else {
              _push(`<!---->`);
            }
            _push(`</div><div><h6 class="mb-0 fw-semibold lh-sm">${ssrInterpolate(d.full_name)}</h6><div class="text-muted" style="${ssrRenderStyle({ "font-size": ".75rem" })}">${ssrInterpolate(d.email)}</div></div></div><div class="small text-muted mb-2"><div><strong>Código:</strong> ${ssrInterpolate(d.code)}</div><div><strong>CRM:</strong> ${ssrInterpolate(d.record)}</div><div><strong>Especialidade:</strong> ${ssrInterpolate(d.record_specialty ?? "—")}</div></div><hr class="my-2">`);
            if (d.mode === "full") {
              _push(ssrRenderComponent(ActionIconGroup, {
                align: "end",
                gap: "tight"
              }, {
                default: withCtx((_, _push2, _parent2, _scopeId) => {
                  if (_push2) {
                    _push2(ssrRenderComponent(_sfc_main$1, {
                      icon: "ti ti-edit",
                      title: "Editar",
                      onClick: ($event) => _ctx.$emit("edit", d.id)
                    }, null, _parent2, _scopeId));
                    _push2(ssrRenderComponent(_sfc_main$1, {
                      icon: `ti ${d.active ? "ti-lock-open" : "ti-lock"}`,
                      title: d.active ? "Desativar" : "Ativar",
                      onClick: ($event) => _ctx.$emit("toggleActive", d.id, d.active)
                    }, null, _parent2, _scopeId));
                    _push2(ssrRenderComponent(_sfc_main$1, {
                      icon: "ti ti-trash",
                      title: "Excluir",
                      variant: "danger",
                      onClick: ($event) => _ctx.$emit("delete", d.id)
                    }, null, _parent2, _scopeId));
                  } else {
                    return [
                      createVNode(_sfc_main$1, {
                        icon: "ti ti-edit",
                        title: "Editar",
                        onClick: ($event) => _ctx.$emit("edit", d.id)
                      }, null, 8, ["onClick"]),
                      createVNode(_sfc_main$1, {
                        icon: `ti ${d.active ? "ti-lock-open" : "ti-lock"}`,
                        title: d.active ? "Desativar" : "Ativar",
                        onClick: ($event) => _ctx.$emit("toggleActive", d.id, d.active)
                      }, null, 8, ["icon", "title", "onClick"]),
                      createVNode(_sfc_main$1, {
                        icon: "ti ti-trash",
                        title: "Excluir",
                        variant: "danger",
                        onClick: ($event) => _ctx.$emit("delete", d.id)
                      }, null, 8, ["onClick"])
                    ];
                  }
                }),
                _: 2
              }, _parent));
            } else {
              _push(`<!---->`);
            }
            _push(`</div></div>`);
          });
          _push(`<!--]--></div>`);
        }
        if (meta.value.last_page > 1) {
          _push(`<nav class="d-flex justify-content-center mt-3"><ul class="pagination pagination-sm mb-0"><li class="${ssrRenderClass([{ disabled: meta.value.current_page === 1 }, "page-item"])}"><button class="page-link"><i class="ti ti-arrow-left"></i></button></li><!--[-->`);
          ssrRenderList(meta.value.last_page, (p) => {
            _push(`<li class="${ssrRenderClass([{ active: p === meta.value.current_page }, "page-item"])}"><button class="page-link">${ssrInterpolate(p)}</button></li>`);
          });
          _push(`<!--]--><li class="${ssrRenderClass([{ disabled: meta.value.current_page === meta.value.last_page }, "page-item"])}"><button class="page-link"><i class="ti ti-arrow-right"></i></button></li></ul></nav>`);
        } else {
          _push(`<!---->`);
        }
        _push(`<!--]-->`);
      }
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Doctors/DoctorCards.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
