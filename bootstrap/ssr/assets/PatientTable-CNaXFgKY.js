import { computed, withCtx, createVNode, createTextVNode, toDisplayString, unref, useSSRContext } from "vue";
import { ssrRenderClass, ssrRenderList, ssrInterpolate, ssrRenderAttr, ssrRenderStyle, ssrRenderComponent } from "vue/server-renderer";
import { Link } from "@inertiajs/vue3";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { A as ActionIconGroup, _ as _sfc_main$1 } from "./ActionIconGroup-Dj2wQrik.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const sortableColClass = "cursor-pointer user-select-none";
const _sfc_main = {
  __name: "PatientTable",
  __ssrInlineRender: true,
  props: {
    patients: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) }
  },
  emits: ["sort", "edit", "view", "delete", "toggleActive", "restore"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const currentSort = computed(() => props.filters.sort ?? "created_at");
    const currentDir = computed(() => props.filters.direction ?? "desc");
    function sortIcon(col) {
      if (currentSort.value !== col) return "ti ti-arrows-sort text-muted";
      return currentDir.value === "asc" ? "ti ti-sort-ascending" : "ti ti-sort-descending";
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[--><div class="table-responsive" data-v-5c3a7454><table class="table table-nowrap table-hover align-middle mb-0" data-v-5c3a7454><thead class="table-light" data-v-5c3a7454><tr data-v-5c3a7454><th class="${ssrRenderClass(sortableColClass)}" data-v-5c3a7454> Cadastro <i class="${ssrRenderClass([sortIcon("created_at"), "ms-1 fs-11"])}" data-v-5c3a7454></i></th><th class="${ssrRenderClass(sortableColClass)}" data-v-5c3a7454> Código <i class="${ssrRenderClass([sortIcon("code"), "ms-1 fs-11"])}" data-v-5c3a7454></i></th><th class="${ssrRenderClass(sortableColClass)}" data-v-5c3a7454> Nome <i class="${ssrRenderClass([sortIcon("full_name"), "ms-1 fs-11"])}" data-v-5c3a7454></i></th><th data-v-5c3a7454>Gênero</th><th class="${ssrRenderClass(sortableColClass)}" data-v-5c3a7454> Telefone <i class="${ssrRenderClass([sortIcon("cellphone"), "ms-1 fs-11"])}" data-v-5c3a7454></i></th><th class="text-center" data-v-5c3a7454>Status</th><th class="text-end" data-v-5c3a7454>Ações</th></tr></thead><tbody data-v-5c3a7454>`);
      if (__props.patients.data.length === 0) {
        _push(`<tr data-v-5c3a7454><td colspan="7" class="text-center text-muted py-5" data-v-5c3a7454><i class="ti ti-user-off fs-1 d-block mb-2" data-v-5c3a7454></i> Nenhum paciente encontrado. </td></tr>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<!--[-->`);
      ssrRenderList(__props.patients.data, (p) => {
        _push(`<tr class="${ssrRenderClass({ "table-secondary opacity-75": p.deleted })}" data-v-5c3a7454><td class="text-muted small" data-v-5c3a7454>${ssrInterpolate(p.created_at)}</td><td data-v-5c3a7454><code class="text-muted small" data-v-5c3a7454>${ssrInterpolate(p.code)}</code></td><td data-v-5c3a7454><div class="d-flex align-items-center gap-2" data-v-5c3a7454><img${ssrRenderAttr("src", p.photo_url)}${ssrRenderAttr("alt", p.full_name)} class="rounded-circle" style="${ssrRenderStyle({ "width": "30px", "height": "30px", "object-fit": "cover" })}" data-v-5c3a7454><span class="fw-medium" data-v-5c3a7454>${ssrInterpolate(p.full_name)}</span>`);
        if (p.deleted) {
          _push(`<i class="ti ti-trash text-danger ms-1" title="Excluído" data-v-5c3a7454></i>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</div></td><td class="text-muted small" data-v-5c3a7454>${ssrInterpolate(p.gender_label ?? "—")}</td><td class="small" data-v-5c3a7454>`);
        if (p.whatsapp) {
          _push(`<i class="fab fa-whatsapp text-success me-1" data-v-5c3a7454></i>`);
        } else {
          _push(`<!---->`);
        }
        _push(` ${ssrInterpolate(p.cellphone ?? "—")}</td><td class="text-center" data-v-5c3a7454>`);
        if (p.deleted) {
          _push(`<span class="badge badge-soft-secondary rounded fs-13 fw-medium" data-v-5c3a7454>Excluído</span>`);
        } else if (p.active) {
          _push(`<span class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium" data-v-5c3a7454>Sim</span>`);
        } else {
          _push(`<span class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium" data-v-5c3a7454>Não</span>`);
        }
        _push(`</td><td class="text-end" data-v-5c3a7454>`);
        if (p.mode === "restore") {
          _push(ssrRenderComponent(ActionIconGroup, { align: "end" }, {
            default: withCtx((_, _push2, _parent2, _scopeId) => {
              if (_push2) {
                _push2(ssrRenderComponent(_sfc_main$1, {
                  icon: "ti ti-recycle",
                  title: "Restaurar",
                  onClick: ($event) => _ctx.$emit("restore", p.id)
                }, null, _parent2, _scopeId));
              } else {
                return [
                  createVNode(_sfc_main$1, {
                    icon: "ti ti-recycle",
                    title: "Restaurar",
                    onClick: ($event) => _ctx.$emit("restore", p.id)
                  }, null, 8, ["onClick"])
                ];
              }
            }),
            _: 2
          }, _parent));
        } else if (p.mode === "view_only") {
          _push(ssrRenderComponent(ActionIconGroup, { align: "end" }, {
            default: withCtx((_, _push2, _parent2, _scopeId) => {
              if (_push2) {
                _push2(ssrRenderComponent(_sfc_main$1, {
                  icon: "ti ti-eye",
                  title: "Visualizar",
                  onClick: ($event) => _ctx.$emit("view", p.id)
                }, null, _parent2, _scopeId));
              } else {
                return [
                  createVNode(_sfc_main$1, {
                    icon: "ti ti-eye",
                    title: "Visualizar",
                    onClick: ($event) => _ctx.$emit("view", p.id)
                  }, null, 8, ["onClick"])
                ];
              }
            }),
            _: 2
          }, _parent));
        } else if (p.mode === "full") {
          _push(ssrRenderComponent(ActionIconGroup, {
            align: "end",
            gap: "tight"
          }, {
            default: withCtx((_, _push2, _parent2, _scopeId) => {
              if (_push2) {
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
                      _push3(`<li data-v-5c3a7454${_scopeId2}><button class="dropdown-item rounded-1" data-v-5c3a7454${_scopeId2}><i class="ti ti-edit me-1" data-v-5c3a7454${_scopeId2}></i> Editar </button></li><li data-v-5c3a7454${_scopeId2}><button class="dropdown-item rounded-1" data-v-5c3a7454${_scopeId2}><i class="${ssrRenderClass(`ti me-1 ${p.active ? "ti-lock-open" : "ti-lock"}`)}" data-v-5c3a7454${_scopeId2}></i> ${ssrInterpolate(p.active ? "Desativar" : "Ativar")}</button></li><li data-v-5c3a7454${_scopeId2}><hr class="dropdown-divider" data-v-5c3a7454${_scopeId2}></li><li data-v-5c3a7454${_scopeId2}><button class="dropdown-item rounded-1 text-danger" data-v-5c3a7454${_scopeId2}><i class="ti ti-trash me-1" data-v-5c3a7454${_scopeId2}></i> Excluir </button></li>`);
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
              } else {
                return [
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
                ];
              }
            }),
            _: 2
          }, _parent));
        } else {
          _push(`<!---->`);
        }
        _push(`</td></tr>`);
      });
      _push(`<!--]--></tbody></table></div>`);
      if (__props.patients.last_page > 1) {
        _push(`<div class="d-flex align-items-center justify-content-between mt-3 flex-wrap gap-2" data-v-5c3a7454><p class="text-muted small mb-0" data-v-5c3a7454> Exibindo ${ssrInterpolate(__props.patients.from)}–${ssrInterpolate(__props.patients.to)} de ${ssrInterpolate(__props.patients.total)} pacientes </p><nav data-v-5c3a7454><ul class="pagination pagination-sm mb-0" data-v-5c3a7454><li class="${ssrRenderClass([{ disabled: __props.patients.current_page === 1 }, "page-item"])}" data-v-5c3a7454>`);
        _push(ssrRenderComponent(unref(Link), {
          class: "page-link",
          href: __props.patients.prev_page_url ?? "#",
          "preserve-scroll": "",
          "preserve-state": ""
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              _push2(`<i class="ti ti-arrow-left" data-v-5c3a7454${_scopeId}></i>`);
            } else {
              return [
                createVNode("i", { class: "ti ti-arrow-left" })
              ];
            }
          }),
          _: 1
        }, _parent));
        _push(`</li><!--[-->`);
        ssrRenderList(__props.patients.links.slice(1, -1), (link) => {
          _push(`<li class="${ssrRenderClass([{ active: link.active, disabled: !link.url }, "page-item"])}" data-v-5c3a7454>`);
          _push(ssrRenderComponent(unref(Link), {
            class: "page-link",
            href: link.url ?? "#",
            "preserve-scroll": "",
            "preserve-state": ""
          }, null, _parent));
          _push(`</li>`);
        });
        _push(`<!--]--><li class="${ssrRenderClass([{ disabled: __props.patients.current_page === __props.patients.last_page }, "page-item"])}" data-v-5c3a7454>`);
        _push(ssrRenderComponent(unref(Link), {
          class: "page-link",
          href: __props.patients.next_page_url ?? "#",
          "preserve-scroll": "",
          "preserve-state": ""
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              _push2(`<i class="ti ti-arrow-right" data-v-5c3a7454${_scopeId}></i>`);
            } else {
              return [
                createVNode("i", { class: "ti ti-arrow-right" })
              ];
            }
          }),
          _: 1
        }, _parent));
        _push(`</li></ul></nav></div>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<!--]-->`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Patients/PatientTable.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const PatientTable = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-5c3a7454"]]);
export {
  PatientTable as default
};
