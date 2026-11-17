<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { BarChart3, BookOpen, Images, Sparkles, Wallet } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

const mainNavItems: NavItem[] = [
    { title: 'Generate', href: route('generations.create'), icon: Sparkles },
    { title: 'History', href: route('generations.index'), icon: Images },
    { title: 'Credits', href: route('credits.index'), icon: Wallet },
];

const adminNavItems: NavItem[] = [{ title: 'Profit report', href: route('admin.report'), icon: BarChart3 }];

const footerNavItems: NavItem[] = [{ title: 'Pixelforge API docs', href: 'https://pixelforge.promptedit.dev/docs', icon: BookOpen }];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('generations.create')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain v-if="page.props.auth.is_admin" :items="adminNavItems" label="Operators" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
