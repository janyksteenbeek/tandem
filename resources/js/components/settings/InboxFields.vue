<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import ColorPicker from '@/components/settings/ColorPicker.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

defineProps<{
    errors: Partial<
        Record<'name' | 'email_address' | 'color' | 'description', string>
    >;
    idPrefix?: string;
}>();

const name = defineModel<string>('name', { default: '' });
const emailAddress = defineModel<string>('emailAddress', { default: '' });
const color = defineModel<string>('color', { default: 'violet' });
const description = defineModel<string>('description', { default: '' });
</script>

<template>
    <div class="grid gap-4">
        <div class="grid gap-1.5">
            <Label :for="`${idPrefix ?? 'inbox'}-name`">Name</Label>
            <Input
                :id="`${idPrefix ?? 'inbox'}-name`"
                v-model="name"
                placeholder="Support"
                maxlength="80"
                required
            />
            <InputError :message="errors.name" />
        </div>
        <div class="grid gap-1.5">
            <Label :for="`${idPrefix ?? 'inbox'}-email`"
                >Public address
                <span class="font-normal text-muted-foreground"
                    >(optional)</span
                ></Label
            >
            <Input
                :id="`${idPrefix ?? 'inbox'}-email`"
                v-model="emailAddress"
                type="email"
                placeholder="support@company.com"
            />
            <InputError :message="errors.email_address" />
        </div>
        <div class="grid gap-1.5">
            <Label>Colour</Label>
            <ColorPicker v-model="color" />
            <InputError :message="errors.color" />
        </div>
        <div class="grid gap-1.5">
            <Label :for="`${idPrefix ?? 'inbox'}-description`"
                >Description
                <span class="font-normal text-muted-foreground"
                    >(optional)</span
                ></Label
            >
            <Textarea
                :id="`${idPrefix ?? 'inbox'}-description`"
                v-model="description"
                rows="2"
                maxlength="255"
                placeholder="What lands here, and who should pick it up."
            />
            <InputError :message="errors.description" />
        </div>
    </div>
</template>
