<script setup>
import NotificationItem from '@/Components/NotificationItem.vue';
import axios from 'axios';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

defineProps({
    // 'light' for the indigo top bars, 'dark' for the admin sidebar.
    tone: { type: String, default: 'light' },
});

const page = usePage();
const open = ref(false);
const loading = ref(false);
const items = ref([]);
const unread = computed(() => page.props.unreadNotifications ?? 0);

async function load() {
    loading.value = true;
    try {
        const { data } = await axios.get(route('notifications.recent'));
        items.value = data.notifications;
    } finally {
        loading.value = false;
    }
}

function toggle() {
    open.value = !open.value;
    if (open.value) load();
}

function bulk(name) {
    router.post(route(name), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: load,
    });
}

const onKey = (e) => { if (e.key === 'Escape') open.value = false; };

// Keep the badge fresh on a page left open (cheap: Inertia partial reload of one prop).
let timer = null;
function poll() {
    if (document.visibilityState === 'visible' && !open.value) {
        router.reload({ only: ['unreadNotifications'] });
    }
}

onMounted(() => {
    document.addEventListener('keydown', onKey);
    timer = setInterval(poll, 120000);
});
onUnmounted(() => {
    document.removeEventListener('keydown', onKey);
    clearInterval(timer);
});
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="relative inline-flex h-10 w-10 items-center justify-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-300"
            :class="tone === 'dark' ? 'text-gray-300 hover:bg-gray-800 hover:text-white' : 'text-indigo-100 hover:bg-indigo-600 hover:text-white'"
            :aria-label="$t('notifications.title')"
            :title="$t('notifications.title')"
            @click="toggle"
        >
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <span
                v-if="unread > 0"
                class="absolute -right-0.5 -top-0.5 inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1 text-[11px] font-semibold leading-5 text-white"
            >
                {{ unread > 99 ? '99+' : unread }}
            </span>
        </button>

        <div v-show="open" class="fixed inset-0 z-40" @click="open = false" />

        <div
            v-show="open"
            class="z-50 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-md bg-white shadow-lg ring-1 ring-black/5"
            :class="tone === 'dark' ? 'fixed bottom-4 left-60' : 'absolute right-0'"
        >
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2">
                <span class="text-sm font-semibold text-gray-900">{{ $t('notifications.title') }}</span>
                <div class="flex gap-3 text-xs">
                    <button v-if="unread > 0" type="button" class="text-indigo-600 hover:underline" @click="bulk('notifications.read-all')">
                        {{ $t('notifications.mark_all_read') }}
                    </button>
                    <button v-if="items.length" type="button" class="text-indigo-600 hover:underline" @click="bulk('notifications.archive-all')">
                        {{ $t('notifications.clear_all') }}
                    </button>
                </div>
            </div>

            <div class="max-h-[60vh] divide-y divide-gray-100 overflow-y-auto">
                <p v-if="loading && !items.length" class="px-4 py-6 text-center text-sm text-gray-500">…</p>
                <p v-else-if="!items.length" class="px-4 py-6 text-center text-sm text-gray-500">
                    {{ $t('notifications.empty_inbox') }}
                </p>
                <NotificationItem
                    v-for="n in items"
                    :key="n.id"
                    :notification="n"
                    compact
                    @changed="load"
                />
            </div>

            <Link
                :href="route('notifications.index')"
                class="block border-t border-gray-100 px-4 py-2 text-center text-sm font-medium text-indigo-600 hover:bg-gray-50"
            >
                {{ $t('notifications.view_all') }}
            </Link>
        </div>
    </div>
</template>
