<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({
    channels: { type: Array, default: () => [] },
    providers: { type: Array, default: () => [] },
});

const addForm = useForm({ name: '', url: '' });
function add() {
    addForm.post(route('admin.live-channels.store'), { preserveScroll: true, onSuccess: () => addForm.reset() });
}

const editingId = ref(null);
const editForm = useForm({ name: '', url: '' });
function startEdit(channel) {
    editingId.value = channel.id;
    editForm.clearErrors();
    editForm.name = channel.name;
    editForm.url = channel.url ?? '';
}
function saveEdit(channel) {
    editForm.patch(route('admin.live-channels.update', channel.id), {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
    });
}

function destroy(channel) {
    const note = channel.shows_count ? ` ${channel.shows_count} show(s) using it will lose their background.` : '';
    if (confirm(`Delete "${channel.name}"?${note}`)) {
        router.delete(route('admin.live-channels.destroy', channel.id), { preserveScroll: true });
    }
}

const inputClass = 'rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">Live Channels</h1>
        </template>

        <div class="mb-6 max-w-3xl space-y-2 text-sm text-gray-500">
            <p>
                Live streams church leaders can pick as a show's background (Shows → background). Changing a
                channel's link here updates every show using it at the screens' next sync.
            </p>
            <p>
                Supported: {{ providers.join(', ') }}. Paste the player's embed link or the
                <code>&lt;iframe&gt;</code> code from the broadcaster's watch page, or a direct
                <code>https://….m3u8</code> stream (the stream must allow cross-origin playback). Only add streams
                you have permission to show; their content is not reviewed per slide.
            </p>
        </div>

        <form class="mb-8 max-w-3xl space-y-3 rounded-xl border border-gray-200 bg-white p-5 shadow-sm" @submit.prevent="add">
            <h2 class="text-base font-semibold text-gray-900">Add a channel</h2>
            <div class="grid gap-3 sm:grid-cols-[1fr_2fr]">
                <input v-model="addForm.name" type="text" placeholder="Name (e.g. Hope Channel)" :class="inputClass" />
                <input v-model="addForm.url" type="text" placeholder="Embed link, iframe code, or .m3u8 stream" :class="inputClass" />
            </div>
            <p v-if="addForm.errors.name" class="text-xs text-red-600">{{ addForm.errors.name }}</p>
            <p v-if="addForm.errors.url" class="text-xs text-red-600">{{ addForm.errors.url }}</p>
            <button type="submit" :disabled="addForm.processing || !addForm.name || !addForm.url"
                class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                Add
            </button>
        </form>

        <div v-if="channels.length" class="max-w-3xl divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white shadow-sm">
            <div v-for="channel in channels" :key="channel.id" class="px-5 py-4">
                <form v-if="editingId === channel.id" class="space-y-2" @submit.prevent="saveEdit(channel)">
                    <div class="grid gap-3 sm:grid-cols-[1fr_2fr]">
                        <input v-model="editForm.name" type="text" :class="inputClass" />
                        <input v-model="editForm.url" type="text" :class="inputClass" />
                    </div>
                    <p v-if="editForm.errors.name" class="text-xs text-red-600">{{ editForm.errors.name }}</p>
                    <p v-if="editForm.errors.url" class="text-xs text-red-600">{{ editForm.errors.url }}</p>
                    <div class="flex gap-2">
                        <button type="submit" :disabled="editForm.processing"
                            class="rounded-md bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-700">Save</button>
                        <button type="button" @click="editingId = null"
                            class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                    </div>
                </form>
                <div v-else class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900">{{ channel.name }}</h2>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ channel.provider_label }}
                            <template v-if="!channel.supports_mute"> · can't be muted</template>
                            · used by {{ channel.shows_count }} show(s)
                        </p>
                        <p class="mt-1 break-all font-mono text-xs text-gray-400">
                            {{ channel.url ?? 'Stored link no longer valid — edit it.' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a v-if="channel.url && channel.kind === 'iframe'" :href="channel.url" target="_blank" rel="noopener noreferrer"
                            class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                            Open
                        </a>
                        <button type="button" @click="startEdit(channel)"
                            class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                            Edit
                        </button>
                        <button type="button" @click="destroy(channel)"
                            class="rounded-md border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50">
                            Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <p v-else class="text-sm text-gray-500">No live channels yet.</p>
    </AdminLayout>
</template>
