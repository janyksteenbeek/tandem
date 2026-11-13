<script lang="ts">
import type { Milestone, ProjectSummary, UserSummary } from '@/types';

export type InitiativeStatus = 'planned' | 'active' | 'completed' | 'canceled';

export type Initiative = {
    id: number;
    uuid: string;
    slug: string;
    name: string;
    color: string;
    status: InitiativeStatus;
    owner: UserSummary | null;
    start_date: string | null;
    target_date: string | null;
    projects: ProjectSummary[];
    progress: {
        projects: number;
        active: number;
        total: number;
        completed: number;
        percent: number;
    };
    description_html?: string | null;
    milestones?: Milestone[];
    can?: { update: boolean; delete: boolean };
};

export const INITIATIVE_STATUS: Record<
    InitiativeStatus,
    { label: string; dot: string }
> = {
    planned: { label: 'Planned', dot: 'bg-info' },
    active: { label: 'Active', dot: 'bg-warning' },
    completed: { label: 'Completed', dot: 'bg-brand' },
    canceled: { label: 'Canceled', dot: 'bg-destructive/70' },
};
</script>

<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { FolderKanban } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import RichTextEditor from '@/components/projects/editor/RichTextEditor.vue';
import {
    DatePicker,
    EntityPicker,
    MemberPicker,
} from '@/components/projects/pickers';
import ColorPicker from '@/components/settings/ColorPicker.vue';
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
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { api, HttpError } from '@/lib/http';
import type { IssueLookups } from '@/types';

const open = defineModel<boolean>('open', { default: false });
const props = defineProps<{
    lookups: IssueLookups | null;
    initiative?: Initiative | null;
}>();
const emit = defineEmits<{ saved: [initiative: Initiative] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const name = ref('');
const description = ref('');
const color = ref('violet');
const ownerId = ref<number | null>(null);
const status = ref<InitiativeStatus>('planned');
const startDate = ref<string | null>(null);
const targetDate = ref<string | null>(null);
const projectIds = ref<number[]>([]);
const busy = ref(false);
const errors = ref<Record<string, string[]>>({});

watch(open, (value) => {
    if (!value) {
        return;
    }

    errors.value = {};
    name.value = props.initiative?.name ?? '';
    description.value = props.initiative?.description_html ?? '';
    color.value = props.initiative?.color ?? 'violet';
    ownerId.value = props.initiative?.owner?.id ?? page.props.auth.user.id;
    status.value = props.initiative?.status ?? 'planned';
    startDate.value = props.initiative?.start_date ?? null;
    targetDate.value = props.initiative?.target_date ?? null;
    projectIds.value = props.initiative?.projects.map((p) => p.id) ?? [];
});

const owner = computed(() =>
    (props.lookups?.members ?? []).filter((m) => m.id === ownerId.value),
);
const projectOptions = computed(() =>
    (props.lookups?.projects ?? []).map((p) => ({
        id: p.id,
        name: p.name,
        color: p.color,
        hint: p.product?.key ?? null,
    })),
);
const selectedProjects = computed(() =>
    projectOptions.value.filter((p) => projectIds.value.includes(p.id)),
);

const submit = async () => {
    busy.value = true;
    errors.value = {};
    const payload = {
        name: name.value.trim(),
        description_html: description.value || null,
        color: color.value,
        owner_id: ownerId.value,
        status: status.value,
        start_date: startDate.value,
        target_date: targetDate.value,
        project_ids: projectIds.value,
    };

    try {
        const data = props.initiative
            ? await api.patch<{ initiative: Initiative }>(
                  `/${slug.value}/initiatives/${props.initiative.slug}`,
                  payload,
              )
            : await api.post<{ initiative: Initiative }>(
                  `/${slug.value}/initiatives`,
                  payload,
              );

        toast.success(
            props.initiative
                ? 'Initiative saved'
                : `${data.initiative.name} created`,
        );
        emit('saved', data.initiative);
        open.value = false;
    } catch (error) {
        if (error instanceof HttpError) {
            errors.value = error.errors;
        } else {
            toast.error('Could not save the initiative.');
        }
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-xl">
            <form class="contents" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle class="font-display text-2xl">{{
                        initiative ? 'Edit initiative' : 'New initiative'
                    }}</DialogTitle>
                    <DialogDescription
                        >A strategic grouping of projects with one outcome in
                        mind.</DialogDescription
                    >
                </DialogHeader>
                <div class="space-y-4">
                    <label class="block space-y-1.5 text-sm">
                        <span class="text-muted-foreground">Name</span>
                        <Input
                            v-model="name"
                            v-focus
                            placeholder="Platform modernization"
                            required
                        />
                        <p v-if="errors.name" class="text-xs text-destructive">
                            {{ errors.name[0] }}
                        </p>
                    </label>
                    <div class="space-y-1.5 text-sm">
                        <span class="text-muted-foreground">Description</span>
                        <div class="rounded-lg border px-3 py-1">
                            <RichTextEditor
                                v-model="description"
                                placeholder="Why does this matter?"
                                compact
                                min-height="4rem"
                                :members="lookups?.members ?? []"
                            />
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <MemberPicker
                            :selected="owner"
                            :members="lookups?.members ?? []"
                            placeholder="Owner…"
                            empty-label="Owner"
                            @change="
                                (users) => (ownerId = users[0]?.id ?? null)
                            "
                        />
                        <EntityPicker
                            :selected="selectedProjects"
                            :options="projectOptions"
                            :icon="FolderKanban"
                            multiple
                            placeholder="Add projects…"
                            empty-label="Projects"
                            @change="
                                (list) => (projectIds = list.map((p) => p.id))
                            "
                        />
                        <DatePicker
                            :value="startDate"
                            label="Start"
                            @change="(v) => (startDate = v)"
                        />
                        <DatePicker
                            :value="targetDate"
                            label="Target"
                            @change="(v) => (targetDate = v)"
                        />
                        <label class="flex items-center gap-2 text-sm">
                            <span class="text-muted-foreground">Status</span>
                            <NativeSelect v-model="status">
                                <NativeSelectOption
                                    v-for="(meta, key) in INITIATIVE_STATUS"
                                    :key="key"
                                    :value="key"
                                    >{{ meta.label }}</NativeSelectOption
                                >
                            </NativeSelect>
                        </label>
                    </div>
                    <div class="space-y-1.5 text-sm">
                        <span class="text-muted-foreground">Colour</span>
                        <ColorPicker v-model="color" />
                    </div>
                </div>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="ghost" @click="open = false"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="busy || !name.trim()">{{
                        initiative ? 'Save' : 'Create initiative'
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
