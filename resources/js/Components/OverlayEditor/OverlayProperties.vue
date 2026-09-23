<script setup>
import { computed, inject, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { FONTS } from '@/Composables/overlay/model.js';

// Edits the selected element's fields in place; `change` events (which
// bubble, and fire once an edit is finished) record an undo step.
const emit = defineEmits(['edit-qr']);
const { t } = useI18n();
const editor = inject('overlayEditor');
const textArea = ref(null);
const el = computed(() => editor.selected.value);

defineExpose({
    focusText: () => nextTick(() => textArea.value?.focus()),
});

const input = 'w-full rounded-md border border-gray-300 px-2 py-1 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
const labelCls = 'block text-[11px] font-medium text-gray-600 mb-0.5';
</script>

<template>
    <div v-if="el" class="space-y-3" @change="editor.commit()">
        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('overlay_editor.properties') }}</h4>

        <p v-if="el.locked" class="rounded-md bg-amber-50 px-2 py-1 text-[11px] text-amber-800">
            {{ t('overlay_editor.locked_hint') }}
        </p>

        <div class="grid grid-cols-4 gap-2">
            <div v-for="f in ['x', 'y', 'w', 'h']" :key="f">
                <label :class="labelCls">{{ f.toUpperCase() }}</label>
                <input v-model.number="el[f]" type="number" :class="input" :disabled="el.locked" />
            </div>
        </div>

        <div>
            <label :class="labelCls">{{ t('overlay_editor.opacity') }} ({{ Math.round((el.opacity ?? 1) * 100) }}%)</label>
            <input v-model.number="el.opacity" type="range" min="0" max="1" step="0.05" class="w-full" />
        </div>

        <template v-if="el.type === 'text'">
            <div>
                <label :class="labelCls">{{ t('overlay_editor.text') }}</label>
                <textarea ref="textArea" v-model="el.text" rows="3" :class="input" />
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label :class="labelCls">{{ t('overlay_editor.font') }}</label>
                    <select v-model="el.fontFamily" :class="input">
                        <option v-for="f in FONTS" :key="f.value" :value="f.value">{{ f.label }}</option>
                    </select>
                </div>
                <div>
                    <label :class="labelCls">{{ t('overlay_editor.font_size') }}</label>
                    <input v-model.number="el.fontSize" type="number" min="8" max="600" :class="input" />
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-xs">
                <label class="flex items-center gap-1"><input v-model="el.bold" type="checkbox" class="rounded" /> {{ t('overlay_editor.bold') }}</label>
                <label class="flex items-center gap-1"><input v-model="el.italic" type="checkbox" class="rounded" /> {{ t('overlay_editor.italic') }}</label>
                <label class="flex items-center gap-1">{{ t('overlay_editor.color') }} <input v-model="el.fill" type="color" class="h-6 w-8" /></label>
            </div>
            <div>
                <label :class="labelCls">{{ t('overlay_editor.align') }}</label>
                <select v-model="el.align" :class="input">
                    <option value="start">{{ t('overlay_editor.align_left') }}</option>
                    <option value="middle">{{ t('overlay_editor.align_center') }}</option>
                    <option value="end">{{ t('overlay_editor.align_right') }}</option>
                </select>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-xs">
                <label class="flex items-center gap-1"><input v-model="el.backgroundEnabled" type="checkbox" class="rounded" /> {{ t('overlay_editor.background_box') }}</label>
                <input v-if="el.backgroundEnabled" v-model="el.background" type="color" class="h-6 w-8" />
                <label v-if="el.backgroundEnabled" class="flex items-center gap-1">{{ t('overlay_editor.corner_radius') }}
                    <input v-model.number="el.backgroundRadius" type="number" min="0" class="w-16 rounded-md border border-gray-300 px-1 py-0.5 text-xs" />
                </label>
            </div>
        </template>

        <template v-else-if="el.type === 'rect'">
            <div class="flex flex-wrap items-center gap-3 text-xs">
                <label class="flex items-center gap-1"><input v-model="el.fillEnabled" type="checkbox" class="rounded" /> {{ t('overlay_editor.fill') }}</label>
                <input v-if="el.fillEnabled" v-model="el.fill" type="color" class="h-6 w-8" />
            </div>
            <div class="grid grid-cols-3 gap-2 text-xs">
                <div>
                    <label :class="labelCls">{{ t('overlay_editor.border') }}</label>
                    <input v-model="el.stroke" type="color" class="h-7 w-full" />
                </div>
                <div>
                    <label :class="labelCls">{{ t('overlay_editor.border_width') }}</label>
                    <input v-model.number="el.strokeWidth" type="number" min="0" :class="input" />
                </div>
                <div>
                    <label :class="labelCls">{{ t('overlay_editor.corner_radius') }}</label>
                    <input v-model.number="el.radius" type="number" min="0" :class="input" />
                </div>
            </div>
        </template>

        <template v-else-if="el.type === 'qr'">
            <p class="break-all text-xs text-gray-600">{{ el.data }}</p>
            <button type="button" class="rounded-md border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50"
                @click="emit('edit-qr', el)">
                {{ t('overlay_editor.edit_qr') }}
            </button>
        </template>

        <p v-else-if="el.type === 'svg-import'" class="text-[11px] text-gray-500">{{ t('overlay_editor.imported_hint') }}</p>
    </div>
    <p v-else class="text-xs text-gray-400">{{ t('overlay_editor.select_hint') }}</p>
</template>
