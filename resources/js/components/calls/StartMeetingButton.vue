<script setup lang="ts">
import { ChevronDown, Video } from '@lucide/vue';
import { reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { useCallStarter } from '@/composables/calls/useCallStarter';
import { cn } from '@/lib/utils';
import type { CallSettings } from '@/types';

/**
 * One click starts a meeting and walks into it. The arrow next to it holds the few things
 * worth deciding beforehand (a name, a password, the waiting room); none of them are required.
 */
const props = withDefaults(
    defineProps<{
        defaults?: Partial<CallSettings> | null;
        size?: 'default' | 'lg';
        full?: boolean;
        label?: string;
    }>(),
    { defaults: null, size: 'default', full: false, label: 'Start meeting' },
);

const { start, starting } = useCallStarter();
const open = ref(false);

const options = reactive({
    title: '',
    password: '',
    waiting_room: props.defaults?.waiting_room ?? true,
    auto_email_transcript: props.defaults?.auto_email_transcript ?? false,
});

const startWithOptions = () => {
    open.value = false;
    start({ ...options });
};
</script>

<template>
    <div :class="cn('inline-flex items-stretch', full && 'flex w-full')">
        <Button
            :size="size"
            :class="
                cn(
                    'gap-2 rounded-r-none text-brand-foreground brand-button-surface',
                    size === 'lg' ? 'h-10 px-5 text-sm' : 'h-9 px-4',
                    full && 'flex-1 justify-start',
                )
            "
            :disabled="starting"
            @click="start()"
        >
            <Spinner v-if="starting" class="size-4" />
            <Video v-else class="size-4" />
            {{ label }}
        </Button>
        <Popover v-model:open="open">
            <PopoverTrigger as-child>
                <Button
                    :size="size"
                    :class="
                        cn(
                            'rounded-l-none border-l border-brand-foreground/20 px-2 text-brand-foreground brand-button-surface',
                            size === 'lg' ? 'h-10' : 'h-9',
                        )
                    "
                    aria-label="Meeting options"
                    :disabled="starting"
                >
                    <ChevronDown class="size-4" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" class="w-80 p-0">
                <form @submit.prevent="startWithOptions">
                    <div class="space-y-3 p-4">
                        <div class="space-y-1.5">
                            <Label for="call-title" class="text-xs"
                                >Name
                                <span class="font-normal text-muted-foreground"
                                    >optional</span
                                ></Label
                            >
                            <Input
                                id="call-title"
                                v-model="options.title"
                                class="h-8"
                                placeholder="Design review"
                                maxlength="200"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <Label for="call-password" class="text-xs"
                                >Password for guests
                                <span class="font-normal text-muted-foreground"
                                    >optional</span
                                ></Label
                            >
                            <Input
                                id="call-password"
                                v-model="options.password"
                                class="h-8"
                                type="text"
                                autocomplete="off"
                                minlength="4"
                                maxlength="200"
                                placeholder="At least 4 characters"
                            />
                        </div>
                        <label
                            class="flex cursor-pointer items-start justify-between gap-3"
                        >
                            <span>
                                <span class="block text-[13px] font-medium"
                                    >Waiting room</span
                                >
                                <span
                                    class="block text-[12px] text-muted-foreground"
                                    >Guests wait until a host lets them
                                    in.</span
                                >
                            </span>
                            <Switch
                                v-model="options.waiting_room"
                                class="mt-0.5"
                            />
                        </label>
                        <label
                            class="flex cursor-pointer items-start justify-between gap-3"
                        >
                            <span>
                                <span class="block text-[13px] font-medium"
                                    >Email the chat afterwards</span
                                >
                                <span
                                    class="block text-[12px] text-muted-foreground"
                                    >Sent to everyone who was in the
                                    meeting.</span
                                >
                            </span>
                            <Switch
                                v-model="options.auto_email_transcript"
                                class="mt-0.5"
                            />
                        </label>
                    </div>
                    <div
                        class="flex justify-end border-t bg-muted/30 px-4 py-2.5"
                    >
                        <Button type="submit" size="sm" :disabled="starting"
                            >Start with these options</Button
                        >
                    </div>
                </form>
            </PopoverContent>
        </Popover>
    </div>
</template>
