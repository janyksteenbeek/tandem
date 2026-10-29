<script setup lang="ts">
import { Check, Plus, Tag as TagIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
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
import { chipClass } from '@/lib/mail';
import { cn } from '@/lib/utils';
import type { Tag } from '@/types';

const props = defineProps<{
    selected: Tag[];
    available: Tag[];
    disabled?: boolean;
}>();
const emit = defineEmits<{
    toggle: [tag: Tag | null, attached: boolean, name?: string];
}>();

const open = ref(false);
const query = ref('');

const selectedIds = computed(() => new Set(props.selected.map((t) => t.id)));
const canCreate = computed(
    () =>
        query.value.trim().length > 0 &&
        !props.available.some(
            (t) => t.name.toLowerCase() === query.value.trim().toLowerCase(),
        ),
);
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                variant="ghost"
                size="sm"
                class="h-7 gap-1 rounded-full border border-dashed px-2 text-xs text-muted-foreground hover:text-foreground"
                :disabled="disabled"
            >
                <TagIcon class="size-3" /> Tag
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-60 p-0" align="start">
            <Command>
                <CommandInput
                    v-model="query"
                    placeholder="Find or create a tag…"
                />
                <CommandList>
                    <CommandEmpty>
                        <button
                            v-if="canCreate"
                            type="button"
                            class="flex w-full items-center gap-2 px-2 py-1 text-sm"
                            @click="
                                emit('toggle', null, true, query.trim());
                                query = '';
                            "
                        >
                            <Plus class="size-3.5" /> Create “{{
                                query.trim()
                            }}”
                        </button>
                        <span v-else>No tags yet.</span>
                    </CommandEmpty>
                    <CommandGroup>
                        <CommandItem
                            v-for="tag in available"
                            :key="tag.id"
                            :value="tag.name"
                            class="gap-2"
                            @select="
                                emit('toggle', tag, !selectedIds.has(tag.id))
                            "
                        >
                            <span
                                :class="
                                    cn(
                                        'size-2.5 rounded-full bg-(--chip)',
                                        chipClass(tag.color),
                                    )
                                "
                            />
                            <span class="truncate">{{ tag.name }}</span>
                            <Check
                                v-if="selectedIds.has(tag.id)"
                                class="ml-auto size-3.5 text-brand"
                            />
                        </CommandItem>
                        <CommandItem
                            v-if="canCreate"
                            :value="`create-${query}`"
                            class="gap-2"
                            @select="
                                emit('toggle', null, true, query.trim());
                                query = '';
                            "
                        >
                            <Plus class="size-3.5" /> Create “{{
                                query.trim()
                            }}”
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
