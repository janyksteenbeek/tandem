<script setup lang="ts">
import { Check, ChevronsUpDown, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import TemplatePicker from '@/components/automations/TemplatePicker.vue';
import InboxDot from '@/components/mail/InboxDot.vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { defaultParams, formatMinutes, variableToken } from '@/lib/automations';
import { chipClass } from '@/lib/mail';
import type { AutomationAction, AutomationCatalog, AutomationLookups, AutomationParamValue } from '@/types/automations';

const props = defineProps<{
    index: number;
    catalog: AutomationCatalog;
    lookups: AutomationLookups;
    errors?: Record<string, string | undefined>;
}>();

const action = defineModel<AutomationAction>({ required: true });
const emit = defineEmits<{ remove: [] }>();

const NEW_TAG = '__new__';
const notifyOpen = ref(false);
const commentEl = ref<InstanceType<typeof Textarea> | null>(null);

const setType = (type: string) => {
    action.value = { type, params: defaultParams(props.catalog, type) };
};

const setParam = (key: string, value: AutomationParamValue) => {
    action.value = { ...action.value, params: { ...action.value.params, [key]: value } };
};

const str = (key: string): string => {
    const value = action.value.params[key];

    return value === null || value === undefined ? '' : String(value);
};

const idModel = (key: string) =>
    computed({
        get: () => str(key),
        set: (value: string) => setParam(key, value === '' ? null : Number(value)),
    });

const userId = idModel('user_id');
const teamId = idModel('team_id');
const templateId = computed({
    get: () => (action.value.params.template_id as number | null) ?? null,
    set: (value: number | string | null) => setParam('template_id', value === null || value === '' ? null : Number(value)),
});

const tagMode = computed({
    get: () => (str('tag_id') !== '' ? str('tag_id') : NEW_TAG),
    set: (value: string) => {
        if (value === NEW_TAG) {
            action.value = { ...action.value, params: { ...action.value.params, tag_id: null } };
        } else {
            action.value = { ...action.value, params: { ...action.value.params, tag_id: Number(value), tag_name: '' } };
        }
    },
});

const notifyIds = computed<string[]>(() => (Array.isArray(action.value.params.user_ids) ? (action.value.params.user_ids as unknown[]).map(String) : []));

const toggleNotify = (id: number) => {
    const current = notifyIds.value;
    const next = current.includes(String(id)) ? current.filter((item) => item !== String(id)) : [...current, String(id)];

    setParam('user_ids', next.map(Number));
};

const cooldownLabel = computed(() => formatMinutes(Number(str('cooldown_minutes')) || null));

const insertVariable = (key: string) => {
    const body = str('body');
    const root = (commentEl.value as unknown as { $el?: HTMLTextAreaElement })?.$el;
    const textarea = root instanceof HTMLTextAreaElement ? root : null;
    const start = textarea?.selectionStart ?? body.length;
    const end = textarea?.selectionEnd ?? body.length;
    const token = variableToken(key);

    setParam('body', `${body.slice(0, start)}${token}${body.slice(end)}`);

    requestAnimationFrame(() => {
        textarea?.focus();
        textarea?.setSelectionRange(start + token.length, start + token.length);
    });
};

const error = (key: string) => props.errors?.[key];
</script>

<template>
    <div class="group relative animate-rise rounded-xl border bg-card p-3 shadow-pane sm:pl-14">
        <span class="mb-2 inline-flex h-6 items-center rounded-full bg-surface-sunken px-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase sm:absolute sm:top-4 sm:left-3 sm:mb-0">
            {{ index === 0 ? 'Then' : 'and' }}
        </span>

        <div class="flex items-start gap-2">
            <div class="min-w-0 flex-1">
                <Select :model-value="action.type" @update:model-value="(v) => setType(String(v))">
                    <SelectTrigger class="w-full sm:w-64" :aria-invalid="errors?.type ? true : undefined" aria-label="Action">
                        <SelectValue placeholder="Choose an action" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="option in catalog.actions" :key="option.value" :value="option.value">{{ option.label }}</SelectItem>
                    </SelectContent>
                </Select>
                <InputError class="mt-1" :message="errors?.type" />
            </div>
            <Button type="button" variant="ghost" size="icon-sm" class="text-muted-foreground hover:text-destructive" aria-label="Remove action" @click="emit('remove')">
                <Trash2 />
            </Button>
        </div>

        <div class="mt-3 grid gap-3">
            <!-- reply_with_template -->
            <template v-if="action.type === 'reply_with_template'">
                <div class="grid gap-1.5">
                    <Label>Template</Label>
                    <TemplatePicker v-model="templateId" :templates="lookups.templates" :invalid="Boolean(error('template_id'))" />
                    <InputError :message="error('template_id')" />
                </div>
                <div class="grid gap-1.5 sm:max-w-xs">
                    <Label :for="`action-${index}-cooldown`">Reply at most once per sender every</Label>
                    <div class="flex items-center gap-2">
                        <Input :id="`action-${index}-cooldown`" type="number" min="0" step="1" class="w-28" :model-value="str('cooldown_minutes')" :aria-invalid="error('cooldown_minutes') ? true : undefined" @update:model-value="(v) => setParam('cooldown_minutes', v === '' ? null : Number(v))" />
                        <span class="text-sm text-muted-foreground">minutes · {{ cooldownLabel }}</span>
                    </div>
                    <p class="text-xs text-muted-foreground">Never replies to robots, bulk mail or your own addresses.</p>
                    <InputError :message="error('cooldown_minutes')" />
                </div>
            </template>

            <!-- assign_user / add_follower -->
            <div v-else-if="action.type === 'assign_user' || action.type === 'add_follower'" class="grid gap-1.5 sm:max-w-sm">
                <Label>{{ action.type === 'assign_user' ? 'Assignee' : 'Follower' }}</Label>
                <Select v-model="userId">
                    <SelectTrigger class="w-full" :aria-invalid="error('user_id') ? true : undefined"><SelectValue placeholder="Pick a teammate" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="user in lookups.users" :key="user.id" :value="String(user.id)">
                            <span class="flex items-center gap-2"><UserAvatar :name="user.name" :src="user.avatar" size="xs" />{{ user.name }}</span>
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="error('user_id')" />
            </div>

            <!-- assign_team -->
            <div v-else-if="action.type === 'assign_team'" class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label>Team</Label>
                    <Select v-model="teamId">
                        <SelectTrigger class="w-full" :aria-invalid="error('team_id') ? true : undefined"><SelectValue placeholder="Pick a team" /></SelectTrigger>
                        <SelectContent>
                            <p v-if="lookups.teams.length === 0" class="p-3 text-xs text-muted-foreground">No teams yet. Create one under Organization settings.</p>
                            <SelectItem v-for="team in lookups.teams" :key="team.id" :value="String(team.id)">
                                <span class="flex items-center gap-2">
                                    <span class="inline-block size-2.5 rounded-full bg-(--chip)" :class="chipClass(team.color)" />
                                    {{ team.name }}<span class="text-xs text-muted-foreground"> · {{ team.member_count }} {{ team.member_count === 1 ? 'member' : 'members' }}</span>
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="error('team_id')" />
                </div>
                <div class="grid gap-1.5">
                    <Label>Hand out work</Label>
                    <Select :model-value="str('strategy')" @update:model-value="(v) => setParam('strategy', String(v))">
                        <SelectTrigger class="w-full" :aria-invalid="error('strategy') ? true : undefined"><SelectValue placeholder="Strategy" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="option in catalog.strategies" :key="option.value" :value="option.value">{{ option.label }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">{{ str('strategy') === 'least_busy' ? 'Whoever has the fewest open conversations.' : 'Take turns, one after another.' }}</p>
                    <InputError :message="error('strategy')" />
                </div>
            </div>

            <!-- move_to_inbox -->
            <div v-else-if="action.type === 'move_to_inbox'" class="grid gap-1.5 sm:max-w-sm">
                <Label>Destination</Label>
                <Select :model-value="str('inbox_uuid')" @update:model-value="(v) => setParam('inbox_uuid', v === '' ? null : String(v))">
                    <SelectTrigger class="w-full" :aria-invalid="error('inbox_uuid') ? true : undefined"><SelectValue placeholder="Pick an inbox" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="inbox in lookups.inboxes" :key="inbox.uuid" :value="inbox.uuid">
                            <span class="flex items-center gap-2"><InboxDot :color="inbox.color" size="sm" />{{ inbox.name }}</span>
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="error('inbox_uuid')" />
            </div>

            <!-- add_tag / remove_tag -->
            <div v-else-if="action.type === 'add_tag' || action.type === 'remove_tag'" class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label>Tag</Label>
                    <Select v-model="tagMode">
                        <SelectTrigger class="w-full" :aria-invalid="error('tag_id') ? true : undefined"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NEW_TAG">{{ action.type === 'add_tag' ? 'Type a tag name…' : 'By name…' }}</SelectItem>
                            <SelectItem v-for="tag in lookups.tags" :key="tag.id" :value="String(tag.id)">
                                <span class="flex items-center gap-2"><span class="inline-block size-2.5 rounded-full bg-(--chip)" :class="chipClass(tag.color)" />{{ tag.name }}</span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="error('tag_id')" />
                </div>
                <div v-if="tagMode === NEW_TAG" class="grid gap-1.5">
                    <Label :for="`action-${index}-tag-name`">Tag name</Label>
                    <Input :id="`action-${index}-tag-name`" :model-value="str('tag_name')" placeholder="e.g. VIP" maxlength="60" :aria-invalid="error('tag_name') ? true : undefined" @update:model-value="(v) => setParam('tag_name', String(v))" />
                    <p class="text-xs text-muted-foreground">{{ action.type === 'add_tag' ? 'Created on the fly if it does not exist yet.' : 'Nothing happens if the tag is not attached.' }}</p>
                    <InputError :message="error('tag_name')" />
                </div>
            </div>

            <!-- set_priority -->
            <div v-else-if="action.type === 'set_priority'" class="grid gap-1.5 sm:max-w-xs">
                <Label>Priority</Label>
                <Select :model-value="str('priority')" @update:model-value="(v) => setParam('priority', String(v))">
                    <SelectTrigger class="w-full" :aria-invalid="error('priority') ? true : undefined"><SelectValue placeholder="Pick a priority" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="option in catalog.priorities" :key="option.value" :value="option.value">{{ option.label }}</SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="error('priority')" />
            </div>

            <!-- set_status -->
            <div v-else-if="action.type === 'set_status'" class="grid gap-1.5 sm:max-w-xs">
                <Label>Status</Label>
                <Select :model-value="str('status')" @update:model-value="(v) => setParam('status', String(v))">
                    <SelectTrigger class="w-full" :aria-invalid="error('status') ? true : undefined"><SelectValue placeholder="Pick a status" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="option in catalog.statuses" :key="option.value" :value="option.value">{{ option.label }}</SelectItem>
                    </SelectContent>
                </Select>
                <p class="text-xs text-muted-foreground">Snoozing is a human thing; rules cannot do it.</p>
                <InputError :message="error('status')" />
            </div>

            <!-- notify_users -->
            <template v-else-if="action.type === 'notify_users'">
                <div class="grid gap-1.5 sm:max-w-sm">
                    <Label>Who to notify</Label>
                    <Popover v-model:open="notifyOpen">
                        <PopoverTrigger as-child>
                            <button
                                type="button"
                                :aria-invalid="error('user_ids') ? true : undefined"
                                class="flex min-h-9 w-full flex-wrap items-center gap-1 rounded-md border border-input bg-transparent px-2 py-1 text-left text-sm shadow-xs transition outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30"
                            >
                                <span v-if="notifyIds.length === 0" class="px-1 text-muted-foreground">Pick teammates…</span>
                                <span v-for="id in notifyIds" :key="id" class="inline-flex items-center gap-1 rounded-full bg-secondary py-0.5 pr-2 pl-0.5 text-xs font-medium">
                                    <UserAvatar :name="lookups.users.find((u) => String(u.id) === id)?.name ?? id" size="xs" />
                                    {{ lookups.users.find((u) => String(u.id) === id)?.name ?? `#${id}` }}
                                </span>
                                <ChevronsUpDown class="ml-auto size-3.5 shrink-0 text-muted-foreground" />
                            </button>
                        </PopoverTrigger>
                        <PopoverContent align="start" class="w-64 p-1">
                            <button v-for="user in lookups.users" :key="user.id" type="button" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent" @click="toggleNotify(user.id)">
                                <span class="flex size-4 items-center justify-center rounded border" :class="notifyIds.includes(String(user.id)) ? 'border-brand bg-brand text-brand-foreground' : 'border-input'">
                                    <Check v-if="notifyIds.includes(String(user.id))" class="size-3" />
                                </span>
                                <UserAvatar :name="user.name" :src="user.avatar" size="xs" />
                                <span class="truncate">{{ user.name }}</span>
                            </button>
                        </PopoverContent>
                    </Popover>
                    <InputError :message="error('user_ids')" />
                </div>
                <div class="grid gap-1.5">
                    <Label :for="`action-${index}-message`">Message <span class="font-normal text-muted-foreground">(optional)</span></Label>
                    <Input :id="`action-${index}-message`" :model-value="str('message')" maxlength="500" placeholder="VIP conversation received" :aria-invalid="error('message') ? true : undefined" @update:model-value="(v) => setParam('message', String(v))" />
                    <InputError :message="error('message')" />
                </div>
            </template>

            <!-- add_comment -->
            <div v-else-if="action.type === 'add_comment'" class="grid gap-1.5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <Label :for="`action-${index}-body`">Internal note</Label>
                    <Select :model-value="''" @update:model-value="(v) => insertVariable(String(v))">
                        <SelectTrigger size="sm" class="h-7 text-xs" aria-label="Insert variable"><SelectValue placeholder="Insert variable" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="variable in catalog.variables" :key="variable.key" :value="variable.key">
                                <span class="flex items-center gap-2"><code class="font-mono text-[11px] text-brand">{{ variableToken(variable.key) }}</code><span class="text-muted-foreground">{{ variable.label }}</span></span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <Textarea ref="commentEl" :id="`action-${index}-body`" :model-value="str('body')" rows="3" maxlength="5000" placeholder="Auto-flagged: {{ contact.email }} looks like a VIP account." :aria-invalid="error('body') ? true : undefined" @update:model-value="(v) => setParam('body', String(v))" />
                <p class="text-xs text-muted-foreground">Only your team sees this. Variables like <code class="font-mono">{{ variableToken('contact.name') }}</code> are filled in per conversation.</p>
                <InputError :message="error('body')" />
            </div>
        </div>
    </div>
</template>
