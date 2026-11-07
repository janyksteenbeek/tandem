<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Bell,
    Check,
    Download,
    Keyboard,
    LogOut,
    Monitor,
    Moon,
    PanelLeftClose,
    PanelLeftOpen,
    Sun,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import NotificationsPopover from '@/components/shared/NotificationsPopover.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAppearance } from '@/composables/useAppearance';
import { useBranding } from '@/composables/useBranding';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useOrganizationSwitcher } from '@/composables/useOrganizationSwitcher';
import { forgetPushSubscription } from '@/composables/usePushNotifications';
import { useInstallPrompt } from '@/composables/usePwa';
import { useSidebarState } from '@/composables/useSidebarState';
import {
    homeFor,
    railItems,
    rememberedUrls,
    sectionForPath,
} from '@/lib/navigation';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import type { Appearance } from '@/types';

/**
 * The rail switches between the three areas of the app. Each item carries a label so nobody
 * has to guess an icon, and takes you back to the page you last had open in that area.
 */
const branding = useBranding();

const emit = defineEmits<{ openShortcuts: [] }>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const { organization, organizations, switchTo } = useOrganizationSwitcher();
const { currentUrl } = useCurrentUrl();
const { appearance, updateAppearance } = useAppearance();
const { canInstall, install } = useInstallPrompt();
const sidebar = useSidebarState();

const slug = computed(() => organization.value?.slug ?? null);
const activeSection = computed(() =>
    sectionForPath(currentUrl.value, slug.value),
);

const items = computed(() =>
    railItems(
        slug.value,
        {
            mail:
                page.props.mailNav?.assigned_to_me ||
                page.props.mailNav?.personal_unread ||
                null,
            projects: page.props.projectsNav?.counts.due_today ?? null,
        },
        rememberedUrls.value,
    ).map((item) => ({
        ...item,
        active: activeSection.value === item.section,
    })),
);

const primary = computed(() =>
    items.value.filter((item) => item.section !== 'settings'),
);
const settings = computed(() =>
    items.value.find((item) => item.section === 'settings'),
);

const signOut = async () => {
    await forgetPushSubscription();
    router.post(logout().url);
};

const itemClass =
    'relative flex h-12 w-14 flex-col items-center justify-center gap-1 rounded-xl text-sidebar-foreground/70 transition-[background-color,color,transform] hover:bg-foreground/[0.06] hover:text-sidebar-accent-foreground active:scale-[0.97]';
const activeClass =
    'vt-rail-active bg-foreground/[0.08] text-sidebar-accent-foreground';
const labelClass = 'text-[10px] leading-none font-medium tracking-[0.01em]';
const badgeClass =
    'absolute top-1 right-2 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand px-1 text-[10px] font-semibold text-brand-foreground tabular-nums';
</script>

