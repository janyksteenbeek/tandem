<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { MemberPicker, TeamPicker } from '@/components/projects/pickers';
import MembersEditor from '@/components/projects/workspace/MembersEditor.vue';
import WorkflowEditor from '@/components/projects/workflow/WorkflowEditor.vue';
import ColorPicker from '@/components/settings/ColorPicker.vue';
import ConfirmDialog from '@/components/settings/ConfirmDialog.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { api, reportError } from '@/lib/http';
import type {
    IssueLookups,
    Product,
    TeamSummary,
    UserSummary,
    Workflow,
} from '@/types';

const props = defineProps<{
    product: Product;
    workflow: Workflow;
    lookups: IssueLookups | null;
}>();
const emit = defineEmits<{
    updated: [product: Product];
    'update:workflow': [workflow: Workflow];
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const base = computed(() => `/${slug.value}/products/${props.product.slug}`);

const name = ref(props.product.name);
const key = ref(props.product.key);
const description = ref(props.product.description ?? '');
const color = ref(props.product.color);
const owner = ref<UserSummary[]>(
    props.product.owner ? [props.product.owner] : [],
);
const teams = ref<TeamSummary[]>(props.product.teams);
const busy = ref(false);
const errors = ref<Record<string, string[]>>({});

watch(
    () => props.product,
    (p) => {
        name.value = p.name;
        key.value = p.key;
        description.value = p.description ?? '';
        color.value = p.color;
        owner.value = p.owner ? [p.owner] : [];
        teams.value = p.teams;
    },
);

const save = async () => {
    busy.value = true;
    errors.value = {};

    try {
        const data = await api.patch<{ product: Product }>(base.value, {
            name: name.value.trim(),
            key: key.value.trim().toUpperCase(),
            description: description.value || null,
            color: color.value,
            owner_id: owner.value[0]?.id ?? null,
            team_ids: teams.value.map((t) => t.id),
        });

        emit('updated', data.product);
        toast.success('Product saved');

        if (data.product.slug !== props.product.slug) {
            router.visit(
                `/${slug.value}/products/${data.product.slug}/settings`,
            );
        }
    } catch (error) {
        if (error instanceof Error && 'errors' in error) {
            errors.value = (
                error as { errors: Record<string, string[]> }
            ).errors;
        }

        reportError(error);
    } finally {
        busy.value = false;
    }
};

const saveMembers = async (members: { user_id: number; role: string }[]) => {
    try {
        const data = await api.put<{ product: Product }>(
            `${base.value}/members`,
            { members },
        );
        emit('updated', data.product);
    } catch (error) {
        reportError(error);
    }
};

const creatingWorkflow = ref(false);

const createWorkflow = async () => {
    creatingWorkflow.value = true;

    try {
        const data = await api.post<{ workflow: Workflow }>(
            `${base.value}/workflow`,
        );
        emit('update:workflow', data.workflow);
        router.reload({ only: ['product', 'workflow', 'lookups'] });
        toast.success('Product workflow created');
    } catch (error) {
        reportError(error);
    } finally {
        creatingWorkflow.value = false;
    }
};

const archiveBusy = ref(false);

const toggleArchive = async () => {
    archiveBusy.value = true;

    try {
        const data = await api.patch<{ product: Product }>(base.value, {
            status: props.product.status === 'active' ? 'archived' : 'active',
        });
        emit('updated', data.product);
        toast.success(
            data.product.status === 'archived'
                ? 'Product archived'
                : 'Product restored',
        );
    } catch (error) {
        reportError(error);
    } finally {
        archiveBusy.value = false;
    }
};

const deleting = ref(false);
const confirmDelete = () =>
    router.delete(base.value, { onFinish: () => (deleting.value = false) });
</script>

<template>
    <div class="max-w-3xl space-y-12">
        <SettingsSection
            title="Product"
            description="Name, key and who looks after it."
        >
            <form class="space-y-4" @submit.prevent="save">
                <div class="grid gap-3 sm:grid-cols-[1fr_8rem]">
                    <label class="space-y-1.5 text-sm">
                        <span class="text-muted-foreground">Name</span>
                        <Input v-model="name" required />
                        <p v-if="errors.name" class="text-xs text-destructive">
                            {{ errors.name[0] }}
                        </p>
                    </label>
                    <label class="space-y-1.5 text-sm">
                        <span class="text-muted-foreground">Key</span>
                        <Input
                            :model-value="key"
                            class="font-mono uppercase"
                            maxlength="10"
                            required
                            @update:model-value="
                                (v) => (key = String(v).toUpperCase())
                            "
                        />
                        <p v-if="errors.key" class="text-xs text-destructive">
                            {{ errors.key[0] }}
                        </p>
                        <p v-else class="text-[11px] text-muted-foreground">
                            Existing identifiers keep their prefix.
                        </p>
                    </label>
                </div>
                <label class="block space-y-1.5 text-sm">
                    <span class="text-muted-foreground">Description</span>
                    <Textarea v-model="description" rows="3" />
                </label>
                <div class="space-y-1.5 text-sm">
                    <span class="text-muted-foreground">Colour</span>
                    <ColorPicker v-model="color" />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <MemberPicker
                        :selected="owner"
                        :members="lookups?.members ?? []"
                        placeholder="Owner…"
                        empty-label="Owner"
                        @change="(users) => (owner = users)"
                    />
                    <TeamPicker
                        :selected="teams"
                        :options="lookups?.teams ?? []"
                        multiple
                        placeholder="Teams…"
                        empty-label="Participating teams"
                        @change="(list) => (teams = list)"
                    />
                </div>
                <div>
                    <Button type="submit" :disabled="busy">Save product</Button>
                </div>
            </form>
        </SettingsSection>

        <SettingsSection
            title="Members"
            description="Admins can edit the product; members take part in it. Owner and teams already have access."
            :count="product.members.length"
        >
            <MembersEditor
                :members="product.members"
                :candidates="lookups?.members ?? []"
                :roles="[
                    { value: 'admin', label: 'Admin' },
                    { value: 'member', label: 'Member' },
                ]"
                :disabled="!product.can.manage_members"
                @change="saveMembers"
            />
        </SettingsSection>

        <SettingsSection
            title="Issue workflow"
            :description="
                product.issue_workflow_id
                    ? 'Overall statuses for issues in this product.'
                    : 'Issues in this product use the organization default workflow.'
            "
        >
            <template v-if="!product.issue_workflow_id" #actions>
                <Button
                    v-if="product.can.manage_workflow"
                    variant="outline"
                    size="sm"
                    :disabled="creatingWorkflow"
                    @click="createWorkflow"
                    >Create product-specific workflow</Button
                >
            </template>
            <WorkflowEditor
                :workflow="workflow"
                :can-manage="
                    product.can.manage_workflow &&
                    product.issue_workflow_id !== null
                "
                @update:workflow="(w) => emit('update:workflow', w)"
            />
        </SettingsSection>

        <SettingsSection v-if="product.can.delete" title="Danger zone">
            <div
                class="flex flex-wrap items-center gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3"
            >
                <p class="min-w-0 flex-1 text-[13px] text-muted-foreground">
                    {{
                        product.status === 'active'
                            ? 'Archiving hides the product from navigation; nothing is deleted.'
                            : 'This product is archived.'
                    }}
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="archiveBusy"
                    @click="toggleArchive"
                    >{{
                        product.status === 'active' ? 'Archive' : 'Restore'
                    }}</Button
                >
                <Button variant="destructive" size="sm" @click="deleting = true"
                    >Delete product</Button
                >
            </div>
        </SettingsSection>

        <ConfirmDialog
            v-model:open="deleting"
            :title="`Delete ${product.name}?`"
            description="Issues keep their identifiers but lose their product. Modules, releases and the product workflow are removed."
            confirm-label="Delete product"
            destructive
            @confirm="confirmDelete"
        />
    </div>
</template>
