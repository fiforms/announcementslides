<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AccountAuthMethod from './Partials/AccountAuthMethod.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import ManageEntitySubscriptions from './Partials/ManageEntitySubscriptions.vue';
import NearbyRadiusForm from './Partials/NearbyRadiusForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    authMethod: {
        type: String,
        required: true,
    },
    subscriptions: {
        type: Array,
        default: () => [],
    },
    nearbyRadiusMiles: {
        type: Number,
        default: 50,
    },
});
</script>

<template>
    <Head :title="t('profile.title')" />

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <AccountAuthMethod :auth-method="authMethod" class="max-w-xl" />
                </div>

                <div class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <ManageEntitySubscriptions
                        :subscriptions="subscriptions"
                        class="max-w-xl"
                    />
                </div>

                <div class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <NearbyRadiusForm
                        :nearby-radius-miles="nearbyRadiusMiles"
                        class="max-w-xl"
                    />
                </div>

                <div class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
