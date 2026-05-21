<script setup>
import { ref, nextTick, onBeforeUnmount } from 'vue';

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

function toggle(e) {
    e.stopPropagation();
    open.value ? close() : openMenu();
}

async function openMenu() {
    open.value = true;
    await nextTick();
    position();
    document.addEventListener('click', onOutsideClick, true);
    document.addEventListener('keydown', onEsc);
    window.addEventListener('resize', close);
    window.addEventListener('scroll', close, true);
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

function onOutsideClick(e) {
    if (triggerRef.value?.contains(e.target)) return;
    if (menuRef.value?.contains(e.target))    return;
    close();
}

function onEsc(e) {
    if (e.key === 'Escape') close();
}

onBeforeUnmount(close);
</script>

<template>
    <button ref="triggerRef" type="button" :class="btnClass" :title="title" @click="toggle">
        <i :class="icon"></i>
    </button>

    <Teleport to="body">
        <transition name="ee-dropdown">
            <ul
                v-if="open"
                ref="menuRef"
                class="dropdown-menu show p-2"
                :style="menuStyle"
                @click="close"
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
