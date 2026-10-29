import { toast } from 'vue-sonner';

export class HttpError extends Error {
    constructor(
        public status: number,
        message: string,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

type Body = Record<string, unknown> | FormData | undefined;

async function request<T>(
    method: string,
    url: string,
    body?: Body,
): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrfToken(),
    };

    let payload: BodyInit | undefined;

    if (body instanceof FormData) {
        payload = body;
    } else if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }

    const response = await fetch(url, {
        method,
        headers,
        body: payload,
        credentials: 'same-origin',
    });

    if (response.status === 204) {
        return undefined as T;
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const message =
            (data as { message?: string }).message ??
            `Request failed (${response.status})`;

        throw new HttpError(
            response.status,
            message,
            (data as { errors?: Record<string, string[]> }).errors ?? {},
        );
    }

    return data as T;
}

export const api = {
    get: <T>(
        url: string,
        params?: Record<string, string | number | undefined>,
    ) => {
        const query = params
            ? '?' +
              Object.entries(params)
                  .filter(([, v]) => v !== undefined && v !== '')
                  .map(
                      ([k, v]) =>
                          `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`,
                  )
                  .join('&')
            : '';

        return request<T>('GET', url + query);
    },
    post: <T>(url: string, body?: Body) => request<T>('POST', url, body),
    patch: <T>(url: string, body?: Body) => request<T>('PATCH', url, body),
    put: <T>(url: string, body?: Body) => request<T>('PUT', url, body),
    delete: <T>(url: string, body?: Body) => request<T>('DELETE', url, body),
};

export function reportError(
    error: unknown,
    fallback = 'Something went wrong.',
): void {
    if (error instanceof HttpError) {
        const first = Object.values(error.errors)[0]?.[0];
        toast.error(first ?? error.message ?? fallback);

        return;
    }

    toast.error(fallback);
    console.error(error);
}
