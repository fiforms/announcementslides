<script setup>
import { usePage } from '@inertiajs/vue3';

// The site's top-bar mark: the branded emblem (public/brand/mark.png, on a white
// chip so it reads on dark bars) when one is installed, else the stock icon.
// `size` is a Tailwind size class pair, `iconClass` colors the stock icon.
defineProps({
    size: { type: String, default: 'h-7 w-7' },
    iconClass: { type: String, default: 'text-white' },
});

const page = usePage();
</script>

<template>
    <span v-if="page.props.brand?.mark" class="inline-flex shrink-0 rounded-md bg-white p-0.5">
        <img :src="page.props.brand.mark" alt="" :class="size" class="object-contain">
    </span>
    <svg v-else :class="[size, iconClass]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
    </svg>
</template>
