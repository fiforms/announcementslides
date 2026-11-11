<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    tokenRequired: { type: Boolean, default: false },
    // Bundled widget folders present in this install (clock, calendar, ...).
    widgets: { type: Array, default: () => [] },
});

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    token: '',
    install_widgets: props.widgets.length > 0,
});

const submit = () => {
    form.post(route('setup.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head :title="t('setup.title')" />

        <h1 class="text-lg font-semibold text-gray-900">{{ $t('setup.heading') }}</h1>
        <p class="mt-1 text-sm text-gray-600">{{ $t('setup.intro') }}</p>

        <form class="mt-4" @submit.prevent="submit">
            <div v-if="tokenRequired">
                <InputLabel for="token" :value="$t('setup.token')" />
                <TextInput
                    id="token"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.token"
                    required
                    autofocus
                    autocomplete="off"
                />
                <p class="mt-1 text-xs text-gray-500">{{ $t('setup.token_help') }}</p>
                <InputError class="mt-2" :message="form.errors.token" />
            </div>

            <div :class="tokenRequired ? 'mt-4' : ''">
                <InputLabel for="name" :value="$t('auth.name')" />
                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    :autofocus="!tokenRequired"
                    autocomplete="name"
                />
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div class="mt-4">
                <InputLabel for="email" :value="$t('auth.email')" />
                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autocomplete="username"
                />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" :value="$t('auth.password')" />
                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4">
                <InputLabel for="password_confirmation" :value="$t('auth.confirm_password')" />
                <TextInput
                    id="password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                />
                <InputError class="mt-2" :message="form.errors.password_confirmation" />
            </div>

            <div v-if="widgets.length" class="mt-4">
                <label class="flex items-start">
                    <Checkbox name="install_widgets" v-model:checked="form.install_widgets" class="mt-0.5" />
                    <span class="ms-2 text-sm text-gray-700">
                        {{ $t('setup.install_widgets', { widgets: widgets.join(', ') }) }}
                    </span>
                </label>
                <InputError class="mt-2" :message="form.errors.install_widgets" />
            </div>

            <div class="mt-6 flex items-center justify-end">
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    {{ $t('setup.create') }}
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
