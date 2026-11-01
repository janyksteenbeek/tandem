<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Bell,
    Download,
    Inbox,
    Keyboard,
    LogOut,
    Moon,
    Search,
    Settings,
    Sun,
    SunMoon,
    Zap,
} from '@lucide/vue';
import { computed } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import NotificationsPopover from '@/components/mail/NotificationsPopover.vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
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
import { forgetPushSubscription } from '@/composables/usePushNotifications';
import { useInstallPrompt } from '@/composables/usePwa';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';

const branding = useBranding();

const emit = defineEmits<{ openSearch: []; openShortcuts: [] }>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const organization = computed(() => page.props.currentOrganization);
const organizations = computed(() => page.props.organizations ?? []);
const { isCurrentOrParentUrl } = useCurrentUrl();
const { appearance, updateAppearance } = useAppearance();
const { canInstall, install } = useInstallPrompt();

const mailUrl = computed(() =>
    organization.value ? `/${organization.value.slug}/mail` : '/',
);
const orgSettingsUrl = computed(() =>
    organization.value ? `/${organization.value.slug}/settings` : null,
);

/** Two places to be: your mail, or the settings behind it. Everything else is a shortcut inside one of those. */
const items = computed(() => [
    {
        title: 'Mail',
        href: mailUrl.value,
        icon: Inbox,
        active: isCurrentOrParentUrl(mailUrl.value),
    },
    {
        title: 'Settings',
        href: '/settings/mail-accounts',
        icon: Settings,
        active:
            isCurrentOrParentUrl('/settings') ||
            (orgSettingsUrl.value !== null &&
                isCurrentOrParentUrl(orgSettingsUrl.value)),
    },
]);

const switchOrganization = (slug: string) => {
    router.post(
        `/settings/organizations/${slug}/switch`,
        {},
        {
            onFinish: () => router.visit(`/${slug}/mail`),
        },
    );
};

const signOut = async () => {
    await forgetPushSubscription();
    router.post(logout().url);
};

const cycleAppearance = () => {
    const next =
        appearance.value === 'light'
            ? 'dark'
            : appearance.value === 'dark'
              ? 'system'
              : 'light';
    updateAppearance(next);
};
</script>

<template>
    <nav
        class="flex h-full w-14 shrink-0 flex-col items-center border-r border-sidebar-border bg-sidebar py-3"
        aria-label="Primary"
    >
        <Link
            :href="mailUrl"
            class="mb-3 rounded-xl transition-transform hover:scale-105 active:scale-95"
            :aria-label="`${branding.name} home`"
        >
            <BrandMark :size="26" />
        </Link>

        <div class="flex flex-col items-center gap-1">
            <template v-for="item in items" :key="item.title">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Link
                            :href="item.href"
                            :class="
                                cn(
                                    'relative flex size-10 items-center justify-center rounded-xl text-sidebar-foreground/70 transition-[background-color,color,transform] hover:bg-sidebar-accent hover:text-sidebar-accent-foreground active:scale-95',
                                    item.active &&
                                        'bg-sidebar-accent text-sidebar-accent-foreground',
                                )
                            "
                            :aria-current="item.active ? 'page' : undefined"
                        >
                            <span
                                v-if="item.active"
                                class="absolute top-1/2 -left-2 h-5 w-[3px] -translate-y-1/2 animate-in rounded-r-full bg-brand duration-200 fade-in slide-in-from-left-1"
                            />
                            <component :is="item.icon" class="size-[18px]" />
                        </Link>
                    </TooltipTrigger>
                    <TooltipContent side="right">{{
                        item.title
                    }}</TooltipContent>
                </Tooltip>
            </template>

            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="flex size-10 items-center justify-center rounded-xl text-sidebar-foreground/70 transition-[background-color,color,transform] hover:bg-sidebar-accent hover:text-sidebar-accent-foreground active:scale-95"
                        @click="emit('openSearch')"
                    >
                        <Search class="size-[18px]" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="right"
                    >Search
                    <kbd class="ml-1 rounded border px-1 text-[10px]"
                        >⌘K</kbd
                    ></TooltipContent
                >
            </Tooltip>
        </div>

        <div class="mt-auto flex flex-col items-center gap-1">
            <NotificationsPopover>
                <template #trigger="{ unread }">
                    <button
                        type="button"
                        class="relative flex size-10 items-center justify-center rounded-xl text-sidebar-foreground/70 transition-[background-color,color,transform] hover:bg-sidebar-accent hover:text-sidebar-accent-foreground active:scale-95"
                        aria-label="Notifications"
                    >
                        <Bell class="size-[18px]" />
                        <span
                            v-if="unread > 0"
                            class="absolute top-1.5 right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand px-1 text-[10px] font-semibold text-brand-foreground"
                        >
                            {{ unread > 9 ? '9+' : unread }}
                        </span>
                    </button>
                </template>
            </NotificationsPopover>

            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="flex size-10 items-center justify-center rounded-xl text-sidebar-foreground/70 transition-[background-color,color,transform] hover:bg-sidebar-accent hover:text-sidebar-accent-foreground active:scale-95"
                        @click="cycleAppearance"
                    >
                        <Sun
                            v-if="appearance === 'light'"
                            class="size-[18px]"
                        />
                        <Moon
                            v-else-if="appearance === 'dark'"
                            class="size-[18px]"
                        />
                        <SunMoon v-else class="size-[18px]" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="right"
                    >Appearance: {{ appearance }}</TooltipContent
                >
            </Tooltip>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="mt-1 rounded-full ring-offset-sidebar transition hover:ring-2 hover:ring-brand/40 hover:ring-offset-2"
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
                    <DropdownMenuLabel
                        class="text-[11px] tracking-wide text-muted-foreground uppercase"
                        >Organizations</DropdownMenuLabel
                    >
                    <DropdownMenuItem
                        v-for="org in organizations"
                        :key="org.id"
                        class="gap-2"
                        @click="
                            org.id === organization?.id
                                ? undefined
                                : switchOrganization(org.slug)
                        "
                    >
                        <span
                            class="flex size-5 items-center justify-center rounded-md bg-brand-soft text-[10px] font-bold text-brand-soft-foreground"
                            >{{ org.name.slice(0, 1).toUpperCase() }}</span
                        >
                        <span class="flex-1 truncate">{{ org.name }}</span>
                        <Zap
                            v-if="org.id === organization?.id"
                            class="size-3.5 text-brand"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem as-child>
                        <Link :href="editProfile()" class="gap-2"
                            ><Settings class="size-4" /> Profile &
                            settings</Link
                        >
                    </DropdownMenuItem>
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
