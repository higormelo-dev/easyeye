import { ref, watch, onMounted, onUnmounted, mergeProps, withCtx, createVNode, createTextVNode, toDisplayString, openBlock, createBlock, Fragment, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderList, ssrRenderAttr, ssrRenderStyle, ssrInterpolate, ssrRenderClass, ssrRenderComponent } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { _ as _sfc_main$1 } from "./ActionIconButton-BTsQtzdl.js";
import { A as ActionIconGroup } from "./ActionIconGroup-B8JEjj1z.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const fallbackPhoto = "/img/system/team.png";
const _sfc_main = {
  __name: "PatientCards",
  __ssrInlineRender: true,
  props: {
    cardsUrl: { type: String, required: true },
    initialSearch: { type: String, default: "" }
  },
  emits: ["edit", "view", "delete", "toggleActive", "restore"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const patients = ref([]);
    const meta = ref({ current_page: 1, last_page: 1, total: 0 });
    const loading = ref(false);
    const page = ref(1);
    async function fetchCards(p = 1) {
      loading.value = true;
      page.value = p;
      try {
        const params = new URLSearchParams({ page: p, search: props.initialSearch });
        const res = await fetch(`${props.cardsUrl}?${params}`);
        const json = await res.json();
        patients.value = json.data;
        meta.value = json.meta;
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.initialSearch, () => fetchCards(1));
    let removeSuccessListener;
    onMounted(() => {
      fetchCards(1);
      removeSuccessListener = router.on("success", () => fetchCards(page.value));
    });
    onUnmounted(() => removeSuccessListener == null ? void 0 : removeSuccessListener());
    return (_ctx, _push, _parent, _attrs) => {
      if (loading.value) {
        _push(`<div${ssrRenderAttrs(mergeProps({ class: "text-center py-5" }, _attrs))}><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Carregando...</span></div></div>`);
      } else {
        _push(`<!--[-->`);
        if (patients.value.length === 0) {
          _push(`<div class="text-center text-muted py-5"><i class="ti ti-user-off fs-1 mb-3 d-block"></i><p>Nenhum paciente encontrado.</p></div>`);
        } else {
          _push(`<div class="row g-3"><!--[-->`);
          ssrRenderList(patients.value, (p) => {
            _push(`<div class="col-xs-12 col-sm-6 col-md-4 col-lg-4 col-xl-3"><div class="card card-body h-100"><div class="row align-items-center"><div class="col-3 text-center"><img${ssrRenderAttr("src", p.photo_url ?? fallbackPhoto)}${ssrRenderAttr("alt", p.full_name)} class="img-fluid rounded-circle" style="${ssrRenderStyle({ "width": "56px", "height": "56px", "object-fit": "cover" })}"></div><div class="col-9"><h6 class="mb-1 fw-semibold lh-sm">${ssrInterpolate(p.full_name)}</h6><span class="${ssrRenderClass(p.active ? "badge badge-soft-success rounded text-success border border-success fs-12" : "badge badge-soft-danger rounded text-danger border border-danger fs-12")}">${ssrInterpolate(p.active ? "Ativo" : "Inativo")}</span></div></div><address class="small text-muted mt-2 mb-1"><strong>Código:</strong> ${ssrInterpolate(p.code)}<br><strong>Convênio:</strong> ${ssrInterpolate(p.covenant ?? "Não informado")}<br><strong>Pele:</strong> ${ssrInterpolate(p.skin ?? "Não informado")}<br><strong>Íris:</strong> ${ssrInterpolate(p.iris ?? "Não informado")}</address><hr class="my-2">`);
            _push(ssrRenderComponent(ActionIconGroup, {
              align: "end",
              gap: "tight"
            }, {
              default: withCtx((_, _push2, _parent2, _scopeId) => {
                if (_push2) {
                  if (p.mode === "restore") {
                    _push2(ssrRenderComponent(_sfc_main$1, {
                      icon: "ti ti-recycle",
                      title: "Restaurar",
                      onClick: ($event) => _ctx.$emit("restore", p.id)
                    }, null, _parent2, _scopeId));
                  } else if (p.mode === "view_only") {
                    _push2(ssrRenderComponent(_sfc_main$1, {
                      icon: "ti ti-eye",
                      title: "Visualizar",
                      onClick: ($event) => _ctx.$emit("view", p.id)
                    }, null, _parent2, _scopeId));
                  } else if (p.mode === "full") {
                    _push2(`<!--[-->`);
                    _push2(ssrRenderComponent(_sfc_main$1, {
                      icon: "ti ti-eye",
                      title: "Visualizar",
                      onClick: ($event) => _ctx.$emit("view", p.id)
                    }, null, _parent2, _scopeId));
                    _push2(ssrRenderComponent(_sfc_main$1, {
                      icon: "ti ti-stethoscope",
                      title: "Prontuário",
                      variant: "info",
                      href: p.medical_records_url
                    }, null, _parent2, _scopeId));
                    _push2(ssrRenderComponent(ActionDropdown, {
                      "btn-class": "ee-action-icon ee-action-icon--default",
                      icon: "ti ti-dots-vertical"
                    }, {
                      default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                        if (_push3) {
                          _push3(`<li${_scopeId2}><button class="dropdown-item rounded-1"${_scopeId2}><i class="ti ti-edit me-1"${_scopeId2}></i> Editar </button></li><li${_scopeId2}><button class="dropdown-item rounded-1"${_scopeId2}><i class="${ssrRenderClass(`ti me-1 ${p.active ? "ti-lock-open" : "ti-lock"}`)}"${_scopeId2}></i> ${ssrInterpolate(p.active ? "Desativar" : "Ativar")}</button></li><li${_scopeId2}><hr class="dropdown-divider"${_scopeId2}></li><li${_scopeId2}><button class="dropdown-item rounded-1 text-danger"${_scopeId2}><i class="ti ti-trash me-1"${_scopeId2}></i> Excluir </button></li>`);
                        } else {
                          return [
                            createVNode("li", null, [
                              createVNode("button", {
                                class: "dropdown-item rounded-1",
                                onClick: ($event) => _ctx.$emit("edit", p.id)
                              }, [
                                createVNode("i", { class: "ti ti-edit me-1" }),
                                createTextVNode(" Editar ")
                              ], 8, ["onClick"])
                            ]),
                            createVNode("li", null, [
                              createVNode("button", {
                                class: "dropdown-item rounded-1",
                                onClick: ($event) => _ctx.$emit("toggleActive", p.id, p.active)
                              }, [
                                createVNode("i", {
                                  class: `ti me-1 ${p.active ? "ti-lock-open" : "ti-lock"}`
                                }, null, 2),
                                createTextVNode(" " + toDisplayString(p.active ? "Desativar" : "Ativar"), 1)
                              ], 8, ["onClick"])
                            ]),
                            createVNode("li", null, [
                              createVNode("hr", { class: "dropdown-divider" })
                            ]),
                            createVNode("li", null, [
                              createVNode("button", {
                                class: "dropdown-item rounded-1 text-danger",
                                onClick: ($event) => _ctx.$emit("delete", p.id)
                              }, [
                                createVNode("i", { class: "ti ti-trash me-1" }),
                                createTextVNode(" Excluir ")
                              ], 8, ["onClick"])
                            ])
                          ];
                        }
                      }),
                      _: 2
                    }, _parent2, _scopeId));
                    _push2(`<!--]-->`);
                  } else {
                    _push2(`<!---->`);
                  }
                } else {
                  return [
                    p.mode === "restore" ? (openBlock(), createBlock(_sfc_main$1, {
                      key: 0,
                      icon: "ti ti-recycle",
                      title: "Restaurar",
                      onClick: ($event) => _ctx.$emit("restore", p.id)
                    }, null, 8, ["onClick"])) : p.mode === "view_only" ? (openBlock(), createBlock(_sfc_main$1, {
                      key: 1,
                      icon: "ti ti-eye",
                      title: "Visualizar",
                      onClick: ($event) => _ctx.$emit("view", p.id)
                    }, null, 8, ["onClick"])) : p.mode === "full" ? (openBlock(), createBlock(Fragment, { key: 2 }, [
                      createVNode(_sfc_main$1, {
                        icon: "ti ti-eye",
                        title: "Visualizar",
                        onClick: ($event) => _ctx.$emit("view", p.id)
                      }, null, 8, ["onClick"]),
                      createVNode(_sfc_main$1, {
                        icon: "ti ti-stethoscope",
                        title: "Prontuário",
                        variant: "info",
                        href: p.medical_records_url
                      }, null, 8, ["href"]),
                      createVNode(ActionDropdown, {
                        "btn-class": "ee-action-icon ee-action-icon--default",
                        icon: "ti ti-dots-vertical"
                      }, {
                        default: withCtx(() => [
                          createVNode("li", null, [
                            createVNode("button", {
                              class: "dropdown-item rounded-1",
                              onClick: ($event) => _ctx.$emit("edit", p.id)
                            }, [
                              createVNode("i", { class: "ti ti-edit me-1" }),
                              createTextVNode(" Editar ")
                            ], 8, ["onClick"])
                          ]),
                          createVNode("li", null, [
                            createVNode("button", {
                              class: "dropdown-item rounded-1",
                              onClick: ($event) => _ctx.$emit("toggleActive", p.id, p.active)
                            }, [
                              createVNode("i", {
                                class: `ti me-1 ${p.active ? "ti-lock-open" : "ti-lock"}`
                              }, null, 2),
                              createTextVNode(" " + toDisplayString(p.active ? "Desativar" : "Ativar"), 1)
                            ], 8, ["onClick"])
                          ]),
                          createVNode("li", null, [
                            createVNode("hr", { class: "dropdown-divider" })
                          ]),
                          createVNode("li", null, [
                            createVNode("button", {
                              class: "dropdown-item rounded-1 text-danger",
                              onClick: ($event) => _ctx.$emit("delete", p.id)
                            }, [
                              createVNode("i", { class: "ti ti-trash me-1" }),
                              createTextVNode(" Excluir ")
                            ], 8, ["onClick"])
                          ])
                        ]),
                        _: 2
                      }, 1024)
                    ], 64)) : createCommentVNode("", true)
                  ];
                }
              }),
              _: 2
            }, _parent));
            _push(`</div></div>`);
          });
          _push(`<!--]--></div>`);
        }
        if (meta.value.last_page > 1) {
          _push(`<nav class="d-flex justify-content-center mt-3"><ul class="pagination pagination-sm mb-0"><li class="${ssrRenderClass([{ disabled: meta.value.current_page === 1 }, "page-item"])}"><button class="page-link"><i class="ti ti-arrow-left text-body"></i></button></li><!--[-->`);
          ssrRenderList(meta.value.last_page, (p) => {
            _push(`<li class="${ssrRenderClass([{ active: p === meta.value.current_page }, "page-item"])}"><button class="page-link">${ssrInterpolate(p)}</button></li>`);
          });
          _push(`<!--]--><li class="${ssrRenderClass([{ disabled: meta.value.current_page === meta.value.last_page }, "page-item"])}"><button class="page-link"><i class="ti ti-arrow-right text-body"></i></button></li></ul></nav>`);
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Patients/PatientCards.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
