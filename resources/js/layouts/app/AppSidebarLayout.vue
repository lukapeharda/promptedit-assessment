<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import type { BreadcrumbItemType, SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { CheckCircle2 } from 'lucide-vue-next';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage<SharedData>();
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="min-w-0">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div
                v-if="page.props.flash?.success"
                class="mx-4 mt-4 flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-300"
                role="status"
            >
                <CheckCircle2 class="size-4 shrink-0" />
                {{ page.props.flash.success }}
            </div>
            <slot />
        </AppContent>
    </AppShell>
</template>
