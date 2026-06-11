<script setup>
import { ref, computed } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const { t } = useI18n();
const appName = computed(() => usePage().props.appName);

const props = defineProps({
    users:       { type: Array, default: () => [] },
    invitations: { type: Array, default: () => [] },
});

// ── Tabs ───────────────────────────────────────────────────────────────────────

const activeTab = ref('users');

// ── Search ─────────────────────────────────────────────────────────────────────

const search = ref('');
const filteredUsers = computed(() => {
    const q = search.value.toLowerCase().trim();
    if (!q) return props.users;
    return props.users.filter(u =>
        u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q)
    );
});

// ── Expanded row ───────────────────────────────────────────────────────────────

const expandedUser = ref(null);
function toggleExpand(userId) {
    expandedUser.value = expandedUser.value === userId ? null : userId;
}

// ── Role update ────────────────────────────────────────────────────────────────

function setGlobalRole(user, role) {
    if (user.role === role) return;
    router.patch(route('admin.users.update', user.id), { role }, { preserveScroll: true });
}

// ── Entity role update ─────────────────────────────────────────────────────────

function setEntityRole(user, entity, role) {
    if (entity.role === role) return;
    router.patch(route('admin.users.entities.update', { user: user.id, entity: entity.id }), { role }, {
        preserveScroll: true,
    });
}

function detachEntity(user, entity) {
    if (!confirm(t('admin_users.confirm_detach_entity', { user: user.name, entity: entity.name }))) return;
    router.delete(route('admin.users.entities.detach', { user: user.id, entity: entity.id }), {
        preserveScroll: true,
    });
}

// ── Attach entity panel ────────────────────────────────────────────────────────

const attachingUser = ref(null);
const entitySearch  = ref('');
const entityResults = ref([]);
const attachRole    = ref('viewer');
let searchTimer     = null;

function openAttachPanel(user) {
    attachingUser.value = user;
    entitySearch.value  = '';
    entityResults.value = [];
    attachRole.value    = 'viewer';
}

function closeAttachPanel() {
    attachingUser.value = null;
}

async function searchEntities() {
    clearTimeout(searchTimer);
    const q = entitySearch.value.trim();
    if (!q) { entityResults.value = []; return; }
    searchTimer = setTimeout(async () => {
        const res = await window.axios.get(route('entities.search'), { params: { q } });
        const existing = new Set(attachingUser.value.entities.map(e => e.id));
        entityResults.value = res.data.filter(e => !existing.has(e.id));
    }, 250);
}

function attachEntity(entity) {
    router.post(
        route('admin.users.entities.attach', attachingUser.value.id),
        { entity_id: entity.id, role: attachRole.value },
        { preserveScroll: true, onSuccess: () => closeAttachPanel() }
    );
}

// ── Invitations ────────────────────────────────────────────────────────────────

const inviteForm = useForm({ email: '', role: 'viewer' });

function submitInvite() {
    inviteForm.post(route('admin.invitations.store'), {
        preserveScroll: true,
        onSuccess: () => inviteForm.reset(),
    });
}

function destroyInvitation(invitation) {
    if (!confirm(t('admin_users.confirm_remove_invitation', { email: invitation.email }))) return;
    router.delete(route('admin.invitations.destroy', invitation.id), { preserveScroll: true });
}

// ── Helpers ────────────────────────────────────────────────────────────────────

const GLOBAL_ROLES = ['viewer', 'contributor', 'admin', 'banned'];

function roleBadgeClass(role) {
    return {
        admin:       'bg-indigo-100 text-indigo-700',
        contributor: 'bg-blue-100 text-blue-700',
        viewer:      'bg-gray-100 text-gray-600',
        banned:      'bg-red-100 text-red-700',
    }[role] ?? 'bg-gray-100 text-gray-600';
}

function entityRoleBadgeClass(role) {
    return role === 'admin'
        ? 'bg-indigo-100 text-indigo-700'
        : 'bg-gray-100 text-gray-600';
}

