<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, Loader2, Plus, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InboxDot from '@/components/mail/InboxDot.vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { access as accessRoute } from '@/routes/settings/inboxes';
import type { InboxRole, InboxRoleOption, InboxSettings, MemberOption, TeamOption } from '@/types';

const props = defineProps<{ organizationSlug: string; inbox: InboxSettings | null; teams: TeamOption[]; members: MemberOption[]; roles: InboxRoleOption[] }>();
const open = defineModel<boolean>('open', { default: false });

type Grant = { id: number; role: InboxRole };

const teamGrants = ref<Grant[]>([]);
const userGrants = ref<Grant[]>([]);
const saving = ref(false);
const userPickerOpen = ref(false);
const teamPickerOpen = ref(false);

watch(
    () => [open.value, props.inbox] as const,
    ([isOpen, inbox]) => {
        if (isOpen && inbox) {
            teamGrants.value = inbox.teams.map((team) => ({ id: team.id, role: team.role }));
            userGrants.value = inbox.users.map((user) => ({ id: user.id, role: user.role }));
        }
    },
    { immediate: true },
);

const ROLE_HELP: Record<InboxRole, string> = {
    admin: 'Manages access, channels and automations.',
    agent: 'Reads, replies, assigns and comments.',
    viewer: 'Reads and comments, never replies.',
};

const teamById = computed(() => new Map(props.teams.map((team) => [team.id, team])));
const memberById = computed(() => new Map(props.members.map((member) => [member.id, member])));

const availableTeams = computed(() => props.teams.filter((team) => !teamGrants.value.some((grant) => grant.id === team.id)));
const availableMembers = computed(() => props.members.filter((member) => !userGrants.value.some((grant) => grant.id === member.id)));

function addTeam(id: number): void {
    teamGrants.value = [...teamGrants.value, { id, role: 'agent' }];
    teamPickerOpen.value = false;
}

function addUser(id: number): void {
    userGrants.value = [...userGrants.value, { id, role: 'agent' }];
    userPickerOpen.value = false;
}

function setRole(list: Grant[], id: number, role: string): void {
    const grant = list.find((entry) => entry.id === id);

    if (grant) {
        grant.role = role as InboxRole;
    }
}

const adminsLeft = computed(() => userGrants.value.some((grant) => grant.role === 'admin') || teamGrants.value.some((grant) => grant.role === 'admin'));

