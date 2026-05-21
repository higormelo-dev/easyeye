import { computed, ref, mergeProps, withCtx, unref, createVNode, createTextVNode, withModifiers, toDisplayString, withDirectives, vModelText, openBlock, createBlock, createCommentVNode, Fragment, renderList, vModelSelect, vModelCheckbox, vShow, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderClass, ssrInterpolate, ssrRenderStyle, ssrRenderAttr, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList } from "vue/server-renderer";
import { useForm, Link } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Form",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    mode: { type: String, default: "create" },
    // 'create' | 'edit'
    reportSetting: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
    paper_sizes: { type: Array, default: () => [] },
    documentation_types: { type: Array, default: () => [] },
    urls: { type: Object, required: true }
  },
  setup(__props) {
    var _a, _b, _c, _d, _e, _f, _g, _h, _i, _j, _k, _l, _m, _n, _o, _p, _q, _r, _s, _t, _u, _v, _w, _x, _y, _z;
    const props = __props;
    const isEdit = computed(() => props.mode === "edit");
    const form = useForm({
      _method: isEdit.value ? "PATCH" : "POST",
      title: ((_a = props.reportSetting) == null ? void 0 : _a.title) ?? "",
      description: ((_b = props.reportSetting) == null ? void 0 : _b.description) ?? "",
      report_category_id: ((_c = props.reportSetting) == null ? void 0 : _c.report_category_id) ?? "",
      paper_size: ((_d = props.reportSetting) == null ? void 0 : _d.paper_size) ?? "A4",
      font_family: ((_e = props.reportSetting) == null ? void 0 : _e.font_family) ?? "Helvetica",
      font_size: ((_f = props.reportSetting) == null ? void 0 : _f.font_size) ?? 11,
      margin_top: ((_g = props.reportSetting) == null ? void 0 : _g.margin_top) ?? 2,
      margin_right: ((_h = props.reportSetting) == null ? void 0 : _h.margin_right) ?? 2,
      margin_bottom: ((_i = props.reportSetting) == null ? void 0 : _i.margin_bottom) ?? 2,
      margin_left: ((_j = props.reportSetting) == null ? void 0 : _j.margin_left) ?? 2,
      show_header: ((_k = props.reportSetting) == null ? void 0 : _k.show_header) ?? true,
      header_show_logo: ((_l = props.reportSetting) == null ? void 0 : _l.header_show_logo) ?? true,
      header_show_name: ((_m = props.reportSetting) == null ? void 0 : _m.header_show_name) ?? true,
      header_show_address: ((_n = props.reportSetting) == null ? void 0 : _n.header_show_address) ?? true,
      header_show_phone: ((_o = props.reportSetting) == null ? void 0 : _o.header_show_phone) ?? true,
      show_signature: ((_p = props.reportSetting) == null ? void 0 : _p.show_signature) ?? true,
      signature_show_name: ((_q = props.reportSetting) == null ? void 0 : _q.signature_show_name) ?? true,
      signature_show_crm: ((_r = props.reportSetting) == null ? void 0 : _r.signature_show_crm) ?? true,
      signature_show_rqe: ((_s = props.reportSetting) == null ? void 0 : _s.signature_show_rqe) ?? false,
      show_footer: ((_t = props.reportSetting) == null ? void 0 : _t.show_footer) ?? false,
      footer_text: ((_u = props.reportSetting) == null ? void 0 : _u.footer_text) ?? "",
      footer_show_address: ((_v = props.reportSetting) == null ? void 0 : _v.footer_show_address) ?? false,
      footer_show_phone: ((_w = props.reportSetting) == null ? void 0 : _w.footer_show_phone) ?? false,
      active: ((_x = props.reportSetting) == null ? void 0 : _x.active) ?? true,
      contents: ((_z = (_y = props.reportSetting) == null ? void 0 : _y.contents) == null ? void 0 : _z.map((c) => ({
        type: c.type,
        label: c.label,
        content: c.content,
        active: c.active
      }))) ?? [{ type: "recipe", label: "Conteúdo principal", content: "", active: true }]
    });
    function addContent() {
      form.contents.push({ type: "recipe", label: "", content: "", active: true });
    }
    function removeContent(idx) {
      form.contents.splice(idx, 1);
    }
    function submit() {
      const url = isEdit.value ? props.urls.update : props.urls.store;
      form.post(url, {
        preserveScroll: true,
        onSuccess: () => {
          if (!isEdit.value) ;
        }
      });
    }
    const activeTab = ref("general");
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: isEdit.value ? "Editar modelo" : "Novo modelo",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: isEdit.value ? "Editar modelo" : "Novo modelo de documentação"
            }, {
              actions: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(ssrRenderComponent(unref(Link), {
                    href: __props.urls.index,
                    class: "btn btn-outline-secondary btn-sm"
                  }, {
                    default: withCtx((_3, _push4, _parent4, _scopeId3) => {
                      if (_push4) {
                        _push4(`<i class="ti ti-arrow-left me-1"${_scopeId3}></i>Voltar `);
                      } else {
                        return [
                          createVNode("i", { class: "ti ti-arrow-left me-1" }),
                          createTextVNode("Voltar ")
                        ];
                      }
                    }),
                    _: 1
                  }, _parent3, _scopeId2));
                } else {
                  return [
                    createVNode(unref(Link), {
                      href: __props.urls.index,
                      class: "btn btn-outline-secondary btn-sm"
                    }, {
                      default: withCtx(() => [
                        createVNode("i", { class: "ti ti-arrow-left me-1" }),
                        createTextVNode("Voltar ")
                      ]),
                      _: 1
                    }, 8, ["href"])
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`<form${_scopeId}><ul class="nav nav-tabs mb-3"${_scopeId}><li class="nav-item"${_scopeId}><button type="button" class="${ssrRenderClass(["nav-link", { active: activeTab.value === "general" }])}"${_scopeId}><i class="ti ti-info-circle me-1"${_scopeId}></i>Geral </button></li><li class="nav-item"${_scopeId}><button type="button" class="${ssrRenderClass(["nav-link", { active: activeTab.value === "layout" }])}"${_scopeId}><i class="ti ti-layout me-1"${_scopeId}></i>Layout </button></li><li class="nav-item"${_scopeId}><button type="button" class="${ssrRenderClass(["nav-link", { active: activeTab.value === "contents" }])}"${_scopeId}><i class="ti ti-file-text me-1"${_scopeId}></i>Conteúdos (${ssrInterpolate(unref(form).contents.length)}) </button></li></ul><div class="card mb-3" style="${ssrRenderStyle(activeTab.value === "general" ? null : { display: "none" })}"${_scopeId}><div class="card-body"${_scopeId}><div class="row g-3"${_scopeId}><div class="col-md-8"${_scopeId}><label class="form-label"${_scopeId}>Título <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", unref(form).title)} type="text" maxlength="255" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.title }, "form-control"])}" required${_scopeId}>`);
            if (unref(form).errors.title) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.title)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-md-4"${_scopeId}><label class="form-label"${_scopeId}>Categoria</label><select class="form-select"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).report_category_id) ? ssrLooseContain(unref(form).report_category_id, "") : ssrLooseEqual(unref(form).report_category_id, "")) ? " selected" : ""}${_scopeId}>—</option><!--[-->`);
            ssrRenderList(__props.categories, (c) => {
              _push2(`<option${ssrRenderAttr("value", c.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).report_category_id) ? ssrLooseContain(unref(form).report_category_id, c.id) : ssrLooseEqual(unref(form).report_category_id, c.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(c.name)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-12"${_scopeId}><label class="form-label"${_scopeId}>Descrição</label><textarea rows="2" maxlength="1000" class="form-control"${_scopeId}>${ssrInterpolate(unref(form).description)}</textarea></div><div class="col-md-4"${_scopeId}><div class="form-check form-switch mt-4"${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(unref(form).active) ? ssrLooseContain(unref(form).active, null) : unref(form).active) ? " checked" : ""} type="checkbox" class="form-check-input" id="field_active" role="switch"${_scopeId}><label class="form-check-label" for="field_active"${_scopeId}>Ativo</label></div></div></div></div></div><div class="card mb-3" style="${ssrRenderStyle(activeTab.value === "layout" ? null : { display: "none" })}"${_scopeId}><div class="card-body"${_scopeId}><h6 class="fw-semibold mb-3"${_scopeId}><i class="ti ti-page-break me-1"${_scopeId}></i>Página</h6><div class="row g-3 mb-4"${_scopeId}><div class="col-md-3"${_scopeId}><label class="form-label small"${_scopeId}>Tamanho</label><select class="form-select form-select-sm"${_scopeId}><!--[-->`);
            ssrRenderList(__props.paper_sizes, (ps) => {
              _push2(`<option${ssrRenderAttr("value", ps.value)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).paper_size) ? ssrLooseContain(unref(form).paper_size, ps.value) : ssrLooseEqual(unref(form).paper_size, ps.value)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(ps.label)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-md-3"${_scopeId}><label class="form-label small"${_scopeId}>Fonte</label><input${ssrRenderAttr("value", unref(form).font_family)} type="text" maxlength="50" class="form-control form-control-sm"${_scopeId}></div><div class="col-md-2"${_scopeId}><label class="form-label small"${_scopeId}>Tamanho</label><input${ssrRenderAttr("value", unref(form).font_size)} type="number" min="8" max="24" class="form-control form-control-sm"${_scopeId}></div></div><h6 class="fw-semibold mb-3"${_scopeId}><i class="ti ti-frame me-1"${_scopeId}></i>Margens (cm)</h6><div class="row g-3 mb-4"${_scopeId}><div class="col-md-3"${_scopeId}><label class="form-label small"${_scopeId}>Topo</label><input${ssrRenderAttr("value", unref(form).margin_top)} type="number" step="0.1" min="0" max="10" class="form-control form-control-sm"${_scopeId}></div><div class="col-md-3"${_scopeId}><label class="form-label small"${_scopeId}>Direita</label><input${ssrRenderAttr("value", unref(form).margin_right)} type="number" step="0.1" min="0" max="10" class="form-control form-control-sm"${_scopeId}></div><div class="col-md-3"${_scopeId}><label class="form-label small"${_scopeId}>Inferior</label><input${ssrRenderAttr("value", unref(form).margin_bottom)} type="number" step="0.1" min="0" max="10" class="form-control form-control-sm"${_scopeId}></div><div class="col-md-3"${_scopeId}><label class="form-label small"${_scopeId}>Esquerda</label><input${ssrRenderAttr("value", unref(form).margin_left)} type="number" step="0.1" min="0" max="10" class="form-control form-control-sm"${_scopeId}></div></div><hr${_scopeId}><h6 class="fw-semibold mb-3"${_scopeId}><i class="ti ti-layout-navbar me-1"${_scopeId}></i>Cabeçalho</h6><div class="form-check form-switch mb-2"${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(unref(form).show_header) ? ssrLooseContain(unref(form).show_header, null) : unref(form).show_header) ? " checked" : ""} type="checkbox" class="form-check-input" id="show_header" role="switch"${_scopeId}><label class="form-check-label" for="show_header"${_scopeId}>Exibir cabeçalho</label></div>`);
            if (unref(form).show_header) {
              _push2(`<div class="row g-2 ms-3"${_scopeId}><div class="col-md-3"${_scopeId}><div class="form-check"${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(unref(form).header_show_logo) ? ssrLooseContain(unref(form).header_show_logo, null) : unref(form).header_show_logo) ? " checked" : ""} type="checkbox" class="form-check-input" id="h_logo"${_scopeId}><label class="form-check-label" for="h_logo"${_scopeId}>Logo</label></div></div><div class="col-md-3"${_scopeId}><div class="form-check"${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(unref(form).header_show_name) ? ssrLooseContain(unref(form).header_show_name, null) : unref(form).header_show_name) ? " checked" : ""} type="checkbox" class="form-check-input" id="h_name"${_scopeId}><label class="form-check-label" for="h_name"${_scopeId}>Nome</label></div></div><div class="col-md-3"${_scopeId}><div class="form-check"${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(unref(form).header_show_address) ? ssrLooseContain(unref(form).header_show_address, null) : unref(form).header_show_address) ? " checked" : ""} type="checkbox" class="form-check-input" id="h_addr"${_scopeId}><label class="form-check-label" for="h_addr"${_scopeId}>Endereço</label></div></div><div class="col-md-3"${_scopeId}><div class="form-check"${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(unref(form).header_show_phone) ? ssrLooseContain(unref(form).header_show_phone, null) : unref(form).header_show_phone) ? " checked" : ""} type="checkbox" class="form-check-input" id="h_phone"${_scopeId}><label class="form-check-label" for="h_phone"${_scopeId}>Telefone</label></div></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<hr${_scopeId}><h6 class="fw-semibold mb-3"${_scopeId}><i class="ti ti-signature me-1"${_scopeId}></i>Assinatura</h6><div class="form-check form-switch mb-2"${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(unref(form).show_signature) ? ssrLooseContain(unref(form).show_signature, null) : unref(form).show_signature) ? " checked" : ""} type="checkbox" class="form-check-input" id="show_sig" role="switch"${_scopeId}><label class="form-check-label" for="show_sig"${_scopeId}>Exibir assinatura</label></div>`);
            if (unref(form).show_signature) {
              _push2(`<div class="row g-2 ms-3"${_scopeId}><div class="col-md-3"${_scopeId}><div class="form-check"${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(unref(form).signature_show_name) ? ssrLooseContain(unref(form).signature_show_name, null) : unref(form).signature_show_name) ? " checked" : ""} type="checkbox" class="form-check-input" id="s_name"${_scopeId}><label class="form-check-label" for="s_name"${_scopeId}>Nome</label></div></div><div class="col-md-3"${_scopeId}><div class="form-check"${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(unref(form).signature_show_crm) ? ssrLooseContain(unref(form).signature_show_crm, null) : unref(form).signature_show_crm) ? " checked" : ""} type="checkbox" class="form-check-input" id="s_crm"${_scopeId}><label class="form-check-label" for="s_crm"${_scopeId}>CRM</label></div></div><div class="col-md-3"${_scopeId}><div class="form-check"${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(unref(form).signature_show_rqe) ? ssrLooseContain(unref(form).signature_show_rqe, null) : unref(form).signature_show_rqe) ? " checked" : ""} type="checkbox" class="form-check-input" id="s_rqe"${_scopeId}><label class="form-check-label" for="s_rqe"${_scopeId}>RQE</label></div></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<hr${_scopeId}><h6 class="fw-semibold mb-3"${_scopeId}><i class="ti ti-layout-bottombar me-1"${_scopeId}></i>Rodapé</h6><div class="form-check form-switch mb-2"${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(unref(form).show_footer) ? ssrLooseContain(unref(form).show_footer, null) : unref(form).show_footer) ? " checked" : ""} type="checkbox" class="form-check-input" id="show_footer" role="switch"${_scopeId}><label class="form-check-label" for="show_footer"${_scopeId}>Exibir rodapé</label></div>`);
            if (unref(form).show_footer) {
              _push2(`<div class="ms-3"${_scopeId}><div class="mb-2"${_scopeId}><label class="form-label small"${_scopeId}>Texto do rodapé</label><textarea rows="2" maxlength="500" class="form-control form-control-sm"${_scopeId}>${ssrInterpolate(unref(form).footer_text)}</textarea></div><div class="row g-2"${_scopeId}><div class="col-md-4"${_scopeId}><div class="form-check"${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(unref(form).footer_show_address) ? ssrLooseContain(unref(form).footer_show_address, null) : unref(form).footer_show_address) ? " checked" : ""} type="checkbox" class="form-check-input" id="f_addr"${_scopeId}><label class="form-check-label" for="f_addr"${_scopeId}>Endereço</label></div></div><div class="col-md-4"${_scopeId}><div class="form-check"${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(unref(form).footer_show_phone) ? ssrLooseContain(unref(form).footer_show_phone, null) : unref(form).footer_show_phone) ? " checked" : ""} type="checkbox" class="form-check-input" id="f_phone"${_scopeId}><label class="form-check-label" for="f_phone"${_scopeId}>Telefone</label></div></div></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div><div class="card mb-3" style="${ssrRenderStyle(activeTab.value === "contents" ? null : { display: "none" })}"${_scopeId}><div class="card-body"${_scopeId}><div class="d-flex justify-content-between align-items-center mb-3"${_scopeId}><h6 class="fw-semibold mb-0"${_scopeId}>Conteúdos disponíveis</h6><button type="button" class="btn btn-sm btn-outline-primary"${_scopeId}><i class="ti ti-plus me-1"${_scopeId}></i>Adicionar variante </button></div>`);
            if (unref(form).contents.length === 0) {
              _push2(`<div class="text-muted small text-center py-3"${_scopeId}> Nenhum conteúdo. Clique em &quot;Adicionar variante&quot; para criar. </div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(unref(form).contents, (content, idx) => {
              _push2(`<div class="card border mb-3"${_scopeId}><div class="card-body"${_scopeId}><div class="row g-2 mb-2"${_scopeId}><div class="col-md-4"${_scopeId}><label class="form-label small"${_scopeId}>Tipo</label><select class="form-select form-select-sm"${_scopeId}><!--[-->`);
              ssrRenderList(__props.documentation_types, (t) => {
                _push2(`<option${ssrRenderAttr("value", t.value)}${ssrIncludeBooleanAttr(Array.isArray(content.type) ? ssrLooseContain(content.type, t.value) : ssrLooseEqual(content.type, t.value)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(t.label)}</option>`);
              });
              _push2(`<!--]--></select></div><div class="col-md-6"${_scopeId}><label class="form-label small"${_scopeId}>Rótulo (ex: &quot;Receita controlada&quot;)</label><input${ssrRenderAttr("value", content.label)} type="text" maxlength="255" class="form-control form-control-sm" required${_scopeId}></div><div class="col-md-2 d-flex align-items-end gap-2"${_scopeId}><div class="form-check form-switch mb-1"${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(content.active) ? ssrLooseContain(content.active, null) : content.active) ? " checked" : ""} type="checkbox" class="form-check-input"${ssrRenderAttr("id", `active_${idx}`)} role="switch"${_scopeId}><label class="form-check-label small"${ssrRenderAttr("for", `active_${idx}`)}${_scopeId}>Ativo</label></div><button type="button" class="btn btn-sm btn-outline-danger ms-auto" title="Remover"${_scopeId}><i class="ti ti-trash"${_scopeId}></i></button></div></div><label class="form-label small"${_scopeId}>Conteúdo (suporta HTML + placeholders)</label><textarea rows="8" class="form-control font-monospace small" required${_scopeId}>${ssrInterpolate(content.content)}</textarea></div></div>`);
            });
            _push2(`<!--]--></div></div><div class="d-flex justify-content-end gap-2"${_scopeId}>`);
            _push2(ssrRenderComponent(unref(Link), {
              href: __props.urls.index,
              class: "btn btn-light"
            }, {
              default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`Cancelar`);
                } else {
                  return [
                    createTextVNode("Cancelar")
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`<button type="submit" class="btn btn-primary"${ssrIncludeBooleanAttr(unref(form).processing) ? " disabled" : ""}${_scopeId}>`);
            if (unref(form).processing) {
              _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
            } else {
              _push2(`<i class="ti ti-device-floppy me-1"${_scopeId}></i>`);
            }
            _push2(` ${ssrInterpolate(isEdit.value ? "Salvar alterações" : "Cadastrar")}</button></div></form></div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, {
                  title: isEdit.value ? "Editar modelo" : "Novo modelo de documentação"
                }, {
                  actions: withCtx(() => [
                    createVNode(unref(Link), {
                      href: __props.urls.index,
                      class: "btn btn-outline-secondary btn-sm"
                    }, {
                      default: withCtx(() => [
                        createVNode("i", { class: "ti ti-arrow-left me-1" }),
                        createTextVNode("Voltar ")
                      ]),
                      _: 1
                    }, 8, ["href"])
                  ]),
                  _: 1
                }, 8, ["title"]),
                createVNode("form", {
                  onSubmit: withModifiers(submit, ["prevent"])
                }, [
                  createVNode("ul", { class: "nav nav-tabs mb-3" }, [
                    createVNode("li", { class: "nav-item" }, [
                      createVNode("button", {
                        type: "button",
                        class: ["nav-link", { active: activeTab.value === "general" }],
                        onClick: ($event) => activeTab.value = "general"
                      }, [
                        createVNode("i", { class: "ti ti-info-circle me-1" }),
                        createTextVNode("Geral ")
                      ], 10, ["onClick"])
                    ]),
                    createVNode("li", { class: "nav-item" }, [
                      createVNode("button", {
                        type: "button",
                        class: ["nav-link", { active: activeTab.value === "layout" }],
                        onClick: ($event) => activeTab.value = "layout"
                      }, [
                        createVNode("i", { class: "ti ti-layout me-1" }),
                        createTextVNode("Layout ")
                      ], 10, ["onClick"])
                    ]),
                    createVNode("li", { class: "nav-item" }, [
                      createVNode("button", {
                        type: "button",
                        class: ["nav-link", { active: activeTab.value === "contents" }],
                        onClick: ($event) => activeTab.value = "contents"
                      }, [
                        createVNode("i", { class: "ti ti-file-text me-1" }),
                        createTextVNode("Conteúdos (" + toDisplayString(unref(form).contents.length) + ") ", 1)
                      ], 10, ["onClick"])
                    ])
                  ]),
                  withDirectives(createVNode("div", { class: "card mb-3" }, [
                    createVNode("div", { class: "card-body" }, [
                      createVNode("div", { class: "row g-3" }, [
                        createVNode("div", { class: "col-md-8" }, [
                          createVNode("label", { class: "form-label" }, [
                            createTextVNode("Título "),
                            createVNode("span", { class: "text-danger" }, "*")
                          ]),
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => unref(form).title = $event,
                            type: "text",
                            maxlength: "255",
                            class: ["form-control", { "is-invalid": unref(form).errors.title }],
                            required: ""
                          }, null, 10, ["onUpdate:modelValue"]), [
                            [vModelText, unref(form).title]
                          ]),
                          unref(form).errors.title ? (openBlock(), createBlock("div", {
                            key: 0,
                            class: "invalid-feedback"
                          }, toDisplayString(unref(form).errors.title), 1)) : createCommentVNode("", true)
                        ]),
                        createVNode("div", { class: "col-md-4" }, [
                          createVNode("label", { class: "form-label" }, "Categoria"),
                          withDirectives(createVNode("select", {
                            "onUpdate:modelValue": ($event) => unref(form).report_category_id = $event,
                            class: "form-select"
                          }, [
                            createVNode("option", { value: "" }, "—"),
                            (openBlock(true), createBlock(Fragment, null, renderList(__props.categories, (c) => {
                              return openBlock(), createBlock("option", {
                                key: c.id,
                                value: c.id
                              }, toDisplayString(c.name), 9, ["value"]);
                            }), 128))
                          ], 8, ["onUpdate:modelValue"]), [
                            [vModelSelect, unref(form).report_category_id]
                          ])
                        ]),
                        createVNode("div", { class: "col-12" }, [
                          createVNode("label", { class: "form-label" }, "Descrição"),
                          withDirectives(createVNode("textarea", {
                            "onUpdate:modelValue": ($event) => unref(form).description = $event,
                            rows: "2",
                            maxlength: "1000",
                            class: "form-control"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelText, unref(form).description]
                          ])
                        ]),
                        createVNode("div", { class: "col-md-4" }, [
                          createVNode("div", { class: "form-check form-switch mt-4" }, [
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => unref(form).active = $event,
                              type: "checkbox",
                              class: "form-check-input",
                              id: "field_active",
                              role: "switch"
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelCheckbox, unref(form).active]
                            ]),
                            createVNode("label", {
                              class: "form-check-label",
                              for: "field_active"
                            }, "Ativo")
                          ])
                        ])
                      ])
                    ])
                  ], 512), [
                    [vShow, activeTab.value === "general"]
                  ]),
                  withDirectives(createVNode("div", { class: "card mb-3" }, [
                    createVNode("div", { class: "card-body" }, [
                      createVNode("h6", { class: "fw-semibold mb-3" }, [
                        createVNode("i", { class: "ti ti-page-break me-1" }),
                        createTextVNode("Página")
                      ]),
                      createVNode("div", { class: "row g-3 mb-4" }, [
                        createVNode("div", { class: "col-md-3" }, [
                          createVNode("label", { class: "form-label small" }, "Tamanho"),
                          withDirectives(createVNode("select", {
                            "onUpdate:modelValue": ($event) => unref(form).paper_size = $event,
                            class: "form-select form-select-sm"
                          }, [
                            (openBlock(true), createBlock(Fragment, null, renderList(__props.paper_sizes, (ps) => {
                              return openBlock(), createBlock("option", {
                                key: ps.value,
                                value: ps.value
                              }, toDisplayString(ps.label), 9, ["value"]);
                            }), 128))
                          ], 8, ["onUpdate:modelValue"]), [
                            [vModelSelect, unref(form).paper_size]
                          ])
                        ]),
                        createVNode("div", { class: "col-md-3" }, [
                          createVNode("label", { class: "form-label small" }, "Fonte"),
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => unref(form).font_family = $event,
                            type: "text",
                            maxlength: "50",
                            class: "form-control form-control-sm"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelText, unref(form).font_family]
                          ])
                        ]),
                        createVNode("div", { class: "col-md-2" }, [
                          createVNode("label", { class: "form-label small" }, "Tamanho"),
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => unref(form).font_size = $event,
                            type: "number",
                            min: "8",
                            max: "24",
                            class: "form-control form-control-sm"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [
                              vModelText,
                              unref(form).font_size,
                              void 0,
                              { number: true }
                            ]
                          ])
                        ])
                      ]),
                      createVNode("h6", { class: "fw-semibold mb-3" }, [
                        createVNode("i", { class: "ti ti-frame me-1" }),
                        createTextVNode("Margens (cm)")
                      ]),
                      createVNode("div", { class: "row g-3 mb-4" }, [
                        createVNode("div", { class: "col-md-3" }, [
                          createVNode("label", { class: "form-label small" }, "Topo"),
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => unref(form).margin_top = $event,
                            type: "number",
                            step: "0.1",
                            min: "0",
                            max: "10",
                            class: "form-control form-control-sm"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [
                              vModelText,
                              unref(form).margin_top,
                              void 0,
                              { number: true }
                            ]
                          ])
                        ]),
                        createVNode("div", { class: "col-md-3" }, [
                          createVNode("label", { class: "form-label small" }, "Direita"),
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => unref(form).margin_right = $event,
                            type: "number",
                            step: "0.1",
                            min: "0",
                            max: "10",
                            class: "form-control form-control-sm"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [
                              vModelText,
                              unref(form).margin_right,
                              void 0,
                              { number: true }
                            ]
                          ])
                        ]),
                        createVNode("div", { class: "col-md-3" }, [
                          createVNode("label", { class: "form-label small" }, "Inferior"),
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => unref(form).margin_bottom = $event,
                            type: "number",
                            step: "0.1",
                            min: "0",
                            max: "10",
                            class: "form-control form-control-sm"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [
                              vModelText,
                              unref(form).margin_bottom,
                              void 0,
                              { number: true }
                            ]
                          ])
                        ]),
                        createVNode("div", { class: "col-md-3" }, [
                          createVNode("label", { class: "form-label small" }, "Esquerda"),
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => unref(form).margin_left = $event,
                            type: "number",
                            step: "0.1",
                            min: "0",
                            max: "10",
                            class: "form-control form-control-sm"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [
                              vModelText,
                              unref(form).margin_left,
                              void 0,
                              { number: true }
                            ]
                          ])
                        ])
                      ]),
                      createVNode("hr"),
                      createVNode("h6", { class: "fw-semibold mb-3" }, [
                        createVNode("i", { class: "ti ti-layout-navbar me-1" }),
                        createTextVNode("Cabeçalho")
                      ]),
                      createVNode("div", { class: "form-check form-switch mb-2" }, [
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => unref(form).show_header = $event,
                          type: "checkbox",
                          class: "form-check-input",
                          id: "show_header",
                          role: "switch"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelCheckbox, unref(form).show_header]
                        ]),
                        createVNode("label", {
                          class: "form-check-label",
                          for: "show_header"
                        }, "Exibir cabeçalho")
                      ]),
                      unref(form).show_header ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "row g-2 ms-3"
                      }, [
                        createVNode("div", { class: "col-md-3" }, [
                          createVNode("div", { class: "form-check" }, [
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => unref(form).header_show_logo = $event,
                              type: "checkbox",
                              class: "form-check-input",
                              id: "h_logo"
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelCheckbox, unref(form).header_show_logo]
                            ]),
                            createVNode("label", {
                              class: "form-check-label",
                              for: "h_logo"
                            }, "Logo")
                          ])
                        ]),
                        createVNode("div", { class: "col-md-3" }, [
                          createVNode("div", { class: "form-check" }, [
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => unref(form).header_show_name = $event,
                              type: "checkbox",
                              class: "form-check-input",
                              id: "h_name"
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelCheckbox, unref(form).header_show_name]
                            ]),
                            createVNode("label", {
                              class: "form-check-label",
                              for: "h_name"
                            }, "Nome")
                          ])
                        ]),
                        createVNode("div", { class: "col-md-3" }, [
                          createVNode("div", { class: "form-check" }, [
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => unref(form).header_show_address = $event,
                              type: "checkbox",
                              class: "form-check-input",
                              id: "h_addr"
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelCheckbox, unref(form).header_show_address]
                            ]),
                            createVNode("label", {
                              class: "form-check-label",
                              for: "h_addr"
                            }, "Endereço")
                          ])
                        ]),
                        createVNode("div", { class: "col-md-3" }, [
                          createVNode("div", { class: "form-check" }, [
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => unref(form).header_show_phone = $event,
                              type: "checkbox",
                              class: "form-check-input",
                              id: "h_phone"
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelCheckbox, unref(form).header_show_phone]
                            ]),
                            createVNode("label", {
                              class: "form-check-label",
                              for: "h_phone"
                            }, "Telefone")
                          ])
                        ])
                      ])) : createCommentVNode("", true),
                      createVNode("hr"),
                      createVNode("h6", { class: "fw-semibold mb-3" }, [
                        createVNode("i", { class: "ti ti-signature me-1" }),
                        createTextVNode("Assinatura")
                      ]),
                      createVNode("div", { class: "form-check form-switch mb-2" }, [
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => unref(form).show_signature = $event,
                          type: "checkbox",
                          class: "form-check-input",
                          id: "show_sig",
                          role: "switch"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelCheckbox, unref(form).show_signature]
                        ]),
                        createVNode("label", {
                          class: "form-check-label",
                          for: "show_sig"
                        }, "Exibir assinatura")
                      ]),
                      unref(form).show_signature ? (openBlock(), createBlock("div", {
                        key: 1,
                        class: "row g-2 ms-3"
                      }, [
                        createVNode("div", { class: "col-md-3" }, [
                          createVNode("div", { class: "form-check" }, [
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => unref(form).signature_show_name = $event,
                              type: "checkbox",
                              class: "form-check-input",
                              id: "s_name"
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelCheckbox, unref(form).signature_show_name]
                            ]),
                            createVNode("label", {
                              class: "form-check-label",
                              for: "s_name"
                            }, "Nome")
                          ])
                        ]),
                        createVNode("div", { class: "col-md-3" }, [
                          createVNode("div", { class: "form-check" }, [
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => unref(form).signature_show_crm = $event,
                              type: "checkbox",
                              class: "form-check-input",
                              id: "s_crm"
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelCheckbox, unref(form).signature_show_crm]
                            ]),
                            createVNode("label", {
                              class: "form-check-label",
                              for: "s_crm"
                            }, "CRM")
                          ])
                        ]),
                        createVNode("div", { class: "col-md-3" }, [
                          createVNode("div", { class: "form-check" }, [
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => unref(form).signature_show_rqe = $event,
                              type: "checkbox",
                              class: "form-check-input",
                              id: "s_rqe"
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelCheckbox, unref(form).signature_show_rqe]
                            ]),
                            createVNode("label", {
                              class: "form-check-label",
                              for: "s_rqe"
                            }, "RQE")
                          ])
                        ])
                      ])) : createCommentVNode("", true),
                      createVNode("hr"),
                      createVNode("h6", { class: "fw-semibold mb-3" }, [
                        createVNode("i", { class: "ti ti-layout-bottombar me-1" }),
                        createTextVNode("Rodapé")
                      ]),
                      createVNode("div", { class: "form-check form-switch mb-2" }, [
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => unref(form).show_footer = $event,
                          type: "checkbox",
                          class: "form-check-input",
                          id: "show_footer",
                          role: "switch"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelCheckbox, unref(form).show_footer]
                        ]),
                        createVNode("label", {
                          class: "form-check-label",
                          for: "show_footer"
                        }, "Exibir rodapé")
                      ]),
                      unref(form).show_footer ? (openBlock(), createBlock("div", {
                        key: 2,
                        class: "ms-3"
                      }, [
                        createVNode("div", { class: "mb-2" }, [
                          createVNode("label", { class: "form-label small" }, "Texto do rodapé"),
                          withDirectives(createVNode("textarea", {
                            "onUpdate:modelValue": ($event) => unref(form).footer_text = $event,
                            rows: "2",
                            maxlength: "500",
                            class: "form-control form-control-sm"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [vModelText, unref(form).footer_text]
                          ])
                        ]),
                        createVNode("div", { class: "row g-2" }, [
                          createVNode("div", { class: "col-md-4" }, [
                            createVNode("div", { class: "form-check" }, [
                              withDirectives(createVNode("input", {
                                "onUpdate:modelValue": ($event) => unref(form).footer_show_address = $event,
                                type: "checkbox",
                                class: "form-check-input",
                                id: "f_addr"
                              }, null, 8, ["onUpdate:modelValue"]), [
                                [vModelCheckbox, unref(form).footer_show_address]
                              ]),
                              createVNode("label", {
                                class: "form-check-label",
                                for: "f_addr"
                              }, "Endereço")
                            ])
                          ]),
                          createVNode("div", { class: "col-md-4" }, [
                            createVNode("div", { class: "form-check" }, [
                              withDirectives(createVNode("input", {
                                "onUpdate:modelValue": ($event) => unref(form).footer_show_phone = $event,
                                type: "checkbox",
                                class: "form-check-input",
                                id: "f_phone"
                              }, null, 8, ["onUpdate:modelValue"]), [
                                [vModelCheckbox, unref(form).footer_show_phone]
                              ]),
                              createVNode("label", {
                                class: "form-check-label",
                                for: "f_phone"
                              }, "Telefone")
                            ])
                          ])
                        ])
                      ])) : createCommentVNode("", true)
                    ])
                  ], 512), [
                    [vShow, activeTab.value === "layout"]
                  ]),
                  withDirectives(createVNode("div", { class: "card mb-3" }, [
                    createVNode("div", { class: "card-body" }, [
                      createVNode("div", { class: "d-flex justify-content-between align-items-center mb-3" }, [
                        createVNode("h6", { class: "fw-semibold mb-0" }, "Conteúdos disponíveis"),
                        createVNode("button", {
                          type: "button",
                          class: "btn btn-sm btn-outline-primary",
                          onClick: addContent
                        }, [
                          createVNode("i", { class: "ti ti-plus me-1" }),
                          createTextVNode("Adicionar variante ")
                        ])
                      ]),
                      unref(form).contents.length === 0 ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "text-muted small text-center py-3"
                      }, ' Nenhum conteúdo. Clique em "Adicionar variante" para criar. ')) : createCommentVNode("", true),
                      (openBlock(true), createBlock(Fragment, null, renderList(unref(form).contents, (content, idx) => {
                        return openBlock(), createBlock("div", {
                          key: idx,
                          class: "card border mb-3"
                        }, [
                          createVNode("div", { class: "card-body" }, [
                            createVNode("div", { class: "row g-2 mb-2" }, [
                              createVNode("div", { class: "col-md-4" }, [
                                createVNode("label", { class: "form-label small" }, "Tipo"),
                                withDirectives(createVNode("select", {
                                  "onUpdate:modelValue": ($event) => content.type = $event,
                                  class: "form-select form-select-sm"
                                }, [
                                  (openBlock(true), createBlock(Fragment, null, renderList(__props.documentation_types, (t) => {
                                    return openBlock(), createBlock("option", {
                                      key: t.value,
                                      value: t.value
                                    }, toDisplayString(t.label), 9, ["value"]);
                                  }), 128))
                                ], 8, ["onUpdate:modelValue"]), [
                                  [vModelSelect, content.type]
                                ])
                              ]),
                              createVNode("div", { class: "col-md-6" }, [
                                createVNode("label", { class: "form-label small" }, 'Rótulo (ex: "Receita controlada")'),
                                withDirectives(createVNode("input", {
                                  "onUpdate:modelValue": ($event) => content.label = $event,
                                  type: "text",
                                  maxlength: "255",
                                  class: "form-control form-control-sm",
                                  required: ""
                                }, null, 8, ["onUpdate:modelValue"]), [
                                  [vModelText, content.label]
                                ])
                              ]),
                              createVNode("div", { class: "col-md-2 d-flex align-items-end gap-2" }, [
                                createVNode("div", { class: "form-check form-switch mb-1" }, [
                                  withDirectives(createVNode("input", {
                                    "onUpdate:modelValue": ($event) => content.active = $event,
                                    type: "checkbox",
                                    class: "form-check-input",
                                    id: `active_${idx}`,
                                    role: "switch"
                                  }, null, 8, ["onUpdate:modelValue", "id"]), [
                                    [vModelCheckbox, content.active]
                                  ]),
                                  createVNode("label", {
                                    class: "form-check-label small",
                                    for: `active_${idx}`
                                  }, "Ativo", 8, ["for"])
                                ]),
                                createVNode("button", {
                                  type: "button",
                                  class: "btn btn-sm btn-outline-danger ms-auto",
                                  onClick: ($event) => removeContent(idx),
                                  title: "Remover"
                                }, [
                                  createVNode("i", { class: "ti ti-trash" })
                                ], 8, ["onClick"])
                              ])
                            ]),
                            createVNode("label", { class: "form-label small" }, "Conteúdo (suporta HTML + placeholders)"),
                            withDirectives(createVNode("textarea", {
                              "onUpdate:modelValue": ($event) => content.content = $event,
                              rows: "8",
                              class: "form-control font-monospace small",
                              required: ""
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelText, content.content]
                            ])
                          ])
                        ]);
                      }), 128))
                    ])
                  ], 512), [
                    [vShow, activeTab.value === "contents"]
                  ]),
                  createVNode("div", { class: "d-flex justify-content-end gap-2" }, [
                    createVNode(unref(Link), {
                      href: __props.urls.index,
                      class: "btn btn-light"
                    }, {
                      default: withCtx(() => [
                        createTextVNode("Cancelar")
                      ]),
                      _: 1
                    }, 8, ["href"]),
                    createVNode("button", {
                      type: "submit",
                      class: "btn btn-primary",
                      disabled: unref(form).processing
                    }, [
                      unref(form).processing ? (openBlock(), createBlock("span", {
                        key: 0,
                        class: "spinner-border spinner-border-sm me-1"
                      })) : (openBlock(), createBlock("i", {
                        key: 1,
                        class: "ti ti-device-floppy me-1"
                      })),
                      createTextVNode(" " + toDisplayString(isEdit.value ? "Salvar alterações" : "Cadastrar"), 1)
                    ], 8, ["disabled"])
                  ])
                ], 32)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Settings/ReportSettings/Form.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
