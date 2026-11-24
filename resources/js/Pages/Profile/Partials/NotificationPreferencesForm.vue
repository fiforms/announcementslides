<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useNotificationText } from '@/Composables/useNotificationText.js';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    // { category: { inbox: bool, email: 'none'|'immediate'|'daily' } }
    preferences: { type: Object, default: () => ({}) },
});

const { categoryLabel } = useNotificationText();

const form = useForm({
    preferences: JSON.parse(JSON.stringify(props.preferences)),
});

const save = () => {
    form.patch(route('profile.notifications.update'), { preserveScroll: true });
};
</script>

<template>
    <section id="notifications">
        <header>
            <h2 class="text-lg font-medium text-gray-900">{{ $t('notifications.preferences') }}</h2>
            <p class="mt-1 text-sm text-gray-600">{{ $t('notifications.preferences_description') }}</p>
        </header>

        <form @submit.prevent="save" class="mt-6 space-y-6">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ $t('notifications.category') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ $t('notifications.in_app') }}</th>
                            <th class="py-2 font-medium">{{ $t('notifications.email') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="(pref, category) in form.preferences" :key="category">
                            <td class="py-3 pr-4">
                                <div class="font-medium text-gray-900">{{ categoryLabel(category) }}</div>
                                <div class="text-xs text-gray-500">{{ $t(`notifications.categories.${category}.description`) }}</div>
                            </td>
                            <td class="py-3 pr-4">
                                <input v-model="pref.inbox" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            </td>
                            <td class="py-3">
                                <select v-model="pref.email" class="rounded-md border-gray-300 py-1 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="none">{{ $t('notifications.email_none') }}</option>
                                    <option value="daily">{{ $t('notifications.email_daily') }}</option>
                                    <option value="immediate">{{ $t('notifications.email_immediate') }}</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">{{ $t('profile.save') }}</PrimaryButton>
                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-gray-600">{{ $t('profile.saved') }}</p>
                </Transition>
            </div>
        </form>
    </section>
</template>
