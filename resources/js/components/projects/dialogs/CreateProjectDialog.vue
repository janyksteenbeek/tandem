<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Box, Layers } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import {
    DatePicker,
    EntityPicker,
    MemberPicker,
    TeamPicker,
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { api, HttpError } from '@/lib/http';
import { PROJECT_STATUS_META } from '@/lib/projects/meta';
import type {
    IssueLookups,
    Project,
    ProjectStatus,
    TeamSummary,
    UserSummary,
} from '@/types';

const open = defineModel<boolean>('open', { default: false });
const props = defineProps<{
    lookups: IssueLookups | null;
    productId?: number | null;
}>();
const emit = defineEmits<{ created: [project: Project] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const name = ref('');
const description = ref('');
const color = ref('violet');
const productId = ref<number | null>(null);
const moduleIds = ref<number[]>([]);
const lead = ref<UserSummary[]>([]);
const teams = ref<TeamSummary[]>([]);
const status = ref<ProjectStatus>('planned');
const isPrivate = ref(false);
const startDate = ref<string | null>(null);
const targetDate = ref<string | null>(null);
const busy = ref(false);
const errors = ref<Record<string, string[]>>({});

watch(open, (value) => {
    if (value) {
        name.value = '';
        description.value = '';
        color.value = 'violet';
        productId.value = props.productId ?? null;
        moduleIds.value = [];
        lead.value = (props.lookups?.members ?? []).filter(
            (m) => m.id === page.props.auth.user.id,
        );
        teams.value = [];
        status.value = 'planned';
        isPrivate.value = false;
        startDate.value = null;
        targetDate.value = null;
        errors.value = {};
    }
});

const product = computed(
    () => props.lookups?.products.find((p) => p.id === productId.value) ?? null,
);
const modules = computed(() =>
    (props.lookups?.modules ?? []).filter(
        (m) => m.product_id === productId.value,
    ),
);

const submit = async () => {
    busy.value = true;
    errors.value = {};

    try {
        const data = await api.post<{ project: Project }>(
            `/${slug.value}/projects`,
            {
                name: name.value.trim(),
                description_html: description.value
                    ? `<p>${description.value}</p>`
                    : null,
                color: color.value,
                product_id: productId.value,
                module_ids: moduleIds.value,
                lead_id: lead.value[0]?.id ?? null,
                team_ids: teams.value.map((t) => t.id),
                status: status.value,
                visibility: isPrivate.value ? 'private' : 'organization',
                start_date: startDate.value,
                target_date: targetDate.value,
            },
        );

        toast.success(`${data.project.name} created`);
        emit('created', data.project);
        open.value = false;
        router.visit(`/${slug.value}/projects/${data.project.slug}`);
    } catch (error) {
        if (error instanceof HttpError) {
            errors.value = error.errors;
        } else {
            toast.error('Could not create the project.');
        }
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-lg">
            <form class="contents" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle class="font-display text-2xl"
                        >New project</DialogTitle
                    >
                    <DialogDescription
                        >An initiative with a goal and a target date. Issues
                        deliver it; progress follows their
                        completion.</DialogDescription
                    >
                </DialogHeader>
                <div class="space-y-4">
                    <label class="block space-y-1.5 text-sm">
                        <span class="text-muted-foreground">Name</span>
                        <Input
                            v-model="name"
                            v-focus
                            placeholder="Reporting redesign"
                            required
                        />
                        <p v-if="errors.name" class="text-xs text-destructive">
                            {{ errors.name[0] }}
                        </p>
                    </label>
                    <label class="block space-y-1.5 text-sm">
                        <span class="text-muted-foreground">Goal</span>
                        <Textarea
                            v-model="description"
                            rows="2"
                            placeholder="What does done look like?"
                        />
                    </label>
                    <div class="flex flex-wrap items-center gap-2">
                        <EntityPicker
                            :selected="
                                product
                                    ? [
                                          {
                                              id: product.id,
                                              name: product.name,
                                              color: product.color,
                                          },
                                      ]
                                    : []
                            "
                            :options="
                                (lookups?.products ?? []).map((p) => ({
                                    id: p.id,
                                    name: p.name,
                                    color: p.color,
                                    hint: p.key,
                                }))
                            "
                            :icon="Box"
                            placeholder="Product…"
                            empty-label="Standalone"
                            @change="
                                (list) => {
                                    productId = list[0]?.id ?? null;
                                    moduleIds = [];
                                }
                            "
                        />
                        <EntityPicker
                            v-if="modules.length > 0"
                            :selected="
                                modules
                                    .filter((m) => moduleIds.includes(m.id))
                                    .map((m) => ({
                                        id: m.id,
                                        name: m.name,
                                        color: m.color,
                                    }))
                            "
                            :options="
                                modules.map((m) => ({
                                    id: m.id,
                                    name: m.name,
                                    color: m.color,
                                }))
                            "
                            :icon="Layers"
                            multiple
                            placeholder="Modules…"
                            empty-label="Modules"
                            @change="
                                (list) => (moduleIds = list.map((m) => m.id))
                            "
                        />
                        <MemberPicker
                            :selected="lead"
                            :members="lookups?.members ?? []"
                            placeholder="Lead…"
                            empty-label="Lead"
                            @change="(users) => (lead = users)"
                        />
                        <TeamPicker
                            :selected="teams"
                            :options="lookups?.teams ?? []"
                            multiple
                            placeholder="Teams…"
                            empty-label="Teams"
                            @change="(list) => (teams = list)"
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
                    </div>
                    <div class="flex flex-wrap items-center gap-4">
                        <label class="flex items-center gap-2 text-sm">
                            <span class="text-muted-foreground">Status</span>
                            <NativeSelect v-model="status">
                                <NativeSelectOption
                                    v-for="(meta, value) in PROJECT_STATUS_META"
                                    :key="value"
                                    :value="value"
                                    >{{ meta.label }}</NativeSelectOption
                                >
                            </NativeSelect>
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <Switch v-model="isPrivate" />
                            <span
                                >Private
                                <span class="text-muted-foreground"
                                    >(members and teams only)</span
                                ></span
                            >
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
                    <Button type="submit" :disabled="busy || !name.trim()"
                        >Create project</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
