<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useAppName } from '@/Composables/useAppName.js';

// The About text shared by the public page and Admin > About: the site's logo
// (or name), what it is based on, the licence and where the source lives.
const props = defineProps({
    about: { type: Object, required: true },
});

const page = usePage();
const appName = useAppName();
const logo = computed(() => page.props.brand?.logo);
</script>

<template>
    <div class="mx-auto max-w-2xl text-center">
        <img v-if="logo" :src="logo" :alt="appName" class="mx-auto max-h-64 w-auto max-w-[80vw] sm:max-h-80">
        <h1 v-else class="text-3xl font-bold text-gray-800">{{ appName }}</h1>

        <p class="mt-6 text-lg font-medium text-gray-800">
            {{ about.branded
                ? $t('about.based_on', { name: appName, product: about.product })
                : $t('about.open_source', { product: about.product }) }}
        </p>
        <p v-if="about.version" class="mt-1 text-sm text-gray-500">
            {{ $t('about.version') }} {{ about.version }}
        </p>

        <div class="mt-6 space-y-3 text-left text-sm leading-6 text-gray-600">
            <p>{{ $t('about.what_is_open_source') }}</p>
            <p>{{ $t('about.licence', { product: about.product, licence: about.licence }) }}</p>
            <p>
                {{ $t('about.source') }}
                <a :href="about.repo_url" target="_blank" rel="noopener" class="break-all font-medium text-indigo-600 hover:text-indigo-800">{{ about.repo_url }}</a>
            </p>
            <p>
                {{ $t('about.device') }}
                <a :href="about.device_repo_url" target="_blank" rel="noopener" class="break-all font-medium text-indigo-600 hover:text-indigo-800">{{ about.device_repo_url }}</a>
            </p>
            <p>
                <a href="https://opensource.org/osd" target="_blank" rel="noopener" class="font-medium text-indigo-600 hover:text-indigo-800">{{ $t('about.learn_more') }}</a>
            </p>
            <p class="text-xs text-gray-400">{{ $t('about.no_warranty') }}</p>
        </div>
    </div>
</template>
