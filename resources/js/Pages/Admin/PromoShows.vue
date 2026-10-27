<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({
    promos: { type: Array, default: () => [] },
});

// ── Rename ──────────────────────────────────────────────────────────────────

const renamingId = ref(null);
const renameValue = ref('');

function startRename(promo) {
    renamingId.value = promo.id;
    renameValue.value = promo.name;
}

function saveRename(promo) {
    const name = renameValue.value.trim();
    if (!name || name === promo.name) {
        renamingId.value = null;
        return;
    }
    router.patch(route('admin.promo-shows.update', promo.id), { name }, {
        preserveScroll: true,
        onSuccess: () => { renamingId.value = null; },
    });
}

// ── Remove / delete ─────────────────────────────────────────────────────────

function detachSlide(promo, slide) {
    if (confirm(`Remove "${slide.title}" from "${promo.name}" at every site? The slide itself is kept.`)) {
        router.delete(route('admin.promo-shows.slides.detach', [promo.id, slide.id]), { preserveScroll: true });
    }
}

function destroy(promo) {
    if (confirm(`Delete "${promo.name}" from all ${promo.sites_count} site(s)? Its slides are kept.`)) {
        router.delete(route('admin.promo-shows.destroy', promo.id), { preserveScroll: true });
    }
}

// ── Links ───────────────────────────────────────────────────────────────────

const copiedUrl = ref(null);

async function copyLink(url) {
    try {
        await navigator.clipboard.writeText(url);
        copiedUrl.value = url;
        setTimeout(() => { if (copiedUrl.value === url) copiedUrl.value = null; }, 2000);
    } catch {
        window.prompt('Copy this link:', url);
    }
}

function formatDate(iso) {
    return iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : null;
}

function slideState(slide) {
    if (slide.is_current) return { label: 'Live', classes: 'bg-green-100 text-green-700' };
    if (slide.status !== 'published') return { label: slide.status, classes: 'bg-gray-100 text-gray-600' };
    if (slide.expires_at && new Date(slide.expires_at) <= new Date()) return { label: 'Expired', classes: 'bg-red-100 text-red-700' };
    return { label: 'Upcoming', classes: 'bg-amber-100 text-amber-700' };
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">Promo Shows</h1>
        </template>

        <p class="mb-6 max-w-3xl text-sm text-gray-500">
            One-off shows distributed to every site (e.g. a special promo video). Create one — or add slides to an
            existing one — from
            <Link :href="route('admin.slides.index')" class="text-indigo-600 hover:underline">Slides</Link>
            by choosing <em>Distribute as a separate one-off show</em> when uploading. A promo is removed from every
            site automatically once none of its slides are live.
        </p>

        <div v-if="promos.length" class="space-y-6">
            <div v-for="promo in promos" :key="promo.id" class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-100 px-5 py-4">
                    <div class="min-w-0">
                        <form v-if="renamingId === promo.id" class="flex items-center gap-2" @submit.prevent="saveRename(promo)">
                            <input v-model="renameValue" type="text" autofocus
                                class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            <button type="submit"
                                class="rounded-md bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-700">Save</button>
                            <button type="button" @click="renamingId = null"
                                class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                        </form>
                        <h2 v-else class="text-base font-semibold text-gray-900">{{ promo.name }}</h2>
                        <p class="mt-1 text-xs text-gray-500">
                            On {{ promo.sites_count }} site(s)
                            <template v-if="promo.creator"> · created by {{ promo.creator.name }}</template>
                            · {{ formatDate(promo.created_at) }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <a :href="promo.public_url" target="_blank" rel="noopener"
                            class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                            View
                        </a>
                        <button type="button" @click="copyLink(promo.public_url)"
                            class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                            {{ copiedUrl === promo.public_url ? 'Copied!' : 'Copy link' }}
                        </button>
                        <button v-if="renamingId !== promo.id" type="button" @click="startRename(promo)"
                            class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                            Rename
                        </button>
                        <button type="button" @click="destroy(promo)"
                            class="rounded-md border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition-colors">
                            Delete
                        </button>
                    </div>
                </div>

                <ul v-if="promo.slides.length" class="divide-y divide-gray-100">
                    <li v-for="slide in promo.slides" :key="slide.id" class="flex flex-wrap items-center gap-4 px-5 py-3">
                        <div class="h-12 w-20 flex-shrink-0 overflow-hidden rounded bg-slate-100">
                            <img v-if="slide.thumbnail_url || (slide.file_url && !slide.mime_type?.startsWith('video/'))"
                                :src="slide.thumbnail_url || slide.file_url" class="h-full w-full object-cover" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="truncate text-sm font-medium text-gray-900">{{ slide.title }}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold capitalize" :class="slideState(slide).classes">
                                    {{ slideState(slide).label }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs text-gray-500">
                                <template v-if="slide.publish_at">From {{ formatDate(slide.publish_at) }} · </template>
                                {{ slide.expires_at ? `Expires ${formatDate(slide.expires_at)}` : 'No expiry' }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" @click="copyLink(slide.public_url)"
                                class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                                {{ copiedUrl === slide.public_url ? 'Copied!' : 'Copy link' }}
                            </button>
                            <Link :href="route('admin.slides.edit', slide.id)"
                                class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                                Edit
                            </Link>
                            <button type="button" @click="detachSlide(promo, slide)"
                                class="rounded-md border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition-colors">
                                Remove
                            </button>
                        </div>
                    </li>
                </ul>
                <p v-else class="px-5 py-4 text-sm text-gray-400">No slides — this promo will be swept automatically.</p>
            </div>
        </div>

        <div v-else class="rounded-xl border-2 border-dashed border-gray-200 py-16 text-center">
            <p class="text-gray-400">No promo shows yet.</p>
        </div>
    </AdminLayout>
</template>
