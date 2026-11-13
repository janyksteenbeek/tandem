import type { InjectionKey, Ref } from 'vue';
import { inject, provide, ref, watch } from 'vue';
import {
    DEFAULT_PREFERENCES,
    loadPreferences,
    savePreferences,
} from '@/lib/meet/preferences';
import type { DevicePreferences } from '@/lib/meet/types';

const key: InjectionKey<Ref<DevicePreferences>> = Symbol('meet-preferences');

/**
 * Devices, on/off state and name as this browser last used them. Created once by the page
 * and shared with everything below it; every change is written back to localStorage.
 */
export function provideMeetPreferences(): Ref<DevicePreferences> {
    // Defaults on the server and for the first client render; the remembered values are
    // read by `hydrate()` after mount so both renders agree.
    const preferences = ref<DevicePreferences>({ ...DEFAULT_PREFERENCES });

    provide(key, preferences);

    return preferences;
}

export function hydrateMeetPreferences(
    preferences: Ref<DevicePreferences>,
): void {
    preferences.value = loadPreferences();

    watch(preferences, (value) => savePreferences(value), { deep: true });
}

export function useMeetPreferences(): Ref<DevicePreferences> {
    const preferences = inject(key, null);

    if (!preferences) {
        throw new Error('Meet preferences were not provided.');
    }

    return preferences;
}
