import { HttpError } from '@/lib/http';

/**
 * A file upload that reports progress. The shared `api` helper is built on fetch, which
 * cannot report upload progress, so this one request uses XMLHttpRequest with the same
 * headers.
 */

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export type UploadHandle<T> = {
    promise: Promise<T>;
    abort: () => void;
};

export function uploadFile<T>(
    url: string,
    file: File,
    onProgress: (fraction: number) => void,
): UploadHandle<T> {
    const xhr = new XMLHttpRequest();

    const promise = new Promise<T>((resolve, reject) => {
        const body = new FormData();
        body.append('file', file);

        xhr.open('POST', url);
        xhr.withCredentials = true;
        xhr.responseType = 'json';
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-XSRF-TOKEN', xsrfToken());

        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable && event.total > 0) {
                onProgress(Math.min(1, event.loaded / event.total));
            }
        };

        xhr.onload = () => {
            const data = (xhr.response ?? {}) as Record<string, unknown>;

            if (xhr.status >= 200 && xhr.status < 300) {
                onProgress(1);
                resolve(data as T);

                return;
            }

            const message =
                xhr.status === 413
                    ? 'That file is too large to share here.'
                    : ((data.message as string | undefined) ??
                      `Upload failed (${xhr.status})`);

            reject(
                new HttpError(
                    xhr.status,
                    message,
                    (data.errors as Record<string, string[]> | undefined) ?? {},
                    data,
                ),
            );
        };

        xhr.onerror = () =>
            reject(new HttpError(0, 'The upload was interrupted. Try again.'));
        xhr.onabort = () => reject(new HttpError(0, 'Upload cancelled.'));

        xhr.send(body);
    });

    return { promise, abort: () => xhr.abort() };
}
