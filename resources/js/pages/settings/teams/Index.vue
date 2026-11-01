<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Crown, Inbox, Pencil, Plus, Trash2, Users } from '@lucide/vue';
import { ref } from 'vue';
import InboxDot from '@/components/mail/InboxDot.vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import ConfirmDialog from '@/components/settings/ConfirmDialog.vue';
import SettingsEmpty from '@/components/settings/SettingsEmpty.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import TeamDialog from '@/components/settings/TeamDialog.vue';
import { Button } from '@/components/ui/button';
import { chipClass } from '@/lib/mail';
import {
    destroy as destroyTeam,
    index as teamsIndex,
} from '@/routes/settings/teams';
import type { MemberOption, OrganizationSummary, TeamSettings } from '@/types';

const props = defineProps<{
    organization: OrganizationSummary;
    teams: TeamSettings[];
    members: MemberOption[];
    canManage: boolean;
}>();

const dialogOpen = ref(false);
const editing = ref<TeamSettings | null>(null);
const removing = ref<TeamSettings | null>(null);
const removeBusy = ref(false);

function create(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function edit(team: TeamSettings): void {
    editing.value = team;
    dialogOpen.value = true;
}

function confirmRemove(): void {
    if (!removing.value) {
        return;
    }

    removeBusy.value = true;
    router.delete(
        destroyTeam.url([props.organization.slug, removing.value.slug]),
        {
            preserveScroll: true,
            onFinish: () => {
                removeBusy.value = false;
                removing.value = null;
            },
        },
    );
}

const leads = (team: TeamSettings) =>
    team.members.filter((member) => member.role === 'lead');
</script>

<template>
    <Head title="Teams" />

    <h1 class="sr-only">Teams</h1>

    <SettingsSection
        title="Teams"
        :count="teams.length"
        :description="`Groups of people in ${organization.name}. Grant a team access to an inbox and everyone in it is covered, including whoever joins next month.`"
    >
        <template #actions>
            <Button
                v-if="canManage"
                class="bg-brand text-brand-foreground shadow-float hover:bg-brand/90"
                @click="create"
                ><Plus /> New team</Button
            >
        </template>

        <SettingsEmpty
            v-if="teams.length === 0"
            :icon="Users"
            title="No teams yet."
            :body="
                canManage
                    ? 'Start with the way you already work: Support, Sales, Billing. Then hand each team its inboxes instead of adding people one by one.'
                    : 'Teams appear here once an organization admin creates them.'
            "
        >
            <Button v-if="canManage" @click="create"
                ><Plus /> Create a team</Button
            >
        </SettingsEmpty>

        <div v-else class="grid gap-4 md:grid-cols-2">
            <article
                v-for="team in teams"
                :key="team.id"
                class="flex flex-col rounded-2xl border bg-card shadow-pane transition hover:shadow-float"
            >
                <div class="flex items-start gap-3 p-4 sm:p-5">
                    <span
                        :class="[
                            'mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-xl bg-(--chip)/15 text-(--chip)',
                            chipClass(team.color),
                        ]"
                    >
                        <Users class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h3 class="truncate text-base font-semibold">
                            {{ team.name }}
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            {{ team.members.length }}
                            {{
                                team.members.length === 1 ? 'person' : 'people'
                            }}
                            <template v-if="leads(team).length">
                                · led by
                                {{
                                    leads(team)
                                        .map(
                                            (member) =>
                                                member.name.split(' ')[0],
                                        )
                                        .join(', ')
                                }}</template
                            >
                        </p>
                        <p
                            v-if="team.description"
                            class="mt-1 line-clamp-2 text-sm text-foreground/80"
                        >
                            {{ team.description }}
                        </p>
                    </div>
                    <div
                        v-if="canManage"
                        class="flex shrink-0 items-center gap-0.5"
                    >
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Edit team"
                            @click="edit(team)"
                            ><Pencil
                        /></Button>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="text-muted-foreground hover:text-destructive"
                            aria-label="Delete team"
                            @click="removing = team"
                            ><Trash2
                        /></Button>
                    </div>
                </div>

                <div class="space-y-4 border-t px-4 py-4 sm:px-5">
                    <div>
                        <p
                            class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Members
                        </p>
                        <ul
                            v-if="team.members.length > 0"
                            class="flex flex-wrap gap-1.5"
                        >
                            <li
                                v-for="member in team.members"
                                :key="member.id"
                                class="inline-flex items-center gap-1.5 rounded-full border bg-surface py-0.5 pr-2.5 pl-0.5 text-xs"
                                :title="member.email"
                            >
                                <UserAvatar
                                    :name="member.name"
                                    :email="member.email"
                                    :src="member.avatar"
                                    :initials="member.initials"
                                    size="sm"
                                />
                                <span class="max-w-32 truncate font-medium">{{
                                    member.name
                                }}</span>
                                <Crown
                                    v-if="member.role === 'lead'"
                                    class="size-3 text-highlight-foreground"
                                    aria-label="Lead"
                                />
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground">
                            Nobody on this team yet.
                        </p>
                    </div>
                    <div>
                        <p
                            class="mb-2 flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            <Inbox class="size-3" /> Inbox access
                        </p>
                        <ul
                            v-if="team.inboxes.length > 0"
                            class="flex flex-wrap gap-1.5"
                        >
                            <li
                                v-for="inbox in team.inboxes"
                                :key="inbox.uuid"
                                class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium"
                            >
                                <InboxDot :color="inbox.color" size="sm" />
                                {{ inbox.name }}
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground">
                            No inboxes yet. Grant some from the Inboxes page.
                        </p>
                    </div>
                </div>
            </article>
        </div>
    </SettingsSection>

    <TeamDialog
        v-model:open="dialogOpen"
        :organization-slug="organization.slug"
        :members="members"
        :team="editing"
    />

    <ConfirmDialog
        :open="removing !== null"
        :title="`Delete ${removing?.name ?? 'this team'}?`"
        description="People keep their accounts and any direct inbox access. Access they only had through this team goes away."
        confirm-label="Delete team"
        destructive
        :busy="removeBusy"
        @update:open="(value) => !value && (removing = null)"
        @confirm="confirmRemove"
    />
</template>
