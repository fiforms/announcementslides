<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import OverlayEditor from '@/Components/OverlayEditor/OverlayEditor.vue';

const props = defineProps({
    template: { type: Object, required: true },
    backgrounds: { type: Array, default: () => [] },
});

const { t } = useI18n();
const editor = ref(null);

// Only a guide to place things against while editing; never saved with the template.
const backgroundId = ref(props.backgrounds[0]?.id ?? '');
const backgroundUrl = computed(() => props.backgrounds.find(b => b.id === backgroundId.value)?.url ?? null);

// The editor reads a slide-shaped object: the template's overlay stands in for the media row.
const slide = computed(() => ({
    id: props.template.id,
    media: props.template.ready
        ? [{ id: props.template.id, media_type: 'slide-overlay', file_url: props.template.file_url, mime_type: props.template.mime_type }]
        : [],
}));

function leave(evt) {
    if (editor.value?.isDirty() && !confirm(t('overlay_editor.confirm_discard'))) evt.preventDefault();
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <div class="flex w-full items-center justify-between gap-4">
                <h1 class="truncate text-xl font-semibold text-gray-900">{{ template.title }}</h1>
                <Link :href="route('admin.library.index')" class="text-sm text-gray-600 hover:text-indigo-700" @click="leave">
                    &larr; {{ $t('library.back') }}
                </Link>
            </div>
        </template>

        <div class="max-w-6xl space-y-4">
            <p class="text-sm text-gray-600">{{ $t('library.template_intro') }}</p>

            <label v-if="backgrounds.length" class="flex items-center gap-2 text-sm text-gray-700">
                {{ $t('library.preview_behind') }}
                <select v-model="backgroundId" class="rounded-lg border border-gray-300 px-2 py-1 text-sm">
                    <option value="">{{ $t('library.no_preview_bg') }}</option>
                    <option v-for="b in backgrounds" :key="b.id" :value="b.id">{{ b.title }}</option>
                </select>
            </label>

            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <OverlayEditor ref="editor" :slide="slide" :background="backgroundUrl"
                    :route-model="{ item: template.id }"
                    show-route="admin.library.overlay.show" save-route="admin.library.overlay.save"
                    destroy-route="admin.library.overlay.destroy" :reload-only="['template']" />
            </div>
        </div>
    </AdminLayout>
</template>
