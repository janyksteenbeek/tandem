import { computed, readonly, ref } from 'vue';
import { api } from '@/lib/http';

/**
 * Push notifications for this device: the browser permission, whether this
 * device is subscribed, and the two actions that change that. The server is
 * told on every change and re-told on each load, since browsers rotate push
 * endpoints without warning.
 */

const permission = ref<NotificationPermission>(
    typeof Notification === 'undefined' ? 'denied' : Notification.permission,
);
const subscribed = ref(false);

const supported =
    typeof window !== 'undefined' &&
    'serviceWorker' in navigator &&
    'PushManager' in window &&
    typeof Notification !== 'undefined';

function toServerKey(base64Url: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64Url.length % 4)) % 4);
    const raw = atob(
        (base64Url + padding).replace(/-/g, '+').replace(/_/g, '/'),
    );

    return Uint8Array.from(raw, (char) => char.charCodeAt(0));
}

async function currentSubscription(): Promise<PushSubscription | null> {
    if (!supported) {
        return null;
    }

    const registration = await navigator.serviceWorker.ready;

    return registration.pushManager.getSubscription();
}

async function saveSubscription(subscription: PushSubscription): Promise<void> {
    const json = subscription.toJSON();

    await api.post('/push/subscriptions', {
        endpoint: json.endpoint,
        keys: json.keys,
        content_encoding: 'aes128gcm',
    });
}

/**
 * Re-register whatever subscription the browser still holds. Cheap, and it
 * is what brings a device back after a sign-out or an endpoint rotation.
 */
export async function syncPushSubscription(): Promise<void> {
    if (!supported || Notification.permission !== 'granted') {
        return;
    }

    try {
        const subscription = await currentSubscription();

        if (subscription) {
            await saveSubscription(subscription);
            subscribed.value = true;
        }
    } catch {
        // Offline or signed out: nothing to do until the next load.
    }
}

/**
 * Forget this device on the server. The browser keeps its subscription so a
 * later sign-in can pick it straight back up through sync.
 */
export async function forgetPushSubscription(): Promise<void> {
    try {
        const subscription = await currentSubscription();

        if (subscription) {
            await api.delete('/push/subscriptions', {
                endpoint: subscription.endpoint,
            });
        }
    } catch {
        // Best effort on the way out.
    }
}

export function usePushNotifications() {
    const refresh = async () => {
        if (!supported) {
            return;
        }

        permission.value = Notification.permission;
        subscribed.value = (await currentSubscription()) !== null;
    };

    const enable = async (publicKey: string): Promise<boolean> => {
        if (!supported) {
            return false;
        }

        permission.value = await Notification.requestPermission();

        if (permission.value !== 'granted') {
            return false;
        }

        const registration = await navigator.serviceWorker.ready;
        const subscription =
            (await registration.pushManager.getSubscription()) ??
            (await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: toServerKey(publicKey),
            }));

        await saveSubscription(subscription);
        subscribed.value = true;

        return true;
    };

    const disable = async (): Promise<void> => {
        const subscription = await currentSubscription();

        if (subscription) {
            await api.delete('/push/subscriptions', {
                endpoint: subscription.endpoint,
            });
            await subscription.unsubscribe();
        }

        subscribed.value = false;
    };

    void refresh();

    return {
        supported,
        permission: readonly(permission),
        subscribed: readonly(subscribed),
        blocked: computed(() => permission.value === 'denied'),
        enable,
        disable,
        refresh,
    };
}
