<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { ref } from 'vue';
import Collapse from '@/components/Collapse.vue';
import WorkflowEditor from '@/components/projects/workflow/WorkflowEditor.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Kbd } from '@/components/ui/kbd';
import { cn } from '@/lib/utils';
import type { CycleSettings, EstimateScale, Workflow } from '@/types';

type ProductRow = {
    id: number;
    slug: string;
    name: string;
    key: string;
    color: string;
    next_number: number;
    has_own_workflow: boolean;
};
type TeamRow = {
    id: number;
    slug: string;
    name: string;
    color: string;
    workflow: Workflow;
    cycle_settings: CycleSettings;
    estimate_scale: EstimateScale;
};

const props = defineProps<{
    organization: { id: number; name: string; slug: string };
    issueKey: string | null;
    derivedKey: string;
    nextNumber: number;
    workflow: Workflow;
    products: ProductRow[];
    teams: TeamRow[];
}>();

const issueKey = ref(props.issueKey ?? '');
const saving = ref(false);
const workflow = ref(props.workflow);
const teamWorkflows = ref<Record<number, Workflow>>(
    Object.fromEntries(props.teams.map((t) => [t.id, t.workflow])),
);
const openTeams = ref<Record<number, boolean>>({});

const saveKey = () => {
    saving.value = true;
    router.patch(
        `/${props.organization.slug}/settings/projects`,
        { issue_key: issueKey.value.trim().toUpperCase() || null },
        { preserveScroll: true, onFinish: () => (saving.value = false) },
    );
};
</script>

<template>
    <Head title="Project settings" />

    <h1 class="sr-only">Project settings</h1>

    <SettingsSection
        title="Issue identifiers"
        description="Issues outside a product use the organization key; products use their own."
    >
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="saveKey">
            <label class="space-y-1.5 text-sm">
                <span class="text-muted-foreground">Organization key</span>
                <Input
                    :model-value="issueKey"
                    :placeholder="derivedKey"
                    class="w-36 font-mono uppercase"
                    maxlength="10"
                    @update:model-value="
                        (v) => (issueKey = String(v).toUpperCase())
                    "
                />
            </label>
            <p class="pb-2 text-[12.5px] text-muted-foreground">
                Next issue:
                <Kbd class="font-mono"
                    >{{ issueKey || derivedKey }}-{{ nextNumber }}</Kbd
                >
            </p>
            <Button type="submit" variant="outline" :disabled="saving"
                >Save key</Button
            >
        </form>
    </SettingsSection>

    <SettingsSection
        title="Default issue workflow"
        description="The overall statuses of issues, unless a product defines its own."
    >
        <WorkflowEditor
            :workflow="workflow"
            can-manage
            @update:workflow="(w) => (workflow = w)"
        />
    </SettingsSection>

    <SettingsSection
        title="Products"
        :count="products.length"
        description="Keys share one namespace with the organization key."
    >
        <p
            v-if="products.length === 0"
            class="text-[12.5px] text-muted-foreground"
        >
            No products yet.
            <Link
                :href="`/${organization.slug}/products?create=1`"
                class="text-brand hover:underline"
                >Create one</Link
            >.
        </p>
        <ul v-else class="divide-y rounded-lg border">
            <li
                v-for="product in products"
                :key="product.id"
                class="flex h-11 items-center gap-3 px-3 text-[13px]"
            >
                <InboxDot :color="product.color" size="sm" />
                <span class="min-w-0 flex-1 truncate font-medium">{{
                    product.name
                }}</span>
                <Kbd class="font-mono">{{ product.key }}</Kbd>
                <span
                    class="w-24 text-right text-[11.5px] text-muted-foreground tabular-nums"
                    >next {{ product.key }}-{{ product.next_number }}</span
                >
                <span
                    class="hidden w-28 text-[11.5px] text-muted-foreground sm:block"
                    >{{
                        product.has_own_workflow
                            ? 'Own workflow'
                            : 'Default workflow'
                    }}</span
                >
                <Link
                    :href="`/${organization.slug}/products/${product.slug}/settings`"
                    class="text-[12px] text-brand hover:underline"
                    >Settings</Link
                >
            </li>
        </ul>
    </SettingsSection>

    <SettingsSection
        title="Team workflows"
        :count="teams.length"
        description="Each team moves its workstreams through its own statuses."
    >
        <div class="space-y-2">
            <div v-for="team in teams" :key="team.id" class="rounded-lg border">
                <button
                    type="button"
                    class="flex h-11 w-full items-center gap-3 px-3 text-left text-[13px]"
                    :aria-expanded="openTeams[team.id] === true"
                    @click="openTeams[team.id] = !openTeams[team.id]"
                >
                    <ChevronRight
                        :class="
                            cn(
                                'size-3.5 text-muted-foreground transition-transform',
                                openTeams[team.id] && 'rotate-90',
                            )
                        "
                    />
                    <InboxDot :color="team.color" size="sm" />
                    <span class="min-w-0 flex-1 truncate font-medium">{{
                        team.name
                    }}</span>
                    <span class="text-[11.5px] text-muted-foreground"
                        >{{ teamWorkflows[team.id]?.statuses.length ?? 0 }}
                        statuses ·
                        {{
                            team.cycle_settings.enabled
                                ? `${team.cycle_settings.length_weeks}-week cycles`
                                : 'no cycles'
                        }}
                        · {{ team.estimate_scale }}</span
                    >
                    <Link
                        :href="`/${organization.slug}/teams/${team.slug}/settings`"
                        class="text-[12px] text-brand hover:underline"
                        @click.stop
                        >Team settings</Link
                    >
                </button>
                <Collapse :open="openTeams[team.id] === true">
                    <div class="border-t px-3 py-3">
                        <WorkflowEditor
                            :workflow="teamWorkflows[team.id]"
                            can-manage
                            @update:workflow="
                                (w) => (teamWorkflows[team.id] = w)
                            "
                        />
                    </div>
                </Collapse>
            </div>
        </div>
    </SettingsSection>
</template>
