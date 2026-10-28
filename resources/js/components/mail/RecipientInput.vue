<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core';
import { X } from '@lucide/vue';
import { computed, ref } from 'vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import { api } from '@/lib/http';
import { cn } from '@/lib/utils';
import type { EmailAddress } from '@/types';

const props = defineProps<{ modelValue: EmailAddress[]; label: string; placeholder?: string; autofocus?: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [value: EmailAddress[]]; blur: [] }>();

const input = ref('');
const suggestions = ref<EmailAddress[]>([]);
const highlighted = ref(0);
const focused = ref(false);
const inputEl = ref<HTMLInputElement | null>(null);

const EMAIL = /^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/;

const fetchSuggestions = useDebounceFn(async (term: string) => {
    if (term.trim().length < 1) {
        suggestions.value = [];

        return;
    }

    try {
        const data = await api.get<{ contacts: EmailAddress[] }>('/contacts', { q: term });
        suggestions.value = data.contacts.filter((c) => !props.modelValue.some((v) => v.email === c.email));
        highlighted.value = 0;
    } catch {
        suggestions.value = [];
    }
}, 150);

const parse = (raw: string): EmailAddress | null => {
    const value = raw.trim().replace(/[,;]$/, '').trim();

    if (!value) {
        return null;
    }

    const match = value.match(/^"?([^"<]*)"?\s*<([^>]+)>$/);

    if (match && EMAIL.test(match[2].trim())) {
        return { name: match[1].trim() || null, email: match[2].trim().toLowerCase() };
    }

    return EMAIL.test(value) ? { name: null, email: value.toLowerCase() } : null;
};

const add = (address: EmailAddress) => {
    if (props.modelValue.some((v) => v.email === address.email)) {
        return;
    }

    emit('update:modelValue', [...props.modelValue, address]);
    input.value = '';
    suggestions.value = [];
};

const commitInput = (): boolean => {
    const parsed = parse(input.value);

    if (parsed) {
        add(parsed);

        return true;
    }

    return false;
};

const remove = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index));

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'ArrowDown' && suggestions.value.length) {
        event.preventDefault();
        highlighted.value = (highlighted.value + 1) % suggestions.value.length;
    } else if (event.key === 'ArrowUp' && suggestions.value.length) {
        event.preventDefault();
        highlighted.value = (highlighted.value - 1 + suggestions.value.length) % suggestions.value.length;
    } else if (event.key === 'Enter' || event.key === 'Tab' || event.key === ',' || event.key === ';') {
        if (suggestions.value.length && (event.key === 'Enter' || event.key === 'Tab')) {
            event.preventDefault();
            add(suggestions.value[highlighted.value]);
        } else if (input.value.trim()) {
            if (commitInput()) {
                event.preventDefault();
            }
        }
    } else if (event.key === 'Backspace' && !input.value && props.modelValue.length) {
        remove(props.modelValue.length - 1);
    } else if (event.key === 'Escape') {
        suggestions.value = [];
    }
};

const onInput = () => void fetchSuggestions(input.value);

const onBlur = () => {
    focused.value = false;
    commitInput();
    setTimeout(() => (suggestions.value = []), 120);
    emit('blur');
};

const onPaste = (event: ClipboardEvent) => {
    const text = event.clipboardData?.getData('text') ?? '';

    if (text.includes(',') || text.includes(';') || text.includes('\n')) {
        event.preventDefault();
        text.split(/[,;\n]/).map(parse).forEach((a) => a && add(a));
    }
};

const invalid = computed(() => input.value.trim().length > 0 && !parse(input.value));

defineExpose({ focus: () => inputEl.value?.focus() });
</script>

<template>
    <div class="relative flex min-h-9 flex-wrap items-center gap-1 border-b border-border/70 py-1 text-sm" @click="inputEl?.focus()">
        <span class="w-10 shrink-0 text-xs text-muted-foreground">{{ label }}</span>
        <span
            v-for="(address, index) in modelValue"
            :key="address.email"
            class="group/chip flex items-center gap-1 rounded-full border bg-surface py-0.5 pr-1 pl-0.5 text-xs"
            :title="address.email"
        >
            <UserAvatar :name="address.name" :email="address.email" size="xs" />
            <span class="max-w-48 truncate">{{ address.name || address.email }}</span>
            <button type="button" class="rounded-full p-0.5 text-muted-foreground hover:bg-accent hover:text-foreground" aria-label="Remove recipient" @click.stop="remove(index)">
                <X class="size-3" />
            </button>
        </span>
        <input
            ref="inputEl"
            v-model="input"
            type="text"
            :placeholder="modelValue.length ? '' : placeholder"
            :autofocus="autofocus"
            :class="cn('min-w-32 flex-1 bg-transparent py-1 text-sm outline-none placeholder:text-muted-foreground', invalid && 'text-destructive')"
            autocomplete="off"
            spellcheck="false"
            @keydown="onKeydown"
            @input="onInput"
            @focus="focused = true"
            @blur="onBlur"
            @paste="onPaste"
        />
        <ul v-if="focused && suggestions.length" class="absolute top-full left-10 z-30 mt-1 w-80 overflow-hidden rounded-lg border bg-popover shadow-float animate-pop">
            <li
                v-for="(suggestion, index) in suggestions"
                :key="suggestion.email"
                :class="cn('flex cursor-pointer items-center gap-2 px-2.5 py-1.5 text-sm', index === highlighted && 'bg-accent')"
                @mousedown.prevent="add(suggestion)"
                @mouseenter="highlighted = index"
            >
                <UserAvatar :name="suggestion.name" :email="suggestion.email" size="sm" />
                <span class="min-w-0">
                    <span class="block truncate">{{ suggestion.name || suggestion.email }}</span>
                    <span v-if="suggestion.name" class="block truncate text-xs text-muted-foreground">{{ suggestion.email }}</span>
                </span>
            </li>
        </ul>
    </div>
</template>
