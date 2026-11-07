<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Bell,
    BellOff,
    Check,
    Copy,
    FolderInput,
    GitBranchPlus,
    Link as LinkIcon,
    MoreHorizontal,
    Trash2,
} from '@lucide/vue';
import { computed, inject, ref } from 'vue';
import { toast } from 'vue-sonner';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { CreateIssuePrefill, Issue } from '@/types';

/**
 * The bar above an issue: where it lives (the same trail the sidebar uses: Projects › project,
 * Products › product, or Issues), its identifier, and the issue-level actions.
 */
const props = defineProps<{ issue: Issue; orgSlug: string }>();
const emit = defineEmits<{
    follow: [following: boolean];
    move: [];
    delete: [];
}>();

const openCreateIssue = inject<(prefill?: CreateIssuePrefill) => void>(
    'openCreateIssue',
    () => {},
);

type Crumb = { label: string; href: string; mono?: boolean };

const crumbs = computed<Crumb[]>(() => {
    const base = `/${props.orgSlug}`;
    const list: Crumb[] = [];

    if (props.issue.project) {
        list.push({ label: 'Projects', href: `${base}/projects/all` });
        list.push({
            label: props.issue.project.name,
            href: `${base}/projects/${props.issue.project.slug}`,
        });
    } else if (props.issue.product) {
        list.push({ label: 'Products', href: `${base}/products` });
        list.push({
            label: props.issue.product.name,
            href: `${base}/products/${props.issue.product.slug}`,
        });
    } else {
        list.push({ label: 'Issues', href: `${base}/issues` });
    }

    if (props.issue.parent) {
        list.push({
            label: props.issue.parent.identifier,
            href: `${base}/issues/${props.issue.parent.identifier}`,
            mono: true,
        });
    }

    return list;
});

const link = computed(
    () =>
        `${typeof window !== 'undefined' ? window.location.origin : ''}/${props.orgSlug}/issues/${props.issue.identifier}`,
);

const copied = ref(false);

const copy = async (text: string, label: string) => {
    await navigator.clipboard.writeText(text);
    toast.success(`${label} copied`);
};

const copyLink = async () => {
    await navigator.clipboard.writeText(link.value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
};

const createSubIssue = () =>
    openCreateIssue({
        parent_identifier: props.issue.identifier,
        product_id: props.issue.product?.id ?? null,
        project_id: props.issue.project?.id ?? null,
        team_ids: props.issue.teams.map((t) => t.id),
    });
</script>

<template>
    <div
        class="flex h-11 shrink-0 items-center gap-2 border-b bg-background/95 px-4 pl-12 backdrop-blur sm:px-5 md:pl-5"
    >
        <Breadcrumb class="min-w-0 flex-1">
            <BreadcrumbList class="flex-nowrap gap-1 text-[12px] sm:gap-1.5">
                <template v-for="crumb in crumbs" :key="crumb.href">
                    <BreadcrumbItem class="min-w-0">
                        <BreadcrumbLink as-child>
                            <Link
                                :href="crumb.href"
                                :class="
                                    crumb.mono
                                        ? 'font-mono text-[11px]'
                                        : 'truncate'
                                "
                                >{{ crumb.label }}</Link
                            >
                        </BreadcrumbLink>
                    </BreadcrumbItem>
                    <BreadcrumbSeparator class="[&>svg]:size-3" />
                </template>
                <BreadcrumbItem>
                    <BreadcrumbPage class="font-mono text-[11px] font-medium">{{
                        issue.identifier
                    }}</BreadcrumbPage>
                </BreadcrumbItem>
            </BreadcrumbList>
        </Breadcrumb>

        <Tooltip>
            <TooltipTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-7 text-muted-foreground"
                    aria-label="Copy link"
                    @click="copyLink"
                >
                    <Check v-if="copied" class="size-3.5 text-success" />
                    <LinkIcon v-else class="size-3.5" />
                </Button>
            </TooltipTrigger>
            <TooltipContent>{{
                copied ? 'Copied' : 'Copy link'
            }}</TooltipContent>
        </Tooltip>
        <Button
            variant="ghost"
            size="sm"
            class="h-7 gap-1.5 px-2 text-[12px] text-muted-foreground lg:hidden"
            @click="emit('follow', !issue.is_following)"
        >
            <BellOff v-if="issue.is_following" class="size-3.5" />
            <Bell v-else class="size-3.5" />
            {{ issue.is_following ? 'Following' : 'Follow' }}
        </Button>
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-7 shrink-0"
                    aria-label="More actions"
                >
                    <MoreHorizontal class="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-56">
                <DropdownMenuItem
                    class="gap-2"
                    @click="copy(issue.identifier, 'ID')"
                >
                    <Copy class="size-4" /> Copy ID
                    <DropdownMenuShortcut>⌘⇧,</DropdownMenuShortcut>
                </DropdownMenuItem>
                <DropdownMenuItem class="gap-2" @click="copy(link, 'Link')">
                    <LinkIcon class="size-4" /> Copy link
                    <DropdownMenuShortcut>⌘.</DropdownMenuShortcut>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem class="gap-2" @click="createSubIssue">
                    <GitBranchPlus class="size-4" /> Create sub-issue
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="issue.can.move"
                    class="gap-2"
                    @click="emit('move')"
                >
                    <FolderInput class="size-4" /> Move to project…
                </DropdownMenuItem>
                <template v-if="issue.can.delete">
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        class="gap-2 text-destructive focus:text-destructive"
                        @click="emit('delete')"
                    >
                        <Trash2 class="size-4" /> Delete issue
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
