<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { BellOff, BellRing, Smartphone } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { usePushNotifications } from '@/composables/usePushNotifications';
import { useInstallPrompt } from '@/composables/usePwa';
import { reportError } from '@/lib/http';
import { update } from '@/routes/notification-settings';

const props = defineProps<{
    publicKey: string | null;
    topics: Record<'direct' | 'follows' | 'shared', boolean>;
    deviceCount: number;
}>();

const { supported, subscribed, blocked, enable, disable } =
    usePushNotifications();
const { isInstalled } = useInstallPrompt();
const busy = ref(false);

const toggleDevice = async (on: boolean) => {
    if (!props.publicKey) {
        return;
    }

    busy.value = true;

    try {
        if (on) {
            const granted = await enable(props.publicKey);

            if (!granted) {
                toast.error('Notifications stay off', {
                    description:
                        'The browser did not allow them. You can change that in its site settings.',
                });
            }
        } else {
            await disable();
        }
    } catch (error) {
        reportError(error, 'Could not change notifications for this device.');
    } finally {
        busy.value = false;
    }
};

const form = useForm({ topics: { ...props.topics } });

const topicRows = [
    {
        key: 'direct',
        title: 'Aimed at you',
        description: 'Mentions, assignments and automations that pick you out.',
    },
    {
        key: 'follows',
        title: 'Conversations you follow',
        description: 'Replies and comments on threads you own or follow.',
    },
    {
        key: 'shared',
        title: 'New mail in shared inboxes',
        description:
            'Every new conversation in a shared inbox you belong to. Noisy on a busy inbox.',
    },
] as const;

const save = () => form.patch(update().url, { preserveScroll: true });
</script>

<template>
    <Head title="Notification settings" />

    <h1 class="sr-only">Notification settings</h1>

    <div class="space-y-8">
        <Heading
            variant="small"
            title="Notifications"
            description="Pushes reach your devices even when no tab is open. The bell in the app keeps everything regardless."
        />

        <section
            class="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 sm:flex-row sm:items-start"
        >
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand-soft-foreground"
            >
                <component
                    :is="subscribed ? BellRing : BellOff"
                    class="size-5"
                />
            </span>

            <div class="min-w-0 flex-1 space-y-1">
                <h2 class="text-sm font-semibold">This device</h2>
                <p v-if="!publicKey" class="text-sm text-muted-foreground">
                    Push is not configured on this server yet. Ask whoever runs
                    it to set the VAPID keys.
                </p>
                <p v-else-if="!supported" class="text-sm text-muted-foreground">
                    This browser cannot receive pushes.
                    <template v-if="!isInstalled">
                        On iPhone and iPad, install the app to your home screen
                        first.
                    </template>
                </p>
                <p v-else-if="blocked" class="text-sm text-muted-foreground">
                    Notifications are blocked for this site in the browser.
                    Allow them in the site settings, then come back here.
                </p>
                <p v-else class="text-sm text-muted-foreground">
                    {{
                        subscribed
                            ? 'Receiving pushes here.'
                            : 'Turn on to get pushes on this device.'
                    }}
                </p>
                <p
                    class="flex items-center gap-1.5 pt-1 text-xs text-muted-foreground"
                >
                    <Smartphone class="size-3.5" />
                    {{ deviceCount }}
                    {{ deviceCount === 1 ? 'device' : 'devices' }} subscribed to
                    your account
                </p>
            </div>

            <Switch
                v-if="publicKey && supported && !blocked"
                :model-value="subscribed"
                :disabled="busy"
                aria-label="Push notifications on this device"
                @update:model-value="toggleDevice"
            />
        </section>

        <form class="space-y-4" @submit.prevent="save">
            <div>
                <h2 class="text-sm font-semibold">What gets pushed</h2>
                <p class="text-sm text-muted-foreground">
                    Applies to every device you have turned on.
                </p>
            </div>

            <div class="divide-y divide-border rounded-xl border border-border">
                <div
                    v-for="row in topicRows"
                    :key="row.key"
                    class="flex items-center justify-between gap-4 px-4 py-3"
                >
                    <div class="min-w-0">
                        <Label :for="`topic-${row.key}`" class="font-medium">{{
                            row.title
                        }}</Label>
                        <p class="text-sm text-muted-foreground">
                            {{ row.description }}
                        </p>
                    </div>
                    <Switch
                        :id="`topic-${row.key}`"
                        v-model="form.topics[row.key]"
                    />
                </div>
            </div>

            <div class="flex items-center gap-3">
                <Button
                    type="submit"
                    :disabled="form.processing || !form.isDirty"
                    >Save</Button
                >
                <span
                    v-if="form.recentlySuccessful"
                    class="text-sm text-muted-foreground"
                    >Saved.</span
                >
            </div>
        </form>
    </div>
</template>
