<script setup>
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { QR_SYMBOLS, renderQr, symbolsForUrl } from '@/Composables/overlay/qr.js';
import { prepareSvgImport } from '@/Composables/overlay/importSvg.js';

// The design controls for a QR code — center symbol, colors, background,
// roundness — shared by the overlay editor's QR dialog and the standalone
// QR Code Creator page. Renders the code whenever the URL or an option
// changes and hands the result back through v-model:rendered (null while
// there's no valid URL).
const props = defineProps({
    url: { type: String, default: null },
    // Skip auto-selecting a brand symbol for the URL on first render, so
    // reopening a saved code keeps the symbol (or none) it was saved with.
    keepInitialSymbol: { type: Boolean, default: false },
});
const options = defineModel('options', { type: Object, required: true });
const rendered = defineModel('rendered', { type: Object, default: null });
const failed = defineModel('failed', { type: Boolean, default: false });
const { t, te } = useI18n();

function field(key) {
    return computed({
        get: () => options.value[key],
        set: value => { options.value = { ...options.value, [key]: value }; },
    });
}
const foreground = field('foreground');
const background = field('background');
const backgroundEnabled = field('backgroundEnabled');
const radius = field('radius');
const symbol = field('symbol');
const symbolColor = field('symbolColor');

// Picker thumbnails, built once per symbol.
const thumbnails = Object.fromEntries(QR_SYMBOLS.map(s => [s.id, {
    ...s,
    label: te(`overlay_editor.qr_symbol_${s.id}`) ? t(`overlay_editor.qr_symbol_${s.id}`) : s.id.replace(/[-_]/g, ' '),
    ...prepareSvgImport(s.svg, `pick-${s.id}`),
}]));

// Brand symbols (Facebook, YouTube, …) only appear when the URL is on that
// network; a chosen one is cleared if the URL moves elsewhere.
const symbolChoices = computed(() => symbolsForUrl(props.url).map(s => thumbnails[s.id]));
watch(symbolChoices, choices => {
    if (symbol.value && !choices.some(c => c.id === symbol.value)) symbol.value = null;
}, { immediate: true });

// When the URL lands on a (different) social network, select its symbol,
// replacing whatever was chosen.
const matchedBrand = computed(() => symbolChoices.value.find(c => c.hosts)?.id ?? null);
watch(matchedBrand, brand => {
    if (brand) symbol.value = brand;
}, { immediate: !props.keepInitialSymbol });

let renderSeq = 0;
watch([() => props.url, options], async () => {
    if (!props.url) {
        rendered.value = null;
        return;
    }
    const seq = ++renderSeq;
    try {
        const out = await renderQr({ data: props.url, ...options.value, radius: Number(options.value.radius) });
        if (seq === renderSeq) {
            rendered.value = out;
            failed.value = false;
        }
    } catch {
        if (seq === renderSeq) {
            rendered.value = null;
            failed.value = true;
        }
    }
}, { immediate: true, deep: true });
</script>

<template>
    <div class="space-y-4">
        <fieldset v-if="symbolChoices.length" class="space-y-2">
            <legend class="mb-1 text-xs font-medium text-gray-700">{{ t('overlay_editor.qr_symbol') }}</legend>
            <div class="flex flex-wrap gap-2">
                <button type="button" @click="symbol = null"
                    class="flex h-14 w-14 items-center justify-center rounded-lg border-2 text-xs text-gray-500"
                    :class="!symbol ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-gray-300'">
                    {{ t('overlay_editor.qr_symbol_none') }}
                </button>
                <button v-for="choice in symbolChoices" :key="choice.id" type="button" :title="choice.label"
                    @click="symbol = choice.id"
                    class="flex h-14 w-14 items-center justify-center rounded-lg border-2 bg-white p-2"
                    :class="symbol === choice.id ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-gray-300'">
                    <svg :viewBox="choice.viewBox" class="h-full w-full" v-html="choice.markup" />
                </button>
            </div>
            <div v-if="symbol" class="flex flex-wrap items-center gap-3 text-xs">
                <label class="flex items-center gap-1">
                    <input type="checkbox" class="rounded" :checked="!symbolColor"
                        @change="symbolColor = $event.target.checked ? null : foreground" />
                    {{ t('overlay_editor.qr_symbol_match') }}
                </label>
                <input v-if="symbolColor" v-model="symbolColor" type="color" class="h-6 w-8" />
            </div>
        </fieldset>

        <div class="grid grid-cols-2 gap-3 text-xs">
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
            <p v-if="!backgroundEnabled" class="col-span-2 text-amber-700">{{ t('overlay_editor.qr_transparent_hint') }}</p>
        </div>
    </div>
</template>
