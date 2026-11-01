<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import InstallAppCard from '@/components/settings/InstallAppCard.vue';
import { edit } from '@/routes/appearance';
</script>

<template>
    <Head title="Appearance settings" />

    <h1 class="sr-only">Appearance settings</h1>

    <div class="space-y-6">
        <Heading variant="small" title="Appearance settings" />
        <AppearanceTabs />

        <InstallAppCard />
    </div>
</template>
