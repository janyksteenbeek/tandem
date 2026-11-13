<script setup lang="ts">
import { CircleDot, Mail } from '@lucide/vue';
import { computed } from 'vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { SUBJECT_LABELS, TRIGGER_HINTS, triggersFor } from '@/lib/automations';
import { chipClass } from '@/lib/mail';
import { cn } from '@/lib/utils';
import type {
    AutomationCatalog,
    AutomationInboxOption,
    AutomationLookups,
    AutomationSubjectType,
} from '@/types/automations';

const props = defineProps<{
    catalog: AutomationCatalog;
    inboxes: AutomationInboxOption[];
    lookups: AutomationLookups;
    errors?: {
        trigger?: string;
        inbox_uuid?: string;
        product_id?: string;
        team_id?: string;
        subject_type?: string;
    };
}>();

const subject = defineModel<AutomationSubjectType>('subject', {
    default: 'conversation',
});
const trigger = defineModel<string>('trigger', { required: true });
const inbox = defineModel<string | null>('inbox', { default: null });
const product = defineModel<number | null>('product', { default: null });
const team = defineModel<number | null>('team', { default: null });

const ALL = '__all__';

const subjects = computed<{ value: AutomationSubjectType; label: string }[]>(
    () =>
        (props.catalog.subjects ?? []).length > 0
            ? (props.catalog.subjects ?? []).map((option) => ({
                  value: option.value as AutomationSubjectType,
                  label: option.label,
              }))
            : (Object.keys(SUBJECT_LABELS) as AutomationSubjectType[]).map(
                  (value) => ({ value, label: SUBJECT_LABELS[value] }),
              ),
);

const triggers = computed(() => triggersFor(props.catalog, subject.value));

const inboxModel = computed({
    get: () => inbox.value ?? ALL,
    set: (value: string) => (inbox.value = value === ALL ? null : value),
});

const productModel = computed({
    get: () => (product.value === null ? ALL : String(product.value)),
    set: (value: string) =>
        (product.value = value === ALL ? null : Number(value)),
});

const teamModel = computed({
    get: () => (team.value === null ? ALL : String(team.value)),
    set: (value: string) => (team.value = value === ALL ? null : Number(value)),
});

const products = computed(() => props.lookups.issues?.products ?? []);
const teams = computed(
    () => props.lookups.issues?.teams ?? props.lookups.teams,
);

const hint = computed(() => TRIGGER_HINTS[trigger.value] ?? '');
const businessHours = computed(() =>
    trigger.value.startsWith('business_hours'),
);
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-2">
            <Label>This rule is about</Label>
            <div
                class="inline-flex w-fit rounded-lg border bg-card p-0.5 text-sm"
                role="radiogroup"
                aria-label="Rule subject"
            >
                <button
                    v-for="option in subjects"
                    :key="option.value"
                    type="button"
                    role="radio"
                    :aria-checked="subject === option.value"
                    :data-test="`subject-${option.value}`"
                    :class="
                        cn(
                            'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 transition',
                            subject === option.value
                                ? 'bg-brand text-brand-foreground shadow-xs'
                                : 'text-muted-foreground hover:text-foreground',
                        )
                    "
                    @click="subject = option.value"
                >
                    <Mail
                        v-if="option.value === 'conversation'"
                        class="size-3.5"
                    />
                    <CircleDot v-else class="size-3.5" />
                    {{ option.label }}s
                </button>
            </div>
            <p class="text-xs text-muted-foreground">
                <template v-if="subject === 'issue'"
                    >Reacts to issues across products and teams. Switching
                    clears checks and actions that only work on
                    conversations.</template
                >
                <template v-else
                    >Reacts to mail in your shared inboxes.</template
                >
            </p>
            <InputError :message="errors?.subject_type" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="rule-trigger">Trigger</Label>
                <Select v-model="trigger">
                    <SelectTrigger
                        id="rule-trigger"
                        class="w-full"
                        :aria-invalid="errors?.trigger ? true : undefined"
                    >
                        <SelectValue placeholder="Pick a moment" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in triggers"
                            :key="option.value"
                            :value="option.value"
                            >{{ option.label }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <p class="text-xs text-muted-foreground">{{ hint }}</p>
                <InputError :message="errors?.trigger" />
            </div>

            <!-- Conversation scope -->
            <div v-if="subject === 'conversation'" class="grid gap-2">
                <Label for="rule-inbox">Inbox scope</Label>
                <Select v-model="inboxModel">
                    <SelectTrigger
                        id="rule-inbox"
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
                        >Personal inboxes never run automations; this covers
                        every shared one.</template
                    >
                </p>
                <InputError :message="errors?.inbox_uuid" />
            </div>

            <!-- Issue scope -->
            <div v-else class="grid gap-3">
                <div class="grid gap-2">
                    <Label for="rule-product">Product scope</Label>
                    <Select v-model="productModel">
                        <SelectTrigger
                            id="rule-product"
                            class="w-full"
                            :aria-invalid="
                                errors?.product_id ? true : undefined
                            "
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">Any product</SelectItem>
                            <SelectItem
                                v-for="option in products"
                                :key="option.id"
                                :value="String(option.id)"
                            >
                                <span class="flex items-center gap-2">
                                    <span
                                        class="inline-block size-2.5 rounded-full bg-(--chip)"
                                        :class="chipClass(option.color)"
                                    />
                                    {{ option.name }}
                                    <span
                                        class="font-mono text-[11px] text-muted-foreground"
                                        >{{ option.key }}</span
                                    >
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errors?.product_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="rule-team">Team scope</Label>
                    <Select v-model="teamModel">
                        <SelectTrigger
                            id="rule-team"
                            class="w-full"
                            :aria-invalid="errors?.team_id ? true : undefined"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">Any team</SelectItem>
                            <SelectItem
                                v-for="option in teams"
                                :key="option.id"
                                :value="String(option.id)"
                            >
                                <span class="flex items-center gap-2">
                                    <span
                                        class="inline-block size-2.5 rounded-full bg-(--chip)"
                                        :class="chipClass(option.color)"
                                    />
                                    {{ option.name }}
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="team"
                            >Only issues this team leads or works on.</template
                        >
                        <template v-else
                            >Any issue, whichever teams are involved.</template
                        >
                    </p>
                    <InputError :message="errors?.team_id" />
                </div>
            </div>
        </div>
    </div>
</template>
