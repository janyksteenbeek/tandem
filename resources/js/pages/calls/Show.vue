<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    CircleDot,
    ClipboardCopy,
    Download,
    FileText,
    FolderKanban,
    Link2,
    Mail,
    MessageSquareText,
    MoreHorizontal,
    Pencil,
    Plus,
    RefreshCw,
    Send,
    Trash2,
    Unlink,
    Video,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ChatTranscript from '@/components/calls/ChatTranscript.vue';
import EmailTranscriptDialog from '@/components/calls/EmailTranscriptDialog.vue';
import LinkCallDialog from '@/components/calls/LinkCallDialog.vue';
import PostToIssueDialog from '@/components/calls/PostToIssueDialog.vue';
import RecordingCard from '@/components/calls/RecordingCard.vue';
import StatusPill from '@/components/calls/StatusPill.vue';
import ConfirmDialog from '@/components/projects/dialogs/ConfirmDialog.vue';
import WorkspaceHeader from '@/components/projects/workspace/WorkspaceHeader.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { copyText } from '@/composables/calls/useCallStarter';
import {
    CALL_STATUS,
    DELIVERY_STATUS,
    formatDuration,
    formatSize,
    plural,
} from '@/lib/calls/format';
import { api, reportError } from '@/lib/http';
import { fullTime, relative } from '@/lib/time';
import { destroy, index, transcript, update } from '@/routes/calls';
import { destroy as destroyLink } from '@/routes/calls/links';
import { resend } from '@/routes/calls/transcript';
import type {
    Call,
    CallAttendee,
    CallFile,
    CallLinkItem,
    CallMessage,
    CallRecordingWithExports,
    TranscriptDelivery,
} from '@/types';

const props = defineProps<{
    call: Call;
    participants: CallAttendee[];
    messages: CallMessage[];
    files: CallFile[];
    recordings: CallRecordingWithExports[];
    deliveries: TranscriptDelivery[];
    links: CallLinkItem[];
    suggestedRecipients: { email: string; name: string }[];
    exportsAvailable: boolean;
    can: {
        update: boolean;
        delete: boolean;
        share: boolean;
        deleteRecordings: boolean;
    };
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const scope = computed<[string, string]>(() => [slug.value, props.call.uuid]);

const emailOpen = ref(false);
const linkOpen = ref(false);
const renameOpen = ref(false);
const deleteOpen = ref(false);
const postTarget = ref<CallLinkItem | null>(null);
const postOpen = ref(false);
const busy = ref(false);
const title = ref('');

const links = ref<CallLinkItem[]>(props.links);
watch(
    () => props.links,
    (value) => (links.value = value),
);

const attended = computed(() =>
    props.participants.filter((participant) => participant.attended),
);
const absent = computed(() =>
    props.participants.filter((participant) => !participant.attended),
);
const hasPlayableRecording = computed(() =>
    props.recordings.some((recording) => recording.is_playable),
);
const ended = computed(() => props.call.status === 'ended');

const subtitle = computed(() => {
    const parts: string[] = [];
    const when = props.call.started_at ?? props.call.created_at;

    if (when) {
        parts.push(fullTime(when));
    }

    if (props.call.started_at && props.call.duration_seconds !== null) {
        parts.push(formatDuration(props.call.duration_seconds));
    }

    parts.push(plural(attended.value.length, 'attendee'));

    return parts.join(' · ');
});

// Recordings being finalised and emails being sent settle on their own; keep the page honest.
const unsettled = computed(
    () =>
        props.recordings.some(
            (recording) =>
                recording.is_active || recording.status === 'processing',
        ) || props.deliveries.some((delivery) => delivery.status === 'queued'),
);

let poll: ReturnType<typeof setInterval> | undefined;

watch(
    unsettled,
    (value) => {
        clearInterval(poll);

        if (value) {
            poll = setInterval(
                () => router.reload({ only: ['recordings', 'deliveries'] }),
                5000,
            );
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => clearInterval(poll));

const copyTranscript = async () => {
    try {
        const data = await api.get<{ text: string }>(
            transcript.url(scope.value),
        );

        await copyText(data.text, 'Meeting chat copied');
    } catch (error) {
        reportError(error, 'The transcript could not be loaded.');
    }
};

const openRename = () => {
    title.value = props.call.title ?? '';
    renameOpen.value = true;
};

const rename = () => {
    router.patch(
        update.url(scope.value),
        { title: title.value.trim() || null },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => (renameOpen.value = false),
        },
    );
};

const setAutoEmail = (value: boolean) => {
    router.patch(
        update.url(scope.value),
        { auto_email_transcript: value },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    value
                        ? 'The chat will be emailed when the meeting ends'
                        : 'Automatic email switched off',
                ),
        },
    );
};

