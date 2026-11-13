<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Building2, Check, ChevronsUpDown, Plus, UserPlus } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useBranding } from '@/composables/useBranding';
import { useOrganizationSwitcher } from '@/composables/useOrganizationSwitcher';
import { monogram } from '@/lib/entityIcons';

/**
 * The top of every sidebar: which organization you are in, and the menu to switch,
 * open its settings or invite people. Without an organization it just shows the brand.
 */
const branding = useBranding();
const { organization, organizations, switchTo } = useOrganizationSwitcher();

const initial = (name: string) => monogram(name);
</script>

<template>
    <DropdownMenu v-if="organization">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="group/switcher flex h-10 w-full min-w-0 items-center gap-2.5 rounded-lg px-1.5 text-left transition-colors hover:bg-sidebar-accent"
                aria-label="Switch organization"
            >
                <span
                    class="flex size-7 shrink-0 items-center justify-center rounded-lg text-[11px] font-bold text-brand-foreground brand-button-surface transition-transform group-hover/switcher:scale-105"
                    >{{ initial(organization.name) }}</span
                >
                <span class="min-w-0 flex-1">
                    <span
                        class="block truncate font-display text-[14px] leading-tight text-sidebar-foreground"
                        >{{ organization.name }}</span
                    >
                    <span
                        class="block truncate text-[10.5px] leading-tight text-muted-foreground"
                        >{{
                            organizations.length > 1
                                ? `${organizations.length} workspaces`
                                : 'Workspace'
                        }}</span
                    >
                </span>
                <ChevronsUpDown
                    class="size-3.5 shrink-0 text-muted-foreground/60 transition-colors group-hover/switcher:text-muted-foreground"
                />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="w-64">
            <DropdownMenuLabel
                class="text-[11px] tracking-wide text-muted-foreground uppercase"
                >Organizations</DropdownMenuLabel
            >
            <DropdownMenuItem
                v-for="org in organizations"
                :key="org.id"
                class="gap-2"
                @click="switchTo(org.slug)"
            >
                <span
                    class="flex size-5 items-center justify-center rounded-md bg-brand-soft text-[10px] font-bold text-brand-soft-foreground"
                    >{{ initial(org.name) }}</span
                >
                <span class="flex-1 truncate">{{ org.name }}</span>
                <Check
                    v-if="org.id === organization.id"
                    class="size-3.5 text-brand"
                />
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem as-child>
                <Link
                    :href="`/settings/organizations/${organization.slug}`"
                    class="gap-2"
                    ><Building2 class="size-4" /> Organization settings</Link
                >
            </DropdownMenuItem>
            <DropdownMenuItem as-child>
                <Link
                    :href="`/settings/organizations/${organization.slug}#invite`"
                    class="gap-2"
                    ><UserPlus class="size-4" /> Invite people</Link
                >
            </DropdownMenuItem>
            <DropdownMenuItem as-child>
                <Link href="/settings/organizations" class="gap-2"
                    ><Plus class="size-4" /> New organization</Link
                >
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
    <div
        v-else
        class="flex h-10 items-center gap-2.5 px-1.5 font-display text-[14px] text-sidebar-foreground"
    >
        <span
            class="flex size-7 items-center justify-center rounded-lg text-[11px] font-bold text-brand-foreground brand-button-surface"
            >{{ initial(branding.name) }}</span
        >
        <span class="truncate">{{ branding.name }}</span>
    </div>
</template>
