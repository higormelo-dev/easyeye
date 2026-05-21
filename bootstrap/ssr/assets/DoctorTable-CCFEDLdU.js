import { computed, withCtx, createVNode, createTextVNode, toDisplayString, openBlock, createBlock, createCommentVNode, unref, useSSRContext } from "vue";
import { ssrRenderClass, ssrRenderList, ssrInterpolate, ssrRenderStyle, ssrRenderAttr, ssrRenderComponent } from "vue/server-renderer";
import { Link } from "@inertiajs/vue3";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { A as ActionIconGroup, _ as _sfc_main$1 } from "./ActionIconGroup-Dj2wQrik.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const th = "cursor-pointer user-select-none";
const _sfc_main = {
  __name: "DoctorTable",
  __ssrInlineRender: true,
  props: {
    doctors: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) }
  },
  emits: ["sort", "view", "edit", "delete", "toggleActive"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const currentSort = computed(() => props.filters.sort ?? "created_at");
    const currentDir = computed(() => props.filters.direction ?? "desc");
    function sortIcon(col) {
      if (currentSort.value !== col) return "ti ti-arrows-sort text-muted";
      return currentDir.value === "asc" ? "ti ti-sort-ascending" : "ti ti-sort-descending";
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[--><div class="table-responsive" data-v-3dd65b34><table class="table table-nowrap table-hover align-middle mb-0" data-v-3dd65b34><thead class="table-light" data-v-3dd65b34><tr data-v-3dd65b34><th class="${ssrRenderClass(th)}" data-v-3dd65b34> Cadastro <i class="${ssrRenderClass([sortIcon("created_at"), "ms-1 fs-11"])}" data-v-3dd65b34></i></th><th class="${ssrRenderClass(th)}" data-v-3dd65b34> Código <i class="${ssrRenderClass([sortIcon("code"), "ms-1 fs-11"])}" data-v-3dd65b34></i></th><th class="${ssrRenderClass(th)}" data-v-3dd65b34> Nome <i class="${ssrRenderClass([sortIcon("full_name"), "ms-1 fs-11"])}" data-v-3dd65b34></i></th><th class="${ssrRenderClass(th)}" data-v-3dd65b34> CRM <i class="${ssrRenderClass([sortIcon("record"), "ms-1 fs-11"])}" data-v-3dd65b34></i></th><th class="${ssrRenderClass(th)}" data-v-3dd65b34> E-mail <i class="${ssrRenderClass([sortIcon("email"), "ms-1 fs-11"])}" data-v-3dd65b34></i></th><th class="text-center" data-v-3dd65b34>Status</th><th class="text-end" data-v-3dd65b34>Ações</th></tr></thead><tbody data-v-3dd65b34>`);
      if (__props.doctors.data.length === 0) {
        _push(`<tr data-v-3dd65b34><td colspan="7" class="text-center text-muted py-5" data-v-3dd65b34><i class="ti ti-stethoscope fs-1 d-block mb-2" data-v-3dd65b34></i> Nenhum médico encontrado. </td></tr>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<!--[-->`);
      ssrRenderList(__props.doctors.data, (d) => {
        _push(`<tr class="${ssrRenderClass({ "table-secondary opacity-75": d.deleted })}" data-v-3dd65b34><td class="text-muted small" data-v-3dd65b34>${ssrInterpolate(d.created_at)}</td><td data-v-3dd65b34><code class="text-muted small" data-v-3dd65b34>${ssrInterpolate(d.code)}</code></td><td data-v-3dd65b34><div class="d-flex align-items-center gap-2" data-v-3dd65b34>`);
        if (d.color) {
          _push(`<span class="rounded-circle d-inline-block border" style="${ssrRenderStyle({ background: d.color, width: "12px", height: "12px", flexShrink: 0 })}" data-v-3dd65b34></span>`);
        } else {
          _push(`<!---->`);
        }
        _push(`<img${ssrRenderAttr("src", d.photo_url)}${ssrRenderAttr("alt", d.full_name)} class="rounded-circle" style="${ssrRenderStyle({ "width": "28px", "height": "28px", "object-fit": "cover" })}" data-v-3dd65b34><div data-v-3dd65b34><div class="fw-medium" style="${ssrRenderStyle({ "font-size": ".875rem" })}" data-v-3dd65b34>${ssrInterpolate(d.full_name)}</div><div class="text-muted" style="${ssrRenderStyle({ "font-size": ".75rem" })}" data-v-3dd65b34>${ssrInterpolate(d.record_specialty)}</div></div></div></td><td class="small" data-v-3dd65b34>${ssrInterpolate(d.record)}</td><td class="text-muted small" data-v-3dd65b34>${ssrInterpolate(d.email)}</td><td class="text-center" data-v-3dd65b34>`);
        if (d.active) {
          _push(`<span class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium" data-v-3dd65b34>Ativo</span>`);
        } else {
          _push(`<span class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium" data-v-3dd65b34>Inativo</span>`);
        }
        _push(`</td><td class="text-end" data-v-3dd65b34>`);
        _push(ssrRenderComponent(ActionIconGroup, {
          align: "end",
          gap: "tight"
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              _push2(ssrRenderComponent(_sfc_main$1, {
                icon: "ti ti-eye",
                title: "Visualizar",
                onClick: ($event) => _ctx.$emit("view", d.id)
              }, null, _parent2, _scopeId));
              _push2(ssrRenderComponent(_sfc_main$1, {
                icon: "ti ti-calendar-time",
                title: "Horários de atendimento",
                variant: "info",
                href: d.work_schedule_url
              }, null, _parent2, _scopeId));
              if (d.mode === "full") {
                _push2(ssrRenderComponent(ActionDropdown, {
                  "btn-class": "ee-action-icon ee-action-icon--default",
                  icon: "ti ti-dots-vertical"
                }, {
                  default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                    if (_push3) {
                      _push3(`<li data-v-3dd65b34${_scopeId2}><button class="dropdown-item rounded-1" data-v-3dd65b34${_scopeId2}><i class="ti ti-edit me-1" data-v-3dd65b34${_scopeId2}></i> Editar </button></li><li data-v-3dd65b34${_scopeId2}><button class="dropdown-item rounded-1" data-v-3dd65b34${_scopeId2}><i class="${ssrRenderClass(`ti me-1 ${d.active ? "ti-lock-open" : "ti-lock"}`)}" data-v-3dd65b34${_scopeId2}></i> ${ssrInterpolate(d.active ? "Desativar" : "Ativar")}</button></li><li data-v-3dd65b34${_scopeId2}><hr class="dropdown-divider" data-v-3dd65b34${_scopeId2}></li><li data-v-3dd65b34${_scopeId2}><button class="dropdown-item rounded-1 text-danger" data-v-3dd65b34${_scopeId2}><i class="ti ti-trash me-1" data-v-3dd65b34${_scopeId2}></i> Excluir </button></li>`);
                    } else {
                      return [
                        createVNode("li", null, [
                          createVNode("button", {
                            class: "dropdown-item rounded-1",
                            onClick: ($event) => _ctx.$emit("edit", d.id)
                          }, [
                            createVNode("i", { class: "ti ti-edit me-1" }),
                            createTextVNode(" Editar ")
                          ], 8, ["onClick"])
                        ]),
                        createVNode("li", null, [
                          createVNode("button", {
                            class: "dropdown-item rounded-1",
                            onClick: ($event) => _ctx.$emit("toggleActive", d.id, d.active)
                          }, [
                            createVNode("i", {
                              class: `ti me-1 ${d.active ? "ti-lock-open" : "ti-lock"}`
                            }, null, 2),
                            createTextVNode(" " + toDisplayString(d.active ? "Desativar" : "Ativar"), 1)
                          ], 8, ["onClick"])
                        ]),
                        createVNode("li", null, [
                          createVNode("hr", { class: "dropdown-divider" })
                        ]),
                        createVNode("li", null, [
                          createVNode("button", {
                            class: "dropdown-item rounded-1 text-danger",
                            onClick: ($event) => _ctx.$emit("delete", d.id)
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
                _push2(`<!---->`);
              }
            } else {
              return [
                createVNode(_sfc_main$1, {
                  icon: "ti ti-eye",
                  title: "Visualizar",
                  onClick: ($event) => _ctx.$emit("view", d.id)
                }, null, 8, ["onClick"]),
                createVNode(_sfc_main$1, {
                  icon: "ti ti-calendar-time",
                  title: "Horários de atendimento",
                  variant: "info",
                  href: d.work_schedule_url
                }, null, 8, ["href"]),
                d.mode === "full" ? (openBlock(), createBlock(ActionDropdown, {
                  key: 0,
                  "btn-class": "ee-action-icon ee-action-icon--default",
                  icon: "ti ti-dots-vertical"
                }, {
                  default: withCtx(() => [
                    createVNode("li", null, [
                      createVNode("button", {
                        class: "dropdown-item rounded-1",
                        onClick: ($event) => _ctx.$emit("edit", d.id)
                      }, [
                        createVNode("i", { class: "ti ti-edit me-1" }),
                        createTextVNode(" Editar ")
                      ], 8, ["onClick"])
                    ]),
                    createVNode("li", null, [
                      createVNode("button", {
                        class: "dropdown-item rounded-1",
                        onClick: ($event) => _ctx.$emit("toggleActive", d.id, d.active)
                      }, [
                        createVNode("i", {
                          class: `ti me-1 ${d.active ? "ti-lock-open" : "ti-lock"}`
                        }, null, 2),
                        createTextVNode(" " + toDisplayString(d.active ? "Desativar" : "Ativar"), 1)
                      ], 8, ["onClick"])
                    ]),
                    createVNode("li", null, [
                      createVNode("hr", { class: "dropdown-divider" })
                    ]),
                    createVNode("li", null, [
                      createVNode("button", {
                        class: "dropdown-item rounded-1 text-danger",
                        onClick: ($event) => _ctx.$emit("delete", d.id)
                      }, [
                        createVNode("i", { class: "ti ti-trash me-1" }),
                        createTextVNode(" Excluir ")
                      ], 8, ["onClick"])
                    ])
                  ]),
                  _: 2
                }, 1024)) : createCommentVNode("", true)
              ];
            }
          }),
          _: 2
        }, _parent));
        _push(`</td></tr>`);
      });
      _push(`<!--]--></tbody></table></div>`);
      if (__props.doctors.last_page > 1) {
        _push(`<div class="d-flex align-items-center justify-content-between mt-3 flex-wrap gap-2" data-v-3dd65b34><p class="text-muted small mb-0" data-v-3dd65b34> Exibindo ${ssrInterpolate(__props.doctors.from)}–${ssrInterpolate(__props.doctors.to)} de ${ssrInterpolate(__props.doctors.total)} médicos </p><nav data-v-3dd65b34><ul class="pagination pagination-sm mb-0" data-v-3dd65b34><li class="${ssrRenderClass([{ disabled: __props.doctors.current_page === 1 }, "page-item"])}" data-v-3dd65b34>`);
        _push(ssrRenderComponent(unref(Link), {
          class: "page-link",
          href: __props.doctors.prev_page_url ?? "#",
          "preserve-scroll": "",
          "preserve-state": ""
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              _push2(`<i class="ti ti-arrow-left" data-v-3dd65b34${_scopeId}></i>`);
            } else {
              return [
                createVNode("i", { class: "ti ti-arrow-left" })
              ];
            }
          }),
          _: 1
        }, _parent));
        _push(`</li><!--[-->`);
        ssrRenderList(__props.doctors.links.slice(1, -1), (link) => {
          _push(`<li class="${ssrRenderClass([{ active: link.active, disabled: !link.url }, "page-item"])}" data-v-3dd65b34>`);
          _push(ssrRenderComponent(unref(Link), {
            class: "page-link",
            href: link.url ?? "#",
            "preserve-scroll": "",
            "preserve-state": ""
          }, null, _parent));
          _push(`</li>`);
        });
        _push(`<!--]--><li class="${ssrRenderClass([{ disabled: __props.doctors.current_page === __props.doctors.last_page }, "page-item"])}" data-v-3dd65b34>`);
        _push(ssrRenderComponent(unref(Link), {
          class: "page-link",
          href: __props.doctors.next_page_url ?? "#",
          "preserve-scroll": "",
          "preserve-state": ""
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              _push2(`<i class="ti ti-arrow-right" data-v-3dd65b34${_scopeId}></i>`);
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Doctors/DoctorTable.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const DoctorTable = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-3dd65b34"]]);
export {
  DoctorTable as default
};
