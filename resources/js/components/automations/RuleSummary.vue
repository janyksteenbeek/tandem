<script setup lang="ts">
import { Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import { summarizeRule } from '@/lib/automations';
import type { AutomationCatalog, AutomationLookups } from '@/types/automations';
import type { SummarizableRule } from '@/lib/automations';

const props = defineProps<{
    rule: SummarizableRule;
    catalog: AutomationCatalog;
    lookups: AutomationLookups;
    active?: boolean;
}>();

const sentence = computed(() => summarizeRule(props.rule, props.catalog, props.lookups));
</script>

<template>
    <div class="relative overflow-hidden rounded-xl border border-brand/20 bg-brand-soft/40 p-4">
        <Sparkles class="absolute -top-2 -right-2 size-16 rotate-12 text-brand/10" aria-hidden="true" />
        <p class="mb-1.5 flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-brand-soft-foreground uppercase">
            <Sparkles class="size-3" /> In plain English
        </p>
        <p class="text-sm leading-relaxed text-foreground">{{ sentence }}</p>
        <p v-if="active === false" class="mt-2 text-xs text-muted-foreground">Paused: nothing happens until you switch it back on.</p>
    </div>
</template>
