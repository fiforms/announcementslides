<script setup>
defineProps({
    issues: { type: Array, default: () => [] },
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div v-if="issues.length" class="validation-warnings">
        <div v-if="compact" class="flex items-center gap-2">
            <div class="relative group">
                <svg class="h-5 w-5 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd"
                        d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                        clip-rule="evenodd" />
                </svg>
                <div class="invisible group-hover:visible absolute left-0 mt-2 bg-gray-900 text-white text-xs rounded-lg py-2 px-3 whitespace-nowrap z-50">
                    {{ issues.length }} warning{{ issues.length === 1 ? '' : 's' }}
                </div>
            </div>
        </div>
        <div v-else class="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
            <div class="flex gap-3">
                <svg class="h-5 w-5 text-yellow-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd"
                        d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                        clip-rule="evenodd" />
                </svg>
                <div class="flex-1">
                    <h4 class="text-sm font-semibold text-yellow-800">Image quality issues detected</h4>
                    <ul class="mt-2 space-y-1">
                        <li v-for="(issue, idx) in issues" :key="idx" class="text-xs text-yellow-700">
                            • {{ issue }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.validation-warnings {
    /* Empty — styles applied inline */
}
</style>
