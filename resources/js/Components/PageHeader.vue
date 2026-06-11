<script setup>
defineProps({
    title: {
        type: String,
        required: true,
    },
    backLink: {
        type: String,
        default: null,
    },
});
</script>

<template>
    <div class="flex items-center justify-between w-full gap-4">
        <div class="flex items-center gap-3">
            <slot name="back" />
            <h1 class="text-2xl font-semibold">{{ title }}</h1>
        </div>
        <slot name="actions" />
    </div>
</template>
