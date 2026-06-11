<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    entity: { type: Object, required: true },
    slide:  { type: Object, required: true },
});

function toLocalDatetime(iso) {
    if (!iso) return '';
    return iso.slice(0, 16);
}

const form = useForm({
    title:      props.slide.title,
    notes:      props.slide.notes ?? '',
    publish_at: toLocalDatetime(props.slide.publish_at),
    expires_at: toLocalDatetime(props.slide.expires_at),
});

function submit() {
    form.patch(route('entity.slides.update', { entity: props.entity.id, slide: props.slide.id }));
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('entity.slides.index', entity.id)" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <h1 class="text-xl font-semibold text-gray-900">Edit Slide — {{ entity.name }}</h1>
            </div>
        </template>

        <div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="mb-6 rounded-xl overflow-hidden bg-slate-100 aspect-video w-full max-w-sm">
                <img v-if="slide.thumbnail_url || slide.file_url"
                    :src="slide.thumbnail_url || slide.file_url"
                    :alt="slide.title"
                    class="w-full h-full object-contain" />
            </div>

            <form @submit.prevent="submit" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Title <span class="text-red-500">*</span></label>
                    <input v-model="form.title" type="text" required
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <p v-if="form.errors.title" class="mt-1 text-xs text-red-600">{{ form.errors.title }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes <span class="text-gray-400 font-normal">(optional)</span></label>
                    <textarea v-model="form.notes" rows="3"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Publish Date</label>
                        <input v-model="form.publish_at" type="datetime-local"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Expiration Date</label>
                        <input v-model="form.expires_at" type="datetime-local"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                        {{ form.processing ? 'Saving…' : 'Save Changes' }}
                    </button>
                    <Link :href="route('entity.slides.index', entity.id)"
                        class="rounded-lg border border-gray-300 px-5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                        Cancel
                    </Link>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
