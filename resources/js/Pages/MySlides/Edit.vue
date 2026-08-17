<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ValidationWarnings from '@/Components/ValidationWarnings.vue';
import MediaManager from '@/Components/MediaManager.vue';

const props = defineProps({
    slide: { type: Object, required: true },
    languages: { type: Array, default: () => [] },
    canSetStatus: { type: Boolean, default: false },
    mediaTypes: { type: Array, default: () => [] },
});

function toLocalDatetime(iso) {
    if (!iso) return '';
    return iso.slice(0, 16);
}

const form = useForm({
    title:             props.slide.title,
    notes:             props.slide.notes ?? '',
    text_description:  props.slide.text_description ?? '',
    link:              props.slide.link ?? '',
    language_id:       props.slide.language_id ?? '',
    publish_at:        toLocalDatetime(props.slide.publish_at),
    expires_at:        toLocalDatetime(props.slide.expires_at),
    status:            props.slide.status,
});

function submit() {
    form.patch(route('my-slides.update', props.slide.id));
}
</script>

<template>
    <AuthenticatedLayout>
        <div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="mb-6 rounded-xl overflow-hidden bg-slate-100 aspect-video w-full max-w-sm">
                <img v-if="slide.thumbnail_url || slide.file_url"
                    :src="slide.thumbnail_url || slide.file_url"
                    :alt="slide.title"
                    class="w-full h-full object-contain" />
            </div>

            <div v-if="slide.validation_issues?.length" class="mb-6">
                <ValidationWarnings :issues="slide.validation_issues" />
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

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description <span class="text-gray-400 font-normal">(optional)</span></label>
                    <textarea v-model="form.text_description" rows="3"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Link <span class="text-gray-400 font-normal">(optional)</span></label>
                    <input v-model="form.link" type="url" placeholder="https://…"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <p v-if="form.errors.link" class="mt-1 text-xs text-red-600">{{ form.errors.link }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Language <span class="text-gray-400 font-normal">(optional)</span></label>
                    <select v-model="form.language_id"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">No specific language (visible in all)</option>
                        <option v-for="lang in languages" :key="lang.id" :value="lang.id">
                            {{ lang.name }} ({{ lang.native_name }})
                        </option>
                    </select>
                    <p v-if="form.errors.language_id" class="mt-1 text-xs text-red-600">{{ form.errors.language_id }}</p>
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

                <div v-if="canSetStatus">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select v-model="form.status"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="published">Published (live)</option>
                        <option value="pending">Pending (submit for review)</option>
                        <option value="draft">Draft (not visible)</option>
                    </select>
                    <p v-if="form.errors.status" class="mt-1 text-xs text-red-600">{{ form.errors.status }}</p>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                        {{ form.processing ? 'Saving…' : 'Save Changes' }}
                    </button>
                    <Link :href="route('my-slides.index')"
                        class="rounded-lg border border-gray-300 px-5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                        Cancel
                    </Link>
                </div>
            </form>

            <div class="mt-6">
                <MediaManager :slide="slide" :media-types="mediaTypes"
                    store-route="my-slides.media.store" destroy-route="my-slides.media.destroy" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
