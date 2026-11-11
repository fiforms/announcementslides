<script setup>
import { useForm, router, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    entity: { type: Object, required: true },
    people: { type: Array, default: () => [] },
    invitations: { type: Array, default: () => [] },
});

const { t } = useI18n();

const form = useForm({ email: '' });

function add() {
    form.post(route('entity.people.store', { entity: props.entity.id }), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function remove(person) {
    if (confirm(t('people.confirm_remove', { name: person.name }))) {
        router.delete(route('entity.people.destroy', { entity: props.entity.id, user: person.id }), { preserveScroll: true });
    }
}

function cancelInvite(inv) {
    if (confirm(t('people.confirm_cancel_invite', { email: inv.email }))) {
        router.delete(route('entity.people.invitations.destroy', { entity: props.entity.id, invitation: inv.id }), { preserveScroll: true });
    }
}

const badge = {
    admin: 'bg-indigo-100 text-indigo-700',
    editor: 'bg-sky-100 text-sky-700',
    viewer: 'bg-gray-100 text-gray-600',
};
</script>

<template>
    <AuthenticatedLayout>
        <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
            <Link :href="route('entity.slides.index', { entity: entity.id })" class="text-sm text-indigo-600 hover:text-indigo-800">
                &larr; {{ $t('people.back_to_slides') }}
            </Link>
            <div>
                <h1 class="text-xl font-semibold text-gray-900">{{ $t('people.title', { name: entity.name }) }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ $t('people.intro') }}</p>
            </div>

            <form @submit.prevent="add" class="rounded-lg bg-white p-4 shadow sm:p-6 space-y-3">
                <h2 class="text-base font-medium text-gray-900">{{ $t('people.add_title') }}</h2>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <div class="flex-1">
                        <label for="pe-email" class="sr-only">{{ $t('people.email_label') }}</label>
                        <input id="pe-email" v-model="form.email" type="email" required maxlength="255"
                            :placeholder="$t('people.email_placeholder')"
                            class="block w-full rounded-lg border-gray-300 text-sm" />
                        <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
                    </div>
                    <button type="submit" :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                        {{ $t('people.add') }}
                    </button>
                </div>
                <p class="text-xs text-gray-500">{{ $t('people.add_hint') }}</p>
            </form>

            <div class="rounded-lg bg-white shadow overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('people.col_name') }}</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden sm:table-cell">{{ $t('people.col_email') }}</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('people.col_role') }}</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">{{ $t('people.col_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="p in people" :key="p.id">
                            <td class="px-4 py-3 font-medium text-gray-900">
                                {{ p.name }}
                                <div class="text-xs font-normal text-gray-500 sm:hidden">{{ p.email }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-600 hidden sm:table-cell">{{ p.email }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="badge[p.role]">
                                    {{ $t('people.role_' + p.role) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button v-if="p.role === 'editor'" @click="remove(p)" class="text-sm text-red-600 hover:text-red-800">
                                    {{ $t('people.remove') }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!people.length">
                            <td colspan="4" class="px-4 py-6 text-center text-gray-500">{{ $t('people.none') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="invitations.length" class="rounded-lg bg-white shadow overflow-hidden">
                <h2 class="px-4 pt-4 text-base font-medium text-gray-900">{{ $t('people.pending_title') }}</h2>
                <ul class="divide-y divide-gray-100 mt-2">
                    <li v-for="inv in invitations" :key="inv.id" class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                        <div>
                            <span class="text-gray-900">{{ inv.email }}</span>
                            <span class="ml-2 text-xs text-gray-500">{{ $t('people.pending_hint') }}</span>
                        </div>
                        <button @click="cancelInvite(inv)" class="text-sm text-red-600 hover:text-red-800">
                            {{ $t('people.cancel_invite') }}
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
