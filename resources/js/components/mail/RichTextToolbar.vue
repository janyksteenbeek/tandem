<script setup lang="ts">
import type { Editor } from '@tiptap/vue-3';
import { Bold, Code, Italic, Link2, List, ListOrdered, Quote, Underline as UnderlineIcon } from '@lucide/vue';
import { Toggle } from '@/components/ui/toggle';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

const props = defineProps<{ editor: Editor | undefined }>();

const setLink = () => {
    if (!props.editor) {
        return;
    }

    const previous = props.editor.getAttributes('link').href as string | undefined;
    const url = window.prompt('Link URL', previous ?? 'https://');

    if (url === null) {
        return;
    }

    if (url === '') {
        props.editor.chain().focus().extendMarkRange('link').unsetLink().run();

        return;
    }

    props.editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
};

const items = [
    { icon: Bold, label: 'Bold', keys: '⌘B', isActive: () => props.editor?.isActive('bold'), run: () => props.editor?.chain().focus().toggleBold().run() },
    { icon: Italic, label: 'Italic', keys: '⌘I', isActive: () => props.editor?.isActive('italic'), run: () => props.editor?.chain().focus().toggleItalic().run() },
    { icon: UnderlineIcon, label: 'Underline', keys: '⌘U', isActive: () => props.editor?.isActive('underline'), run: () => props.editor?.chain().focus().toggleUnderline().run() },
    { icon: Link2, label: 'Link', keys: '⌘K', isActive: () => props.editor?.isActive('link'), run: setLink },
    { icon: List, label: 'Bullet list', keys: '', isActive: () => props.editor?.isActive('bulletList'), run: () => props.editor?.chain().focus().toggleBulletList().run() },
    { icon: ListOrdered, label: 'Numbered list', keys: '', isActive: () => props.editor?.isActive('orderedList'), run: () => props.editor?.chain().focus().toggleOrderedList().run() },
    { icon: Quote, label: 'Quote', keys: '', isActive: () => props.editor?.isActive('blockquote'), run: () => props.editor?.chain().focus().toggleBlockquote().run() },
    { icon: Code, label: 'Code', keys: '', isActive: () => props.editor?.isActive('code'), run: () => props.editor?.chain().focus().toggleCode().run() },
];
</script>

<template>
    <div class="flex items-center gap-0.5">
        <Tooltip v-for="item in items" :key="item.label">
            <TooltipTrigger as-child>
                <Toggle size="sm" class="size-7 p-0" :model-value="Boolean(item.isActive())" :aria-label="item.label" @click="item.run()">
                    <component :is="item.icon" class="size-3.5" />
                </Toggle>
            </TooltipTrigger>
            <TooltipContent>{{ item.label }} <kbd v-if="item.keys" class="ml-1 rounded border px-1 text-[10px]">{{ item.keys }}</kbd></TooltipContent>
        </Tooltip>
    </div>
</template>
