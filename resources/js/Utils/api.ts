/**
 * Small JSON fetch helper for the handful of endpoints that are better used
 * as an API than as an Inertia visit.
 *
 * Fortify's security endpoints answer JSON when asked (201 for a confirmed
 * password, 200 for enabling two-factor) instead of redirecting. That matters
 * for the two-factor enrolment flow: as Inertia visits, confirming a password
 * would navigate away from the half-finished setup and Fortify would send the
 * user to the "intended" URL — which was a POST — leaving them stranded.
 * Asking for JSON keeps the whole flow on one page.
 *
 * Session cookies still authenticate these calls; the CSRF token is read from
 * the meta tag the root view renders.
 */

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        /** Laravel's validation errors, when the response carried any. */
        public readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

/**
 * Narrower than RequestInit on purpose: headers are a plain record so they can
 * be merged, rather than the array/Headers union fetch also accepts.
 */
export interface ApiFetchOptions extends Omit<RequestInit, 'headers'> {
    headers?: Record<string, string>;
}

export async function apiFetch<T>(url: string, init: ApiFetchOptions = {}): Promise<T> {
    const { headers, ...rest } = init;

    const response = await fetch(url, {
        ...rest,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
            ...headers,
        },
        // Same-origin only; these are session-authenticated.
        credentials: 'same-origin',
    });

    if (!response.ok) {
        let message = `Request failed with status ${response.status}`;
        let errors: Record<string, string[]> = {};

        try {
            const body = (await response.json()) as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            message = body.message ?? message;
            errors = body.errors ?? {};
        } catch {
            // A non-JSON error body tells us nothing useful; the status does.
        }

        throw new ApiError(message, response.status, errors);
    }

    // 201 and 204 carry no body.
    if (response.status === 204 || response.headers.get('content-length') === '0') {
        return undefined as T;
    }

    const text = await response.text();

    return (text === '' ? undefined : JSON.parse(text)) as T;
}

/**
 * The first validation message for a field, if the error carried one.
 */
export function firstError(error: unknown, field: string): string | undefined {
    if (error instanceof ApiError) {
        return error.errors[field]?.[0] ?? error.message;
    }

    return undefined;
}
