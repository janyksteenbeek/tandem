<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { settings as settingsRoute } from '@/routes/calls';
import type { CallOrganizationSettings } from '@/types';

/**
 * What every new meeting in the organization starts with, and how long recordings are kept.
 * Hosts can still change the per-meeting settings inside their own meeting.
 */
const props = defineProps<{ settings: CallOrganizationSettings }>();
const open = defineModel<boolean>('open', { default: false });

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const form = reactive({
    waiting_room: true,
    screen_share: 'everyone' as 'everyone' | 'hosts',
    recording: 'hosts' as 'hosts' | 'members',
    guest_uploads: true,
    auto_email_transcript: false,
    keep_forever: true,
    recording_retention_days: 90,
});
const errors = ref<Record<string, string>>({});
const saving = ref(false);

watch(
    open,
    (value) => {
        if (!value) {
            return;
        }

        const { defaults, recording_retention_days: days } = props.settings;

        Object.assign(form, {
            waiting_room: defaults.waiting_room,
            screen_share: defaults.screen_share,
            recording: defaults.recording,
            guest_uploads: defaults.guest_uploads,
            auto_email_transcript: defaults.auto_email_transcript,
            keep_forever: days === null,
            recording_retention_days: days ?? 90,
        });
        errors.value = {};
    },
    { immediate: true },
);

const save = () => {
    router.put(
        settingsRoute.url(slug.value),
        {
            waiting_room: form.waiting_room,
            screen_share: form.screen_share,
            recording: form.recording,
            guest_uploads: form.guest_uploads,
            auto_email_transcript: form.auto_email_transcript,
            recording_retention_days: form.keep_forever
                ? null
                : form.recording_retention_days,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
            onError: (bag) => (errors.value = bag),
            onSuccess: () => {
                open.value = false;
                toast.success('Call settings saved');
            },
        },
    );
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Call settings</DialogTitle>
                <DialogDescription>
                    Defaults for new meetings in this organization. A host can
                    change them for their own meeting.
                </DialogDescription>
            </DialogHeader>

            <form id="call-settings" class="space-y-5" @submit.prevent="save">
                <div class="divide-y rounded-lg border">
                    <label
                        class="flex cursor-pointer items-start justify-between gap-4 px-3.5 py-3"
                    >
                        <span>
                            <span class="block text-[13px] font-medium"
                                >Waiting room for guests</span
                            >
                            <span
                                class="block text-[12px] text-muted-foreground"
                                >People without an account in this organization
                                wait until a host lets them in.</span
                            >
                        </span>
                        <Switch v-model="form.waiting_room" class="mt-0.5" />
                    </label>
                    <label
                        class="flex cursor-pointer items-start justify-between gap-4 px-3.5 py-3"
                    >
                        <span>
                            <span class="block text-[13px] font-medium"
                                >Guests may share files</span
                            >
                            <span
                                class="block text-[12px] text-muted-foreground"
                                >Guests can always download what was shared with
                                the meeting.</span
                            >
                        </span>
                        <Switch v-model="form.guest_uploads" class="mt-0.5" />
                    </label>
                    <label
                        class="flex cursor-pointer items-start justify-between gap-4 px-3.5 py-3"
                    >
                        <span>
                            <span class="block text-[13px] font-medium"
                                >Email the chat after every meeting</span
                            >
                            <span
                                class="block text-[12px] text-muted-foreground"
                                >Only goes to people who were in the meeting,
                                and only when something was written.</span
                            >
                        </span>
                        <Switch
                            v-model="form.auto_email_transcript"
                            class="mt-0.5"
                        />
                    </label>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="call-screen-share" class="text-xs"
                            >Who can share their screen</Label
                        >
                        <Select v-model="form.screen_share">
                            <SelectTrigger
                                id="call-screen-share"
                                class="h-9 w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="everyone"
                                    >Everyone</SelectItem
                                >
                                <SelectItem value="hosts"
                                    >Hosts only</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="call-recording" class="text-xs"
                            >Who can record</Label
                        >
                        <Select v-model="form.recording">
                            <SelectTrigger
                                id="call-recording"
                                class="h-9 w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="hosts"
                                    >Hosts only</SelectItem
                                >
                                <SelectItem value="members"
                                    >Any member</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <p class="text-[11.5px] text-muted-foreground">
                            Guests can never record.
                        </p>
                    </div>
                </div>

                <div class="rounded-lg border px-3.5 py-3">
                    <label
                        class="flex cursor-pointer items-start justify-between gap-4"
                    >
                        <span>
                            <span class="block text-[13px] font-medium"
                                >Keep recordings until someone deletes
                                them</span
                            >
                            <span
                                class="block text-[12px] text-muted-foreground"
                                >Switch off to delete recordings automatically
                                after a number of days.</span
                            >
                        </span>
                        <Switch v-model="form.keep_forever" class="mt-0.5" />
                    </label>
                    <div
                        v-if="!form.keep_forever"
                        class="mt-3 flex items-center gap-2 text-[13px]"
                    >
                        <Label for="call-retention" class="font-normal"
                            >Delete recordings after</Label
                        >
                        <Input
                            id="call-retention"
                            v-model.number="form.recording_retention_days"
                            type="number"
                            min="1"
                            max="3650"
                            class="h-8 w-20 tabular-nums"
                            :aria-invalid="
                                Boolean(errors.recording_retention_days)
                            "
                        />
                        <span>days</span>
                    </div>
                    <p
                        v-if="errors.recording_retention_days"
                        role="alert"
                        class="mt-1.5 text-[12px] text-destructive"
                    >
                        {{ errors.recording_retention_days }}
                    </p>
                    <p
                        v-if="!form.keep_forever"
                        class="mt-2 text-[11.5px] text-muted-foreground"
                    >
                        Applies to recordings made from now on. Recordings that
                        already exist keep the date they were given.
                    </p>
                </div>
            </form>

            <DialogFooter>
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="saving"
                    @click="open = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="call-settings"
                    size="sm"
                    :disabled="saving"
                    >Save settings</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
