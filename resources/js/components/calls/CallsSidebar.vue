<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Clapperboard, History, LogIn, Video } from '@lucide/vue';
import { computed, ref } from 'vue';
import JoinMeetingDialog from '@/components/calls/JoinMeetingDialog.vue';
import ShellColumn from '@/components/shell/ShellColumn.vue';
import SidebarRow from '@/components/shell/SidebarRow.vue';
import SidebarSearchRow from '@/components/shell/SidebarSearchRow.vue';
import SidebarSection from '@/components/shell/SidebarSection.vue';
import WorkspaceSwitcher from '@/components/shell/WorkspaceSwitcher.vue';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import { Spinner } from '@/components/ui/spinner';
import { useCallStarter } from '@/composables/calls/useCallStarter';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { index } from '@/routes/calls';

/**
 * The calls sidebar. Four things, in the order people reach for them: start a meeting, join
 * one, look back at recent meetings, find a recording.
 */
const page = usePage();
const { slug, start, starting } = useCallStarter();
const { isCurrentOrParentUrl } = useCurrentUrl();

const joinOpen = ref(false);

const home = computed(() => index.url(slug.value));
const recordingsUrl = computed(() =>
    index.url(slug.value, { query: { tab: 'recordings' } }),
);
const onCalls = computed(() => isCurrentOrParentUrl(home.value));
const onRecordings = computed(
    () =>
        onCalls.value &&
        (page.url.includes('tab=recordings') ||
            page.url.includes('/recordings/')),
);

defineExpose({ openJoin: () => (joinOpen.value = true) });
</script>

<template>
    <ShellColumn label="Calls">
        <template #header>
            <WorkspaceSwitcher />
            <Button
                class="mt-2 h-8 w-full justify-start gap-2 rounded-lg px-2.5 text-[13px] text-brand-foreground brand-button-surface"
                :disabled="starting"
                @click="start()"
            >
                <Spinner v-if="starting" class="size-3.5" />
                <Video v-else class="size-3.5" />
                <span class="flex-1 text-left">Start meeting</span>
                <Kbd
                    class="h-4 min-w-4 bg-brand-foreground/15 px-1 text-[10px] text-brand-foreground/80"
                    >N</Kbd
                >
            </Button>
            <Button
                variant="outline"
                class="mt-1.5 h-8 w-full justify-start gap-2 rounded-lg px-2.5 text-[13px]"
                @click="joinOpen = true"
            >
                <LogIn class="size-3.5 text-muted-foreground" />
                <span class="flex-1 text-left">Join meeting</span>
                <Kbd class="h-4 min-w-4 px-1 text-[10px]">J</Kbd>
            </Button>
            <SidebarSearchRow class="mt-2" />
        </template>

        <nav aria-label="Calls">
            <SidebarSection label="Meetings">
                <SidebarRow
                    :href="home"
                    :icon="History"
                    :active="onCalls && !onRecordings"
                >
                    Recent meetings
                </SidebarRow>
                <SidebarRow
                    :href="recordingsUrl"
                    :icon="Clapperboard"
                    :active="onRecordings"
                >
                    Recordings
                </SidebarRow>
            </SidebarSection>
        </nav>

        <JoinMeetingDialog v-model:open="joinOpen" />
    </ShellColumn>
</template>
