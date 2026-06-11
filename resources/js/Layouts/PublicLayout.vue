<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import UserMenu from '@/Components/UserMenu.vue';

const page = usePage();
const auth = computed(() => page.props.auth);
const flash = computed(() => page.props.flash);
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

                    <div class="flex items-center gap-6">
                        <Link :href="route('slides.index')"
                            class="text-sm text-indigo-200 hover:text-white transition-colors"
                            :class="{ 'text-white font-semibold': route().current('slides.index') }">
                            Current
                        </Link>
                        <Link v-if="auth?.user" :href="route('slides.archive')"
                            class="text-sm text-indigo-200 hover:text-white transition-colors"
                            :class="{ 'text-white font-semibold': route().current('slides.archive') }">
                            Archive
                        </Link>

                        <template v-if="auth?.user">
                            <Link :href="route('my-slides.index')"
                                class="text-sm text-indigo-200 hover:text-white transition-colors"
                                :class="{ 'text-white font-semibold': route().current('my-slides.*') }">
                                {{ $t('nav.my_slides') }}
                            </Link>
                        </template>

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
            Carolinas Conference &mdash; {{ page.props.appName }}
        </footer>
    </div>
</template>
