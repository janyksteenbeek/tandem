<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    Inbox,
    Mail,
    Settings,
    UserCheck,
    Workflow,
} from '@lucide/vue';
import { computed } from 'vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index as automations } from '@/routes/automations';
import { home as mailHome } from '@/routes/mail';
import { index as organizations } from '@/routes/organizations';
import { edit as profile } from '@/routes/profile';
import type { DashboardInvitation, Organization } from '@/types';

defineProps<{
    pendingInvitations?: DashboardInvitation[];
}>();

defineOptions({
    layout: (props: { currentOrganization?: Organization | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: props.currentOrganization
                    ? dashboard(props.currentOrganization.slug)
                    : '/',
            },
        ],
    }),
});

const page = usePage();
const organization = computed(() => page.props.currentOrganization);
const nav = computed(() => page.props.mailNav);
const firstName = computed(() => page.props.auth.user.name.split(' ')[0]);
const mailUrl = computed(() =>
    organization.value ? mailHome(organization.value.slug).url : '/',
);

const greeting = computed(() => {
    const hour = new Date().getHours();

    if (hour < 5) {
        return 'Still up';
    }

    if (hour < 12) {
        return 'Good morning';
    }

    if (hour < 18) {
        return 'Good afternoon';
    }

    return 'Good evening';
});

const stats = computed(() => [
    {
        label: 'Unread in your mail',
        value: nav.value?.personal_unread ?? 0,
        icon: Mail,
    },
    {
        label: 'Assigned to you',
        value: nav.value?.assigned_to_me ?? 0,
        icon: UserCheck,
    },
    {
        label: 'Shared inboxes',
        value: nav.value?.shared.length ?? 0,
        icon: Inbox,
    },
]);
</script>

<template>
    <Head title="Dashboard" />

    <PendingInvitationsModal
        v-if="pendingInvitations && pendingInvitations.length > 0"
        :invitations="pendingInvitations"
    />

    <div class="mx-auto w-full max-w-5xl space-y-8 p-4 sm:p-6">
        <section
            class="relative overflow-hidden rounded-3xl border bg-surface px-6 py-10 sm:px-10"
        >
            <div
                class="pointer-events-none absolute -top-24 -right-20 size-72 rounded-full bg-brand-soft blur-3xl dark:bg-brand/15"
                aria-hidden="true"
            />
            <div
                class="relative flex flex-wrap items-end justify-between gap-6"
            >
                <div>
                    <p class="text-sm text-muted-foreground">
                        {{ organization ? organization.name : 'Welcome' }}
                    </p>
                    <h1
                        class="mt-1 font-display text-4xl leading-tight sm:text-5xl"
                    >
                        {{ greeting }}, {{ firstName }}.
                    </h1>
                    <p class="mt-3 max-w-md text-muted-foreground">
                        <template v-if="(nav?.personal_unread ?? 0) > 0"
                            >{{ nav?.personal_unread }} unread
                            {{
                                nav?.personal_unread === 1
                                    ? 'message is'
                                    : 'messages are'
                            }}
                            waiting for you.</template
                        >
                        <template v-else
                            >Your mail is quiet. Suspiciously quiet.</template
                        >
                    </p>
                </div>
                <Button
                    as-child
                    size="lg"
                    class="bg-brand text-brand-foreground hover:bg-brand/90"
                >
                    <Link :href="mailUrl">Go to mail <ArrowRight /></Link>
                </Button>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-3">
            <div
                v-for="stat in stats"
                :key="stat.label"
                class="flex items-center gap-4 rounded-2xl border bg-card p-4 shadow-pane"
            >
                <span
                    class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-soft text-brand-soft-foreground"
                >
                    <component :is="stat.icon" class="size-5" />
                </span>
                <div>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ stat.value }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ stat.label }}
                    </p>
                </div>
            </div>
        </section>

        <section>
            <h2 class="mb-3 text-sm font-semibold text-muted-foreground">
                Shortcuts
            </h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    :href="organizations()"
                    class="group rounded-2xl border bg-card p-4 shadow-pane transition hover:-translate-y-0.5 hover:shadow-float"
                >
                    <Building2 class="size-5 text-brand" />
                    <p class="mt-3 font-medium">Organizations</p>
                    <p class="text-xs text-muted-foreground">
                        Invite teammates, manage roles, switch workspaces.
                    </p>
                </Link>
                <Link
                    v-if="organization && page.props.canManageOrganization"
                    :href="automations(organization.slug)"
                    class="group rounded-2xl border bg-card p-4 shadow-pane transition hover:-translate-y-0.5 hover:shadow-float"
                >
                    <Workflow class="size-5 text-brand" />
                    <p class="mt-3 font-medium">Automations</p>
                    <p class="text-xs text-muted-foreground">
                        Rules that tag, route and reply while you sleep.
                    </p>
                </Link>
                <Link
                    :href="profile()"
                    class="group rounded-2xl border bg-card p-4 shadow-pane transition hover:-translate-y-0.5 hover:shadow-float"
                >
                    <Settings class="size-5 text-brand" />
                    <p class="mt-3 font-medium">Settings</p>
                    <p class="text-xs text-muted-foreground">
                        Profile, security, appearance.
                    </p>
                </Link>
            </div>
        </section>
    </div>
</template>
