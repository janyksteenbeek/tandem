<script setup lang="ts">
import { computed } from 'vue';
import InboxDot from '@/components/mail/InboxDot.vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { TRIGGER_HINTS } from '@/lib/automations';
import type {
    AutomationCatalog,
    AutomationInboxOption,
} from '@/types/automations';

const props = defineProps<{
    catalog: AutomationCatalog;
    inboxes: AutomationInboxOption[];
    errors?: { trigger?: string; inbox_uuid?: string };
}>();

const trigger = defineModel<string>('trigger', { required: true });
const inbox = defineModel<string | null>('inbox', { default: null });

const ALL = '__all__';

const inboxModel = computed({
    get: () => inbox.value ?? ALL,
    set: (value: string) => (inbox.value = value === ALL ? null : value),
});

const hint = computed(() => TRIGGER_HINTS[trigger.value] ?? '');
const businessHours = computed(() =>
    trigger.value.startsWith('business_hours'),
);
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="grid gap-2">
            <Label for="rule-trigger">Trigger</Label>
            <Select v-model="trigger">
                <SelectTrigger
                    class="w-full"
                    :aria-invalid="errors?.trigger ? true : undefined"
                >
                    <SelectValue placeholder="Pick a moment" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in catalog.triggers"
                        :key="option.value"
                        :value="option.value"
                        >{{ option.label }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <p class="text-xs text-muted-foreground">{{ hint }}</p>
            <InputError :message="errors?.trigger" />
        </div>

        <div class="grid gap-2">
            <Label for="rule-inbox">Inbox scope</Label>
            <Select v-model="inboxModel">
                <SelectTrigger
                    class="w-full"
                    :aria-invalid="errors?.inbox_uuid ? true : undefined"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">All shared inboxes</SelectItem>
                    <SelectItem
                        v-for="option in props.inboxes"
                        :key="option.uuid"
                        :value="option.uuid"
                    >
                        <span class="flex items-center gap-2"
                            ><InboxDot :color="option.color" size="sm" />{{
                                option.name
                            }}</span
                        >
                    </SelectItem>
                </SelectContent>
            </Select>
            <p class="text-xs text-muted-foreground">
                <template v-if="inbox"
                    >Only conversations owned by this inbox. Shared-in
                    conversations do not count.</template
                >
                <template v-else-if="businessHours"
                    >Every open conversation in every shared inbox of the
                    organization.</template
                >
                <template v-else
                    >Personal inboxes never run automations; this covers every
                    shared one.</template
                >
            </p>
            <InputError :message="errors?.inbox_uuid" />
        </div>
    </div>
</template>
