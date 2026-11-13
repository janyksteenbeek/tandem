<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { EyeOff, Link2, Mail, Unlink } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import SectionLabel from '@/components/projects/detail/SectionLabel.vue';
import ConfirmDialog from '@/components/projects/dialogs/ConfirmDialog.vue';
import LinkConversationDialog from '@/components/projects/dialogs/LinkConversationDialog.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import { api, reportError } from '@/lib/http';
import { STATUS_META } from '@/lib/mail';
import { relative } from '@/lib/time';
import { cn } from '@/lib/utils';
import type {
    ConversationListItem,
    LinkedConversation,
    LinkedConversations,
} from '@/types';

/**
 * Mail conversations tied to this issue. Only the ones the viewer may open are listed; the rest
 * are counted so nobody is surprised by "missing" context. Linking and unlinking happen here
 * against the issue-side endpoints and update the local copy from the server's answer.
 */
const props = defineProps<{
    data: LinkedConversations | null | undefined;
    canLink: boolean;
    identifier: string;
}>();
const emit = defineEmits<{
    link: [];
    change: [data: LinkedConversations];
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const base = computed(
    () => `/${slug.value}/issues/${props.identifier}/conversations`,
);

const local = ref<LinkedConversations | null | undefined>(props.data);
watch(
    () => props.data,
    (value) => (local.value = value),
);

const linkOpen = ref(false);
const unlinkTarget = ref<LinkedConversation | null>(null);
const busy = ref(false);

type Response = { linked_conversations: LinkedConversations };

const apply = (data: Response) => {
    local.value = data.linked_conversations;
    emit('change', data.linked_conversations);
};

const link = async (conversation: ConversationListItem) => {
    busy.value = true;

    try {
        apply(
            await api.post<Response>(base.value, {
                conversation_uuid: conversation.uuid,
            }),
        );
        toast.success('Conversation linked');
    } catch (error) {
        reportError(error);
    } finally {
        busy.value = false;
    }
};

const unlink = async () => {
    if (!unlinkTarget.value) {
        return;
    }

    busy.value = true;

    try {
        apply(
            await api.delete<Response>(
                `${base.value}/${unlinkTarget.value.uuid}`,
            ),
        );
        toast.success('Conversation unlinked');
        unlinkTarget.value = null;
    } catch (error) {
        reportError(error);
    } finally {
        busy.value = false;
    }
};

const openLink = () => {
    emit('link');
    linkOpen.value = true;
};
</script>

<template>
    <section
        v-if="
            local === undefined ||
            local === null ||
            local.conversations.length > 0 ||
            local.hidden_count > 0 ||
            canLink
        "
    >
        <SectionLabel label="Email conversations">
            <template #actions>
                <button
                    v-if="canLink"
                    type="button"
                    class="flex h-6 items-center gap-1 rounded-md px-1.5 text-[11.5px] text-muted-foreground hover:bg-accent hover:text-foreground"
                    @click="openLink"
                >
                    <Link2 class="size-3" /> Link conversation…
                </button>
            </template>
        </SectionLabel>

        <div v-if="local === undefined || local === null" class="space-y-1">
            <div v-for="i in 2" :key="i" class="h-10 shimmer rounded-lg" />
        </div>

        <template v-else>
            <ul
                v-if="local.conversations.length"
                class="divide-y rounded-lg border"
            >
                <li
                    v-for="conversation in local.conversations"
                    :key="conversation.uuid"
                    class="group flex items-center"
                >
                    <Link
                        :href="`/${slug}/mail/${conversation.inbox_uuid}/all/${conversation.uuid}`"
                        class="flex min-w-0 flex-1 items-center gap-2.5 px-3 py-2 text-[13px] hover:bg-accent/60"
                    >
                        <InboxDot :color="conversation.inbox_color" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2">
                                <span class="truncate font-medium">{{
                                    conversation.subject ?? '(no subject)'
                                }}</span>
                                <span
                                    v-if="conversation.kind === 'created_from'"
                                    class="shrink-0 rounded-full border px-1.5 text-[10px] text-muted-foreground"
                                    >Created from</span
                                >
                            </span>
                            <span
                                class="block truncate text-[11.5px] text-muted-foreground"
                            >
                                {{ conversation.inbox_name
                                }}<template v-if="conversation.counterpart">
                                    ·
                                    {{
                                        conversation.counterpart.name ||
                                        conversation.counterpart.email
                                    }}</template
                                >
                            </span>
                        </span>
                        <span
                            :class="
                                cn(
                                    'size-1.5 shrink-0 rounded-full',
                                    STATUS_META[conversation.status]?.dot ??
                                        'bg-muted-foreground',
                                )
                            "
                            :title="STATUS_META[conversation.status]?.label"
                        />
                        <span
                            class="shrink-0 text-[11px] text-muted-foreground tabular-nums"
                            >{{ relative(conversation.last_activity_at) }}</span
                        >
                    </Link>
                    <button
                        v-if="canLink"
                        type="button"
                        class="mr-1.5 shrink-0 rounded-md p-1 text-muted-foreground opacity-0 group-hover:opacity-100 hover:bg-accent hover:text-destructive focus-visible:opacity-100"
                        aria-label="Unlink conversation"
                        @click="unlinkTarget = conversation"
                    >
                        <Unlink class="size-3.5" />
                    </button>
                </li>
            </ul>
            <p
                v-else-if="local.hidden_count === 0"
                class="flex items-center gap-2 rounded-lg border border-dashed px-3 py-2.5 text-[12.5px] text-muted-foreground"
            >
                <Mail class="size-3.5" /> No conversations linked.
            </p>
            <p
                v-if="local.hidden_count > 0"
                class="mt-1.5 flex items-center gap-1.5 text-[11.5px] text-muted-foreground"
            >
                <EyeOff class="size-3" /> {{ local.hidden_count }} more
                {{
                    local.hidden_count === 1 ? 'conversation' : 'conversations'
                }}
                you can’t see
            </p>
        </template>

        <LinkConversationDialog
            v-model:open="linkOpen"
            :exclude="local?.conversations.map((c) => c.uuid) ?? []"
            @pick="link"
        />

        <ConfirmDialog
            :open="unlinkTarget !== null"
            title="Unlink this conversation?"
            :description="`${unlinkTarget?.subject ?? 'The conversation'} will no longer be listed on ${identifier}. Anything already shared into the issue stays.`"
            confirm-label="Unlink"
            destructive
            :busy="busy"
            @update:open="(v) => !v && (unlinkTarget = null)"
            @confirm="unlink"
        />
    </section>
</template>
