<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import TandemMark from '@/components/mail/TandemMark.vue';

const name = usePage().props.name;
</script>

<template>
    <TandemMark :size="32" />
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            name
        }}</span>
    </div>
</template>
