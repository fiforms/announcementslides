<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import HelpLanguages from '@/Components/HelpLanguages.vue';

const props = defineProps({
    topics: { type: Array, required: true },
    locale: { type: String, required: true },
    langQuery: { type: [Object, Array], default: () => ({}) },
    languages: { type: Array, default: () => [] },
});

const { t } = useI18n();

// Group topics by their `group` front-matter, keeping their order.
const groups = computed(() => {
    const map = new Map();
    for (const topic of props.topics) {
        const key = topic.group || '';
        if (!map.has(key)) map.set(key, []);
        map.get(key).push(topic);
    }
    return [...map.entries()].map(([name, topics]) => ({ name, topics }));
});
</script>

<template>
    <Head :title="t('help.title')" />
    <PublicLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold text-slate-900">{{ $t('help.title') }}</h1>
            <HelpLanguages :languages="languages" :locale="locale" route-name="help.index" :route-params="{}" />
        </div>

        <p v-if="!topics.length" class="text-slate-500">{{ $t('help.empty') }}</p>

        <section v-for="group in groups" :key="group.name" class="mb-8">
            <h2 v-if="group.name" class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">{{ group.name }}</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link v-for="topic in group.topics" :key="topic.slug"
                    :href="route('help.show', { topic: topic.slug, ...langQuery })"
                    class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-indigo-400 hover:shadow">
                    <div class="font-semibold text-slate-900">{{ topic.title }}</div>
                    <p v-if="topic.summary" class="mt-1 text-sm text-slate-600">{{ topic.summary }}</p>
                    <p v-if="topic.fallback" class="mt-2 text-xs text-amber-700">{{ $t('help.not_translated_short') }}</p>
                </Link>
            </div>
        </section>
    </PublicLayout>
</template>
