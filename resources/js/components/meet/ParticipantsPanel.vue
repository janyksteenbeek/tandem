<script setup lang="ts">
import {
    Check,
    Link2,
    Mail,
    Mic,
    MicOff,
    MonitorUp,
    MoreHorizontal,
    UserMinus,
    Video,
    VideoOff,
    Volume1,
    Volume2,
    VolumeX,
    X,
} from '@lucide/vue';
import { computed } from 'vue';
import QualityBars from '@/components/meet/QualityBars.vue';
import SidePanel from '@/components/meet/SidePanel.vue';
import VolumeControl from '@/components/meet/VolumeControl.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useParticipantVolumes } from '@/composables/meet/useParticipantVolumes';
import type { RoomParticipant } from '@/lib/meet/types';
import type { CallMe, CallParticipant } from '@/types';

/**
 * Who is here, who is waiting to be let in, how loud each of them is for this listener,
 * and (for hosts) the controls over them.
 */
const props = defineProps<{
    participants: RoomParticipant[];
    waiting: CallParticipant[];
    me: CallMe | null;
}>();

const emit = defineEmits<{
    close: [];
    admit: [uuid: string];
    deny: [uuid: string];
    mute: [participant: RoomParticipant];
    remove: [participant: RoomParticipant];
    copyLink: [];
    invite: [];
}>();

const canManage = computed(() => props.me?.can.manage ?? false);

const roleLabel = (participant: RoomParticipant) =>
    participant.meta.role === 'host'
        ? 'Host'
        : participant.meta.guest
          ? 'Guest'
          : null;

const { volumeOf } = useParticipantVolumes();

const volumeIcon = (participant: RoomParticipant) => {
    const volume = volumeOf(participant.identity);

    return volume <= 0 ? VolumeX : volume < 0.5 ? Volume1 : Volume2;
};
</script>

