<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { EyeOff, Link2, Mail } from '@lucide/vue';
import { computed } from 'vue';
import SectionLabel from '@/components/projects/detail/SectionLabel.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import { STATUS_META } from '@/lib/mail';
import { relative } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { LinkedConversations } from '@/types';

/**
 * Mail conversations tied to this issue. Only the ones the viewer may open are listed; the rest
 * are counted so nobody is surprised by "missing" context.
 */
defineProps<{
    data: LinkedConversations | null | undefined;
    canLink: boolean;
}>();
const emit = defineEmits<{ link: [] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
</script>

<template>
    <section
        v-if="
            data === undefined ||
            data === null ||
            data.conversations.length > 0 ||
            data.hidden_count > 0 ||
            canLink
        "
    >
        <SectionLabel label="Email conversations">
            <template #actions>
                <button
                    v-if="canLink"
                    type="button"
                    class="flex h-6 items-center gap-1 rounded-md px-1.5 text-[11.5px] text-muted-foreground hover:bg-accent hover:text-foreground"
                    @click="emit('link')"
                >
                    <Link2 class="size-3" /> Link
                </button>
            </template>
        </SectionLabel>

        <div v-if="data === undefined || data === null" class="space-y-1">
            <div v-for="i in 2" :key="i" class="h-10 shimmer rounded-lg" />
        </div>

        <template v-else>
            <ul
                v-if="data.conversations.length"
                class="divide-y rounded-lg border"
            >
                <li
                    v-for="conversation in data.conversations"
                    :key="conversation.uuid"
                >
                    <Link
                        :href="`/${slug}/mail/${conversation.inbox_uuid}/all/${conversation.uuid}`"
                        class="flex items-center gap-2.5 px-3 py-2 text-[13px] hover:bg-accent/60"
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
                </li>
            </ul>
            <p
                v-else-if="data.hidden_count === 0"
                class="flex items-center gap-2 rounded-lg border border-dashed px-3 py-2.5 text-[12.5px] text-muted-foreground"
            >
                <Mail class="size-3.5" /> No conversations linked.
            </p>
            <p
                v-if="data.hidden_count > 0"
                class="mt-1.5 flex items-center gap-1.5 text-[11.5px] text-muted-foreground"
            >
                <EyeOff class="size-3" /> {{ data.hidden_count }} linked
                {{ data.hidden_count === 1 ? 'conversation' : 'conversations' }}
                you can’t see
            </p>
        </template>
    </section>
</template>
