<script setup lang="ts">
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, Generation, ResourcePage } from '@/types';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { AlertTriangle, ImageOff, Loader2, Sparkles } from 'lucide-vue-next';
import { computed, watch } from 'vue';

const props = defineProps<{ generations: ResourcePage<Generation> }>();

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
                            <StatusBadge :status="generation.status" />
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