function inviteMailtoLink(inv) {
    const loginUrl = new URL(route('auth.google'), window.location.origin).href;
    const subject  = t('admin_users.invite_email_subject', { appName: appName.value });
    const body     = t('admin_users.invite_email_body', { role: inv.role, appName: appName.value, url: loginUrl });
    return `mailto:${inv.email}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">{{ $t('admin_users.page_title') }}</h1>
        </template>

        <!-- Tabs -->
        <div class="border-b border-gray-200 mb-6">
            <nav class="-mb-px flex gap-6">
                <button @click="activeTab = 'users'"
                    class="pb-3 text-sm font-medium border-b-2 transition-colors"
                    :class="activeTab === 'users'
                        ? 'border-indigo-600 text-indigo-600'
                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'">
                    {{ $t('admin_users.tab_users') }}
                    <span class="ml-1.5 rounded-full px-2 py-0.5 text-xs font-semibold bg-gray-100 text-gray-600">
                        {{ users.length }}
                    </span>
                </button>
                <button @click="activeTab = 'invitations'"
                    class="pb-3 text-sm font-medium border-b-2 transition-colors"
                    :class="activeTab === 'invitations'
                        ? 'border-indigo-600 text-indigo-600'
                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'">
                    {{ $t('admin_users.tab_invitations') }}
                    <span class="ml-1.5 rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="invitations.length > 0 ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600'">
                        {{ invitations.length }}
                    </span>
                </button>
            </nav>
        </div>

        <!-- ── Users tab ─────────────────────────────────────────────────────── -->
        <template v-if="activeTab === 'users'">
            <div class="mb-6 max-w-sm">
                <input v-model="search" type="search" :placeholder="$t('admin_users.search_placeholder')"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
            </div>

            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('admin_users.col_user') }}</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('admin_users.col_global_role') }}</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">{{ $t('admin_users.col_joined') }}</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">{{ $t('admin_users.col_entities') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template v-for="user in filteredUsers" :key="user.id">
                            <tr class="hover:bg-gray-50 cursor-pointer" @click="toggleExpand(user.id)">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <img v-if="user.avatar_url" :src="user.avatar_url"
                                            class="h-8 w-8 rounded-full object-cover flex-shrink-0" />
                                        <div v-else
                                            class="h-8 w-8 rounded-full bg-indigo-100 flex items-center justify-center flex-shrink-0">
                                            <span class="text-xs font-semibold text-indigo-700">
                                                {{ user.name.charAt(0).toUpperCase() }}
                                            </span>
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-900">{{ user.name }}</p>
                                            <p class="text-xs text-gray-400">{{ user.email }}</p>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-3" @click.stop>
                                    <select :value="user.role"
                                        @change="setGlobalRole(user, $event.target.value)"
                                        class="rounded-md border border-gray-300 px-2 py-1 text-xs font-medium focus:border-indigo-500 focus:ring-indigo-500"
                                        :class="roleBadgeClass(user.role)">
                                        <option v-for="r in GLOBAL_ROLES" :key="r" :value="r"
                                            class="bg-white text-gray-900">
                                            {{ r }}
                                        </option>
                                    </select>
                                </td>

                                <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-500">
                                    {{ user.created_at }}
                                </td>

                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <span class="text-xs text-gray-500">
                                            {{ $t('admin_users.entity_count', { n: user.entities.length }, user.entities.length) }}
                                        </span>
                                        <svg class="h-4 w-4 text-gray-400 transition-transform"
                                            :class="expandedUser === user.id ? 'rotate-180' : ''"
                                            fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="expandedUser === user.id" :key="`${user.id}-entities`">
                                <td colspan="4" class="bg-gray-50 px-4 py-4">
                                    <div class="pl-11">
                                        <div class="flex items-center justify-between mb-3">
                                            <h3 class="text-sm font-semibold text-gray-700">{{ $t('admin_users.entity_relationships') }}</h3>
                                            <button @click.stop="openAttachPanel(user)"
                                                class="flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 transition-colors">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                                </svg>
                                                {{ $t('admin_users.add_entity') }}
                                            </button>
                                        </div>

                                        <div v-if="user.entities.length" class="rounded-lg border border-gray-200 bg-white overflow-hidden">
                                            <table class="min-w-full divide-y divide-gray-100 text-xs">
                                                <thead class="bg-gray-50">
                                                    <tr>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500">{{ $t('admin_users.col_entity') }}</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500">{{ $t('admin_users.col_type') }}</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500">{{ $t('admin_users.col_role') }}</th>
                                                        <th class="px-3 py-2 text-right font-medium text-gray-500">{{ $t('admin_users.col_actions') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-100">
                                                    <tr v-for="entity in user.entities" :key="entity.id" class="hover:bg-gray-50">
                                                        <td class="px-3 py-2 font-medium text-gray-800">
                                                            {{ entity.name }}
                                                            <span v-if="entity.city || entity.state" class="ml-1 text-gray-400 font-normal">
                                                                {{ [entity.city, entity.state].filter(Boolean).join(', ') }}
                                                            </span>
                                                        </td>
                                                        <td class="px-3 py-2 text-gray-500 capitalize">{{ entity.entity_type ?? '—' }}</td>
                                                        <td class="px-3 py-2">
                                                            <select :value="entity.role"
                                                                @change="setEntityRole(user, entity, $event.target.value)"
                                                                class="rounded border border-gray-300 px-1.5 py-0.5 text-xs focus:border-indigo-500 focus:ring-indigo-500"
                                                                :class="entityRoleBadgeClass(entity.role)">
                                                                <option value="viewer">{{ $t('admin_users.role_viewer') }}</option>
                                                                <option value="admin">{{ $t('admin_users.role_leader') }}</option>
                                                            </select>
                                                        </td>
                                                        <td class="px-3 py-2 text-right">
                                                            <button @click.stop="detachEntity(user, entity)"
                                                                class="text-red-500 hover:text-red-700 transition-colors">
                                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                                </svg>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        <p v-else class="text-xs text-gray-400 italic">{{ $t('admin_users.no_entity_relationships') }}</p>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div v-if="!filteredUsers.length" class="mt-8 rounded-xl border-2 border-dashed border-gray-200 py-12 text-center">
                <p class="text-gray-400 text-sm">{{ $t('admin_users.no_users') }}</p>
            </div>
        </template>

        <!-- ── Invitations tab ───────────────────────────────────────────────── -->
        <template v-if="activeTab === 'invitations'">
            <div class="mb-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-gray-900 mb-4">{{ $t('admin_users.preregister_title') }}</h2>
                <form @submit.prevent="submitInvite" class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-48">
                        <label class="block text-xs font-medium text-gray-700 mb-1">{{ $t('admin_users.email_label') }}</label>
                        <input v-model="inviteForm.email" type="email" required
                            :placeholder="$t('admin_users.email_placeholder')"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            :class="inviteForm.errors.email ? 'border-red-400' : ''" />
                        <p v-if="inviteForm.errors.email" class="mt-1 text-xs text-red-600">{{ inviteForm.errors.email }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">{{ $t('admin_users.role_on_login_label') }}</label>
                        <select v-model="inviteForm.role"
                            class="rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="viewer">{{ $t('admin_users.role_option_viewer') }}</option>
                            <option value="contributor">{{ $t('admin_users.role_option_contributor') }}</option>
                            <option value="admin">{{ $t('admin_users.role_option_admin') }}</option>
                        </select>
                    </div>
                    <button type="submit" :disabled="inviteForm.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                        {{ $t('admin_users.save') }}
                    </button>
                </form>
                <p class="mt-3 text-xs text-gray-400">{{ $t('admin_users.preregister_hint') }}</p>
            </div>

            <div v-if="invitations.length" class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('admin_users.col_email') }}</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('admin_users.col_role') }}</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">{{ $t('admin_users.col_added_by') }}</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">{{ $t('admin_users.col_date') }}</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">{{ $t('admin_users.col_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="inv in invitations" :key="inv.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ inv.email }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold capitalize"
                                    :class="roleBadgeClass(inv.role)">
                                    {{ inv.role }}
                                </span>
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-500">
                                {{ inv.created_by?.name ?? '—' }}
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-500">
                                {{ inv.created_at }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a :href="inviteMailtoLink(inv)"
                                        class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                                        {{ $t('admin_users.send_email') }}
                                    </a>
                                    <button @click="destroyInvitation(inv)"
                                        class="rounded-md border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition-colors">
                                        {{ $t('admin_users.remove') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="mt-2 rounded-xl border-2 border-dashed border-gray-200 py-12 text-center">
                <p class="text-gray-400 text-sm">{{ $t('admin_users.no_invitations') }}</p>
            </div>
        </template>

        <!-- Attach entity modal -->
        <div v-if="attachingUser" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-xl" @click.stop>
                <div class="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-900">
                        {{ $t('admin_users.modal_add_entity_title', { name: attachingUser.name }) }}
                    </h2>
                    <button @click="closeAttachPanel" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('admin_users.search_entity_label') }}</label>
                        <input v-model="entitySearch" @input="searchEntities" type="search"
                            :placeholder="$t('admin_users.search_entity_placeholder')"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('admin_users.role_label') }}</label>
                        <select v-model="attachRole"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="viewer">{{ $t('admin_users.role_viewer_member') }}</option>
                            <option value="admin">{{ $t('admin_users.role_leader_admin') }}</option>
                        </select>
                    </div>

                    <ul v-if="entityResults.length" class="divide-y divide-gray-100 rounded-lg border border-gray-200 overflow-hidden max-h-60 overflow-y-auto">
                        <li v-for="entity in entityResults" :key="entity.id"
                            @click="attachEntity(entity)"
                            class="px-3 py-2.5 hover:bg-indigo-50 cursor-pointer transition-colors">
                            <p class="text-sm font-medium text-gray-800">{{ entity.name }}</p>
                            <p class="text-xs text-gray-400">
                                {{ [entity.city, entity.state].filter(Boolean).join(', ') }}
                                <span v-if="entity.entity_type" class="ml-1 capitalize">· {{ entity.entity_type }}</span>
                            </p>
                        </li>
                    </ul>

                    <p v-else-if="entitySearch.trim()" class="text-xs text-gray-400 italic">{{ $t('admin_users.no_entity_results') }}</p>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
