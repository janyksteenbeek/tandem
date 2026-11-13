import {
    connected,
    credentials,
    end,
    join,
    leave,
    settings,
    show,
    state,
    status,
} from '@/routes/meet';
import {
    destroy as destroyFile,
    store as storeFile,
} from '@/routes/meet/files';
import { store as storeInvitation } from '@/routes/meet/invitations';
import {
    destroy as destroyMessage,
    index as indexMessages,
    react as reactToMessage,
    read as readMessages,
    store as storeMessage,
    update as updateMessage,
} from '@/routes/meet/messages';
import { admit, deny, mute, remove } from '@/routes/meet/participants';
import {
    consent as recordingConsent,
    stop as stopRecording,
    store as storeRecording,
} from '@/routes/meet/recordings';

/**
 * Every URL the meeting room talks to, bound to one meeting code. Built from the generated
 * route helpers so a renamed route is a type error here and nowhere else.
 */
export function meetEndpoints(code: string) {
    return {
        show: () => show.url(code),
        join: () => join.url(code),
        status: () => status.url(code),
        credentials: () => credentials.url(code),
        connected: () => connected.url(code),
        leave: () => leave.url(code),
        state: () => state.url(code),
        settings: () => settings.url(code),
        end: () => end.url(code),
        invitations: () => storeInvitation.url(code),

        messages: () => indexMessages.url(code),
        storeMessage: () => storeMessage.url(code),
        readMessages: () => readMessages.url(code),
        updateMessage: (uuid: string) =>
            updateMessage.url({ call: code, message: uuid }),
        destroyMessage: (uuid: string) =>
            destroyMessage.url({ call: code, message: uuid }),
        react: (uuid: string) =>
            reactToMessage.url({ call: code, message: uuid }),

        storeFile: () => storeFile.url(code),
        destroyFile: (uuid: string) =>
            destroyFile.url({ call: code, file: uuid }),

        startRecording: () => storeRecording.url(code),
        stopRecording: (uuid: string) =>
            stopRecording.url({ call: code, recording: uuid }),
        recordingConsent: () => recordingConsent.url(code),

        admit: (uuid: string) => admit.url({ call: code, participant: uuid }),
        deny: (uuid: string) => deny.url({ call: code, participant: uuid }),
        remove: (uuid: string) => remove.url({ call: code, participant: uuid }),
        mute: (uuid: string) => mute.url({ call: code, participant: uuid }),
    };
}

export type MeetEndpoints = ReturnType<typeof meetEndpoints>;
