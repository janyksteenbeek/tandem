<script setup lang="ts">
import {
    addDays,
    addHours,
    nextMonday,
    setHours,
    setMinutes,
    startOfTomorrow,
} from 'date-fns';
import { CalendarClock, Clock } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { snoozeLabel } from '@/lib/time';

const props = defineProps<{
    snoozedUntil?: string | null;
    disabled?: boolean;
    asIcon?: boolean;
}>();
const emit = defineEmits<{ snooze: [until: string]; wake: [] }>();

const custom = ref('');

const at = (date: Date, hour = 9) => setMinutes(setHours(date, hour), 0);

const options = () => {
    const now = new Date();

    return [
        { label: 'Later today', when: addHours(now, 3) },
        {
            label: 'This evening',
            when: at(now, 18),
            hide: now.getHours() >= 17,
        },
        { label: 'Tomorrow morning', when: at(startOfTomorrow(), 9) },
        { label: 'In 2 days', when: at(addDays(now, 2), 9) },
        { label: 'Next Monday', when: at(nextMonday(now), 9) },
        { label: 'In a week', when: at(addDays(now, 7), 9) },
    ].filter((o) => !o.hide);
};

const pick = (date: Date) => emit('snooze', date.toISOString());

const pickCustom = () => {
    if (!custom.value) {
        return;
    }

    pick(new Date(custom.value));
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                v-if="asIcon"
                variant="ghost"
                size="icon"
                class="size-8"
                :disabled="disabled"
                aria-label="Snooze"
                ><Clock class="size-4"
            /></Button>
            <Button
                v-else
                variant="outline"
                size="sm"
                class="h-8 gap-1.5 rounded-full px-2.5 text-xs"
                :disabled="disabled"
            >
                <Clock
                    class="size-3.5"
                    :class="props.snoozedUntil && 'text-highlight-foreground'"
                />
                {{
                    props.snoozedUntil
                        ? snoozeLabel(props.snoozedUntil)
                        : 'Later'
                }}
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-60">
            <DropdownMenuLabel
                class="text-[11px] text-muted-foreground uppercase"
                >Bring back…</DropdownMenuLabel
            >
            <DropdownMenuItem
                v-for="option in options()"
                :key="option.label"
                class="justify-between"
                @click="pick(option.when)"
            >
                {{ option.label }}
                <span class="text-xs text-muted-foreground">{{
                    snoozeLabel(option.when.toISOString())
                }}</span>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <div
                class="flex items-center gap-1 px-2 py-1.5"
                @click.stop
                @keydown.stop
            >
                <CalendarClock class="size-3.5 text-muted-foreground" />
                <Input
                    v-model="custom"
                    type="datetime-local"
                    class="h-7 text-xs"
                />
                <Button
                    size="sm"
                    class="h-7 px-2 text-xs"
                    :disabled="!custom"
                    @click="pickCustom"
                    >Set</Button
                >
            </div>
            <template v-if="props.snoozedUntil">
                <DropdownMenuSeparator />
                <DropdownMenuItem @click="emit('wake')"
                    >Wake up now</DropdownMenuItem
                >
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
