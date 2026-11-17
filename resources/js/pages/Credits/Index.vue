<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Wallet } from 'lucide-vue-next';

interface Transaction {
    id: number;
    type: 'purchase' | 'hold' | 'release' | 'charge';
    amount: string;
    is_credit: boolean;
    description: string | null;
    prompt: string | null;
    created_at: string;
}

defineProps<{ packs: number[]; transactions: Paginated<Transaction> }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Credits', href: route('credits.index') }];
const page = usePage<SharedData>();
const form = useForm({ amount: 0 });

const buy = (amount: number) => {
    form.amount = amount;
    form.post(route('credits.store'), { preserveScroll: true });
};

const labels: Record<Transaction['type'], string> = {
    purchase: 'Purchase',
    hold: 'Reserved',
    release: 'Reservation released',
    charge: 'Charged',
};

const formatTime = (iso: string) => new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
</script>

<template>
    <Head title="Credits" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
            <section class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 p-6 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <Wallet class="size-8 text-muted-foreground" />
                    <div>
                        <p class="text-sm text-muted-foreground">Available balance</p>
                        <p class="text-3xl font-semibold tabular-nums">{{ page.props.balance }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-for="pack in packs" :key="pack" :disabled="form.processing" @click="buy(pack)">Buy ${{ pack }}</Button>
                </div>
            </section>
            <p class="text-xs text-muted-foreground">
                This is a demo: purchases are free and no payment details are collected. Credits are US dollars. When you start an image we reserve
                its maximum possible price, then release the reservation and charge the actual price once it is done.
            </p>

            <section class="overflow-hidden rounded-xl border border-sidebar-border/70">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">When</th>
                            <th class="px-4 py-2 font-medium">What</th>
                            <th class="px-4 py-2 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="transactions.data.length === 0">
                            <td colspan="3" class="px-4 py-6 text-center text-muted-foreground">No transactions yet.</td>
                        </tr>
                        <tr v-for="t in transactions.data" :key="t.id" class="border-t border-sidebar-border/70">
                            <td class="whitespace-nowrap px-4 py-2 text-muted-foreground">{{ formatTime(t.created_at) }}</td>
                            <td class="px-4 py-2">
                                <span class="font-medium">{{ labels[t.type] }}</span>
                                <span v-if="t.prompt" class="ml-1 line-clamp-1 text-muted-foreground" :title="t.prompt">“{{ t.prompt }}”</span>
                            </td>
                            <td :class="['whitespace-nowrap px-4 py-2 text-right tabular-nums', t.is_credit ? 'text-emerald-700 dark:text-emerald-400' : '']">
                                {{ t.is_credit ? '+' : '' }}{{ t.amount }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <nav v-if="transactions.last_page > 1" class="flex justify-between text-sm">
                <Link v-if="transactions.prev_page_url" :href="transactions.prev_page_url" class="underline">Newer</Link>
                <span v-else />
                <Link v-if="transactions.next_page_url" :href="transactions.next_page_url" class="underline">Older</Link>
            </nav>
        </div>
    </AppLayout>
</template>
