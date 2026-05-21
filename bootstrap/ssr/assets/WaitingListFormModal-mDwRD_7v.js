import { ref, watch, mergeProps, withCtx, createVNode, withModifiers, createTextVNode, toDisplayString, withDirectives, openBlock, createBlock, Fragment, renderList, vModelSelect, createCommentVNode, vModelText, withKeys, vModelCheckbox, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderClass, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList, ssrRenderAttr, ssrRenderStyle } from "vue/server-renderer";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "WaitingListFormModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    doctors: { type: Array, default: () => [] },
    covenants: { type: Array, default: () => [] },
    visitTypes: { type: Array, default: () => [] },
    t: { type: Object, required: true }
  },
  emits: ["close", "saved"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const saving = ref(false);
    const errors = ref({});
    const form = ref(emptyForm());
    function emptyForm() {
      return {
        doctor_id: "",
        patient_id: "",
        full_name: "",
        telephone: "",
        cellphone: "",
        cellphone_whatsapp: false,
        covenant_id: "",
        visit_id: "",
        notes: "",
        preferred_date_from: "",
        preferred_date_until: ""
      };
    }
    watch(() => props.open, (val) => {
      if (val) {
        form.value = emptyForm();
        errors.value = {};
        saving.value = false;
        patientSearch.value = "";
        patientResults.value = [];
        showQuickReg.value = false;
        quickName.value = "";
      }
    });
    const patientSearch = ref("");
    const patientResults = ref([]);
    const showQuickReg = ref(false);
    const quickName = ref("");
    let searchDebounce = null;
    function onPatientInput() {
      clearTimeout(searchDebounce);
      if (patientSearch.value.length < 2) {
        patientResults.value = [];
        return;
      }
      searchDebounce = setTimeout(searchPatients, 350);
    }
    async function searchPatients() {
      const res = await fetch(`/panel/patients/search?q=${encodeURIComponent(patientSearch.value)}`, {
        headers: { Accept: "application/json" }
      });
      patientResults.value = res.ok ? await res.json() : [];
    }
    function selectPatient(p) {
      form.value.patient_id = p.id;
      form.value.full_name = p.full_name;
      form.value.cellphone = p.cellphone ?? "";
      form.value.telephone = p.telephone ?? "";
      patientSearch.value = p.full_name;
      patientResults.value = [];
    }
    function clearPatient() {
      form.value.patient_id = "";
      form.value.full_name = "";
      patientSearch.value = "";
      patientResults.value = [];
    }
    async function quickRegister() {
      var _a;
      if (!quickName.value.trim()) return;
      const res = await fetch("/panel/patients/quick", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? ""
        },
        body: JSON.stringify({ full_name: quickName.value.trim() })
      });
      if (res.ok) {
        const json = await res.json();
        selectPatient({ id: json.id, full_name: json.full_name, cellphone: "", telephone: "" });
        showQuickReg.value = false;
        quickName.value = "";
      }
    }
    async function onSubmit() {
      var _a;
      if (saving.value) return;
      saving.value = true;
      errors.value = {};
      const res = await fetch(route("panel.waiting-list.store"), {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? ""
        },
        body: JSON.stringify(form.value)
      });
      const json = await res.json();
      saving.value = false;
      if (res.ok) {
        if (window.showSuccessToast) window.showSuccessToast(json.message);
        emit("saved", json.data);
      } else if (res.status === 422) {
        errors.value = json.errors ?? {};
      } else {
        if (window.showErrorToast) window.showErrorToast(json.message ?? "Erro ao salvar.");
      }
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(OffcanvasPanel, mergeProps({
        open: __props.open,
        width: 560,
        onClose: ($event) => emit("close")
      }, _attrs), {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<h5 class="mb-0 fw-bold"${_scopeId}><i class="fas fa-hourglass-half me-2 text-warning"${_scopeId}></i>${ssrInterpolate(__props.t.wl_title)}</h5>`);
          } else {
            return [
              createVNode("h5", { class: "mb-0 fw-bold" }, [
                createVNode("i", { class: "fas fa-hourglass-half me-2 text-warning" }),
                createTextVNode(toDisplayString(__props.t.wl_title), 1)
              ])
            ];
          }
        }),
        footer: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<button type="button" class="btn btn-secondary"${_scopeId}>${ssrInterpolate(__props.t.wl_cancel)}</button><button type="button" class="btn btn-warning px-4"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}${_scopeId}>`);
            if (saving.value) {
              _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<i class="fas fa-hourglass-half me-1"${_scopeId}></i>${ssrInterpolate(__props.t.wl_add_btn)}</button>`);
          } else {
            return [
              createVNode("button", {
                type: "button",
                class: "btn btn-secondary",
                onClick: ($event) => emit("close")
              }, toDisplayString(__props.t.wl_cancel), 9, ["onClick"]),
              createVNode("button", {
                type: "button",
                class: "btn btn-warning px-4",
                disabled: saving.value,
                onClick: onSubmit
              }, [
                saving.value ? (openBlock(), createBlock("span", {
                  key: 0,
                  class: "spinner-border spinner-border-sm me-1"
                })) : createCommentVNode("", true),
                createVNode("i", { class: "fas fa-hourglass-half me-1" }),
                createTextVNode(toDisplayString(__props.t.wl_add_btn), 1)
              ], 8, ["disabled"])
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b;
          if (_push2) {
            _push2(`<form${_scopeId}><div class="mb-3"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.wl_doctor)} <span class="text-danger"${_scopeId}>*</span></label><select class="${ssrRenderClass([{ "is-invalid": errors.value.doctor_id }, "form-select"])}"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.doctor_id) ? ssrLooseContain(form.value.doctor_id, "") : ssrLooseEqual(form.value.doctor_id, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.form_select)}</option><!--[-->`);
            ssrRenderList(__props.doctors, (d) => {
              _push2(`<option${ssrRenderAttr("value", d.id)}${ssrIncludeBooleanAttr(Array.isArray(form.value.doctor_id) ? ssrLooseContain(form.value.doctor_id, d.id) : ssrLooseEqual(form.value.doctor_id, d.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(d.name)}</option>`);
            });
            _push2(`<!--]--></select>`);
            if (errors.value.doctor_id) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(errors.value.doctor_id[0])}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="mb-3"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.wl_period)} <small class="text-muted fw-normal"${_scopeId}>${ssrInterpolate(__props.t.wl_period_opt)}</small></label><div class="input-group"${_scopeId}><input${ssrRenderAttr("value", form.value.preferred_date_from)} type="date" class="${ssrRenderClass([{ "is-invalid": errors.value.preferred_date_from }, "form-control"])}"${_scopeId}><span class="input-group-text"${_scopeId}>${ssrInterpolate(__props.t.wl_until)}</span><input${ssrRenderAttr("value", form.value.preferred_date_until)} type="date" class="${ssrRenderClass([{ "is-invalid": errors.value.preferred_date_until }, "form-control"])}"${_scopeId}></div>`);
            if (errors.value.preferred_date_from || errors.value.preferred_date_until) {
              _push2(`<div class="invalid-feedback d-block"${_scopeId}>${ssrInterpolate((_a = errors.value.preferred_date_from ?? errors.value.preferred_date_until) == null ? void 0 : _a[0])}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="mb-3"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.wl_patient)}</label><div class="position-relative"${_scopeId}><input${ssrRenderAttr("value", patientSearch.value)} type="text" class="form-control"${ssrRenderAttr("placeholder", __props.t.wl_patient_search)} autocomplete="off"${_scopeId}>`);
            if (form.value.patient_id) {
              _push2(`<button type="button" class="btn btn-sm btn-outline-secondary position-absolute end-0 top-0 mt-1 me-1"${_scopeId}><i class="fas fa-times"${_scopeId}></i></button>`);
            } else {
              _push2(`<!---->`);
            }
            if (patientResults.value.length > 0) {
              _push2(`<ul class="list-group position-absolute w-100 shadow-sm" style="${ssrRenderStyle({ "z-index": "1060", "max-height": "200px", "overflow-y": "auto" })}"${_scopeId}><!--[-->`);
              ssrRenderList(patientResults.value, (p) => {
                _push2(`<li class="list-group-item list-group-item-action py-2 px-3" style="${ssrRenderStyle({ "cursor": "pointer" })}"${_scopeId}><div class="fw-semibold small"${_scopeId}>${ssrInterpolate(p.full_name)}</div><div class="text-muted" style="${ssrRenderStyle({ "font-size": ".75rem" })}"${_scopeId}>${ssrInterpolate(p.cellphone || p.telephone || "—")} • ${ssrInterpolate(p.code)}</div></li>`);
              });
              _push2(`<!--]--><li class="list-group-item list-group-item-action text-primary py-2 px-3" style="${ssrRenderStyle({ "cursor": "pointer" })}"${_scopeId}><i class="fas fa-plus-circle me-1"${_scopeId}></i> ${ssrInterpolate(__props.t.wl_register)} &quot;${ssrInterpolate(patientSearch.value)}&quot; </li></ul>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
            if (showQuickReg.value) {
              _push2(`<div class="card card-body bg-light mt-2 p-3"${_scopeId}><p class="mb-2 fw-semibold small"${_scopeId}>${ssrInterpolate(__props.t.wl_quick_register)}</p><div class="row g-2"${_scopeId}><div class="col-8"${_scopeId}><input${ssrRenderAttr("value", quickName.value)} type="text" class="form-control form-control-sm"${ssrRenderAttr("placeholder", __props.t.wl_full_name + " *")}${_scopeId}></div></div><div class="d-flex gap-2 mt-2"${_scopeId}><button type="button" class="btn btn-sm btn-primary"${_scopeId}>${ssrInterpolate(__props.t.wl_save_link)}</button><button type="button" class="btn btn-sm btn-outline-secondary"${_scopeId}>${ssrInterpolate(__props.t.wl_cancel)}</button></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="mb-3"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.wl_full_name)} <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", form.value.full_name)} type="text" class="${ssrRenderClass([{ "is-invalid": errors.value.full_name }, "form-control"])}"${ssrRenderAttr("placeholder", __props.t.wl_patient_name)}${_scopeId}>`);
            if (errors.value.full_name) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(errors.value.full_name[0])}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="row g-2 mb-3"${_scopeId}><div class="col-6"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.wl_covenant)}</label><select class="form-select"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.covenant_id) ? ssrLooseContain(form.value.covenant_id, "") : ssrLooseEqual(form.value.covenant_id, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.wl_none)}</option><!--[-->`);
            ssrRenderList(__props.covenants, (c) => {
              _push2(`<option${ssrRenderAttr("value", c.id)}${ssrIncludeBooleanAttr(Array.isArray(form.value.covenant_id) ? ssrLooseContain(form.value.covenant_id, c.id) : ssrLooseEqual(form.value.covenant_id, c.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(c.name)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-6"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.wl_visit_type)}</label><select class="form-select"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.visit_id) ? ssrLooseContain(form.value.visit_id, "") : ssrLooseEqual(form.value.visit_id, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.wl_none)}</option><!--[-->`);
            ssrRenderList(__props.visitTypes, (v) => {
              _push2(`<option${ssrRenderAttr("value", v.id)}${ssrIncludeBooleanAttr(Array.isArray(form.value.visit_id) ? ssrLooseContain(form.value.visit_id, v.id) : ssrLooseEqual(form.value.visit_id, v.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(v.name)}</option>`);
            });
            _push2(`<!--]--></select></div></div><div class="row g-2 mb-3"${_scopeId}><div class="col-6"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.wl_telephone)}</label><input${ssrRenderAttr("value", form.value.telephone)} type="text" class="form-control" placeholder="(00) 0000-0000"${_scopeId}></div><div class="col-6"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.wl_cellphone)}</label><input${ssrRenderAttr("value", form.value.cellphone)} type="text" class="form-control" placeholder="(00) 00000-0000"${_scopeId}></div></div><div class="form-check mb-3"${_scopeId}><input id="wl-whatsapp"${ssrIncludeBooleanAttr(Array.isArray(form.value.cellphone_whatsapp) ? ssrLooseContain(form.value.cellphone_whatsapp, null) : form.value.cellphone_whatsapp) ? " checked" : ""} type="checkbox" class="form-check-input"${_scopeId}><label for="wl-whatsapp" class="form-check-label small"${_scopeId}><i class="fab fa-whatsapp text-success me-1"${_scopeId}></i>${ssrInterpolate(__props.t.wl_whatsapp)}</label></div><div class="mb-3"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.wl_notes)}</label><textarea class="form-control" rows="2"${ssrRenderAttr("placeholder", __props.t.wl_notes_ph)}${_scopeId}>${ssrInterpolate(form.value.notes)}</textarea></div></form>`);
          } else {
            return [
              createVNode("form", {
                onSubmit: withModifiers(onSubmit, ["prevent"])
              }, [
                createVNode("div", { class: "mb-3" }, [
                  createVNode("label", { class: "form-label fw-semibold" }, [
                    createTextVNode(toDisplayString(__props.t.wl_doctor) + " ", 1),
                    createVNode("span", { class: "text-danger" }, "*")
                  ]),
                  withDirectives(createVNode("select", {
                    "onUpdate:modelValue": ($event) => form.value.doctor_id = $event,
                    class: ["form-select", { "is-invalid": errors.value.doctor_id }]
                  }, [
                    createVNode("option", { value: "" }, toDisplayString(__props.t.form_select), 1),
                    (openBlock(true), createBlock(Fragment, null, renderList(__props.doctors, (d) => {
                      return openBlock(), createBlock("option", {
                        key: d.id,
                        value: d.id
                      }, toDisplayString(d.name), 9, ["value"]);
                    }), 128))
                  ], 10, ["onUpdate:modelValue"]), [
                    [vModelSelect, form.value.doctor_id]
                  ]),
                  errors.value.doctor_id ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "invalid-feedback"
                  }, toDisplayString(errors.value.doctor_id[0]), 1)) : createCommentVNode("", true)
                ]),
                createVNode("div", { class: "mb-3" }, [
                  createVNode("label", { class: "form-label fw-semibold" }, [
                    createTextVNode(toDisplayString(__props.t.wl_period) + " ", 1),
                    createVNode("small", { class: "text-muted fw-normal" }, toDisplayString(__props.t.wl_period_opt), 1)
                  ]),
                  createVNode("div", { class: "input-group" }, [
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => form.value.preferred_date_from = $event,
                      type: "date",
                      class: ["form-control", { "is-invalid": errors.value.preferred_date_from }]
                    }, null, 10, ["onUpdate:modelValue"]), [
                      [vModelText, form.value.preferred_date_from]
                    ]),
                    createVNode("span", { class: "input-group-text" }, toDisplayString(__props.t.wl_until), 1),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => form.value.preferred_date_until = $event,
                      type: "date",
                      class: ["form-control", { "is-invalid": errors.value.preferred_date_until }]
                    }, null, 10, ["onUpdate:modelValue"]), [
                      [vModelText, form.value.preferred_date_until]
                    ])
                  ]),
                  errors.value.preferred_date_from || errors.value.preferred_date_until ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "invalid-feedback d-block"
                  }, toDisplayString((_b = errors.value.preferred_date_from ?? errors.value.preferred_date_until) == null ? void 0 : _b[0]), 1)) : createCommentVNode("", true)
                ]),
                createVNode("div", { class: "mb-3" }, [
                  createVNode("label", { class: "form-label fw-semibold" }, toDisplayString(__props.t.wl_patient), 1),
                  createVNode("div", { class: "position-relative" }, [
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => patientSearch.value = $event,
                      type: "text",
                      class: "form-control",
                      placeholder: __props.t.wl_patient_search,
                      autocomplete: "off",
                      onInput: onPatientInput
                    }, null, 40, ["onUpdate:modelValue", "placeholder"]), [
                      [vModelText, patientSearch.value]
                    ]),
                    form.value.patient_id ? (openBlock(), createBlock("button", {
                      key: 0,
                      type: "button",
                      class: "btn btn-sm btn-outline-secondary position-absolute end-0 top-0 mt-1 me-1",
                      onClick: clearPatient
                    }, [
                      createVNode("i", { class: "fas fa-times" })
                    ])) : createCommentVNode("", true),
                    patientResults.value.length > 0 ? (openBlock(), createBlock("ul", {
                      key: 1,
                      class: "list-group position-absolute w-100 shadow-sm",
                      style: { "z-index": "1060", "max-height": "200px", "overflow-y": "auto" }
                    }, [
                      (openBlock(true), createBlock(Fragment, null, renderList(patientResults.value, (p) => {
                        return openBlock(), createBlock("li", {
                          key: p.id,
                          class: "list-group-item list-group-item-action py-2 px-3",
                          style: { "cursor": "pointer" },
                          onMousedown: withModifiers(($event) => selectPatient(p), ["prevent"])
                        }, [
                          createVNode("div", { class: "fw-semibold small" }, toDisplayString(p.full_name), 1),
                          createVNode("div", {
                            class: "text-muted",
                            style: { "font-size": ".75rem" }
                          }, toDisplayString(p.cellphone || p.telephone || "—") + " • " + toDisplayString(p.code), 1)
                        ], 40, ["onMousedown"]);
                      }), 128)),
                      createVNode("li", {
                        class: "list-group-item list-group-item-action text-primary py-2 px-3",
                        style: { "cursor": "pointer" },
                        onMousedown: withModifiers(($event) => {
                          showQuickReg.value = true;
                          quickName.value = patientSearch.value;
                        }, ["prevent"])
                      }, [
                        createVNode("i", { class: "fas fa-plus-circle me-1" }),
                        createTextVNode(" " + toDisplayString(__props.t.wl_register) + ' "' + toDisplayString(patientSearch.value) + '" ', 1)
                      ], 40, ["onMousedown"])
                    ])) : createCommentVNode("", true)
                  ]),
                  showQuickReg.value ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "card card-body bg-light mt-2 p-3"
                  }, [
                    createVNode("p", { class: "mb-2 fw-semibold small" }, toDisplayString(__props.t.wl_quick_register), 1),
                    createVNode("div", { class: "row g-2" }, [
                      createVNode("div", { class: "col-8" }, [
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => quickName.value = $event,
                          type: "text",
                          class: "form-control form-control-sm",
                          placeholder: __props.t.wl_full_name + " *",
                          onKeyup: withKeys(quickRegister, ["enter"])
                        }, null, 40, ["onUpdate:modelValue", "placeholder"]), [
                          [vModelText, quickName.value]
                        ])
                      ])
                    ]),
                    createVNode("div", { class: "d-flex gap-2 mt-2" }, [
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-sm btn-primary",
                        onClick: quickRegister
                      }, toDisplayString(__props.t.wl_save_link), 1),
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-sm btn-outline-secondary",
                        onClick: ($event) => showQuickReg.value = false
                      }, toDisplayString(__props.t.wl_cancel), 9, ["onClick"])
                    ])
                  ])) : createCommentVNode("", true)
                ]),
                createVNode("div", { class: "mb-3" }, [
                  createVNode("label", { class: "form-label fw-semibold" }, [
                    createTextVNode(toDisplayString(__props.t.wl_full_name) + " ", 1),
                    createVNode("span", { class: "text-danger" }, "*")
                  ]),
                  withDirectives(createVNode("input", {
                    "onUpdate:modelValue": ($event) => form.value.full_name = $event,
                    type: "text",
                    class: ["form-control", { "is-invalid": errors.value.full_name }],
                    placeholder: __props.t.wl_patient_name
                  }, null, 10, ["onUpdate:modelValue", "placeholder"]), [
                    [vModelText, form.value.full_name]
                  ]),
                  errors.value.full_name ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "invalid-feedback"
                  }, toDisplayString(errors.value.full_name[0]), 1)) : createCommentVNode("", true)
                ]),
                createVNode("div", { class: "row g-2 mb-3" }, [
                  createVNode("div", { class: "col-6" }, [
                    createVNode("label", { class: "form-label fw-semibold" }, toDisplayString(__props.t.wl_covenant), 1),
                    withDirectives(createVNode("select", {
                      "onUpdate:modelValue": ($event) => form.value.covenant_id = $event,
                      class: "form-select"
                    }, [
                      createVNode("option", { value: "" }, toDisplayString(__props.t.wl_none), 1),
                      (openBlock(true), createBlock(Fragment, null, renderList(__props.covenants, (c) => {
                        return openBlock(), createBlock("option", {
                          key: c.id,
                          value: c.id
                        }, toDisplayString(c.name), 9, ["value"]);
                      }), 128))
                    ], 8, ["onUpdate:modelValue"]), [
                      [vModelSelect, form.value.covenant_id]
                    ])
                  ]),
                  createVNode("div", { class: "col-6" }, [
                    createVNode("label", { class: "form-label fw-semibold" }, toDisplayString(__props.t.wl_visit_type), 1),
                    withDirectives(createVNode("select", {
                      "onUpdate:modelValue": ($event) => form.value.visit_id = $event,
                      class: "form-select"
                    }, [
                      createVNode("option", { value: "" }, toDisplayString(__props.t.wl_none), 1),
                      (openBlock(true), createBlock(Fragment, null, renderList(__props.visitTypes, (v) => {
                        return openBlock(), createBlock("option", {
                          key: v.id,
                          value: v.id
                        }, toDisplayString(v.name), 9, ["value"]);
                      }), 128))
                    ], 8, ["onUpdate:modelValue"]), [
                      [vModelSelect, form.value.visit_id]
                    ])
                  ])
                ]),
                createVNode("div", { class: "row g-2 mb-3" }, [
                  createVNode("div", { class: "col-6" }, [
                    createVNode("label", { class: "form-label fw-semibold" }, toDisplayString(__props.t.wl_telephone), 1),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => form.value.telephone = $event,
                      type: "text",
                      class: "form-control",
                      placeholder: "(00) 0000-0000"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelText, form.value.telephone]
                    ])
                  ]),
                  createVNode("div", { class: "col-6" }, [
                    createVNode("label", { class: "form-label fw-semibold" }, toDisplayString(__props.t.wl_cellphone), 1),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => form.value.cellphone = $event,
                      type: "text",
                      class: "form-control",
                      placeholder: "(00) 00000-0000"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelText, form.value.cellphone]
                    ])
                  ])
                ]),
                createVNode("div", { class: "form-check mb-3" }, [
                  withDirectives(createVNode("input", {
                    id: "wl-whatsapp",
                    "onUpdate:modelValue": ($event) => form.value.cellphone_whatsapp = $event,
                    type: "checkbox",
                    class: "form-check-input"
                  }, null, 8, ["onUpdate:modelValue"]), [
                    [vModelCheckbox, form.value.cellphone_whatsapp]
                  ]),
                  createVNode("label", {
                    for: "wl-whatsapp",
                    class: "form-check-label small"
                  }, [
                    createVNode("i", { class: "fab fa-whatsapp text-success me-1" }),
                    createTextVNode(toDisplayString(__props.t.wl_whatsapp), 1)
                  ])
                ]),
                createVNode("div", { class: "mb-3" }, [
                  createVNode("label", { class: "form-label fw-semibold" }, toDisplayString(__props.t.wl_notes), 1),
                  withDirectives(createVNode("textarea", {
                    "onUpdate:modelValue": ($event) => form.value.notes = $event,
                    class: "form-control",
                    rows: "2",
                    placeholder: __props.t.wl_notes_ph
                  }, null, 8, ["onUpdate:modelValue", "placeholder"]), [
                    [vModelText, form.value.notes]
                  ])
                ])
              ], 32)
            ];
          }
        }),
        _: 1
      }, _parent));
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Schedules/WaitingListFormModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
