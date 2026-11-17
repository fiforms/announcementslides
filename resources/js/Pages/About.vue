<script setup>
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AboutContent from '@/Components/AboutContent.vue';

defineProps({ about: { type: Object, required: true } });

const { t } = useI18n();
</script>

<template>
    <Head :title="t('about.title')" />
    <PublicLayout>
        <AboutContent :about="about" />
    </PublicLayout>
</template>
