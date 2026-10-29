<script setup lang="ts">
import { Check, ChevronsUpDown, Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import InboxDot from '@/components/mail/InboxDot.vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { chipClass } from '@/lib/mail';
import { cn } from '@/lib/utils';
import type {
    AutomationCatalog,
    AutomationConditionValue,
    AutomationLookups,
    ConditionValueType,
    OperatorValueShape,
} from '@/types/automations';

const props = defineProps<{
    valueType: ConditionValueType;
    shape: OperatorValueShape;
    operator: string;
    catalog: AutomationCatalog;
    lookups: AutomationLookups;
    invalid?: boolean;
}>();

const model = defineModel<AutomationConditionValue>({ default: '' });

type Option = {
    value: string;
    label: string;
    color?: string | null;
    avatar?: string | null;
};

const options = computed<Option[]>(() => {
    switch (props.valueType) {
        case 'inbox':
            return props.lookups.inboxes.map((inbox) => ({
                value: inbox.uuid,
                label: inbox.name,
                color: inbox.color,
            }));
        case 'user':
            return props.lookups.users.map((user) => ({
                value: String(user.id),
                label: user.name,
                avatar: user.avatar,
            }));
        case 'tag':
            return props.lookups.tags.map((tag) => ({
                value: tag.slug,
                label: tag.name,
                color: tag.color,
            }));
        case 'status':
            return props.catalog.statuses;
        case 'priority':
            return props.catalog.priorities;
        default:
            return [];
    }
});

/**
 * Tags support fuzzy operators (contains) where free text makes more sense than a pick list.
 */
const usesPickList = computed(() => {
    if (['inbox', 'user', 'status', 'priority'].includes(props.valueType)) {
        return true;
    }

    return (
        props.valueType === 'tag' &&
        ['equals', 'not_equals', 'in', 'not_in'].includes(props.operator)
    );
});

const placeholder = computed(() => {
    switch (props.valueType) {
        case 'email':
            return props.operator === 'matches_regex'
                ? '^inv-\\d+@'
                : props.operator === 'ends_with'
                  ? '@acme.com'
                  : 'jane@acme.com or @acme.com';
        case 'tag':
            return 'vip';
        case 'text':
            return props.operator === 'matches_regex'
                ? '\\b(invoice|refund)\\b'
                : 'Type a word or phrase';
        default:
            return 'Value';
    }
});

const hint = computed(() => {
    if (props.valueType === 'email' && props.operator !== 'matches_regex') {
        return 'Tip: “@acme.com” matches every address at that domain.';
    }

    if (props.operator === 'matches_regex') {
        return 'Case-insensitive. Wrap in /…/ to pass your own flags.';
    }

    return null;
});

const single = computed({
    get: () =>
        Array.isArray(model.value)
            ? (model.value[0] ?? '')
            : (model.value ?? ''),
    set: (value: string) => (model.value = value),
});

const many = computed<string[]>({
    get: () =>
        Array.isArray(model.value)
            ? model.value
            : model.value
              ? [model.value]
              : [],
    set: (value: string[]) => (model.value = value),
});

const draft = ref('');
const pickOpen = ref(false);

const addDraft = () => {
    const pieces = draft.value
        .split(/[,\n]/)
        .map((piece) => piece.trim())
        .filter(Boolean);

    if (pieces.length === 0) {
        return;
    }

    many.value = Array.from(new Set([...many.value, ...pieces]));
    draft.value = '';
};

const removeAt = (index: number) => {
    many.value = many.value.filter((_, i) => i !== index);
};

const toggle = (value: string) => {
    many.value = many.value.includes(value)
        ? many.value.filter((item) => item !== value)
        : [...many.value, value];
};

const labelOf = (value: string) =>
    options.value.find((option) => option.value === value)?.label ?? value;

const onDraftKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Enter' || event.key === ',') {
        event.preventDefault();
        addDraft();
    } else if (
        event.key === 'Backspace' &&
        draft.value === '' &&
        many.value.length > 0
    ) {
        removeAt(many.value.length - 1);
    }
};
</script>

