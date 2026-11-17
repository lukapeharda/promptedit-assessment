<script setup lang="ts">
import type { GenerationStatus } from '@/types';
import { computed } from 'vue';

const props = defineProps<{ status: GenerationStatus }>();

const styles: Record<GenerationStatus, { label: string; classes: string; pulse: boolean }> = {
    pending: { label: 'Submitting', classes: 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300', pulse: true },
    queued: { label: 'Queued', classes: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300', pulse: true },
    processing: { label: 'Processing', classes: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300', pulse: true },
    succeeded: { label: 'Succeeded', classes: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300', pulse: false },
    failed: { label: 'Failed', classes: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300', pulse: false },
};

const style = computed(() => styles[props.status]);
</script>

<template>
    <span :class="['inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium', style.classes]">
        <span v-if="style.pulse" class="size-1.5 animate-pulse rounded-full bg-current" />
        {{ style.label }}
    </span>
</template>
