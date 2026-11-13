import { onBeforeUnmount, reactive } from 'vue';
import { toast } from 'vue-sonner';
import { api, reportError } from '@/lib/http';
import {
    download,
    show as showExport,
    store,
} from '@/routes/calls/recordings/exports';
import type { ExportKind, RecordingExport } from '@/types';

type Scope = { slug: string; call: string; recording: string };

const POLL_MS = 2500;

/**
 * Single-file exports of a recording (mixed audio, the screen with sound, a combined video).
 * They are built on a queue after the meeting, so asking for one returns immediately; this
 * watches it until it is ready, then starts the download.
 */
export function useRecordingExports(
    scope: () => Scope,
    initial: () => RecordingExport[],
) {
    const exports = reactive(new Map<ExportKind, RecordingExport>());
    const timers = new Map<ExportKind, ReturnType<typeof setTimeout>>();

    initial().forEach((item) => exports.set(item.kind, item));

    const args = (uuid: string): [string, string, string, string] => {
        const { slug, call, recording } = scope();

        return [slug, call, recording, uuid];
    };

    const save = (item: RecordingExport) => {
        window.location.assign(download.url(args(item.uuid)));
    };

    const watch = (kind: ExportKind, thenDownload: boolean) => {
        clearTimeout(timers.get(kind));

        timers.set(
            kind,
            setTimeout(async () => {
                const current = exports.get(kind);

                if (!current) {
                    return;
                }

                try {
                    const data = await api.get<{ export: RecordingExport }>(
                        showExport.url(args(current.uuid)),
                    );

                    exports.set(kind, data.export);

                    if (data.export.status === 'ready') {
                        toast.success(`${data.export.label} is ready`);

                        if (thenDownload) {
                            save(data.export);
                        }
                    } else if (data.export.status === 'failed') {
                        toast.error(
                            data.export.error ??
                                `${data.export.label} could not be built.`,
                        );
                    } else {
                        watch(kind, thenDownload);
                    }
                } catch {
                    // A dropped poll is not a failed export; keep watching.
                    watch(kind, thenDownload);
                }
            }, POLL_MS),
        );
    };

    /** Download the export of this kind, building it first when it does not exist yet. */
    const request = async (kind: ExportKind) => {
        const existing = exports.get(kind);

        if (existing?.status === 'ready') {
            save(existing);

            return;
        }

        if (
            existing &&
            (existing.status === 'queued' || existing.status === 'processing')
        ) {
            toast.message('Still being built. It downloads when it is ready.');
            watch(kind, true);

            return;
        }

        try {
            const { slug, call, recording } = scope();
            const data = await api.post<{ export: RecordingExport }>(
                store.url([slug, call, recording]),
                { kind },
            );

            exports.set(kind, data.export);

            if (data.export.status === 'ready') {
                save(data.export);
            } else {
                toast.message(
                    `Building ${data.export.label.toLowerCase()}. It downloads when it is ready.`,
                );
                watch(kind, true);
            }
        } catch (error) {
            reportError(error, 'The export could not be started.');
        }
    };

    const isBusy = (kind: ExportKind) => {
        const status = exports.get(kind)?.status;

        return status === 'queued' || status === 'processing';
    };

    // Pick up exports that were already under way when the page opened.
    exports.forEach((item, kind) => {
        if (item.status === 'queued' || item.status === 'processing') {
            watch(kind, false);
        }
    });

    onBeforeUnmount(() => timers.forEach((timer) => clearTimeout(timer)));

    return { exports, request, isBusy };
}
