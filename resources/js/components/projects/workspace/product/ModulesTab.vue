<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Layers,
    MoreHorizontal,
    Pencil,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ModuleDialog from '@/components/projects/dialogs/ModuleDialog.vue';
import ConfirmDialog from '@/components/settings/ConfirmDialog.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { api, reportError } from '@/lib/http';
import { cn } from '@/lib/utils';
import type { IssueLookups, ModuleSummary, Product } from '@/types';

const props = defineProps<{ product: Product; lookups: IssueLookups | null }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const base = computed(() => `/${slug.value}/products/${props.product.slug}`);

type Node = ModuleSummary & { depth: number; siblings: number; index: number };

const tree = computed<Node[]>(() => {
    const byParent = new Map<number | null, ModuleSummary[]>();

    for (const module of props.product.modules) {
        const list = byParent.get(module.parent_id) ?? [];
        list.push(module);
        byParent.set(module.parent_id, list);
    }

    const rows: Node[] = [];
    const walk = (parent: number | null, depth: number) => {
        const children = (byParent.get(parent) ?? []).sort(
            (a, b) => a.position - b.position || a.name.localeCompare(b.name),
        );
        children.forEach((module, index) => {
            rows.push({ ...module, depth, siblings: children.length, index });
            walk(module.id, depth + 1);
        });
    };

    walk(null, 0);

    return rows;
});

const dialogOpen = ref(false);
const editing = ref<ModuleSummary | null>(null);
const parentForNew = ref<number | null>(null);
const removing = ref<ModuleSummary | null>(null);
const busy = ref(false);

const create = (parentId: number | null = null) => {
    editing.value = null;
    parentForNew.value = parentId;
    dialogOpen.value = true;
};

const edit = (module: ModuleSummary) => {
    editing.value = module;
    dialogOpen.value = true;
};

const reload = () => router.reload({ only: ['product', 'lookups'] });

const move = async (module: Node, direction: -1 | 1) => {
    const siblings = tree.value
        .filter((m) => m.parent_id === module.parent_id)
        .map((m) => m.id);
    const index = siblings.indexOf(module.id);
    const target = index + direction;

    if (target < 0 || target >= siblings.length) {
        return;
    }

    siblings.splice(index, 1);
    siblings.splice(target, 0, module.id);

    try {
        await api.post(`${base.value}/modules/reorder`, { ids: siblings });
        reload();
    } catch (error) {
        reportError(error);
    }
};

const confirmRemove = async () => {
    if (!removing.value) {
        return;
    }

    busy.value = true;

    try {
        await api.delete(`${base.value}/modules/${removing.value.slug}`);
        toast.success(`${removing.value.name} deleted`);
        removing.value = null;
        reload();
    } catch (error) {
        reportError(error);
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <div class="max-w-3xl">
        <div class="mb-3 flex items-center justify-between">
            <p class="text-[12.5px] text-muted-foreground">
                Modules are the permanent functional areas of
                {{ product.name }}. Issues and projects can touch several at
                once.
            </p>
            <Button
                v-if="product.can.update"
                size="sm"
                class="gap-1.5"
                @click="create()"
                ><Plus class="size-3.5" /> New module</Button
            >
        </div>

        <div
            v-if="tree.length === 0"
            class="flex flex-col items-center rounded-2xl border border-dashed bg-surface px-6 py-10 text-center"
        >
            <span
                class="mb-3 flex size-11 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground"
                ><Layers class="size-5"
            /></span>
            <p class="font-display text-2xl">No modules yet.</p>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                Reporting, CRM, Integrations — the parts of the product people
                recognise.
            </p>
            <Button
                v-if="product.can.update"
                class="mt-4 gap-1.5"
                @click="create()"
                ><Plus class="size-3.5" /> Add a module</Button
            >
        </div>

        <ul v-else class="space-y-px">
            <li
                v-for="module in tree"
                :key="module.id"
                class="group/module flex h-10 items-center gap-2 rounded-lg px-2 hover:bg-accent/60"
                :style="{ paddingLeft: `${8 + module.depth * 20}px` }"
            >
                <InboxDot :color="module.color ?? 'slate'" size="md" />
                <Link
                    :href="`${base}/modules/${module.slug}`"
                    class="min-w-0 flex-1 truncate text-[13.5px] font-medium hover:underline"
                    >{{ module.name }}</Link
                >
                <span
                    class="hidden text-[11.5px] text-muted-foreground sm:block"
                    ><span class="font-semibold text-foreground tabular-nums">{{
                        module.open_issues_count
                    }}</span>
                    open</span
                >
                <span class="flex items-center -space-x-0.5"
                    ><InboxDot
                        v-for="team in module.teams ?? []"
                        :key="team.id"
                        :color="team.color"
                        size="sm"
                /></span>
                <UserAvatar
                    v-if="module.owner"
                    :name="module.owner.name"
                    :email="module.owner.email"
                    :src="module.owner.avatar"
                    :initials="module.owner.initials"
                    size="xs"
                />
                <template v-if="product.can.update">
                    <Button
                        variant="ghost"
                        size="icon"
                        :class="
                            cn(
                                'size-6 text-muted-foreground/0 group-hover/module:text-muted-foreground',
                            )
                        "
                        :disabled="module.index === 0"
                        aria-label="Move up"
                        @click="move(module, -1)"
                        ><ArrowUp class="size-3.5"
                    /></Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        :class="
                            cn(
                                'size-6 text-muted-foreground/0 group-hover/module:text-muted-foreground',
                            )
                        "
                        :disabled="module.index === module.siblings - 1"
                        aria-label="Move down"
                        @click="move(module, 1)"
                        ><ArrowDown class="size-3.5"
                    /></Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child
                            ><Button
                                variant="ghost"
                                size="icon"
                                class="size-6 text-muted-foreground"
                                aria-label="Module actions"
                                ><MoreHorizontal class="size-3.5" /></Button
                        ></DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-44">
                            <DropdownMenuItem
                                class="gap-2"
                                @click="edit(module)"
                                ><Pencil class="size-3.5" />
                                Edit</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                class="gap-2"
                                @click="create(module.id)"
                                ><Plus class="size-3.5" /> Add
                                submodule</DropdownMenuItem
                            >
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                class="gap-2 text-destructive"
                                @click="removing = module"
                                ><Trash2 class="size-3.5" />
                                Delete</DropdownMenuItem
                            >
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </li>
        </ul>

        <ModuleDialog
            v-model:open="dialogOpen"
            :product="product"
            :module="editing"
            :parent-id="parentForNew"
            :lookups="lookups"
            @saved="reload"
        />
        <ConfirmDialog
            :open="removing !== null"
            title="Delete this module?"
            :description="`Issues keep existing; they simply stop referencing ${removing?.name ?? 'it'}. Submodules move up one level.`"
            confirm-label="Delete module"
            destructive
            :busy="busy"
            @update:open="(v) => !v && (removing = null)"
            @confirm="confirmRemove"
        />
    </div>
</template>
