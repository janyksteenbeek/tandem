<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Inbox, Plus } from '@lucide/vue';
import { ref } from 'vue';
import InboxAccessDialog from '@/components/settings/InboxAccessDialog.vue';
import InboxCard from '@/components/settings/InboxCard.vue';
import InboxDialog from '@/components/settings/InboxDialog.vue';
import InboxEditSheet from '@/components/settings/InboxEditSheet.vue';
import SettingsEmpty from '@/components/settings/SettingsEmpty.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { TooltipProvider } from '@/components/ui/tooltip';
import { index as inboxesIndex } from '@/routes/settings/inboxes';
import type { InboxRoleOption, InboxSettings, MemberOption, OrganizationSummary, TeamOption } from '@/types';

defineProps<{
    organization: OrganizationSummary;
    inboxes: InboxSettings[];
    teams: TeamOption[];
    members: MemberOption[];
    roles: InboxRoleOption[];
    canManage: boolean;
    forwardingDomain: string;
}>();

defineOptions({
    layout: (props: { organization: OrganizationSummary }) => ({
        breadcrumbs: [{ title: 'Inboxes', href: inboxesIndex(props.organization.slug) }],
    }),
});

const createOpen = ref(false);
const editOpen = ref(false);
const accessOpen = ref(false);
const selected = ref<InboxSettings | null>(null);

function edit(inbox: InboxSettings): void {
    selected.value = inbox;
    editOpen.value = true;
}

function access(inbox: InboxSettings): void {
    selected.value = inbox;
    accessOpen.value = true;
}
</script>

<template>
    <Head title="Inboxes" />

    <h1 class="sr-only">Inboxes</h1>

    <TooltipProvider :delay-duration="200">
        <SettingsSection
            title="Shared inboxes"
            :count="inboxes.length"
            :description="`Team mailboxes in ${organization.name}. Each one has its own channels, access list and automations.`"
        >
            <template #actions>
                <Button v-if="canManage" class="bg-brand text-brand-foreground shadow-float hover:bg-brand/90" @click="createOpen = true"><Plus /> New inbox</Button>
            </template>

            <SettingsEmpty
                v-if="inboxes.length === 0"
                :icon="Inbox"
                :title="canManage ? 'No shared inboxes yet.' : 'Nothing shared with you yet.'"
                :body="
                    canManage
                        ? 'Create one for support@, sales@ or anywhere a team answers together. Then connect a channel so mail starts flowing.'
                        : 'Ask an organization admin to give you access to an inbox. It will show up here and in your mail sidebar.'
                "
            >
                <Button v-if="canManage" @click="createOpen = true"><Plus /> Create your first inbox</Button>
            </SettingsEmpty>

            <div v-else class="grid gap-4 xl:grid-cols-2">
                <InboxCard v-for="inbox in inboxes" :key="inbox.uuid" :inbox="inbox" :organization-slug="organization.slug" @edit="edit(inbox)" @access="access(inbox)" />
            </div>
        </SettingsSection>
    </TooltipProvider>

    <InboxDialog v-model:open="createOpen" :organization-slug="organization.slug" :teams="teams" />
    <InboxEditSheet v-model:open="editOpen" :organization-slug="organization.slug" :inbox="selected" />
    <InboxAccessDialog v-model:open="accessOpen" :organization-slug="organization.slug" :inbox="selected" :teams="teams" :members="members" :roles="roles" />
</template>
