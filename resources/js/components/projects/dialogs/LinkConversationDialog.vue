<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core';
import { Loader2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { api } from '@/lib/http';
import { displayName } from '@/lib/mail';
import { relative } from '@/lib/time';
import type { ConversationListItem } from '@/types';

/**
 * Search the conversations the viewer can open and pick one to link to an issue. The server
 * authorises both sides again, so nothing the viewer cannot see can ever be linked.
 */
defineProps<{ exclude?: string[] }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ pick: [conversation: ConversationListItem] }>();

const query = ref('');
const results = ref<ConversationListItem[]>([]);
const searching = ref(false);

const search = useDebounceFn(async (term: string) => {
    if (term.trim().length < 2) {
        results.value = [];

        return;
    }

    searching.value = true;

    try {
        const data = await api.get<{ conversations: ConversationListItem[] }>(
            '/search',
            { q: term, limit: 10, types: 'conversations' },
        );

        if (query.value === term) {
            results.value = data.conversations;
        }
    } catch {
        results.value = [];
    } finally {
        searching.value = false;
    }
}, 200);

watch(query, (value) => void search(value));

watch(open, (value) => {
    if (value) {
        query.value = '';
        results.value = [];
    }
});

const onInput = (event: Event) => {
    const target = event.target;

    if (target instanceof HTMLInputElement) {
        query.value = target.value;
    }
};

const pick = (conversation: ConversationListItem) => {
    emit('pick', conversation);
    open.value = false;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="top-[14%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-lg"
            :show-close-button="false"
        >
            <DialogTitle class="sr-only">Link a conversation</DialogTitle>
            <DialogDescription class="sr-only"
                >Search your mail for a conversation to link.</DialogDescription
            >

            <div class="border-b px-4 py-2">
                <p class="text-[12px] font-medium text-muted-foreground">
                    Link an email conversation
                </p>
            </div>

            <Command class="rounded-none border-0" @input="onInput">
                <div class="relative">
                    <input
                        class="h-11 w-full border-b bg-transparent px-4 text-[14px] outline-none placeholder:text-muted-foreground/60"
                        placeholder="Search subject, sender or text…"
                        autofocus
                    />
                    <Loader2
                        v-if="searching"
                        class="absolute top-3.5 right-4 size-4 animate-spin text-muted-foreground"
                    />
                </div>
                <CommandList class="max-h-80">
                    <CommandEmpty>{{
                        query.trim().length < 2
                            ? 'Type at least two characters.'
                            : 'No matching conversations.'
                    }}</CommandEmpty>
                    <CommandGroup heading="Conversations">
                        <CommandItem
                            v-for="conversation in results.filter(
                                (c) => !exclude?.includes(c.uuid),
                            )"
                            :key="conversation.uuid"
                            :value="`${conversation.uuid} ${conversation.subject ?? ''}`"
                            class="gap-2.5 py-2"
                            @select="pick(conversation)"
                        >
                            <InboxDot
                                :color="conversation.tags[0]?.color ?? null"
                                size="sm"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[13px]">{{
                                    conversation.subject ?? '(no subject)'
                                }}</span>
                                <span
                                    class="block truncate text-[11.5px] text-muted-foreground"
                                >
                                    {{ conversation.inbox_name
                                    }}<template v-if="conversation.counterpart">
                                        ·
                                        {{
                                            displayName(
                                                conversation.counterpart,
                                            )
                                        }}</template
                                    >
                                </span>
                            </span>
                            <span
                                class="shrink-0 text-[11px] text-muted-foreground tabular-nums"
                                >{{
                                    relative(conversation.last_activity_at)
                                }}</span
                            >
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </DialogContent>
    </Dialog>
</template>