<template>
    <nav
        class="flex h-full w-16 shrink-0 flex-col items-center border-r border-sidebar-border py-2.5 shell-rail-surface"
        aria-label="Primary"
    >
        <Link
            :href="slug ? homeFor('mail', slug) : '/'"
            class="flex size-10 items-center justify-center rounded-xl transition-transform hover:scale-105 active:scale-95"
            :aria-label="`${branding.name} home`"
        >
            <BrandMark :size="26" />
        </Link>

        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="mt-1 hidden size-7 items-center justify-center rounded-md text-sidebar-foreground/50 transition-colors hover:bg-foreground/[0.06] hover:text-sidebar-accent-foreground md:flex"
                    :aria-label="
                        sidebar.collapsed.value
                            ? 'Show sidebar'
                            : 'Hide sidebar'
                    "
                    :aria-expanded="!sidebar.collapsed.value"
                    aria-controls="app-shell-column"
                    @click="sidebar.toggle()"
                >
                    <PanelLeftOpen
                        v-if="sidebar.collapsed.value"
                        class="size-4"
                    />
                    <PanelLeftClose v-else class="size-4" />
                </button>
            </TooltipTrigger>
            <TooltipContent side="right">
                {{ sidebar.collapsed.value ? 'Show' : 'Hide' }} sidebar
                <kbd class="ml-1 rounded border px-1 text-[10px]">⌘\</kbd>
            </TooltipContent>
        </Tooltip>

        <div class="mt-3 flex flex-col items-center gap-1">
            <Link
                v-for="item in primary"
                :key="item.section"
                :href="item.href"
                :class="cn(itemClass, item.active && activeClass)"
                :aria-current="item.active ? 'page' : undefined"
                :data-section="item.section"
            >
                <span
                    v-if="item.active"
                    class="absolute top-1/2 -left-1 h-6 w-[3px] -translate-y-1/2 rounded-r-full bg-brand"
                />
                <component :is="item.icon" class="size-5" />
                <span :class="labelClass">{{ item.title }}</span>
                <span v-if="item.badge" :class="badgeClass">{{
                    item.badge > 99 ? '99+' : item.badge
                }}</span>
            </Link>
        </div>

        <div class="mt-auto flex flex-col items-center gap-1">
            <Link
                v-if="settings"
                :href="settings.href"
                :class="cn(itemClass, settings.active && activeClass)"
                :aria-current="settings.active ? 'page' : undefined"
                data-section="settings"
            >
                <span
                    v-if="settings.active"
                    class="absolute top-1/2 -left-1 h-6 w-[3px] -translate-y-1/2 rounded-r-full bg-brand"
                />
                <component :is="settings.icon" class="size-5" />
                <span :class="labelClass">{{ settings.title }}</span>
            </Link>

            <NotificationsPopover>
                <template #trigger="{ unread }">
                    <button
                        type="button"
                        :class="itemClass"
                        aria-label="Notifications"
                    >
                        <Bell class="size-5" />
                        <span :class="labelClass">Alerts</span>
                        <span v-if="unread > 0" :class="badgeClass">{{
                            unread > 99 ? '99+' : unread
                        }}</span>
                    </button>
                </template>
            </NotificationsPopover>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="mt-1 mb-1 rounded-full ring-offset-sidebar transition hover:ring-2 hover:ring-brand/40 hover:ring-offset-2"
                        aria-label="Account menu"
                    >
                        <UserAvatar
                            :name="user.name"
                            :email="user.email"
                            :src="user.avatar ?? null"
                            :initials="(user.initials as string) ?? null"
                            size="md"
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent side="right" align="end" class="w-64">
                    <DropdownMenuLabel class="flex items-center gap-2">
                        <UserAvatar
                            :name="user.name"
                            :email="user.email"
                            :src="user.avatar ?? null"
                            size="sm"
                        />
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium">{{
                                user.name
                            }}</span>
                            <span
                                class="block truncate text-xs font-normal text-muted-foreground"
                                >{{ user.email }}</span
                            >
                        </span>
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem as-child>
                        <Link :href="editProfile()" class="gap-2"
                            ><UserRound class="size-4" /> Profile</Link
                        >
                    </DropdownMenuItem>
                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger class="gap-2">
                            <Sun v-if="appearance === 'light'" class="size-4" />
                            <Moon
                                v-else-if="appearance === 'dark'"
                                class="size-4"
                            />
                            <Monitor v-else class="size-4" />
                            Appearance
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent>
                            <DropdownMenuRadioGroup
                                :model-value="appearance"
                                @update:model-value="
                                    (value) =>
                                        updateAppearance(value as Appearance)
                                "
                            >
                                <DropdownMenuRadioItem value="light"
                                    >Light</DropdownMenuRadioItem
                                >
                                <DropdownMenuRadioItem value="dark"
                                    >Dark</DropdownMenuRadioItem
                                >
                                <DropdownMenuRadioItem value="system"
                                    >System</DropdownMenuRadioItem
                                >
                            </DropdownMenuRadioGroup>
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
                    <DropdownMenuSub v-if="organizations.length > 1">
                        <DropdownMenuSubTrigger class="gap-2">
                            <span
                                class="flex size-4 items-center justify-center rounded bg-brand-soft text-[9px] font-bold text-brand-soft-foreground"
                                >{{
                                    (organization?.name ?? '?')
                                        .slice(0, 1)
                                        .toUpperCase()
                                }}</span
                            >
                            Switch organization
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent class="w-56">
                            <DropdownMenuItem
                                v-for="org in organizations"
                                :key="org.id"
                                class="gap-2"
                                @click="switchTo(org.slug)"
                            >
                                <span class="flex-1 truncate">{{
                                    org.name
                                }}</span>
                                <Check
                                    v-if="org.id === organization?.id"
                                    class="size-3.5 text-brand"
                                />
                            </DropdownMenuItem>
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
                    <DropdownMenuItem
                        class="gap-2"
                        @click="emit('openShortcuts')"
                    >
                        <Keyboard class="size-4" /> Keyboard shortcuts
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="canInstall"
                        class="gap-2"
                        @click="install"
                    >
                        <Download class="size-4" /> Install app
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem class="gap-2" @click="signOut">
                        <LogOut class="size-4" /> Log out
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </nav>
</template>
