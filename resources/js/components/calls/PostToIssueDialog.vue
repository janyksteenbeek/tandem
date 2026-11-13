<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { api, reportError } from '@/lib/http';
import { comment } from '@/routes/calls/links';
import type { CallLinkItem } from '@/types';

/**
 * Leaves a comment on a linked issue that points back at this meeting: its chat and, if
 * wanted, its recordings. The links only open for people who may see the meeting.
 */
const props = defineProps<{
    callUuid: string;
    link: CallLinkItem | null;
    hasRecordings: boolean;
}>();
const open = defineModel<boolean>('open', { default: false });

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const note = ref('');
const includeRecordings = ref(true);
const busy = ref(false);

watch(open, (value) => {
    if (value) {
        note.value = '';
        includeRecordings.value = props.hasRecordings;
    }
});

const post = async () => {
    if (!props.link) {
        return;
    }

    busy.value = true;

    try {
        await api.post(
            comment.url([slug.value, props.callUuid, props.link.id]),
            {
                include_recordings: includeRecordings.value,
                note: note.value.trim() || null,
            },
        );
        toast.success(`Posted to ${props.link.key}`);
        open.value = false;
    } catch (error) {
        reportError(error, 'The comment could not be posted.');
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Post to {{ link?.key }}</DialogTitle>
                <DialogDescription>
                    Adds a comment to “{{ link?.title }}” with a link to this
                    meeting's chat.
                </DialogDescription>
            </DialogHeader>

            <form id="post-to-issue" class="space-y-3" @submit.prevent="post">
                <div class="space-y-1.5">
                    <Label for="post-note" class="text-xs"
                        >Note
                        <span class="font-normal text-muted-foreground"
                            >optional</span
                        ></Label
                    >
                    <Textarea
                        id="post-note"
                        v-model="note"
                        rows="3"
                        maxlength="2000"
                        placeholder="What was decided?"
                    />
                </div>
                <label
                    class="flex items-center justify-between gap-4 rounded-lg border px-3.5 py-2.5"
                    :class="
                        hasRecordings ? 'cursor-pointer' : 'cursor-not-allowed'
                    "
                >
                    <span class="text-[13px]">
                        <span class="block font-medium"
                            >Attach the recordings</span
                        >
                        <span class="block text-[12px] text-muted-foreground">{{
                            hasRecordings
                                ? 'Adds a link to each finished recording.'
                                : 'This meeting has no finished recording.'
                        }}</span>
                    </span>
                    <Switch
                        v-model="includeRecordings"
                        :disabled="!hasRecordings"
                    />
                </label>
            </form>

            <DialogFooter>
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="busy"
                    @click="open = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="post-to-issue"
                    size="sm"
                    class="gap-1.5"
                    :disabled="busy"
                >
                    <Spinner v-if="busy" class="size-3.5" /> Post comment
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
