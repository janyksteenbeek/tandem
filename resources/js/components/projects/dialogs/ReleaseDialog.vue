<script lang="ts">
import type { Release } from '@/types';

export type ReleaseDetail = Release & {
    notes_html?: string | null;
    progress?: number;
};
</script>

<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import RichTextEditor from '@/components/projects/editor/RichTextEditor.vue';
import { DatePicker } from '@/components/projects/pickers';
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
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { api, HttpError } from '@/lib/http';

const open = defineModel<boolean>('open', { default: false });
const props = defineProps<{
    productSlug: string;
    productName: string;
    release?: ReleaseDetail | null;
}>();
const emit = defineEmits<{ saved: [release: ReleaseDetail] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const name = ref('');
const version = ref('');
const status = ref<Release['status']>('planned');
const plannedAt = ref<string | null>(null);
const notes = ref('');
const busy = ref(false);
const errors = ref<Record<string, string[]>>({});

watch(open, (value) => {
    if (!value) {
        return;
    }

    errors.value = {};
    name.value = props.release?.name ?? '';
    version.value = props.release?.version ?? '';
    status.value = props.release?.status ?? 'planned';
    plannedAt.value = props.release?.planned_at ?? null;
    notes.value = props.release?.notes_html ?? '';
});

const submit = async () => {
    busy.value = true;
    errors.value = {};
    const payload = {
        name: name.value.trim(),
        version: version.value.trim() || null,
        status: status.value,
        planned_at: plannedAt.value,
        notes_html: notes.value || null,
    };
    const base = `/${slug.value}/products/${props.productSlug}/releases`;

    try {
        const data = props.release
            ? await api.patch<{ release: ReleaseDetail }>(
                  `${base}/${props.release.uuid}`,
                  payload,
              )
            : await api.post<{ release: ReleaseDetail }>(base, payload);

        toast.success(
            props.release ? 'Release saved' : `${data.release.name} created`,
        );
        emit('saved', data.release);
        open.value = false;
    } catch (error) {
        if (error instanceof HttpError) {
            errors.value = error.errors;
        } else {
            toast.error('Could not save the release.');
        }
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-lg">
            <form class="contents" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle class="font-display text-2xl">{{
                        release ? 'Edit release' : 'New release'
                    }}</DialogTitle>
                    <DialogDescription
                        >A version of {{ productName }} you plan to ship. Issues
                        from any project can join it.</DialogDescription
                    >
                </DialogHeader>
                <div class="space-y-4">
                    <div class="grid grid-cols-[1fr_8rem] gap-3">
                        <label class="block space-y-1.5 text-sm">
                            <span class="text-muted-foreground">Name</span>
                            <Input
                                v-model="name"
                                v-focus
                                placeholder="Frontier 2.0"
                                required
                            />
                            <p
                                v-if="errors.name"
                                class="text-xs text-destructive"
                            >
                                {{ errors.name[0] }}
                            </p>
                        </label>
                        <label class="block space-y-1.5 text-sm">
                            <span class="text-muted-foreground">Version</span>
                            <Input
                                v-model="version"
                                placeholder="2.0.0"
                                class="font-mono"
                            />
                        </label>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <label class="flex items-center gap-2">
                            <span class="text-muted-foreground">Status</span>
                            <NativeSelect v-model="status">
                                <NativeSelectOption value="planned"
                                    >Planned</NativeSelectOption
                                >
                                <NativeSelectOption value="in_progress"
                                    >In progress</NativeSelectOption
                                >
                                <NativeSelectOption value="released"
                                    >Released</NativeSelectOption
                                >
                                <NativeSelectOption value="canceled"
                                    >Canceled</NativeSelectOption
                                >
                            </NativeSelect>
                        </label>
                        <DatePicker
                            :value="plannedAt"
                            label="Planned date"
                            @change="(v) => (plannedAt = v)"
                        />
                    </div>
                    <div class="space-y-1.5 text-sm">
                        <span class="text-muted-foreground">Release notes</span>
                        <div class="rounded-lg border px-3 py-1">
                            <RichTextEditor
                                v-model="notes"
                                placeholder="What ships in this release?"
                                compact
                                min-height="4rem"
                            />
                        </div>
                    </div>
                </div>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="ghost" @click="open = false"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="busy || !name.trim()">{{
                        release ? 'Save' : 'Create release'
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
