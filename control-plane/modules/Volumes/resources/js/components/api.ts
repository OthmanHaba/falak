import { toast } from '@/components/falak';
import { HttpError, errorMessage, requestJson, type HttpMethod } from '@/lib/http';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/** Props the volume page reloads after an action. */
export const SHOW_RELOAD = ['volume', 'backups', 'schedules', 'operations'];

/**
 * A JSON action of the volume page: validation errors per field, a toast, then a partial reload.
 */
export function useVolumeAction() {
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const run = async <T = unknown>(method: HttpMethod, url: string, body: unknown, success: string, detail?: string): Promise<T | null> => {
        setBusy(true);
        setErrors({});
        try {
            const response = await requestJson<T>(url, method, body);
            toast.success(success, detail);
            router.reload({ only: SHOW_RELOAD });

            return response;
        } catch (error) {
            if (error instanceof HttpError) setErrors(error.errors);
            toast.error('That did not work', errorMessage(error));

            return null;
        } finally {
            setBusy(false);
        }
    };

    return { busy, errors, setErrors, run };
}
