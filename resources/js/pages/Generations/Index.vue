<script setup lang="ts">
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, Generation, ResourcePage } from '@/types';
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { AlertTriangle, Copy, ImageOff, Loader2, Pencil, RotateCcw, Sparkles } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ generations: ResourcePage<Generation> }>();

const page = usePage();
const errors = computed(() => Object.values(page.props.errors ?? {}) as string[]);

// A retry is a new generation with the same settings; the failed one stays as a free attempt.
// Only offered when the failure was outside the user's control (server decides: can_retry).
const retrying = ref<number | null>(null);
const retriedLabel: Record<string, string> = {
    pending: 'in progress',
    queued: 'in progress',
    processing: 'in progress',
    succeeded: 'succeeded',
    failed: 'also failed',
};
const retry = (generation: Generation) => {
    retrying.value = generation.id;
    router.post(route('generations.retry', generation.id), {}, { preserveScroll: true, onFinish: () => (retrying.value = null) });
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'History', href: route('generations.index') }];

// Refresh statuses (and the balance) while anything is still in flight.
const inFlight = computed(() => props.generations.data.some((g) => !g.is_final));
const { start, stop } = usePoll(3000, { only: ['generations', 'balance'] }, { autoStart: false });
watch(inFlight, (active) => (active ? start() : stop()), { immediate: true });

const aspect = (size: string) => {
    const [w, h] = size.split('x').map(Number);
    return `${w} / ${h}`;
};

const formatTime = (iso: string) =>
    new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-4 p-4">
            <div
                v-for="message in errors"
                :key="message"
                role="alert"
                class="flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-200"
            >
                <AlertTriangle class="size-4 shrink-0" />
                <span>{{ message }}</span>
                <Link v-if="message.includes('Buy credits')" :href="route('credits.index')" class="ml-auto shrink-0 font-medium underline">Buy credits</Link>
            </div>
            <div v-if="generations.data.length === 0" class="flex flex-col items-center gap-4 rounded-xl border border-dashed p-12 text-center">
                <p class="text-muted-foreground">No images yet.</p>
                <Button as-child>
                    <Link :href="route('generations.create')"><Sparkles /> Generate your first image</Link>
                </Button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <article
                    v-for="generation in generations.data"
                    :key="generation.id"
                    class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70"
                >
                    <div class="relative flex items-center justify-center bg-muted" :style="{ aspectRatio: aspect(generation.size) }">
                        <a v-if="generation.image" :href="generation.image" target="_blank" rel="noopener" class="block size-full">
                            <img :src="generation.image" :alt="generation.prompt" class="size-full object-cover" loading="lazy" />
                        </a>
                        <div v-else-if="generation.status === 'failed'" class="flex flex-col items-center gap-2 p-4 text-center text-sm text-red-700 dark:text-red-400">
                            <AlertTriangle class="size-6" />
                            {{ generation.error_message }}
                            <span v-if="generation.retried_status" class="mt-1 text-xs text-muted-foreground">
                                Retried · {{ retriedLabel[generation.retried_status] }}
                            </span>
                            <Button
                                v-else-if="generation.can_retry"
                                variant="outline"
                                size="sm"
                                class="mt-1 text-foreground"
                                :disabled="retrying !== null"
                                @click="retry(generation)"
                            >
                                <Loader2 v-if="retrying === generation.id" class="animate-spin" />
                                <RotateCcw v-else />
                                Try again
                            </Button>
                            <!-- The request itself was the problem (e.g. content policy): retrying as-is would fail again. -->
                            <Button v-else variant="outline" size="sm" class="mt-1 text-foreground" as-child>
                                <Link :href="route('generations.create', { from: generation.id })"><Pencil /> Edit prompt</Link>
                            </Button>
                        </div>
                        <div v-else-if="generation.image_expired" class="flex flex-col items-center gap-2 text-sm text-muted-foreground">
                            <ImageOff class="size-6" />
                            Image link expired
                        </div>
                        <div v-else-if="!generation.is_final" class="flex flex-col items-center gap-2 text-sm text-muted-foreground">
                            <Loader2 class="size-6 animate-spin" />
                            Working on it…
                        </div>
                    </div>

                    <div class="flex flex-1 flex-col gap-3 p-4">
                        <div class="flex items-start justify-between gap-2">
                            <span class="flex items-center gap-1.5">
                                <StatusBadge :status="generation.status" />
                                <span v-if="generation.is_retry" class="rounded-full border px-2 py-0.5 text-xs text-muted-foreground">Retry</span>
                            </span>
                            <time class="text-xs text-muted-foreground" :datetime="generation.created_at">{{ formatTime(generation.created_at) }}</time>
                        </div>

                        <p class="line-clamp-3 text-sm" :title="generation.prompt">{{ generation.prompt }}</p>

                        <p class="text-xs capitalize text-muted-foreground">
                            {{ generation.size.replace('x', ' × ') }} · {{ generation.quality }}
                            <template v-if="generation.reference_images.length">
                                · {{ generation.reference_images.length }} reference {{ generation.reference_images.length === 1 ? 'image' : 'images' }}
                            </template>
                        </p>

                        <dl class="mt-auto grid grid-cols-2 gap-x-4 gap-y-1 border-t border-sidebar-border/70 pt-3 text-sm">
                            <dt class="text-muted-foreground">Estimated</dt>
                            <dd class="text-right tabular-nums">{{ generation.estimate.min }} – {{ generation.estimate.max }}</dd>

                            <dt class="text-muted-foreground">Charged</dt>
                            <dd class="text-right font-medium tabular-nums">
                                <template v-if="generation.status === 'failed'">Free</template>
                                <template v-else-if="generation.charge">{{ generation.charge }}</template>
                                <span v-else class="font-normal text-muted-foreground">up to {{ generation.estimate.max }} reserved</span>
                            </dd>
                        </dl>

                        <Link
                            :href="route('generations.create', { from: generation.id })"
                            class="flex items-center gap-1.5 self-start text-xs font-medium text-muted-foreground hover:text-foreground"
                        >
                            <Copy class="size-3.5" /> Use these settings
                        </Link>

                        <details v-if="generation.tokens" class="text-xs text-muted-foreground">
                            <summary class="cursor-pointer">Token usage</summary>
                            <ul class="mt-1 space-y-0.5 tabular-nums">
                                <li>Prompt text: {{ generation.tokens.input_text }}</li>
                                <li>Reference images: {{ generation.tokens.input_image }}</li>
                                <li>Output: {{ generation.tokens.output }}</li>
                                <li>Reasoning: {{ generation.tokens.reasoning }}</li>
                            </ul>
                        </details>
                    </div>
                </article>
            </div>

            <nav v-if="generations.meta.last_page > 1" class="flex items-center justify-between pt-2 text-sm">
                <Button variant="outline" size="sm" :disabled="!generations.links.prev" as-child>
                    <Link :href="generations.links.prev ?? '#'" preserve-scroll>Newer</Link>
                </Button>
                <span class="text-muted-foreground">Page {{ generations.meta.current_page }} of {{ generations.meta.last_page }}</span>
                <Button variant="outline" size="sm" :disabled="!generations.links.next" as-child>
                    <Link :href="generations.links.next ?? '#'" preserve-scroll>Older</Link>
                </Button>
            </nav>
        </div>
    </AppLayout>
</template>
