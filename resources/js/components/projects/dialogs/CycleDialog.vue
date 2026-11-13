<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { suggestedDates } from '@/components/projects/cycles/cycleMeta';
import { DatePicker } from '@/components/projects/pickers';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { api, HttpError } from '@/lib/http';
import type { CycleSettings, CycleSummary } from '@/types';

const open = defineModel<boolean>('open', { default: false });
const props = defineProps<{
    teamSlug: string;
    cycleSettings?: CycleSettings | null;
    /** The latest cycle, to suggest the next dates. */
    lastCycle?: CycleSummary | null;
    cycle?: CycleSummary | null;
}>();
const emit = defineEmits<{ saved: [cycle: CycleSummary] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const name = ref('');
const goals = ref('');
const startsAt = ref<string | null>(null);
const endsAt = ref<string | null>(null);
const busy = ref(false);
const errors = ref<Record<string, string[]>>({});

watch(open, (value) => {
    if (!value) {
        return;
    }

    errors.value = {};
    name.value =
        props.cycle?.name && !/^Cycle \d+$/.test(props.cycle.name)
            ? props.cycle.name
            : '';
    goals.value = props.cycle?.goals ?? '';

    if (props.cycle) {
        startsAt.value = props.cycle.starts_at;
        endsAt.value = props.cycle.ends_at;
    } else {
        const dates = suggestedDates(
            props.lastCycle?.ends_at ?? null,
            props.cycleSettings?.length_weeks ?? 2,
            props.cycleSettings?.cooldown_weeks ?? 0,
        );
        startsAt.value = dates.starts_at;
        endsAt.value = dates.ends_at;
    }
});

const submit = async () => {
    if (!startsAt.value || !endsAt.value) {
        errors.value = { ends_at: ['Pick both dates.'] };

        return;
    }

    busy.value = true;
    errors.value = {};
    const payload = {
        name: name.value.trim() || null,
        goals: goals.value.trim() || null,
        starts_at: startsAt.value,
        ends_at: endsAt.value,
    };
    const base = `/${slug.value}/teams/${props.teamSlug}/cycles`;

    try {
        const data = props.cycle
            ? await api.patch<{ cycle: CycleSummary }>(
                  `${base}/${props.cycle.uuid}`,
                  payload,
              )
            : await api.post<{ cycle: CycleSummary }>(base, payload);

        toast.success(
            props.cycle ? 'Cycle saved' : `${data.cycle.name} created`,
        );
        emit('saved', data.cycle);
        open.value = false;
    } catch (error) {
        if (error instanceof HttpError) {
            errors.value = error.errors;
        } else {
            toast.error('Could not save the cycle.');
        }
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-md">
            <form class="contents" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle class="font-display text-2xl">{{
                        cycle ? 'Edit cycle' : 'New cycle'
                    }}</DialogTitle>
                    <DialogDescription
                        >A time box for one team. Leave the name empty to number
                        it.</DialogDescription
                    >
                </DialogHeader>
                <div class="space-y-4">
                    <label class="block space-y-1.5 text-sm">
                        <span class="text-muted-foreground"
                            >Name
                            <span class="text-muted-foreground/60"
                                >(optional)</span
                            ></span
                        >
                        <Input v-model="name" v-focus placeholder="Sprint 14" />
                    </label>
                    <label class="block space-y-1.5 text-sm">
                        <span class="text-muted-foreground">Goals</span>
                        <Textarea
                            v-model="goals"
                            rows="2"
                            placeholder="What should be true when this cycle ends?"
                        />
                    </label>
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <span class="text-muted-foreground">From</span>
                        <DatePicker
                            :value="startsAt"
                            label="Start"
                            @change="(v) => (startsAt = v)"
                        />
                        <span class="text-muted-foreground">to</span>
                        <DatePicker
                            :value="endsAt"
                            label="End"
                            @change="(v) => (endsAt = v)"
                        />
                    </div>
                    <p
                        v-if="errors.ends_at || errors.starts_at"
                        class="text-xs text-destructive"
                    >
                        {{ (errors.ends_at ?? errors.starts_at)[0] }}
                    </p>
                </div>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="ghost" @click="open = false"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="busy">{{
                        cycle ? 'Save' : 'Create cycle'
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
