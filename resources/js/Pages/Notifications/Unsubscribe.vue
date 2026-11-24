<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const page = usePage();

const props = defineProps({
    done: { type: Boolean, default: false },
    // Localized category name, or null for "all notification emails".
    category: { type: String, default: null },
    postUrl: { type: String, required: true },
});

function confirm() {
    router.post(props.postUrl);
}
</script>

<template>
    <Head :title="t('notifications.unsubscribe_title')" />

    <GuestLayout>
        <div class="space-y-4 py-2 text-sm text-gray-700">
            <template v-if="done">
                <h1 class="text-lg font-semibold text-gray-900">{{ t('notifications.unsubscribed_title') }}</h1>
                <p>
                    {{ category
                        ? t('notifications.unsubscribed_body', { category })
                        : t('notifications.unsubscribed_all_body') }}
                </p>
            </template>
            <template v-else>
                <h1 class="text-lg font-semibold text-gray-900">{{ t('notifications.unsubscribe_title') }}</h1>
                <p>
                    {{ category
                        ? t('notifications.unsubscribe_confirm', { category })
                        : t('notifications.unsubscribe_all_confirm') }}
                </p>
                <button type="button" class="rounded-md bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-500" @click="confirm">
                    {{ t('notifications.unsubscribe_button') }}
                </button>
            </template>

            <p>
                <Link :href="page.props.auth?.user ? route('profile.edit') + '#notifications' : route('login')" class="text-indigo-600 hover:underline">
                    {{ t('notifications.preferences') }}
                </Link>
            </p>
        </div>
    </GuestLayout>
</template>
