<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Link2, Unlink } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SsoProviderIcon from '@/components/SsoProviderIcon.vue';
import { Button } from '@/components/ui/button';
import { useSso } from '@/composables/useSso';
import { destroy } from '@/routes/sso';
import { redirect } from '@/routes/sso';
import type { SsoIdentity } from '@/types/auth';

export type Props = {
    ssoIdentities?: SsoIdentity[];
};

const props = withDefaults(defineProps<Props>(), {
    ssoIdentities: () => [],
});

const sso = useSso();
const page = usePage();
const disconnecting = ref<string | null>(null);

const rows = computed(() =>
    sso.providers.map((provider) => ({
        ...provider,
        identity:
            props.ssoIdentities.find(
                (identity) => identity.provider === provider.key,
            ) ?? null,
    })),
);

const error = computed(
    () => (page.props.errors as Record<string, string> | undefined)?.sso,
);

const canDisconnect = computed(
    () => !sso.forced || props.ssoIdentities.length > 1,
);

const disconnect = (provider: string) => {
    disconnecting.value = provider;
    router.delete(destroy.url(provider), {
        preserveScroll: true,
        onFinish: () => {
            disconnecting.value = null;
        },
    });
};
</script>

<template>
    <div v-if="sso.providers.length" class="space-y-6">
        <Heading
            variant="small"
            title="Single sign-on"
            :description="
                sso.forced
                    ? 'Signing in happens through one of these providers'
                    : 'Sign in with an account you already have'
            "
        />

        <div class="overflow-hidden rounded-lg border border-border">
            <div
                v-for="row in rows"
                :key="row.key"
                class="flex items-center justify-between gap-4 border-b p-4 last:border-b-0"
                :data-test="`sso-identity-${row.key}`"
            >
                <div class="flex items-center gap-4">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted"
                    >
                        <SsoProviderIcon :provider="row.key" />
                    </div>
                    <div class="space-y-1">
                        <p class="font-medium tracking-tight">{{ row.name }}</p>
                        <p
                            v-if="row.identity"
                            class="text-sm text-muted-foreground"
                        >
                            <span v-if="row.identity.email">{{
                                row.identity.email
                            }}</span>
                            <template v-if="row.identity.connected_at_diff">
                                <span
                                    v-if="row.identity.email"
                                    class="mx-1 text-muted-foreground/50"
                                    >/</span
                                >
                                Connected {{ row.identity.connected_at_diff }}
                            </template>
                        </p>
                        <p v-else class="text-sm text-muted-foreground">
                            Not connected
                        </p>
                    </div>
                </div>

                <Button
                    v-if="row.identity"
                    variant="ghost"
                    size="sm"
                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                    :disabled="!canDisconnect || disconnecting === row.key"
                    :title="
                        canDisconnect
                            ? undefined
                            : 'Single sign-on is required, so the last connected account stays'
                    "
                    @click="disconnect(row.key)"
                >
                    <Unlink class="h-4 w-4" />
                    Disconnect
                </Button>
                <Button
                    v-else
                    as="a"
                    :href="redirect.url(row.key)"
                    variant="outline"
                    size="sm"
                >
                    <Link2 class="h-4 w-4" />
                    Connect
                </Button>
            </div>
        </div>

        <InputError :message="error" />
    </div>
</template>