const remove = () => {
    router.delete(destroy.url(scope.value), {
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
    });
};

const unlink = async (link: CallLinkItem) => {
    try {
        const data = await api.delete<{ links: CallLinkItem[] }>(
            destroyLink.url([slug.value, props.call.uuid, link.id]),
        );

        links.value = data.links;
    } catch (error) {
        reportError(error);
    }
};

const resendDelivery = async (delivery: TranscriptDelivery) => {
    try {
        await api.post(
            resend.url([slug.value, props.call.uuid, delivery.uuid]),
        );
        toast.success(`Sending again to ${delivery.email}`);
        router.reload({ only: ['deliveries'] });
    } catch (error) {
        reportError(error, 'The email could not be queued again.');
    }
};

const openPost = (link: CallLinkItem) => {
    postTarget.value = link;
    postOpen.value = true;
};

const sectionLabel =
    'text-[11px] font-semibold tracking-wide text-muted-foreground uppercase';
</script>

<template>
    <Head :title="call.display_title" />

    <div class="flex h-full min-h-0 flex-col">
        <WorkspaceHeader
            :breadcrumbs="[
                { label: 'Calls', href: index.url(slug) },
                { label: call.display_title },
            ]"
            :title="call.display_title"
            :icon="Video"
        >
            <template #subtitle>{{ subtitle }}</template>
            <template #title-extra>
                <StatusPill
                    v-if="!ended"
                    :meta="CALL_STATUS[call.status]"
                    :pulse="call.status === 'live'"
                />
            </template>
            <template #actions>
                <Button
                    v-if="!ended"
                    as-child
                    size="sm"
                    class="h-8 gap-1.5 px-4 text-brand-foreground brand-button-surface"
                >
                    <a :href="call.join_url"
                        ><Video class="size-3.5" />
                        {{ call.status === 'live' ? 'Join' : 'Open' }}</a
                    >
                </Button>
                <Button
                    v-if="can.share"
                    size="sm"
                    :variant="ended ? 'default' : 'outline'"
                    class="h-8 gap-1.5"
                    @click="emailOpen = true"
                >
                    <Mail class="size-3.5" /> Email meeting chat
                </Button>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label="More actions"
                            ><MoreHorizontal class="size-4"
                        /></Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <DropdownMenuItem
                            v-if="!ended"
                            class="gap-2"
                            @select="
                                copyText(call.join_url, 'Meeting link copied')
                            "
                        >
                            <Link2 class="size-4" /> Copy meeting link
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="gap-2"
                            @select="copyTranscript"
                        >
                            <ClipboardCopy class="size-4" /> Copy transcript
                        </DropdownMenuItem>
                        <DropdownMenuItem as-child>
                            <a
                                :href="
                                    transcript.url(scope, {
                                        query: { download: 1 },
                                    })
                                "
                                class="gap-2"
                                ><Download class="size-4" /> Download
                                transcript</a
                            >
                        </DropdownMenuItem>
                        <template v-if="can.update">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                class="gap-2"
                                @select="openRename"
                            >
                                <Pencil class="size-4" /> Rename
                            </DropdownMenuItem>
                        </template>
                        <template v-if="can.delete">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                class="gap-2 text-destructive focus:text-destructive"
                                @select="deleteOpen = true"
                            >
                                <Trash2 class="size-4" /> Delete meeting
                            </DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </WorkspaceHeader>

        <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
            <div
                class="mx-auto grid max-w-6xl gap-x-10 gap-y-8 px-4 py-6 sm:px-6 lg:grid-cols-[minmax(0,1fr)_19rem]"
            >
                <div class="min-w-0 space-y-8">
                    <section
                        v-if="recordings.length > 0"
                        aria-labelledby="call-recordings"
                    >
                        <h2
                            id="call-recordings"
                            :class="[sectionLabel, 'mb-2']"
                        >
                            Recordings
                        </h2>
                        <div class="space-y-2">
                            <RecordingCard
                                v-for="recording in recordings"
                                :key="recording.uuid"
                                :slug="slug"
                                :call-uuid="call.uuid"
                                :recording="recording"
                                :exports-available="exportsAvailable"
                                :can="{
                                    share: can.share,
                                    delete: can.deleteRecordings,
                                }"
                            />
                        </div>
                    </section>

                    <section id="chat" aria-labelledby="call-chat">
                        <div class="mb-3 flex items-center justify-between">
                            <h2 id="call-chat" :class="sectionLabel">
                                Meeting chat
                                <span
                                    v-if="call.message_count > 0"
                                    class="ml-1 font-normal tabular-nums"
                                    >{{ call.message_count }}</span
                                >
                            </h2>
                            <div
                                v-if="messages.length > 0"
                                class="flex items-center gap-1"
                            >
                                <button
                                    type="button"
                                    class="flex h-6 items-center gap-1 rounded-md px-1.5 text-[11.5px] text-muted-foreground hover:bg-accent hover:text-foreground"
                                    @click="copyTranscript"
                                >
                                    <ClipboardCopy class="size-3" /> Copy
                                </button>
                                <a
                                    :href="
                                        transcript.url(scope, {
                                            query: { download: 1 },
                                        })
                                    "
                                    class="flex h-6 items-center gap-1 rounded-md px-1.5 text-[11.5px] text-muted-foreground hover:bg-accent hover:text-foreground"
                                >
                                    <Download class="size-3" /> Download
                                </a>
                            </div>
                        </div>

                        <ChatTranscript
                            v-if="messages.length > 0"
                            :messages="messages"
                        />
                        <div
                            v-else
                            class="flex flex-col items-center rounded-xl border border-dashed px-6 py-10 text-center"
                        >
                            <span
                                class="mb-3 flex size-10 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground"
                                ><MessageSquareText class="size-5"
                            /></span>
                            <p class="font-display text-lg">
                                Nothing was written in this meeting.
                            </p>
                            <p
                                class="mt-1 max-w-sm text-[13px] text-muted-foreground"
                            >
                                Messages and files shared in the meeting chat
                                stay here after it ends.
                            </p>
                        </div>
                    </section>
                </div>

                <aside class="min-w-0 space-y-7">
                    <section aria-labelledby="call-people">
                        <h2 id="call-people" :class="[sectionLabel, 'mb-2']">
                            Participants
                            <span class="ml-1 font-normal tabular-nums">{{
                                attended.length
                            }}</span>
                        </h2>
                        <p
                            v-if="participants.length === 0"
                            class="text-[12.5px] text-muted-foreground"
                        >
                            Nobody has joined yet.
                        </p>
                        <ul class="space-y-2">
                            <li
                                v-for="person in attended"
                                :key="person.uuid"
                                class="flex items-center gap-2.5"
                            >
                                <UserAvatar
                                    :name="person.name"
                                    :email="person.email"
                                    :src="person.avatar"
                                    :initials="person.initials"
                                    size="sm"
                                />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[13px]">{{
                                        person.name
                                    }}</span>
                                    <span
                                        v-if="person.email"
                                        class="block truncate text-[11.5px] text-muted-foreground"
                                        >{{ person.email }}</span
                                    >
                                </span>
                                <span
                                    v-if="person.role !== 'member'"
                                    class="shrink-0 rounded-full border px-1.5 text-[10px] text-muted-foreground capitalize"
                                    >{{ person.role }}</span
                                >
                            </li>
                        </ul>
                        <p
                            v-if="absent.length > 0"
                            class="mt-2.5 text-[12px] text-muted-foreground"
                        >
                            Let in but never connected:
                            {{ absent.map((p) => p.name).join(', ') }}
                        </p>
                    </section>

                    <section aria-labelledby="call-links">
                        <div class="mb-2 flex items-center justify-between">
                            <h2 id="call-links" :class="sectionLabel">
                                Linked to
                            </h2>
                            <button
                                v-if="can.update"
                                type="button"
                                class="flex h-6 items-center gap-1 rounded-md px-1.5 text-[11.5px] text-muted-foreground hover:bg-accent hover:text-foreground"
                                @click="linkOpen = true"
                            >
                                <Plus class="size-3" /> Link…
                            </button>
                        </div>
                        <p
                            v-if="links.length === 0"
                            class="text-[12.5px] text-muted-foreground"
                        >
                            Not linked to an issue or project.
                        </p>
                        <ul v-else class="divide-y rounded-lg border">
                            <li
                                v-for="link in links"
                                :key="link.id"
                                class="group px-2.5 py-2"
                            >
                                <div class="flex items-center gap-2">
                                    <component
                                        :is="
                                            link.type === 'issue'
                                                ? CircleDot
                                                : FolderKanban
                                        "
                                        class="size-3.5 shrink-0 text-muted-foreground"
                                    />
                                    <Link
                                        :href="link.url"
                                        class="min-w-0 flex-1 truncate text-[13px] hover:underline"
                                    >
                                        <span
                                            v-if="link.type === 'issue'"
                                            class="mr-1 font-mono text-[11px] text-muted-foreground"
                                            >{{ link.key }}</span
                                        >{{ link.title }}
                                    </Link>
                                    <button
                                        v-if="can.update"
                                        type="button"
                                        class="shrink-0 rounded-md p-1 text-muted-foreground opacity-0 group-hover:opacity-100 hover:bg-accent hover:text-destructive focus-visible:opacity-100"
                                        :aria-label="`Unlink ${link.title}`"
                                        @click="unlink(link)"
                                    >
                                        <Unlink class="size-3.5" />
                                    </button>
                                </div>
                                <button
                                    v-if="link.type === 'issue' && can.share"
                                    type="button"
                                    class="mt-1 ml-5.5 flex h-6 items-center gap-1 rounded-md px-1.5 text-[11.5px] text-brand hover:bg-brand-soft/60"
                                    @click="openPost(link)"
                                >
                                    <Send class="size-3" /> Post chat and
                                    recordings to this issue
                                </button>
                            </li>
                        </ul>
                    </section>

                    <section
                        v-if="files.length > 0"
                        aria-labelledby="call-files"
                    >
                        <h2 id="call-files" :class="[sectionLabel, 'mb-2']">
                            Shared files
                            <span class="ml-1 font-normal tabular-nums">{{
                                files.length
                            }}</span>
                        </h2>
                        <ul class="divide-y rounded-lg border">
                            <li v-for="file in files" :key="file.uuid">
                                <a
                                    :href="file.download_url"
                                    class="group flex items-center gap-2.5 px-2.5 py-2 hover:bg-accent/60"
                                >
                                    <img
                                        v-if="file.is_image"
                                        :src="file.url"
                                        alt=""
                                        loading="lazy"
                                        class="size-7 shrink-0 rounded object-cover"
                                    />
                                    <span
                                        v-else
                                        class="flex size-7 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground"
                                        ><FileText class="size-3.5"
                                    /></span>
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block truncate text-[12.5px] font-medium"
                                            >{{ file.filename }}</span
                                        >
                                        <span
                                            class="block text-[11px] text-muted-foreground"
                                            >{{
                                                formatSize(file.size_bytes)
                                            }}</span
                                        >
                                    </span>
                                    <Download
                                        class="size-3.5 shrink-0 text-muted-foreground group-hover:text-brand"
                                        aria-label="Download"
                                    />
                                </a>
                            </li>
                        </ul>
                    </section>

                    <section aria-labelledby="call-email">
                        <h2 id="call-email" :class="[sectionLabel, 'mb-2']">
                            Emailed chat
                        </h2>
                        <label
                            v-if="can.update && !ended"
                            class="mb-3 flex cursor-pointer items-start justify-between gap-3 rounded-lg border px-3 py-2.5"
                        >
                            <span>
                                <span class="block text-[12.5px] font-medium"
                                    >Email the chat when this meeting ends</span
                                >
                                <span
                                    class="block text-[11.5px] text-muted-foreground"
                                    >To everyone who was in it.</span
                                >
                            </span>
                            <Switch
                                :model-value="
                                    call.settings.auto_email_transcript
                                "
                                class="mt-0.5"
                                @update:model-value="setAutoEmail"
                            />
                        </label>
                        <p
                            v-if="deliveries.length === 0"
                            class="text-[12.5px] text-muted-foreground"
                        >
                            The chat has not been emailed to anyone.
                        </p>
                        <ul v-else class="divide-y rounded-lg border">
                            <li
                                v-for="delivery in deliveries"
                                :key="delivery.uuid"
                                class="px-2.5 py-2"
                                data-test="delivery"
                            >
                                <div class="flex items-center gap-2">
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block truncate text-[12.5px]"
                                            >{{
                                                delivery.name || delivery.email
                                            }}</span
                                        >
                                        <span
                                            class="block truncate text-[11px] text-muted-foreground"
                                            >{{
                                                delivery.name
                                                    ? `${delivery.email} · `
                                                    : ''
                                            }}{{
                                                relative(
                                                    delivery.sent_at ??
                                                        delivery.created_at,
                                                )
                                            }}{{
                                                delivery.is_automatic
                                                    ? ' · automatic'
                                                    : ''
                                            }}</span
                                        >
                                    </span>
                                    <StatusPill
                                        :meta="DELIVERY_STATUS[delivery.status]"
                                        :pulse="delivery.status === 'queued'"
                                    />
                                    <button
                                        v-if="
                                            can.share &&
                                            delivery.status !== 'queued'
                                        "
                                        type="button"
                                        class="shrink-0 rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                                        :aria-label="`Send again to ${delivery.email}`"
                                        :title="`Send again to ${delivery.email}`"
                                        @click="resendDelivery(delivery)"
                                    >
                                        <RefreshCw class="size-3.5" />
                                    </button>
                                </div>
                                <p
                                    v-if="
                                        delivery.status === 'failed' &&
                                        delivery.error
                                    "
                                    class="mt-1 text-[11.5px] break-words text-destructive"
                                >
                                    {{ delivery.error }}
                                </p>
                            </li>
                        </ul>
                    </section>
                </aside>
            </div>
        </div>

        <EmailTranscriptDialog
            v-model:open="emailOpen"
            :call="call"
            :suggested="suggestedRecipients"
            :has-files="files.length > 0"
            :has-recording="hasPlayableRecording"
            @sent="router.reload({ only: ['deliveries'] })"
        />
        <LinkCallDialog
            v-model:open="linkOpen"
            :call-uuid="call.uuid"
            @linked="(value) => (links = value)"
        />
        <PostToIssueDialog
            v-model:open="postOpen"
            :call-uuid="call.uuid"
            :link="postTarget"
            :has-recordings="hasPlayableRecording"
        />

        <Dialog v-model:open="renameOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Rename meeting</DialogTitle>
                    <DialogDescription class="sr-only"
                        >Give this meeting a name</DialogDescription
                    >
                </DialogHeader>
                <form id="rename-call" @submit.prevent="rename">
                    <Input
                        v-model="title"
                        v-focus
                        maxlength="200"
                        aria-label="Meeting name"
                        :placeholder="`Meeting ${call.code}`"
                    />
                </form>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="busy"
                        @click="renameOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        form="rename-call"
                        size="sm"
                        :disabled="busy"
                        >Save</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            v-model:open="deleteOpen"
            title="Delete this meeting?"
            :description="`“${call.display_title}” is removed with its chat, shared files and ${plural(recordings.length, 'recording')}. This cannot be undone.`"
            confirm-label="Delete meeting"
            destructive
            :busy="busy"
            @confirm="remove"
        />
    </div>
</template>
