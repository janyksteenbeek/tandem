<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import SsoProviderIcon from '@/components/SsoProviderIcon.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useSso } from '@/composables/useSso';
import { redirect } from '@/routes/sso';

/**
 * One button per enabled identity provider. Rendered on the login, register
 * and confirm-password screens; the separator only shows when something else
 * (a password form) follows.
 */
const props = withDefaults(
    defineProps<{
        intent?: 'login' | 'confirm';
        label?: string;
        separator?: string | false;
        only?: string[];
    }>(),
    {
        intent: 'login',
        label: 'Continue with',
        separator: 'Or continue with email',
    },
);

const sso = useSso();
const page = usePage();

const providers = computed(() =>
    sso.providers.filter(
        (provider) => !props.only || props.only.includes(provider.key),
    ),
);

const error = computed(
    () => (page.props.errors as Record<string, string> | undefined)?.sso,
);

const hrefFor = (provider: string): string =>
    redirect.url(provider, {
        query: props.intent === 'confirm' ? { intent: 'confirm' } : undefined,
    });
</script>

<template>
    <div v-if="providers.length" data-test="sso-buttons">
        <div class="grid gap-2">
            <Button
                v-for="provider in providers"
                :key="provider.key"
                as="a"
                :href="hrefFor(provider.key)"
                variant="outline"
                class="w-full"
                :data-test="`sso-${provider.key}`"
            >
                <SsoProviderIcon :provider="provider.key" />
                {{ label }} {{ provider.name }}
            </Button>

            <div v-if="error" class="text-center">
                <InputError :message="error" />
            </div>
        </div>

        <div v-if="separator" class="relative my-6">
            <div class="absolute inset-0 flex items-center">
                <Separator class="w-full" />
            </div>
            <div class="relative flex justify-center text-xs uppercase">
                <span class="bg-background px-2 text-muted-foreground">
                    {{ separator }}
                </span>
            </div>
        </div>
    </div>
</template>
