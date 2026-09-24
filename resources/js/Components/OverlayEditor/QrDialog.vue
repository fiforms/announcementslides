<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import QrDesigner from './QrDesigner.vue';
import QrPreview from './QrPreview.vue';
import { QR_DEFAULTS, QR_SYMBOLS, normalizeUrl } from '@/Composables/overlay/qr.js';

// Creates or edits a QR code. The target is the slide's Link, its canonical
// page, or a typed-in URL; the URL is captured when the code is generated
// (changing the slide's Link later doesn't rewrite existing codes). The
// design controls are shared with the QR Code Creator page (QrDesigner).
const props = defineProps({
    slide: { type: Object, required: true },
    initial: { type: Object, default: null }, // existing QR element when editing
});
const emit = defineEmits(['apply', 'close']);
const { t } = useI18n();

const init = props.initial ?? {};
const target = ref(init.target ?? (props.slide.link ? 'link' : 'canonical'));
const custom = ref(init.target === 'custom' ? init.data : '');
const options = ref({
    foreground: init.foreground ?? QR_DEFAULTS.foreground,
    background: init.background ?? QR_DEFAULTS.background,
    backgroundEnabled: init.backgroundEnabled ?? QR_DEFAULTS.backgroundEnabled,
    radius: init.radius ?? QR_DEFAULTS.radius,
    symbol: QR_SYMBOLS.some(s => s.id === init.symbol) ? init.symbol : QR_DEFAULTS.symbol,
    symbolColor: init.symbolColor ?? QR_DEFAULTS.symbolColor,
});
const rendered = ref(null);
const failed = ref(false);

const resolved = computed(() => {
    if (target.value === 'link') return props.slide.link ? normalizeUrl(props.slide.link) : { url: null, error: 'empty' };
    if (target.value === 'canonical') return { url: props.slide.canonical_url, error: null };
    return normalizeUrl(custom.value);
});

function apply() {
    if (!rendered.value) return;
    emit('apply', {
        ...options.value,
        radius: Number(options.value.radius),
        data: resolved.value.url,
        target: target.value,
        ...rendered.value,
    });
}

const input = 'w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
</script>

<template>
    <div class="absolute inset-0 z-10 flex items-start justify-center overflow-y-auto bg-black/30 p-4" @click.self="emit('close')">
        <div class="w-full max-w-lg space-y-4 rounded-xl bg-white p-5 shadow-xl">
            <div class="flex items-center justify-between">
                <h4 class="text-sm font-semibold text-gray-900">
                    {{ initial ? t('overlay_editor.qr_edit_title') : t('overlay_editor.qr_add_title') }}
                </h4>
                <button type="button" class="text-gray-400 hover:text-gray-600" @click="emit('close')">&times;</button>
            </div>

            <fieldset class="space-y-2 text-sm">
                <legend class="mb-1 text-xs font-medium text-gray-700">{{ t('overlay_editor.qr_target') }}</legend>
                <label class="flex items-start gap-2" :class="!slide.link && 'opacity-50'">
                    <input v-model="target" type="radio" value="link" :disabled="!slide.link" class="mt-0.5" />
                    <span>
                        {{ t('overlay_editor.qr_target_link') }}
                        <span class="block break-all text-xs text-gray-500">{{ slide.link || t('overlay_editor.qr_no_link') }}</span>
                    </span>
                </label>
                <label class="flex items-start gap-2">
                    <input v-model="target" type="radio" value="canonical" class="mt-0.5" />
                    <span>
                        {{ t('overlay_editor.qr_target_canonical') }}
                        <span class="block break-all text-xs text-gray-500">{{ slide.canonical_url }}</span>
                        <span v-if="slide.entity_id" class="block text-xs text-amber-700">{{ t('overlay_editor.qr_canonical_local_hint') }}</span>
                    </span>
                </label>
                <label class="flex items-start gap-2">
                    <input v-model="target" type="radio" value="custom" class="mt-0.5" />
                    <span class="flex-1">
                        {{ t('overlay_editor.qr_target_custom') }}
                        <input v-if="target === 'custom'" v-model="custom" type="url" placeholder="https://…" :class="[input, 'mt-1']" />
                    </span>
                </label>
                <p v-if="target === 'custom' && custom && resolved.error" class="text-xs text-red-600">
                    {{ t(`overlay_editor.qr_error_${resolved.error}`) }}
                </p>
                <p v-if="resolved.url && resolved.url.length > 300" class="text-xs text-amber-700">{{ t('overlay_editor.qr_long_url') }}</p>
                <p v-if="resolved.url && resolved.removed?.length" class="text-xs text-gray-500">
                    {{ t('overlay_editor.qr_trackers_removed', { params: resolved.removed.join(', ') }) }}
                    <span class="block break-all">{{ resolved.url }}</span>
                </p>
            </fieldset>

            <div class="flex gap-4">
                <QrDesigner v-model:options="options" v-model:rendered="rendered" v-model:failed="failed"
                    :url="resolved.url" :keep-initial-symbol="!!initial" class="min-w-0 flex-1" />
                <QrPreview :rendered="rendered" :options="options" :failed="failed" class="h-36 w-36 shrink-0" />
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    @click="emit('close')">{{ t('overlay_editor.cancel') }}</button>
                <button type="button" :disabled="!rendered"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                    @click="apply">
                    {{ initial ? t('overlay_editor.qr_update') : t('overlay_editor.qr_insert') }}
                </button>
            </div>
        </div>
    </div>
</template>
