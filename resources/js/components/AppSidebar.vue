<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Inbox, Mail, Settings, Users, Workflow } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import OrganizationSwitcher from '@/components/OrganizationSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();

const dashboardUrl = computed(() =>
    page.props.currentOrganization
        ? dashboard(page.props.currentOrganization.slug).url
        : '/',
);

const slug = computed(() => page.props.currentOrganization?.slug);

const mainNavItems = computed<NavItem[]>(() => [
    ...(slug.value ? [{ title: 'Mail', href: `/${slug.value}/mail`, icon: Mail }] : []),
    { title: 'Mail accounts', href: '/settings/mail-accounts', icon: Settings },
    ...(slug.value
        ? [
              { title: 'Inboxes', href: `/settings/organizations/${slug.value}/inboxes`, icon: Inbox },
              { title: 'Teams', href: `/settings/organizations/${slug.value}/teams`, icon: Users },
              ...(page.props.canManageOrganization ? [{ title: 'Automations', href: `/${slug.value}/settings/automations`, icon: Workflow }] : []),
          ]
        : []),
]);


</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="slug ? `/${slug}/mail` : dashboardUrl">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <SidebarMenu>
                <SidebarMenuItem>
                    <OrganizationSwitcher />
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
