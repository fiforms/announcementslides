<script setup>
import { computed } from 'vue';

const props = defineProps({
    slide: { type: Object, required: true },
    showDownload: { type: Boolean, default: true },
    selectable: { type: Boolean, default: false },
    selected: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle-select', 'open']);

const expiresLabel = computed(() => {
    if (!props.slide.expires_at) return null;
    const d = new Date(props.slide.expires_at);
    const isExpired = d <= new Date();
    const dateStr = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    return isExpired ? `Expired ${dateStr}` : `Expires ${dateStr}`;
});

const publishLabel = computed(() => {
    if (!props.slide.publish_at) return null;
    const d = new Date(props.slide.publish_at);
    return `From ${d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
});

const previewSrc = computed(() => props.slide.thumbnail_url || props.slide.file_url);
</script>

<template>
    <div class="group relative rounded-xl overflow-hidden bg-white shadow-sm hover:shadow-md transition-shadow border border-slate-200 cursor-pointer"
        :class="{ 'ring-2 ring-indigo-500': selected }"
        @click="emit('open', slide)">

        <!-- Checkbox (selectable mode only) -->
        <div v-if="selectable" class="absolute top-2 left-2 z-20" @click.stop="emit('toggle-select', slide.id)">
            <div class="h-5 w-5 rounded border-2 flex items-center justify-center transition-colors"
                :class="selected ? 'bg-indigo-600 border-indigo-600' : 'bg-white/90 border-gray-300'">
                <svg v-if="selected" class="h-3 w-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd"
                        d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                        clip-rule="evenodd" />
                </svg>
            </div>
        </div>

        <!-- Thumbnail -->
        <div class="aspect-video bg-slate-100 overflow-hidden">
            <img v-if="previewSrc" :src="previewSrc" :alt="slide.title"
                class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105" />
            <div v-else class="w-full h-full flex items-center justify-center text-slate-300">
                <svg class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                        d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01" />
                </svg>
            </div>
        </div>

        <!-- Info -->
        <div class="p-3">
            <p class="text-sm font-semibold text-gray-900 truncate">{{ slide.title }}</p>
            <div class="mt-1 flex flex-wrap gap-1">
                <span v-if="publishLabel" class="inline-flex text-xs text-indigo-600 bg-indigo-50 px-1.5 py-0.5 rounded">
                    {{ publishLabel }}
                </span>
                <span v-if="expiresLabel" class="inline-flex text-xs text-amber-700 bg-amber-50 px-1.5 py-0.5 rounded">
                    {{ expiresLabel }}
                </span>
            </div>
        </div>

        <!-- Download button -->
        <div v-if="showDownload" class="px-3 pb-3" @click.stop>
            <a :href="route('slides.download', slide.id)"
                class="flex items-center justify-center gap-1.5 w-full rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 transition-colors">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                Download
            </a>
        </div>
    </div>
</template>
