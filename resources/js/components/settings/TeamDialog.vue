<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Crown, Loader2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import ColorPicker from '@/components/settings/ColorPicker.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import {
    store as storeTeam,
    update as updateTeam,
} from '@/routes/settings/teams';
import type { MemberOption, TeamRole, TeamSettings } from '@/types';

const props = defineProps<{
    organizationSlug: string;
    members: MemberOption[];
    team: TeamSettings | null;
}>();
const open = defineModel<boolean>('open', { default: false });

type MemberGrant = { id: number; role: TeamRole };

const form = useForm({
    name: '',
    color: 'violet',
    description: '',
    members: [] as MemberGrant[],
});

const search = ref('');

watch(
    () => [open.value, props.team] as const,
    ([isOpen, team]) => {
        if (!isOpen) {
            return;
        }

        form.clearErrors();
        search.value = '';
        form.name = team?.name ?? '';
        form.color = team?.color ?? 'violet';
        form.description = team?.description ?? '';
        form.members = team
            ? team.members.map((member) => ({
                  id: member.id,
                  role: member.role,
              }))
            : [];
    },
    { immediate: true },
);

const filteredMembers = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return needle
        ? props.members.filter((member) =>
              `${member.name} ${member.email}`.toLowerCase().includes(needle),
          )
        : props.members;
});

const grantFor = (id: number): MemberGrant | undefined =>
    form.members.find((member) => member.id === id);

function toggleMember(id: number, checked: boolean): void {
    form.members = checked
        ? [
              ...form.members.filter((member) => member.id !== id),
              { id, role: 'member' },
          ]
        : form.members.filter((member) => member.id !== id);
}

function toggleLead(id: number): void {
    const grant = grantFor(id);

    if (grant) {
        grant.role = grant.role === 'lead' ? 'member' : 'lead';
    }
}

const leadCount = computed(
    () => form.members.filter((member) => member.role === 'lead').length,
);

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (props.team) {
        form.patch(
            updateTeam.url([props.organizationSlug, props.team.slug]),
            options,
        );
    } else {
        form.post(storeTeam.url(props.organizationSlug), options);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="scrollbar-thin max-h-[90dvh] overflow-y-auto sm:max-w-lg"
        >
            <form class="space-y-6" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle class="font-display text-2xl">{{
                        team ? `Edit ${team.name}` : 'New team'
                    }}</DialogTitle>
                    <DialogDescription
                        >Teams group people so you can grant inbox access and
                        route conversations in one go.</DialogDescription
                    >
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-1.5">
                        <Label for="team-name">Name</Label>
                        <Input
                            id="team-name"
                            v-model="form.name"
                            placeholder="Support"
                            maxlength="60"
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Colour</Label>
                        <ColorPicker v-model="form.color" />
                        <InputError :message="form.errors.color" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="team-description"
                            >Description
                            <span class="font-normal text-muted-foreground"
                                >(optional)</span
                            ></Label
                        >
                        <Textarea
                            id="team-description"
                            v-model="form.description"
                            rows="2"
                            maxlength="255"
                            placeholder="Who is on it and what they handle."
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between gap-2">
                        <Label
                            >Members
                            <span class="font-normal text-muted-foreground"
                                >({{ form.members.length }} selected<template
                                    v-if="leadCount"
                                    >, {{ leadCount }} lead{{
                                        leadCount === 1 ? '' : 's'
                                    }}</template
                                >)</span
                            ></Label
                        >
                        <Input
                            v-model="search"
                            placeholder="Filter people…"
                            class="h-8 w-40 text-xs"
                        />
                    </div>
                    <ul
                        class="scrollbar-thin max-h-64 divide-y overflow-y-auto rounded-xl border"
                    >
                        <li
                            v-for="member in filteredMembers"
                            :key="member.id"
                            class="flex items-center gap-3 px-3 py-2"
                        >
                            <Checkbox
                                :id="`team-member-${member.id}`"
                                :model-value="!!grantFor(member.id)"
                                @update:model-value="
                                    (value) =>
                                        toggleMember(member.id, value === true)
                                "
                            />
                            <label
                                :for="`team-member-${member.id}`"
                                class="flex min-w-0 flex-1 cursor-pointer items-center gap-2"
                            >
                                <UserAvatar
                                    :name="member.name"
                                    :email="member.email"
                                    :src="member.avatar"
                                    :initials="member.initials"
                                    size="sm"
                                />
                                <span class="min-w-0">
                                    <span
                                        class="block truncate text-sm font-medium"
                                        >{{ member.name }}</span
                                    >
                                    <span
                                        class="block truncate text-xs text-muted-foreground"
                                        >{{ member.email }}</span
                                    >
                                </span>
                            </label>
                            <button
                                v-if="grantFor(member.id)"
                                type="button"
                                :class="
                                    cn(
                                        'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium transition',
                                        grantFor(member.id)?.role === 'lead'
                                            ? 'border-highlight/60 bg-highlight/25 text-highlight-foreground'
                                            : 'text-muted-foreground hover:bg-accent',
                                    )
                                "
                                :aria-pressed="
                                    grantFor(member.id)?.role === 'lead'
                                "
                                @click="toggleLead(member.id)"
                            >
                                <Crown class="size-3" /> Lead
                            </button>
                        </li>
                        <li
                            v-if="filteredMembers.length === 0"
                            class="px-3 py-4 text-center text-xs text-muted-foreground"
                        >
                            Nobody matches. Invite them to the organization
                            first.
                        </li>
                    </ul>
                    <p class="text-xs text-muted-foreground">
                        Leads are the go-to people for the team. Assignment
                        rules and reports treat them the same as members.
                    </p>
                    <InputError :message="form.errors.members" />
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="ghost" @click="open = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        class="bg-brand text-brand-foreground hover:bg-brand/90"
                        :disabled="form.processing"
                    >
                        <Loader2 v-if="form.processing" class="animate-spin" />
                        {{ team ? 'Save team' : 'Create team' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
