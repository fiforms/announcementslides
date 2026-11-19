<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';

// "Build a slide" from the admin-managed library: pick a shared background
// and, optionally, an overlay template. The server points the slide at the
// library's one stored background and copies the template's overlay into the
// slide (LibraryBuildController), then the caller opens it for editing.
//
// `fixedShow` places the slide in that show; without it (global slides) it
// goes on the Global Board.
const props = defineProps({
    entityId: { type: Number, default: null },
    fixedShow: { type: Object, default: null },
    languageId: { type: [Number, String], default: null },
});
const emit = defineEmits(['built', 'close']);

const { t } = useI18n();
const loading = ref(true);
const backgrounds = ref([]);
const templates = ref([]);
const title = ref('');
const backgroundId = ref(null);
const templateId = ref(null);
const busy = ref(false);
const error = ref(null);

const background = computed(() => backgrounds.value.find(b => b.id === backgroundId.value) ?? null);
const template = computed(() => templates.value.find(x => x.id === templateId.value) ?? null);

onMounted(async () => {
    try {
        const { data } = await axios.get(route('library.catalog'));
        backgrounds.value = data.backgrounds;
        templates.value = data.templates;
    } catch {
        error.value = t('library.build_failed');
    } finally {
        loading.value = false;
    }
});

// Picking a template suggests its name as the title until the user types one.
function pickTemplate(id) {
    templateId.value = id;
}

async function build() {
    error.value = null;
    if (!backgroundId.value && !templateId.value) {
        error.value = t('library.need_one');
        return;
    }
    busy.value = true;
    try {
        const payload = {
            title: title.value.trim() || background.value?.title || template.value?.title,
            entity_id: props.entityId,
            background_id: backgroundId.value,
            template_id: templateId.value,
            language_id: props.languageId || null,
        };
        if (props.fixedShow) {
            payload.add_to_show = props.fixedShow.is_main ? 'main' : 'separate';
            if (!props.fixedShow.is_main) payload.show_id = props.fixedShow.id;
        } else {
            payload.add_to_show = 'main';
        }
        const { data } = await axios.post(route('library.build'), payload);
        emit('built', data);
    } catch (e) {
        error.value = e.response?.data?.message ?? t('library.build_failed');
    } finally {
        busy.value = false;
    }
}

const tile = (active) => ['relative overflow-hidden rounded-lg border-2 text-left transition-colors',
    active ? 'border-indigo-600 ring-2 ring-indigo-200' : 'border-gray-200 hover:border-gray-400'];
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="emit('close')">
        <div class="max-h-[92vh] w-full max-w-5xl space-y-4 overflow-y-auto rounded-2xl bg-white p-6 shadow-xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">{{ $t('library.builder_title') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ $t('library.builder_intro') }}</p>
                </div>
                <button type="button" :aria-label="$t('library.cancel')" class="text-2xl leading-none text-gray-400 hover:text-gray-600" @click="emit('close')">&times;</button>
            </div>

            <p v-if="loading" class="py-10 text-center text-sm text-gray-500">{{ $t('library.loading') }}</p>
            <p v-else-if="!backgrounds.length && !templates.length" class="py-10 text-center text-sm text-gray-500">{{ $t('library.empty_catalog') }}</p>

            <div v-else class="grid gap-6 lg:grid-cols-[1fr_20rem]">
                <div class="space-y-5">
                    <section>
                        <h4 class="mb-2 text-sm font-medium text-gray-700">{{ $t('library.pick_background') }}</h4>
                        <div class="grid max-h-64 grid-cols-3 gap-2 overflow-y-auto sm:grid-cols-4">
                            <button type="button" :class="tile(backgroundId === null)" @click="backgroundId = null">
                                <div class="flex aspect-video items-center justify-center bg-slate-100 text-xs text-gray-500">{{ $t('library.none') }}</div>
                            </button>
                            <button v-for="b in backgrounds" :key="b.id" type="button" :class="tile(backgroundId === b.id)" :title="b.title" @click="backgroundId = b.id">
                                <img :src="b.thumbnail_url" :alt="b.title" class="aspect-video w-full object-cover" loading="lazy" />
                                <span v-if="b.is_video" class="absolute left-1 top-1 rounded bg-black/60 px-1 text-[10px] font-medium uppercase text-white">{{ $t('library.video') }}</span>
                            </button>
                        </div>
                    </section>

                    <section>
                        <h4 class="mb-2 text-sm font-medium text-gray-700">{{ $t('library.pick_template') }}</h4>
                        <div class="grid max-h-64 grid-cols-3 gap-2 overflow-y-auto sm:grid-cols-4">
                            <button type="button" :class="tile(templateId === null)" @click="pickTemplate(null)">
                                <div class="flex aspect-video items-center justify-center bg-slate-100 text-xs text-gray-500">{{ $t('library.none') }}</div>
                            </button>
                            <button v-for="x in templates" :key="x.id" type="button" :class="tile(templateId === x.id)" :title="x.title" @click="pickTemplate(x.id)">
                                <div class="aspect-video bg-slate-600"><img :src="x.preview_url" :alt="x.title" class="h-full w-full object-contain" loading="lazy" /></div>
                                <div class="truncate bg-white px-1.5 py-1 text-xs text-gray-700">{{ x.title }}</div>
                            </button>
                        </div>
                    </section>
                </div>

                <div class="space-y-3">
                    <!-- Live preview: the template's SVG as an image over the chosen background. -->
                    <div class="relative aspect-video overflow-hidden rounded-lg bg-slate-800">
                        <img v-if="background" :src="background.thumbnail_url" alt="" class="absolute inset-0 h-full w-full object-cover" />
                        <img v-if="template" :src="template.preview_url" alt="" class="absolute inset-0 h-full w-full object-contain" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ $t('library.slide_title') }}</label>
                        <input v-model="title" type="text" maxlength="255" :placeholder="background?.title || template?.title"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100" @click="emit('close')">{{ $t('library.cancel') }}</button>
                        <button type="button" :disabled="busy || (!backgroundId && !templateId)" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50" @click="build">
                            {{ busy ? $t('library.building') : $t('library.build') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
