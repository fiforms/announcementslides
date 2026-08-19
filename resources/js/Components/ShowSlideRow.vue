<script setup>
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
</script>

<template>
    <div :draggable="draggable"
        @dragstart="draggable && $emit('dragstart')" @dragend="$emit('dragend')"
        class="flex items-center gap-3 rounded-lg border p-2 hover:bg-gray-50"
        :class="[dimmed ? 'border-gray-100 bg-gray-50 opacity-75' : 'border-gray-200', draggable ? 'cursor-grab active:cursor-grabbing' : '']">
        <div class="h-10 w-16 flex-shrink-0 rounded overflow-hidden bg-slate-100">
            <img v-if="slide.thumbnail_url" :src="slide.thumbnail_url" class="h-full w-full object-cover" />
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
</template>
