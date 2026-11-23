<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import HelpLanguages from '@/Components/HelpLanguages.vue';

const props = defineProps({
    topic: { type: Object, required: true },
    topics: { type: Array, required: true },
    locale: { type: String, required: true },
    langQuery: { type: [Object, Array], default: () => ({}) },
    languages: { type: Array, default: () => [] },
});

const { t } = useI18n();

// Links between topics are plain anchors in the rendered HTML; route them
// through Inertia so moving between topics doesn't reload the page.
function onBodyClick(event) {
    const link = event.target.closest('a');
    if (!link || link.target === '_blank' || event.metaKey || event.ctrlKey || event.shiftKey) return;
    const url = new URL(link.href, window.location.href);
    if (url.origin === window.location.origin && url.pathname.startsWith('/help/')) {
        event.preventDefault();
        router.visit(url.pathname + url.search + url.hash);
    }
}
</script>

<template>
    <Head :title="topic.title + ' · ' + t('help.title')" />
    <PublicLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <Link :href="route('help.index', langQuery)" class="text-sm text-indigo-600 hover:text-indigo-800">&larr; {{ $t('help.all_topics') }}</Link>
            <HelpLanguages :languages="languages" :locale="locale" route-name="help.show" :route-params="topic.slug" />
        </div>

        <div class="grid gap-8 lg:grid-cols-[16rem_1fr]">
            <nav class="hidden lg:block" :aria-label="$t('help.title')">
                <ul class="space-y-1 text-sm">
                    <li v-for="item in topics" :key="item.slug">
                        <Link :href="route('help.show', { topic: item.slug, ...langQuery })"
                            class="block rounded px-3 py-1.5 hover:bg-slate-100"
                            :class="item.slug === topic.slug ? 'bg-indigo-50 font-semibold text-indigo-700' : 'text-slate-700'">
                            {{ item.title }}
                        </Link>
                    </li>
                </ul>
            </nav>

            <article class="min-w-0 rounded-lg border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <h1 class="mb-4 text-3xl font-bold text-slate-900">{{ topic.title }}</h1>
                <p v-if="topic.fallback" class="mb-6 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    {{ $t('help.not_translated') }}
                </p>
                <div class="help-body" @click="onBodyClick" v-html="topic.html" />
            </article>
        </div>
    </PublicLayout>
</template>

<style>
.help-body { color: rgb(51 65 85); line-height: 1.7; overflow-wrap: anywhere; }
.help-body > * + * { margin-top: 1rem; }
.help-body h2 { margin-top: 2rem; font-size: 1.4rem; font-weight: 700; color: rgb(15 23 42); }
.help-body h3 { margin-top: 1.5rem; font-size: 1.15rem; font-weight: 600; color: rgb(15 23 42); }
.help-body a { color: rgb(79 70 229); text-decoration: underline; }
.help-body ul { list-style: disc; padding-left: 1.5rem; }
.help-body ol { list-style: decimal; padding-left: 1.5rem; }
.help-body li + li { margin-top: 0.35rem; }
.help-body code { background: rgb(241 245 249); border-radius: 0.25rem; padding: 0.1rem 0.35rem; font-size: 0.9em; }
.help-body pre { background: rgb(15 23 42); color: rgb(226 232 240); border-radius: 0.5rem; padding: 1rem; overflow-x: auto; }
.help-body pre code { background: none; padding: 0; }
.help-body blockquote { border-left: 4px solid rgb(165 180 252); background: rgb(238 242 255); padding: 0.5rem 1rem; border-radius: 0 0.5rem 0.5rem 0; }
.help-body table { width: 100%; border-collapse: collapse; font-size: 0.95em; }
.help-body th, .help-body td { border: 1px solid rgb(226 232 240); padding: 0.4rem 0.7rem; text-align: left; }
.help-body th { background: rgb(248 250 252); }
.help-body img, .help-body video { max-width: 100%; height: auto; border-radius: 0.5rem; border: 1px solid rgb(226 232 240); }
.help-body .help-media-missing { display: inline-block; padding: 0.25rem 0.5rem; background: rgb(254 242 242); color: rgb(153 27 27); border-radius: 0.25rem; font-size: 0.85em; }
</style>
