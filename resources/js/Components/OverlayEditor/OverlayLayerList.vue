<script setup>
import { computed, inject } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const editor = inject('overlayEditor');

// Top of the list = front-most layer.
const rows = computed(() => [...editor.elements.value].reverse());

function label(el) {
    if (el.type === 'text') return (el.text || '').split('\n')[0].slice(0, 30) || t('overlay_editor.type_text');
    return t(`overlay_editor.type_${el.type.replace('-', '_')}`);
}

function toggle(el, field) {
    editor.update(el.id, { [field]: !el[field] });
}
</script>

<template>
    <div>
        <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('overlay_editor.layers') }}</h4>
        <p v-if="!rows.length" class="text-xs text-gray-400">{{ t('overlay_editor.no_layers') }}</p>
        <ul class="space-y-1">
            <li v-for="(el, i) in rows" :key="el.id"
                class="flex items-center gap-1 rounded-md border px-2 py-1 text-xs"
                :class="el.id === editor.selectedId.value ? 'border-indigo-400 bg-indigo-50' : 'border-gray-200 bg-white'">
                <button type="button" class="min-w-0 flex-1 truncate text-left" :class="el.hidden ? 'text-gray-400 line-through' : 'text-gray-800'"
                    @click="editor.select(el.id)">
                    {{ label(el) }}
                </button>
                <button type="button" class="px-1 text-gray-500 hover:text-gray-900 disabled:opacity-30" :disabled="i === 0"
                    :title="t('overlay_editor.bring_forward')" @click="editor.move(el.id, 1)">↑</button>
                <button type="button" class="px-1 text-gray-500 hover:text-gray-900 disabled:opacity-30" :disabled="i === rows.length - 1"
                    :title="t('overlay_editor.send_backward')" @click="editor.move(el.id, -1)">↓</button>
                <button type="button" class="px-1 text-gray-500 hover:text-gray-900" @click="toggle(el, 'hidden')">
                    {{ el.hidden ? t('overlay_editor.show') : t('overlay_editor.hide') }}
                </button>
                <button type="button" class="px-1 text-gray-500 hover:text-gray-900" @click="toggle(el, 'locked')">
                    {{ el.locked ? t('overlay_editor.unlock') : t('overlay_editor.lock') }}
                </button>
                <button type="button" class="px-1 text-red-500 hover:text-red-700" :title="t('overlay_editor.delete')"
                    @click="editor.remove(el.id)">&times;</button>
            </li>
        </ul>
    </div>
</template>
