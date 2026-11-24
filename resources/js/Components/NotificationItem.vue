<script setup>
import { useNotificationText } from '@/Composables/useNotificationText.js';
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    notification: { type: Object, required: true },
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['changed']);

const { message, categoryLabel, when } = useNotificationText();

const n = computed(() => props.notification);
const placements = computed(() => n.value.params?.placements ?? []);
const startsAt = computed(() => n.value.params?.starts_at
    ? new Date(n.value.params.starts_at + 'T00:00:00').toLocaleDateString()
    : null);

function post(name) {
    router.post(route(name, n.value.id), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('changed'),
    });
}
</script>

<template>
    <div
        class="flex gap-3 px-4 py-3"
        :class="n.read ? 'bg-white' : 'bg-indigo-50/60'"
    >
        <div class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="n.read ? 'bg-transparent' : 'bg-indigo-500'" />

        <img
            v-if="n.thumbnail"
            :src="n.thumbnail"
            alt=""
            class="shrink-0 rounded object-cover bg-gray-100"
            :class="compact ? 'h-9 w-16' : 'h-12 w-20'"
        />

        <div class="min-w-0 flex-1">
            <Link
                :href="route('notifications.open', n.id)"
                class="block text-sm text-gray-900 hover:text-indigo-700"
                :class="{ 'font-medium': !n.read }"
            >
                {{ message(n) }}
            </Link>

            <ul v-if="placements.length && !compact" class="mt-1 space-y-0.5 text-xs text-gray-600">
                <li v-for="p in placements" :key="p.entity">
                    <span class="font-medium">{{ p.entity }}:</span>
                    <template v-if="p.shows?.length">
                        {{ $t('notifications.added_to', { shows: p.shows.join(', ') }) }}
                    </template>
                    <template v-else>{{ $t('notifications.not_added') }}</template>
                </li>
                <li v-if="n.params?.more">{{ $t('notifications.more_churches', { n: n.params.more }) }}</li>
            </ul>
            <p v-if="startsAt && !compact" class="mt-1 text-xs text-gray-600">
                {{ $t('notifications.starts', { date: startsAt }) }}
            </p>

            <div class="mt-0.5 flex items-center gap-2 text-xs text-gray-500">
                <span>{{ when(n.created_at) }}</span>
                <span v-if="!compact">· {{ categoryLabel(n.category) }}</span>
            </div>
        </div>

        <div class="flex shrink-0 items-start gap-1">
            <button
                v-if="!n.read && !compact"
                type="button"
                class="rounded px-2 py-1 text-xs text-gray-500 hover:bg-gray-100 hover:text-gray-800"
                :title="$t('notifications.mark_read')"
                @click="post('notifications.read')"
            >
                {{ $t('notifications.mark_read') }}
            </button>
            <button
                v-if="!n.archived"
                type="button"
                class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                :title="$t('notifications.clear')"
                :aria-label="$t('notifications.clear')"
                @click="post('notifications.archive')"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <button
                v-else
                type="button"
                class="rounded px-2 py-1 text-xs text-gray-500 hover:bg-gray-100 hover:text-gray-800"
                @click="post('notifications.restore')"
            >
                {{ $t('notifications.restore') }}
            </button>
        </div>
    </div>
</template>
