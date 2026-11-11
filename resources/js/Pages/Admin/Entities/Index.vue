<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    entities: { type: Array, default: () => [] },
});

const search = ref('');
const showDeactivated = ref(false);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.entities.filter((e) => {
        if (e.deactivated && !showDeactivated.value) return false;
        if (!q) return true;
        return [e.name, e.entity_type, e.city, e.state].some((v) => (v ?? '').toLowerCase().includes(q));
    });
});

// ── Details lightbox ─────────────────────────────────────────────────────
const open = ref(false);
const loading = ref(false);
const failed = ref(false);
const detail = ref(null);
let requestSeq = 0;

async function openDetails(entity) {
    const seq = ++requestSeq;
    open.value = true;
    loading.value = true;
    failed.value = false;
    detail.value = null;
    try {
        const res = await window.axios.get(route('admin.entities.show', entity.id));
        if (seq === requestSeq) detail.value = res.data;
    } catch {
        if (seq === requestSeq) failed.value = true;
    } finally {
        if (seq === requestSeq) loading.value = false;
    }
}

function close() {
    open.value = false;
}

function fmtDate(iso) {
    return iso ? new Date(iso).toLocaleDateString() : '—';
}

const addressLine = computed(() => {
    const d = detail.value;
    if (!d) return '';
    const cityLine = [d.city, [d.state, d.zip].filter(Boolean).join(' ')].filter(Boolean).join(', ');
    return [d.address, cityLine, d.country].filter(Boolean).join(' · ');
});

