import { ref, watch, computed, withCtx, openBlock, createBlock, Fragment, createVNode, createTextVNode, toDisplayString, createCommentVNode, withDirectives, renderList, vShow, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderClass, ssrRenderStyle, ssrRenderList, ssrRenderAttr } from "vue/server-renderer";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import { _ as _sfc_main$1 } from "./ActionIconButton-BTsQtzdl.js";
import _sfc_main$2 from "./PdfPreviewModal-BGdxaBML.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
import "@inertiajs/vue3";
const _sfc_main = {
  __name: "MedicalRecordDetailDrawer",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    record: { type: Object, default: null },
    patient: { type: Object, required: true }
  },
  emits: ["close"],
  setup(__props) {
    const props = __props;
    const loading = ref(false);
    const detail = ref(null);
    const activeTab = ref("info");
    async function loadDetail() {
      var _a;
      if (!((_a = props.record) == null ? void 0 : _a.show_url)) return;
      loading.value = true;
      detail.value = null;
      activeTab.value = "info";
      try {
        const res = await fetch(props.record.show_url, { headers: { Accept: "application/json" } });
        detail.value = await res.json();
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.open, (val) => {
      if (val) loadDetail();
      else {
        detail.value = null;
        activeTab.value = "info";
      }
    });
    function valOr(v, alt = "—") {
      return v === null || v === void 0 || v === "" ? alt : v;
    }
    function boolLabel(v, labels) {
      if (v === true) return (labels == null ? void 0 : labels.yes) ?? "Sim";
      if (v === false) return (labels == null ? void 0 : labels.no) ?? "Não";
      return (labels == null ? void 0 : labels.not_informed) ?? "Não informado";
    }
    function datePart(s) {
      if (!s || typeof s !== "string") return "";
      return s.slice(0, 10);
    }
    function formatDocTime(docCreatedAt) {
      var _a;
      if (!docCreatedAt || typeof docCreatedAt !== "string") return "—";
      const recordDate = datePart(((_a = detail.value) == null ? void 0 : _a.created_at_formatted) ?? "");
      const docDate = datePart(docCreatedAt);
      const docTime = docCreatedAt.slice(11, 16);
      if (!recordDate || !docDate) return docCreatedAt;
      if (recordDate === docDate) return docTime;
      const recordYear = recordDate.slice(-4);
      const docYear = docDate.slice(-4);
      const shortDate = recordYear === docYear ? docDate.slice(0, 5) : docDate;
      return `${shortDate} ${docTime}`;
    }
    const documentations = computed(() => {
      var _a;
      return ((_a = detail.value) == null ? void 0 : _a.documentations) ?? [];
    });
    const docCount = computed(() => documentations.value.length);
    const pdfPreviewOpen = ref(false);
    const pdfPreviewUrl = ref("");
    const pdfPreviewTitle = ref("");
    const pdfPreviewName = ref("");
    function openPdfPreview(doc) {
      if (!(doc == null ? void 0 : doc.pdf_url)) return;
      pdfPreviewUrl.value = doc.pdf_url;
      pdfPreviewTitle.value = `${doc.type_label} — ${doc.title}`;
      pdfPreviewName.value = `${doc.type_label}-${doc.title}`.toLowerCase();
      pdfPreviewOpen.value = true;
    }
    function closePdfPreview() {
      pdfPreviewOpen.value = false;
      pdfPreviewUrl.value = "";
      pdfPreviewTitle.value = "";
      pdfPreviewName.value = "";
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[-->`);
      _push(ssrRenderComponent(OffcanvasPanel, {
        open: __props.open,
        width: 680,
        loading: loading.value,
        "loading-label": "Carregando prontuário...",
        onClose: ($event) => _ctx.$emit("close")
      }, {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b, _c, _d, _e, _f;
          if (_push2) {
            _push2(`<div data-v-13a8c979${_scopeId}><h5 class="mb-0 fw-semibold" data-v-13a8c979${_scopeId}><i class="ti ti-file-text me-2 text-info" data-v-13a8c979${_scopeId}></i> Prontuário </h5>`);
            if (detail.value) {
              _push2(`<code class="text-muted small" data-v-13a8c979${_scopeId}>${ssrInterpolate(detail.value.code)}</code>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
            if (detail.value) {
              _push2(`<div class="d-flex gap-1 ms-2" data-v-13a8c979${_scopeId}>`);
              if (detail.value.is_signed) {
                _push2(`<span class="badge badge-soft-success rounded text-success border border-success fs-11" data-v-13a8c979${_scopeId}><i class="ti ti-shield-check me-1" data-v-13a8c979${_scopeId}></i>Assinado </span>`);
              } else {
                _push2(`<!---->`);
              }
              if ((_a = __props.record) == null ? void 0 : _a.pdf_url) {
                _push2(`<a${ssrRenderAttr("href", __props.record.pdf_url)} target="_blank" class="btn btn-sm btn-outline-secondary" data-v-13a8c979${_scopeId}><i class="ti ti-file-download me-1" data-v-13a8c979${_scopeId}></i>PDF </a>`);
              } else {
                _push2(`<!---->`);
              }
              if ((_b = __props.record) == null ? void 0 : _b.edit_url) {
                _push2(`<a${ssrRenderAttr("href", __props.record.edit_url)} class="btn btn-sm btn-outline-primary" data-v-13a8c979${_scopeId}><i class="ti ti-edit me-1" data-v-13a8c979${_scopeId}></i>${ssrInterpolate(((_c = detail.value) == null ? void 0 : _c.is_locked) ? "Ver" : "Editar")}</a>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div>`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              createVNode("div", null, [
                createVNode("h5", { class: "mb-0 fw-semibold" }, [
                  createVNode("i", { class: "ti ti-file-text me-2 text-info" }),
                  createTextVNode(" Prontuário ")
                ]),
                detail.value ? (openBlock(), createBlock("code", {
                  key: 0,
                  class: "text-muted small"
                }, toDisplayString(detail.value.code), 1)) : createCommentVNode("", true)
              ]),
              detail.value ? (openBlock(), createBlock("div", {
                key: 0,
                class: "d-flex gap-1 ms-2"
              }, [
                detail.value.is_signed ? (openBlock(), createBlock("span", {
                  key: 0,
                  class: "badge badge-soft-success rounded text-success border border-success fs-11"
                }, [
                  createVNode("i", { class: "ti ti-shield-check me-1" }),
                  createTextVNode("Assinado ")
                ])) : createCommentVNode("", true),
                ((_d = __props.record) == null ? void 0 : _d.pdf_url) ? (openBlock(), createBlock("a", {
                  key: 1,
                  href: __props.record.pdf_url,
                  target: "_blank",
                  class: "btn btn-sm btn-outline-secondary"
                }, [
                  createVNode("i", { class: "ti ti-file-download me-1" }),
                  createTextVNode("PDF ")
                ], 8, ["href"])) : createCommentVNode("", true),
                ((_e = __props.record) == null ? void 0 : _e.edit_url) ? (openBlock(), createBlock("a", {
                  key: 2,
                  href: __props.record.edit_url,
                  class: "btn btn-sm btn-outline-primary"
                }, [
                  createVNode("i", { class: "ti ti-edit me-1" }),
                  createTextVNode(toDisplayString(((_f = detail.value) == null ? void 0 : _f.is_locked) ? "Ver" : "Editar"), 1)
                ], 8, ["href"])) : createCommentVNode("", true)
              ])) : createCommentVNode("", true)
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b, _c, _d, _e, _f, _g, _h, _i, _j, _k, _l, _m, _n, _o, _p, _q, _r, _s, _t, _u, _v, _w, _x, _y, _z;
          if (_push2) {
            if (detail.value) {
              _push2(`<!--[--><div class="mb-3 small text-muted" data-v-13a8c979${_scopeId}><i class="ti ti-calendar me-1" data-v-13a8c979${_scopeId}></i>${ssrInterpolate(detail.value.created_at_formatted)} `);
              if (detail.value.doctor_name) {
                _push2(`<span class="ms-3" data-v-13a8c979${_scopeId}><i class="ti ti-stethoscope me-1" data-v-13a8c979${_scopeId}></i>${ssrInterpolate(detail.value.doctor_name)}</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><ul class="nav nav-tabs mb-3" role="tablist" data-v-13a8c979${_scopeId}><li class="nav-item" role="presentation" data-v-13a8c979${_scopeId}><button type="button" class="${ssrRenderClass([{ active: activeTab.value === "info" }, "nav-link"])}" data-v-13a8c979${_scopeId}><i class="ti ti-clipboard-text me-1" data-v-13a8c979${_scopeId}></i>Informações </button></li><li class="nav-item" role="presentation" data-v-13a8c979${_scopeId}><button type="button" class="${ssrRenderClass([{ active: activeTab.value === "docs" }, "nav-link d-flex align-items-center gap-1"])}" data-v-13a8c979${_scopeId}><i class="ti ti-paperclip me-1" data-v-13a8c979${_scopeId}></i>Documentos `);
              if (docCount.value > 0) {
                _push2(`<span class="badge rounded-pill bg-info-subtle text-info border border-info-subtle ms-1" data-v-13a8c979${_scopeId}>${ssrInterpolate(docCount.value)}</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</button></li></ul><div style="${ssrRenderStyle(activeTab.value === "info" ? null : { display: "none" })}" data-v-13a8c979${_scopeId}><section class="detail-section" data-v-13a8c979${_scopeId}><h6 class="detail-section__title" data-v-13a8c979${_scopeId}><i class="ti ti-message me-1" data-v-13a8c979${_scopeId}></i>${ssrInterpolate(((_a = detail.value.labels) == null ? void 0 : _a.complaint) ?? "Queixa & Anamnese")}</h6><div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>${ssrInterpolate(((_b = detail.value.labels) == null ? void 0 : _b.complaint) ?? "Queixa principal")}</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.main_complaint))}</span></div><div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>${ssrInterpolate(((_c = detail.value.labels) == null ? void 0 : _c.history) ?? "HDA")}</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.hda))}</span></div><div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>${ssrInterpolate(((_d = detail.value.labels) == null ? void 0 : _d.diabetic) ?? "Diabético")}</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(boolLabel(detail.value.diabetic, detail.value.labels))} `);
              if (detail.value.diabetic_family) {
                _push2(`<small class="text-muted" data-v-13a8c979${_scopeId}>(${ssrInterpolate((_e = detail.value.labels) == null ? void 0 : _e.family)})</small>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</span></div><div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>${ssrInterpolate(((_f = detail.value.labels) == null ? void 0 : _f.hypertensive) ?? "Hipertenso")}</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(boolLabel(detail.value.hypertensive, detail.value.labels))} `);
              if (detail.value.hypertensive_family) {
                _push2(`<small class="text-muted" data-v-13a8c979${_scopeId}>(${ssrInterpolate((_g = detail.value.labels) == null ? void 0 : _g.family)})</small>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</span></div><div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>${ssrInterpolate(((_h = detail.value.labels) == null ? void 0 : _h.glaucomatous) ?? "Glaucomatoso")}</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(boolLabel(detail.value.glaucomatous, detail.value.labels))} `);
              if (detail.value.glaucomatous_family) {
                _push2(`<small class="text-muted" data-v-13a8c979${_scopeId}>(${ssrInterpolate((_i = detail.value.labels) == null ? void 0 : _i.family)})</small>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</span></div>`);
              if (detail.value.ocular_surgical_history) {
                _push2(`<div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>Histórico cirúrgico</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(detail.value.ocular_surgical_history)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              if (detail.value.medications_in_use) {
                _push2(`<div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>Medicações em uso</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(detail.value.medications_in_use)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</section><section class="detail-section" data-v-13a8c979${_scopeId}><h6 class="detail-section__title" data-v-13a8c979${_scopeId}><i class="ti ti-eye me-1" data-v-13a8c979${_scopeId}></i>Exame físico</h6><div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>Acuidade visual</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.visual_acuity_type))}</span></div><div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>Conv. ponto próximo</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.near_point_convergence))}</span></div><div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>Cover test</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.cover_test_type))}</span></div><div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>Visão de cores</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.color_vision_type))}</span></div>`);
              if (detail.value.tonometer_right || detail.value.tonometer_left) {
                _push2(`<div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>${ssrInterpolate(((_j = detail.value.labels) == null ? void 0 : _j.tonometry) ?? "Tonometria")}</span><span class="detail-value" data-v-13a8c979${_scopeId}> OD: <code data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.tonometer_right))}</code> / OE: <code data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.tonometer_left))}</code>`);
                if (detail.value.tonometer_time) {
                  _push2(`<small class="text-muted ms-2" data-v-13a8c979${_scopeId}>${ssrInterpolate(detail.value.tonometer_time)}</small>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              if (detail.value.pachymetry_right || detail.value.pachymetry_left) {
                _push2(`<div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>Paquimetria</span><span class="detail-value" data-v-13a8c979${_scopeId}> OD: <code data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.pachymetry_right))}</code> / OE: <code data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.pachymetry_left))}</code></span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</section>`);
              if (detail.value.dynamic_spherical_right || detail.value.dynamic_spherical_left || detail.value.static_spherical_right) {
                _push2(`<section class="detail-section" data-v-13a8c979${_scopeId}><h6 class="detail-section__title" data-v-13a8c979${_scopeId}><i class="ti ti-glasses me-1" data-v-13a8c979${_scopeId}></i>Refração</h6><div class="small" data-v-13a8c979${_scopeId}><table class="table table-sm table-borderless mb-2" data-v-13a8c979${_scopeId}><thead class="table-light" data-v-13a8c979${_scopeId}><tr data-v-13a8c979${_scopeId}><th data-v-13a8c979${_scopeId}></th><th data-v-13a8c979${_scopeId}>OD</th><th data-v-13a8c979${_scopeId}>OE</th></tr></thead><tbody data-v-13a8c979${_scopeId}><tr data-v-13a8c979${_scopeId}><td class="fw-medium" data-v-13a8c979${_scopeId}>Dinâmica esf/cil/eixo</td><td data-v-13a8c979${_scopeId}><code data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.dynamic_spherical_right))} / ${ssrInterpolate(valOr(detail.value.dynamic_cylindrical_right))} / ${ssrInterpolate(valOr(detail.value.dynamic_axis_right))}</code></td><td data-v-13a8c979${_scopeId}><code data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.dynamic_spherical_left))} / ${ssrInterpolate(valOr(detail.value.dynamic_cylindrical_left))} / ${ssrInterpolate(valOr(detail.value.dynamic_axis_left))}</code></td></tr><tr data-v-13a8c979${_scopeId}><td class="fw-medium" data-v-13a8c979${_scopeId}>Estática esf/cil/eixo</td><td data-v-13a8c979${_scopeId}><code data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.static_spherical_right))} / ${ssrInterpolate(valOr(detail.value.static_cylindrical_right))} / ${ssrInterpolate(valOr(detail.value.static_axis_right))}</code></td><td data-v-13a8c979${_scopeId}><code data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.static_spherical_left))} / ${ssrInterpolate(valOr(detail.value.static_cylindrical_left))} / ${ssrInterpolate(valOr(detail.value.static_axis_left))}</code></td></tr></tbody></table><div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>Adição</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.addition_type))}</span></div><div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>Lente longe</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.lens_away))}</span></div><div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>Lente perto</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(valOr(detail.value.lens_near))}</span></div></div></section>`);
              } else {
                _push2(`<!---->`);
              }
              if (detail.value.biomicroscopy_right || detail.value.fundoscopy_right || detail.value.observation_general) {
                _push2(`<section class="detail-section" data-v-13a8c979${_scopeId}><h6 class="detail-section__title" data-v-13a8c979${_scopeId}><i class="ti ti-microscope me-1" data-v-13a8c979${_scopeId}></i>Achados</h6>`);
                if (detail.value.biomicroscopy_right || detail.value.biomicroscopy_left) {
                  _push2(`<div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>Biomicroscopia</span><span class="detail-value small" data-v-13a8c979${_scopeId}><strong data-v-13a8c979${_scopeId}>OD:</strong> ${ssrInterpolate(valOr(detail.value.biomicroscopy_right))}<br data-v-13a8c979${_scopeId}><strong data-v-13a8c979${_scopeId}>OE:</strong> ${ssrInterpolate(valOr(detail.value.biomicroscopy_left))}</span></div>`);
                } else {
                  _push2(`<!---->`);
                }
                if (detail.value.fundoscopy_right || detail.value.fundoscopy_left) {
                  _push2(`<div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>Fundoscopia</span><span class="detail-value small" data-v-13a8c979${_scopeId}><strong data-v-13a8c979${_scopeId}>OD:</strong> ${ssrInterpolate(valOr(detail.value.fundoscopy_right))}<br data-v-13a8c979${_scopeId}><strong data-v-13a8c979${_scopeId}>OE:</strong> ${ssrInterpolate(valOr(detail.value.fundoscopy_left))}</span></div>`);
                } else {
                  _push2(`<!---->`);
                }
                if (detail.value.observation_general) {
                  _push2(`<div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>${ssrInterpolate(((_k = detail.value.labels) == null ? void 0 : _k.general_obs) ?? "Observação geral")}</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(detail.value.observation_general)}</span></div>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</section>`);
              } else {
                _push2(`<!---->`);
              }
              if (((_l = detail.value.diagnosis_cids) == null ? void 0 : _l.length) > 0 || detail.value.clinical_conduct) {
                _push2(`<section class="detail-section" data-v-13a8c979${_scopeId}><h6 class="detail-section__title" data-v-13a8c979${_scopeId}><i class="ti ti-clipboard-text me-1" data-v-13a8c979${_scopeId}></i>Diagnóstico &amp; Conduta</h6>`);
                if (((_m = detail.value.diagnosis_cids) == null ? void 0 : _m.length) > 0) {
                  _push2(`<div class="mb-2" data-v-13a8c979${_scopeId}><!--[-->`);
                  ssrRenderList(detail.value.diagnosis_cids, (cid, idx) => {
                    _push2(`<span class="badge badge-soft-secondary me-1" data-v-13a8c979${_scopeId}>${ssrInterpolate(typeof cid === "object" ? `${cid.code} ${cid.description ?? ""}` : cid)}</span>`);
                  });
                  _push2(`<!--]--></div>`);
                } else {
                  _push2(`<!---->`);
                }
                if (detail.value.clinical_conduct) {
                  _push2(`<div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>Conduta clínica</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(detail.value.clinical_conduct)}</span></div>`);
                } else {
                  _push2(`<!---->`);
                }
                if (detail.value.follow_up_days) {
                  _push2(`<div class="detail-row" data-v-13a8c979${_scopeId}><span class="detail-label" data-v-13a8c979${_scopeId}>Retorno</span><span class="detail-value" data-v-13a8c979${_scopeId}>${ssrInterpolate(detail.value.follow_up_days)} dias</span></div>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</section>`);
              } else {
                _push2(`<!---->`);
              }
              if (detail.value.is_signed) {
                _push2(`<section class="detail-section" data-v-13a8c979${_scopeId}><h6 class="detail-section__title" data-v-13a8c979${_scopeId}><i class="ti ti-shield-check me-1 text-success" data-v-13a8c979${_scopeId}></i>Assinatura digital</h6><div class="alert alert-success small mb-0" data-v-13a8c979${_scopeId}><i class="ti ti-check me-1" data-v-13a8c979${_scopeId}></i> Assinado em <strong data-v-13a8c979${_scopeId}>${ssrInterpolate(detail.value.signed_at_formatted)}</strong>`);
                if (detail.value.signed_by_name) {
                  _push2(`<span data-v-13a8c979${_scopeId}>por <strong data-v-13a8c979${_scopeId}>${ssrInterpolate(detail.value.signed_by_name)}</strong></span>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`<small class="d-block mt-1 text-muted" data-v-13a8c979${_scopeId}>CFM Res. 2.227/2018 — prontuário travado para edição.</small></div></section>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><div style="${ssrRenderStyle(activeTab.value === "docs" ? null : { display: "none" })}" data-v-13a8c979${_scopeId}>`);
              if (documentations.value.length === 0) {
                _push2(`<div class="text-center py-5 text-muted" data-v-13a8c979${_scopeId}><i class="ti ti-paperclip fs-1 d-block mb-2 opacity-25" data-v-13a8c979${_scopeId}></i><p class="mb-0 small" data-v-13a8c979${_scopeId}>Nenhum documento gerado neste prontuário.</p></div>`);
              } else {
                _push2(`<div class="table-responsive" data-v-13a8c979${_scopeId}><table class="table table-sm table-hover align-middle mb-0" data-v-13a8c979${_scopeId}><thead class="table-light" data-v-13a8c979${_scopeId}><tr data-v-13a8c979${_scopeId}><th style="${ssrRenderStyle({ "width": "110px" })}" data-v-13a8c979${_scopeId}>Hora</th><th style="${ssrRenderStyle({ "width": "140px" })}" data-v-13a8c979${_scopeId}>Tipo</th><th data-v-13a8c979${_scopeId}>Título</th><th style="${ssrRenderStyle({ "width": "70px" })}" class="text-end" data-v-13a8c979${_scopeId}>Ações</th></tr></thead><tbody data-v-13a8c979${_scopeId}><!--[-->`);
                ssrRenderList(documentations.value, (doc) => {
                  _push2(`<tr data-v-13a8c979${_scopeId}><td class="small text-muted" data-v-13a8c979${_scopeId}><span${ssrRenderAttr("title", doc.created_at)} data-v-13a8c979${_scopeId}>${ssrInterpolate(formatDocTime(doc.created_at))}</span></td><td data-v-13a8c979${_scopeId}><span class="badge badge-soft-info text-info" data-v-13a8c979${_scopeId}>${ssrInterpolate(doc.type_label)}</span></td><td data-v-13a8c979${_scopeId}><div class="fw-medium small" data-v-13a8c979${_scopeId}>${ssrInterpolate(doc.title)}</div>`);
                  if (doc.doctor_name) {
                    _push2(`<small class="text-muted" data-v-13a8c979${_scopeId}><i class="ti ti-stethoscope me-1" data-v-13a8c979${_scopeId}></i>${ssrInterpolate(doc.doctor_name)}</small>`);
                  } else {
                    _push2(`<!---->`);
                  }
                  _push2(`</td><td class="text-end" data-v-13a8c979${_scopeId}>`);
                  _push2(ssrRenderComponent(_sfc_main$1, {
                    icon: "ti ti-eye",
                    title: "Visualizar documento",
                    variant: "default",
                    onClick: ($event) => openPdfPreview(doc)
                  }, null, _parent2, _scopeId));
                  _push2(`</td></tr>`);
                });
                _push2(`<!--]--></tbody></table></div>`);
              }
              _push2(`</div><!--]-->`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              detail.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                createVNode("div", { class: "mb-3 small text-muted" }, [
                  createVNode("i", { class: "ti ti-calendar me-1" }),
                  createTextVNode(toDisplayString(detail.value.created_at_formatted) + " ", 1),
                  detail.value.doctor_name ? (openBlock(), createBlock("span", {
                    key: 0,
                    class: "ms-3"
                  }, [
                    createVNode("i", { class: "ti ti-stethoscope me-1" }),
                    createTextVNode(toDisplayString(detail.value.doctor_name), 1)
                  ])) : createCommentVNode("", true)
                ]),
                createVNode("ul", {
                  class: "nav nav-tabs mb-3",
                  role: "tablist"
                }, [
                  createVNode("li", {
                    class: "nav-item",
                    role: "presentation"
                  }, [
                    createVNode("button", {
                      type: "button",
                      class: ["nav-link", { active: activeTab.value === "info" }],
                      onClick: ($event) => activeTab.value = "info"
                    }, [
                      createVNode("i", { class: "ti ti-clipboard-text me-1" }),
                      createTextVNode("Informações ")
                    ], 10, ["onClick"])
                  ]),
                  createVNode("li", {
                    class: "nav-item",
                    role: "presentation"
                  }, [
                    createVNode("button", {
                      type: "button",
                      class: ["nav-link d-flex align-items-center gap-1", { active: activeTab.value === "docs" }],
                      onClick: ($event) => activeTab.value = "docs"
                    }, [
                      createVNode("i", { class: "ti ti-paperclip me-1" }),
                      createTextVNode("Documentos "),
                      docCount.value > 0 ? (openBlock(), createBlock("span", {
                        key: 0,
                        class: "badge rounded-pill bg-info-subtle text-info border border-info-subtle ms-1"
                      }, toDisplayString(docCount.value), 1)) : createCommentVNode("", true)
                    ], 10, ["onClick"])
                  ])
                ]),
                withDirectives(createVNode("div", null, [
                  createVNode("section", { class: "detail-section" }, [
                    createVNode("h6", { class: "detail-section__title" }, [
                      createVNode("i", { class: "ti ti-message me-1" }),
                      createTextVNode(toDisplayString(((_n = detail.value.labels) == null ? void 0 : _n.complaint) ?? "Queixa & Anamnese"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(((_o = detail.value.labels) == null ? void 0 : _o.complaint) ?? "Queixa principal"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(valOr(detail.value.main_complaint)), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(((_p = detail.value.labels) == null ? void 0 : _p.history) ?? "HDA"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(valOr(detail.value.hda)), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(((_q = detail.value.labels) == null ? void 0 : _q.diabetic) ?? "Diabético"), 1),
                      createVNode("span", { class: "detail-value" }, [
                        createTextVNode(toDisplayString(boolLabel(detail.value.diabetic, detail.value.labels)) + " ", 1),
                        detail.value.diabetic_family ? (openBlock(), createBlock("small", {
                          key: 0,
                          class: "text-muted"
                        }, "(" + toDisplayString((_r = detail.value.labels) == null ? void 0 : _r.family) + ")", 1)) : createCommentVNode("", true)
                      ])
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(((_s = detail.value.labels) == null ? void 0 : _s.hypertensive) ?? "Hipertenso"), 1),
                      createVNode("span", { class: "detail-value" }, [
                        createTextVNode(toDisplayString(boolLabel(detail.value.hypertensive, detail.value.labels)) + " ", 1),
                        detail.value.hypertensive_family ? (openBlock(), createBlock("small", {
                          key: 0,
                          class: "text-muted"
                        }, "(" + toDisplayString((_t = detail.value.labels) == null ? void 0 : _t.family) + ")", 1)) : createCommentVNode("", true)
                      ])
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(((_u = detail.value.labels) == null ? void 0 : _u.glaucomatous) ?? "Glaucomatoso"), 1),
                      createVNode("span", { class: "detail-value" }, [
                        createTextVNode(toDisplayString(boolLabel(detail.value.glaucomatous, detail.value.labels)) + " ", 1),
                        detail.value.glaucomatous_family ? (openBlock(), createBlock("small", {
                          key: 0,
                          class: "text-muted"
                        }, "(" + toDisplayString((_v = detail.value.labels) == null ? void 0 : _v.family) + ")", 1)) : createCommentVNode("", true)
                      ])
                    ]),
                    detail.value.ocular_surgical_history ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Histórico cirúrgico"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(detail.value.ocular_surgical_history), 1)
                    ])) : createCommentVNode("", true),
                    detail.value.medications_in_use ? (openBlock(), createBlock("div", {
                      key: 1,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Medicações em uso"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(detail.value.medications_in_use), 1)
                    ])) : createCommentVNode("", true)
                  ]),
                  createVNode("section", { class: "detail-section" }, [
                    createVNode("h6", { class: "detail-section__title" }, [
                      createVNode("i", { class: "ti ti-eye me-1" }),
                      createTextVNode("Exame físico")
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Acuidade visual"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(valOr(detail.value.visual_acuity_type)), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Conv. ponto próximo"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(valOr(detail.value.near_point_convergence)), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Cover test"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(valOr(detail.value.cover_test_type)), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Visão de cores"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(valOr(detail.value.color_vision_type)), 1)
                    ]),
                    detail.value.tonometer_right || detail.value.tonometer_left ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(((_w = detail.value.labels) == null ? void 0 : _w.tonometry) ?? "Tonometria"), 1),
                      createVNode("span", { class: "detail-value" }, [
                        createTextVNode(" OD: "),
                        createVNode("code", null, toDisplayString(valOr(detail.value.tonometer_right)), 1),
                        createTextVNode(" / OE: "),
                        createVNode("code", null, toDisplayString(valOr(detail.value.tonometer_left)), 1),
                        detail.value.tonometer_time ? (openBlock(), createBlock("small", {
                          key: 0,
                          class: "text-muted ms-2"
                        }, toDisplayString(detail.value.tonometer_time), 1)) : createCommentVNode("", true)
                      ])
                    ])) : createCommentVNode("", true),
                    detail.value.pachymetry_right || detail.value.pachymetry_left ? (openBlock(), createBlock("div", {
                      key: 1,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Paquimetria"),
                      createVNode("span", { class: "detail-value" }, [
                        createTextVNode(" OD: "),
                        createVNode("code", null, toDisplayString(valOr(detail.value.pachymetry_right)), 1),
                        createTextVNode(" / OE: "),
                        createVNode("code", null, toDisplayString(valOr(detail.value.pachymetry_left)), 1)
                      ])
                    ])) : createCommentVNode("", true)
                  ]),
                  detail.value.dynamic_spherical_right || detail.value.dynamic_spherical_left || detail.value.static_spherical_right ? (openBlock(), createBlock("section", {
                    key: 0,
                    class: "detail-section"
                  }, [
                    createVNode("h6", { class: "detail-section__title" }, [
                      createVNode("i", { class: "ti ti-glasses me-1" }),
                      createTextVNode("Refração")
                    ]),
                    createVNode("div", { class: "small" }, [
                      createVNode("table", { class: "table table-sm table-borderless mb-2" }, [
                        createVNode("thead", { class: "table-light" }, [
                          createVNode("tr", null, [
                            createVNode("th"),
                            createVNode("th", null, "OD"),
                            createVNode("th", null, "OE")
                          ])
                        ]),
                        createVNode("tbody", null, [
                          createVNode("tr", null, [
                            createVNode("td", { class: "fw-medium" }, "Dinâmica esf/cil/eixo"),
                            createVNode("td", null, [
                              createVNode("code", null, toDisplayString(valOr(detail.value.dynamic_spherical_right)) + " / " + toDisplayString(valOr(detail.value.dynamic_cylindrical_right)) + " / " + toDisplayString(valOr(detail.value.dynamic_axis_right)), 1)
                            ]),
                            createVNode("td", null, [
                              createVNode("code", null, toDisplayString(valOr(detail.value.dynamic_spherical_left)) + " / " + toDisplayString(valOr(detail.value.dynamic_cylindrical_left)) + " / " + toDisplayString(valOr(detail.value.dynamic_axis_left)), 1)
                            ])
                          ]),
                          createVNode("tr", null, [
                            createVNode("td", { class: "fw-medium" }, "Estática esf/cil/eixo"),
                            createVNode("td", null, [
                              createVNode("code", null, toDisplayString(valOr(detail.value.static_spherical_right)) + " / " + toDisplayString(valOr(detail.value.static_cylindrical_right)) + " / " + toDisplayString(valOr(detail.value.static_axis_right)), 1)
                            ]),
                            createVNode("td", null, [
                              createVNode("code", null, toDisplayString(valOr(detail.value.static_spherical_left)) + " / " + toDisplayString(valOr(detail.value.static_cylindrical_left)) + " / " + toDisplayString(valOr(detail.value.static_axis_left)), 1)
                            ])
                          ])
                        ])
                      ]),
                      createVNode("div", { class: "detail-row" }, [
                        createVNode("span", { class: "detail-label" }, "Adição"),
                        createVNode("span", { class: "detail-value" }, toDisplayString(valOr(detail.value.addition_type)), 1)
                      ]),
                      createVNode("div", { class: "detail-row" }, [
                        createVNode("span", { class: "detail-label" }, "Lente longe"),
                        createVNode("span", { class: "detail-value" }, toDisplayString(valOr(detail.value.lens_away)), 1)
                      ]),
                      createVNode("div", { class: "detail-row" }, [
                        createVNode("span", { class: "detail-label" }, "Lente perto"),
                        createVNode("span", { class: "detail-value" }, toDisplayString(valOr(detail.value.lens_near)), 1)
                      ])
                    ])
                  ])) : createCommentVNode("", true),
                  detail.value.biomicroscopy_right || detail.value.fundoscopy_right || detail.value.observation_general ? (openBlock(), createBlock("section", {
                    key: 1,
                    class: "detail-section"
                  }, [
                    createVNode("h6", { class: "detail-section__title" }, [
                      createVNode("i", { class: "ti ti-microscope me-1" }),
                      createTextVNode("Achados")
                    ]),
                    detail.value.biomicroscopy_right || detail.value.biomicroscopy_left ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Biomicroscopia"),
                      createVNode("span", { class: "detail-value small" }, [
                        createVNode("strong", null, "OD:"),
                        createTextVNode(" " + toDisplayString(valOr(detail.value.biomicroscopy_right)), 1),
                        createVNode("br"),
                        createVNode("strong", null, "OE:"),
                        createTextVNode(" " + toDisplayString(valOr(detail.value.biomicroscopy_left)), 1)
                      ])
                    ])) : createCommentVNode("", true),
                    detail.value.fundoscopy_right || detail.value.fundoscopy_left ? (openBlock(), createBlock("div", {
                      key: 1,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Fundoscopia"),
                      createVNode("span", { class: "detail-value small" }, [
                        createVNode("strong", null, "OD:"),
                        createTextVNode(" " + toDisplayString(valOr(detail.value.fundoscopy_right)), 1),
                        createVNode("br"),
                        createVNode("strong", null, "OE:"),
                        createTextVNode(" " + toDisplayString(valOr(detail.value.fundoscopy_left)), 1)
                      ])
                    ])) : createCommentVNode("", true),
                    detail.value.observation_general ? (openBlock(), createBlock("div", {
                      key: 2,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(((_x = detail.value.labels) == null ? void 0 : _x.general_obs) ?? "Observação geral"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(detail.value.observation_general), 1)
                    ])) : createCommentVNode("", true)
                  ])) : createCommentVNode("", true),
                  ((_y = detail.value.diagnosis_cids) == null ? void 0 : _y.length) > 0 || detail.value.clinical_conduct ? (openBlock(), createBlock("section", {
                    key: 2,
                    class: "detail-section"
                  }, [
                    createVNode("h6", { class: "detail-section__title" }, [
                      createVNode("i", { class: "ti ti-clipboard-text me-1" }),
                      createTextVNode("Diagnóstico & Conduta")
                    ]),
                    ((_z = detail.value.diagnosis_cids) == null ? void 0 : _z.length) > 0 ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "mb-2"
                    }, [
                      (openBlock(true), createBlock(Fragment, null, renderList(detail.value.diagnosis_cids, (cid, idx) => {
                        return openBlock(), createBlock("span", {
                          key: idx,
                          class: "badge badge-soft-secondary me-1"
                        }, toDisplayString(typeof cid === "object" ? `${cid.code} ${cid.description ?? ""}` : cid), 1);
                      }), 128))
                    ])) : createCommentVNode("", true),
                    detail.value.clinical_conduct ? (openBlock(), createBlock("div", {
                      key: 1,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Conduta clínica"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(detail.value.clinical_conduct), 1)
                    ])) : createCommentVNode("", true),
                    detail.value.follow_up_days ? (openBlock(), createBlock("div", {
                      key: 2,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Retorno"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(detail.value.follow_up_days) + " dias", 1)
                    ])) : createCommentVNode("", true)
                  ])) : createCommentVNode("", true),
                  detail.value.is_signed ? (openBlock(), createBlock("section", {
                    key: 3,
                    class: "detail-section"
                  }, [
                    createVNode("h6", { class: "detail-section__title" }, [
                      createVNode("i", { class: "ti ti-shield-check me-1 text-success" }),
                      createTextVNode("Assinatura digital")
                    ]),
                    createVNode("div", { class: "alert alert-success small mb-0" }, [
                      createVNode("i", { class: "ti ti-check me-1" }),
                      createTextVNode(" Assinado em "),
                      createVNode("strong", null, toDisplayString(detail.value.signed_at_formatted), 1),
                      detail.value.signed_by_name ? (openBlock(), createBlock("span", { key: 0 }, [
                        createTextVNode("por "),
                        createVNode("strong", null, toDisplayString(detail.value.signed_by_name), 1)
                      ])) : createCommentVNode("", true),
                      createVNode("small", { class: "d-block mt-1 text-muted" }, "CFM Res. 2.227/2018 — prontuário travado para edição.")
                    ])
                  ])) : createCommentVNode("", true)
                ], 512), [
                  [vShow, activeTab.value === "info"]
                ]),
                withDirectives(createVNode("div", null, [
                  documentations.value.length === 0 ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "text-center py-5 text-muted"
                  }, [
                    createVNode("i", { class: "ti ti-paperclip fs-1 d-block mb-2 opacity-25" }),
                    createVNode("p", { class: "mb-0 small" }, "Nenhum documento gerado neste prontuário.")
                  ])) : (openBlock(), createBlock("div", {
                    key: 1,
                    class: "table-responsive"
                  }, [
                    createVNode("table", { class: "table table-sm table-hover align-middle mb-0" }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          createVNode("th", { style: { "width": "110px" } }, "Hora"),
                          createVNode("th", { style: { "width": "140px" } }, "Tipo"),
                          createVNode("th", null, "Título"),
                          createVNode("th", {
                            style: { "width": "70px" },
                            class: "text-end"
                          }, "Ações")
                        ])
                      ]),
                      createVNode("tbody", null, [
                        (openBlock(true), createBlock(Fragment, null, renderList(documentations.value, (doc) => {
                          return openBlock(), createBlock("tr", {
                            key: doc.id
                          }, [
                            createVNode("td", { class: "small text-muted" }, [
                              createVNode("span", {
                                title: doc.created_at
                              }, toDisplayString(formatDocTime(doc.created_at)), 9, ["title"])
                            ]),
                            createVNode("td", null, [
                              createVNode("span", { class: "badge badge-soft-info text-info" }, toDisplayString(doc.type_label), 1)
                            ]),
                            createVNode("td", null, [
                              createVNode("div", { class: "fw-medium small" }, toDisplayString(doc.title), 1),
                              doc.doctor_name ? (openBlock(), createBlock("small", {
                                key: 0,
                                class: "text-muted"
                              }, [
                                createVNode("i", { class: "ti ti-stethoscope me-1" }),
                                createTextVNode(toDisplayString(doc.doctor_name), 1)
                              ])) : createCommentVNode("", true)
                            ]),
                            createVNode("td", { class: "text-end" }, [
                              createVNode(_sfc_main$1, {
                                icon: "ti ti-eye",
                                title: "Visualizar documento",
                                variant: "default",
                                onClick: ($event) => openPdfPreview(doc)
                              }, null, 8, ["onClick"])
                            ])
                          ]);
                        }), 128))
                      ])
                    ])
                  ]))
                ], 512), [
                  [vShow, activeTab.value === "docs"]
                ])
              ], 64)) : createCommentVNode("", true)
            ];
          }
        }),
        _: 1
      }, _parent));
      if (pdfPreviewOpen.value) {
        _push(ssrRenderComponent(_sfc_main$2, {
          url: pdfPreviewUrl.value,
          title: pdfPreviewTitle.value,
          filename: pdfPreviewName.value,
          onClose: closePdfPreview
        }, null, _parent));
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/MedicalRecords/MedicalRecordDetailDrawer.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const MedicalRecordDetailDrawer = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-13a8c979"]]);
export {
  MedicalRecordDetailDrawer as default
};
