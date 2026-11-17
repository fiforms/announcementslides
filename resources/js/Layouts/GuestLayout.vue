<script setup>
import { useAppName } from '@/Composables/useAppName.js';
import { Link, usePage } from '@inertiajs/vue3';

const appName = useAppName();
const page = usePage();
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-gray-100 pt-6 sm:justify-center sm:pt-0"
    >
        <div>
            <Link v-if="page.props.brand?.logo" href="/">
                <img :src="page.props.brand.logo" :alt="appName" class="mx-auto max-h-64 w-auto max-w-[80vw] sm:max-h-80">
            </Link>
            <Link v-else href="/" class="text-2xl font-bold text-gray-700">
                {{ appName }}
            </Link>
        </div>

        <div
            class="mt-6 w-full overflow-hidden bg-white px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg"
        >
            <slot />
        </div>

        <Link :href="route('about')" class="mt-4 pb-6 text-sm text-gray-500 hover:text-gray-700 sm:pb-0">
            {{ $t('nav.about') }}
        </Link>
    </div>
</template>
