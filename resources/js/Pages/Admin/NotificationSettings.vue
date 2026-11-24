<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    settings: { type: Object, required: true },
    mailer: { type: String, default: null },
    mailFrom: { type: String, default: null },
    stats: { type: Object, default: () => ({}) },
});

const form = useForm({ ...props.settings });

function save() {
    form.patch(route('admin.notifications.update'), { preserveScroll: true });
}

function sendTest() {
    router.post(route('admin.notifications.test'), {}, { preserveScroll: true });
}

const mailerIsLog = ['log', 'array'].includes(props.mailer);
</script>

<template>
    <Head :title="t('nav.notifications')" />

    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">{{ t('nav.notifications') }}</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-8">
            <form class="space-y-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm" @submit.prevent="save">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{{ t('admin_notifications.email') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ t('admin_notifications.email_hint') }}</p>
                </div>

                <div v-if="mailerIsLog" class="rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                    {{ t('admin_notifications.mailer_log', { mailer }) }}
                </div>
                <p v-else class="text-xs text-gray-500">
                    {{ t('admin_notifications.mailer_info', { mailer, from: mailFrom }) }}
                </p>

                <label class="flex items-start gap-3">
                    <input v-model="form.email_enabled" type="checkbox"
                        class="mt-0.5 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                    <span>
                        <span class="block text-sm font-medium text-gray-900">{{ t('admin_notifications.email_enabled') }}</span>
                        <span class="block text-xs text-gray-500">{{ t('admin_notifications.email_enabled_hint') }}</span>
                    </span>
                </label>

                <label class="block">
                    <span class="block text-sm font-medium text-gray-900">{{ t('admin_notifications.digest_time') }}</span>
                    <input v-model="form.digest_time" type="time" class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <span class="block text-xs text-gray-500">{{ t('admin_notifications.digest_time_hint') }}</span>
                    <span v-if="form.errors.digest_time" class="block text-xs text-red-600">{{ form.errors.digest_time }}</span>
                </label>

                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{{ t('admin_notifications.retention') }}</h2>
                </div>

                <label class="block">
                    <span class="block text-sm font-medium text-gray-900">{{ t('admin_notifications.retention_days') }}</span>
                    <input v-model.number="form.retention_days" type="number" min="1" max="3650" class="mt-1 w-32 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <span class="block text-xs text-gray-500">{{ t('admin_notifications.retention_days_hint') }}</span>
                    <span v-if="form.errors.retention_days" class="block text-xs text-red-600">{{ form.errors.retention_days }}</span>
                </label>

                <label class="block">
                    <span class="block text-sm font-medium text-gray-900">{{ t('admin_notifications.inbox_max_days') }}</span>
                    <input v-model.number="form.inbox_max_days" type="number" min="0" max="3650" class="mt-1 w-32 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <span class="block text-xs text-gray-500">{{ t('admin_notifications.inbox_max_days_hint') }}</span>
                    <span v-if="form.errors.inbox_max_days" class="block text-xs text-red-600">{{ form.errors.inbox_max_days }}</span>
                </label>

                <div class="flex items-center gap-4">
                    <button type="submit" :disabled="form.processing"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                        {{ t('profile.save') }}
                    </button>
                    <button type="button" class="text-sm text-indigo-600 hover:underline" @click="sendTest">
                        {{ t('admin_notifications.send_test') }}
                    </button>
                </div>
            </form>

            <div class="rounded-xl border border-gray-200 bg-white p-6 text-sm text-gray-700 shadow-sm">
                <h2 class="mb-2 text-lg font-semibold text-gray-900">{{ t('admin_notifications.stats') }}</h2>
                <ul class="space-y-1">
                    <li>{{ t('admin_notifications.stat_inbox', { n: stats.inbox }) }}</li>
                    <li>{{ t('admin_notifications.stat_archived', { n: stats.archived }) }}</li>
                    <li>{{ t('admin_notifications.stat_pending', { n: stats.pending_digest }) }}</li>
                </ul>
            </div>
        </div>
    </AdminLayout>
</template>
