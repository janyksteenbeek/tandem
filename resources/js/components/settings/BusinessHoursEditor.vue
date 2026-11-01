<script setup lang="ts">
import { CalendarOff, Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import TimezonePicker from '@/components/settings/TimezonePicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import type {
    BusinessHours,
    BusinessHoursDay,
    BusinessHoursRange,
} from '@/types';

const props = defineProps<{
    timezones: string[];
    errors?: Record<string, string>;
}>();
const model = defineModel<BusinessHours>({ required: true });

const DAYS: { key: BusinessHoursDay; label: string; short: string }[] = [
    { key: 'mon', label: 'Monday', short: 'Mon' },
    { key: 'tue', label: 'Tuesday', short: 'Tue' },
    { key: 'wed', label: 'Wednesday', short: 'Wed' },
    { key: 'thu', label: 'Thursday', short: 'Thu' },
    { key: 'fri', label: 'Friday', short: 'Fri' },
    { key: 'sat', label: 'Saturday', short: 'Sat' },
    { key: 'sun', label: 'Sunday', short: 'Sun' },
];

const lastRange = ref<BusinessHoursRange>({ start: '09:00', end: '17:00' });
const newHoliday = ref('');

const enabled = (day: BusinessHoursDay): boolean =>
    (model.value.days[day]?.length ?? 0) > 0;

function toggleDay(day: BusinessHoursDay, on: boolean): void {
    if (on) {
        model.value.days[day] = [{ ...lastRange.value }];
    } else {
        const first = model.value.days[day]?.[0];

        if (first) {
            lastRange.value = { ...first };
        }

        model.value.days[day] = [];
    }
}

function addRange(day: BusinessHoursDay): void {
    const previous = model.value.days[day].at(-1);
    model.value.days[day] = [
        ...model.value.days[day],
        { start: previous?.end ?? '13:00', end: '17:00' },
    ];
}

function removeRange(day: BusinessHoursDay, index: number): void {
    model.value.days[day] = model.value.days[day].filter((_, i) => i !== index);
}

function copyToWeekdays(day: BusinessHoursDay): void {
    const ranges = model.value.days[day].map((range) => ({ ...range }));

    (['mon', 'tue', 'wed', 'thu', 'fri'] as BusinessHoursDay[]).forEach(
        (key) => {
            model.value.days[key] = ranges.map((range) => ({ ...range }));
        },
    );
}

function addHoliday(): void {
    const value = newHoliday.value;

    if (
        !/^\d{4}-\d{2}-\d{2}$/.test(value) ||
        model.value.holidays.includes(value)
    ) {
        return;
    }

    model.value.holidays = [...model.value.holidays, value].sort();
    newHoliday.value = '';
}

function removeHoliday(date: string): void {
    model.value.holidays = model.value.holidays.filter(
        (holiday) => holiday !== date,
    );
}

const holidayLabel = (iso: string): string =>
    new Date(`${iso}T12:00:00`).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

const openDays = computed(() => DAYS.filter((day) => enabled(day.key)).length);
const errorFor = (path: string): string | undefined => props.errors?.[path];
</script>

