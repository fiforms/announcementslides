<script setup>
import { ref, computed } from 'vue';
import { usePage, Link, router } from '@inertiajs/vue3';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import UserMenu from '@/Components/UserMenu.vue';

const page = usePage();
const showingNavigationDropdown = ref(false);
const showingEntityDropdown = ref(false);

const userEntities = computed(() => page.props.auth.user_entities || []);
const hasEntities = computed(() => userEntities.value.length > 0);

const currentEntityId = computed(() => {
    const param = new URLSearchParams(window.location.search).get('entity_id');
    return param ? parseInt(param) : (userEntities.value[0]?.id || null);
});

const currentEntity = computed(() => {
    return userEntities.value.find(e => e.id === currentEntityId.value);
});

function switchEntity(entityId) {
    const currentUrl = window.location.pathname + window.location.search;
    const url = new URL(window.location);
    url.searchParams.set('entity_id', entityId);
    router.visit(url.pathname + url.search, { preserveScroll: true });
}
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100">
            <nav
                class="bg-indigo-700 shadow-lg"
            >
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <!-- App Name -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('slides.index')" class="flex items-center gap-2">
                                    <svg class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                    <span class="text-lg font-bold text-white">{{ $page.props.appName }}</span>
                                </Link>
                            </div>

                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center sm:gap-6">
                            <!-- Current link -->
                            <Link :href="route('slides.index', currentEntityId ? { entity_id: currentEntityId } : {})"
                                class="text-sm text-indigo-200 hover:text-white transition-colors"
                                :class="{ 'text-white font-semibold': route().current('slides.index') }">
                                Current
                            </Link>
                            <!-- Archive link -->
                            <Link :href="route('slides.archive', currentEntityId ? { entity_id: currentEntityId } : {})"
                                class="text-sm text-indigo-200 hover:text-white transition-colors"
                                :class="{ 'text-white font-semibold': route().current('slides.archive') }">
                                Archive
                            </Link>
                            <!-- My Slides link (only for viewers) -->
                            <Link
                                v-if="$page.props.auth.user?.role === 'viewer'"
                                :href="route('my-slides.index')"
                                class="text-sm text-indigo-200 hover:text-white transition-colors"
                                :class="{ 'text-white font-semibold': route().current('my-slides.*') }">
                                {{ $t('nav.my_slides') }}
                            </Link>

                            <!-- Entity selector and Local Slides -->
                            <template v-if="hasEntities">
                                <div class="relative group">
                                    <button
                                        class="flex items-center gap-1 text-sm text-indigo-200 hover:text-white transition-colors"
                                        :class="{ 'text-white font-semibold': route().current('local-slides.*') }">
                                        <span v-if="currentEntityId">
                                            <Link :href="route('local-slides.index', { entity_id: currentEntityId })" class="hover:text-white">
                                                Local Slides
                                            </Link>
                                        </span>
                                        <span v-else>Local Slides</span>
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                                        </svg>
                                    </button>

                                    <!-- Entity dropdown menu -->
                                    <div
                                        class="absolute right-0 mt-0 w-48 rounded-lg bg-white shadow-lg py-2 z-50 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all"
                                    >
                                        <button
                                            v-for="entity in userEntities"
                                            :key="entity.id"
                                            @click="switchEntity(entity.id)"
                                            class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 transition-colors"
                                            :class="{ 'bg-indigo-50 font-semibold': entity.id === currentEntityId }"
                                        >
                                            {{ entity.name }}
                                        </button>
                                    </div>
                                </div>
                            </template>

                            <!-- Admin button -->
                            <Link v-if="$page.props.auth.user?.role === 'admin'" :href="route('admin.dashboard')"
                                class="rounded-md bg-indigo-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-400 transition-colors">
                                Admin
                            </Link>
                            <UserMenu :user="$page.props.auth.user" />
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button
                                @click="
                                    showingNavigationDropdown =
                                        !showingNavigationDropdown
                                "
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                            >
                                <svg
                                    class="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex':
                                                !showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{
                                            hidden: !showingNavigationDropdown,
                                            'inline-flex':
                                                showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{
                        block: showingNavigationDropdown,
                        hidden: !showingNavigationDropdown,
                    }"
                    class="sm:hidden bg-indigo-600"
                >
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            :href="route('my-slides.index')"
                            :active="route().current('my-slides.*')"
                        >
                            {{ $t('nav.my_slides') }}
                        </ResponsiveNavLink>
                        <template v-if="hasEntities">
                            <ResponsiveNavLink
                                v-for="entity in userEntities"
                                :key="entity.id"
                                :href="route('local-slides.index', { entity_id: entity.id })"
                                :active="route().current('local-slides.*') && currentEntityId === entity.id"
                            >
                                {{ entity.name }}
                            </ResponsiveNavLink>
                        </template>
                        <ResponsiveNavLink
                            v-if="$page.props.auth.user?.role === 'viewer' && !hasEntities"
                            :href="route('slides.submit')"
                            :active="route().current('slides.submit')"
                        >
                            {{ $t('nav.submit_slide') }}
                        </ResponsiveNavLink>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div
                        class="border-t border-indigo-500 pb-1 pt-4"
                    >
                        <div class="px-4">
                            <div
                                class="text-base font-medium text-white"
                            >
                                {{ $page.props.auth.user.name }}
                            </div>
                            <div class="text-sm font-medium text-indigo-200">
                                {{ $page.props.auth.user.email }}
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.edit')">
                                {{ $t('nav.profile') }}
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('logout')"
                                method="post"
                                as="button"
                            >
                                {{ $t('nav.log_out') }}
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
