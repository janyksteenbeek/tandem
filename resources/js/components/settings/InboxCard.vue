<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight, MessageSquare, Pencil, Plug, Users } from '@lucide/vue';
import { computed } from 'vue';
import InboxDot from '@/components/mail/InboxDot.vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import ProviderBadge from '@/components/settings/ProviderBadge.vue';
import StatusPill from '@/components/settings/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { chipClass } from '@/lib/mail';
import { relative } from '@/lib/time';
import { index as mailAccountsIndex } from '@/routes/mail/accounts';
import type { InboxSettings } from '@/types';

const props = defineProps<{ inbox: InboxSettings; organizationSlug: string }>();
const emit = defineEmits<{ edit: []; access: [] }>();

const mailUrl = computed(() => `/${props.organizationSlug}/mail/${props.inbox.uuid}/open`);
const visibleUsers = computed(() => props.inbox.users.slice(0, 5));
const hiddenUsers = computed(() => Math.max(0, props.inbox.users.length - visibleUsers.value.length));
const unhealthy = computed(() => props.inbox.accounts.filter((account) => !account.healthy && account.status !== 'pending').length);
</script>

<template>
    <article class="flex flex-col rounded-2xl border bg-card shadow-pane transition hover:shadow-float">
        <div class="flex items-start gap-3 p-4 sm:p-5">
            <span :class="['mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-xl bg-(--chip)/15 text-(--chip)', chipClass(inbox.color)]">
                <InboxDot :color="inbox.color" class="size-3" />
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <h3 class="truncate text-base font-semibold">{{ inbox.name }}</h3>
                    <span v-if="unhealthy > 0" class="rounded-full bg-warning/20 px-2 py-0.5 text-[11px] font-medium text-warning-foreground">{{ unhealthy }} channel{{ unhealthy === 1 ? '' : 's' }} need attention</span>
                </div>
                <p class="truncate text-sm text-muted-foreground">{{ inbox.email_address ?? 'No public address yet' }}</p>
                <p v-if="inbox.description" class="mt-1 line-clamp-2 text-sm text-foreground/80">{{ inbox.description }}</p>
            </div>
            <Tooltip>
                <TooltipTrigger as-child>
                    <Button as-child variant="ghost" size="icon-sm" class="text-muted-foreground">
                        <Link :href="mailUrl" aria-label="Open in mail"><ArrowUpRight /></Link>
                    </Button>
                </TooltipTrigger>
                <TooltipContent>Open in mail</TooltipContent>
            </Tooltip>
        </div>

        <div class="grid gap-4 border-t px-4 py-4 sm:grid-cols-2 sm:px-5">
            <div class="min-w-0">
                <p class="mb-1.5 flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"><Plug class="size-3" /> Channels</p>
                <ul v-if="inbox.accounts.length > 0" class="space-y-1.5">
                    <li v-for="account in inbox.accounts" :key="account.uuid" class="flex items-center gap-2 text-sm">
                        <ProviderBadge :provider="account.provider" variant="icon" size="sm" />
                        <span class="min-w-0 flex-1 truncate" :title="account.email">{{ account.email }}</span>
                        <StatusPill :status="account.status" :label="account.status_label" size="sm" :title="account.last_synced_at ? `Synced ${relative(account.last_synced_at)}` : undefined" />
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    Nothing connected, so nothing arrives.
                    <Link v-if="inbox.can_manage" :href="mailAccountsIndex()" class="font-medium text-brand underline-offset-2 hover:underline">Connect a channel</Link>
                </p>
            </div>

            <div class="min-w-0">
                <p class="mb-1.5 flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"><Users class="size-3" /> Access</p>
                <div class="flex flex-wrap items-center gap-2">
                    <div v-if="inbox.users.length > 0" class="flex -space-x-2">
                        <UserAvatar v-for="user in visibleUsers" :key="user.id" :name="user.name" :email="user.email" :src="user.avatar" :initials="user.initials" size="md" class="ring-2 ring-card" />
                        <span v-if="hiddenUsers > 0" class="flex size-8 items-center justify-center rounded-full bg-muted text-[11px] font-semibold text-muted-foreground ring-2 ring-card">+{{ hiddenUsers }}</span>
                    </div>
                    <span v-for="team in inbox.teams" :key="team.id" :class="['inline-flex items-center gap-1.5 rounded-full border border-(--chip)/40 bg-(--chip)/10 px-2 py-0.5 text-xs font-medium', chipClass(team.color)]">
                        <InboxDot :color="team.color" size="sm" /> {{ team.name }}
                        <span class="text-[10px] text-muted-foreground">{{ team.role }}</span>
                    </span>
                    <span v-if="inbox.users.length === 0 && inbox.teams.length === 0" class="text-sm text-muted-foreground">Nobody yet.</span>
                </div>
            </div>
        </div>

        <footer class="mt-auto flex flex-wrap items-center justify-between gap-2 border-t bg-surface/60 px-4 py-3 sm:px-5">
            <p class="flex items-center gap-1.5 text-xs text-muted-foreground tabular-nums"><MessageSquare class="size-3.5" /> {{ inbox.conversation_count }} conversation{{ inbox.conversation_count === 1 ? '' : 's' }}</p>
            <div v-if="inbox.can_manage" class="flex gap-1.5">
                <Button variant="outline" size="sm" @click="emit('access')"><Users /> Access</Button>
                <Button variant="outline" size="sm" @click="emit('edit')"><Pencil /> Edit</Button>
            </div>
        </footer>
    </article>
</template>