const safeWebsite = computed(() => {
    const w = detail.value?.website;
    if (!w) return null;
    const url = /^https?:\/\//i.test(w) ? w : `https://${w}`;
    try {
        const u = new URL(url);
        return ['http:', 'https:'].includes(u.protocol) ? u.href : null;
    } catch {
        return null;
    }
});
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">{{ $t('admin_entities.page_title') }}</h1>
        </template>

        <div class="mb-4 flex flex-wrap items-center gap-4">
            <input v-model="search" type="search" :placeholder="$t('admin_entities.search')"
                class="w-full max-w-xs rounded-md border-gray-300 text-sm" />
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input v-model="showDeactivated" type="checkbox" class="rounded border-gray-300" />
                {{ $t('admin_entities.show_deactivated') }}
            </label>
        </div>

        <div v-if="filtered.length" class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('admin_entities.col_entity') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden sm:table-cell">{{ $t('admin_entities.col_type') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">{{ $t('admin_entities.col_location') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">{{ $t('admin_entities.col_people') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('admin_entities.col_slides') }}</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">{{ $t('admin_entities.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="entity in filtered" :key="entity.id" class="hover:bg-gray-50"
                        :class="entity.deactivated ? 'opacity-60' : ''">
                        <td class="px-4 py-3 font-medium text-gray-900">
                            {{ entity.name }}
                            <span v-if="entity.deactivated" class="ml-1 rounded-full bg-gray-200 px-1.5 text-xs text-gray-600">{{ $t('admin_entities.deactivated') }}</span>
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell text-sm text-gray-500">{{ entity.entity_type ?? '—' }}</td>
                        <td class="px-4 py-3 hidden md:table-cell text-sm text-gray-500">{{ [entity.city, entity.state].filter(Boolean).join(', ') || '—' }}</td>
                        <td class="px-4 py-3 hidden md:table-cell text-sm text-gray-500">{{ entity.leaders_count }} / {{ entity.members_count }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="entity.slides_count > 0 ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-500'">
                                {{ entity.slides_count }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <button type="button" @click="openDetails(entity)"
                                class="mr-2 rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                                {{ $t('admin_entities.details') }}
                            </button>
                            <Link :href="route('admin.entities.slides', entity.id)"
                                class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                                {{ $t('admin_entities.view_slides') }}
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="rounded-xl border-2 border-dashed border-gray-200 py-16 text-center">
            <p class="text-gray-400">{{ $t('admin_entities.no_entities') }}</p>
        </div>

        <Modal :show="open" max-width="2xl" @close="close">
            <div class="p-6">
                <p v-if="loading" class="text-sm text-gray-500">{{ $t('admin_entities.loading') }}</p>
                <p v-else-if="failed" class="text-sm text-red-600">{{ $t('admin_entities.load_failed') }}</p>

                <template v-else-if="detail">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">{{ detail.name }}</h2>
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                <span v-if="detail.deactivated" class="rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-700">{{ $t('admin_entities.deactivated') }}</span>
                                <span v-if="detail.is_custom" class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs text-indigo-700">{{ $t('admin_entities.custom') }}</span>
                            </div>
                        </div>
                        <button type="button" @click="close" class="text-sm text-gray-400 hover:text-gray-600">{{ $t('admin_entities.close') }}</button>
                    </div>

                    <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-[10rem_1fr]">
                        <template v-if="detail.org_mast_id"><dt class="text-gray-500">{{ $t('admin_entities.f_org_mast_id') }}</dt><dd class="text-gray-900">{{ detail.org_mast_id }}</dd></template>
                        <template v-if="detail.entity_type"><dt class="text-gray-500">{{ $t('admin_entities.f_type') }}</dt><dd class="text-gray-900">{{ detail.entity_type }}</dd></template>
                        <template v-if="addressLine"><dt class="text-gray-500">{{ $t('admin_entities.f_address') }}</dt><dd class="text-gray-900">{{ addressLine }}</dd></template>
                        <template v-if="detail.website">
                            <dt class="text-gray-500">{{ $t('admin_entities.f_website') }}</dt>
                            <dd class="text-gray-900">
                                <a v-if="safeWebsite" :href="safeWebsite" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">{{ detail.website }}</a>
                                <span v-else>{{ detail.website }}</span>
                            </dd>
                        </template>
                        <template v-if="detail.latitude && detail.longitude"><dt class="text-gray-500">{{ $t('admin_entities.f_coords') }}</dt><dd class="text-gray-900">{{ detail.latitude }}, {{ detail.longitude }}</dd></template>
                        <template v-if="detail.description"><dt class="text-gray-500">{{ $t('admin_entities.f_description') }}</dt><dd class="text-gray-900 whitespace-pre-line">{{ detail.description }}</dd></template>
                        <dt class="text-gray-500">{{ $t('admin_entities.f_subscription') }}</dt><dd class="text-gray-900">{{ detail.allow_subscription ? $t('admin_entities.yes') : $t('admin_entities.no') }}</dd>
                        <dt class="text-gray-500">{{ $t('admin_entities.f_source') }}</dt><dd class="text-gray-900">{{ detail.is_custom ? $t('admin_entities.src_custom') : $t('admin_entities.src_directory') }}</dd>
                        <dt class="text-gray-500">{{ $t('admin_entities.f_created') }}</dt><dd class="text-gray-900">{{ fmtDate(detail.created_at) }}<template v-if="detail.created_by"> · {{ detail.created_by }}</template></dd>
                        <dt class="text-gray-500">{{ $t('admin_entities.f_slides') }}</dt><dd class="text-gray-900">{{ detail.slides_count }}</dd>
                    </dl>

                    <h3 class="mt-6 text-sm font-semibold text-gray-900">{{ $t('admin_entities.users_title') }} ({{ detail.users.length }})</h3>
                    <p v-if="!detail.users.length" class="mt-2 text-sm text-gray-400">{{ $t('admin_entities.no_users') }}</p>
                    <div v-else class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium text-gray-500">{{ $t('admin_entities.col_name') }}</th>
                                    <th class="px-3 py-2 text-left font-medium text-gray-500">{{ $t('admin_entities.col_entity_role') }}</th>
                                    <th class="px-3 py-2 text-left font-medium text-gray-500 hidden sm:table-cell">{{ $t('admin_entities.col_global_role') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="u in detail.users" :key="u.id">
                                    <td class="px-3 py-2">
                                        <div class="font-medium text-gray-900">{{ u.name }}</div>
                                        <div class="text-xs text-gray-500">{{ u.email }}</div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="u.entity_role === 'admin' ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600'">
                                            {{ $t('admin_entities.role_' + u.entity_role) }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 hidden sm:table-cell text-gray-600">{{ u.global_role }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </Modal>
    </AdminLayout>
</template>
