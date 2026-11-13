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
import MembersEditor from '@/components/projects/workspace/MembersEditor.vue';
import ColorPicker from '@/components/settings/ColorPicker.vue';
import ConfirmDialog from '@/components/settings/ConfirmDialog.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { api, HttpError, reportError } from '@/lib/http';
import { PROJECT_HEALTH_META, PROJECT_STATUS_META } from '@/lib/projects/meta';
import type {
    IssueLookups,
    Project,
    ProjectHealth,
    ProjectStatus,
    TeamSummary,
    UserSummary,
} from '@/types';

const props = defineProps<{ project: Project; lookups: IssueLookups | null }>();
const emit = defineEmits<{ updated: [project: Project] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const base = computed(() => `/${slug.value}/projects/${props.project.slug}`);

const name = ref(props.project.name);
const color = ref(props.project.color);
const lead = ref<UserSummary[]>(props.project.lead ? [props.project.lead] : []);
const teams = ref<TeamSummary[]>(props.project.teams);
const status = ref<ProjectStatus>(props.project.status);
const health = ref<ProjectHealth>(props.project.health);
const priority = ref(props.project.priority);
const isPrivate = ref(props.project.visibility === 'private');
const startDate = ref(props.project.start_date);
const targetDate = ref(props.project.target_date);
const productId = ref(props.project.product?.id ?? null);
const moduleIds = ref(props.project.modules.map((m) => m.id));
const busy = ref(false);
const errors = ref<Record<string, string[]>>({});

watch(
    () => props.project,
    (p) => {
        name.value = p.name;
        color.value = p.color;
        lead.value = p.lead ? [p.lead] : [];
        teams.value = p.teams;
        status.value = p.status;
        health.value = p.health;
        priority.value = p.priority;
        isPrivate.value = p.visibility === 'private';
        startDate.value = p.start_date;
        targetDate.value = p.target_date;
        productId.value = p.product?.id ?? null;
        moduleIds.value = p.modules.map((m) => m.id);
    },
);

const product = computed(
    () => props.lookups?.products.find((p) => p.id === productId.value) ?? null,
);
const modules = computed(() =>
    (props.lookups?.modules ?? []).filter(
        (m) => m.product_id === productId.value,
    ),
);

const save = async () => {
    busy.value = true;
    errors.value = {};

    try {
        const data = await api.patch<{ project: Project }>(base.value, {
            name: name.value.trim(),
            color: color.value,
            lead_id: lead.value[0]?.id ?? null,
            team_ids: teams.value.map((t) => t.id),
            status: status.value,
            health: health.value,
            priority: priority.value,
            visibility: isPrivate.value ? 'private' : 'organization',
            start_date: startDate.value,
            target_date: targetDate.value,
            product_id: productId.value,
            module_ids: moduleIds.value,
        });

        emit('updated', data.project);
        toast.success('Project saved');

        if (data.project.slug !== props.project.slug) {
            router.visit(
                `/${slug.value}/projects/${data.project.slug}/settings`,
            );
        }
    } catch (error) {
        if (error instanceof HttpError) {
            errors.value = error.errors;
        }

        reportError(error);
    } finally {
        busy.value = false;
    }
};

const saveMembers = async (members: { user_id: number; role: string }[]) => {
    try {
        const data = await api.put<{ project: Project }>(
            `${base.value}/members`,
            { members },
        );
        emit('updated', data.project);
    } catch (error) {
        reportError(error);
    }
};

const deleting = ref(false);
const confirmDelete = () =>
    router.delete(base.value, { onFinish: () => (deleting.value = false) });
</script>

<template>
    <div class="max-w-3xl space-y-12">
        <SettingsSection
            title="Project"
            description="Goal, ownership, timeline and where it lives."
        >
            <form class="space-y-4" @submit.prevent="save">
                <label class="block space-y-1.5 text-sm">
                    <span class="text-muted-foreground">Name</span>
                    <Input v-model="name" required />
                    <p v-if="errors.name" class="text-xs text-destructive">
                        {{ errors.name[0] }}
                    </p>
                </label>
                <div class="flex flex-wrap items-center gap-2">
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
                        @change="(list) => (moduleIds = list.map((m) => m.id))"
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
                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <label class="flex items-center gap-2"
                        ><span class="text-muted-foreground">Status</span>
                        <NativeSelect v-model="status"
                            ><NativeSelectOption
                                v-for="(meta, value) in PROJECT_STATUS_META"
                                :key="value"
                                :value="value"
                                >{{ meta.label }}</NativeSelectOption
                            ></NativeSelect
                        >
                    </label>
                    <label class="flex items-center gap-2"
                        ><span class="text-muted-foreground">Health</span>
                        <NativeSelect v-model="health"
                            ><NativeSelectOption
                                v-for="(meta, value) in PROJECT_HEALTH_META"
                                :key="value"
                                :value="value"
                                >{{ meta.label }}</NativeSelectOption
                            ></NativeSelect
                        >
                    </label>
                    <label class="flex items-center gap-2"
                        ><span class="text-muted-foreground">Priority</span>
                        <NativeSelect v-model="priority">
                            <NativeSelectOption :value="0"
                                >None</NativeSelectOption
                            ><NativeSelectOption :value="1"
                                >Low</NativeSelectOption
                            ><NativeSelectOption :value="2"
                                >Medium</NativeSelectOption
                            ><NativeSelectOption :value="3"
                                >High</NativeSelectOption
                            ><NativeSelectOption :value="4"
                                >Urgent</NativeSelectOption
                            >
                        </NativeSelect>
                    </label>
                    <label class="flex items-center gap-2"
                        ><Switch v-model="isPrivate" /> Private</label
                    >
                </div>
                <div class="space-y-1.5 text-sm">
                    <span class="text-muted-foreground">Colour</span>
                    <ColorPicker v-model="color" />
                </div>
                <div>
                    <Button type="submit" :disabled="busy">Save project</Button>
                </div>
            </form>
        </SettingsSection>

        <SettingsSection
            title="Members"
            description="Private projects are visible to the lead, members and participating teams."
            :count="project.members.length"
        >
            <MembersEditor
                :members="project.members"
                :candidates="lookups?.members ?? []"
                :roles="[
                    { value: 'lead', label: 'Lead' },
                    { value: 'member', label: 'Member' },
                ]"
                :disabled="!project.can.manage_members"
                @change="saveMembers"
            />
        </SettingsSection>

        <SettingsSection v-if="project.can.delete" title="Danger zone">
            <div
                class="flex flex-wrap items-center gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3"
            >
                <p class="min-w-0 flex-1 text-[13px] text-muted-foreground">
                    Deleting the project leaves its issues in place without a
                    project.
                </p>
                <Button variant="destructive" size="sm" @click="deleting = true"
                    >Delete project</Button
                >
            </div>
        </SettingsSection>

        <ConfirmDialog
            v-model:open="deleting"
            :title="`Delete ${project.name}?`"
            description="Issues keep their identifiers and history; milestones are removed."
            confirm-label="Delete project"
            destructive
            @confirm="confirmDelete"
        />
    </div>
</template>
