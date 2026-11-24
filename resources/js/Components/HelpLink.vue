<script setup>
// A small "?" that opens a help topic: <HelpLink topic="submitting-a-slide" />.
// `anchor` jumps to a heading inside the topic (the heading's slug).
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    topic: { type: String, required: true },
    anchor: { type: String, default: '' },
});

const href = computed(() => route('help.show', props.topic) + (props.anchor ? '#' + props.anchor : ''));
</script>

<template>
    <Link
        :href="href"
        class="inline-flex h-5 w-5 items-center justify-center rounded-full border border-slate-300 text-xs font-semibold text-slate-500 hover:border-indigo-500 hover:text-indigo-600"
        :title="$t('help.link_title')"
        :aria-label="$t('help.link_title')"
    >?</Link>
</template>
