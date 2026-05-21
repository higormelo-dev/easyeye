import { ref, computed, mergeProps, withCtx, createVNode, toDisplayString, createTextVNode, openBlock, createBlock, createCommentVNode, withDirectives, vModelText, Fragment, renderList, vModelCheckbox, Transition, vModelSelect, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderStyle, ssrRenderAttr, ssrRenderList, ssrRenderClass, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual } from "vue/server-renderer";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
import "@inertiajs/vue3";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
const _sfc_main = {
  __name: "WorkSchedule",
  __ssrInlineRender: true,
  props: {
    doctor: { type: Object, required: true },
    days: { type: Array, required: true },
    interval: { type: [Number, String], default: null },
    entityInterval: { type: Number, default: 15 },
    blocks: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    var _a, _b, _c;
    const props = __props;
    const csrf = ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? "";
    const localDays = ref(JSON.parse(JSON.stringify(props.days)));
    const localInterval = ref(props.interval ?? null);
    const localBlocks = ref([...props.blocks]);
    const saving = ref(false);
    const saveSuccess = ref(false);
    const saveError = ref("");
    const activeDaysCount = computed(() => localDays.value.filter((d) => d.active).length);
    function addRange(dayIndex) {
      localDays.value[dayIndex].ranges.push({ starts_at: "08:00", ends_at: "12:00" });
    }
    function removeRange(dayIndex, rangeIndex) {
      localDays.value[dayIndex].ranges.splice(rangeIndex, 1);
    }
    async function saveSchedule() {
      saving.value = true;
      saveSuccess.value = false;
      saveError.value = "";
      try {
        const res = await fetch(props.urls.sync, {
          method: "PUT",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": csrf,
            "X-Requested-With": "XMLHttpRequest",
            "Accept": "application/json"
          },
          body: JSON.stringify({
            schedule_interval: localInterval.value || null,
            days: localDays.value
          })
        });
        const data = await res.json();
        if (!res.ok) {
          saveError.value = data.message ?? (props.t.work_schedule_save_error ?? "Erro ao salvar escala.");
          return;
        }
        saveSuccess.value = true;
        if (window.showSuccessToast) window.showSuccessToast(data.message);
        setTimeout(() => {
          saveSuccess.value = false;
        }, 3e3);
      } catch (e) {
        saveError.value = props.t.work_schedule_save_error ?? "Erro de conexão.";
      } finally {
        saving.value = false;
      }
    }
    const showBlockForm = ref(false);
    const storingBlock = ref(false);
    const blockErrors = ref({});
    const blockForm = ref({
      type: "absence",
      starts_at: "",
      ends_at: "",
      reason: ""
    });
    function resetBlockForm() {
      blockForm.value = { type: "absence", starts_at: "", ends_at: "", reason: "" };
      blockErrors.value = {};
    }
    async function storeBlock() {
      storingBlock.value = true;
      blockErrors.value = {};
      try {
        const res = await fetch(props.urls.store_block, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": csrf,
            "X-Requested-With": "XMLHttpRequest",
            "Accept": "application/json"
          },
          body: JSON.stringify(blockForm.value)
        });
        const data = await res.json();
        if (res.status === 422) {
          blockErrors.value = data.errors ?? {};
          return;
        }
        if (!res.ok) {
          if (window.showErrorToast) window.showErrorToast(data.message ?? "Erro ao criar bloqueio.");
          return;
        }
        localBlocks.value.push(data.data);
        localBlocks.value.sort((a, b) => a.starts_at.localeCompare(b.starts_at));
        resetBlockForm();
        showBlockForm.value = false;
        if (window.showSuccessToast) window.showSuccessToast(data.message);
      } catch (e) {
        if (window.showErrorToast) window.showErrorToast("Erro de conexão.");
      } finally {
        storingBlock.value = false;
      }
    }
    async function destroyBlock(blockId) {
      if (!confirm(props.t.confirm_remove_block ?? "Remover bloqueio? Esta ação não pode ser desfeita.")) {
        return;
      }
      try {
        const res = await fetch(`${props.urls.destroy_block}/${blockId}`, {
          method: "DELETE",
          headers: {
            "X-CSRF-TOKEN": csrf,
            "X-Requested-With": "XMLHttpRequest",
            "Accept": "application/json"
          }
        });
        const data = await res.json();
        if (!res.ok) {
          if (window.showErrorToast) window.showErrorToast(data.message ?? "Erro ao remover.");
          return;
        }
        localBlocks.value = localBlocks.value.filter((b) => b.id !== blockId);
        if (window.showSuccessToast) window.showSuccessToast(data.message);
      } catch (e) {
        if (window.showErrorToast) window.showErrorToast("Erro de conexão.");
      }
    }
    const breadcrumbs = [
      { label: ((_b = props.t.sidemenu) == null ? void 0 : _b.dashboard) ?? "Dashboard", url: route("panel.dashboard"), active: false },
      { label: ((_c = props.t.sidemenu) == null ? void 0 : _c.doctors) ?? "Médicos", url: route("panel.doctors.index"), active: false },
      { label: props.t.work_schedule ?? "Escala de Atendimento", url: "#", active: true }
    ];
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: __props.t.work_schedule ?? "Escala de Atendimento",
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="d-flex align-items-center gap-2 pb-3 mb-3 border-bottom flex-wrap" data-v-8f7b3083${_scopeId}><div class="d-flex align-items-center gap-2 me-auto" data-v-8f7b3083${_scopeId}><h4 class="mb-0 fw-bold" data-v-8f7b3083${_scopeId}>${ssrInterpolate(__props.t.work_schedule ?? "Escala de Atendimento")}</h4><span style="${ssrRenderStyle({ "font-size": ".78rem", "font-weight": "600", "color": "#0d6efd", "background": "#eff4ff", "border": "1.5px solid #0d6efd", "border-radius": "20px", "padding": "2px 12px", "white-space": "nowrap", "line-height": "1.6" })}" data-v-8f7b3083${_scopeId}>${ssrInterpolate(activeDaysCount.value)} ${ssrInterpolate(activeDaysCount.value === 1 ? "dia ativo" : "dias ativos")}</span></div><a${ssrRenderAttr("href", __props.urls.back)} class="btn btn-sm btn-outline-secondary" data-v-8f7b3083${_scopeId}><i class="ti ti-arrow-left me-1" data-v-8f7b3083${_scopeId}></i> ${ssrInterpolate(__props.t.back ?? "Voltar")}</a></div><div class="card mb-3" data-v-8f7b3083${_scopeId}><div class="card-body py-3" data-v-8f7b3083${_scopeId}><div class="d-flex align-items-center gap-3" data-v-8f7b3083${_scopeId}><img${ssrRenderAttr("src", __props.doctor.photo_url)}${ssrRenderAttr("alt", __props.doctor.name)} class="rounded-circle flex-shrink-0" width="56" height="56" style="${ssrRenderStyle({ objectFit: "cover", border: `3px solid ${__props.doctor.color}` })}" data-v-8f7b3083${_scopeId}><div data-v-8f7b3083${_scopeId}><h5 class="mb-0 fw-bold" style="${ssrRenderStyle({ color: __props.doctor.color })}" data-v-8f7b3083${_scopeId}>${ssrInterpolate(__props.doctor.name)}</h5><small class="text-muted" data-v-8f7b3083${_scopeId}>`);
            if (__props.doctor.record) {
              _push2(`<span data-v-8f7b3083${_scopeId}>CRM ${ssrInterpolate(__props.doctor.record)} — </span>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<code data-v-8f7b3083${_scopeId}>${ssrInterpolate(__props.doctor.code)}</code></small></div></div></div></div><div class="row g-3" data-v-8f7b3083${_scopeId}><div class="col-lg-8" data-v-8f7b3083${_scopeId}><div class="card" data-v-8f7b3083${_scopeId}><div class="card-header d-flex align-items-center justify-content-between" data-v-8f7b3083${_scopeId}><span data-v-8f7b3083${_scopeId}><i class="fas fa-clock me-2 text-info" data-v-8f7b3083${_scopeId}></i>${ssrInterpolate(__props.t.work_schedule ?? "Horários de Atendimento")}</span><span class="badge bg-secondary" data-v-8f7b3083${_scopeId}>${ssrInterpolate(activeDaysCount.value)} ${ssrInterpolate(activeDaysCount.value === 1 ? "dia ativo" : "dias ativos")}</span></div><div class="card-body" data-v-8f7b3083${_scopeId}><div class="mb-4 p-3 bg-light rounded" data-v-8f7b3083${_scopeId}><label class="form-label fw-semibold mb-1" data-v-8f7b3083${_scopeId}><i class="fas fa-stopwatch me-1 text-secondary" data-v-8f7b3083${_scopeId}></i> ${ssrInterpolate(__props.t.work_schedule_interval ?? "Intervalo entre consultas")}</label><div class="d-flex align-items-center gap-2" style="${ssrRenderStyle({ "max-width": "280px" })}" data-v-8f7b3083${_scopeId}><input${ssrRenderAttr("value", localInterval.value)} type="number" class="form-control form-control-sm" min="5" max="120" step="5" placeholder="Ex: 15" data-v-8f7b3083${_scopeId}><span class="text-muted small flex-shrink-0" data-v-8f7b3083${_scopeId}>min</span></div><small class="text-muted d-block mt-1" data-v-8f7b3083${_scopeId}>${ssrInterpolate(__props.t.clinic_default ?? "Padrão da clínica")}: ${ssrInterpolate(__props.entityInterval)} min. </small></div><!--[-->`);
            ssrRenderList(localDays.value, (day, dayIndex) => {
              _push2(`<div class="border rounded mb-2 overflow-hidden" data-v-8f7b3083${_scopeId}><div class="${ssrRenderClass([day.active ? "bg-info bg-opacity-10" : "bg-light", "d-flex align-items-center px-3 py-2"])}" data-v-8f7b3083${_scopeId}><div class="form-check form-switch mb-0 flex-grow-1" data-v-8f7b3083${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(day.active) ? ssrLooseContain(day.active, null) : day.active) ? " checked" : ""} type="checkbox" class="form-check-input"${ssrRenderAttr("id", `day-toggle-${day.day}`)} data-v-8f7b3083${_scopeId}><label${ssrRenderAttr("for", `day-toggle-${day.day}`)} class="${ssrRenderClass([day.active ? "text-dark" : "text-muted", "form-check-label fw-semibold"])}" data-v-8f7b3083${_scopeId}>${ssrInterpolate(day.name)}</label></div>`);
              if (day.active) {
                _push2(`<button type="button" class="btn btn-sm btn-outline-info"${ssrRenderAttr("title", __props.t.work_schedule_add_range ?? "Adicionar faixa de horário")} data-v-8f7b3083${_scopeId}><i class="fas fa-plus me-1" data-v-8f7b3083${_scopeId}></i> ${ssrInterpolate(__props.t.work_schedule_add_range ?? "Faixa")}</button>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div>`);
              if (day.active) {
                _push2(`<div class="px-3 pb-3 pt-2" data-v-8f7b3083${_scopeId}><!--[-->`);
                ssrRenderList(day.ranges, (range, rangeIndex) => {
                  _push2(`<div class="d-flex align-items-center gap-2 mb-2" data-v-8f7b3083${_scopeId}><span class="text-muted small flex-shrink-0" style="${ssrRenderStyle({ "width": "30px" })}" data-v-8f7b3083${_scopeId}>${ssrInterpolate(__props.t.work_schedule_time_from ?? "De")}</span><input${ssrRenderAttr("value", range.starts_at)} type="time" class="form-control form-control-sm" style="${ssrRenderStyle({ "max-width": "120px" })}" data-v-8f7b3083${_scopeId}><span class="text-muted small flex-shrink-0" data-v-8f7b3083${_scopeId}>${ssrInterpolate(__props.t.work_schedule_time_until ?? "até")}</span><input${ssrRenderAttr("value", range.ends_at)} type="time" class="form-control form-control-sm" style="${ssrRenderStyle({ "max-width": "120px" })}" data-v-8f7b3083${_scopeId}>`);
                  if (day.ranges.length > 1) {
                    _push2(`<button type="button" class="btn btn-sm btn-outline-danger"${ssrRenderAttr("title", __props.t.remove ?? "Remover faixa")} data-v-8f7b3083${_scopeId}><i class="fas fa-times" data-v-8f7b3083${_scopeId}></i></button>`);
                  } else {
                    _push2(`<!---->`);
                  }
                  _push2(`</div>`);
                });
                _push2(`<!--]-->`);
                if (day.ranges.length === 0) {
                  _push2(`<div class="text-muted small" data-v-8f7b3083${_scopeId}>${ssrInterpolate(__props.t.work_schedule_empty_ranges ?? 'Nenhuma faixa de horário. Clique em "+ Faixa" para adicionar.')}</div>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</div>`);
              } else {
                _push2(`<div class="px-3 pb-2 pt-1" data-v-8f7b3083${_scopeId}><small class="text-muted" data-v-8f7b3083${_scopeId}>${ssrInterpolate(__props.t.work_schedule_day_off ?? "Médico não atende neste dia.")}</small></div>`);
              }
              _push2(`</div>`);
            });
            _push2(`<!--]--></div><div class="card-footer d-flex align-items-center gap-2" data-v-8f7b3083${_scopeId}><button type="button" class="btn btn-info"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""} data-v-8f7b3083${_scopeId}>`);
            if (saving.value) {
              _push2(`<span class="spinner-border spinner-border-sm me-1" data-v-8f7b3083${_scopeId}></span>`);
            } else {
              _push2(`<i class="far fa-save me-1" data-v-8f7b3083${_scopeId}></i>`);
            }
            _push2(` ${ssrInterpolate(__props.t.work_schedule_save ?? "Salvar Escala")}</button>`);
            if (saveSuccess.value) {
              _push2(`<span class="text-success small" data-v-8f7b3083${_scopeId}><i class="fas fa-check-circle me-1" data-v-8f7b3083${_scopeId}></i> ${ssrInterpolate(__props.t.work_schedule_saved ?? "Salvo com sucesso.")}</span>`);
            } else {
              _push2(`<!---->`);
            }
            if (saveError.value) {
              _push2(`<span class="text-danger small" data-v-8f7b3083${_scopeId}>${ssrInterpolate(saveError.value)}</span>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div></div><div class="col-lg-4" data-v-8f7b3083${_scopeId}><div class="card" data-v-8f7b3083${_scopeId}><div class="card-header d-flex align-items-center justify-content-between" data-v-8f7b3083${_scopeId}><span data-v-8f7b3083${_scopeId}><i class="fas fa-ban me-2 text-danger" data-v-8f7b3083${_scopeId}></i>${ssrInterpolate(__props.t.work_schedule_blocks ?? "Bloqueios / Ausências")}</span><button type="button" class="btn btn-sm btn-outline-danger"${ssrRenderAttr("title", showBlockForm.value ? __props.t.cancel ?? "Cancelar" : __props.t.block_add ?? "Adicionar Bloqueio")} data-v-8f7b3083${_scopeId}><i class="${ssrRenderClass(showBlockForm.value ? "fas fa-times" : "fas fa-plus")}" data-v-8f7b3083${_scopeId}></i></button></div>`);
            if (showBlockForm.value) {
              _push2(`<div class="card-body border-bottom pb-3" data-v-8f7b3083${_scopeId}><div class="row g-2" data-v-8f7b3083${_scopeId}><div class="col-12" data-v-8f7b3083${_scopeId}><label class="form-label small mb-1" data-v-8f7b3083${_scopeId}>${ssrInterpolate(__props.t.block_type ?? "Tipo")}</label><select class="form-select form-select-sm" data-v-8f7b3083${_scopeId}><option value="absence" data-v-8f7b3083${ssrIncludeBooleanAttr(Array.isArray(blockForm.value.type) ? ssrLooseContain(blockForm.value.type, "absence") : ssrLooseEqual(blockForm.value.type, "absence")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.block_type_absence ?? "Ausência")}</option><option value="holiday" data-v-8f7b3083${ssrIncludeBooleanAttr(Array.isArray(blockForm.value.type) ? ssrLooseContain(blockForm.value.type, "holiday") : ssrLooseEqual(blockForm.value.type, "holiday")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.block_type_holiday ?? "Feriado")}</option><option value="meeting" data-v-8f7b3083${ssrIncludeBooleanAttr(Array.isArray(blockForm.value.type) ? ssrLooseContain(blockForm.value.type, "meeting") : ssrLooseEqual(blockForm.value.type, "meeting")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.block_type_meeting ?? "Reunião / Compromisso")}</option><option value="other" data-v-8f7b3083${ssrIncludeBooleanAttr(Array.isArray(blockForm.value.type) ? ssrLooseContain(blockForm.value.type, "other") : ssrLooseEqual(blockForm.value.type, "other")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.block_type_other ?? "Outro")}</option></select></div><div class="col-12" data-v-8f7b3083${_scopeId}><label class="form-label small mb-1" data-v-8f7b3083${_scopeId}>${ssrInterpolate(__props.t.starts_at ?? "Início")} <span class="text-danger" data-v-8f7b3083${_scopeId}>*</span></label><input${ssrRenderAttr("value", blockForm.value.starts_at)} type="datetime-local" class="${ssrRenderClass([{ "is-invalid": blockErrors.value.starts_at }, "form-control form-control-sm"])}" data-v-8f7b3083${_scopeId}>`);
              if (blockErrors.value.starts_at) {
                _push2(`<div class="invalid-feedback" data-v-8f7b3083${_scopeId}>${ssrInterpolate(blockErrors.value.starts_at[0])}</div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><div class="col-12" data-v-8f7b3083${_scopeId}><label class="form-label small mb-1" data-v-8f7b3083${_scopeId}>${ssrInterpolate(__props.t.ends_at ?? "Fim")} <span class="text-danger" data-v-8f7b3083${_scopeId}>*</span></label><input${ssrRenderAttr("value", blockForm.value.ends_at)} type="datetime-local" class="${ssrRenderClass([{ "is-invalid": blockErrors.value.ends_at }, "form-control form-control-sm"])}" data-v-8f7b3083${_scopeId}>`);
              if (blockErrors.value.ends_at) {
                _push2(`<div class="invalid-feedback" data-v-8f7b3083${_scopeId}>${ssrInterpolate(blockErrors.value.ends_at[0])}</div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><div class="col-12" data-v-8f7b3083${_scopeId}><label class="form-label small mb-1" data-v-8f7b3083${_scopeId}>${ssrInterpolate(__props.t.reason ?? "Motivo")}</label><input${ssrRenderAttr("value", blockForm.value.reason)} type="text" class="form-control form-control-sm"${ssrRenderAttr("placeholder", __props.t.block_reason_placeholder ?? "Motivo (opcional)")} data-v-8f7b3083${_scopeId}></div><div class="col-12 d-flex gap-2" data-v-8f7b3083${_scopeId}><button type="button" class="btn btn-sm btn-danger w-100"${ssrIncludeBooleanAttr(storingBlock.value) ? " disabled" : ""} data-v-8f7b3083${_scopeId}>`);
              if (storingBlock.value) {
                _push2(`<span class="spinner-border spinner-border-sm me-1" data-v-8f7b3083${_scopeId}></span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(` ${ssrInterpolate(__props.t.block_add ?? "Adicionar Bloqueio")}</button></div></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="card-body p-0" data-v-8f7b3083${_scopeId}>`);
            if (localBlocks.value.length === 0) {
              _push2(`<div class="text-center py-4 text-muted" data-v-8f7b3083${_scopeId}><i class="fas fa-check-circle fa-2x mb-2 text-success" data-v-8f7b3083${_scopeId}></i><p class="small mb-0" data-v-8f7b3083${_scopeId}>${ssrInterpolate(__props.t.work_schedule_no_blocks ?? "Nenhum bloqueio futuro.")}</p></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(localBlocks.value, (block) => {
              _push2(`<div class="d-flex align-items-start gap-2 px-3 py-2 border-bottom" data-v-8f7b3083${_scopeId}><div class="flex-grow-1" data-v-8f7b3083${_scopeId}><div class="fw-semibold small" data-v-8f7b3083${_scopeId}>${ssrInterpolate(block.type_label)}</div><div class="text-muted" style="${ssrRenderStyle({ "font-size": ".8rem" })}" data-v-8f7b3083${_scopeId}><i class="fas fa-calendar-alt me-1" data-v-8f7b3083${_scopeId}></i><span data-v-8f7b3083${_scopeId}>${ssrInterpolate(block.starts_at)}</span><span class="mx-1" data-v-8f7b3083${_scopeId}>→</span><span data-v-8f7b3083${_scopeId}>${ssrInterpolate(block.ends_at)}</span></div>`);
              if (block.reason) {
                _push2(`<div class="text-secondary" style="${ssrRenderStyle({ "font-size": ".8rem" })}" data-v-8f7b3083${_scopeId}>${ssrInterpolate(block.reason)}</div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0"${ssrRenderAttr("title", __props.t.remove ?? "Remover bloqueio")} data-v-8f7b3083${_scopeId}><i class="fas fa-trash fa-xs" data-v-8f7b3083${_scopeId}></i></button></div>`);
            });
            _push2(`<!--]--></div></div></div></div>`);
          } else {
            return [
              createVNode("div", { class: "d-flex align-items-center gap-2 pb-3 mb-3 border-bottom flex-wrap" }, [
                createVNode("div", { class: "d-flex align-items-center gap-2 me-auto" }, [
                  createVNode("h4", { class: "mb-0 fw-bold" }, toDisplayString(__props.t.work_schedule ?? "Escala de Atendimento"), 1),
                  createVNode("span", { style: { "font-size": ".78rem", "font-weight": "600", "color": "#0d6efd", "background": "#eff4ff", "border": "1.5px solid #0d6efd", "border-radius": "20px", "padding": "2px 12px", "white-space": "nowrap", "line-height": "1.6" } }, toDisplayString(activeDaysCount.value) + " " + toDisplayString(activeDaysCount.value === 1 ? "dia ativo" : "dias ativos"), 1)
                ]),
                createVNode("a", {
                  href: __props.urls.back,
                  class: "btn btn-sm btn-outline-secondary"
                }, [
                  createVNode("i", { class: "ti ti-arrow-left me-1" }),
                  createTextVNode(" " + toDisplayString(__props.t.back ?? "Voltar"), 1)
                ], 8, ["href"])
              ]),
              createVNode("div", { class: "card mb-3" }, [
                createVNode("div", { class: "card-body py-3" }, [
                  createVNode("div", { class: "d-flex align-items-center gap-3" }, [
                    createVNode("img", {
                      src: __props.doctor.photo_url,
                      alt: __props.doctor.name,
                      class: "rounded-circle flex-shrink-0",
                      width: "56",
                      height: "56",
                      style: { objectFit: "cover", border: `3px solid ${__props.doctor.color}` }
                    }, null, 12, ["src", "alt"]),
                    createVNode("div", null, [
                      createVNode("h5", {
                        class: "mb-0 fw-bold",
                        style: { color: __props.doctor.color }
                      }, toDisplayString(__props.doctor.name), 5),
                      createVNode("small", { class: "text-muted" }, [
                        __props.doctor.record ? (openBlock(), createBlock("span", { key: 0 }, "CRM " + toDisplayString(__props.doctor.record) + " — ", 1)) : createCommentVNode("", true),
                        createVNode("code", null, toDisplayString(__props.doctor.code), 1)
                      ])
                    ])
                  ])
                ])
              ]),
              createVNode("div", { class: "row g-3" }, [
                createVNode("div", { class: "col-lg-8" }, [
                  createVNode("div", { class: "card" }, [
                    createVNode("div", { class: "card-header d-flex align-items-center justify-content-between" }, [
                      createVNode("span", null, [
                        createVNode("i", { class: "fas fa-clock me-2 text-info" }),
                        createTextVNode(toDisplayString(__props.t.work_schedule ?? "Horários de Atendimento"), 1)
                      ]),
                      createVNode("span", { class: "badge bg-secondary" }, toDisplayString(activeDaysCount.value) + " " + toDisplayString(activeDaysCount.value === 1 ? "dia ativo" : "dias ativos"), 1)
                    ]),
                    createVNode("div", { class: "card-body" }, [
                      createVNode("div", { class: "mb-4 p-3 bg-light rounded" }, [
                        createVNode("label", { class: "form-label fw-semibold mb-1" }, [
                          createVNode("i", { class: "fas fa-stopwatch me-1 text-secondary" }),
                          createTextVNode(" " + toDisplayString(__props.t.work_schedule_interval ?? "Intervalo entre consultas"), 1)
                        ]),
                        createVNode("div", {
                          class: "d-flex align-items-center gap-2",
                          style: { "max-width": "280px" }
                        }, [
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => localInterval.value = $event,
                            type: "number",
                            class: "form-control form-control-sm",
                            min: "5",
                            max: "120",
                            step: "5",
                            placeholder: "Ex: 15"
                          }, null, 8, ["onUpdate:modelValue"]), [
                            [
                              vModelText,
                              localInterval.value,
                              void 0,
                              { number: true }
                            ]
                          ]),
                          createVNode("span", { class: "text-muted small flex-shrink-0" }, "min")
                        ]),
                        createVNode("small", { class: "text-muted d-block mt-1" }, toDisplayString(__props.t.clinic_default ?? "Padrão da clínica") + ": " + toDisplayString(__props.entityInterval) + " min. ", 1)
                      ]),
                      (openBlock(true), createBlock(Fragment, null, renderList(localDays.value, (day, dayIndex) => {
                        return openBlock(), createBlock("div", {
                          key: day.day,
                          class: "border rounded mb-2 overflow-hidden"
                        }, [
                          createVNode("div", {
                            class: ["d-flex align-items-center px-3 py-2", day.active ? "bg-info bg-opacity-10" : "bg-light"]
                          }, [
                            createVNode("div", { class: "form-check form-switch mb-0 flex-grow-1" }, [
                              withDirectives(createVNode("input", {
                                "onUpdate:modelValue": ($event) => day.active = $event,
                                type: "checkbox",
                                class: "form-check-input",
                                id: `day-toggle-${day.day}`
                              }, null, 8, ["onUpdate:modelValue", "id"]), [
                                [vModelCheckbox, day.active]
                              ]),
                              createVNode("label", {
                                class: ["form-check-label fw-semibold", day.active ? "text-dark" : "text-muted"],
                                for: `day-toggle-${day.day}`
                              }, toDisplayString(day.name), 11, ["for"])
                            ]),
                            day.active ? (openBlock(), createBlock("button", {
                              key: 0,
                              type: "button",
                              class: "btn btn-sm btn-outline-info",
                              title: __props.t.work_schedule_add_range ?? "Adicionar faixa de horário",
                              onClick: ($event) => addRange(dayIndex)
                            }, [
                              createVNode("i", { class: "fas fa-plus me-1" }),
                              createTextVNode(" " + toDisplayString(__props.t.work_schedule_add_range ?? "Faixa"), 1)
                            ], 8, ["title", "onClick"])) : createCommentVNode("", true)
                          ], 2),
                          day.active ? (openBlock(), createBlock("div", {
                            key: 0,
                            class: "px-3 pb-3 pt-2"
                          }, [
                            (openBlock(true), createBlock(Fragment, null, renderList(day.ranges, (range, rangeIndex) => {
                              return openBlock(), createBlock("div", {
                                key: rangeIndex,
                                class: "d-flex align-items-center gap-2 mb-2"
                              }, [
                                createVNode("span", {
                                  class: "text-muted small flex-shrink-0",
                                  style: { "width": "30px" }
                                }, toDisplayString(__props.t.work_schedule_time_from ?? "De"), 1),
                                withDirectives(createVNode("input", {
                                  "onUpdate:modelValue": ($event) => range.starts_at = $event,
                                  type: "time",
                                  class: "form-control form-control-sm",
                                  style: { "max-width": "120px" }
                                }, null, 8, ["onUpdate:modelValue"]), [
                                  [vModelText, range.starts_at]
                                ]),
                                createVNode("span", { class: "text-muted small flex-shrink-0" }, toDisplayString(__props.t.work_schedule_time_until ?? "até"), 1),
                                withDirectives(createVNode("input", {
                                  "onUpdate:modelValue": ($event) => range.ends_at = $event,
                                  type: "time",
                                  class: "form-control form-control-sm",
                                  style: { "max-width": "120px" }
                                }, null, 8, ["onUpdate:modelValue"]), [
                                  [vModelText, range.ends_at]
                                ]),
                                day.ranges.length > 1 ? (openBlock(), createBlock("button", {
                                  key: 0,
                                  type: "button",
                                  class: "btn btn-sm btn-outline-danger",
                                  title: __props.t.remove ?? "Remover faixa",
                                  onClick: ($event) => removeRange(dayIndex, rangeIndex)
                                }, [
                                  createVNode("i", { class: "fas fa-times" })
                                ], 8, ["title", "onClick"])) : createCommentVNode("", true)
                              ]);
                            }), 128)),
                            day.ranges.length === 0 ? (openBlock(), createBlock("div", {
                              key: 0,
                              class: "text-muted small"
                            }, toDisplayString(__props.t.work_schedule_empty_ranges ?? 'Nenhuma faixa de horário. Clique em "+ Faixa" para adicionar.'), 1)) : createCommentVNode("", true)
                          ])) : (openBlock(), createBlock("div", {
                            key: 1,
                            class: "px-3 pb-2 pt-1"
                          }, [
                            createVNode("small", { class: "text-muted" }, toDisplayString(__props.t.work_schedule_day_off ?? "Médico não atende neste dia."), 1)
                          ]))
                        ]);
                      }), 128))
                    ]),
                    createVNode("div", { class: "card-footer d-flex align-items-center gap-2" }, [
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-info",
                        disabled: saving.value,
                        onClick: saveSchedule
                      }, [
                        saving.value ? (openBlock(), createBlock("span", {
                          key: 0,
                          class: "spinner-border spinner-border-sm me-1"
                        })) : (openBlock(), createBlock("i", {
                          key: 1,
                          class: "far fa-save me-1"
                        })),
                        createTextVNode(" " + toDisplayString(__props.t.work_schedule_save ?? "Salvar Escala"), 1)
                      ], 8, ["disabled"]),
                      createVNode(Transition, { name: "fade" }, {
                        default: withCtx(() => [
                          saveSuccess.value ? (openBlock(), createBlock("span", {
                            key: 0,
                            class: "text-success small"
                          }, [
                            createVNode("i", { class: "fas fa-check-circle me-1" }),
                            createTextVNode(" " + toDisplayString(__props.t.work_schedule_saved ?? "Salvo com sucesso."), 1)
                          ])) : createCommentVNode("", true)
                        ]),
                        _: 1
                      }),
                      createVNode(Transition, { name: "fade" }, {
                        default: withCtx(() => [
                          saveError.value ? (openBlock(), createBlock("span", {
                            key: 0,
                            class: "text-danger small"
                          }, toDisplayString(saveError.value), 1)) : createCommentVNode("", true)
                        ]),
                        _: 1
                      })
                    ])
                  ])
                ]),
                createVNode("div", { class: "col-lg-4" }, [
                  createVNode("div", { class: "card" }, [
                    createVNode("div", { class: "card-header d-flex align-items-center justify-content-between" }, [
                      createVNode("span", null, [
                        createVNode("i", { class: "fas fa-ban me-2 text-danger" }),
                        createTextVNode(toDisplayString(__props.t.work_schedule_blocks ?? "Bloqueios / Ausências"), 1)
                      ]),
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-sm btn-outline-danger",
                        title: showBlockForm.value ? __props.t.cancel ?? "Cancelar" : __props.t.block_add ?? "Adicionar Bloqueio",
                        onClick: ($event) => showBlockForm.value = !showBlockForm.value
                      }, [
                        createVNode("i", {
                          class: showBlockForm.value ? "fas fa-times" : "fas fa-plus"
                        }, null, 2)
                      ], 8, ["title", "onClick"])
                    ]),
                    showBlockForm.value ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "card-body border-bottom pb-3"
                    }, [
                      createVNode("div", { class: "row g-2" }, [
                        createVNode("div", { class: "col-12" }, [
                          createVNode("label", { class: "form-label small mb-1" }, toDisplayString(__props.t.block_type ?? "Tipo"), 1),
                          withDirectives(createVNode("select", {
                            "onUpdate:modelValue": ($event) => blockForm.value.type = $event,
                            class: "form-select form-select-sm"
                          }, [
                            createVNode("option", { value: "absence" }, toDisplayString(__props.t.block_type_absence ?? "Ausência"), 1),
                            createVNode("option", { value: "holiday" }, toDisplayString(__props.t.block_type_holiday ?? "Feriado"), 1),
                            createVNode("option", { value: "meeting" }, toDisplayString(__props.t.block_type_meeting ?? "Reunião / Compromisso"), 1),
                            createVNode("option", { value: "other" }, toDisplayString(__props.t.block_type_other ?? "Outro"), 1)
                          ], 8, ["onUpdate:modelValue"]), [
                            [vModelSelect, blockForm.value.type]
                          ])
                        ]),
                        createVNode("div", { class: "col-12" }, [
                          createVNode("label", { class: "form-label small mb-1" }, [
                            createTextVNode(toDisplayString(__props.t.starts_at ?? "Início") + " ", 1),
                            createVNode("span", { class: "text-danger" }, "*")
                          ]),
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => blockForm.value.starts_at = $event,
                            type: "datetime-local",
                            class: ["form-control form-control-sm", { "is-invalid": blockErrors.value.starts_at }]
                          }, null, 10, ["onUpdate:modelValue"]), [
                            [vModelText, blockForm.value.starts_at]
                          ]),
                          blockErrors.value.starts_at ? (openBlock(), createBlock("div", {
                            key: 0,
                            class: "invalid-feedback"
                          }, toDisplayString(blockErrors.value.starts_at[0]), 1)) : createCommentVNode("", true)
                        ]),
                        createVNode("div", { class: "col-12" }, [
                          createVNode("label", { class: "form-label small mb-1" }, [
                            createTextVNode(toDisplayString(__props.t.ends_at ?? "Fim") + " ", 1),
                            createVNode("span", { class: "text-danger" }, "*")
                          ]),
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => blockForm.value.ends_at = $event,
                            type: "datetime-local",
                            class: ["form-control form-control-sm", { "is-invalid": blockErrors.value.ends_at }]
                          }, null, 10, ["onUpdate:modelValue"]), [
                            [vModelText, blockForm.value.ends_at]
                          ]),
                          blockErrors.value.ends_at ? (openBlock(), createBlock("div", {
                            key: 0,
                            class: "invalid-feedback"
                          }, toDisplayString(blockErrors.value.ends_at[0]), 1)) : createCommentVNode("", true)
                        ]),
                        createVNode("div", { class: "col-12" }, [
                          createVNode("label", { class: "form-label small mb-1" }, toDisplayString(__props.t.reason ?? "Motivo"), 1),
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => blockForm.value.reason = $event,
                            type: "text",
                            class: "form-control form-control-sm",
                            placeholder: __props.t.block_reason_placeholder ?? "Motivo (opcional)"
                          }, null, 8, ["onUpdate:modelValue", "placeholder"]), [
                            [vModelText, blockForm.value.reason]
                          ])
                        ]),
                        createVNode("div", { class: "col-12 d-flex gap-2" }, [
                          createVNode("button", {
                            type: "button",
                            class: "btn btn-sm btn-danger w-100",
                            disabled: storingBlock.value,
                            onClick: storeBlock
                          }, [
                            storingBlock.value ? (openBlock(), createBlock("span", {
                              key: 0,
                              class: "spinner-border spinner-border-sm me-1"
                            })) : createCommentVNode("", true),
                            createTextVNode(" " + toDisplayString(__props.t.block_add ?? "Adicionar Bloqueio"), 1)
                          ], 8, ["disabled"])
                        ])
                      ])
                    ])) : createCommentVNode("", true),
                    createVNode("div", { class: "card-body p-0" }, [
                      localBlocks.value.length === 0 ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "text-center py-4 text-muted"
                      }, [
                        createVNode("i", { class: "fas fa-check-circle fa-2x mb-2 text-success" }),
                        createVNode("p", { class: "small mb-0" }, toDisplayString(__props.t.work_schedule_no_blocks ?? "Nenhum bloqueio futuro."), 1)
                      ])) : createCommentVNode("", true),
                      (openBlock(true), createBlock(Fragment, null, renderList(localBlocks.value, (block) => {
                        return openBlock(), createBlock("div", {
                          key: block.id,
                          class: "d-flex align-items-start gap-2 px-3 py-2 border-bottom"
                        }, [
                          createVNode("div", { class: "flex-grow-1" }, [
                            createVNode("div", { class: "fw-semibold small" }, toDisplayString(block.type_label), 1),
                            createVNode("div", {
                              class: "text-muted",
                              style: { "font-size": ".8rem" }
                            }, [
                              createVNode("i", { class: "fas fa-calendar-alt me-1" }),
                              createVNode("span", null, toDisplayString(block.starts_at), 1),
                              createVNode("span", { class: "mx-1" }, "→"),
                              createVNode("span", null, toDisplayString(block.ends_at), 1)
                            ]),
                            block.reason ? (openBlock(), createBlock("div", {
                              key: 0,
                              class: "text-secondary",
                              style: { "font-size": ".8rem" }
                            }, toDisplayString(block.reason), 1)) : createCommentVNode("", true)
                          ]),
                          createVNode("button", {
                            type: "button",
                            class: "btn btn-sm btn-outline-danger flex-shrink-0",
                            title: __props.t.remove ?? "Remover bloqueio",
                            onClick: ($event) => destroyBlock(block.id)
                          }, [
                            createVNode("i", { class: "fas fa-trash fa-xs" })
                          ], 8, ["title", "onClick"])
                        ]);
                      }), 128))
                    ])
                  ])
                ])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Doctors/WorkSchedule.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const WorkSchedule = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-8f7b3083"]]);
export {
  WorkSchedule as default
};
