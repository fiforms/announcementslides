<script setup>
import { ref } from 'vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import UserMenu from '@/Components/UserMenu.vue';
import { Link } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);
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
                                <Link :href="route('slides.index')" class="text-lg font-bold text-white">
                                    {{ $page.props.appName }}
                                </Link>
                            </div>

                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center sm:gap-6">
                            <!-- My Slides link -->
                            <Link
                                :href="route('my-slides.index')"
                                class="text-sm text-indigo-200 hover:text-white transition-colors"
                                :class="{ 'text-white font-semibold': route().current('my-slides.*') }">
                                {{ $t('nav.my_slides') }}
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
                        <template v-if="$page.props.auth.admin_entities?.length">
                            <ResponsiveNavLink
                                v-for="entity in $page.props.auth.admin_entities"
                                :key="entity.id"
                                :href="route('entity.slides.index', entity.id)"
                                :active="route().current('entity.slides.*')"
                            >
                                {{ entity.name }}
                            </ResponsiveNavLink>
                        </template>
                        <ResponsiveNavLink
                            v-if="$page.props.auth.user?.role === 'viewer' && !$page.props.auth.admin_entities?.length"
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
