import { useSSRContext, ref, watch, nextTick, computed, mergeProps, withCtx, createVNode, withModifiers, openBlock, createBlock, createTextVNode, toDisplayString, withDirectives, Fragment, renderList, vModelSelect, createCommentVNode, vModelText, withKeys, vModelCheckbox } from "vue";
import { ssrRenderTeleport, ssrRenderClass, ssrRenderSlot, ssrInterpolate, ssrRenderComponent, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList, ssrRenderAttr, ssrRenderStyle } from "vue/server-renderer";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
import { _ as _sfc_main$2 } from "./SlotPicker-Bgvng9-B.js";
const _sfc_main$1 = {
  __name: "CenteredModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    size: { type: String, default: "lg" },
    loading: { type: Boolean, default: false },
    loadingLabel: { type: String, default: "Carregando..." }
  },
  emits: ["close"],
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      ssrRenderTeleport(_push, (_push2) => {
        if (__props.open) {
          _push2(`<div class="ee-modal__backdrop" data-v-841c70f1></div>`);
        } else {
          _push2(`<!---->`);
        }
        if (__props.open) {
          _push2(`<div class="ee-modal__wrap" role="dialog" aria-modal="true" data-v-841c70f1><div class="${ssrRenderClass([`ee-modal--${__props.size}`, "ee-modal__dialog"])}" data-v-841c70f1><div class="ee-modal__header" data-v-841c70f1><div class="ee-modal__header-content" data-v-841c70f1>`);
          ssrRenderSlot(_ctx.$slots, "header", {}, null, _push2, _parent);
          _push2(`</div><button type="button" class="btn-close flex-shrink-0" data-v-841c70f1></button></div>`);
          if (_ctx.$slots.tabs) {
            _push2(`<div class="ee-modal__tabs border-bottom" data-v-841c70f1>`);
            ssrRenderSlot(_ctx.$slots, "tabs", {}, null, _push2, _parent);
            _push2(`</div>`);
          } else {
            _push2(`<!---->`);
          }
          if (__props.loading) {
            _push2(`<div class="text-center py-5" data-v-841c70f1><div class="spinner-border text-primary" role="status" data-v-841c70f1><span class="visually-hidden" data-v-841c70f1>${ssrInterpolate(__props.loadingLabel)}</span></div></div>`);
          } else {
            _push2(`<!--[--><div class="ee-modal__body" data-v-841c70f1>`);
            ssrRenderSlot(_ctx.$slots, "default", {}, null, _push2, _parent);
            _push2(`</div>`);
            if (_ctx.$slots.footer) {
              _push2(`<div class="ee-modal__footer" data-v-841c70f1>`);
              ssrRenderSlot(_ctx.$slots, "footer", {}, null, _push2, _parent);
              _push2(`</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--]-->`);
          }
          _push2(`</div></div>`);
        } else {
          _push2(`<!---->`);
        }
      }, "body", false, _parent);
    };
  }
};
const _sfc_setup$1 = _sfc_main$1.setup;
_sfc_main$1.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/CenteredModal.vue");
  return _sfc_setup$1 ? _sfc_setup$1(props, ctx) : void 0;
};
const CenteredModal = /* @__PURE__ */ _export_sfc(_sfc_main$1, [["__scopeId", "data-v-841c70f1"]]);
const _sfc_main = {
  __name: "ScheduleFormModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    editSchedule: { type: Object, default: null },
    prefillData: { type: Object, default: null },
    doctors: { type: Array, default: () => [] },
    covenants: { type: Array, default: () => [] },
    visitTypes: { type: Array, default: () => [] },
    storeUrl: { type: String, required: true },
    defaultDate: { type: String, default: "" },
    t: { type: Object, required: true }
  },
  emits: ["close", "saved"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const saving = ref(false);
    const errors = ref({});
    const form = ref({
      doctor_id: "",
      patient_id: "",
      full_name: "",
      date_time: "",
      telephone: "",
      cellphone: "",
      cellphone_whatsapp: false,
      notes: "",
      covenant_id: "",
      visit_id: "",
      resource_ids: [],
      waiting_list_id: "",
      use_recurrence: false,
      recurrence_type: "weekly",
      recurrence_until: ""
    });
    const slotPickerRef = ref(null);
    const patientSearch = ref("");
    const patientResults = ref([]);
    const patientDebounce = ref(null);
    const showQuickReg = ref(false);
    const quickName = ref("");
    function onPatientInput() {
      clearTimeout(patientDebounce.value);
      if (patientSearch.value.length < 2) {
        patientResults.value = [];
        return;
      }
      patientDebounce.value = setTimeout(searchPatients, 350);
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
    const resources = ref([]);
    const resourcesLoaded = ref(false);
    const resourcesLoading = ref(false);
    async function loadResources(dt) {
      var _a;
      if (!dt) return;
      resourcesLoading.value = true;
      resourcesLoaded.value = false;
      const qs = new URLSearchParams({ date_time: dt });
      if ((_a = props.editSchedule) == null ? void 0 : _a.id) qs.set("schedule_id", props.editSchedule.id);
      try {
        const res = await fetch(`/panel/schedules/resources?${qs}`, {
          headers: { Accept: "application/json" }
        });
        if (res.ok) {
          resources.value = (await res.json()).data ?? [];
          resourcesLoaded.value = true;
        }
      } catch {
      }
      resourcesLoading.value = false;
    }
    watch(
      () => form.value.date_time,
      (dt) => {
        if (dt) {
          loadResources(dt);
        } else {
          resources.value = [];
          resourcesLoaded.value = false;
        }
      }
    );
    watch(
      () => props.open,
      async (open) => {
        var _a, _b;
        if (!open) return;
        errors.value = {};
        saving.value = false;
        patientResults.value = [];
        showQuickReg.value = false;
        quickName.value = "";
        resources.value = [];
        resourcesLoaded.value = false;
        if (props.editSchedule) {
          const s = props.editSchedule;
          const dt = s.date_time ? s.date_time.substring(0, 16) : "";
          const date = dt.substring(0, 10);
          form.value = {
            doctor_id: s.doctor_id ?? "",
            patient_id: s.patient_id ?? "",
            full_name: s.full_name ?? "",
            date_time: dt,
            telephone: s.telephone ?? "",
            cellphone: s.cellphone ?? "",
            cellphone_whatsapp: s.cellphone_whatsapp ?? false,
            notes: s.notes ?? "",
            covenant_id: s.covenant_id ?? "",
            visit_id: s.visit_id ?? "",
            resource_ids: (s.resources ?? []).map((r) => r.id),
            waiting_list_id: "",
            use_recurrence: false,
            recurrence_type: "weekly",
            recurrence_until: ""
          };
          patientSearch.value = s.full_name ?? "";
          await nextTick();
          (_a = slotPickerRef.value) == null ? void 0 : _a.setDate(date);
        } else {
          const initDate = props.defaultDate || (/* @__PURE__ */ new Date()).toISOString().substring(0, 10);
          const p = props.prefillData;
          const prefillDatetime = (p == null ? void 0 : p.date_time) ?? "";
          form.value = {
            doctor_id: (p == null ? void 0 : p.doctor_id) ?? (props.doctors.length === 1 ? props.doctors[0].id : ""),
            patient_id: (p == null ? void 0 : p.patient_id) ?? "",
            full_name: (p == null ? void 0 : p.full_name) ?? "",
            date_time: prefillDatetime,
            telephone: (p == null ? void 0 : p.telephone) ?? "",
            cellphone: (p == null ? void 0 : p.cellphone) ?? "",
            cellphone_whatsapp: (p == null ? void 0 : p.cellphone_whatsapp) ?? false,
            notes: (p == null ? void 0 : p.notes) ?? "",
            covenant_id: (p == null ? void 0 : p.covenant_id) ?? "",
            visit_id: (p == null ? void 0 : p.visit_id) ?? "",
            resource_ids: [],
            waiting_list_id: (p == null ? void 0 : p.id) ?? "",
            use_recurrence: false,
            recurrence_type: "weekly",
            recurrence_until: ""
          };
          patientSearch.value = (p == null ? void 0 : p.full_name) ?? "";
          const resetDate = prefillDatetime ? prefillDatetime.substring(0, 10) : initDate;
          await nextTick();
          (_b = slotPickerRef.value) == null ? void 0 : _b.reset(resetDate);
        }
      }
    );
    const isEdit = computed(() => !!props.editSchedule);
    const submitUrl = computed(
      () => isEdit.value ? props.editSchedule.update_url ?? `/panel/schedules/${props.editSchedule.id}` : props.storeUrl
    );
    async function onSubmit() {
      var _a;
      if (saving.value) return;
      saving.value = true;
      errors.value = {};
      const body = {
        ...form.value,
        date_time: form.value.date_time || null,
        recurrence_type: form.value.use_recurrence ? form.value.recurrence_type : null,
        recurrence_until: form.value.use_recurrence ? form.value.recurrence_until : null
      };
      const res = await fetch(submitUrl.value, {
        method: isEdit.value ? "PUT" : "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? ""
        },
        body: JSON.stringify(body)
      });
      const json = await res.json();
      saving.value = false;
      if (res.ok) {
        if (window.showSuccessToast) window.showSuccessToast(json.message);
        emit("saved", json);
      } else if (res.status === 422) {
        errors.value = json.errors ?? {};
      } else {
        if (window.showErrorToast) window.showErrorToast(json.message ?? "Erro ao salvar.");
      }
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(CenteredModal, mergeProps({
        open: __props.open,
        size: "lg",
        onClose: ($event) => emit("close")
      }, _attrs), {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<h5 class="mb-0 fw-bold"${_scopeId}><i class="fas fa-calendar-plus me-2 text-primary"${_scopeId}></i> ${ssrInterpolate(isEdit.value ? __props.t.form_title : __props.t.btn_new)}</h5>`);
          } else {
            return [
              createVNode("h5", { class: "mb-0 fw-bold" }, [
                createVNode("i", { class: "fas fa-calendar-plus me-2 text-primary" }),
                createTextVNode(" " + toDisplayString(isEdit.value ? __props.t.form_title : __props.t.btn_new), 1)
              ])
            ];
          }
        }),
        footer: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<button type="button" class="btn btn-secondary"${_scopeId}>${ssrInterpolate(__props.t.form_cancel)}</button><button type="button" class="btn btn-primary px-4"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}${_scopeId}>`);
            if (saving.value) {
              _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(` ${ssrInterpolate(saving.value ? __props.t.saving : __props.t.form_save_link)}</button>`);
          } else {
            return [
              createVNode("button", {
                type: "button",
                class: "btn btn-secondary",
                onClick: ($event) => emit("close")
              }, toDisplayString(__props.t.form_cancel), 9, ["onClick"]),
              createVNode("button", {
                type: "button",
                class: "btn btn-primary px-4",
                disabled: saving.value,
                onClick: onSubmit
              }, [
                saving.value ? (openBlock(), createBlock("span", {
                  key: 0,
                  class: "spinner-border spinner-border-sm me-1"
                })) : createCommentVNode("", true),
                createTextVNode(" " + toDisplayString(saving.value ? __props.t.saving : __props.t.form_save_link), 1)
              ], 8, ["disabled"])
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b;
          if (_push2) {
            _push2(`<form${_scopeId}>`);
            if (__props.doctors.length !== 1) {
              _push2(`<div class="mb-3"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.form_doctor)} <span class="text-danger"${_scopeId}>*</span></label><select class="${ssrRenderClass([{ "is-invalid": errors.value.doctor_id }, "form-select"])}"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.doctor_id) ? ssrLooseContain(form.value.doctor_id, "") : ssrLooseEqual(form.value.doctor_id, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.form_select)}</option><!--[-->`);
              ssrRenderList(__props.doctors, (d) => {
                _push2(`<option${ssrRenderAttr("value", d.id)}${ssrIncludeBooleanAttr(Array.isArray(form.value.doctor_id) ? ssrLooseContain(form.value.doctor_id, d.id) : ssrLooseEqual(form.value.doctor_id, d.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(d.name)}</option>`);
              });
              _push2(`<!--]--></select>`);
              if (errors.value.doctor_id) {
                _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(errors.value.doctor_id[0])}</div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="mb-3"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.form_date_time)} <span class="text-danger"${_scopeId}>*</span></label>`);
            if (errors.value.date_time) {
              _push2(`<div class="text-danger small mb-2"${_scopeId}><i class="fas fa-exclamation-circle me-1"${_scopeId}></i>${ssrInterpolate(errors.value.date_time[0])}</div>`);
            } else {
              _push2(`<!---->`);
            }
            if (__props.open) {
              _push2(ssrRenderComponent(_sfc_main$2, {
                ref_key: "slotPickerRef",
                ref: slotPickerRef,
                modelValue: form.value.date_time,
                "onUpdate:modelValue": ($event) => form.value.date_time = $event,
                "doctor-id": form.value.doctor_id,
                "schedule-id": ((_a = __props.editSchedule) == null ? void 0 : _a.id) ?? null,
                t: __props.t
              }, null, _parent2, _scopeId));
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="mb-3"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.form_patient)}</label><div class="position-relative"${_scopeId}><input${ssrRenderAttr("value", patientSearch.value)} type="text" class="form-control"${ssrRenderAttr("placeholder", __props.t.form_patient_search)} autocomplete="off"${_scopeId}>`);
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
              _push2(`<!--]--></ul>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="mt-1"${_scopeId}>`);
            if (!showQuickReg.value) {
              _push2(`<button type="button" class="btn btn-link btn-sm p-0 text-decoration-none"${_scopeId}><i class="fas fa-plus me-1"${_scopeId}></i>${ssrInterpolate(__props.t.form_register)}</button>`);
            } else {
              _push2(`<div class="d-flex gap-2 mt-1"${_scopeId}><input${ssrRenderAttr("value", quickName.value)} type="text" class="form-control form-control-sm"${ssrRenderAttr("placeholder", __props.t.form_patient_name)}${_scopeId}><button type="button" class="btn btn-sm btn-success"${_scopeId}>OK</button><button type="button" class="btn btn-sm btn-secondary"${_scopeId}><i class="fas fa-times"${_scopeId}></i></button></div>`);
            }
            _push2(`</div></div>`);
            if (!form.value.patient_id) {
              _push2(`<div class="mb-3"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.form_full_name)}</label><input${ssrRenderAttr("value", form.value.full_name)} type="text" class="${ssrRenderClass([{ "is-invalid": errors.value.full_name }, "form-control"])}"${_scopeId}>`);
              if (errors.value.full_name) {
                _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(errors.value.full_name[0])}</div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="row g-2 mb-3"${_scopeId}><div class="col-6"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.form_covenant)}</label><select class="form-select"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.covenant_id) ? ssrLooseContain(form.value.covenant_id, "") : ssrLooseEqual(form.value.covenant_id, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.form_none)}</option><!--[-->`);
            ssrRenderList(__props.covenants, (c) => {
              _push2(`<option${ssrRenderAttr("value", c.id)}${ssrIncludeBooleanAttr(Array.isArray(form.value.covenant_id) ? ssrLooseContain(form.value.covenant_id, c.id) : ssrLooseEqual(form.value.covenant_id, c.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(c.name)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-6"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.form_visit_type)}</label><select class="form-select"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.visit_id) ? ssrLooseContain(form.value.visit_id, "") : ssrLooseEqual(form.value.visit_id, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.form_none)}</option><!--[-->`);
            ssrRenderList(__props.visitTypes, (v) => {
              _push2(`<option${ssrRenderAttr("value", v.id)}${ssrIncludeBooleanAttr(Array.isArray(form.value.visit_id) ? ssrLooseContain(form.value.visit_id, v.id) : ssrLooseEqual(form.value.visit_id, v.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(v.name)}</option>`);
            });
            _push2(`<!--]--></select></div></div>`);
            if (form.value.date_time) {
              _push2(`<div class="mb-3"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.form_resources)} <small class="text-muted fw-normal"${_scopeId}>${ssrInterpolate(__props.t.form_resources_opt)}</small></label>`);
              if (resourcesLoading.value) {
                _push2(`<div class="py-1 d-flex align-items-center gap-2"${_scopeId}><span class="spinner-border spinner-border-sm text-secondary"${_scopeId}></span><span class="text-muted small"${_scopeId}>${ssrInterpolate(__props.t.loading)}</span></div>`);
              } else if (resourcesLoaded.value && resources.value.length === 0) {
                _push2(`<p class="text-muted small mb-0"${_scopeId}><i class="fas fa-info-circle me-1"${_scopeId}></i>${ssrInterpolate(__props.t.form_no_resources)}</p>`);
              } else {
                _push2(`<div class="d-flex flex-wrap gap-3"${_scopeId}><!--[-->`);
                ssrRenderList(resources.value, (r) => {
                  _push2(`<div class="form-check"${_scopeId}><input${ssrRenderAttr("id", `res-${r.id}`)}${ssrIncludeBooleanAttr(Array.isArray(form.value.resource_ids) ? ssrLooseContain(form.value.resource_ids, r.id) : form.value.resource_ids) ? " checked" : ""} type="checkbox" class="form-check-input"${ssrRenderAttr("value", r.id)}${ssrIncludeBooleanAttr(!r.available && !form.value.resource_ids.includes(r.id)) ? " disabled" : ""}${_scopeId}><label${ssrRenderAttr("for", `res-${r.id}`)} class="form-check-label small"${_scopeId}>${ssrInterpolate(r.name)} `);
                  if (!r.available && !form.value.resource_ids.includes(r.id)) {
                    _push2(`<span class="badge bg-danger ms-1" style="${ssrRenderStyle({ "font-size": ".65rem" })}"${_scopeId}>${ssrInterpolate(__props.t.form_busy)}</span>`);
                  } else if (r.available && !form.value.resource_ids.includes(r.id)) {
                    _push2(`<span class="badge bg-success ms-1" style="${ssrRenderStyle({ "font-size": ".65rem" })}"${_scopeId}>${ssrInterpolate(__props.t.form_free)}</span>`);
                  } else {
                    _push2(`<!---->`);
                  }
                  _push2(`</label></div>`);
                });
                _push2(`<!--]--></div>`);
              }
              _push2(`</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="row g-2 mb-3"${_scopeId}><div class="col-6"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.form_telephone)}</label><input${ssrRenderAttr("value", form.value.telephone)} type="text" class="form-control"${_scopeId}></div><div class="col-6"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.form_cellphone)}</label><input${ssrRenderAttr("value", form.value.cellphone)} type="text" class="form-control"${_scopeId}></div></div><div class="form-check mb-3"${_scopeId}><input id="whatsapp"${ssrIncludeBooleanAttr(Array.isArray(form.value.cellphone_whatsapp) ? ssrLooseContain(form.value.cellphone_whatsapp, null) : form.value.cellphone_whatsapp) ? " checked" : ""} type="checkbox" class="form-check-input"${_scopeId}><label for="whatsapp" class="form-check-label small"${_scopeId}><i class="fab fa-whatsapp text-success me-1"${_scopeId}></i>${ssrInterpolate(__props.t.form_whatsapp)}</label></div><div class="mb-3"${_scopeId}><label class="form-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.form_notes)}</label><textarea class="form-control" rows="3"${ssrRenderAttr("placeholder", __props.t.form_notes_ph)}${_scopeId}>${ssrInterpolate(form.value.notes)}</textarea></div>`);
            if (!isEdit.value) {
              _push2(`<div class="mb-3"${_scopeId}><div class="form-check"${_scopeId}><input id="use-recurrence"${ssrIncludeBooleanAttr(Array.isArray(form.value.use_recurrence) ? ssrLooseContain(form.value.use_recurrence, null) : form.value.use_recurrence) ? " checked" : ""} type="checkbox" class="form-check-input"${_scopeId}><label for="use-recurrence" class="form-check-label fw-semibold"${_scopeId}>${ssrInterpolate(__props.t.form_recurrence)}</label></div>`);
              if (form.value.use_recurrence) {
                _push2(`<div class="mt-2 row g-2"${_scopeId}><div class="col-6"${_scopeId}><label class="form-label small"${_scopeId}>${ssrInterpolate(__props.t.form_rec_freq)}</label><select class="form-select form-select-sm"${_scopeId}><option value="weekly"${ssrIncludeBooleanAttr(Array.isArray(form.value.recurrence_type) ? ssrLooseContain(form.value.recurrence_type, "weekly") : ssrLooseEqual(form.value.recurrence_type, "weekly")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.form_weekly)}</option><option value="monthly"${ssrIncludeBooleanAttr(Array.isArray(form.value.recurrence_type) ? ssrLooseContain(form.value.recurrence_type, "monthly") : ssrLooseEqual(form.value.recurrence_type, "monthly")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.form_monthly)}</option></select></div><div class="col-6"${_scopeId}><label class="form-label small"${_scopeId}>${ssrInterpolate(__props.t.form_rec_until)}</label><input${ssrRenderAttr("value", form.value.recurrence_until)} type="date" class="form-control form-control-sm"${_scopeId}></div><div class="col-12"${_scopeId}><small class="text-muted"${_scopeId}><i class="fas fa-info-circle me-1"${_scopeId}></i>${ssrInterpolate(__props.t.form_rec_hint)}</small></div></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</form>`);
          } else {
            return [
              createVNode("form", {
                onSubmit: withModifiers(onSubmit, ["prevent"])
              }, [
                __props.doctors.length !== 1 ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "mb-3"
                }, [
                  createVNode("label", { class: "form-label fw-semibold" }, [
                    createTextVNode(toDisplayString(__props.t.form_doctor) + " ", 1),
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
                ])) : createCommentVNode("", true),
                createVNode("div", { class: "mb-3" }, [
                  createVNode("label", { class: "form-label fw-semibold" }, [
                    createTextVNode(toDisplayString(__props.t.form_date_time) + " ", 1),
                    createVNode("span", { class: "text-danger" }, "*")
                  ]),
                  errors.value.date_time ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "text-danger small mb-2"
                  }, [
                    createVNode("i", { class: "fas fa-exclamation-circle me-1" }),
                    createTextVNode(toDisplayString(errors.value.date_time[0]), 1)
                  ])) : createCommentVNode("", true),
                  __props.open ? (openBlock(), createBlock(_sfc_main$2, {
                    key: 1,
                    ref_key: "slotPickerRef",
                    ref: slotPickerRef,
                    modelValue: form.value.date_time,
                    "onUpdate:modelValue": ($event) => form.value.date_time = $event,
                    "doctor-id": form.value.doctor_id,
                    "schedule-id": ((_b = __props.editSchedule) == null ? void 0 : _b.id) ?? null,
                    t: __props.t
                  }, null, 8, ["modelValue", "onUpdate:modelValue", "doctor-id", "schedule-id", "t"])) : createCommentVNode("", true)
                ]),
                createVNode("div", { class: "mb-3" }, [
                  createVNode("label", { class: "form-label fw-semibold" }, toDisplayString(__props.t.form_patient), 1),
                  createVNode("div", { class: "position-relative" }, [
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => patientSearch.value = $event,
                      type: "text",
                      class: "form-control",
                      placeholder: __props.t.form_patient_search,
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
                      }), 128))
                    ])) : createCommentVNode("", true)
                  ]),
                  createVNode("div", { class: "mt-1" }, [
                    !showQuickReg.value ? (openBlock(), createBlock("button", {
                      key: 0,
                      type: "button",
                      class: "btn btn-link btn-sm p-0 text-decoration-none",
                      onClick: ($event) => showQuickReg.value = true
                    }, [
                      createVNode("i", { class: "fas fa-plus me-1" }),
                      createTextVNode(toDisplayString(__props.t.form_register), 1)
                    ], 8, ["onClick"])) : (openBlock(), createBlock("div", {
                      key: 1,
                      class: "d-flex gap-2 mt-1"
                    }, [
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => quickName.value = $event,
                        type: "text",
                        class: "form-control form-control-sm",
                        placeholder: __props.t.form_patient_name,
                        onKeyup: withKeys(quickRegister, ["enter"])
                      }, null, 40, ["onUpdate:modelValue", "placeholder"]), [
                        [vModelText, quickName.value]
                      ]),
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-sm btn-success",
                        onClick: quickRegister
                      }, "OK"),
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-sm btn-secondary",
                        onClick: ($event) => {
                          showQuickReg.value = false;
                          quickName.value = "";
                        }
                      }, [
                        createVNode("i", { class: "fas fa-times" })
                      ], 8, ["onClick"])
                    ]))
                  ])
                ]),
                !form.value.patient_id ? (openBlock(), createBlock("div", {
                  key: 1,
                  class: "mb-3"
                }, [
                  createVNode("label", { class: "form-label fw-semibold" }, toDisplayString(__props.t.form_full_name), 1),
                  withDirectives(createVNode("input", {
                    "onUpdate:modelValue": ($event) => form.value.full_name = $event,
                    type: "text",
                    class: ["form-control", { "is-invalid": errors.value.full_name }]
                  }, null, 10, ["onUpdate:modelValue"]), [
                    [vModelText, form.value.full_name]
                  ]),
                  errors.value.full_name ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "invalid-feedback"
                  }, toDisplayString(errors.value.full_name[0]), 1)) : createCommentVNode("", true)
                ])) : createCommentVNode("", true),
                createVNode("div", { class: "row g-2 mb-3" }, [
                  createVNode("div", { class: "col-6" }, [
                    createVNode("label", { class: "form-label fw-semibold" }, toDisplayString(__props.t.form_covenant), 1),
                    withDirectives(createVNode("select", {
                      "onUpdate:modelValue": ($event) => form.value.covenant_id = $event,
                      class: "form-select"
                    }, [
                      createVNode("option", { value: "" }, toDisplayString(__props.t.form_none), 1),
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
                    createVNode("label", { class: "form-label fw-semibold" }, toDisplayString(__props.t.form_visit_type), 1),
                    withDirectives(createVNode("select", {
                      "onUpdate:modelValue": ($event) => form.value.visit_id = $event,
                      class: "form-select"
                    }, [
                      createVNode("option", { value: "" }, toDisplayString(__props.t.form_none), 1),
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
                form.value.date_time ? (openBlock(), createBlock("div", {
                  key: 2,
                  class: "mb-3"
                }, [
                  createVNode("label", { class: "form-label fw-semibold" }, [
                    createTextVNode(toDisplayString(__props.t.form_resources) + " ", 1),
                    createVNode("small", { class: "text-muted fw-normal" }, toDisplayString(__props.t.form_resources_opt), 1)
                  ]),
                  resourcesLoading.value ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "py-1 d-flex align-items-center gap-2"
                  }, [
                    createVNode("span", { class: "spinner-border spinner-border-sm text-secondary" }),
                    createVNode("span", { class: "text-muted small" }, toDisplayString(__props.t.loading), 1)
                  ])) : resourcesLoaded.value && resources.value.length === 0 ? (openBlock(), createBlock("p", {
                    key: 1,
                    class: "text-muted small mb-0"
                  }, [
                    createVNode("i", { class: "fas fa-info-circle me-1" }),
                    createTextVNode(toDisplayString(__props.t.form_no_resources), 1)
                  ])) : (openBlock(), createBlock("div", {
                    key: 2,
                    class: "d-flex flex-wrap gap-3"
                  }, [
                    (openBlock(true), createBlock(Fragment, null, renderList(resources.value, (r) => {
                      return openBlock(), createBlock("div", {
                        key: r.id,
                        class: "form-check"
                      }, [
                        withDirectives(createVNode("input", {
                          id: `res-${r.id}`,
                          "onUpdate:modelValue": ($event) => form.value.resource_ids = $event,
                          type: "checkbox",
                          class: "form-check-input",
                          value: r.id,
                          disabled: !r.available && !form.value.resource_ids.includes(r.id)
                        }, null, 8, ["id", "onUpdate:modelValue", "value", "disabled"]), [
                          [vModelCheckbox, form.value.resource_ids]
                        ]),
                        createVNode("label", {
                          for: `res-${r.id}`,
                          class: "form-check-label small"
                        }, [
                          createTextVNode(toDisplayString(r.name) + " ", 1),
                          !r.available && !form.value.resource_ids.includes(r.id) ? (openBlock(), createBlock("span", {
                            key: 0,
                            class: "badge bg-danger ms-1",
                            style: { "font-size": ".65rem" }
                          }, toDisplayString(__props.t.form_busy), 1)) : r.available && !form.value.resource_ids.includes(r.id) ? (openBlock(), createBlock("span", {
                            key: 1,
                            class: "badge bg-success ms-1",
                            style: { "font-size": ".65rem" }
                          }, toDisplayString(__props.t.form_free), 1)) : createCommentVNode("", true)
                        ], 8, ["for"])
                      ]);
                    }), 128))
                  ]))
                ])) : createCommentVNode("", true),
                createVNode("div", { class: "row g-2 mb-3" }, [
                  createVNode("div", { class: "col-6" }, [
                    createVNode("label", { class: "form-label fw-semibold" }, toDisplayString(__props.t.form_telephone), 1),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => form.value.telephone = $event,
                      type: "text",
                      class: "form-control"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelText, form.value.telephone]
                    ])
                  ]),
                  createVNode("div", { class: "col-6" }, [
                    createVNode("label", { class: "form-label fw-semibold" }, toDisplayString(__props.t.form_cellphone), 1),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => form.value.cellphone = $event,
                      type: "text",
                      class: "form-control"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelText, form.value.cellphone]
                    ])
                  ])
                ]),
                createVNode("div", { class: "form-check mb-3" }, [
                  withDirectives(createVNode("input", {
                    id: "whatsapp",
                    "onUpdate:modelValue": ($event) => form.value.cellphone_whatsapp = $event,
                    type: "checkbox",
                    class: "form-check-input"
                  }, null, 8, ["onUpdate:modelValue"]), [
                    [vModelCheckbox, form.value.cellphone_whatsapp]
                  ]),
                  createVNode("label", {
                    for: "whatsapp",
                    class: "form-check-label small"
                  }, [
                    createVNode("i", { class: "fab fa-whatsapp text-success me-1" }),
                    createTextVNode(toDisplayString(__props.t.form_whatsapp), 1)
                  ])
                ]),
                createVNode("div", { class: "mb-3" }, [
                  createVNode("label", { class: "form-label fw-semibold" }, toDisplayString(__props.t.form_notes), 1),
                  withDirectives(createVNode("textarea", {
                    "onUpdate:modelValue": ($event) => form.value.notes = $event,
                    class: "form-control",
                    rows: "3",
                    placeholder: __props.t.form_notes_ph
                  }, null, 8, ["onUpdate:modelValue", "placeholder"]), [
                    [vModelText, form.value.notes]
                  ])
                ]),
                !isEdit.value ? (openBlock(), createBlock("div", {
                  key: 3,
                  class: "mb-3"
                }, [
                  createVNode("div", { class: "form-check" }, [
                    withDirectives(createVNode("input", {
                      id: "use-recurrence",
                      "onUpdate:modelValue": ($event) => form.value.use_recurrence = $event,
                      type: "checkbox",
                      class: "form-check-input"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelCheckbox, form.value.use_recurrence]
                    ]),
                    createVNode("label", {
                      for: "use-recurrence",
                      class: "form-check-label fw-semibold"
                    }, toDisplayString(__props.t.form_recurrence), 1)
                  ]),
                  form.value.use_recurrence ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "mt-2 row g-2"
                  }, [
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label small" }, toDisplayString(__props.t.form_rec_freq), 1),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => form.value.recurrence_type = $event,
                        class: "form-select form-select-sm"
                      }, [
                        createVNode("option", { value: "weekly" }, toDisplayString(__props.t.form_weekly), 1),
                        createVNode("option", { value: "monthly" }, toDisplayString(__props.t.form_monthly), 1)
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, form.value.recurrence_type]
                      ])
                    ]),
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label small" }, toDisplayString(__props.t.form_rec_until), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => form.value.recurrence_until = $event,
                        type: "date",
                        class: "form-control form-control-sm"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, form.value.recurrence_until]
                      ])
                    ]),
                    createVNode("div", { class: "col-12" }, [
                      createVNode("small", { class: "text-muted" }, [
                        createVNode("i", { class: "fas fa-info-circle me-1" }),
                        createTextVNode(toDisplayString(__props.t.form_rec_hint), 1)
                      ])
                    ])
                  ])) : createCommentVNode("", true)
                ])) : createCommentVNode("", true)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Schedules/ScheduleFormModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
