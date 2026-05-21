import { ref, computed, watch, mergeProps, withCtx, withDirectives, createVNode, createTextVNode, toDisplayString, vModelText, openBlock, createBlock, createCommentVNode, Fragment, renderList, vModelSelect, vShow, vModelCheckbox, Transition, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderStyle, ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "ReportSettingFormModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    recordId: { type: String, default: null },
    categories: { type: Array, default: () => [] },
    paperSizes: { type: Array, default: () => [] },
    fontFamilies: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close", "saved"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const loading = ref(false);
    const saving = ref(false);
    const errors = ref({});
    const activeTab = ref("general");
    const defaultForm = () => ({
      title: "",
      description: "",
      report_category_id: "",
      paper_size: "A4",
      font_family: "Arial",
      font_size: 11,
      margin_top: 2,
      margin_right: 2,
      margin_bottom: 2,
      margin_left: 2,
      active: true,
      show_header: true,
      header_show_logo: true,
      header_show_name: true,
      header_show_address: false,
      header_show_phone: false,
      show_signature: true,
      signature_show_name: true,
      signature_show_crm: true,
      signature_show_rqe: true,
      show_footer: false,
      footer_text: "",
      footer_show_address: false,
      footer_show_phone: false
    });
    const form = ref(defaultForm());
    const isEdit = computed(() => !!props.recordId);
    const panelTitle = computed(() => isEdit.value ? props.t.form_title_edit : props.t.form_title_create);
    function resetForm() {
      form.value = defaultForm();
      errors.value = {};
      activeTab.value = "general";
    }
    async function loadRecord(id) {
      loading.value = true;
      try {
        const res = await fetch(route("manager.report-settings.show", id));
        const json = await res.json();
        const d = json.data;
        form.value = {
          title: d.title ?? "",
          description: d.description ?? "",
          report_category_id: d.report_category_id ?? "",
          paper_size: d.paper_size ?? "A4",
          font_family: d.font_family ?? "Arial",
          font_size: d.font_size ?? 11,
          margin_top: d.margin_top ?? 2,
          margin_right: d.margin_right ?? 2,
          margin_bottom: d.margin_bottom ?? 2,
          margin_left: d.margin_left ?? 2,
          active: d.active ?? true,
          show_header: d.show_header ?? true,
          header_show_logo: d.header_show_logo ?? true,
          header_show_name: d.header_show_name ?? true,
          header_show_address: d.header_show_address ?? false,
          header_show_phone: d.header_show_phone ?? false,
          show_signature: d.show_signature ?? true,
          signature_show_name: d.signature_show_name ?? true,
          signature_show_crm: d.signature_show_crm ?? true,
          signature_show_rqe: d.signature_show_rqe ?? true,
          show_footer: d.show_footer ?? false,
          footer_text: d.footer_text ?? "",
          footer_show_address: d.footer_show_address ?? false,
          footer_show_phone: d.footer_show_phone ?? false
        };
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.open, (val) => {
      if (val) {
        resetForm();
        if (props.recordId) loadRecord(props.recordId);
      }
    });
    async function submit() {
      var _a;
      saving.value = true;
      errors.value = {};
      try {
        const url = isEdit.value ? route("manager.report-settings.update", props.recordId) : route("manager.report-settings.store");
        const method = isEdit.value ? "PUT" : "POST";
        const res = await fetch(url, {
          method,
          headers: {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? ""
          },
          body: JSON.stringify(form.value)
        });
        const json = await res.json();
        if (!res.ok) {
          errors.value = json.errors ?? {};
          if (Object.keys(errors.value).some((k) => ["title", "description", "report_category_id", "paper_size", "font_family", "font_size", "margin_top", "margin_right", "margin_bottom", "margin_left", "active"].includes(k))) {
            activeTab.value = "general";
          } else if (Object.keys(errors.value).some((k) => k.startsWith("header"))) {
            activeTab.value = "header";
          } else if (Object.keys(errors.value).some((k) => k.startsWith("signature"))) {
            activeTab.value = "signature";
          } else if (Object.keys(errors.value).some((k) => k.startsWith("footer"))) {
            activeTab.value = "footer";
          }
          return;
        }
        emit("saved");
        emit("close");
        router.reload({ only: ["reportSettings", "total"] });
      } finally {
        saving.value = false;
      }
    }
    function err(field) {
      const e = errors.value[field];
      return Array.isArray(e) ? e[0] : e ?? "";
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(OffcanvasPanel, mergeProps({
        open: __props.open,
        width: 620,
        loading: loading.value,
        "loading-label": __props.t.loading,
        onClose: ($event) => _ctx.$emit("close")
      }, _attrs), {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div data-v-9761ca84${_scopeId}><h5 class="mb-0 fw-semibold" data-v-9761ca84${_scopeId}><i class="ti ti-file-text me-2 text-primary" data-v-9761ca84${_scopeId}></i>${ssrInterpolate(panelTitle.value)}</h5></div>`);
          } else {
            return [
              createVNode("div", null, [
                createVNode("h5", { class: "mb-0 fw-semibold" }, [
                  createVNode("i", { class: "ti ti-file-text me-2 text-primary" }),
                  createTextVNode(toDisplayString(panelTitle.value), 1)
                ])
              ])
            ];
          }
        }),
        tabs: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<ul class="nav nav-tabs border-0 pt-1" data-v-9761ca84${_scopeId}><li class="nav-item" data-v-9761ca84${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "general" }, "nav-link"])}" data-v-9761ca84${_scopeId}><i class="ti ti-settings me-1" data-v-9761ca84${_scopeId}></i>${ssrInterpolate(__props.t.tab_general)}</button></li><li class="nav-item" data-v-9761ca84${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "header" }, "nav-link"])}" data-v-9761ca84${_scopeId}><i class="ti ti-layout-navbar me-1" data-v-9761ca84${_scopeId}></i>${ssrInterpolate(__props.t.tab_header)}</button></li><li class="nav-item" data-v-9761ca84${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "signature" }, "nav-link"])}" data-v-9761ca84${_scopeId}><i class="ti ti-writing me-1" data-v-9761ca84${_scopeId}></i>${ssrInterpolate(__props.t.tab_signature)}</button></li><li class="nav-item" data-v-9761ca84${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "footer" }, "nav-link"])}" data-v-9761ca84${_scopeId}><i class="ti ti-layout-bottombar me-1" data-v-9761ca84${_scopeId}></i>${ssrInterpolate(__props.t.tab_footer)}</button></li></ul>`);
          } else {
            return [
              createVNode("ul", { class: "nav nav-tabs border-0 pt-1" }, [
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    class: ["nav-link", { active: activeTab.value === "general" }],
                    onClick: ($event) => activeTab.value = "general"
                  }, [
                    createVNode("i", { class: "ti ti-settings me-1" }),
                    createTextVNode(toDisplayString(__props.t.tab_general), 1)
                  ], 10, ["onClick"])
                ]),
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    class: ["nav-link", { active: activeTab.value === "header" }],
                    onClick: ($event) => activeTab.value = "header"
                  }, [
                    createVNode("i", { class: "ti ti-layout-navbar me-1" }),
                    createTextVNode(toDisplayString(__props.t.tab_header), 1)
                  ], 10, ["onClick"])
                ]),
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    class: ["nav-link", { active: activeTab.value === "signature" }],
                    onClick: ($event) => activeTab.value = "signature"
                  }, [
                    createVNode("i", { class: "ti ti-writing me-1" }),
                    createTextVNode(toDisplayString(__props.t.tab_signature), 1)
                  ], 10, ["onClick"])
                ]),
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    class: ["nav-link", { active: activeTab.value === "footer" }],
                    onClick: ($event) => activeTab.value = "footer"
                  }, [
                    createVNode("i", { class: "ti ti-layout-bottombar me-1" }),
                    createTextVNode(toDisplayString(__props.t.tab_footer), 1)
                  ], 10, ["onClick"])
                ])
              ])
            ];
          }
        }),
        footer: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<button type="button" class="btn btn-secondary" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.cancel)}</button><button type="button" class="btn btn-primary"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""} data-v-9761ca84${_scopeId}>`);
            if (saving.value) {
              _push2(`<span class="spinner-border spinner-border-sm me-1" data-v-9761ca84${_scopeId}></span>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(` ${ssrInterpolate(saving.value ? __props.t.saving : __props.t.save)}</button>`);
          } else {
            return [
              createVNode("button", {
                type: "button",
                class: "btn btn-secondary",
                onClick: ($event) => _ctx.$emit("close")
              }, toDisplayString(__props.t.cancel), 9, ["onClick"]),
              createVNode("button", {
                type: "button",
                class: "btn btn-primary",
                disabled: saving.value,
                onClick: submit
              }, [
                saving.value ? (openBlock(), createBlock("span", {
                  key: 0,
                  class: "spinner-border spinner-border-sm me-1"
                })) : createCommentVNode("", true),
                createTextVNode(" " + toDisplayString(saving.value ? __props.t.saving : __props.t.save), 1)
              ], 8, ["disabled"])
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="row g-3" style="${ssrRenderStyle(activeTab.value === "general" ? null : { display: "none" })}" data-v-9761ca84${_scopeId}><div class="col-12" data-v-9761ca84${_scopeId}><label class="form-label" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_title)} <span class="text-danger" data-v-9761ca84${_scopeId}>*</span></label><input${ssrRenderAttr("value", form.value.title)} type="text" class="${ssrRenderClass([{ "is-invalid": err("title") }, "form-control"])}" maxlength="255" data-v-9761ca84${_scopeId}>`);
            if (err("title")) {
              _push2(`<div class="invalid-feedback" data-v-9761ca84${_scopeId}>${ssrInterpolate(err("title"))}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-sm-6" data-v-9761ca84${_scopeId}><label class="form-label" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_category)}</label><select class="${ssrRenderClass([{ "is-invalid": err("report_category_id") }, "form-select"])}" data-v-9761ca84${_scopeId}><option value="" data-v-9761ca84${ssrIncludeBooleanAttr(Array.isArray(form.value.report_category_id) ? ssrLooseContain(form.value.report_category_id, "") : ssrLooseEqual(form.value.report_category_id, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.select_option)}</option><!--[-->`);
            ssrRenderList(__props.categories, (cat) => {
              _push2(`<option${ssrRenderAttr("value", cat.id)} data-v-9761ca84${ssrIncludeBooleanAttr(Array.isArray(form.value.report_category_id) ? ssrLooseContain(form.value.report_category_id, cat.id) : ssrLooseEqual(form.value.report_category_id, cat.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(cat.name)}</option>`);
            });
            _push2(`<!--]--></select>`);
            if (err("report_category_id")) {
              _push2(`<div class="invalid-feedback" data-v-9761ca84${_scopeId}>${ssrInterpolate(err("report_category_id"))}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-sm-6" data-v-9761ca84${_scopeId}><label class="form-label" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_paper_size)}</label><select class="form-select" data-v-9761ca84${_scopeId}><!--[-->`);
            ssrRenderList(__props.paperSizes, (sz) => {
              _push2(`<option${ssrRenderAttr("value", sz)} data-v-9761ca84${ssrIncludeBooleanAttr(Array.isArray(form.value.paper_size) ? ssrLooseContain(form.value.paper_size, sz) : ssrLooseEqual(form.value.paper_size, sz)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(sz)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-sm-6" data-v-9761ca84${_scopeId}><label class="form-label" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_font_family)}</label><select class="form-select" data-v-9761ca84${_scopeId}><!--[-->`);
            ssrRenderList(__props.fontFamilies, (f) => {
              _push2(`<option${ssrRenderAttr("value", f)} data-v-9761ca84${ssrIncludeBooleanAttr(Array.isArray(form.value.font_family) ? ssrLooseContain(form.value.font_family, f) : ssrLooseEqual(form.value.font_family, f)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(f)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-sm-3" data-v-9761ca84${_scopeId}><label class="form-label" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_font_size)}</label><input${ssrRenderAttr("value", form.value.font_size)} type="number" min="8" max="24" class="form-control" data-v-9761ca84${_scopeId}></div><div class="col-sm-3" data-v-9761ca84${_scopeId}><label class="form-label" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_active)}</label><select class="form-select" data-v-9761ca84${_scopeId}><option${ssrRenderAttr("value", true)} data-v-9761ca84${ssrIncludeBooleanAttr(Array.isArray(form.value.active) ? ssrLooseContain(form.value.active, true) : ssrLooseEqual(form.value.active, true)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.yes)}</option><option${ssrRenderAttr("value", false)} data-v-9761ca84${ssrIncludeBooleanAttr(Array.isArray(form.value.active) ? ssrLooseContain(form.value.active, false) : ssrLooseEqual(form.value.active, false)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.no)}</option></select></div><div class="col-12" data-v-9761ca84${_scopeId}><label class="form-label" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_description)}</label><textarea class="${ssrRenderClass([{ "is-invalid": err("description") }, "form-control"])}" rows="2" maxlength="1000" data-v-9761ca84${_scopeId}>${ssrInterpolate(form.value.description)}</textarea>`);
            if (err("description")) {
              _push2(`<div class="invalid-feedback" data-v-9761ca84${_scopeId}>${ssrInterpolate(err("description"))}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-12" data-v-9761ca84${_scopeId}><p class="fw-semibold text-muted small text-uppercase mb-2" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_margins)}</p><div class="row g-2" data-v-9761ca84${_scopeId}><div class="col-6 col-sm-3" data-v-9761ca84${_scopeId}><label class="form-label small" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_margin_top)}</label><input${ssrRenderAttr("value", form.value.margin_top)} type="number" step="0.5" min="0" max="10" class="form-control form-control-sm" data-v-9761ca84${_scopeId}></div><div class="col-6 col-sm-3" data-v-9761ca84${_scopeId}><label class="form-label small" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_margin_right)}</label><input${ssrRenderAttr("value", form.value.margin_right)} type="number" step="0.5" min="0" max="10" class="form-control form-control-sm" data-v-9761ca84${_scopeId}></div><div class="col-6 col-sm-3" data-v-9761ca84${_scopeId}><label class="form-label small" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_margin_bottom)}</label><input${ssrRenderAttr("value", form.value.margin_bottom)} type="number" step="0.5" min="0" max="10" class="form-control form-control-sm" data-v-9761ca84${_scopeId}></div><div class="col-6 col-sm-3" data-v-9761ca84${_scopeId}><label class="form-label small" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_margin_left)}</label><input${ssrRenderAttr("value", form.value.margin_left)} type="number" step="0.5" min="0" max="10" class="form-control form-control-sm" data-v-9761ca84${_scopeId}></div></div></div></div><div style="${ssrRenderStyle(activeTab.value === "header" ? null : { display: "none" })}" data-v-9761ca84${_scopeId}><div class="d-flex align-items-center justify-content-between mb-3" data-v-9761ca84${_scopeId}><p class="text-muted small mb-0" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.header_desc)}</p><div class="form-check form-switch ms-3 mb-0 flex-shrink-0" data-v-9761ca84${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(form.value.show_header) ? ssrLooseContain(form.value.show_header, null) : form.value.show_header) ? " checked" : ""} class="form-check-input" type="checkbox" role="switch" id="show_header" data-v-9761ca84${_scopeId}><label class="form-check-label fw-semibold" for="show_header" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_show_header)}</label></div></div>`);
            if (form.value.show_header) {
              _push2(`<div class="row g-3" data-v-9761ca84${_scopeId}><div class="col-6" data-v-9761ca84${_scopeId}><div class="form-check form-switch" data-v-9761ca84${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(form.value.header_show_logo) ? ssrLooseContain(form.value.header_show_logo, null) : form.value.header_show_logo) ? " checked" : ""} class="form-check-input" type="checkbox" role="switch" id="header_show_logo" data-v-9761ca84${_scopeId}><label class="form-check-label" for="header_show_logo" data-v-9761ca84${_scopeId}><i class="ti ti-photo me-1 text-muted" data-v-9761ca84${_scopeId}></i>${ssrInterpolate(__props.t.field_header_show_logo)}</label></div></div><div class="col-6" data-v-9761ca84${_scopeId}><div class="form-check form-switch" data-v-9761ca84${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(form.value.header_show_name) ? ssrLooseContain(form.value.header_show_name, null) : form.value.header_show_name) ? " checked" : ""} class="form-check-input" type="checkbox" role="switch" id="header_show_name" data-v-9761ca84${_scopeId}><label class="form-check-label" for="header_show_name" data-v-9761ca84${_scopeId}><i class="ti ti-building-hospital me-1 text-muted" data-v-9761ca84${_scopeId}></i>${ssrInterpolate(__props.t.field_header_show_name)}</label></div></div><div class="col-6" data-v-9761ca84${_scopeId}><div class="form-check form-switch" data-v-9761ca84${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(form.value.header_show_address) ? ssrLooseContain(form.value.header_show_address, null) : form.value.header_show_address) ? " checked" : ""} class="form-check-input" type="checkbox" role="switch" id="header_show_address" data-v-9761ca84${_scopeId}><label class="form-check-label" for="header_show_address" data-v-9761ca84${_scopeId}><i class="ti ti-map-pin me-1 text-muted" data-v-9761ca84${_scopeId}></i>${ssrInterpolate(__props.t.field_header_show_address)}</label></div></div><div class="col-6" data-v-9761ca84${_scopeId}><div class="form-check form-switch" data-v-9761ca84${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(form.value.header_show_phone) ? ssrLooseContain(form.value.header_show_phone, null) : form.value.header_show_phone) ? " checked" : ""} class="form-check-input" type="checkbox" role="switch" id="header_show_phone" data-v-9761ca84${_scopeId}><label class="form-check-label" for="header_show_phone" data-v-9761ca84${_scopeId}><i class="ti ti-phone me-1 text-muted" data-v-9761ca84${_scopeId}></i>${ssrInterpolate(__props.t.field_header_show_phone)}</label></div></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            if (!form.value.show_header) {
              _push2(`<div class="text-center text-muted py-5" data-v-9761ca84${_scopeId}><i class="ti ti-layout-navbar-collapse fs-1 d-block mb-2 opacity-25" data-v-9761ca84${_scopeId}></i><p class="small" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_show_header)} desativado</p></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div style="${ssrRenderStyle(activeTab.value === "signature" ? null : { display: "none" })}" data-v-9761ca84${_scopeId}><div class="d-flex align-items-center justify-content-between mb-3" data-v-9761ca84${_scopeId}><p class="text-muted small mb-0" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.signature_desc)}</p><div class="form-check form-switch ms-3 mb-0 flex-shrink-0" data-v-9761ca84${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(form.value.show_signature) ? ssrLooseContain(form.value.show_signature, null) : form.value.show_signature) ? " checked" : ""} class="form-check-input" type="checkbox" role="switch" id="show_signature" data-v-9761ca84${_scopeId}><label class="form-check-label fw-semibold" for="show_signature" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_show_signature)}</label></div></div>`);
            if (form.value.show_signature) {
              _push2(`<div class="row g-3" data-v-9761ca84${_scopeId}><div class="col-6" data-v-9761ca84${_scopeId}><div class="form-check form-switch" data-v-9761ca84${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(form.value.signature_show_name) ? ssrLooseContain(form.value.signature_show_name, null) : form.value.signature_show_name) ? " checked" : ""} class="form-check-input" type="checkbox" role="switch" id="signature_show_name" data-v-9761ca84${_scopeId}><label class="form-check-label" for="signature_show_name" data-v-9761ca84${_scopeId}><i class="ti ti-user-check me-1 text-muted" data-v-9761ca84${_scopeId}></i>${ssrInterpolate(__props.t.field_signature_show_name)}</label></div></div><div class="col-6" data-v-9761ca84${_scopeId}><div class="form-check form-switch" data-v-9761ca84${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(form.value.signature_show_crm) ? ssrLooseContain(form.value.signature_show_crm, null) : form.value.signature_show_crm) ? " checked" : ""} class="form-check-input" type="checkbox" role="switch" id="signature_show_crm" data-v-9761ca84${_scopeId}><label class="form-check-label" for="signature_show_crm" data-v-9761ca84${_scopeId}><i class="ti ti-id-badge me-1 text-muted" data-v-9761ca84${_scopeId}></i>${ssrInterpolate(__props.t.field_signature_show_crm)}</label></div></div><div class="col-6" data-v-9761ca84${_scopeId}><div class="form-check form-switch" data-v-9761ca84${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(form.value.signature_show_rqe) ? ssrLooseContain(form.value.signature_show_rqe, null) : form.value.signature_show_rqe) ? " checked" : ""} class="form-check-input" type="checkbox" role="switch" id="signature_show_rqe" data-v-9761ca84${_scopeId}><label class="form-check-label" for="signature_show_rqe" data-v-9761ca84${_scopeId}><i class="ti ti-star me-1 text-muted" data-v-9761ca84${_scopeId}></i>${ssrInterpolate(__props.t.field_signature_show_rqe)}</label></div></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            if (!form.value.show_signature) {
              _push2(`<div class="text-center text-muted py-5" data-v-9761ca84${_scopeId}><i class="ti ti-writing fs-1 d-block mb-2 opacity-25" data-v-9761ca84${_scopeId}></i><p class="small" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_show_signature)} desativado</p></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div style="${ssrRenderStyle(activeTab.value === "footer" ? null : { display: "none" })}" data-v-9761ca84${_scopeId}><div class="d-flex align-items-center justify-content-between mb-3" data-v-9761ca84${_scopeId}><p class="text-muted small mb-0" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.footer_desc)}</p><div class="form-check form-switch ms-3 mb-0 flex-shrink-0" data-v-9761ca84${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(form.value.show_footer) ? ssrLooseContain(form.value.show_footer, null) : form.value.show_footer) ? " checked" : ""} class="form-check-input" type="checkbox" role="switch" id="show_footer" data-v-9761ca84${_scopeId}><label class="form-check-label fw-semibold" for="show_footer" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_show_footer)}</label></div></div>`);
            if (form.value.show_footer) {
              _push2(`<div class="row g-3" data-v-9761ca84${_scopeId}><div class="col-6" data-v-9761ca84${_scopeId}><div class="form-check form-switch" data-v-9761ca84${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(form.value.footer_show_address) ? ssrLooseContain(form.value.footer_show_address, null) : form.value.footer_show_address) ? " checked" : ""} class="form-check-input" type="checkbox" role="switch" id="footer_show_address" data-v-9761ca84${_scopeId}><label class="form-check-label" for="footer_show_address" data-v-9761ca84${_scopeId}><i class="ti ti-map-pin me-1 text-muted" data-v-9761ca84${_scopeId}></i>${ssrInterpolate(__props.t.field_footer_show_address)}</label></div></div><div class="col-6" data-v-9761ca84${_scopeId}><div class="form-check form-switch" data-v-9761ca84${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(form.value.footer_show_phone) ? ssrLooseContain(form.value.footer_show_phone, null) : form.value.footer_show_phone) ? " checked" : ""} class="form-check-input" type="checkbox" role="switch" id="footer_show_phone" data-v-9761ca84${_scopeId}><label class="form-check-label" for="footer_show_phone" data-v-9761ca84${_scopeId}><i class="ti ti-phone me-1 text-muted" data-v-9761ca84${_scopeId}></i>${ssrInterpolate(__props.t.field_footer_show_phone)}</label></div></div><div class="col-12" data-v-9761ca84${_scopeId}><label class="form-label" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_footer_text)}</label><input${ssrRenderAttr("value", form.value.footer_text)} type="text" class="${ssrRenderClass([{ "is-invalid": err("footer_text") }, "form-control"])}"${ssrRenderAttr("placeholder", __props.t.field_footer_text_ph)} maxlength="500" data-v-9761ca84${_scopeId}>`);
              if (err("footer_text")) {
                _push2(`<div class="invalid-feedback" data-v-9761ca84${_scopeId}>${ssrInterpolate(err("footer_text"))}</div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div>`);
            } else {
              _push2(`<!---->`);
            }
            if (!form.value.show_footer) {
              _push2(`<div class="text-center text-muted py-5" data-v-9761ca84${_scopeId}><i class="ti ti-layout-bottombar fs-1 d-block mb-2 opacity-25" data-v-9761ca84${_scopeId}></i><p class="small" data-v-9761ca84${_scopeId}>${ssrInterpolate(__props.t.field_show_footer)} desativado</p></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
          } else {
            return [
              withDirectives(createVNode("div", { class: "row g-3" }, [
                createVNode("div", { class: "col-12" }, [
                  createVNode("label", { class: "form-label" }, [
                    createTextVNode(toDisplayString(__props.t.field_title) + " ", 1),
                    createVNode("span", { class: "text-danger" }, "*")
                  ]),
                  withDirectives(createVNode("input", {
                    "onUpdate:modelValue": ($event) => form.value.title = $event,
                    type: "text",
                    class: ["form-control", { "is-invalid": err("title") }],
                    maxlength: "255"
                  }, null, 10, ["onUpdate:modelValue"]), [
                    [vModelText, form.value.title]
                  ]),
                  err("title") ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "invalid-feedback"
                  }, toDisplayString(err("title")), 1)) : createCommentVNode("", true)
                ]),
                createVNode("div", { class: "col-sm-6" }, [
                  createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_category), 1),
                  withDirectives(createVNode("select", {
                    "onUpdate:modelValue": ($event) => form.value.report_category_id = $event,
                    class: ["form-select", { "is-invalid": err("report_category_id") }]
                  }, [
                    createVNode("option", { value: "" }, toDisplayString(__props.t.select_option), 1),
                    (openBlock(true), createBlock(Fragment, null, renderList(__props.categories, (cat) => {
                      return openBlock(), createBlock("option", {
                        key: cat.id,
                        value: cat.id
                      }, toDisplayString(cat.name), 9, ["value"]);
                    }), 128))
                  ], 10, ["onUpdate:modelValue"]), [
                    [vModelSelect, form.value.report_category_id]
                  ]),
                  err("report_category_id") ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "invalid-feedback"
                  }, toDisplayString(err("report_category_id")), 1)) : createCommentVNode("", true)
                ]),
                createVNode("div", { class: "col-sm-6" }, [
                  createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_paper_size), 1),
                  withDirectives(createVNode("select", {
                    "onUpdate:modelValue": ($event) => form.value.paper_size = $event,
                    class: "form-select"
                  }, [
                    (openBlock(true), createBlock(Fragment, null, renderList(__props.paperSizes, (sz) => {
                      return openBlock(), createBlock("option", {
                        key: sz,
                        value: sz
                      }, toDisplayString(sz), 9, ["value"]);
                    }), 128))
                  ], 8, ["onUpdate:modelValue"]), [
                    [vModelSelect, form.value.paper_size]
                  ])
                ]),
                createVNode("div", { class: "col-sm-6" }, [
                  createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_font_family), 1),
                  withDirectives(createVNode("select", {
                    "onUpdate:modelValue": ($event) => form.value.font_family = $event,
                    class: "form-select"
                  }, [
                    (openBlock(true), createBlock(Fragment, null, renderList(__props.fontFamilies, (f) => {
                      return openBlock(), createBlock("option", {
                        key: f,
                        value: f
                      }, toDisplayString(f), 9, ["value"]);
                    }), 128))
                  ], 8, ["onUpdate:modelValue"]), [
                    [vModelSelect, form.value.font_family]
                  ])
                ]),
                createVNode("div", { class: "col-sm-3" }, [
                  createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_font_size), 1),
                  withDirectives(createVNode("input", {
                    "onUpdate:modelValue": ($event) => form.value.font_size = $event,
                    type: "number",
                    min: "8",
                    max: "24",
                    class: "form-control"
                  }, null, 8, ["onUpdate:modelValue"]), [
                    [
                      vModelText,
                      form.value.font_size,
                      void 0,
                      { number: true }
                    ]
                  ])
                ]),
                createVNode("div", { class: "col-sm-3" }, [
                  createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_active), 1),
                  withDirectives(createVNode("select", {
                    "onUpdate:modelValue": ($event) => form.value.active = $event,
                    class: "form-select"
                  }, [
                    createVNode("option", { value: true }, toDisplayString(__props.t.yes), 1),
                    createVNode("option", { value: false }, toDisplayString(__props.t.no), 1)
                  ], 8, ["onUpdate:modelValue"]), [
                    [vModelSelect, form.value.active]
                  ])
                ]),
                createVNode("div", { class: "col-12" }, [
                  createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_description), 1),
                  withDirectives(createVNode("textarea", {
                    "onUpdate:modelValue": ($event) => form.value.description = $event,
                    class: ["form-control", { "is-invalid": err("description") }],
                    rows: "2",
                    maxlength: "1000"
                  }, null, 10, ["onUpdate:modelValue"]), [
                    [vModelText, form.value.description]
                  ]),
                  err("description") ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "invalid-feedback"
                  }, toDisplayString(err("description")), 1)) : createCommentVNode("", true)
                ]),
                createVNode("div", { class: "col-12" }, [
                  createVNode("p", { class: "fw-semibold text-muted small text-uppercase mb-2" }, toDisplayString(__props.t.field_margins), 1),
                  createVNode("div", { class: "row g-2" }, [
                    createVNode("div", { class: "col-6 col-sm-3" }, [
                      createVNode("label", { class: "form-label small" }, toDisplayString(__props.t.field_margin_top), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => form.value.margin_top = $event,
                        type: "number",
                        step: "0.5",
                        min: "0",
                        max: "10",
                        class: "form-control form-control-sm"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [
                          vModelText,
                          form.value.margin_top,
                          void 0,
                          { number: true }
                        ]
                      ])
                    ]),
                    createVNode("div", { class: "col-6 col-sm-3" }, [
                      createVNode("label", { class: "form-label small" }, toDisplayString(__props.t.field_margin_right), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => form.value.margin_right = $event,
                        type: "number",
                        step: "0.5",
                        min: "0",
                        max: "10",
                        class: "form-control form-control-sm"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [
                          vModelText,
                          form.value.margin_right,
                          void 0,
                          { number: true }
                        ]
                      ])
                    ]),
                    createVNode("div", { class: "col-6 col-sm-3" }, [
                      createVNode("label", { class: "form-label small" }, toDisplayString(__props.t.field_margin_bottom), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => form.value.margin_bottom = $event,
                        type: "number",
                        step: "0.5",
                        min: "0",
                        max: "10",
                        class: "form-control form-control-sm"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [
                          vModelText,
                          form.value.margin_bottom,
                          void 0,
                          { number: true }
                        ]
                      ])
                    ]),
                    createVNode("div", { class: "col-6 col-sm-3" }, [
                      createVNode("label", { class: "form-label small" }, toDisplayString(__props.t.field_margin_left), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => form.value.margin_left = $event,
                        type: "number",
                        step: "0.5",
                        min: "0",
                        max: "10",
                        class: "form-control form-control-sm"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [
                          vModelText,
                          form.value.margin_left,
                          void 0,
                          { number: true }
                        ]
                      ])
                    ])
                  ])
                ])
              ], 512), [
                [vShow, activeTab.value === "general"]
              ]),
              withDirectives(createVNode("div", null, [
                createVNode("div", { class: "d-flex align-items-center justify-content-between mb-3" }, [
                  createVNode("p", { class: "text-muted small mb-0" }, toDisplayString(__props.t.header_desc), 1),
                  createVNode("div", { class: "form-check form-switch ms-3 mb-0 flex-shrink-0" }, [
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => form.value.show_header = $event,
                      class: "form-check-input",
                      type: "checkbox",
                      role: "switch",
                      id: "show_header"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelCheckbox, form.value.show_header]
                    ]),
                    createVNode("label", {
                      class: "form-check-label fw-semibold",
                      for: "show_header"
                    }, toDisplayString(__props.t.field_show_header), 1)
                  ])
                ]),
                createVNode(Transition, { name: "fade" }, {
                  default: withCtx(() => [
                    form.value.show_header ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "row g-3"
                    }, [
                      createVNode("div", { class: "col-6" }, [
                        createVNode("div", { class: "form-check form-switch" }, [
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => form.value.header_show_logo = $event,
                            class: "form-check-input",
                            type: "checkbox",
                            role: "switch",
                            id: "header_show_logo"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelCheckbox, form.value.header_show_logo]
                          ]),
                          createVNode("label", {
                            class: "form-check-label",
                            for: "header_show_logo"
                          }, [
                            createVNode("i", { class: "ti ti-photo me-1 text-muted" }),
                            createTextVNode(toDisplayString(__props.t.field_header_show_logo), 1)
                          ])
                        ])
                      ]),
                      createVNode("div", { class: "col-6" }, [
                        createVNode("div", { class: "form-check form-switch" }, [
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => form.value.header_show_name = $event,
                            class: "form-check-input",
                            type: "checkbox",
                            role: "switch",
                            id: "header_show_name"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelCheckbox, form.value.header_show_name]
                          ]),
                          createVNode("label", {
                            class: "form-check-label",
                            for: "header_show_name"
                          }, [
                            createVNode("i", { class: "ti ti-building-hospital me-1 text-muted" }),
                            createTextVNode(toDisplayString(__props.t.field_header_show_name), 1)
                          ])
                        ])
                      ]),
                      createVNode("div", { class: "col-6" }, [
                        createVNode("div", { class: "form-check form-switch" }, [
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => form.value.header_show_address = $event,
                            class: "form-check-input",
                            type: "checkbox",
                            role: "switch",
                            id: "header_show_address"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelCheckbox, form.value.header_show_address]
                          ]),
                          createVNode("label", {
                            class: "form-check-label",
                            for: "header_show_address"
                          }, [
                            createVNode("i", { class: "ti ti-map-pin me-1 text-muted" }),
                            createTextVNode(toDisplayString(__props.t.field_header_show_address), 1)
                          ])
                        ])
                      ]),
                      createVNode("div", { class: "col-6" }, [
                        createVNode("div", { class: "form-check form-switch" }, [
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => form.value.header_show_phone = $event,
                            class: "form-check-input",
                            type: "checkbox",
                            role: "switch",
                            id: "header_show_phone"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelCheckbox, form.value.header_show_phone]
                          ]),
                          createVNode("label", {
                            class: "form-check-label",
                            for: "header_show_phone"
                          }, [
                            createVNode("i", { class: "ti ti-phone me-1 text-muted" }),
                            createTextVNode(toDisplayString(__props.t.field_header_show_phone), 1)
                          ])
                        ])
                      ])
                    ])) : createCommentVNode("", true)
                  ]),
                  _: 1
                }),
                !form.value.show_header ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "text-center text-muted py-5"
                }, [
                  createVNode("i", { class: "ti ti-layout-navbar-collapse fs-1 d-block mb-2 opacity-25" }),
                  createVNode("p", { class: "small" }, toDisplayString(__props.t.field_show_header) + " desativado", 1)
                ])) : createCommentVNode("", true)
              ], 512), [
                [vShow, activeTab.value === "header"]
              ]),
              withDirectives(createVNode("div", null, [
                createVNode("div", { class: "d-flex align-items-center justify-content-between mb-3" }, [
                  createVNode("p", { class: "text-muted small mb-0" }, toDisplayString(__props.t.signature_desc), 1),
                  createVNode("div", { class: "form-check form-switch ms-3 mb-0 flex-shrink-0" }, [
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => form.value.show_signature = $event,
                      class: "form-check-input",
                      type: "checkbox",
                      role: "switch",
                      id: "show_signature"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelCheckbox, form.value.show_signature]
                    ]),
                    createVNode("label", {
                      class: "form-check-label fw-semibold",
                      for: "show_signature"
                    }, toDisplayString(__props.t.field_show_signature), 1)
                  ])
                ]),
                createVNode(Transition, { name: "fade" }, {
                  default: withCtx(() => [
                    form.value.show_signature ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "row g-3"
                    }, [
                      createVNode("div", { class: "col-6" }, [
                        createVNode("div", { class: "form-check form-switch" }, [
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => form.value.signature_show_name = $event,
                            class: "form-check-input",
                            type: "checkbox",
                            role: "switch",
                            id: "signature_show_name"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelCheckbox, form.value.signature_show_name]
                          ]),
                          createVNode("label", {
                            class: "form-check-label",
                            for: "signature_show_name"
                          }, [
                            createVNode("i", { class: "ti ti-user-check me-1 text-muted" }),
                            createTextVNode(toDisplayString(__props.t.field_signature_show_name), 1)
                          ])
                        ])
                      ]),
                      createVNode("div", { class: "col-6" }, [
                        createVNode("div", { class: "form-check form-switch" }, [
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => form.value.signature_show_crm = $event,
                            class: "form-check-input",
                            type: "checkbox",
                            role: "switch",
                            id: "signature_show_crm"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelCheckbox, form.value.signature_show_crm]
                          ]),
                          createVNode("label", {
                            class: "form-check-label",
                            for: "signature_show_crm"
                          }, [
                            createVNode("i", { class: "ti ti-id-badge me-1 text-muted" }),
                            createTextVNode(toDisplayString(__props.t.field_signature_show_crm), 1)
                          ])
                        ])
                      ]),
                      createVNode("div", { class: "col-6" }, [
                        createVNode("div", { class: "form-check form-switch" }, [
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => form.value.signature_show_rqe = $event,
                            class: "form-check-input",
                            type: "checkbox",
                            role: "switch",
                            id: "signature_show_rqe"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelCheckbox, form.value.signature_show_rqe]
                          ]),
                          createVNode("label", {
                            class: "form-check-label",
                            for: "signature_show_rqe"
                          }, [
                            createVNode("i", { class: "ti ti-star me-1 text-muted" }),
                            createTextVNode(toDisplayString(__props.t.field_signature_show_rqe), 1)
                          ])
                        ])
                      ])
                    ])) : createCommentVNode("", true)
                  ]),
                  _: 1
                }),
                !form.value.show_signature ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "text-center text-muted py-5"
                }, [
                  createVNode("i", { class: "ti ti-writing fs-1 d-block mb-2 opacity-25" }),
                  createVNode("p", { class: "small" }, toDisplayString(__props.t.field_show_signature) + " desativado", 1)
                ])) : createCommentVNode("", true)
              ], 512), [
                [vShow, activeTab.value === "signature"]
              ]),
              withDirectives(createVNode("div", null, [
                createVNode("div", { class: "d-flex align-items-center justify-content-between mb-3" }, [
                  createVNode("p", { class: "text-muted small mb-0" }, toDisplayString(__props.t.footer_desc), 1),
                  createVNode("div", { class: "form-check form-switch ms-3 mb-0 flex-shrink-0" }, [
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => form.value.show_footer = $event,
                      class: "form-check-input",
                      type: "checkbox",
                      role: "switch",
                      id: "show_footer"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelCheckbox, form.value.show_footer]
                    ]),
                    createVNode("label", {
                      class: "form-check-label fw-semibold",
                      for: "show_footer"
                    }, toDisplayString(__props.t.field_show_footer), 1)
                  ])
                ]),
                createVNode(Transition, { name: "fade" }, {
                  default: withCtx(() => [
                    form.value.show_footer ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "row g-3"
                    }, [
                      createVNode("div", { class: "col-6" }, [
                        createVNode("div", { class: "form-check form-switch" }, [
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => form.value.footer_show_address = $event,
                            class: "form-check-input",
                            type: "checkbox",
                            role: "switch",
                            id: "footer_show_address"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelCheckbox, form.value.footer_show_address]
                          ]),
                          createVNode("label", {
                            class: "form-check-label",
                            for: "footer_show_address"
                          }, [
                            createVNode("i", { class: "ti ti-map-pin me-1 text-muted" }),
                            createTextVNode(toDisplayString(__props.t.field_footer_show_address), 1)
                          ])
                        ])
                      ]),
                      createVNode("div", { class: "col-6" }, [
                        createVNode("div", { class: "form-check form-switch" }, [
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => form.value.footer_show_phone = $event,
                            class: "form-check-input",
                            type: "checkbox",
                            role: "switch",
                            id: "footer_show_phone"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelCheckbox, form.value.footer_show_phone]
                          ]),
                          createVNode("label", {
                            class: "form-check-label",
                            for: "footer_show_phone"
                          }, [
                            createVNode("i", { class: "ti ti-phone me-1 text-muted" }),
                            createTextVNode(toDisplayString(__props.t.field_footer_show_phone), 1)
                          ])
                        ])
                      ]),
                      createVNode("div", { class: "col-12" }, [
                        createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_footer_text), 1),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => form.value.footer_text = $event,
                          type: "text",
                          class: ["form-control", { "is-invalid": err("footer_text") }],
                          placeholder: __props.t.field_footer_text_ph,
                          maxlength: "500"
                        }, null, 10, ["onUpdate:modelValue", "placeholder"]), [
                          [vModelText, form.value.footer_text]
                        ]),
                        err("footer_text") ? (openBlock(), createBlock("div", {
                          key: 0,
                          class: "invalid-feedback"
                        }, toDisplayString(err("footer_text")), 1)) : createCommentVNode("", true)
                      ])
                    ])) : createCommentVNode("", true)
                  ]),
                  _: 1
                }),
                !form.value.show_footer ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "text-center text-muted py-5"
                }, [
                  createVNode("i", { class: "ti ti-layout-bottombar fs-1 d-block mb-2 opacity-25" }),
                  createVNode("p", { class: "small" }, toDisplayString(__props.t.field_show_footer) + " desativado", 1)
                ])) : createCommentVNode("", true)
              ], 512), [
                [vShow, activeTab.value === "footer"]
              ])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/ReportSettings/ReportSettingFormModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const ReportSettingFormModal = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-9761ca84"]]);
export {
  ReportSettingFormModal as default
};
