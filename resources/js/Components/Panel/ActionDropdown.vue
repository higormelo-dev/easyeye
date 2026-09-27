<script setup>
import { ref, nextTick, onBeforeUnmount, useId } from 'vue';

const props = defineProps({
    title:    { type: String, default: 'Mais ações' },
    align:    { type: String, default: 'right' }, // 'right' | 'left'
    minWidth: { type: Number, default: 180 },
    icon:     { type: String, default: 'ti ti-dots-vertical' },
    btnClass: { type: String, default: 'btn btn-sm btn-outline-secondary' },
});

const open       = ref(false);
const triggerRef = ref(null);
const menuRef    = ref(null);
const menuStyle  = ref({});
const menuId     = `ee-dropdown-${useId()}`;

// Itens navegáveis por teclado (divisores e itens desabilitados ficam de fora).
const FOCUSABLE = 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])';
// Campos de formulário dentro do menu (ex.: exportação com datas): setas, Home,
// End e Tab pertencem ao campo — o menu não intercepta.
const FIELD     = 'input, select, textarea, [contenteditable="true"]';
const FIRST     = `${FOCUSABLE}, input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled])`;

function toggle(e) {
    e.stopPropagation();
    // Enter/Espaço no botão geram click com detail 0: aberto pelo teclado.
    open.value ? close() : openMenu(e.detail === 0);
}

/**
 * O menu é teleportado para o fim do <body>: sem mover o foco, quem usa
 * teclado abre o menu mas o Tab segue a ordem do documento e nunca chega aos
 * itens. Aberto pelo teclado, o foco vai para o 1º item (preventScroll: um
 * scroll fecharia o menu); pelo mouse, nada muda.
 */
async function openMenu(viaKeyboard = false) {
    open.value = true;
    await nextTick();
    position();
    document.addEventListener('click', onOutsideClick, true);
    document.addEventListener('keydown', onEsc);
    window.addEventListener('resize', close);
    window.addEventListener('scroll', close, true);

    applyMenuRoles();

    if (viaKeyboard) menuRef.value?.querySelector(FIRST)?.focus({ preventScroll: true });
}

/**
 * Semântica de menu (o botão anuncia aria-haspopup="menu"): lista role=menu,
 * <li> role=none e itens role=menuitem. Menu com campos de formulário (ex.:
 * exportação com datas) não recebe os papéis — role=menu com inputs é inválido.
 */
function applyMenuRoles() {
    const menu = menuRef.value;
    if (!menu || menu.querySelector(FIELD)) return;

    menu.setAttribute('role', 'menu');
    menu.querySelectorAll(':scope > li').forEach((li) => li.setAttribute('role', 'none'));
    menuItems().forEach((item) => item.setAttribute('role', 'menuitem'));
    menu.querySelectorAll('hr.dropdown-divider').forEach((hr) => hr.setAttribute('role', 'separator'));
}

function menuItems() {
    return Array.from(menuRef.value?.querySelectorAll(FOCUSABLE) ?? []);
}

function focusItem(index) {
    const items = menuItems();
    if (!items.length) return;

    items[((index % items.length) + items.length) % items.length].focus({ preventScroll: true });
}

function closeAndReturnFocus() {
    close();
    triggerRef.value?.focus({ preventScroll: true });
}

/** Setas/Home/End navegam; Tab fecha e devolve o foco ao botão (Esc: onEsc). */
function onMenuKeydown(e) {
    if (e.target instanceof Element && e.target.closest(FIELD)) return;

    const current = menuItems().indexOf(document.activeElement);

    switch (e.key) {
        case 'ArrowDown':
            e.preventDefault();
            focusItem(current + 1);
            break;
        case 'ArrowUp':
            e.preventDefault();
            focusItem(current < 0 ? -1 : current - 1);
            break;
        case 'Home':
            e.preventDefault();
            focusItem(0);
            break;
        case 'End':
            e.preventDefault();
            focusItem(-1);
            break;
        case 'Tab':
            // Menu com formulário: Tab percorre os campos normalmente.
            if (menuRef.value?.querySelector(FIELD)) return;

            e.preventDefault();
            closeAndReturnFocus();
            break;
        default:
            break;
    }
}

function close() {
    open.value = false;
    document.removeEventListener('click', onOutsideClick, true);
    document.removeEventListener('keydown', onEsc);
    window.removeEventListener('resize', close);
    window.removeEventListener('scroll', close, true);
}

function position() {
    if (!triggerRef.value || !menuRef.value) return;

    const triggerRect = triggerRef.value.getBoundingClientRect();
    const menuRect    = menuRef.value.getBoundingClientRect();
    const vh          = window.innerHeight;
    const vw          = window.innerWidth;

    // Vertical: open below if space, otherwise above
    const spaceBelow = vh - triggerRect.bottom;
    const top = spaceBelow > menuRect.height + 8
        ? triggerRect.bottom + 4
        : Math.max(8, triggerRect.top - menuRect.height - 4);

    // Horizontal: align right or left edge of trigger
    let left = props.align === 'right'
        ? triggerRect.right - menuRect.width
        : triggerRect.left;
    left = Math.max(8, Math.min(left, vw - menuRect.width - 8));

    menuStyle.value = {
        position: 'fixed',
        top:      `${top}px`,
        left:     `${left}px`,
        minWidth: `${props.minWidth}px`,
        zIndex:   1080,
    };
}

/**
 * Item escolhido: fecha. Se o foco estava no menu, volta ao botão — o modal que
 * o item abrir guarda o botão como retorno de foco (o item some do DOM).
 */
function onMenuClick() {
    const hadFocusInside = menuRef.value?.contains(document.activeElement);

    close();
    if (hadFocusInside) triggerRef.value?.focus({ preventScroll: true });
}

function onOutsideClick(e) {
    if (triggerRef.value?.contains(e.target)) return;
    if (menuRef.value?.contains(e.target))    return;
    close();
}

function onEsc(e) {
    if (e.key !== 'Escape') return;

    // Fechado pelo Esc, o foco volta ao botão (senão se perde no fim do <body>).
    const hadFocusInside = menuRef.value?.contains(document.activeElement);

    close();
    if (hadFocusInside) triggerRef.value?.focus({ preventScroll: true });
}

onBeforeUnmount(close);
</script>

<template>
    <button
        ref="triggerRef"
        type="button"
        :class="btnClass"
        :title="title"
        :aria-label="title"
        aria-haspopup="menu"
        :aria-expanded="open ? 'true' : 'false'"
        :aria-controls="open ? menuId : undefined"
        @click="toggle"
        @keydown.down.prevent="open ? focusItem(0) : openMenu(true)"
    >
        <slot name="trigger"><i :class="icon" aria-hidden="true"></i></slot>
    </button>

    <Teleport to="body">
        <transition name="ee-dropdown">
            <ul
                v-if="open"
                :id="menuId"
                ref="menuRef"
                class="dropdown-menu show p-2"
                :style="menuStyle"
                @click="onMenuClick"
                @keydown="onMenuKeydown"
            >
                <slot />
            </ul>
        </transition>
    </Teleport>
</template>

<style scoped>
.ee-dropdown-enter-active,
.ee-dropdown-leave-active { transition: opacity .12s ease, transform .12s ease; }
.ee-dropdown-enter-from,
.ee-dropdown-leave-to     { opacity: 0; transform: scale(.97); }
</style>