<template>
    <div class="space-y-6">
        <div class="grid gap-1.5">
            <Label for="business-hours-timezone"
                >Timezone for these hours</Label
            >
            <TimezonePicker
                id="business-hours-timezone"
                v-model="model.timezone!"
                :timezones="timezones"
            />
            <p
                v-if="errorFor('business_hours.timezone')"
                class="text-xs text-destructive"
            >
                {{ errorFor('business_hours.timezone') }}
            </p>
        </div>

        <div class="overflow-hidden rounded-xl border">
            <div
                class="flex items-center justify-between bg-surface px-4 py-2 text-xs text-muted-foreground"
            >
                <span>{{
                    openDays === 0
                        ? 'Closed all week. Everything counts as outside hours.'
                        : `Open ${openDays} day${openDays === 1 ? '' : 's'} a week`
                }}</span>
            </div>
            <ul class="divide-y">
                <li
                    v-for="day in DAYS"
                    :key="day.key"
                    class="grid gap-3 px-4 py-3 sm:grid-cols-[8rem_1fr] sm:items-start"
                >
                    <label class="flex items-center gap-3 sm:pt-1.5">
                        <Switch
                            :model-value="enabled(day.key)"
                            :aria-label="`Open on ${day.label}`"
                            @update:model-value="
                                (value) => toggleDay(day.key, value)
                            "
                        />
                        <span
                            class="text-sm font-medium"
                            :class="
                                !enabled(day.key) && 'text-muted-foreground'
                            "
                            ><span class="sm:hidden">{{ day.label }}</span
                            ><span class="hidden sm:inline">{{
                                day.short
                            }}</span></span
                        >
                    </label>
                    <div v-if="enabled(day.key)" class="space-y-2">
                        <div
                            v-for="(range, index) in model.days[day.key]"
                            :key="index"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <Input
                                v-model="range.start"
                                type="time"
                                class="h-8 w-[8.75rem] text-sm"
                                :aria-label="`${day.label} opens at`"
                            />
                            <span class="text-xs text-muted-foreground"
                                >to</span
                            >
                            <Input
                                v-model="range.end"
                                type="time"
                                class="h-8 w-[8.75rem] text-sm"
                                :aria-label="`${day.label} closes at`"
                            />
                            <Button
                                v-if="model.days[day.key].length > 1"
                                type="button"
                                variant="ghost"
                                size="icon-xs"
                                aria-label="Remove range"
                                @click="removeRange(day.key, index)"
                                ><X
                            /></Button>
                            <Button
                                v-else-if="index === 0"
                                type="button"
                                variant="ghost"
                                size="xs"
                                class="text-muted-foreground"
                                @click="addRange(day.key)"
                                ><Plus /> Split</Button
                            >
                            <Button
                                v-if="index === 0 && day.key === 'mon'"
                                type="button"
                                variant="ghost"
                                size="xs"
                                class="text-muted-foreground"
                                @click="copyToWeekdays(day.key)"
                                >Copy to Mon–Fri</Button
                            >
                            <p
                                v-if="
                                    errorFor(
                                        `business_hours.days.${day.key}.${index}.start`,
                                    ) ||
                                    errorFor(
                                        `business_hours.days.${day.key}.${index}.end`,
                                    )
                                "
                                class="w-full text-xs text-destructive"
                            >
                                {{
                                    errorFor(
                                        `business_hours.days.${day.key}.${index}.start`,
                                    ) ??
                                    errorFor(
                                        `business_hours.days.${day.key}.${index}.end`,
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                    <p v-else class="text-sm text-muted-foreground sm:pt-1.5">
                        Closed
                    </p>
                </li>
            </ul>
        </div>

        <div class="grid gap-2">
            <Label
                for="business-hours-holiday"
                class="flex items-center gap-1.5"
                ><CalendarOff class="size-4 text-muted-foreground" />
                Holidays</Label
            >
            <div class="flex flex-wrap gap-2">
                <Input
                    id="business-hours-holiday"
                    v-model="newHoliday"
                    type="date"
                    class="h-9 w-44"
                    @keydown.enter.prevent="addHoliday"
                />
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!newHoliday"
                    @click="addHoliday"
                    ><Plus /> Add day off</Button
                >
            </div>
            <ul v-if="model.holidays.length > 0" class="flex flex-wrap gap-1.5">
                <li
                    v-for="holiday in model.holidays"
                    :key="holiday"
                    class="inline-flex items-center gap-1 rounded-full border bg-surface py-0.5 pr-1 pl-2.5 text-xs"
                >
                    {{ holidayLabel(holiday) }}
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-xs"
                        class="size-5"
                        :aria-label="`Remove ${holiday}`"
                        @click="removeHoliday(holiday)"
                        ><X
                    /></Button>
                </li>
            </ul>
            <p v-else class="text-xs text-muted-foreground">No holidays yet.</p>
            <p
                v-if="errorFor('business_hours.holidays')"
                class="text-xs text-destructive"
            >
                {{ errorFor('business_hours.holidays') }}
            </p>
        </div>
    </div>
</template>