<template>
    <SidePanel
        title="People"
        :subtitle="`${participants.length} in the meeting`"
        @close="emit('close')"
    >
        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
            <!-- Waiting room -->
            <section
                v-if="waiting.length > 0"
                class="border-b bg-brand-soft/40 px-3 py-3"
                aria-label="Waiting to join"
            >
                <h3
                    class="mb-2 flex items-center justify-between px-1 text-xs font-semibold tracking-wide text-brand-soft-foreground uppercase"
                >
                    Waiting to join
                    <span
                        class="rounded-full bg-brand px-1.5 text-[10px] text-brand-foreground"
                        >{{ waiting.length }}</span
                    >
                </h3>
                <ul class="flex flex-col gap-1.5">
                    <li
                        v-for="person in waiting"
                        :key="person.uuid"
                        class="flex animate-rise items-center gap-2.5 rounded-xl bg-card px-2.5 py-2 shadow-pane"
                        data-testid="waiting-participant"
                    >
                        <UserAvatar
                            :name="person.name"
                            :initials="person.initials"
                            size="md"
                        />
                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-[13px] font-medium"
                                >{{ person.name }}</span
                            >
                            <span
                                class="block text-[11px] text-muted-foreground"
                                >Guest</span
                            >
                        </span>
                        <Button
                            size="icon-sm"
                            variant="ghost"
                            :aria-label="`Decline ${person.name}`"
                            class="text-muted-foreground hover:text-destructive"
                            @click="emit('deny', person.uuid)"
                        >
                            <X />
                        </Button>
                        <Button
                            size="sm"
                            class="rounded-lg bg-brand text-brand-foreground hover:bg-brand/90"
                            :aria-label="`Admit ${person.name}`"
                            data-testid="admit"
                            @click="emit('admit', person.uuid)"
                        >
                            <Check /> Admit
                        </Button>
                    </li>
                </ul>
            </section>

            <!-- In the meeting -->
            <ul class="flex flex-col p-2" aria-label="In the meeting">
                <li
                    v-for="participant in participants"
                    :key="participant.identity"
                    class="group/person flex items-center gap-2.5 rounded-xl px-2 py-2 hover:bg-foreground/[0.035]"
                    data-testid="participant-row"
                >
                    <span class="relative">
                        <UserAvatar
                            :name="participant.name"
                            :src="participant.meta.avatar"
                            :initials="participant.meta.initials"
                            size="md"
                        />
                        <span
                            v-if="
                                participant.isSpeaking && participant.micEnabled
                            "
                            class="absolute -inset-0.5 rounded-full ring-2 ring-brand"
                        />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5">
                            <span class="truncate text-[13px] font-medium">{{
                                participant.name
                            }}</span>
                            <span
                                v-if="participant.isLocal"
                                class="text-xs text-muted-foreground"
                                >(you)</span
                            >
                        </span>
                        <span
                            v-if="
                                roleLabel(participant) ||
                                participant.screenTrack
                            "
                            class="flex items-center gap-1.5 text-[11px] text-muted-foreground"
                        >
                            <span v-if="roleLabel(participant)">{{
                                roleLabel(participant)
                            }}</span>
                            <span
                                v-if="participant.screenTrack"
                                class="flex items-center gap-1 text-brand"
                            >
                                <MonitorUp class="size-3" /> Sharing
                            </span>
                        </span>
                    </span>

                    <QualityBars
                        v-if="
                            participant.quality !== 'good' &&
                            participant.quality !== 'unknown'
                        "
                        :level="participant.quality"
                        size="sm"
                        class="text-muted-foreground"
                    />
                    <component
                        :is="participant.cameraEnabled ? Video : VideoOff"
                        class="size-4"
                        :class="
                            participant.cameraEnabled
                                ? 'text-muted-foreground'
                                : 'text-muted-foreground/45'
                        "
                        :aria-label="
                            participant.cameraEnabled
                                ? 'Camera on'
                                : 'Camera off'
                        "
                    />
                    <component
                        :is="participant.micEnabled ? Mic : MicOff"
                        class="size-4"
                        :class="
                            participant.micEnabled
                                ? 'text-muted-foreground'
                                : 'text-destructive'
                        "
                        :aria-label="
                            participant.micEnabled ? 'Microphone on' : 'Muted'
                        "
                    />

                    <Popover v-if="!participant.isLocal">
                        <PopoverTrigger as-child>
                            <button
                                type="button"
                                :aria-label="`Set how loud ${participant.name} is for you`"
                                :class="[
                                    'flex size-7 items-center justify-center rounded-lg outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                    volumeOf(participant.identity) <= 0
                                        ? 'text-destructive'
                                        : volumeOf(participant.identity) < 1
                                          ? 'text-brand'
                                          : 'text-muted-foreground hover:text-foreground',
                                ]"
                                data-testid="participant-volume-open"
                            >
                                <component
                                    :is="volumeIcon(participant)"
                                    class="size-4"
                                />
                            </button>
                        </PopoverTrigger>
                        <PopoverContent align="end" class="w-64 p-3">
                            <p class="mb-2 text-xs text-muted-foreground">
                                How loud {{ participant.name }} is for you.
                                Nobody else hears a difference.
                            </p>
                            <VolumeControl
                                :identity="participant.identity"
                                :name="participant.name"
                            />
                        </PopoverContent>
                    </Popover>

                    <DropdownMenu v-if="canManage && !participant.isLocal">
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                :aria-label="`Options for ${participant.name}`"
                                class="flex size-7 items-center justify-center rounded-lg text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                data-testid="participant-menu"
                            >
                                <MoreHorizontal class="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-48">
                            <DropdownMenuItem
                                class="gap-2"
                                :disabled="!participant.micEnabled"
                                @click="emit('mute', participant)"
                            >
                                <MicOff class="size-4" /> Mute microphone
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="participant.meta.role !== 'host'"
                                class="gap-2 text-destructive focus:text-destructive"
                                @click="emit('remove', participant)"
                            >
                                <UserMinus class="size-4" /> Remove from meeting
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </li>
            </ul>
        </div>

        <footer class="flex gap-2 border-t p-3">
            <Button
                variant="outline"
                class="flex-1 rounded-xl"
                @click="emit('copyLink')"
            >
                <Link2 /> Copy link
            </Button>
            <Button
                v-if="me?.can.invite"
                class="flex-1 rounded-xl bg-brand text-brand-foreground hover:bg-brand/90"
                @click="emit('invite')"
            >
                <Mail /> Invite
            </Button>
        </footer>
    </SidePanel>
</template>
