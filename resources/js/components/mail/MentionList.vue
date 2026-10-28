<script setup lang="ts">
import { ref, watch } from 'vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import { cn } from '@/lib/utils';
import type { UserSummary } from '@/types';

const props = defineProps<{ items: UserSummary[]; command: (payload: { id: string; label: string }) => void }>();

const selected = ref(0);

watch(
    () => props.items,
    () => (selected.value = 0),
);

const select = (index: number) => {
    const item = props.items[index];

    if (item) {
        props.command({ id: String(item.id), label: item.name });
    }
};

defineExpose({
    onKeyDown: ({ event }: { event: KeyboardEvent }): boolean => {
        if (event.key === 'ArrowUp') {
            selected.value = (selected.value + props.items.length - 1) % props.items.length;

            return true;
        }

        if (event.key === 'ArrowDown') {
            selected.value = (selected.value + 1) % props.items.length;

            return true;
        }

        if (event.key === 'Enter' || event.key === 'Tab') {
            select(selected.value);

            return true;
        }

        return false;
    },
});
</script>

<template>
    <div class="w-64 overflow-hidden rounded-lg border bg-popover p-1 shadow-float animate-pop">
        <p v-if="items.length === 0" class="px-2 py-1.5 text-xs text-muted-foreground">No teammate matches.</p>
        <button
            v-for="(item, index) in items"
            :key="item.id"
            type="button"
            :class="cn('flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm', index === selected && 'bg-accent')"
            @click="select(index)"
            @mouseenter="selected = index"
        >
            <UserAvatar :name="item.name" :email="item.email" :src="item.avatar" :initials="item.initials" size="sm" />
            <span class="min-w-0">
                <span class="block truncate">{{ item.name }}</span>
                <span class="block truncate text-xs text-muted-foreground">{{ item.email }}</span>
            </span>
        </button>
    </div>
</template>