function save(): void {
    if (!props.inbox) {
        return;
    }

    saving.value = true;
    router.put(
        accessRoute.url([props.organizationSlug, props.inbox.uuid]),
        { teams: teamGrants.value, users: userGrants.value },
        {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90dvh] overflow-y-auto scrollbar-thin sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle class="font-display text-2xl">Who can see {{ inbox?.name ?? 'this inbox' }}?</DialogTitle>
                <DialogDescription>Grant access by team so new hires are covered automatically, or add people one by one. The highest role wins when both apply.</DialogDescription>
            </DialogHeader>

            <dl class="grid gap-2 rounded-xl bg-surface-sunken p-3 text-xs sm:grid-cols-3">
                <div v-for="role in roles" :key="role.value">
                    <dt class="font-semibold">{{ role.label }}</dt>
                    <dd class="text-muted-foreground">{{ ROLE_HELP[role.value] }}</dd>
                </div>
            </dl>

            <section class="space-y-2">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold">Teams</h3>
                    <Popover v-model:open="teamPickerOpen">
                        <PopoverTrigger as-child>
                            <Button variant="outline" size="sm" :disabled="availableTeams.length === 0"><Plus /> Add team</Button>
                        </PopoverTrigger>
                        <PopoverContent align="end" class="w-64 p-0">
                            <Command>
                                <CommandInput placeholder="Find a team…" />
                                <CommandList>
                                    <CommandEmpty>No team found.</CommandEmpty>
                                    <CommandGroup>
                                        <CommandItem v-for="team in availableTeams" :key="team.id" :value="team.name" class="gap-2" @select="addTeam(team.id)">
                                            <InboxDot :color="team.color" size="sm" /> {{ team.name }}
                                        </CommandItem>
                                    </CommandGroup>
                                </CommandList>
                            </Command>
                        </PopoverContent>
                    </Popover>
                </div>
                <ul v-if="teamGrants.length > 0" class="divide-y rounded-xl border">
                    <li v-for="grant in teamGrants" :key="grant.id" class="flex items-center gap-3 px-3 py-2">
                        <InboxDot :color="teamById.get(grant.id)?.color" />
                        <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ teamById.get(grant.id)?.name ?? 'Team' }}</span>
                        <Select :model-value="grant.role" @update:model-value="(value) => setRole(teamGrants, grant.id, String(value))">
                            <SelectTrigger size="sm" class="w-28"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button variant="ghost" size="icon-sm" aria-label="Remove team" @click="teamGrants = teamGrants.filter((entry) => entry.id !== grant.id)"><X /></Button>
                    </li>
                </ul>
                <p v-else class="rounded-xl border border-dashed px-3 py-3 text-center text-xs text-muted-foreground">No teams yet. Add one and everyone in it gets access at once.</p>
            </section>

            <section class="space-y-2">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold">People</h3>
                    <Popover v-model:open="userPickerOpen">
                        <PopoverTrigger as-child>
                            <Button variant="outline" size="sm" :disabled="availableMembers.length === 0"><Plus /> Add person</Button>
                        </PopoverTrigger>
                        <PopoverContent align="end" class="w-72 p-0">
                            <Command>
                                <CommandInput placeholder="Search by name or email…" />
                                <CommandList>
                                    <CommandEmpty>Nobody matches. Invite them to the organization first.</CommandEmpty>
                                    <CommandGroup>
                                        <CommandItem v-for="member in availableMembers" :key="member.id" :value="`${member.name} ${member.email}`" class="gap-2" @select="addUser(member.id)">
                                            <UserAvatar :name="member.name" :email="member.email" :src="member.avatar" :initials="member.initials" size="sm" />
                                            <span class="min-w-0 flex-1 truncate">{{ member.name }}</span>
                                            <span class="truncate text-xs text-muted-foreground">{{ member.email }}</span>
                                        </CommandItem>
                                    </CommandGroup>
                                </CommandList>
                            </Command>
                        </PopoverContent>
                    </Popover>
                </div>
                <ul v-if="userGrants.length > 0" class="divide-y rounded-xl border">
                    <li v-for="grant in userGrants" :key="grant.id" class="flex items-center gap-3 px-3 py-2">
                        <UserAvatar :name="memberById.get(grant.id)?.name" :email="memberById.get(grant.id)?.email" :src="memberById.get(grant.id)?.avatar" :initials="memberById.get(grant.id)?.initials" size="sm" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ memberById.get(grant.id)?.name ?? 'Member' }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ memberById.get(grant.id)?.email }}</p>
                        </div>
                        <Select :model-value="grant.role" @update:model-value="(value) => setRole(userGrants, grant.id, String(value))">
                            <SelectTrigger size="sm" class="w-28"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button variant="ghost" size="icon-sm" aria-label="Remove person" @click="userGrants = userGrants.filter((entry) => entry.id !== grant.id)"><X /></Button>
                    </li>
                </ul>
                <p v-else class="rounded-xl border border-dashed px-3 py-3 text-center text-xs text-muted-foreground">Nobody has direct access. That is fine if a team covers it.</p>
            </section>

            <p v-if="!adminsLeft" class="rounded-lg border border-warning/50 bg-warning/10 px-3 py-2 text-xs text-warning-foreground">
                Heads up: nobody would be an admin of this inbox. Organization admins can still fix access from here, but channels and automations would be orphaned.
            </p>

            <DialogFooter class="gap-2">
                <Button type="button" variant="ghost" @click="open = false">Cancel</Button>
                <Button type="button" class="bg-brand text-brand-foreground hover:bg-brand/90" :disabled="saving" @click="save">
                    <Loader2 v-if="saving" class="animate-spin" />
                    <Check v-else />
                    Save access
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
