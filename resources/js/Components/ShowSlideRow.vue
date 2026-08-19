<script setup>
import { ref } from 'vue';
import ValidationWarnings from '@/Components/ValidationWarnings.vue';

const props = defineProps({
    slide: { type: Object, required: true },
    scopeBadge: { type: Object, required: true },
    expiresLabel: { type: String, default: null },
    draggable: { type: Boolean, default: true },
    dimmed: { type: Boolean, default: false },
    autoTag: { type: Boolean, default: false },
    showEdit: { type: Boolean, default: false },
});

defineEmits(['dragstart', 'dragend', 'edit']);

const showValidationDetails = ref(false);
</script>

<template>
    <div>
        <div :draggable="draggable"
            @dragstart="draggable && $emit('dragstart')" @dragend="$emit('dragend')"
            class="flex items-center gap-3 rounded-lg border p-2 hover:bg-gray-50"
            :class="[dimmed ? 'border-gray-100 bg-gray-50 opacity-75' : 'border-gray-200', draggable ? 'cursor-grab active:cursor-grabbing' : '']">
            <div class="relative h-10 w-16 flex-shrink-0 rounded overflow-hidden bg-slate-100">
                <img v-if="slide.thumbnail_url" :src="slide.thumbnail_url" class="h-full w-full object-cover" />
                <button v-if="slide.validation_issues?.length" @click.stop="showValidationDetails = !showValidationDetails"
                    class="absolute top-0.5 right-0.5" title="Image quality warnings detected">
                    <svg class="h-4 w-4 text-yellow-400 drop-shadow" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd"
                            d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                            clip-rule="evenodd" />
                    </svg>
                </button>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium line-clamp-1" :class="dimmed ? 'text-gray-700' : 'text-gray-900'">{{ slide.title }}</p>
                <div class="mt-0.5 flex flex-wrap items-center gap-1">
                    <span :class="scopeBadge.classes" class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-medium">
                        <span :class="scopeBadge.dot" class="h-1.5 w-1.5 rounded-full"></span>
                        {{ scopeBadge.label }}
                    </span>
                    <span v-if="autoTag" class="inline-flex text-[10px] text-gray-500 bg-gray-100 px-1.5 py-0.5 rounded-full">Auto</span>
                    <span v-if="expiresLabel" class="inline-flex text-[10px] text-amber-700 bg-amber-50 px-1.5 py-0.5 rounded-full">
                        {{ expiresLabel }}
                    </span>
                </div>
            </div>
            <button v-if="showEdit" @click.stop="$emit('edit')" title="Edit slide"
                class="flex-shrink-0 rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                    <path d="M13.586 3.586a2 2 0 1 1 2.828 2.828l-9 9a2 2 0 0 1-.878.507l-3 .824a.5.5 0 0 1-.615-.615l.824-3a2 2 0 0 1 .507-.878l9-9Z" />
                </svg>
            </button>
            <slot name="extra" />
        </div>

        <div v-if="showValidationDetails && slide.validation_issues?.length" class="mt-1 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2" @click.stop>
            <ValidationWarnings :issues="slide.validation_issues" />
        </div>
    </div>
</template>
