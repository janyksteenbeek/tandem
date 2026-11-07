<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Collapse from '@/components/Collapse.vue';
import ActivityRow from '@/components/projects/detail/ActivityRow.vue';
import SectionLabel from '@/components/projects/detail/SectionLabel.vue';
import { cn } from '@/lib/utils';
import type { Activity } from '@/types';

const props = defineProps<{ activity: Activity[] | null | undefined }>();

const visible = computed(() =>
    (props.activity ?? []).filter((a) => a.type !== 'comment_added'),
);
const open = ref(true);
let initialised = false;

watch(
    () => props.activity,
    (value) => {
        if (value && !initialised) {
            initialised = true;
            open.value = visible.value.length <= 8;
        }
    },
    { immediate: true },
);

const days = computed(() => {
    const map = new Map<string, Activity[]>();

    for (const item of visible.value) {
        const day = new Date(item.created_at).toLocaleDateString(undefined, {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
        });
        map.set(day, [...(map.get(day) ?? []), item]);
    }

    return [...map.entries()];
});
</script>

<template>
    <section>
        <SectionLabel label="Activity">
            <template #meta>
                <span v-if="activity" class="font-normal normal-case">{{
                    visible.length
                }}</span>
            </template>
            <template #actions>
                <button
                    type="button"
                    class="flex h-6 items-center gap-1 rounded-md px-1.5 text-[11.5px] text-muted-foreground hover:bg-accent hover:text-foreground"
                    :aria-expanded="open"
                    @click="open = !open"
                >
                    <ChevronRight
                        :class="
                            cn(
                                'size-3 transition-transform',
                                open && 'rotate-90',
                            )
                        "
                    />
                    {{ open ? 'Collapse' : 'Expand' }}
                </button>
            </template>
        </SectionLabel>

        <div
            v-if="activity === undefined || activity === null"
            class="space-y-1.5"
        >
            <div v-for="i in 3" :key="i" class="h-5 w-2/3 shimmer rounded" />
        </div>

        <Collapse v-else :open="open">
            <div class="border-l pl-3">
                <div v-for="[day, items] in days" :key="day" class="mb-2">
                    <p
                        class="mb-0.5 text-[10.5px] font-semibold tracking-wide text-muted-foreground/70 uppercase"
                    >
                        {{ day }}
                    </p>
                    <ActivityRow
                        v-for="item in items"
                        :key="item.id"
                        :activity="item"
                    />
                </div>
                <p
                    v-if="visible.length === 0"
                    class="text-[12.5px] text-muted-foreground"
                >
                    No activity yet.
                </p>
            </div>
        </Collapse>
    </section>
</template>
