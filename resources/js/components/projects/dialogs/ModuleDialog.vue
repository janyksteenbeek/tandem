<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { MemberPicker, TeamPicker } from '@/components/projects/pickers';
import ColorPicker from '@/components/settings/ColorPicker.vue';
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
import type {
    IssueLookups,
    ModuleSummary,
    Product,
    TeamSummary,
    UserSummary,
} from '@/types';

/** Create or edit a module of a product. `parentId` preselects nesting. */
const open = defineModel<boolean>('open', { default: false });
const props = defineProps<{
    product: Product;
    module?: ModuleSummary | null;
    parentId?: number | null;
    lookups: IssueLookups | null;
}>();
const emit = defineEmits<{ saved: [module: ModuleSummary] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const name = ref('');
const description = ref('');
const color = ref<string>('slate');
const parent = ref<number | null>(null);
const owner = ref<UserSummary[]>([]);
const teams = ref<TeamSummary[]>([]);
const busy = ref(false);
const errors = ref<Record<string, string[]>>({});

watch(open, (value) => {
    if (!value) {
        return;
    }

    name.value = props.module?.name ?? '';
    description.value = props.module?.description ?? '';
    color.value = props.module?.color ?? 'slate';
    parent.value = props.module?.parent_id ?? props.parentId ?? null;
    owner.value = props.module?.owner ? [props.module.owner] : [];
    teams.value = props.module?.teams ?? [];
    errors.value = {};
});

const parentOptions = computed(() =>
    props.product.modules.filter((m) => m.id !== props.module?.id),
);

const submit = async () => {
    busy.value = true;
    errors.value = {};

    const payload = {
        name: name.value.trim(),
        description: description.value || null,
        color: color.value,
        parent_id: parent.value,
        owner_id: owner.value[0]?.id ?? null,
        team_ids: teams.value.map((t) => t.id),
    };

    try {
        const data = props.module
            ? await api.patch<{ module: ModuleSummary }>(
                  `/${slug.value}/products/${props.product.slug}/modules/${props.module.slug}`,
                  payload,
              )
            : await api.post<{ module: ModuleSummary }>(
                  `/${slug.value}/products/${props.product.slug}/modules`,
                  payload,
              );

        toast.success(
            props.module ? 'Module saved' : `${data.module.name} added`,
        );
        emit('saved', data.module);
        open.value = false;
    } catch (error) {
        if (error instanceof HttpError) {
            errors.value = error.errors;
        } else {
            toast.error('Could not save the module.');
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
                        module ? 'Edit module' : 'New module'
                    }}</DialogTitle>
                    <DialogDescription
                        >A functional area of {{ product.name }}: Reporting,
                        CRM, Integrations. Permanent, and
                        nestable.</DialogDescription
                    >
                </DialogHeader>
                <div class="space-y-4">
                    <label class="block space-y-1.5 text-sm">
                        <span class="text-muted-foreground">Name</span>
                        <Input
                            v-model="name"
                            v-focus
                            placeholder="Reporting"
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
                    <label class="block space-y-1.5 text-sm">
                        <span class="text-muted-foreground">Nested under</span>
                        <NativeSelect v-model="parent" class="w-full">
                            <NativeSelectOption :value="null"
                                >Top level</NativeSelectOption
                            >
                            <NativeSelectOption
                                v-for="option in parentOptions"
                                :key="option.id"
                                :value="option.id"
                                >{{ option.name }}</NativeSelectOption
                            >
                        </NativeSelect>
                        <p
                            v-if="errors.parent_id"
                            class="text-xs text-destructive"
                        >
                            {{ errors.parent_id[0] }}
                        </p>
                    </label>
                    <div class="space-y-1.5 text-sm">
                        <span class="text-muted-foreground">Colour</span>
                        <ColorPicker v-model="color" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <MemberPicker
                            :selected="owner"
                            :members="lookups?.members ?? []"
                            placeholder="Owner…"
                            empty-label="Owner"
                            @change="(users) => (owner = users)"
                        />
                        <TeamPicker
                            :selected="teams"
                            :options="lookups?.teams ?? []"
                            multiple
                            placeholder="Teams…"
                            empty-label="Teams"
                            @change="(list) => (teams = list)"
                        />
                    </div>
                </div>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="ghost" @click="open = false"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="busy || !name.trim()">{{
                        module ? 'Save' : 'Add module'
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
