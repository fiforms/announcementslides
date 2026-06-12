<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    nearbyRadiusMiles: {
        type: Number,
        default: 50,
    },
});

const form = useForm({
    nearby_radius_miles: props.nearbyRadiusMiles,
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
            <h2 class="text-lg font-medium text-gray-900">Nearby sharing</h2>

            <p class="mt-1 text-sm text-gray-600">
                When you enable "Include nearby" on the slides dashboard, slides that other churches
                have chosen to share are pulled in if they fall within this distance of the selected
                church.
            </p>
        </header>

        <form @submit.prevent="save" class="mt-6 space-y-6">
            <div>
                <InputLabel for="nearby_radius_miles" value="Radius (miles)" />

                <TextInput
                    id="nearby_radius_miles"
                    v-model.number="form.nearby_radius_miles"
                    type="number"
                    min="1"
                    max="500"
                    class="mt-1 block w-32"
                />

                <InputError :message="form.errors.nearby_radius_miles" class="mt-2" />
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
