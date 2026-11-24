<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import NotificationItem from '@/Components/NotificationItem.vue';
import { useNotificationText } from '@/Composables/useNotificationText.js';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const { categoryLabel } = useNotificationText();

const props = defineProps({
    tab: { type: String, default: 'inbox' },
    category: { type: String, default: null },
    notifications: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    inboxCount: { type: Number, default: 0 },
});

function query(overrides = {}) {
    const q = { tab: props.tab, category: props.category, ...overrides };
    if (q.tab === 'inbox') delete q.tab;
    if (!q.category) delete q.category;
    return q;
}

function clearAll() {
    if (!confirm(t('notifications.clear_all_confirm'))) return;
    router.post(route('notifications.archive-all'), { category: props.category }, { preserveScroll: true });
}

function readAll() {
    router.post(route('notifications.read-all'), {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('notifications.title')" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h1 class="text-2xl font-semibold text-gray-900">{{ t('notifications.title') }}</h1>
                    <Link :href="route('profile.edit') + '#notifications'" class="text-sm text-indigo-600 hover:underline">
                        {{ t('notifications.preferences') }}
                    </Link>
                </div>

                <div class="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-gray-200">
                    <nav class="-mb-px flex gap-6">
                        <Link
                            :href="route('notifications.index', query({ tab: 'inbox', category: null }))"
                            class="border-b-2 px-1 pb-2 text-sm font-medium"
                            :class="tab === 'inbox' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        >
                            {{ t('notifications.inbox') }}
                            <span v-if="inboxCount" class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{{ inboxCount }}</span>
                        </Link>
                        <Link
                            :href="route('notifications.index', query({ tab: 'archive', category: null }))"
                            class="border-b-2 px-1 pb-2 text-sm font-medium"
                            :class="tab === 'archive' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        >
                            {{ t('notifications.archive') }}
                        </Link>
                    </nav>

                    <div v-if="tab === 'inbox' && notifications.data.length" class="flex gap-4 pb-2 text-sm">
                        <button type="button" class="text-indigo-600 hover:underline" @click="readAll">
                            {{ t('notifications.mark_all_read') }}
                        </button>
                        <button type="button" class="text-indigo-600 hover:underline" @click="clearAll">
                            {{ category ? t('notifications.clear_category') : t('notifications.clear_all') }}
                        </button>
                    </div>
                </div>

                <div v-if="categories.length > 1" class="mb-4 flex flex-wrap gap-2">
                    <Link
                        :href="route('notifications.index', query({ category: null }))"
                        class="rounded-full px-3 py-1 text-xs font-medium"
                        :class="!category ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50'"
                    >
                        {{ t('notifications.all_categories') }}
                    </Link>
                    <Link
                        v-for="c in categories"
                        :key="c"
                        :href="route('notifications.index', query({ category: c }))"
                        class="rounded-full px-3 py-1 text-xs font-medium"
                        :class="category === c ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50'"
                    >
                        {{ categoryLabel(c) }}
                    </Link>
                </div>

                <p v-if="tab === 'archive'" class="mb-3 text-xs text-gray-500">
                    {{ t('notifications.archive_hint') }}
                </p>

                <div class="overflow-hidden rounded-lg bg-white shadow">
                    <div v-if="notifications.data.length" class="divide-y divide-gray-100">
                        <NotificationItem v-for="n in notifications.data" :key="n.id" :notification="n" />
                    </div>
                    <p v-else class="px-4 py-12 text-center text-sm text-gray-500">
                        {{ tab === 'archive' ? t('notifications.empty_archive') : t('notifications.empty_inbox') }}
                    </p>
                </div>

                <div v-if="notifications.last_page > 1" class="mt-4 flex justify-between text-sm">
                    <Link v-if="notifications.prev_page_url" :href="notifications.prev_page_url" preserve-scroll class="text-indigo-600 hover:underline">
                        ← {{ t('notifications.newer') }}
                    </Link>
                    <span v-else />
                    <Link v-if="notifications.next_page_url" :href="notifications.next_page_url" preserve-scroll class="text-indigo-600 hover:underline">
                        {{ t('notifications.older') }} →
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
