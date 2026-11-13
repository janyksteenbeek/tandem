<script setup lang="ts">
import { ArrowRight, Keyboard } from '@lucide/vue';
import { ref, useId } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useCallStarter } from '@/composables/calls/useCallStarter';

/**
 * Paste a meeting code or a whole link, press Enter, and you are on your way into the room.
 */
withDefaults(defineProps<{ autofocus?: boolean; size?: 'default' | 'lg' }>(), {
    autofocus: false,
    size: 'default',
});

const { join, joining } = useCallStarter();
const code = ref('');
const error = ref<string | null>(null);
const errorId = useId();

const submit = async () => {
    error.value = await join(code.value);
};
</script>

<template>
    <form class="w-full" novalidate @submit.prevent="submit">
        <div class="flex items-center gap-2">
            <div class="relative min-w-0 flex-1">
                <Keyboard
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="code"
                    v-focus="autofocus"
                    :class="size === 'lg' ? 'h-10 pl-9' : 'h-9 pl-9'"
                    placeholder="Enter a code or paste a link"
                    aria-label="Meeting code or link"
                    autocomplete="off"
                    autocapitalize="none"
                    spellcheck="false"
                    :aria-invalid="error !== null"
                    :aria-describedby="error ? errorId : undefined"
                    @input="error = null"
                />
            </div>
            <Button
                type="submit"
                variant="outline"
                :size="size === 'lg' ? 'lg' : 'default'"
                class="shrink-0 gap-1.5 px-4"
                :disabled="joining || code.trim() === ''"
            >
                <Spinner v-if="joining" class="size-3.5" />
                Join
                <ArrowRight v-if="!joining" class="size-3.5" />
            </Button>
        </div>
        <p
            v-if="error"
            :id="errorId"
            role="alert"
            class="mt-1.5 text-[12.5px] text-destructive"
        >
            {{ error }}
        </p>
    </form>
</template>
