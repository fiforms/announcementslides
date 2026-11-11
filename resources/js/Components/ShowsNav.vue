<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';

// The entity-scoped management links (Show Editor, Announcer Devices, Shared
// Links), shared by AuthenticatedLayout and PublicLayout. Render it only when
// an entity is selected: none of these have meaning in Global View.
const props = defineProps({
    entityId: { type: Number, required: true },
    // Flat links for the hamburger menu instead of the desktop dropdown.
    responsive: { type: Boolean, default: false },
});

const page = usePage();

// Leaders (and site admins) also get the Team page; editors don't.
const canManageTeam = computed(() => {
    const auth = page.props.auth;
    return auth?.user?.role === 'admin' || (auth?.led_entity_ids ?? []).includes(props.entityId);
});

const items = computed(() => [
    { label: 'nav.show_editor', href: route('shows.index', { entity_id: props.entityId }), active: route().current('shows.*') },
    { label: 'nav.slide_announcers', href: route('slide-announcers.index', { entity_id: props.entityId }), active: route().current('slide-announcers.*') },
    { label: 'nav.play_links', href: route('play-links.index', { entity_id: props.entityId }), active: route().current('play-links.*') },
    ...(canManageTeam.value
        ? [{ label: 'nav.team', href: route('entity.people.index', { entity: props.entityId }), active: route().current('entity.people.*') }]
        : []),
]);

const anyActive = computed(() => items.value.some(i => i.active));
</script>

<template>
    <template v-if="responsive">
        <ResponsiveNavLink v-for="item in items" :key="item.label" :href="item.href" :active="item.active">
            {{ $t(item.label) }}
        </ResponsiveNavLink>
    </template>

    <Dropdown v-else align="right" width="48" contentClasses="py-1 bg-white">
        <template #trigger>
            <button class="flex items-center gap-1 text-sm text-indigo-200 hover:text-white transition-colors"
                :class="{ 'text-white font-semibold': anyActive }">
                {{ $t('nav.shows') }}
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>
        </template>
        <template #content>
            <DropdownLink v-for="item in items" :key="item.label" :href="item.href"
                :class="{ 'bg-indigo-50 font-semibold': item.active }">
                {{ $t(item.label) }}
            </DropdownLink>
        </template>
    </Dropdown>
</template>
