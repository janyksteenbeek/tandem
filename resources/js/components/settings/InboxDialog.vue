<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from '@lucide/vue';
import { watch } from 'vue';
import InboxDot from '@/components/mail/InboxDot.vue';
import InboxFields from '@/components/settings/InboxFields.vue';
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
import { Label } from '@/components/ui/label';
import { store as storeInbox } from '@/routes/settings/inboxes';
import type { TeamOption } from '@/types';

const props = defineProps<{ organizationSlug: string; teams: TeamOption[] }>();
const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    name: '',
    email_address: '',
    color: 'violet',
    description: '',
    team_ids: [] as number[],
});

watch(open, (value) => {
    if (value) {
        form.reset();
        form.clearErrors();
    }
});

function toggleTeam(id: number, checked: boolean): void {
    form.team_ids = checked
        ? [...new Set([...form.team_ids, id])]
        : form.team_ids.filter((teamId) => teamId !== id);
}

function submit(): void {
    form.post(storeInbox.url(props.organizationSlug), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="scrollbar-thin max-h-[90dvh] overflow-y-auto sm:max-w-lg"
        >
            <form class="space-y-6" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle class="font-display text-2xl"
                        >New shared inbox</DialogTitle
                    >
                    <DialogDescription
                        >One place for a team to answer together. You become its
                        first admin.</DialogDescription
                    >
                </DialogHeader>

                <InboxFields
                    v-model:name="form.name"
                    v-model:email-address="form.email_address"
                    v-model:color="form.color"
                    v-model:description="form.description"
                    :errors="form.errors"
                    id-prefix="new-inbox"
                />

                <div class="grid gap-2">
                    <Label>Give access to teams</Label>
                    <p
                        v-if="teams.length === 0"
                        class="rounded-lg border border-dashed px-3 py-2 text-xs text-muted-foreground"
                    >
                        No teams yet. You can add people directly from the
                        Access dialog after creating the inbox.
                    </p>
                    <ul v-else class="grid gap-1.5 sm:grid-cols-2">
                        <li v-for="team in teams" :key="team.id">
                            <label
                                class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition hover:bg-accent has-[[data-state=checked]]:border-brand/60 has-[[data-state=checked]]:bg-brand-soft/30"
                            >
                                <Checkbox
                                    :model-value="
                                        form.team_ids.includes(team.id)
                                    "
                                    @update:model-value="
                                        (value) =>
                                            toggleTeam(team.id, value === true)
                                    "
                                />
                                <InboxDot :color="team.color" size="sm" />
                                <span class="truncate">{{ team.name }}</span>
                            </label>
                        </li>
                    </ul>
                    <p class="text-xs text-muted-foreground">
                        Teams join as agents: they can read, reply and assign.
                        Fine-tune roles later.
                    </p>
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
                        Create inbox
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
