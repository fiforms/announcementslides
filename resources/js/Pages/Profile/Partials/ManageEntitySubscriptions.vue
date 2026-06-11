<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    subscriptions: {
        type: Array,
        default: () => [],
    },
});

const searchQuery = ref('');
const searchResults = ref([]);
const searching = ref(false);
const showDropdown = ref(false);
let searchTimeout = null;

const subscribedIds = computed(() => new Set(props.subscriptions.map((s) => s.id)));

function onSearchInput() {
    clearTimeout(searchTimeout);
    if (searchQuery.value.trim().length < 2) {
        searchResults.value = [];
        showDropdown.value = false;
        return;
    }
    searching.value = true;
    searchTimeout = setTimeout(async () => {
        try {
            const { data } = await axios.get(route('entities.search'), {
                params: { q: searchQuery.value },
            });
            searchResults.value = data;
            showDropdown.value = data.length > 0;
        } finally {
            searching.value = false;
        }
    }, 250);
}

function subscribe(entity) {
    router.post(route('entities.subscribe', entity.id), {}, { preserveScroll: true });
    searchQuery.value = '';
    searchResults.value = [];
    showDropdown.value = false;
}

function unsubscribe(entityId) {
    router.delete(route('entities.unsubscribe', entityId), { preserveScroll: true });
}

function hideDropdown() {
    setTimeout(() => { showDropdown.value = false; }, 150);
}

function entityLabel(entity) {
    const parts = [entity.name];
    if (entity.city && entity.state) parts.push(`${entity.city}, ${entity.state}`);
    else if (entity.city) parts.push(entity.city);
    if (entity.entity_type) parts.push(`· ${entity.entity_type}`);
    return parts.join('  ');
}
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">
                {{ $t('profile.connected_entities') }}
            </h2>
            <p class="mt-1 text-sm text-gray-600">
                {{ $t('profile.connected_entities_description') }}
            </p>
        </header>

        <!-- Current subscriptions -->
        <div class="mt-6 space-y-2">
            <div
                v-for="sub in subscriptions"
                :key="sub.id"
                class="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3"
            >
                <div>
                    <span class="text-sm font-medium text-gray-900">{{ sub.name }}</span>
                    <span v-if="sub.city || sub.state" class="ml-2 text-sm text-gray-500">
                        {{ [sub.city, sub.state].filter(Boolean).join(', ') }}
                    </span>
                    <span
                        class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="sub.role === 'admin'
                            ? 'bg-indigo-100 text-indigo-800'
                            : 'bg-gray-100 text-gray-700'"
                    >
                        {{ $t('profile.role_' + sub.role) }}
                    </span>
                </div>
                <SecondaryButton
                    v-if="sub.role === 'viewer'"
                    type="button"
                    class="text-xs"
                    @click="unsubscribe(sub.id)"
                >
                    {{ $t('profile.unsubscribe') }}
                </SecondaryButton>
                <span v-else class="text-xs text-gray-400">{{ $t('profile.admin_managed') }}</span>
            </div>

            <p v-if="subscriptions.length === 0" class="text-sm text-gray-500 italic">
                {{ $t('profile.no_subscriptions') }}
            </p>
        </div>

        <!-- Search & subscribe -->
        <div class="mt-6 relative max-w-xl">
            <InputLabel for="entity-search" :value="$t('profile.search_entity')" />
            <div class="relative mt-1">
                <input
                    id="entity-search"
                    v-model="searchQuery"
                    type="text"
                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                    :placeholder="$t('profile.search_entity_placeholder')"
                    autocomplete="off"
                    @input="onSearchInput"
                    @blur="hideDropdown"
                    @focus="showDropdown = searchResults.length > 0"
                />
                <span
                    v-if="searching"
                    class="absolute inset-y-0 right-3 flex items-center text-gray-400 text-xs"
                >
                    {{ $t('profile.searching') }}
                </span>
            </div>

            <!-- Dropdown -->
            <ul
                v-if="showDropdown"
                class="absolute z-10 mt-1 w-full rounded-md border border-gray-200 bg-white shadow-lg max-h-60 overflow-auto text-sm"
            >
                <li
                    v-for="entity in searchResults"
                    :key="entity.id"
                    class="flex items-center justify-between px-4 py-2 hover:bg-indigo-50 cursor-pointer"
                    @mousedown.prevent="subscribe(entity)"
                >
                    <span>{{ entityLabel(entity) }}</span>
                    <span
                        v-if="subscribedIds.has(entity.id)"
                        class="ml-2 text-xs text-green-600 font-medium"
                    >
                        {{ $t('profile.already_subscribed') }}
                    </span>
                    <PrimaryButton
                        v-else
                        type="button"
                        class="ml-2 text-xs py-1 px-2"
                    >
                        {{ $t('profile.subscribe') }}
                    </PrimaryButton>
                </li>
            </ul>
        </div>
    </section>
</template>
