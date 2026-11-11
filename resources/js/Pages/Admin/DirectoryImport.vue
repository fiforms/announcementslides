<script setup>
import { Link, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({
    pending: { type: Object, default: null },
    missing: { type: Array, default: () => [] },
});

const entitiesForm = useForm({ file: null, conference: '', sync: true });
const pastorsForm = useForm({ file: null });

function previewEntities() {
    entitiesForm.post(route('admin.directory-import.entities'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => entitiesForm.reset('file'),
    });
}

function previewPastors() {
    pastorsForm.post(route('admin.directory-import.pastors'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => pastorsForm.reset('file'),
    });
}

function confirmImport() {
    router.post(route('admin.directory-import.apply'), {}, { preserveScroll: true });
}

function cancelImport() {
    router.delete(route('admin.directory-import.cancel'), { preserveScroll: true });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">{{ $t('directory_import.page_title') }}</h1>
        </template>

        <div class="max-w-4xl space-y-6">
            <p class="text-sm text-gray-600">{{ $t('directory_import.intro') }}</p>

            <!-- Pending preview -->
            <section v-if="pending" class="rounded-xl border-2 border-indigo-300 bg-white p-5 shadow-sm">
                <h2 class="font-semibold text-gray-900">
                    {{ $t('directory_import.preview_title', { file: pending.filename }) }}
                </h2>

                <template v-if="pending.kind === 'entities'">
                    <dl class="mt-4 grid grid-cols-3 gap-3 text-center text-sm">
                        <div class="rounded-lg bg-green-50 p-3"><dd class="text-2xl font-semibold text-green-700">{{ pending.summary.new }}</dd><dt class="text-gray-600">{{ $t('directory_import.new_count') }}</dt></div>
                        <div class="rounded-lg bg-amber-50 p-3"><dd class="text-2xl font-semibold text-amber-700">{{ pending.summary.changed }}</dd><dt class="text-gray-600">{{ $t('directory_import.changed_count') }}</dt></div>
                        <div class="rounded-lg bg-gray-50 p-3"><dd class="text-2xl font-semibold text-gray-700">{{ pending.summary.unchanged }}</dd><dt class="text-gray-600">{{ $t('directory_import.unchanged_count') }}</dt></div>
                    </dl>
                    <div v-if="pending.summary.samples.length" class="mt-4">
                        <h3 class="text-xs font-medium uppercase text-gray-500">{{ $t('directory_import.changes_sample') }}</h3>
                        <ul class="mt-1 divide-y divide-gray-100 text-sm">
                            <li v-for="(s, i) in pending.summary.samples" :key="i" class="py-1">
                                <span class="font-medium text-gray-900">{{ s.name }}</span>
                                <span class="ml-2 text-xs text-gray-500">{{ $t('directory_import.kind_' + s.kind) }}<template v-if="s.fields.length">: {{ s.fields.join(', ') }}</template></span>
                            </li>
                        </ul>
                    </div>
                    <p v-if="pending.sync" class="mt-3 text-sm text-gray-600">{{ $t('directory_import.will_sync') }}</p>
                </template>

                <template v-else>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-center text-sm sm:grid-cols-5">
                        <div class="rounded-lg bg-green-50 p-3"><dd class="text-2xl font-semibold text-green-700">{{ pending.summary.new_users }}</dd><dt class="text-gray-600">{{ $t('directory_import.new_users') }}</dt></div>
                        <div class="rounded-lg bg-gray-50 p-3"><dd class="text-2xl font-semibold text-gray-700">{{ pending.summary.existing_users }}</dd><dt class="text-gray-600">{{ $t('directory_import.existing_users') }}</dt></div>
                        <div class="rounded-lg bg-indigo-50 p-3"><dd class="text-2xl font-semibold text-indigo-700">{{ pending.summary.new_links }}</dd><dt class="text-gray-600">{{ $t('directory_import.new_links') }}</dt></div>
                        <div class="rounded-lg bg-amber-50 p-3"><dd class="text-2xl font-semibold text-amber-700">{{ pending.summary.upgrades }}</dd><dt class="text-gray-600">{{ $t('directory_import.upgrades') }}</dt></div>
                        <div class="rounded-lg bg-gray-50 p-3"><dd class="text-2xl font-semibold text-gray-700">{{ pending.summary.already }}</dd><dt class="text-gray-600">{{ $t('directory_import.already') }}</dt></div>
                    </dl>

                    <div v-if="pending.summary.unmatched.length" class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                        <p class="font-medium">{{ $t('directory_import.unmatched') }}</p>
                        <p class="mt-1">{{ pending.summary.unmatched.join(', ') }}</p>
                    </div>
                    <div v-if="pending.summary.skipped.length" class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
                        <p class="font-medium">{{ $t('directory_import.skipped') }}</p>
                        <p class="mt-1">{{ pending.summary.skipped.join('; ') }}</p>
                    </div>

                    <div class="mt-4 max-h-72 overflow-y-auto rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium text-gray-500">{{ $t('directory_import.col_pastor') }}</th>
                                    <th class="px-3 py-2 text-left font-medium text-gray-500">{{ $t('directory_import.col_churches') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="p in pending.summary.plan" :key="p.email">
                                    <td class="px-3 py-2">
                                        <span class="font-medium text-gray-900">{{ p.name }}</span>
                                        <span v-if="p.new" class="ml-1 rounded-full bg-green-100 px-1.5 text-xs text-green-700">{{ $t('directory_import.is_new') }}</span>
                                        <div class="text-xs text-gray-500">{{ p.email }}</div>
                                    </td>
                                    <td class="px-3 py-2 text-gray-600">{{ p.churches.join(', ') || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>

                <div class="mt-5 flex gap-3">
                    <button type="button" @click="confirmImport"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        {{ $t('directory_import.confirm_btn') }}
                    </button>
                    <button type="button" @click="cancelImport"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        {{ $t('directory_import.cancel_btn') }}
                    </button>
                </div>
            </section>

            <!-- Upload forms (hidden while a preview is pending) -->
            <template v-else>
                <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="font-semibold text-gray-900">{{ $t('directory_import.churches_title') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ $t('directory_import.churches_help') }}</p>
                    <a :href="route('admin.directory-import.sample', 'entities')"
                        class="mt-2 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-800">
                        {{ $t('directory_import.sample_link') }}
                    </a>
                    <form class="mt-4 space-y-3" @submit.prevent="previewEntities">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('directory_import.file_label') }}</label>
                            <input type="file" accept=".json,.csv,.txt" class="mt-1 block text-sm"
                                @input="entitiesForm.file = $event.target.files[0]" />
                            <p v-if="entitiesForm.errors.file" class="mt-1 text-sm text-red-600">{{ entitiesForm.errors.file }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('directory_import.conference_label') }}</label>
                            <input v-model="entitiesForm.conference" type="text" maxlength="20"
                                class="mt-1 w-40 rounded-md border-gray-300 text-sm" />
                            <p class="mt-1 text-xs text-gray-500">{{ $t('directory_import.conference_help') }}</p>
                            <p v-if="entitiesForm.errors.conference" class="mt-1 text-sm text-red-600">{{ entitiesForm.errors.conference }}</p>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input v-model="entitiesForm.sync" type="checkbox" class="rounded border-gray-300" />
                            {{ $t('directory_import.sync_label') }}
                        </label>
                        <button type="submit" :disabled="!entitiesForm.file || entitiesForm.processing"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                            {{ $t('directory_import.preview_btn') }}
                        </button>
                    </form>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="font-semibold text-gray-900">{{ $t('directory_import.pastors_title') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ $t('directory_import.pastors_help') }}</p>
                    <p class="mt-1 text-sm text-gray-500">{{ $t('directory_import.pastors_note') }}</p>
                    <a :href="route('admin.directory-import.sample', 'pastors')"
                        class="mt-2 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-800">
                        {{ $t('directory_import.sample_link') }}
                    </a>
                    <form class="mt-4 space-y-3" @submit.prevent="previewPastors">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ $t('directory_import.file_label') }}</label>
                            <input type="file" accept=".json,.csv,.txt" class="mt-1 block text-sm"
                                @input="pastorsForm.file = $event.target.files[0]" />
                            <p v-if="pastorsForm.errors.file" class="mt-1 text-sm text-red-600">{{ pastorsForm.errors.file }}</p>
                        </div>
                        <button type="submit" :disabled="!pastorsForm.file || pastorsForm.processing"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                            {{ $t('directory_import.preview_btn') }}
                        </button>
                    </form>
                </section>
            </template>

            <!-- Report: imported users no longer in the master data -->
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="font-semibold text-gray-900">{{ $t('directory_import.missing_title') }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ $t('directory_import.missing_help') }}</p>
                <p v-if="!missing.length" class="mt-3 text-sm text-gray-400">{{ $t('directory_import.missing_none') }}</p>
                <div v-else class="mt-3 overflow-hidden rounded-lg border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-gray-500">{{ $t('directory_import.col_name') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-500">{{ $t('directory_import.col_email') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-500">{{ $t('directory_import.col_role') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-500">{{ $t('directory_import.col_leader_of') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="u in missing" :key="u.id">
                                <td class="px-3 py-2 font-medium text-gray-900">{{ u.name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ u.email }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ u.role }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ u.entities_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Link v-if="missing.length" :href="route('admin.users.index')"
                    class="mt-3 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-800">
                    {{ $t('directory_import.manage_users') }}
                </Link>
            </section>

            <!-- How to get data.json -->
            <section class="rounded-xl border border-gray-200 bg-gray-50 p-5">
                <h2 class="font-semibold text-gray-900">{{ $t('directory_import.howto_title') }}</h2>
                <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-gray-700">
                    <li>{{ $t('directory_import.howto_1') }}</li>
                    <li>{{ $t('directory_import.howto_2') }}</li>
                    <li>{{ $t('directory_import.howto_3') }}</li>
                </ol>
            </section>
        </div>
    </AdminLayout>
</template>
