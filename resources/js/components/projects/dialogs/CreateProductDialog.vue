<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
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
import { Textarea } from '@/components/ui/textarea';
import { api, HttpError } from '@/lib/http';
import type { IssueLookups, Product, TeamSummary, UserSummary } from '@/types';

const open = defineModel<boolean>('open', { default: false });
const props = defineProps<{ lookups: IssueLookups | null }>();
const emit = defineEmits<{ created: [product: Product] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const name = ref('');
const key = ref('');
const keyTouched = ref(false);
const description = ref('');
const color = ref('violet');
const owner = ref<UserSummary[]>([]);
const teams = ref<TeamSummary[]>([]);
const busy = ref(false);
const errors = ref<Record<string, string[]>>({});

const suggestKey = (value: string) => {
    const words = value
        .replace(/[^A-Za-z0-9 ]/g, ' ')
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    const raw =
        words.length >= 2
            ? words.map((w) => w[0]).join('')
            : (words[0] ?? '').slice(0, 5);

    return raw
        .toUpperCase()
        .replace(/^[^A-Z]+/, '')
        .slice(0, 10);
};

watch(name, (value) => {
    if (!keyTouched.value) {
        key.value = suggestKey(value);
    }
});

watch(open, (value) => {
    if (value) {
        name.value = '';
        key.value = '';
        keyTouched.value = false;
        description.value = '';
        color.value = 'violet';
        owner.value = (props.lookups?.members ?? []).filter(
            (m) => m.id === page.props.auth.user.id,
        );
        teams.value = [];
        errors.value = {};
    }
});

const submit = async () => {
    busy.value = true;
    errors.value = {};

    try {
        const data = await api.post<{ product: Product }>(
            `/${slug.value}/products`,
            {
                name: name.value.trim(),
                key: key.value.trim().toUpperCase(),
                description: description.value || null,
                color: color.value,
                owner_id: owner.value[0]?.id ?? null,
                team_ids: teams.value.map((t) => t.id),
            },
        );

        toast.success(`${data.product.name} created`);
        emit('created', data.product);
        open.value = false;
        router.visit(`/${slug.value}/products/${data.product.slug}`);
    } catch (error) {
        if (error instanceof HttpError) {
            errors.value = error.errors;
        } else {
            toast.error('Could not create the product.');
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
                    <DialogTitle class="font-display text-2xl"
                        >New product</DialogTitle
                    >
                    <DialogDescription
                        >A product is something you build and maintain for a
                        long time. Its key prefixes every issue, like
                        FRONT-124.</DialogDescription
                    >
                </DialogHeader>
                <div class="space-y-4">
                    <div class="grid grid-cols-[1fr_7rem] gap-3">
                        <label class="space-y-1.5 text-sm">
                            <span class="text-muted-foreground">Name</span>
                            <Input
                                v-model="name"
                                v-focus
                                placeholder="Portivio Frontier"
                                required
                            />
                            <p
                                v-if="errors.name"
                                class="text-xs text-destructive"
                            >
                                {{ errors.name[0] }}
                            </p>
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="text-muted-foreground">Key</span>
                            <Input
                                :model-value="key"
                                placeholder="FRONT"
                                class="font-mono uppercase"
                                maxlength="10"
                                required
                                @update:model-value="
                                    (v) => {
                                        key = String(v).toUpperCase();
                                        keyTouched = true;
                                    }
                                "
                            />
                            <p
                                v-if="errors.key"
                                class="text-xs text-destructive"
                            >
                                {{ errors.key[0] }}
                            </p>
                        </label>
                    </div>
                    <label class="block space-y-1.5 text-sm">
                        <span class="text-muted-foreground">Description</span>
                        <Textarea
                            v-model="description"
                            rows="2"
                            placeholder="What is it, who is it for?"
                        />
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
                            empty-label="Participating teams"
                            @change="(list) => (teams = list)"
                        />
                    </div>
                </div>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="ghost" @click="open = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        :disabled="busy || !name.trim() || !key.trim()"
                        >Create product</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
