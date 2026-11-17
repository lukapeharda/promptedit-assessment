/** POST JSON to a Laravel route with the XSRF cookie, returning parsed JSON. */
export async function postJson<T>(url: string, body: unknown, signal?: AbortSignal): Promise<T> {
    const token = decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1] ?? '');

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': token,
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
        signal,
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw Object.assign(new Error(data.message ?? `Request failed (${response.status})`), { status: response.status, errors: data.errors ?? {} });
    }

    return data as T;
}
