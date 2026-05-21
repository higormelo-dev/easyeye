import { ref, onMounted, onUnmounted, mergeProps, withCtx, openBlock, createBlock, Fragment, renderList, createVNode, createTextVNode, toDisplayString, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderStyle, ssrIncludeBooleanAttr, ssrInterpolate, ssrRenderClass, ssrRenderAttr, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { A as ActionIconGroup, _ as _sfc_main$1 } from "./ActionIconGroup-Dj2wQrik.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "@inertiajs/vue3";
const _sfc_main = {
  __name: "ScheduleCard",
  __ssrInlineRender: true,
  props: {
    item: { type: Object, required: true },
    isStaff: { type: Boolean, default: false },
    isDoctor: { type: Boolean, default: false },
    moods: { type: Array, default: () => [] },
    selectionMode: { type: Boolean, default: false },
    selected: { type: Boolean, default: false },
    t: { type: Object, required: true }
  },
  emits: [
    "toggle-select",
    "view",
    "edit",
    "reschedule",
    "cancel",
    "change-situation",
    "change-mood"
  ],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const waitingTime = ref("");
    let timerInterval = null;
    function updateWaiting() {
      if (props.item.situation !== 3 || !props.item.arrived_at) {
        waitingTime.value = "";
        return;
      }
      const diff = Math.floor((Date.now() - new Date(props.item.arrived_at).getTime()) / 1e3);
      const h = Math.floor(diff / 3600);
      const m = Math.floor(diff % 3600 / 60);
      const s = diff % 60;
      waitingTime.value = h > 0 ? `${h}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}` : `${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
    }
    onMounted(() => {
      if (props.item.situation === 3 && props.item.arrived_at) {
        updateWaiting();
        timerInterval = setInterval(updateWaiting, 1e3);
      }
    });
    onUnmounted(() => clearInterval(timerInterval));
    function onSituationClick(trans) {
      if (trans.is_cancel) {
        emit("cancel", props.item);
      } else {
        emit("change-situation", { item: props.item, to: trans.value });
      }
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({
        class: ["card mb-2 border schedule-card", {
          "opacity-65 bg-light": __props.item.is_terminal,
          "border-primary bg-primary-subtle": __props.selectionMode && __props.selected
        }],
        style: { borderLeftColor: __props.item.doctor_color + " !important" }
      }, _attrs))}><div class="card-body py-2 px-3"><div class="d-flex align-items-center gap-3">`);
      if (__props.isStaff && __props.selectionMode) {
        _push(`<div class="flex-shrink-0"><input type="checkbox" class="form-check-input" style="${ssrRenderStyle({ "width": "1.2rem", "height": "1.2rem", "cursor": "pointer" })}"${ssrIncludeBooleanAttr(__props.selected) ? " checked" : ""}></div>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<div class="d-none d-md-flex flex-shrink-0 align-items-center justify-content-center rounded-circle" style="${ssrRenderStyle([{ "width": "42px", "height": "42px" }, { background: __props.item.doctor_color + "22", border: `2px solid ${__props.item.doctor_color}` }])}"><i class="fas fa-user" style="${ssrRenderStyle({ color: __props.item.doctor_color })}"></i></div><div class="flex-grow-1 schedule-card-info min-w-0"><div class="d-flex align-items-center gap-2 flex-wrap"><strong class="fs-6" style="${ssrRenderStyle({ color: __props.item.doctor_color })}">${ssrInterpolate(__props.item.time)}</strong><span class="fw-semibold">${ssrInterpolate(__props.item.name)}</span>`);
      if (__props.item.code) {
        _push(`<small class="text-muted">(${ssrInterpolate(__props.item.code)})</small>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div><div class="text-muted small mt-1">${ssrInterpolate(__props.item.visit_name ?? "—")} — ${ssrInterpolate(__props.item.covenant_name ?? "—")} `);
      if (!__props.isDoctor) {
        _push(`<!--[--> — ${ssrInterpolate(__props.item.doctor_name)}<!--]-->`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div><div class="d-flex align-items-center gap-1 flex-wrap mt-1"><span class="${ssrRenderClass([__props.item.badge, "badge"])}"><i class="${ssrRenderClass([__props.item.icon, "fas me-1"])}"></i>${ssrInterpolate(__props.item.label)}</span>`);
      if (__props.item.confirmed_at) {
        _push(`<span class="badge bg-info text-dark"${ssrRenderAttr("title", __props.t.show_confirmed_at + " " + __props.item.confirmed_at)}><i class="fas fa-check-circle"></i></span>`);
      } else {
        _push(`<!---->`);
      }
      if (__props.item.notes) {
        _push(`<span class="badge bg-light text-secondary border"${ssrRenderAttr("title", __props.item.notes)}><i class="fas fa-sticky-note"></i></span>`);
      } else {
        _push(`<!---->`);
      }
      if (__props.item.patient_mood) {
        _push(`<span class="${ssrRenderClass([__props.item.patient_mood.badge, "badge"])}"${ssrRenderAttr("title", __props.item.patient_mood.label)}><i class="${ssrRenderClass([__props.item.patient_mood.icon, "fas"])}"></i></span>`);
      } else {
        _push(`<!---->`);
      }
      if (__props.item.situation === 3 && __props.item.arrived_at && waitingTime.value) {
        _push(`<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>${ssrInterpolate(waitingTime.value)}</span>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div></div><div class="d-flex align-items-center flex-shrink-0" style="${ssrRenderStyle({ "gap": "8px" })}">`);
      if (!__props.item.is_terminal && __props.isStaff && (__props.item.allowed_transitions.length > 0 || __props.item.patient_id)) {
        _push(ssrRenderComponent(ActionIconGroup, { gap: "tight" }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              if (__props.item.allowed_transitions.length > 0) {
                _push2(ssrRenderComponent(ActionDropdown, {
                  icon: "fas fa-list-ul",
                  "btn-class": "ee-action-icon ee-action-icon--default",
                  title: __props.t.dropdown_situation ?? "Alterar situação"
                }, {
                  default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                    if (_push3) {
                      _push3(`<!--[-->`);
                      ssrRenderList(__props.item.allowed_transitions, (trans) => {
                        _push3(`<li${_scopeId2}><button type="button" class="dropdown-item"${_scopeId2}><i class="${ssrRenderClass(["text-" + (trans.is_cancel ? "dark" : "secondary"), "fas fa-circle me-2"])}"${_scopeId2}></i> ${ssrInterpolate(trans.label.toUpperCase())}</button></li>`);
                      });
                      _push3(`<!--]-->`);
                    } else {
                      return [
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.item.allowed_transitions, (trans) => {
                          return openBlock(), createBlock("li", {
                            key: trans.value
                          }, [
                            createVNode("button", {
                              type: "button",
                              class: "dropdown-item",
                              onClick: ($event) => onSituationClick(trans)
                            }, [
                              createVNode("i", {
                                class: ["fas fa-circle me-2", "text-" + (trans.is_cancel ? "dark" : "secondary")]
                              }, null, 2),
                              createTextVNode(" " + toDisplayString(trans.label.toUpperCase()), 1)
                            ], 8, ["onClick"])
                          ]);
                        }), 128))
                      ];
                    }
                  }),
                  _: 1
                }, _parent2, _scopeId));
              } else {
                _push2(`<!---->`);
              }
              if (__props.item.patient_id) {
                _push2(ssrRenderComponent(ActionDropdown, {
                  icon: "fas " + (__props.item.patient_mood ? __props.item.patient_mood.icon : "fa-theater-masks"),
                  "btn-class": "ee-action-icon ee-action-icon--default",
                  title: __props.t.dropdown_mood ?? "Humor do paciente"
                }, {
                  default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                    if (_push3) {
                      _push3(`<li${_scopeId2}><button type="button" class="dropdown-item"${_scopeId2}><i class="fas fa-times text-muted me-2"${_scopeId2}></i> ${ssrInterpolate(__props.t.dropdown_mood_clear ?? "Limpar")}</button></li><li${_scopeId2}><hr class="dropdown-divider"${_scopeId2}></li><!--[-->`);
                      ssrRenderList(__props.moods, (m) => {
                        var _a;
                        _push3(`<li${_scopeId2}><button type="button" class="${ssrRenderClass([{ "fw-bold": ((_a = __props.item.patient_mood) == null ? void 0 : _a.value) === m.value }, "dropdown-item"])}"${_scopeId2}><i class="${ssrRenderClass([[m.icon, m.text_class], "fas me-2"])}"${_scopeId2}></i> ${ssrInterpolate(m.label)}</button></li>`);
                      });
                      _push3(`<!--]-->`);
                    } else {
                      return [
                        createVNode("li", null, [
                          createVNode("button", {
                            type: "button",
                            class: "dropdown-item",
                            onClick: ($event) => emit("change-mood", { item: __props.item, mood: null })
                          }, [
                            createVNode("i", { class: "fas fa-times text-muted me-2" }),
                            createTextVNode(" " + toDisplayString(__props.t.dropdown_mood_clear ?? "Limpar"), 1)
                          ], 8, ["onClick"])
                        ]),
                        createVNode("li", null, [
                          createVNode("hr", { class: "dropdown-divider" })
                        ]),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.moods, (m) => {
                          var _a;
                          return openBlock(), createBlock("li", {
                            key: m.value
                          }, [
                            createVNode("button", {
                              type: "button",
                              class: ["dropdown-item", { "fw-bold": ((_a = __props.item.patient_mood) == null ? void 0 : _a.value) === m.value }],
                              onClick: ($event) => emit("change-mood", { item: __props.item, mood: m.value })
                            }, [
                              createVNode("i", {
                                class: ["fas me-2", [m.icon, m.text_class]]
                              }, null, 2),
                              createTextVNode(" " + toDisplayString(m.label), 1)
                            ], 10, ["onClick"])
                          ]);
                        }), 128))
                      ];
                    }
                  }),
                  _: 1
                }, _parent2, _scopeId));
              } else {
                _push2(`<!---->`);
              }
            } else {
              return [
                __props.item.allowed_transitions.length > 0 ? (openBlock(), createBlock(ActionDropdown, {
                  key: 0,
                  icon: "fas fa-list-ul",
                  "btn-class": "ee-action-icon ee-action-icon--default",
                  title: __props.t.dropdown_situation ?? "Alterar situação"
                }, {
                  default: withCtx(() => [
                    (openBlock(true), createBlock(Fragment, null, renderList(__props.item.allowed_transitions, (trans) => {
                      return openBlock(), createBlock("li", {
                        key: trans.value
                      }, [
                        createVNode("button", {
                          type: "button",
                          class: "dropdown-item",
                          onClick: ($event) => onSituationClick(trans)
                        }, [
                          createVNode("i", {
                            class: ["fas fa-circle me-2", "text-" + (trans.is_cancel ? "dark" : "secondary")]
                          }, null, 2),
                          createTextVNode(" " + toDisplayString(trans.label.toUpperCase()), 1)
                        ], 8, ["onClick"])
                      ]);
                    }), 128))
                  ]),
                  _: 1
                }, 8, ["title"])) : createCommentVNode("", true),
                __props.item.patient_id ? (openBlock(), createBlock(ActionDropdown, {
                  key: 1,
                  icon: "fas " + (__props.item.patient_mood ? __props.item.patient_mood.icon : "fa-theater-masks"),
                  "btn-class": "ee-action-icon ee-action-icon--default",
                  title: __props.t.dropdown_mood ?? "Humor do paciente"
                }, {
                  default: withCtx(() => [
                    createVNode("li", null, [
                      createVNode("button", {
                        type: "button",
                        class: "dropdown-item",
                        onClick: ($event) => emit("change-mood", { item: __props.item, mood: null })
                      }, [
                        createVNode("i", { class: "fas fa-times text-muted me-2" }),
                        createTextVNode(" " + toDisplayString(__props.t.dropdown_mood_clear ?? "Limpar"), 1)
                      ], 8, ["onClick"])
                    ]),
                    createVNode("li", null, [
                      createVNode("hr", { class: "dropdown-divider" })
                    ]),
                    (openBlock(true), createBlock(Fragment, null, renderList(__props.moods, (m) => {
                      var _a;
                      return openBlock(), createBlock("li", {
                        key: m.value
                      }, [
                        createVNode("button", {
                          type: "button",
                          class: ["dropdown-item", { "fw-bold": ((_a = __props.item.patient_mood) == null ? void 0 : _a.value) === m.value }],
                          onClick: ($event) => emit("change-mood", { item: __props.item, mood: m.value })
                        }, [
                          createVNode("i", {
                            class: ["fas me-2", [m.icon, m.text_class]]
                          }, null, 2),
                          createTextVNode(" " + toDisplayString(m.label), 1)
                        ], 10, ["onClick"])
                      ]);
                    }), 128))
                  ]),
                  _: 1
                }, 8, ["icon", "title"])) : createCommentVNode("", true)
              ];
            }
          }),
          _: 1
        }, _parent));
      } else {
        _push(`<!---->`);
      }
      _push(ssrRenderComponent(ActionIconGroup, { gap: "tight" }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            if (!__props.item.is_terminal) {
              _push2(ssrRenderComponent(_sfc_main$1, {
                icon: "fas fa-edit",
                title: __props.t.btn_edit ?? "Editar",
                onClick: ($event) => emit("edit", __props.item)
              }, null, _parent2, _scopeId));
            } else {
              _push2(`<!---->`);
            }
            if (__props.item.patient_url) {
              _push2(ssrRenderComponent(_sfc_main$1, {
                icon: "fas fa-address-card",
                title: __props.t.btn_patient ?? "Cadastro do paciente",
                href: __props.item.patient_url
              }, null, _parent2, _scopeId));
            } else {
              _push2(`<!---->`);
            }
            if (__props.item.medical_records_url) {
              _push2(ssrRenderComponent(_sfc_main$1, {
                icon: "fas fa-file-medical",
                title: __props.t.btn_medical_records ?? "Prontuário",
                variant: "info",
                href: __props.item.medical_records_url
              }, null, _parent2, _scopeId));
            } else {
              _push2(`<!---->`);
            }
            _push2(ssrRenderComponent(_sfc_main$1, {
              icon: "fas fa-eye",
              title: __props.t.btn_view ?? "Visualizar",
              onClick: ($event) => emit("view", __props.item)
            }, null, _parent2, _scopeId));
            if (!__props.item.is_terminal && __props.isStaff) {
              _push2(ssrRenderComponent(ActionDropdown, {
                icon: "fas fa-ellipsis-v",
                "btn-class": "ee-action-icon ee-action-icon--default",
                title: __props.t.dropdown_more ?? "Mais ações"
              }, {
                default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                  if (_push3) {
                    _push3(`<li${_scopeId2}><button type="button" class="dropdown-item"${_scopeId2}><i class="fas fa-calendar-alt me-2"${_scopeId2}></i>${ssrInterpolate(__props.t.btn_reschedule ?? "Reagendar")}</button></li>`);
                  } else {
                    return [
                      createVNode("li", null, [
                        createVNode("button", {
                          type: "button",
                          class: "dropdown-item",
                          onClick: ($event) => emit("reschedule", __props.item)
                        }, [
                          createVNode("i", { class: "fas fa-calendar-alt me-2" }),
                          createTextVNode(toDisplayString(__props.t.btn_reschedule ?? "Reagendar"), 1)
                        ], 8, ["onClick"])
                      ])
                    ];
                  }
                }),
                _: 1
              }, _parent2, _scopeId));
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              !__props.item.is_terminal ? (openBlock(), createBlock(_sfc_main$1, {
                key: 0,
                icon: "fas fa-edit",
                title: __props.t.btn_edit ?? "Editar",
                onClick: ($event) => emit("edit", __props.item)
              }, null, 8, ["title", "onClick"])) : createCommentVNode("", true),
              __props.item.patient_url ? (openBlock(), createBlock(_sfc_main$1, {
                key: 1,
                icon: "fas fa-address-card",
                title: __props.t.btn_patient ?? "Cadastro do paciente",
                href: __props.item.patient_url
              }, null, 8, ["title", "href"])) : createCommentVNode("", true),
              __props.item.medical_records_url ? (openBlock(), createBlock(_sfc_main$1, {
                key: 2,
                icon: "fas fa-file-medical",
                title: __props.t.btn_medical_records ?? "Prontuário",
                variant: "info",
                href: __props.item.medical_records_url
              }, null, 8, ["title", "href"])) : createCommentVNode("", true),
              createVNode(_sfc_main$1, {
                icon: "fas fa-eye",
                title: __props.t.btn_view ?? "Visualizar",
                onClick: ($event) => emit("view", __props.item)
              }, null, 8, ["title", "onClick"]),
              !__props.item.is_terminal && __props.isStaff ? (openBlock(), createBlock(ActionDropdown, {
                key: 3,
                icon: "fas fa-ellipsis-v",
                "btn-class": "ee-action-icon ee-action-icon--default",
                title: __props.t.dropdown_more ?? "Mais ações"
              }, {
                default: withCtx(() => [
                  createVNode("li", null, [
                    createVNode("button", {
                      type: "button",
                      class: "dropdown-item",
                      onClick: ($event) => emit("reschedule", __props.item)
                    }, [
                      createVNode("i", { class: "fas fa-calendar-alt me-2" }),
                      createTextVNode(toDisplayString(__props.t.btn_reschedule ?? "Reagendar"), 1)
                    ], 8, ["onClick"])
                  ])
                ]),
                _: 1
              }, 8, ["title"])) : createCommentVNode("", true)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`</div></div></div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Schedules/ScheduleCard.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
