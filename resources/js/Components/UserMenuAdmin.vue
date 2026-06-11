<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    user: {
        type: Object,
        required: true,
    },
});

function getInitials(name) {
    return name
        .split(' ')
        .map(part => part[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}
</script>

<template>
    <div class="border-t border-gray-700 px-4 py-4 space-y-3">
        <!-- User Avatar and Info -->
        <div class="flex items-center gap-3">
            <div
                class="h-10 w-10 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-sm font-semibold text-white flex-shrink-0"
                :title="user.name"
            >
                {{ getInitials(user.name) }}
            </div>
            <div class="min-w-0">
                <div class="text-sm font-medium text-white truncate">
                    {{ user.name }}
                </div>
                <div class="text-xs text-gray-400 truncate">
                    {{ user.email }}
                </div>
            </div>
        </div>

        <!-- Profile Link -->
        <Link :href="route('profile.edit')"
            class="block w-full rounded px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 hover:text-white transition-colors text-center">
            {{ $t('nav.profile') }}
        </Link>

        <!-- Logout Link -->
        <Link :href="route('logout')" method="post" as="button"
            class="block w-full rounded px-3 py-2 text-sm text-gray-300 hover:bg-red-900/20 hover:text-red-200 transition-colors text-center">
            {{ $t('nav.log_out') }}
        </Link>
    </div>
</template>