<template>
    <div
        v-if="shape === 'none'"
        class="flex h-9 items-center text-xs text-muted-foreground italic"
    >
        No value needed.
    </div>

    <!-- Multi-value pick list (inboxes, users, tags, statuses, priorities) -->
    <div v-else-if="shape === 'array' && usesPickList" class="min-w-0">
        <Popover v-model:open="pickOpen">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    :aria-invalid="invalid || undefined"
                    :class="
                        cn(
                            'flex min-h-9 w-full flex-wrap items-center gap-1 rounded-md border border-input bg-transparent px-2 py-1 text-left text-sm shadow-xs transition outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30',
                        )
                    "
                >
                    <span
                        v-if="many.length === 0"
                        class="px-1 text-muted-foreground"
                        >Pick one or more…</span
                    >
                    <span
                        v-for="value in many"
                        :key="value"
                        class="inline-flex items-center gap-1 rounded-full bg-secondary px-2 py-0.5 text-xs font-medium text-secondary-foreground"
                    >
                        {{ labelOf(value) }}
                    </span>
                    <ChevronsUpDown
                        class="ml-auto size-3.5 shrink-0 text-muted-foreground"
                    />
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" class="w-64 p-1">
                <p
                    v-if="options.length === 0"
                    class="p-3 text-xs text-muted-foreground"
                >
                    Nothing to pick from yet.
                </p>
                <button
                    v-for="option in options"
                    :key="option.value"
                    type="button"
                    class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent"
                    @click="toggle(option.value)"
                >
                    <span
                        class="flex size-4 items-center justify-center rounded border"
                        :class="
                            many.includes(option.value)
                                ? 'border-brand bg-brand text-brand-foreground'
                                : 'border-input'
                        "
                    >
                        <Check
                            v-if="many.includes(option.value)"
                            class="size-3"
                        />
                    </span>
                    <UserAvatar
                        v-if="valueType === 'user'"
                        :name="option.label"
                        :src="option.avatar"
                        size="xs"
                    />
                    <InboxDot
                        v-else-if="option.color !== undefined"
                        :color="option.color"
                        size="sm"
                    />
                    <span class="truncate">{{ option.label }}</span>
                </button>
            </PopoverContent>
        </Popover>
    </div>

    <!-- Multi-value free text chips (emails, domains, words) -->
    <div v-else-if="shape === 'array'" class="min-w-0">
        <div
            :aria-invalid="invalid || undefined"
            class="flex min-h-9 w-full flex-wrap items-center gap-1 rounded-md border border-input bg-transparent px-2 py-1 text-sm shadow-xs transition focus-within:ring-[3px] focus-within:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30"
        >
            <span
                v-for="(value, index) in many"
                :key="`${value}-${index}`"
                class="inline-flex items-center gap-1 rounded-full bg-brand-soft px-2 py-0.5 font-mono text-xs text-brand-soft-foreground"
            >
                {{ value }}
                <button
                    type="button"
                    class="rounded-full hover:bg-brand/20"
                    :aria-label="`Remove ${value}`"
                    @click="removeAt(index)"
                >
                    <X class="size-3" />
                </button>
            </span>
            <input
                v-model="draft"
                type="text"
                class="min-w-24 flex-1 bg-transparent px-1 py-0.5 outline-none placeholder:text-muted-foreground"
                :placeholder="
                    many.length === 0 ? `${placeholder}, …` : 'Add another'
                "
                @keydown="onDraftKeydown"
                @blur="addDraft"
            />
            <Button
                v-if="draft"
                type="button"
                variant="ghost"
                size="icon-xs"
                aria-label="Add value"
                @click="addDraft"
                ><Plus
            /></Button>
        </div>
        <p class="mt-1 text-[11px] text-muted-foreground">
            Press Enter or comma after each value.
        </p>
    </div>

    <!-- Single pick list -->
    <div v-else-if="usesPickList" class="min-w-0">
        <Select v-model="single">
            <SelectTrigger class="w-full" :aria-invalid="invalid || undefined">
                <SelectValue placeholder="Choose…" />
            </SelectTrigger>
            <SelectContent>
                <p
                    v-if="options.length === 0"
                    class="p-3 text-xs text-muted-foreground"
                >
                    Nothing to pick from yet.
                </p>
                <SelectItem
                    v-for="option in options"
                    :key="option.value"
                    :value="option.value"
                >
                    <span class="flex items-center gap-2">
                        <UserAvatar
                            v-if="valueType === 'user'"
                            :name="option.label"
                            :src="option.avatar"
                            size="xs"
                        />
                        <span
                            v-else-if="option.color !== undefined"
                            class="inline-block size-2.5 rounded-full bg-(--chip)"
                            :class="chipClass(option.color)"
                        />
                        {{ option.label }}
                    </span>
                </SelectItem>
            </SelectContent>
        </Select>
    </div>

    <!-- Single free text -->
    <div v-else class="min-w-0">
        <Input
            v-model="single"
            type="text"
            :placeholder="placeholder"
            :aria-invalid="invalid || undefined"
            :class="operator === 'matches_regex' ? 'font-mono text-xs' : ''"
            autocomplete="off"
        />
        <p v-if="hint" class="mt-1 text-[11px] text-muted-foreground">
            {{ hint }}
        </p>
    </div>
</template>
