<script setup>
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import UserMenu from '@/Components/UserMenu.vue';
import Dropdown from '@/Components/Dropdown.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { useEntitySelection } from '@/Composables/useEntitySelection.js';

const page = usePage();
const auth = computed(() => page.props.auth);
const flash = computed(() => page.props.flash);
const showingNavigationDropdown = ref(false);

const userEntities = computed(() => page.props.auth?.user_entities || []);
const hasEntities = computed(() => userEntities.value.length > 0);

const { currentEntityId, currentEntity, selectEntity } = useEntitySelection(userEntities);
</script>

<template>
    <div class="min-h-screen bg-slate-50">
        <!-- Nav -->
        <nav class="bg-indigo-700 shadow-lg">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <Link :href="route('slides.index')" class="flex items-center gap-2">
                        <svg class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        <span class="text-lg font-bold text-white">{{ page.props.appName }}</span>
                    </Link>

                    <div class="hidden nav:flex nav:items-center nav:gap-6">
                        <Link :href="route('slides.index', currentEntityId ? { entity_id: currentEntityId } : {})"
                            class="text-sm text-indigo-200 hover:text-white transition-colors"
                            :class="{ 'text-white font-semibold': route().current('slides.index') }">
                            Announcements
                        </Link>

                        <template v-if="auth?.user?.role === 'viewer' || auth?.user?.role === 'contributor'">
                            <Link :href="route('my-slides.index')"
                                class="text-sm text-indigo-200 hover:text-white transition-colors"
                                :class="{ 'text-white font-semibold': route().current('my-slides.*') }">
                                {{ $t('nav.my_slides') }}
                            </Link>
                        </template>

                        <!-- Show Editor link (no meaning in Global View) -->
                        <Link v-if="auth?.user && hasEntities && currentEntityId" :href="route('shows.index', { entity_id: currentEntityId })"
                            class="text-sm text-indigo-200 hover:text-white transition-colors"
                            :class="{ 'text-white font-semibold': route().current('shows.*') }">
                            Show Editor
                        </Link>

                        <!-- SlideAnnouncers link (no meaning in Global View) -->
                        <Link v-if="auth?.user && hasEntities && currentEntityId" :href="route('slide-announcers.index', { entity_id: currentEntityId })"
                            class="text-sm text-indigo-200 hover:text-white transition-colors"
                            :class="{ 'text-white font-semibold': route().current('slide-announcers.*') }">
                            SlideAnnouncers
                        </Link>

                        <!-- Entity switcher: click to see available entities; shows the active one when closed -->
                        <Dropdown v-if="auth?.user && hasEntities" align="right" width="48" contentClasses="py-1 bg-white">
                            <template #trigger>
                                <button class="flex items-center gap-1 text-sm text-indigo-200 hover:text-white transition-colors">
                                    {{ currentEntity?.name ?? 'Global View' }}
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>
                            </template>
                            <template #content>
                                <button
                                    @click="selectEntity(null)"
                                    class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 transition-colors"
                                    :class="{ 'bg-indigo-50 font-semibold': !currentEntityId }"
                                >
                                    Global View
                                </button>
                                <button
                                    v-for="entity in userEntities"
                                    :key="entity.id"
                                    @click="selectEntity(entity.id)"
                                    class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 transition-colors"
                                    :class="{ 'bg-indigo-50 font-semibold': entity.id === currentEntityId }"
                                >
                                    {{ entity.name }}
                                </button>
                            </template>
                        </Dropdown>

                        <template v-if="auth?.user">
                            <Link v-if="auth.user.role === 'admin'" :href="route('admin.dashboard')"
                                class="rounded-md bg-indigo-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-400 transition-colors">
                                Admin
                            </Link>
                            <UserMenu :user="auth.user" />
                        </template>
                        <template v-else>
                            <Link :href="route('login')"
                                class="text-sm text-indigo-200 hover:text-white transition-colors">
                                Log In
                            </Link>
                        </template>
                    </div>

                    <!-- Mobile: user menu + hamburger (guests just get the Log In link) -->
                    <div class="flex items-center gap-2 nav:hidden">
                        <template v-if="auth?.user">
                            <UserMenu :user="auth.user" />
                            <button
                                @click="showingNavigationDropdown = !showingNavigationDropdown"
                                class="inline-flex items-center justify-center rounded-md p-2 text-indigo-200 transition duration-150 ease-in-out hover:bg-indigo-600 hover:text-white focus:bg-indigo-600 focus:text-white focus:outline-none"
                            >
                                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path
                                        :class="{ hidden: showingNavigationDropdown, 'inline-flex': !showingNavigationDropdown }"
                                        stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                                    <path
                                        :class="{ hidden: !showingNavigationDropdown, 'inline-flex': showingNavigationDropdown }"
                                        stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </template>
                        <Link v-else :href="route('login')" class="text-sm text-indigo-200 hover:text-white transition-colors">
                            Log In
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Responsive Navigation Menu -->
            <div
                v-if="auth?.user"
                :class="{ block: showingNavigationDropdown, hidden: !showingNavigationDropdown }"
                class="nav:hidden bg-indigo-600"
            >
                <div class="space-y-1 pb-3 pt-2">
                    <ResponsiveNavLink
                        :href="route('slides.index', currentEntityId ? { entity_id: currentEntityId } : {})"
                        :active="route().current('slides.index')"
                    >
                        Announcements
                    </ResponsiveNavLink>
                    <ResponsiveNavLink
                        v-if="auth.user.role === 'viewer' || auth.user.role === 'contributor'"
                        :href="route('my-slides.index')"
                        :active="route().current('my-slides.*')"
                    >
                        {{ $t('nav.my_slides') }}
                    </ResponsiveNavLink>
                    <template v-if="hasEntities">
                        <ResponsiveNavLink
                            v-if="currentEntityId"
                            :href="route('shows.index', { entity_id: currentEntityId })"
                            :active="route().current('shows.*')"
                        >
                            Show Editor
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            v-if="currentEntityId"
                            :href="route('slide-announcers.index', { entity_id: currentEntityId })"
                            :active="route().current('slide-announcers.*')"
                        >
                            SlideAnnouncers
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            @click.prevent="selectEntity(null)"
                            href="#"
                            :active="!currentEntityId"
                        >
                            Global View
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            v-for="entity in userEntities"
                            :key="entity.id"
                            @click.prevent="selectEntity(entity.id)"
                            href="#"
                            :active="currentEntityId === entity.id"
                        >
                            {{ entity.name }}
                        </ResponsiveNavLink>
                    </template>
                    <ResponsiveNavLink
                        v-if="auth.user.role === 'admin'"
                        :href="route('admin.dashboard')"
                        :active="route().current('admin.*')"
                    >
                        Admin
                    </ResponsiveNavLink>
                </div>
            </div>
        </nav>

        <!-- Flash -->
        <div v-if="flash?.success" class="bg-green-50 border-b border-green-200 px-4 py-3 text-center text-sm text-green-700">
            {{ flash.success }}
        </div>
        <div v-if="flash?.error" class="bg-red-50 border-b border-red-200 px-4 py-3 text-center text-sm text-red-700">
            {{ flash.error }}
        </div>

        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <slot />
        </main>

        <footer class="mt-16 border-t border-slate-200 bg-white py-8 text-center text-sm text-slate-400">
            {{ page.props.appName }} &mdash; Powered by <a href="https://github.com/fiforms/announcementslides" class="text-indigo-600 hover:text-indigo-800" target="_blank">AnnouncementSlides</a>
        </footer>
    </div>
</template>
