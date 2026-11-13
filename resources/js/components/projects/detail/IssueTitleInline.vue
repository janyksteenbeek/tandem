<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { cn } from '@/lib/utils';

/**
 * The issue title as a heading that turns into an auto-growing textarea on click (or E).
 * Enter saves, Escape reverts, blur saves.
 */
const props = defineProps<{ title: string; editable: boolean }>();
const emit = defineEmits<{
    save: [title: string];
    editing: [active: boolean];
}>();

const editing = ref(false);
const draft = ref(props.title);
const input = ref<HTMLTextAreaElement | null>(null);

watch(
    () => props.title,
    (value) => {
        if (!editing.value) {
            draft.value = value;
        }
    },
);

const resize = () => {
    if (input.value) {
        input.value.style.height = 'auto';
        input.value.style.height = `${input.value.scrollHeight}px`;
    }
};

const start = async () => {
    if (!props.editable || editing.value) {
        return;
    }

    draft.value = props.title;
    editing.value = true;
    emit('editing', true);
    await nextTick();
    input.value?.focus();
    input.value?.select();
    resize();
};

const stop = (save: boolean) => {
    if (!editing.value) {
        return;
    }

    editing.value = false;
    emit('editing', false);

    const next = draft.value.trim();

    if (save && next && next !== props.title) {
        emit('save', next);
    } else {
        draft.value = props.title;
    }
};

defineExpose({ start });
</script>

<template>
    <textarea
        v-if="editing"
        ref="input"
        v-model="draft"
        rows="1"
        class="w-full resize-none overflow-hidden bg-transparent text-[22px] leading-tight font-semibold tracking-[-0.015em] outline-none"
        aria-label="Issue title"
        @input="resize"
        @keydown.enter.prevent="stop(true)"
        @keydown.esc.prevent="stop(false)"
        @blur="stop(true)"
    />
    <h1
        v-else
        :class="
            cn(
                'text-[22px] leading-tight font-semibold tracking-[-0.015em] break-words',
                editable &&
                    '-mx-1 cursor-text rounded-md px-1 transition-colors hover:bg-accent/60',
            )
        "
        :tabindex="editable ? 0 : undefined"
        :role="editable ? 'button' : undefined"
        @click="start"
        @keydown.enter.prevent="start"
    >
        {{ title }}
    </h1>
</template>
