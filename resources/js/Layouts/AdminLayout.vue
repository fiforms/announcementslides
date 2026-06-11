<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const flash = computed(() => page.props.flash);
const user  = computed(() => page.props.auth?.user);
</script>

<template>
    <div class="min-h-screen bg-gray-100">
        <!-- Sidebar nav -->
        <div class="flex">
            <aside class="fixed inset-y-0 left-0 z-50 w-56 bg-gray-900 flex flex-col">
                <div class="flex h-16 items-center px-6">
                    <Link :href="route('slides.index')" class="flex items-center gap-2">
                        <svg class="h-6 w-6 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        <span class="text-sm font-bold text-white">{{ page.props.appName }}</span>
                    </Link>
                </div>

                <nav class="flex-1 space-y-1 px-3 py-4">
                    <Link :href="route('admin.dashboard')"
                        class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="route().current('admin.dashboard') ? 'bg-indigo-600 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white'">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                        </svg>
                        Dashboard
                    </Link>
                    <Link :href="route('admin.slides.index')"
                        class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="route().current('admin.slides.*') ? 'bg-indigo-600 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white'">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        Slides
                    </Link>
                    <Link :href="route('admin.users.index')"
                        class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="route().current('admin.users.*') ? 'bg-indigo-600 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white'">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                        Users
                    </Link>
                    <Link :href="route('slides.index')"
                        class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-gray-300 hover:bg-gray-800 hover:text-white transition-colors">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                        View Public Site
                    </Link>
                </nav>

                <div class="border-t border-gray-700 px-4 py-4">
                    <div class="text-xs text-gray-400 truncate mb-2">{{ user?.name }}</div>
                    <Link :href="route('logout')" method="post" as="button"
                        class="text-xs text-gray-400 hover:text-white transition-colors">
                        Log Out
                    </Link>
                </div>
            </aside>

            <!-- Main content -->
            <div class="ml-56 flex-1 flex flex-col min-h-screen">
                <header class="bg-white shadow-sm h-16 flex items-center px-8">
                    <slot name="header">
                        <h1 class="text-xl font-semibold text-gray-900">Admin</h1>
                    </slot>
                </header>

                <!-- Flash -->
                <div v-if="flash?.success" class="bg-green-50 border-b border-green-200 px-8 py-3 text-sm text-green-700">
                    {{ flash.success }}
                </div>
                <div v-if="flash?.error" class="bg-red-50 border-b border-red-200 px-8 py-3 text-sm text-red-700">
                    {{ flash.error }}
                </div>

                <main class="flex-1 p-8">
                    <slot />
                </main>
            </div>
        </div>
    </div>
</template>
