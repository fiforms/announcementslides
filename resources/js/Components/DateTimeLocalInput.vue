<script setup>
import { computed } from 'vue';

// A `datetime-local` input requires both date and time segments before its
// value counts as valid — leave the time segment untouched (still showing
// "--:--") and the browser reports the whole field invalid, even though a
// date was entered. Splitting into separate date/time inputs sidesteps that:
// the time always carries a real default (midnight) that only needs
// changing when the user actually wants a specific time.
const props = defineProps({
    modelValue: { type: String, default: '' }, // 'YYYY-MM-DDTHH:MM' or ''
});

const emit = defineEmits(['update:modelValue']);

const datePart = computed(() => props.modelValue ? props.modelValue.slice(0, 10) : '');
const timePart = computed(() => props.modelValue ? props.modelValue.slice(11, 16) : '00:00');

function onDateInput(e) {
    const date = e.target.value;
    emit('update:modelValue', date ? `${date}T${timePart.value}` : '');
}

function onTimeInput(e) {
    // Nothing to combine into a full value until a date is actually picked.
    if (!datePart.value) return;
    emit('update:modelValue', `${datePart.value}T${e.target.value}`);
}
</script>

<template>
    <div class="flex gap-2">
        <input :value="datePart" @input="onDateInput" type="date"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
        <input :value="timePart" @input="onTimeInput" type="time"
            class="w-28 flex-shrink-0 rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
</template>
