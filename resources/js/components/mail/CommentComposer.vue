<script setup lang="ts">
import Mention from '@tiptap/extension-mention';
import Placeholder from '@tiptap/extension-placeholder';
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { AtSign, Lock, Send } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { mentionSuggestion } from '@/lib/mentionSuggestion';
import { cn } from '@/lib/utils';
import type { UserSummary } from '@/types';

const props = defineProps<{
    members: UserSummary[];
    inboxName: string;
    busy?: boolean;
    autofocus?: boolean;
}>();
const emit = defineEmits<{
    submit: [body: string, mentions: number[]];
    typing: [];
    cancel: [];
}>();

const editor = useEditor({
    extensions: [
        StarterKit.configure({
            heading: false,
            codeBlock: false,
            horizontalRule: false,
        }),
        Placeholder.configure({
            placeholder: `Add an internal comment… visible only to teammates in ${props.inboxName}. Type @ to mention.`,
        }),
        Mention.configure({
            HTMLAttributes: { class: 'mention' },
            renderText: ({ node }) => `@${node.attrs.label ?? node.attrs.id}`,
            suggestion: mentionSuggestion(() => props.members),
        }),
    ],
    editorProps: {
        attributes: {
            class: 'prose-tandem min-h-[3.5rem] max-h-64 overflow-y-auto px-3 py-2 text-sm scrollbar-thin',
        },
        handleKeyDown: (_view, event) => {
            if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                submit();

                return true;
            }

            if (event.key === 'Escape') {
                emit('cancel');

                return true;
            }

            return false;
        },
    },
    autofocus: props.autofocus ? 'end' : false,
    onUpdate: () => emit('typing'),
});

const isEmpty = ref(true);

watch(
    () => editor.value?.state.doc.textContent,
    (text) => (isEmpty.value = !text?.trim()),
);

const submit = () => {
    if (!editor.value || isEmpty.value || props.busy) {
        return;
    }

    const html = editor.value.getHTML();
    const mentions: number[] = [];

    editor.value.state.doc.descendants((node) => {
        if (node.type.name === 'mention' && node.attrs.id) {
            mentions.push(Number(node.attrs.id));
        }
    });

    emit('submit', html, Array.from(new Set(mentions)));
    editor.value.commands.clearContent();
};

const insertMention = () =>
    editor.value?.chain().focus().insertContent('@').run();

defineExpose({ focus: () => editor.value?.commands.focus('end') });

onBeforeUnmount(() => editor.value?.destroy());
</script>

<template>
    <div
        :class="
            cn(
                'rounded-xl border border-highlight/50 bg-highlight/10 shadow-pane transition focus-within:border-highlight',
            )
        "
    >
        <div
            class="flex items-center gap-1.5 border-b border-highlight/30 px-3 py-1.5 text-[11px] font-medium text-highlight-foreground"
        >
            <Lock class="size-3" /> Internal comment · never sent to the
            customer
        </div>
        <EditorContent :editor="editor" />
        <div class="flex items-center gap-1 px-2 pb-2">
            <Button
                variant="ghost"
                size="icon"
                class="size-7"
                aria-label="Mention someone"
                @click="insertMention"
                ><AtSign class="size-3.5"
            /></Button>
            <span class="ml-auto text-[11px] text-muted-foreground"
                ><kbd class="rounded border px-1">⌘↵</kbd> to post</span
            >
            <Button
                size="sm"
                class="h-7 gap-1 bg-highlight text-highlight-foreground hover:bg-highlight/90"
                :disabled="isEmpty || busy"
                @click="submit"
            >
                <Send class="size-3.5" /> Comment
            </Button>
        </div>
    </div>
</template>
