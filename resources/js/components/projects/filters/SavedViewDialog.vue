<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
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
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { api, reportError } from '@/lib/http';
import type { IssueDisplay, IssueFilters, IssueLookups } from '@/types';

/** Save the current filters + display as a personal, team or organization view. */
const open = defineModel<boolean>('open', { default: false });
const props = defineProps<{
    filters: IssueFilters;
    display: IssueDisplay;
    lookups: IssueLookups | null;
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const canManage = computed(() => Boolean(page.props.canManageOrganization));
const myTeams = computed(() =>
    (props.lookups?.teams ?? []).filter((t) =>
        props.lookups?.team_ids.includes(t.id),
    ),
);

const name = ref('');
const scope = ref<'personal' | 'team' | 'organization'>('personal');
const teamId = ref<number | null>(null);
const busy = ref(false);

watch(open, (value) => {
    if (value) {
        name.value = '';
        scope.value = 'personal';
        teamId.value = myTeams.value[0]?.id ?? null;
    }
});

const submit = async () => {
    if (!name.value.trim() || busy.value) {
        return;
    }

    busy.value = true;

    try {
        const data = await api.post<{ view: { uuid: string } }>(
            `/${slug.value}/views`,
            {
                name: name.value.trim(),
                scope: scope.value,
                team_id: scope.value === 'team' ? teamId.value : null,
                filters: props.filters,
                display: props.display,
            },
        );

        toast.success('View saved');
        open.value = false;
        router.reload({ only: ['projectsNav'] });
        router.visit(`/${slug.value}/views/${data.view.uuid}`);
    } catch (error) {
        reportError(error);
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Save view</DialogTitle>
                <DialogDescription
                    >Keep these filters and display settings one click
                    away.</DialogDescription
                >
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-1.5">
                    <Label for="view-name">Name</Label>
                    <Input
                        id="view-name"
                        v-model="name"
                        placeholder="Blocked work"
                        autofocus
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="view-scope">Visible to</Label>
                    <NativeSelect id="view-scope" v-model="scope" class="h-9">
                        <NativeSelectOption value="personal"
                            >Only me</NativeSelectOption
                        >
                        <NativeSelectOption v-if="myTeams.length" value="team"
                            >A team</NativeSelectOption
                        >
                        <NativeSelectOption
                            v-if="canManage"
                            value="organization"
                            >Everyone in the organization</NativeSelectOption
                        >
                    </NativeSelect>
                </div>
                <div v-if="scope === 'team'" class="space-y-1.5">
                    <Label for="view-team">Team</Label>
                    <NativeSelect
                        id="view-team"
                        :model-value="teamId ?? ''"
                        class="h-9"
                        @change="
                            (e: Event) =>
                                (teamId = Number(
                                    (e.target as HTMLSelectElement).value,
                                ))
                        "
                    >
                        <NativeSelectOption
                            v-for="team in myTeams"
                            :key="team.id"
                            :value="team.id"
                            >{{ team.name }}</NativeSelectOption
                        >
                    </NativeSelect>
                </div>
            </form>
            <DialogFooter>
                <Button variant="ghost" @click="open = false">Cancel</Button>
                <Button :disabled="!name.trim() || busy" @click="submit"
                    >Save view</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
