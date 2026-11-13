<script setup lang="ts">
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

/**
 * Shown to everyone the moment a recording starts, and to anyone who joins while one is
 * running. It cannot be dismissed: you either stay, knowing you are recorded, or you leave.
 */
defineProps<{ open: boolean; startedBy: string | null }>();

const emit = defineEmits<{ stay: []; leave: [] }>();

const busy = ref(false);

const stay = () => {
    busy.value = true;
    emit('stay');
};
</script>

<template>
    <Dialog :open="open">
        <DialogContent
            :show-close-button="false"
            class="sm:max-w-md"
            data-testid="recording-consent"
            @escape-key-down.prevent
            @pointer-down-outside.prevent
            @interact-outside.prevent
        >
            <DialogHeader>
                <div class="mb-1 flex items-center gap-2.5">
                    <span class="relative flex size-3">
                        <span
                            class="absolute inline-flex size-full animate-ping rounded-full bg-destructive/60"
                        />
                        <span
                            class="relative inline-flex size-3 rounded-full bg-destructive"
                        />
                    </span>
                    <DialogTitle>This meeting is being recorded</DialogTitle>
                </div>
                <DialogDescription class="leading-relaxed">
                    {{
                        startedBy
                            ? `${startedBy} started a recording.`
                            : 'A recording is running.'
                    }}
                    Your voice, your camera and anything you share are captured
                    from now on and kept with the meeting. Stay only if you
                    agree to that.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    class="rounded-xl"
                    data-testid="consent-leave"
                    @click="emit('leave')"
                >
                    Leave meeting
                </Button>
                <Button
                    class="rounded-xl bg-brand text-brand-foreground hover:bg-brand/90"
                    :disabled="busy"
                    data-testid="consent-stay"
                    @click="stay"
                >
                    <Spinner v-if="busy" /> Stay in the meeting
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
