<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { postJson } from '@/lib/http';
import type { BreadcrumbItem, Estimate } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Info, Loader2, Plus, Sparkles, X } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    sizes: string[];
    qualities: string[];
    maxPromptLength: number;
    maxReferenceImages: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generate', href: route('generations.create') }];

const form = useForm({
    prompt: '',
    size: props.sizes[0] ?? '1024x1024',
    quality: props.qualities.includes('medium') ? 'medium' : props.qualities[0],
    reference_images: [] as string[],
});

const estimate = ref<Estimate | null>(null);
const estimating = ref(false);
const estimateError = ref<string | null>(null);
let controller: AbortController | null = null;

const refreshEstimate = useDebounceFn(async () => {
    controller?.abort();
    controller = new AbortController();
    estimating.value = true;

    try {
        estimate.value = await postJson<Estimate>(
            route('generations.estimate'),
            { prompt: form.prompt, size: form.size, quality: form.quality, reference_images: form.reference_images },
            controller.signal,
        );
        estimateError.value = null;
    } catch (error: any) {
        if (error.name === 'AbortError') return;
        // Invalid input (e.g. a malformed URL) is shown next to the field on submit; keep the last estimate.
        estimateError.value = error.status === 422 ? null : 'Could not calculate the price right now.';
    } finally {
        estimating.value = false;
    }
}, 300);

watch(() => [form.prompt, form.size, form.quality, form.reference_images.filter((u) => u.trim() !== '').length], refreshEstimate);
onMounted(refreshEstimate);

const promptLength = computed(() => [...form.prompt].length);
const canSubmit = computed(() => form.prompt.trim() !== '' && !form.processing && estimate.value?.can_afford !== false);

const addReference = () => {
    if (form.reference_images.length < props.maxReferenceImages) form.reference_images.push('');
};
const removeReference = (index: number) => form.reference_images.splice(index, 1);

const submit = () => form.post(route('generations.store'));

const selectClass =
    'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 md:text-sm';
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto grid w-full max-w-5xl gap-6 p-4 lg:grid-cols-[1fr_320px]">
            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <div class="flex items-baseline justify-between">
                        <Label for="prompt">Prompt</Label>
                        <span class="text-xs tabular-nums text-muted-foreground" :class="{ 'text-red-600': promptLength > maxPromptLength }">
                            {{ promptLength }} / {{ maxPromptLength }}
                        </span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="6"
                        :maxlength="maxPromptLength"
                        placeholder="A lighthouse on a cliff at dawn, oil painting"
                        :class="[selectClass, 'h-auto resize-y']"
                    />
                    <InputError :message="form.errors.prompt" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="size">Size</Label>
                        <select id="size" v-model="form.size" :class="selectClass">
                            <option v-for="size in sizes" :key="size" :value="size">{{ size.replace('x', ' × ') }}</option>
                        </select>
                        <InputError :message="form.errors.size" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="quality">Quality</Label>
                        <select id="quality" v-model="form.quality" :class="[selectClass, 'capitalize']">
                            <option v-for="quality in qualities" :key="quality" :value="quality">{{ quality }}</option>
                        </select>
                        <InputError :message="form.errors.quality" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Reference images <span class="font-normal text-muted-foreground">(optional, up to {{ maxReferenceImages }})</span></Label>
                    <div v-for="(_, index) in form.reference_images" :key="index" class="flex gap-2">
                        <Input v-model="form.reference_images[index]" type="url" placeholder="https://example.com/style.png" />
                        <Button type="button" variant="ghost" size="icon" :aria-label="`Remove reference image ${index + 1}`" @click="removeReference(index)">
                            <X />
                        </Button>
                    </div>
                    <InputError v-for="(message, key) in form.errors" v-show="String(key).startsWith('reference_images')" :key="key" :message="message" />
                    <div>
                        <Button v-if="form.reference_images.length < maxReferenceImages" type="button" variant="outline" size="sm" @click="addReference">
                            <Plus /> Add reference image
                        </Button>
                    </div>
                </div>

                <InputError :message="(form.errors as Record<string, string>).balance" />

                <Button type="submit" size="lg" :disabled="!canSubmit">
                    <Loader2 v-if="form.processing" class="animate-spin" />
                    <Sparkles v-else />
                    Generate
                </Button>
            </form>

            <aside class="h-fit space-y-4 rounded-xl border border-sidebar-border/70 p-5 lg:sticky lg:top-4">
                <div>
                    <h2 class="text-sm font-medium text-muted-foreground">Price</h2>
                    <div v-if="estimate" class="mt-1" :class="{ 'opacity-60': estimating }">
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ estimate.charge.min_formatted }} – {{ estimate.charge.max_formatted }}
                        </p>
                        <p class="text-sm text-muted-foreground">Typically about {{ estimate.charge.expected_formatted }}</p>
                    </div>
                    <p v-else class="mt-1 text-2xl font-semibold text-muted-foreground">…</p>
                    <p v-if="estimateError" class="mt-1 text-sm text-red-600">{{ estimateError }}</p>
                </div>

                <div class="flex gap-2 text-xs leading-relaxed text-muted-foreground">
                    <Info class="mt-0.5 size-4 shrink-0" />
                    <p>
                        The exact prompt length in tokens and the model's reasoning are only known once the image is ready, so we
                        <strong class="text-foreground">reserve the maximum</strong> and then charge the actual price. Failed images are free.
                    </p>
                </div>

                <div v-if="estimate" class="border-t border-sidebar-border/70 pt-4 text-sm">
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Your balance</span>
                        <span class="font-medium tabular-nums">{{ estimate.balance }}</span>
                    </div>
                    <div v-if="!estimate.can_afford" class="mt-3 rounded-md bg-amber-50 p-3 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200">
                        You need at least {{ estimate.charge.max_formatted }} available to start this image.
                        <Link :href="route('credits.index')" class="font-medium underline">Buy credits</Link>
                    </div>
                </div>
            </aside>
        </div>
    </AppLayout>
</template>
