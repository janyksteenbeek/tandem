<script setup lang="ts">
import { Check, ChevronsUpDown, Globe } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

const props = defineProps<{
    timezones: string[];
    id?: string;
    class?: string;
}>();
const model = defineModel<string>({ default: 'UTC' });

const open = ref(false);
const offsets = new Map<string, string>();

const offset = (zone: string): string => {
    const cached = offsets.get(zone);

    if (cached !== undefined) {
        return cached;
    }

    let value = '';

    try {
        value =
            new Intl.DateTimeFormat('en', {
                timeZone: zone,
                timeZoneName: 'shortOffset',
            })
                .formatToParts(new Date())
                .find((part) => part.type === 'timeZoneName')?.value ?? '';
    } catch {
        value = '';
    }

    offsets.set(zone, value);

    return value;
};

const humanize = (zone: string): string => zone.replace(/_/g, ' ');

function pick(zone: string): void {
    model.value = zone;
    open.value = false;
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                :id="id"
                type="button"
                variant="outline"
                role="combobox"
                :aria-expanded="open"
                :class="cn('w-full justify-between font-normal', props.class)"
            >
                <span class="flex min-w-0 items-center gap-2"
                    ><Globe class="size-4 text-muted-foreground" /><span
                        class="truncate"
                        >{{ humanize(model) }}</span
                    ></span
                >
                <span
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                    >{{ offset(model) }}
                    <ChevronsUpDown class="size-3.5 opacity-60"
                /></span>
            </Button>
        </PopoverTrigger>
        <PopoverContent
            class="w-(--reka-popover-trigger-width) p-0"
            align="start"
        >
            <Command>
                <CommandInput placeholder="Search a city or region…" />
                <CommandList class="max-h-64">
                    <CommandEmpty>No timezone matches.</CommandEmpty>
                    <CommandGroup>
                        <CommandItem
                            v-for="zone in timezones"
                            :key="zone"
                            :value="humanize(zone)"
                            class="justify-between"
                            @select="pick(zone)"
                        >
                            <span class="truncate">{{ humanize(zone) }}</span>
                            <span
                                class="flex items-center gap-2 text-xs text-muted-foreground"
                                >{{ offset(zone)
                                }}<Check
                                    v-if="zone === model"
                                    class="size-3.5 text-brand"
                            /></span>
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
