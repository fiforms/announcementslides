<script setup>
import { computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    stats:         { type: Object, required: true },
    pendingSlides: { type: Array,  default: () => [] },
    expiringSoon:  { type: Array,  default: () => [] },
    recentlyAdded: { type: Array,  default: () => [] },
});

const statCards = computed(() => [
    {
        label: t('admin.live_slides'),
        value: props.stats.live,
        color: 'bg-green-500',
        text:  'text-green-700',
        bg:    'bg-green-50',
        border:'border-green-100',
        icon: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
    },
    {
        label: t('admin.pending_review'),
        value: props.stats.pending,
        color: 'bg-yellow-500',
        text:  'text-yellow-700',
        bg:    'bg-yellow-50',
        border:'border-yellow-100',
        icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
        urgent: props.stats.pending > 0,
    },
    {
        label: t('admin.upcoming'),
        value: props.stats.upcoming,
        color: 'bg-blue-500',
        text:  'text-blue-700',
        bg:    'bg-blue-50',
        border:'border-blue-100',
        icon: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    },
    {
        label: t('admin.expiring_soon'),
        value: props.stats.expiring_soon,
        color: 'bg-orange-500',
        text:  'text-orange-700',
        bg:    'bg-orange-50',
        border:'border-orange-100',
        icon: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
        urgent: props.stats.expiring_soon > 0,
    },
    {
        label: t('admin.in_archive'),
        value: props.stats.archived,
        color: 'bg-gray-400',
        text:  'text-gray-600',
        bg:    'bg-gray-50',
        border:'border-gray-200',
        icon: 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4',
    },
]);

function approve(id) {
    router.post(route('admin.slides.approve', id));
}
function reject(id) {
    router.post(route('admin.slides.reject', id));
}

