<script setup lang="ts">
import { computed } from 'vue';
import { linkify } from '@/lib/calls/linkify';

/**
 * Chat text as participants typed it: plain text with line breaks kept and http(s) links
 * turned into anchors. Nothing is ever rendered as HTML.
 */
const props = defineProps<{ text: string }>();

const parts = computed(() => linkify(props.text));
</script>

<template>
    <span class="break-words whitespace-pre-wrap"
        ><template v-for="(part, index) in parts" :key="index"
            ><a
                v-if="part.type === 'link'"
                :href="part.href"
                target="_blank"
                rel="noopener noreferrer nofollow"
                class="text-brand underline decoration-brand/30 underline-offset-2 hover:decoration-brand"
                >{{ part.value }}</a
            ><template v-else>{{ part.value }}</template></template
        ></span
    >
</template>
