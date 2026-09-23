<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { QR_DEFAULTS, normalizeUrl, renderQr } from '@/Composables/overlay/qr.js';

// Creates or edits a QR code. The target is the slide's Link, its canonical
// page, or a typed-in URL; the URL is captured when the code is generated
// (changing the slide's Link later doesn't rewrite existing codes).
const props = defineProps({
    slide: { type: Object, required: true },
    initial: { type: Object, default: null }, // existing QR element when editing
});
const emit = defineEmits(['apply', 'close']);
const { t } = useI18n();

const init = props.initial ?? {};
const target = ref(init.target ?? (props.slide.link ? 'link' : 'canonical'));
const custom = ref(init.target === 'custom' ? init.data : '');
const foreground = ref(init.foreground ?? QR_DEFAULTS.foreground);
const background = ref(init.background ?? QR_DEFAULTS.background);
const backgroundEnabled = ref(init.backgroundEnabled ?? QR_DEFAULTS.backgroundEnabled);
const radius = ref(init.radius ?? QR_DEFAULTS.radius);
const ecl = ref(init.ecl ?? QR_DEFAULTS.ecl);

const resolved = computed(() => {
    if (target.value === 'link') return props.slide.link ? normalizeUrl(props.slide.link) : { url: null, error: 'empty' };
    if (target.value === 'canonical') return { url: props.slide.canonical_url, error: null };
    return normalizeUrl(custom.value);
});

const preview = ref(null);
const failed = ref(false);
let renderSeq = 0;

watch([() => resolved.value.url, foreground, background, backgroundEnabled, radius, ecl], async () => {
    const url = resolved.value.url;
    if (!url) {
        preview.value = null;
        return;
    }
    const seq = ++renderSeq;
    try {
        const out = await renderQr(options(url));
        if (seq === renderSeq) {
            preview.value = out;
            failed.value = false;
        }
    } catch {
        if (seq === renderSeq) {
            preview.value = null;
            failed.value = true;
        }
    }
}, { immediate: true });

function options(url) {
    return {
        data: url,
        foreground: foreground.value,
        background: background.value,
        backgroundEnabled: backgroundEnabled.value,
        radius: Number(radius.value),
        ecl: ecl.value,
    };
}

function apply() {
    if (!preview.value) return;
    emit('apply', { ...options(resolved.value.url), target: target.value, ...preview.value });
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
            </fieldset>

            <div class="flex gap-4">
                <div class="grid flex-1 grid-cols-2 gap-3 text-xs">
                    <label class="flex items-center gap-2">{{ t('overlay_editor.qr_foreground') }}
                        <input v-model="foreground" type="color" class="h-6 w-8" />
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="backgroundEnabled" type="checkbox" class="rounded" /> {{ t('overlay_editor.qr_background') }}
                        <input v-if="backgroundEnabled" v-model="background" type="color" class="h-6 w-8" />
                    </label>
                    <label class="col-span-2">{{ t('overlay_editor.qr_roundness') }}
                        <input v-model.number="radius" type="range" min="0" max="1" step="0.1" class="w-full" />
                    </label>
                    <label class="col-span-2">{{ t('overlay_editor.qr_ecl') }}
                        <select v-model="ecl" :class="input">
                            <option value="L">{{ t('overlay_editor.qr_ecl_l') }}</option>
                            <option value="M">{{ t('overlay_editor.qr_ecl_m') }}</option>
                            <option value="Q">{{ t('overlay_editor.qr_ecl_q') }}</option>
                            <option value="H">{{ t('overlay_editor.qr_ecl_h') }}</option>
                        </select>
                    </label>
                    <p v-if="!backgroundEnabled" class="col-span-2 text-amber-700">{{ t('overlay_editor.qr_transparent_hint') }}</p>
                </div>
                <div class="flex h-36 w-36 shrink-0 items-center justify-center rounded-lg bg-[repeating-conic-gradient(#e5e7eb_0%_25%,#fff_0%_50%)] bg-[length:16px_16px]">
                    <svg v-if="preview" :viewBox="preview.viewBox" class="h-32 w-32" v-html="preview.markup" />
                    <span v-else class="px-2 text-center text-xs text-gray-400">
                        {{ failed ? t('overlay_editor.qr_failed') : t('overlay_editor.qr_preview') }}
                    </span>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    @click="emit('close')">{{ t('overlay_editor.cancel') }}</button>
                <button type="button" :disabled="!preview"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                    @click="apply">
                    {{ initial ? t('overlay_editor.qr_update') : t('overlay_editor.qr_insert') }}
                </button>
            </div>
        </div>
    </div>
</template>
