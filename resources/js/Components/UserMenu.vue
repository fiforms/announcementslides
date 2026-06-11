<script setup>
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';

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
    <div class="relative ms-3">
        <Dropdown align="right" width="48">
            <template #trigger>
                <button
                    type="button"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-blue-600 text-sm font-semibold text-white shadow-sm transition duration-150 ease-in-out hover:shadow-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                    :title="user.name"
                >
                    {{ getInitials(user.name) }}
                </button>
            </template>

            <template #content>
                <div class="px-4 py-3 border-b border-gray-100">
                    <div class="text-sm font-medium text-gray-900">
                        {{ user.name }}
                    </div>
                    <div class="text-xs text-gray-500 mt-1">
                        {{ user.email }}
                    </div>
                </div>
                <DropdownLink
                    :href="route('profile.edit')"
                >
                    {{ $t('nav.profile') }}
                </DropdownLink>
                <DropdownLink
                    :href="route('logout')"
                    method="post"
                    as="button"
                >
                    {{ $t('nav.log_out') }}
                </DropdownLink>
            </template>
        </Dropdown>
    </div>
</template>
