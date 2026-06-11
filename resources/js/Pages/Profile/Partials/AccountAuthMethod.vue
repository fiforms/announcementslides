<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    authMethod: {
        type: String, // 'google' | 'password'
        required: true,
    },
});
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">
                {{ $t('profile.sign_in_method') }}
            </h2>
            <p class="mt-1 text-sm text-gray-600">
                {{ $t('profile.sign_in_method_description') }}
            </p>
        </header>

        <div class="mt-6 flex items-center gap-3">
            <!-- Google badge -->
            <template v-if="authMethod === 'google'">
                <span class="inline-flex items-center gap-2 rounded-full bg-blue-50 border border-blue-200 px-4 py-2 text-sm font-medium text-blue-800">
                    <!-- Google "G" SVG -->
                    <svg class="h-4 w-4" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/>
                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                    </svg>
                    {{ $t('profile.signed_in_with_google') }}
                </span>
            </template>

            <!-- Password badge -->
            <template v-else>
                <span class="inline-flex items-center gap-2 rounded-full bg-gray-100 border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700">
                    <svg class="h-4 w-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    {{ $t('profile.signed_in_with_password') }}
                </span>
            </template>
        </div>
    </section>
</template>
