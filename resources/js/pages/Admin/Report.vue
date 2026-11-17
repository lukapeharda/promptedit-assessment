<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, ChevronDown, XCircle } from 'lucide-vue-next';
import { ref } from 'vue';

interface Money {
    charged: string;
    cost: string;
    profit: string;
}
interface UserRow extends Money {
    user_id: number;
    name: string;
    email: string;
    generations: number;
}
interface DayRow extends Money {
    date: string;
    generations: number;
    pixelforge_cost: string | null;
    matches_pixelforge: boolean | null;
    users: UserRow[];
}

const props = defineProps<{
    start: string;
    end: string;
    report: {
        days: DayRow[];
        totals: Money & { generations: number; pixelforge_cost: string | null; matches_pixelforge: boolean | null };
        per_user: UserRow[];
        usage_error: string | null;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Profit report', href: route('admin.report') }];

const range = ref({ start: props.start, end: props.end });
const expanded = ref<Set<string>>(new Set());
const toggle = (date: string) => (expanded.value.has(date) ? expanded.value.delete(date) : expanded.value.add(date));
const apply = () => router.get(route('admin.report'), range.value, { preserveState: true });
</script>

<template>
    <Head title="Profit report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full min-w-0 max-w-6xl space-y-6 p-4">
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
                <div class="grid gap-1">
                    <Label for="start">From (UTC)</Label>
                    <Input id="start" v-model="range.start" type="date" />
                </div>
                <div class="grid gap-1">
                    <Label for="end">To (UTC)</Label>
                    <Input id="end" v-model="range.end" type="date" />
                </div>
                <Button type="submit">Show</Button>
                <p class="basis-full text-xs text-muted-foreground">
                    Days are UTC days on which a generation <em>completed</em>, matching how Pixelforge bills. Amounts are exact US dollars.
                </p>
            </form>

            <div v-if="report.usage_error" class="flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-200">
                <AlertTriangle class="size-4" /> {{ report.usage_error }}
            </div>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="min-w-0 rounded-xl border border-sidebar-border/70 p-4">
                    <p class="text-xs text-muted-foreground">Generations billed</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ report.totals.generations }}</p>
                </div>
                <div class="min-w-0 rounded-xl border border-sidebar-border/70 p-4">
                    <p class="text-xs text-muted-foreground">Credit spend</p>
                    <p class="text-xl font-semibold tabular-nums break-all">${{ report.totals.charged }}</p>
                </div>
                <div class="min-w-0 rounded-xl border border-sidebar-border/70 p-4">
                    <p class="text-xs text-muted-foreground">Provider cost</p>
                    <p class="text-xl font-semibold tabular-nums break-all">${{ report.totals.cost }}</p>
                    <p v-if="report.totals.pixelforge_cost !== null" class="mt-1 flex items-center gap-1 text-xs" :class="report.totals.matches_pixelforge ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700'">
                        <component :is="report.totals.matches_pixelforge ? CheckCircle2 : XCircle" class="size-3.5" />
                        Pixelforge: ${{ report.totals.pixelforge_cost }}
                    </p>
                </div>
                <div class="min-w-0 rounded-xl border border-sidebar-border/70 p-4">
                    <p class="text-xs text-muted-foreground">Profit</p>
                    <p class="text-xl font-semibold tabular-nums break-all">${{ report.totals.profit }}</p>
                </div>
            </section>

            <section class="overflow-x-auto rounded-xl border border-sidebar-border/70">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Day / user</th>
                            <th class="px-4 py-2 text-right font-medium">Images</th>
                            <th class="px-4 py-2 text-right font-medium">Credit spend</th>
                            <th class="px-4 py-2 text-right font-medium">Provider cost</th>
                            <th class="px-4 py-2 text-right font-medium">Pixelforge /v1/usage</th>
                            <th class="px-4 py-2 text-right font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="day in report.days" :key="day.date">
                            <tr class="border-t border-sidebar-border/70" :class="{ 'cursor-pointer hover:bg-muted/30': day.users.length }" @click="day.users.length && toggle(day.date)">
                                <td class="whitespace-nowrap px-4 py-2 font-medium">
                                    <ChevronDown v-if="day.users.length" class="mr-1 inline size-4 transition-transform" :class="{ '-rotate-90': !expanded.has(day.date) }" />
                                    {{ day.date }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ day.generations }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ day.charged }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ day.cost }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">
                                    <template v-if="day.pixelforge_cost !== null">
                                        <span :class="day.matches_pixelforge ? 'text-emerald-700 dark:text-emerald-400' : 'font-medium text-red-700'">
                                            {{ day.pixelforge_cost }}
                                        </span>
                                        <component :is="day.matches_pixelforge ? CheckCircle2 : XCircle" class="ml-1 inline size-3.5" :class="day.matches_pixelforge ? 'text-emerald-600' : 'text-red-600'" />
                                    </template>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ day.profit }}</td>
                            </tr>
                            <template v-if="expanded.has(day.date)">
                                <tr v-for="user in day.users" :key="`${day.date}-${user.user_id}`" class="bg-muted/20 text-muted-foreground">
                                    <td class="py-1.5 pl-10 pr-4">{{ user.name }} <span class="text-xs">({{ user.email }})</span></td>
                                    <td class="px-4 py-1.5 text-right tabular-nums">{{ user.generations }}</td>
                                    <td class="px-4 py-1.5 text-right tabular-nums">{{ user.charged }}</td>
                                    <td class="px-4 py-1.5 text-right tabular-nums">{{ user.cost }}</td>
                                    <td />
                                    <td class="px-4 py-1.5 text-right tabular-nums">{{ user.profit }}</td>
                                </tr>
                            </template>
                        </template>
                    </tbody>
                </table>
            </section>

            <section class="overflow-x-auto rounded-xl border border-sidebar-border/70">
                <h2 class="border-b border-sidebar-border/70 px-4 py-2 text-sm font-medium">Per user, whole range</h2>
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-if="report.per_user.length === 0">
                            <td class="px-4 py-6 text-center text-muted-foreground">No billed generations in this range.</td>
                        </tr>
                        <tr v-for="user in report.per_user" :key="user.user_id" class="border-t border-sidebar-border/70 first:border-t-0">
                            <td class="px-4 py-2">{{ user.name }} <span class="text-xs text-muted-foreground">({{ user.email }})</span></td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ user.generations }} images</td>
                            <td class="px-4 py-2 text-right tabular-nums">spent ${{ user.charged }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">cost ${{ user.cost }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">profit ${{ user.profit }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
