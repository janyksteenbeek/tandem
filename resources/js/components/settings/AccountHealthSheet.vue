<script setup lang="ts">
import { AlertTriangle, FolderSync, RefreshCw } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ProviderBadge from '@/components/settings/ProviderBadge.vue';
import StatusPill from '@/components/settings/StatusPill.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { api, reportError } from '@/lib/http';
import { fullTime, relative } from '@/lib/time';
import { health as healthRoute } from '@/routes/mail/accounts';
import type { AccountHealth, MailAccount, SyncRun } from '@/types';

const props = defineProps<{ account: MailAccount }>();
const open = defineModel<boolean>('open', { default: false });

const loading = ref(false);
const data = ref<AccountHealth | null>(null);

async function load(): Promise<void> {
    loading.value = true;

    try {
        data.value = await api.get<AccountHealth>(
            healthRoute.url(props.account.uuid),
        );
    } catch (error) {
        reportError(error, 'Could not load health details.');
    } finally {
        loading.value = false;
    }
}

watch(open, (value) => {
    if (value) {
        void load();
    }
});

const account = computed(() => data.value?.account ?? props.account);

const stats = computed(() => [
    {
        label: 'Last synced',
        value: account.value.last_synced_at
            ? relative(account.value.last_synced_at)
            : 'Never',
        hint: fullTime(account.value.last_synced_at),
    },
    {
        label: 'Initial sync',
        value: account.value.initial_sync_completed_at
            ? 'Complete'
            : 'In progress',
        hint: fullTime(account.value.initial_sync_completed_at),
    },
    ...(account.value.provider === 'gmail'
        ? [
              {
                  label: 'Push watch',
                  value: account.value.watch_expires_at
                      ? `Renews ${relative(account.value.watch_expires_at)}`
                      : 'Not active',
                  hint: fullTime(account.value.watch_expires_at),
              },
          ]
        : []),
    {
        label: 'Queue',
        value: data.value ? `${data.value.queue.pending} waiting` : '…',
        hint: 'Jobs on the mail-sync queue across all accounts',
    },
    {
        label: 'Errors',
        value: String(account.value.error_count),
        hint:
            account.value.error_count > 0
                ? 'Consecutive failures since the last good sync'
                : 'No consecutive failures',
    },
]);

function duration(run: SyncRun): string {
    if (run.duration_ms === null) {
        return run.status === 'running' ? 'running' : '—';
    }

    return run.duration_ms < 1000
        ? `${run.duration_ms} ms`
        : `${(run.duration_ms / 1000).toFixed(1)} s`;
}

