<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
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
import { Textarea } from '@/components/ui/textarea';
import { api, HttpError } from '@/lib/http';
import type { Milestone, Project } from '@/types';

const open = defineModel<boolean>('open', { default: false });
const props = defineProps<{ project: Project; milestone?: Milestone | null }>();
const emit = defineEmits<{ saved: [milestone: Milestone] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const name = ref('');
const description = ref('');
const targetDate = ref<string | null>(null);
const status = ref<Milestone['status']>('planned');
const busy = ref(false);
const errors = ref<Record<string, string[]>>({});

watch(open, (value) => {
    if (!value) {
        return;
    }

    name.value = props.milestone?.name ?? '';
    description.value = props.milestone?.description ?? '';
    targetDate.value = props.milestone?.target_date ?? null;
    status.value = props.milestone?.status ?? 'planned';
    errors.value = {};
});

const submit = async () => {
    busy.value = true;
    errors.value = {};
    const payload = {
        name: name.value.trim(),
        description: description.value || null,
        target_date: targetDate.value,
        status: status.value,
    };

    try {
        const data = props.milestone
            ? await api.patch<{ milestone: Milestone }>(
                  `/${slug.value}/projects/${props.project.slug}/milestones/${props.milestone.uuid}`,
                  payload,
              )
            : await api.post<{ milestone: Milestone }>(
                  `/${slug.value}/projects/${props.project.slug}/milestones`,
                  payload,
              );

        toast.success(
            props.milestone
                ? 'Milestone saved'
                : `${data.milestone.name} added`,
        );
        emit('saved', data.milestone);
        open.value = false;
    } catch (error) {
        if (error instanceof HttpError) {
            errors.value = error.errors;
        } else {
            toast.error('Could not save the milestone.');
        }
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-md">
            <form class="contents" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle class="font-display text-2xl">{{
                        milestone ? 'Edit milestone' : 'New milestone'
                    }}</DialogTitle>
                    <DialogDescription
                        >A checkpoint inside {{ project.name }}. Issues attached
                        to it drive its progress.</DialogDescription
                    >
                </DialogHeader>
                <div class="space-y-4">
                    <label class="block space-y-1.5 text-sm">
                        <span class="text-muted-foreground">Name</span>
                        <Input
                            v-model="name"
                            v-focus
                            placeholder="Design sign-off"
                            required
                        />
                        <p v-if="errors.name" class="text-xs text-destructive">
                            {{ errors.name[0] }}
                        </p>
                    </label>
                    <label class="block space-y-1.5 text-sm">
                        <span class="text-muted-foreground">Description</span>
                        <Textarea v-model="description" rows="2" />
                    </label>
                    <div class="flex flex-wrap items-center gap-3">
                        <DatePicker
                            :value="targetDate"
                            label="Target date"
                            @change="(v) => (targetDate = v)"
                        />
                        <label class="flex items-center gap-2 text-sm">
                            <span class="text-muted-foreground">Status</span>
                            <NativeSelect v-model="status">
                                <NativeSelectOption value="planned"
                                    >Planned</NativeSelectOption
                                >
                                <NativeSelectOption value="active"
                                    >Active</NativeSelectOption
                                >
                                <NativeSelectOption value="completed"
                                    >Completed</NativeSelectOption
                                >
                                <NativeSelectOption value="missed"
                                    >Missed</NativeSelectOption
                                >
                            </NativeSelect>
                        </label>
                    </div>
                </div>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="ghost" @click="open = false"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="busy || !name.trim()">{{
                        milestone ? 'Save' : 'Add milestone'
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
