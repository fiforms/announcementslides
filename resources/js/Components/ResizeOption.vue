<script setup>
import { ineligibleMessage } from '@/Composables/useUpscaler.js';

// The per-file resize line in an upload form: the checkbox (AI upscale 2× or
// downscale to 4K), then progress and the outcome. `info` and `size` come from
// useUploadResize (infos[i], sizeFor(i)); `settings` is the shared upscaler prop.
defineProps({
    info:       { type: Object, default: null },
    validation: { type: Object, default: null },
    size:       { type: Object, default: null },
    settings:   { type: Object, default: null },
    busy:       { type: Boolean, default: false },
});

defineEmits(['update:enabled', 'cancel']);
</script>

<template>
    <div v-if="info && info.kind" class="mt-2 text-xs">
        <label v-if="info.eligible && !info.status" class="flex items-center gap-2 text-gray-700">
            <input type="checkbox" :checked="info.enabled" :disabled="busy"
                class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                @change="$emit('update:enabled', $event.target.checked)" />
            <span>
                <template v-if="info.kind === 'compress'">
                    Convert to JPEG to reduce the file size
                    <span class="text-gray-400">({{ (info.fileSize / 1048576).toFixed(1) }} MB; the original is kept)</span>
                </template>
                <template v-else>
                    {{ info.kind === 'upscale' ? 'Upscale 2× with AI' : 'Downscale to fit 4K' }}
                    <span v-if="size && validation" class="text-gray-400">
                        ({{ validation.width }}×{{ validation.height }} → {{ size.width }}×{{ size.height }};
                        the original is kept)
                    </span>
                </template>
            </span>
        </label>
        <p v-else-if="info.reason === 'too-small'" class="text-gray-400">
            {{ ineligibleMessage(info.reason, settings) }}
        </p>

        <div v-if="info.status === 'working'">
            <template v-if="info.kind === 'upscale'">
                <div class="mb-1 flex justify-between text-gray-500">
                    <span>Upscaling… {{ info.progress }}%</span>
                    <button type="button" class="text-red-600 hover:underline" @click="$emit('cancel')">Cancel</button>
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200">
                    <div class="h-full rounded-full bg-purple-500 transition-all duration-200"
                        :style="{ width: info.progress + '%' }" />
                </div>
            </template>
            <p v-else class="text-gray-500">{{ info.kind === 'compress' ? 'Converting…' : 'Downscaling…' }}</p>
        </div>
        <p v-else-if="info.note" :class="info.status === 'failed' ? 'text-amber-700' : 'text-green-700'">
            {{ info.note }}
        </p>
    </div>
</template>
