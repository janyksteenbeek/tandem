<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Clapperboard, MessageSquareText, Video } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import StatusPill from '@/components/calls/StatusPill.vue';
import { Spinner } from '@/components/ui/spinner';
import { useCallStarter } from '@/composables/calls/useCallStarter';
import { CALL_STATUS, formatDuration, plural } from '@/lib/calls/format';
import { api } from '@/lib/http';
import { fullTime } from '@/lib/time';
import { linked } from '@/routes/calls';
import type { CallSummary } from '@/types';

/**
 * Meetings about one issue or project: start one from here (it opens in a new tab so the page
 * you are on stays put) and find the ones that already happened, with their recordings. Only
 * meetings the viewer may open are listed.
 */
const props = defineProps<{ type: 'issue' | 'project'; linkKey: string }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const { startInNewTab, starting } = useCallStarter();

const calls = ref<CallSummary[] | null>(null);
const failed = ref(false);

const load = async () => {
    try {
        const data = await api.get<{ calls: CallSummary[] }>(
            linked.url(slug.value),
            { type: props.type, key: props.linkKey },
        );

        calls.value = data.calls;
        failed.value = false;
    } catch {
        calls.value = [];
        failed.value = true;
    }
};

const start = async () => {
    const call = await startInNewTab({
        link_type: props.type,
        link_key: props.linkKey,
    });

    if (call) {
        void load();
    }
};

onMounted(load);
watch(() => props.linkKey, load);

defineExpose({ start, reload: load });
</script>

<template>
    <section data-test="linked-calls">
        <div class="mb-2 flex items-center justify-between gap-2">
            <p
                class="flex items-center gap-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
            >
                Meetings
                <span
                    v-if="calls && calls.length > 0"
                    class="font-normal tabular-nums"
                    >{{ calls.length }}</span
                >
            </p>
            <button
                type="button"
                class="flex h-6 items-center gap-1 rounded-md px-1.5 text-[11.5px] text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-60"
                :disabled="starting"
                @click="start"
            >
                <Spinner v-if="starting" class="size-3" />
                <Video v-else class="size-3" />
                Start call
            </button>
        </div>

        <div v-if="calls === null" class="space-y-1">
            <div class="h-10 shimmer rounded-lg" />
        </div>

        <ul v-else-if="calls.length > 0" class="divide-y rounded-lg border">
            <li
                v-for="call in calls"
                :key="call.uuid"
                class="flex items-center"
            >
                <Link
                    :href="call.url"
                    class="flex min-w-0 flex-1 items-center gap-2.5 px-3 py-2 text-[13px] hover:bg-accent/60"
                >
                    <Video class="size-3.5 shrink-0 text-muted-foreground" />
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-2">
                            <span class="truncate font-medium">{{
                                call.display_title
                            }}</span>
                            <StatusPill
                                v-if="call.status !== 'ended'"
                                :meta="CALL_STATUS[call.status]"
                                :pulse="call.status === 'live'"
                            />
                        </span>
                        <span
                            class="block truncate text-[11.5px] text-muted-foreground"
                        >
                            {{ fullTime(call.started_at ?? call.created_at)
                            }}<template
                                v-if="
                                    call.status === 'ended' &&
                                    call.started_at &&
                                    call.duration_seconds !== null
                                "
                            >
                                ·
                                {{
                                    formatDuration(call.duration_seconds)
                                }}</template
                            ><template v-if="call.host">
                                · {{ call.host.name }}</template
                            >
                        </span>
                    </span>
                    <span
                        class="flex shrink-0 items-center gap-2.5 text-[11.5px] text-muted-foreground tabular-nums"
                    >
                        <span
                            v-if="call.recording_count > 0"
                            class="flex items-center gap-1 text-brand"
                            :title="plural(call.recording_count, 'recording')"
                            ><Clapperboard class="size-3.5" />{{
                                call.recording_count
                            }}</span
                        >
                        <span
                            v-if="call.message_count > 0"
                            class="flex items-center gap-1"
                            :title="plural(call.message_count, 'message')"
                            ><MessageSquareText class="size-3.5" />{{
                                call.message_count
                            }}</span
                        >
                    </span>
                </Link>
                <a
                    v-if="call.status !== 'ended'"
                    :href="call.join_url"
                    target="_blank"
                    rel="noopener"
                    class="mr-2 flex h-7 shrink-0 items-center rounded-md px-2.5 text-[12px] font-medium text-brand-foreground brand-button-surface"
                    >{{ call.status === 'live' ? 'Join' : 'Open' }}</a
                >
            </li>
        </ul>

        <p
            v-else
            class="flex items-center gap-2 rounded-lg border border-dashed px-3 py-2.5 text-[12.5px] text-muted-foreground"
        >
            <Video class="size-3.5" />
            {{
                failed
                    ? 'Meetings could not be loaded.'
                    : `No meetings about this ${type} yet.`
            }}
        </p>
    </section>
</template>
