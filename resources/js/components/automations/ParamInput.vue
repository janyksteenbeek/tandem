<script setup lang="ts">
import { Check, ChevronsUpDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { PARAM_HINTS, PARAM_LABELS, statusOptionsFor } from '@/lib/automations';
import { chipClass } from '@/lib/mail';
import { cn } from '@/lib/utils';
import type {
    AutomationActionParams,
    AutomationCatalog,
    AutomationLookups,
    AutomationParamValue,
    CatalogActionParam,
} from '@/types/automations';
import type { StatusCategory } from '@/types/projects';

/**
 * Renders one catalog-described action parameter by its declared type, so new actions work
 * without a hand-written branch. Team-bound parameters (a workstream status) narrow their
 * options to the team chosen in the sibling `team_id` parameter.
 */
const props = defineProps<{
    id: string;
    param: CatalogActionParam;
    actionType: string;
    params: AutomationActionParams;
    catalog: AutomationCatalog;
    lookups: AutomationLookups;
    error?: string;
    label?: string;
}>();

const model = defineModel<AutomationParamValue>({ default: null });

type Option = {
    value: string;
    label: string;
    hint?: string;
    color?: string | null;
    avatar?: string | null;
    category?: StatusCategory;
};

const NONE = '__none__';

const label = computed(
    () =>
        props.label ??
        PARAM_LABELS[props.param.name] ??
        props.param.name.replace(/_/g, ' '),
);
const hint = computed(() => PARAM_HINTS[props.param.name] ?? null);

const teamContext = computed<number | null>(() => {
    const raw = props.params.team_id;

    return typeof raw === 'number' || (typeof raw === 'string' && raw !== '')
        ? Number(raw)
        : null;
});

const options = computed<Option[]>(() => {
    const issues = props.lookups.issues;

    switch (props.param.type) {
        case 'product':
            return (issues?.products ?? []).map((product) => ({
                value: String(product.id),
                label: product.name,
                hint: product.key,
                color: product.color,
            }));
        case 'project':
            return (issues?.projects ?? []).map((project) => ({
                value: String(project.id),
                label: project.name,
                color: project.color,
            }));
        case 'team':
        case 'teams':
            return (issues?.teams ?? props.lookups.teams).map((team) => ({
                value: String(team.id),
                label: team.name,
                color: team.color,
            }));
        case 'user':
        case 'users':
            return (issues?.members ?? props.lookups.users).map((user) => ({
                value: String(user.id),
                label: user.name,
                avatar: user.avatar,
            }));
        case 'label':
        case 'labels':
            return (issues?.labels ?? []).map((item) => ({
                value: String(item.id),
                label: item.name,
                color: item.color,
            }));
        case 'workflow_status': {
            const scoped =
                props.actionType === 'set_workstream_status' ||
                props.actionType === 'assign_workstream';

            return statusOptionsFor(
                props.lookups,
                scoped ? teamContext.value : null,
            ).map((status) => ({
                value: String(status.id),
                label: status.name,
                hint: scoped ? undefined : status.group,
                color: status.color,
                category: status.category,
            }));
        }
        case 'select':
            return props.param.options ?? [];
        default:
            return [];
    }
});

const isMulti = computed(() =>
    ['teams', 'users', 'labels'].includes(props.param.type),
);

const single = computed({
    get: () => {
        const value = model.value;

        if (value === null || value === undefined || value === '') {
            return NONE;
        }

        return String(value);
    },
    set: (value: string) => {
        if (value === NONE) {
            model.value = null;

            return;
        }

        model.value = props.param.type === 'select' ? value : Number(value);
    },
});

const many = computed<string[]>(() =>
    Array.isArray(model.value) ? model.value.map(String) : [],
);

const toggle = (value: string) => {
    const next = many.value.includes(value)
        ? many.value.filter((item) => item !== value)
        : [...many.value, value];

    model.value = next.map(Number);
};

const text = computed({
    get: () =>
        model.value === null || model.value === undefined
            ? ''
            : String(model.value),
    set: (value: string | number) => (model.value = String(value)),
});

const bool = computed({
    get: () => Boolean(model.value),
    set: (value: boolean) => (model.value = value),
});

const pickOpen = ref(false);
const labelOf = (value: string) =>
    options.value.find((option) => option.value === value)?.label ?? value;
const emptyText = computed(() => {
    if (props.param.type === 'workflow_status' && teamContext.value === null) {
        if (props.actionType === 'set_workstream_status') {
            return 'Pick a team first.';
        }
    }

    return 'Nothing to pick from yet.';
});
</script>

<template>
    <!-- Boolean -->
    <div
        v-if="param.type === 'boolean'"
        class="flex items-center justify-between gap-3 rounded-lg bg-surface px-3 py-2"
    >
        <div class="min-w-0">
            <Label :for="id" class="cursor-pointer">{{ label }}</Label>
            <p v-if="hint" class="text-[11px] text-muted-foreground">
                {{ hint }}
            </p>
        </div>
        <Switch :id="id" v-model="bool" class="data-[state=checked]:bg-brand" />
    </div>

    <!-- Long text -->
    <div v-else-if="param.type === 'text'" class="grid gap-1.5">
        <Label :for="id">{{ label }}</Label>
        <Textarea
            :id="id"
            v-model="text"
            rows="3"
            maxlength="5000"
            :aria-invalid="error ? true : undefined"
        />
        <p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
        <InputError :message="error" />
    </div>

    <!-- Short text / number -->
    <div
        v-else-if="param.type === 'string' || param.type === 'number'"
        class="grid gap-1.5"
    >
        <Label :for="id"
            >{{ label }}
            <span
                v-if="!param.required"
                class="font-normal text-muted-foreground"
                >(optional)</span
            ></Label
        >
        <Input
            :id="id"
            v-model="text"
            :type="param.type === 'number' ? 'number' : 'text'"
            :placeholder="hint ?? undefined"
            :class="param.name === 'identifier' ? 'font-mono uppercase' : ''"
            :aria-invalid="error ? true : undefined"
            autocomplete="off"
        />
        <p
            v-if="hint && param.name !== 'identifier'"
            class="text-xs text-muted-foreground"
        >
            {{ hint }}
        </p>
        <InputError :message="error" />
    </div>

    <!-- Multi pick -->
    <div v-else-if="isMulti" class="grid gap-1.5">
        <Label
            >{{ label }}
            <span
                v-if="!param.required"
                class="font-normal text-muted-foreground"
                >(optional)</span
            ></Label
        >
        <Popover v-model:open="pickOpen">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    :aria-invalid="error ? true : undefined"
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
                        >{{ labelOf(value) }}</span
                    >
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
                    {{ emptyText }}
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
                        v-if="param.type === 'users'"
                        :name="option.label"
                        :src="option.avatar"
                        size="xs"
                    />
                    <span
                        v-else
                        class="inline-block size-2.5 rounded-full bg-(--chip)"
                        :class="chipClass(option.color)"
                    />
                    <span class="truncate">{{ option.label }}</span>
                </button>
            </PopoverContent>
        </Popover>
        <p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
        <InputError :message="error" />
    </div>

    <!-- Single pick -->
    <div v-else class="grid gap-1.5">
        <Label :for="id"
            >{{ label }}
            <span
                v-if="!param.required"
                class="font-normal text-muted-foreground"
                >(optional)</span
            ></Label
        >
        <Select v-model="single">
            <SelectTrigger
                :id="id"
                class="w-full"
                :aria-invalid="error ? true : undefined"
            >
                <SelectValue placeholder="Choose…" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem v-if="!param.required" :value="NONE"
                    ><span class="text-muted-foreground">None</span></SelectItem
                >
                <p
                    v-if="options.length === 0"
                    class="p-3 text-xs text-muted-foreground"
                >
                    {{ emptyText }}
                </p>
                <SelectItem
                    v-for="option in options"
                    :key="option.value"
                    :value="option.value"
                >
                    <span class="flex items-center gap-2">
                        <UserAvatar
                            v-if="param.type === 'user'"
                            :name="option.label"
                            :src="option.avatar"
                            size="xs"
                        />
                        <StatusIcon
                            v-else-if="param.type === 'workflow_status'"
                            :category="option.category"
                            :color="option.color"
                        />
                        <span
                            v-else-if="option.color !== undefined"
                            class="inline-block size-2.5 rounded-full bg-(--chip)"
                            :class="chipClass(option.color)"
                        />
                        {{ option.label }}
                        <span
                            v-if="option.hint"
                            class="font-mono text-[11px] text-muted-foreground"
                            >{{ option.hint }}</span
                        >
                    </span>
                </SelectItem>
            </SelectContent>
        </Select>
        <p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
        <InputError :message="error" />
    </div>
</template>