function summary(run: SyncRun): string {
    if (!run.stats) {
        return '';
    }

    return Object.entries(run.stats)
        .filter(([, value]) => typeof value === 'number' && value > 0)
        .map(([key, value]) => `${value} ${key.replace(/_/g, ' ')}`)
        .join(' · ');
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            class="flex w-full flex-col gap-0 overflow-hidden p-0 sm:max-w-xl"
        >
            <SheetHeader class="border-b px-6 py-5">
                <div class="flex items-center gap-3">
                    <ProviderBadge
                        :provider="account.provider"
                        variant="icon"
                    />
                    <div class="min-w-0 flex-1">
                        <SheetTitle
                            class="truncate font-display text-2xl leading-tight"
                            >{{ account.email }}</SheetTitle
                        >
                        <SheetDescription
                            class="flex flex-wrap items-center gap-2"
                        >
                            <StatusPill
                                :status="account.status"
                                :label="account.status_label"
                                size="sm"
                            />
                            <span
                                >{{ account.provider_label }} ·
                                {{
                                    account.inbox.type === 'personal'
                                        ? 'Personal mailbox'
                                        : account.inbox.name
                                }}</span
                            >
                        </SheetDescription>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="loading"
                        aria-label="Refresh"
                        @click="load"
                    >
                        <RefreshCw :class="loading && 'animate-spin'" />
                    </Button>
                </div>
            </SheetHeader>

            <div
                class="scrollbar-thin flex-1 space-y-6 overflow-y-auto px-6 py-5"
            >
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <div
                        v-for="stat in stats"
                        :key="stat.label"
                        class="rounded-xl border bg-surface p-3"
                        :title="stat.hint"
                    >
                        <p
                            class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            {{ stat.label }}
                        </p>
                        <p class="mt-1 truncate text-sm font-medium">
                            {{ stat.value }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="account.last_error"
                    class="flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2.5 text-sm"
                >
                    <AlertTriangle
                        class="mt-0.5 size-4 shrink-0 text-destructive"
                    />
                    <div class="min-w-0">
                        <p class="font-medium text-destructive">Last error</p>
                        <p
                            class="font-mono text-xs break-words text-foreground/80"
                        >
                            {{ account.last_error }}
                        </p>
                    </div>
                </div>

                <section>
                    <h3 class="mb-2 text-sm font-semibold">Recent sync runs</h3>
                    <div v-if="loading && !data" class="space-y-2">
                        <Skeleton v-for="n in 4" :key="n" class="h-9 w-full" />
                    </div>
                    <p
                        v-else-if="data && data.runs.length === 0"
                        class="rounded-lg border border-dashed px-3 py-4 text-center text-sm text-muted-foreground"
                    >
                        No sync has run yet. Hit “Sync now” and come back.
                    </p>
                    <div
                        v-else-if="data"
                        class="overflow-hidden rounded-xl border"
                    >
                        <Table>
                            <TableHeader>
                                <TableRow class="bg-surface">
                                    <TableHead class="h-9 text-xs"
                                        >Type</TableHead
                                    >
                                    <TableHead class="h-9 text-xs"
                                        >Status</TableHead
                                    >
                                    <TableHead class="h-9 text-xs"
                                        >Started</TableHead
                                    >
                                    <TableHead class="h-9 text-right text-xs"
                                        >Took</TableHead
                                    >
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="run in data.runs"
                                    :key="run.id"
                                    class="text-xs"
                                >
                                    <TableCell class="capitalize">{{
                                        run.type
                                    }}</TableCell>
                                    <TableCell>
                                        <StatusPill
                                            :status="run.status"
                                            size="sm"
                                        />
                                        <p
                                            v-if="run.error"
                                            class="mt-1 max-w-56 truncate font-mono text-[11px] text-destructive"
                                            :title="run.error"
                                        >
                                            {{ run.error }}
                                        </p>
                                        <p
                                            v-else-if="summary(run)"
                                            class="mt-1 text-[11px] text-muted-foreground"
                                        >
                                            {{ summary(run) }}
                                        </p>
                                    </TableCell>
                                    <TableCell
                                        class="whitespace-nowrap text-muted-foreground"
                                        :title="fullTime(run.started_at)"
                                        >{{
                                            relative(run.started_at)
                                        }}</TableCell
                                    >
                                    <TableCell
                                        class="text-right whitespace-nowrap tabular-nums"
                                        >{{ duration(run) }}</TableCell
                                    >
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </section>

                <section v-if="data && data.folders.length > 0">
                    <h3
                        class="mb-2 flex items-center gap-1.5 text-sm font-semibold"
                    >
                        <FolderSync class="size-4 text-muted-foreground" />
                        Folders
                    </h3>
                    <ul class="divide-y rounded-xl border">
                        <li
                            v-for="folder in data.folders"
                            :key="folder.id"
                            class="flex items-center gap-3 px-3 py-2 text-sm"
                        >
                            <span class="min-w-0 flex-1 truncate">{{
                                folder.name
                            }}</span>
                            <span
                                class="hidden text-[11px] text-muted-foreground capitalize sm:inline"
                                >{{ folder.type }}</span
                            >
                            <span
                                class="text-xs text-muted-foreground tabular-nums"
                                >{{ folder.message_count }}</span
                            >
                            <span
                                :class="[
                                    'size-1.5 rounded-full',
                                    folder.sync_enabled
                                        ? 'bg-success'
                                        : 'bg-muted-foreground/40',
                                ]"
                                :title="
                                    folder.sync_enabled
                                        ? 'Syncing'
                                        : 'Not synced'
                                "
                            />
                        </li>
                    </ul>
                </section>
            </div>
        </SheetContent>
    </Sheet>
</template>