function expiresClass(isoDate) {
    const hours = (new Date(isoDate) - Date.now()) / 36e5;
    if (hours < 24)  return 'text-red-600 font-semibold';
    if (hours < 72)  return 'text-orange-600 font-medium';
    return 'text-yellow-700';
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">{{ $t('admin.dashboard_title') }}</h1>
        </template>

        <!-- Stat cards -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5 mb-8">
            <div v-for="card in statCards" :key="card.label"
                class="relative rounded-xl border p-4 shadow-sm transition-shadow hover:shadow-md"
                :class="[card.bg, card.border]">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide"
                            :class="card.text">{{ card.label }}</p>
                        <p class="mt-1 text-3xl font-bold text-gray-900">{{ card.value }}</p>
                    </div>
                    <div class="rounded-lg p-2" :class="card.color">
                        <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="card.icon" />
                        </svg>
                    </div>
                </div>
                <!-- Urgent pulse dot -->
                <span v-if="card.urgent"
                    class="absolute top-2 right-2 flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75"
                        :class="card.color" />
                    <span class="relative inline-flex rounded-full h-2 w-2" :class="card.color" />
                </span>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

            <!-- Pending Review -->
            <section class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                    <h2 class="text-sm font-semibold text-gray-900">
                        {{ $t('admin.pending_review') }}
                        <span v-if="pendingSlides.length"
                            class="ml-2 rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-semibold text-yellow-700">
                            {{ pendingSlides.length }}
                        </span>
                    </h2>
                    <Link :href="route('admin.slides.index', { tab: 'pending' })"
                        class="text-xs text-indigo-600 hover:text-indigo-800 transition-colors">
                        {{ $t('admin.view_all') }}
                    </Link>
                </div>

                <div v-if="pendingSlides.length" class="divide-y divide-gray-50">
                    <div v-for="slide in pendingSlides" :key="slide.id"
                        class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50 transition-colors">
                        <div class="h-10 w-16 flex-shrink-0 rounded overflow-hidden bg-slate-100">
                            <img v-if="slide.thumbnail_url || slide.file_url"
                                :src="slide.thumbnail_url || slide.file_url"
                                class="h-full w-full object-cover" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ slide.title }}</p>
                            <p class="text-xs text-gray-400">
                                {{ slide.uploader?.name ?? 'Unknown' }} · {{ slide.created_at }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <button @click="approve(slide.id)"
                                class="rounded-md bg-green-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-700 transition-colors">
                                {{ $t('admin.approve') }}
                            </button>
                            <button @click="reject(slide.id)"
                                class="rounded-md border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition-colors">
                                {{ $t('admin.reject') }}
                            </button>
                        </div>
                    </div>
                </div>

                <div v-else class="px-5 py-10 text-center text-sm text-gray-400">
                    {{ $t('admin.no_pending_slides') }}
                </div>
            </section>

            <!-- Expiring Soon -->
            <section class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                    <h2 class="text-sm font-semibold text-gray-900">
                        {{ $t('admin.expiring_within_7_days') }}
                        <span v-if="expiringSoon.length"
                            class="ml-2 rounded-full bg-orange-100 px-2 py-0.5 text-xs font-semibold text-orange-700">
                            {{ expiringSoon.length }}
                        </span>
                    </h2>
                    <Link :href="route('admin.slides.index')"
                        class="text-xs text-indigo-600 hover:text-indigo-800 transition-colors">
                        {{ $t('admin.manage') }}
                    </Link>
                </div>

                <div v-if="expiringSoon.length" class="divide-y divide-gray-50">
                    <div v-for="slide in expiringSoon" :key="slide.id"
                        class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50 transition-colors">
                        <div class="h-10 w-16 flex-shrink-0 rounded overflow-hidden bg-slate-100">
                            <img v-if="slide.thumbnail_url || slide.file_url"
                                :src="slide.thumbnail_url || slide.file_url"
                                class="h-full w-full object-cover" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ slide.title }}</p>
                            <p class="text-xs" :class="expiresClass(slide.expires_at)">
                                Expires {{ slide.expires_human }}
                            </p>
                        </div>
                        <Link :href="route('admin.slides.edit', slide.id)"
                            class="flex-shrink-0 rounded-md border border-gray-200 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 transition-colors">
                            {{ $t('admin.edit') }}
                        </Link>
                    </div>
                </div>

                <div v-else class="px-5 py-10 text-center text-sm text-gray-400">
                    {{ $t('admin.no_expiring_soon') }}
                </div>
            </section>

            <!-- Recently Added -->
            <section class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden lg:col-span-2">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                    <h2 class="text-sm font-semibold text-gray-900">{{ $t('admin.recently_published') }}</h2>
                    <Link :href="route('admin.slides.index')"
                        class="text-xs text-indigo-600 hover:text-indigo-800 transition-colors">
                        {{ $t('admin.all_slides') }}
                    </Link>
                </div>

                <div v-if="recentlyAdded.length" class="divide-y divide-gray-50">
                    <div v-for="slide in recentlyAdded" :key="slide.id"
                        class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50 transition-colors">
                        <div class="h-10 w-16 flex-shrink-0 rounded overflow-hidden bg-slate-100">
                            <img v-if="slide.thumbnail_url || slide.file_url"
                                :src="slide.thumbnail_url || slide.file_url"
                                class="h-full w-full object-cover" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ slide.title }}</p>
                            <p class="text-xs text-gray-400">
                                {{ slide.uploader?.name ?? 'Unknown' }} · {{ slide.created_at }}
                            </p>
                        </div>
                        <Link :href="route('admin.slides.edit', slide.id)"
                            class="flex-shrink-0 rounded-md border border-gray-200 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 transition-colors">
                            {{ $t('admin.edit') }}
                        </Link>
                    </div>
                </div>

                <div v-else class="px-5 py-10 text-center text-sm text-gray-400">
                    {{ $t('admin.no_published_slides') }}
                    <Link :href="route('admin.slides.index')"
                        class="ml-1 text-indigo-600 hover:underline">{{ $t('admin.upload_first') }}</Link>
                </div>
            </section>

        </div>
    </AdminLayout>
</template>
