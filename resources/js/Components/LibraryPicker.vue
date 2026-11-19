<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';

// A grid of library items to pick from: backgrounds (thumbnails) or overlay
// templates (the SVG over a dark panel). The catalog is fetched when it opens;
// where the viewer may not use the library the request fails and `unavailable`
// is emitted so the caller can hide its button.
const props = defineProps({
    kind: { type: String, default: 'backgrounds' }, // 'backgrounds' | 'templates'
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['pick', 'unavailable']);

const items = ref([]);
const loading = ref(true);

const isBackground = computed(() => props.kind === 'backgrounds');

onMounted(async () => {
    try {
        items.value = (await axios.get(route('library.catalog'))).data[props.kind] ?? [];
    } catch {
        emit('unavailable');
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
        <p v-if="loading" class="text-xs text-gray-500">{{ $t('library.loading') }}</p>
        <p v-else-if="!items.length" class="text-xs text-gray-500">{{ $t('library.library_none_for_type') }}</p>
        <div v-else class="grid max-h-56 grid-cols-3 gap-2 overflow-y-auto sm:grid-cols-4">
            <button v-for="item in items" :key="item.id" type="button" :disabled="busy" :title="item.title"
                class="overflow-hidden rounded-lg border-2 border-gray-200 bg-white text-left hover:border-indigo-500 disabled:opacity-50"
                @click="emit('pick', item)">
                <div class="relative aspect-video" :class="isBackground ? '' : 'bg-slate-600'">
                    <img :src="isBackground ? item.thumbnail_url : item.preview_url" :alt="item.title"
                        class="h-full w-full" :class="isBackground ? 'object-cover' : 'object-contain'" loading="lazy" />
                    <span v-if="item.is_video" class="absolute left-1 top-1 rounded bg-black/60 px-1 text-[10px] font-medium uppercase text-white">{{ $t('library.video') }}</span>
                </div>
                <div class="truncate px-1.5 py-1 text-xs text-gray-700">{{ item.title }}</div>
            </button>
        </div>
        <p v-if="busy" class="mt-2 text-xs text-gray-500">{{ $t('library.library_adding') }}</p>
    </div>
</template>
