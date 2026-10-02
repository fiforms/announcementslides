<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    slideDelaySeconds: {
        type: Number,
        default: 12,
    },
});

const form = useForm({
    slide_delay_seconds: props.slideDelaySeconds,
});

const save = () => {
    form.patch(route('profile.settings.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Slide delay</h2>

            <p class="mt-1 text-sm text-gray-600">
                How long each slide shows before a slideshow you start from the browser moves to the
                next one. This is also the auto-advance time written into the REVELation Snapshot
                Presenter export.
            </p>
        </header>

        <form @submit.prevent="save" class="mt-6 space-y-6">
            <div>
                <InputLabel for="slide_delay_seconds" value="Seconds per slide" />

                <TextInput
                    id="slide_delay_seconds"
                    v-model.number="form.slide_delay_seconds"
                    type="number"
                    min="1"
                    max="600"
                    class="mt-1 block w-32"
                />

                <InputError :message="form.errors.slide_delay_seconds" class="mt-2" />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">{{ $t('profile.save') }}</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-gray-600">
                        {{ $t('profile.saved') }}
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
