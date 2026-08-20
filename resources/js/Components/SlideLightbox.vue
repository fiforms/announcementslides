<script setup>
import { ref } from 'vue';

defineProps({
    slide: { type: Object, default: null },
    canUnarchive: { type: Boolean, default: false },
});

defineEmits(['close', 'unarchive']);

const MEDIA_TYPE_LABELS = {
    slide: 'Slide',
    'slide-overlay': 'Overlay',
    'color-flyer': 'Color Flyer',
    'easy-print-flyer': 'Easy-Print Flyer',
    'social-media-image': 'Social Media Image',
};

function mediaLabel(media) {
    return MEDIA_TYPE_LABELS[media.media_type] ?? media.media_type;
}

function isPdf(media) {
    return media.mime_type === 'application/pdf';
}

const copiedField = ref(null);

async function copy(text, field) {
    try {
        await navigator.clipboard.writeText(text);
        copiedField.value = field;
        setTimeout(() => { if (copiedField.value === field) copiedField.value = null; }, 1500);
    } catch {
        // Clipboard access denied/unavailable — nothing sensible to do but leave the text visible to copy manually.
    }
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="slide"
                class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 overflow-y-auto bg-black/85 p-4"
                @click.self="$emit('close')">

                <!-- Close button -->
                <button @click="$emit('close')"
                    class="absolute top-4 right-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20 transition-colors"
                    aria-label="Close">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                <!-- Preview (base + overlay, composited exactly like the slideshow) -->
                <div class="relative max-h-[65vh] w-full max-w-4xl flex-shrink" @click.self="$emit('close')">
                    <video v-if="slide.mime_type?.startsWith('video/')"
                        :src="slide.file_url"
                        controls autoplay loop
                        class="max-h-[65vh] w-full rounded-lg object-contain shadow-2xl" />
                    <img v-else :src="slide.file_url || slide.thumbnail_url"
                        :alt="slide.title"
                        class="max-h-[65vh] w-full rounded-lg object-contain shadow-2xl" />
                    <img v-if="slide.overlay_url" :src="slide.overlay_url" :alt="`${slide.title} overlay`"
                        class="pointer-events-none absolute inset-0 h-full w-full object-contain" />
                </div>

                <!-- Description / link / attached files -->
                <div class="w-full max-w-4xl rounded-xl border border-white/10 bg-black/60 p-4 text-white shadow-2xl backdrop-blur-md space-y-3"
                    @click.stop>
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-lg font-medium">{{ slide.title }}</p>
                        <button v-if="canUnarchive" @click="$emit('unarchive', slide)"
                            class="flex flex-shrink-0 items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 8l6-6 6 6M10 2v16m-8 4h16" />
                            </svg>
                            Un-archive
                        </button>
                    </div>

                    <div v-if="slide.text_description" class="flex items-start gap-2">
                        <p class="flex-1 text-sm text-white/80 whitespace-pre-line">{{ slide.text_description }}</p>
                        <button @click="copy(slide.text_description, 'description')" title="Copy description"
                            class="flex-shrink-0 rounded p-1 text-white/60 hover:bg-white/10 hover:text-white">
                            <svg v-if="copiedField === 'description'" class="h-4 w-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                        </button>
                    </div>

                    <div v-if="slide.link" class="flex items-center gap-2">
                        <a :href="slide.link" target="_blank" rel="noopener"
                            class="flex-1 truncate text-sm text-indigo-300 hover:text-indigo-200 underline">{{ slide.link }}</a>
                        <button @click="copy(slide.link, 'link')" title="Copy link"
                            class="flex-shrink-0 rounded p-1 text-white/60 hover:bg-white/10 hover:text-white">
                            <svg v-if="copiedField === 'link'" class="h-4 w-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                        </button>
                    </div>

                    <div v-if="slide.media?.length" class="flex flex-wrap gap-2 pt-1">
                        <a v-for="media in slide.media" :key="media.id"
                            :href="route('slides.media.download', [slide.id, media.id])"
                            class="flex items-center gap-1.5 rounded-lg bg-white/10 px-3 py-1.5 text-xs font-medium text-white hover:bg-white/20 transition-colors">
                            <svg v-if="isPdf(media)" class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                            <svg v-else class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M4 8h16M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z" />
                            </svg>
                            {{ mediaLabel(media) }}
                            <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
