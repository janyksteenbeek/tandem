<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import WorkflowEditor from '@/components/projects/workflow/WorkflowEditor.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { api, reportError } from '@/lib/http';
import type { EstimateScale, Team, Workflow } from '@/types';

const props = defineProps<{ team: Team; workflow: Workflow }>();
const emit = defineEmits<{ 'update:workflow': [workflow: Workflow] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const estimateScale = ref<EstimateScale>(props.team.estimate_scale);
const cycle = reactive({ ...props.team.cycle_settings });
const busy = ref(false);

watch(
    () => props.team,
    (t) => {
        estimateScale.value = t.estimate_scale;
        Object.assign(cycle, t.cycle_settings);
    },
);

const weekdays = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];

const save = async () => {
    busy.value = true;

    try {
        await api.patch(
            `/${slug.value}/teams/${props.team.slug}/project-settings`,
            {
                estimate_scale: estimateScale.value,
                cycle_settings: { ...cycle },
            },
        );
        toast.success('Team settings saved');
        router.reload({ only: ['team', 'lookups'] });
    } catch (error) {
        reportError(error);
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <div class="max-w-3xl space-y-12">
        <SettingsSection
            title="Workflow"
            :description="`The statuses ${team.name} moves its work through. Other teams on the same issue keep their own.`"
        >
            <WorkflowEditor
                :workflow="workflow"
                :can-manage="team.can.lead"
                @update:workflow="(w) => emit('update:workflow', w)"
            />
        </SettingsSection>

        <SettingsSection
            title="Estimates"
            description="How the team sizes its workstreams."
        >
            <label class="flex items-center gap-3 text-sm">
                <span class="text-muted-foreground">Scale</span>
                <NativeSelect
                    v-model="estimateScale"
                    :disabled="!team.can.lead"
                >
                    <NativeSelectOption value="none"
                        >No estimates</NativeSelectOption
                    >
                    <NativeSelectOption value="fibonacci"
                        >Fibonacci (1, 2, 3, 5, 8…)</NativeSelectOption
                    >
                    <NativeSelectOption value="linear"
                        >Linear (1–8)</NativeSelectOption
                    >
                    <NativeSelectOption value="tshirt"
                        >T-shirt (XS–XL)</NativeSelectOption
                    >
                </NativeSelect>
            </label>
        </SettingsSection>

        <SettingsSection
            title="Cycles"
            description="Time-boxed planning. Workstreams, not issues, belong to a cycle, so each team schedules its own part."
        >
            <div class="space-y-4">
                <label class="flex items-center gap-2 text-sm"
                    ><Switch
                        v-model="cycle.enabled"
                        :disabled="!team.can.lead"
                    />
                    Use cycles for {{ team.name }}</label
                >
                <div
                    class="grid gap-3 sm:grid-cols-2"
                    :class="!cycle.enabled && 'opacity-50'"
                >
                    <label class="space-y-1.5 text-sm"
                        ><span class="text-muted-foreground"
                            >Length (weeks)</span
                        ><Input
                            v-model.number="cycle.length_weeks"
                            type="number"
                            min="1"
                            max="12"
                            :disabled="!team.can.lead || !cycle.enabled"
                    /></label>
                    <label class="space-y-1.5 text-sm"
                        ><span class="text-muted-foreground"
                            >Cooldown between cycles (weeks)</span
                        ><Input
                            v-model.number="cycle.cooldown_weeks"
                            type="number"
                            min="0"
                            max="4"
                            :disabled="!team.can.lead || !cycle.enabled"
                    /></label>
                    <label class="space-y-1.5 text-sm"
                        ><span class="text-muted-foreground"
                            >Cycles start on</span
                        >
                        <NativeSelect
                            v-model="cycle.start_weekday"
                            class="w-full"
                            :disabled="!team.can.lead || !cycle.enabled"
                            ><NativeSelectOption
                                v-for="(day, index) in weekdays"
                                :key="day"
                                :value="index"
                                >{{ day }}</NativeSelectOption
                            ></NativeSelect
                        >
                    </label>
                    <label class="space-y-1.5 text-sm"
                        ><span class="text-muted-foreground"
                            >Upcoming cycles to keep planned</span
                        ><Input
                            v-model.number="cycle.upcoming_count"
                            type="number"
                            min="1"
                            max="6"
                            :disabled="!team.can.lead || !cycle.enabled"
                    /></label>
                    <label class="flex items-center gap-2 text-sm sm:col-span-2"
                        ><Switch
                            v-model="cycle.auto_carry_over"
                            :disabled="!team.can.lead || !cycle.enabled"
                        />
                        Carry unfinished work into the next cycle
                        automatically</label
                    >
                </div>
                <div v-if="team.can.lead">
                    <Button :disabled="busy" @click="save"
                        >Save settings</Button
                    >
                </div>
            </div>
        </SettingsSection>
    </div>
</template>
