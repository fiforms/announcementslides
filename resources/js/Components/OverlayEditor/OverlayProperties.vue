<script setup>
import { computed, inject, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { BACKGROUND_OPACITY_TYPES, FONTS } from '@/Composables/overlay/model.js';
import { fitBox, resolveSizing } from '@/Composables/overlay/widgetSizing.js';

// Edits the selected element's fields in place; `change` events (which
// bubble, and fire once an edit is finished) record an undo step.
const emit = defineEmits(['edit-qr']);
const { t } = useI18n();
const editor = inject('overlayEditor');
const textArea = ref(null);
const el = computed(() => editor.selected.value);
const widgetCatalog = inject('widgetCatalog', null);
const widget = computed(() => el.value?.type === 'widget' ? widgetCatalog?.value?.[el.value.widget] ?? null : null);

// Changing a parameter (a widget's mode) can change the sizes it accepts.
// Snap the box into the new rules; the `change` event that follows records
// it in the same undo step as the parameter.
watch(() => resolveSizing(widget.value, el.value?.params), sizing => {
    if (!sizing || !el.value || el.value.type !== 'widget') return;
    const { x, y, w, h } = el.value;
    const fitted = fitBox({ x, y, w, h }, sizing);
    if (fitted.w !== w || fitted.h !== h) editor.update(el.value.id, fitted, false);
}, { flush: 'sync' });

// Text, QR codes and rectangles fade only their background (and only have
// the slider while they have one); images and imported artwork fade whole.
const opacityField = computed(() => {
    const e = el.value;
    if (!e) return null;
    if (!BACKGROUND_OPACITY_TYPES.has(e.type)) return { key: 'opacity', label: 'opacity' };
    const hasBackground = e.type === 'rect' ? e.fillEnabled !== false : e.backgroundEnabled;
    if (!hasBackground) return null;
    return { key: 'backgroundOpacity', label: e.type === 'rect' ? 'fill_opacity' : 'background_opacity' };
});

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

        <div v-if="opacityField">
            <label :class="labelCls">{{ t(`overlay_editor.${opacityField.label}`) }} ({{ Math.round((el[opacityField.key] ?? 1) * 100) }}%)</label>
            <input v-model.number="el[opacityField.key]" type="range" min="0" max="1" step="0.05" class="w-full" />
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

        <template v-else-if="el.type === 'widget'">
            <p v-if="!widget" class="rounded-md bg-red-50 px-2 py-1 text-[11px] text-red-700">
                {{ t('overlay_editor.widget_missing_hint', { slug: el.widget }) }}
            </p>
            <template v-else>
                <p v-if="widget.description" class="text-[11px] text-gray-500">{{ widget.description }}</p>
                <p v-if="widget.enabled === false" class="rounded-md bg-amber-50 px-2 py-1 text-[11px] text-amber-800">
                    {{ t('overlay_editor.widget_disabled_hint') }}
                </p>
                <div v-for="(p, key) in widget.parameters" :key="key">
                    <label v-if="p.type !== 'boolean'" :class="labelCls">{{ p.label ?? key }}</label>
                    <select v-if="p.type === 'enum'" v-model="el.params[key]" :class="input">
                        <option v-for="o in p.options" :key="o" :value="o">{{ o }}</option>
                    </select>
                    <textarea v-else-if="p.type === 'text'" v-model="el.params[key]" rows="3" :maxlength="p.maxLength" :class="input" />
                    <input v-else-if="p.type === 'color'" v-model="el.params[key]" type="color" class="h-7 w-full" />
                    <input v-else-if="p.type === 'number'" v-model.number="el.params[key]" type="number"
                        :min="p.min" :max="p.max" :step="p.step ?? 'any'" :class="input" />
                    <label v-else-if="p.type === 'boolean'" class="flex items-center gap-1 text-xs">
                        <input v-model="el.params[key]" type="checkbox" class="rounded" /> {{ p.label ?? key }}
                    </label>
                    <input v-else v-model.trim="el.params[key]" :type="p.type === 'url' ? 'url' : 'text'"
                        :maxlength="p.maxLength" :placeholder="p.type === 'url' ? 'https://…' : null" :class="input" />
                    <p v-if="p.help" class="mt-0.5 text-[11px] text-gray-500">{{ p.help }}</p>
                    <p v-if="p.type === 'url' && p.allow?.length" class="mt-0.5 break-all text-[11px] text-gray-400">
                        {{ t('overlay_editor.widget_allowed_urls', { list: p.allow.join(', ') }) }}
                    </p>
                </div>
            </template>
        </template>
    </div>
    <p v-else class="text-xs text-gray-400">{{ t('overlay_editor.select_hint') }}</p>
</template>
