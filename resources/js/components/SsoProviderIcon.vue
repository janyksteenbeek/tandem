<script setup lang="ts">
import { KeyRound } from '@lucide/vue';
import GoogleIcon from '@/components/icons/GoogleIcon.vue';

defineProps<{
    provider: string;
}>();
</script>

<template>
    <GoogleIcon v-if="provider === 'google'" class="size-4" />
    <KeyRound v-else class="size-4" />
</template>
