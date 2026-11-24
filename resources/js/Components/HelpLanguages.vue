<script setup>
// Language switcher for the help pages; only listed when more than one language has content.
import { Link } from '@inertiajs/vue3';

defineProps({
    languages: { type: Array, required: true },
    locale: { type: String, required: true },
    // Route name and params of the current help page, so the switcher stays on it.
    routeName: { type: String, required: true },
    routeParams: { type: [String, Object], default: undefined },
});
</script>

<template>
    <div v-if="languages.length > 1" class="flex items-center gap-2 text-sm">
        <template v-for="(code, i) in languages" :key="code">
            <span v-if="i" class="text-slate-300">|</span>
            <span v-if="code === locale" class="font-semibold text-slate-700">{{ $t('help.language_name.' + code) }}</span>
            <Link v-else :href="route(routeName, { ...(typeof routeParams === 'string' ? { topic: routeParams } : routeParams), lang: code })"
                class="text-indigo-600 hover:text-indigo-800">{{ $t('help.language_name.' + code) }}</Link>
        </template>
    </div>
</template>
