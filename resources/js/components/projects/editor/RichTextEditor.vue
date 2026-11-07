<script setup lang="ts">
import Image from '@tiptap/extension-image';
import Link from '@tiptap/extension-link';
import Mention from '@tiptap/extension-mention';
import Placeholder from '@tiptap/extension-placeholder';
import TaskItem from '@tiptap/extension-task-item';
import TaskList from '@tiptap/extension-task-list';
import Underline from '@tiptap/extension-underline';
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { onBeforeUnmount, ref, watch } from 'vue';
import RichTextToolbar from '@/components/shared/RichTextToolbar.vue';
import { useShortcutScope } from '@/composables/useKeyboard';
import { api } from '@/lib/http';
import { PRIORITY } from '@/lib/keyboard';
import { mentionSuggestion } from '@/lib/mentionSuggestion';
import { cn } from '@/lib/utils';
import type { UserSummary } from '@/types';

/**
 * The shared rich-text editor for issue descriptions and comments: headings, lists, task
 * lists, links, images (pasted or dropped, uploaded when `uploadUrl` is set) and @mentions.
 * While focused it pushes a blocking keyboard scope so single-key shortcuts stay quiet.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        placeholder?: string;
        members?: UserSummary[];
        uploadUrl?: string | null;
        autofocus?: boolean;
        minHeight?: string;
        compact?: boolean;
        readonly?: boolean;
        submitShortcut?: boolean;
        toolbar?: boolean;
    }>(),
    {
        placeholder: 'Write something…',
        members: () => [],
        uploadUrl: null,
        autofocus: false,
        minHeight: '6rem',
        compact: false,
        readonly: false,
        submitShortcut: false,
        toolbar: true,
    },
);

const emit = defineEmits<{
    'update:modelValue': [html: string];
    submit: [];
    blur: [];
    focus: [];
    uploading: [busy: boolean];
}>();

const focused = ref(false);
useShortcutScope('Editor', focused, PRIORITY.editor);

const upload = async (file: File): Promise<string | null> => {
    if (!props.uploadUrl || !file.type.startsWith('image/')) {
        return null;
    }

    const body = new FormData();
    body.append('file', file);
    emit('uploading', true);

    try {
        const data = await api.post<{ attachment: { url: string } }>(
            props.uploadUrl,
            body,
        );

        return data.attachment.url;
    } catch {
        return null;
    } finally {
        emit('uploading', false);
    }
};

const editor = useEditor({
    content: props.modelValue,
    editable: !props.readonly,
    extensions: [
        StarterKit.configure({ heading: { levels: [1, 2, 3] } }),
        Underline,
        Link.configure({
            openOnClick: false,
            autolink: true,
            HTMLAttributes: { rel: 'noopener noreferrer nofollow' },
        }),
        Image.configure({ inline: false, allowBase64: false }),
        TaskList,
        TaskItem.configure({ nested: true }),
        Placeholder.configure({ placeholder: props.placeholder }),
        Mention.configure({
            HTMLAttributes: { class: 'mention' },
            renderText: ({ node }) => `@${node.attrs.label ?? node.attrs.id}`,
            suggestion: mentionSuggestion(() => props.members),
        }),
    ],
    editorProps: {
        attributes: {
            class: cn(
                'prose-tandem scrollbar-thin max-w-none outline-none',
                props.compact
                    ? 'px-0 py-1 text-[13.5px]'
                    : 'px-0 py-2 text-[14px]',
            ),
            style: `min-height: ${props.minHeight}`,
        },
        handleKeyDown: (_view, event) => {
            if (
                (event.metaKey || event.ctrlKey) &&
                event.key === 'Enter' &&
                props.submitShortcut
            ) {
                event.stopPropagation();
                emit('submit');

                return true;
            }

            return false;
        },
        handlePaste: (_view, event) => {
            const file = Array.from(event.clipboardData?.files ?? []).find(
                (f) => f.type.startsWith('image/'),
            );

            if (!file || !props.uploadUrl) {
                return false;
            }

            void upload(file).then(
                (url) =>
                    url &&
                    editor.value?.chain().focus().setImage({ src: url }).run(),
            );

            return true;
        },
        handleDrop: (_view, event) => {
            const file = Array.from(event.dataTransfer?.files ?? []).find((f) =>
                f.type.startsWith('image/'),
            );

            if (!file || !props.uploadUrl) {
                return false;
            }

            event.preventDefault();
            void upload(file).then(
                (url) =>
                    url &&
                    editor.value?.chain().focus().setImage({ src: url }).run(),
            );

            return true;
        },
    },
    autofocus: props.autofocus ? 'end' : false,
    onUpdate: ({ editor: instance }) => {
        const html = instance.isEmpty ? '' : instance.getHTML();

        if (html !== props.modelValue) {
            emit('update:modelValue', html);
        }
    },
    onFocus: () => {
        focused.value = true;
        emit('focus');
    },
    onBlur: () => {
        focused.value = false;
        emit('blur');
    },
});

watch(
    () => props.modelValue,
    (value) => {
        if (
            editor.value &&
            value !== editor.value.getHTML() &&
            !(value === '' && editor.value.isEmpty)
        ) {
            editor.value.commands.setContent(value || '', {
                emitUpdate: false,
            });
        }
    },
);

watch(
    () => props.readonly,
    (value) => editor.value?.setEditable(!value),
);

const mentions = (): number[] => {
    const ids: number[] = [];

    editor.value?.state.doc.descendants((node) => {
        if (node.type.name === 'mention' && node.attrs.id) {
            ids.push(Number(node.attrs.id));
        }
    });

    return Array.from(new Set(ids));
};

defineExpose({
    focus: () => editor.value?.commands.focus('end'),
    clear: () => editor.value?.commands.clearContent(true),
    mentions,
    isEmpty: () => editor.value?.isEmpty ?? true,
    editor,
});

onBeforeUnmount(() => editor.value?.destroy());
</script>

<template>
    <div :class="cn('group/editor relative', $attrs.class as string)">
        <EditorContent :editor="editor" />
        <div
            v-if="toolbar && !readonly && editor"
            :class="
                cn(
                    'mt-1 flex items-center transition-opacity',
                    focused
                        ? 'opacity-100'
                        : 'opacity-0 group-hover/editor:opacity-60',
                )
            "
        >
            <RichTextToolbar :editor="editor" />
        </div>
    </div>
</template>
