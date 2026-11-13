<script setup lang="ts">
import { computed } from 'vue';
import { linkify } from '@/lib/meet/linkify';

/**
 * A chat message body. The text is plain: it is split into text and link segments and each
 * is rendered as an ordinary node, so nothing in it can ever be interpreted as markup.
 */
const props = defineProps<{ text: string; edited?: boolean }>();

const segments = computed(() => linkify(props.text));
</script>

<template>
    <p class="text-[13.5px] leading-relaxed break-words whitespace-pre-wrap">
        <template v-for="(segment, index) in segments" :key="index">
            <a
                v-if="segment.type === 'link'"
                :href="segment.href"
                target="_blank"
                rel="noopener noreferrer nofollow"
                class="font-medium text-brand underline decoration-brand/30 underline-offset-2 hover:decoration-brand"
                >{{ segment.text }}</a
            >
            <template v-else>{{ segment.text }}</template>
        </template>
        <span v-if="edited" class="ml-1 text-[11px] text-muted-foreground"
            >(edited)</span
        >
    </p>
</template>
