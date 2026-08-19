<script setup>
defineProps({
    slide: { type: Object, default: null },
});

defineEmits(['close']);
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
                class="fixed inset-0 z-50 flex flex-col items-center justify-center bg-black/85 p-4"
                @click.self="$emit('close')">

                <!-- Close button -->
                <button @click="$emit('close')"
                    class="absolute top-4 right-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20 transition-colors"
                    aria-label="Close">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                <!-- Preview -->
                <video v-if="slide.mime_type?.startsWith('video/')"
                    :src="slide.file_url"
                    controls autoplay loop
                    class="max-h-[80vh] max-w-full rounded-lg object-contain shadow-2xl" />
                <img v-else :src="slide.file_url || slide.thumbnail_url"
                    :alt="slide.title"
                    class="max-h-[80vh] max-w-full rounded-lg object-contain shadow-2xl" />

                <!-- Caption + download -->
                <div class="mt-4 flex items-center gap-4">
                    <p class="text-white font-medium text-lg">{{ slide.title }}</p>
                    <a :href="route('slides.download', slide.id)"
                        class="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition-colors">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        {{ $t('slides.download') }}
                    </a>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
