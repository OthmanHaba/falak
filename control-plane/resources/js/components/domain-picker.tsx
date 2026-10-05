import { Input, Skeleton } from '@/components/falak';
import { registeredDomainPicker, type DomainPickerProps } from '@/lib/registry';
import { Link2 } from 'lucide-react';
import { Suspense } from 'react';

export type { DomainChoice, DomainChoiceType, DomainPickerProps } from '@/lib/registry';

/** The registered domain picker (Edge), or a plain custom-domain input when none is registered. */
export function DomainPicker(props: DomainPickerProps) {
    const Registered = registeredDomainPicker();
    if (Registered) {
        return (
            <Suspense fallback={<Skeleton className="h-8" />}>
                <Registered {...props} />
            </Suspense>
        );
    }

    return (
        <Input
            aria-label={props.ariaLabel}
            value={props.value?.type === 'custom' ? (props.value.name ?? '') : ''}
            onChange={(event) => props.onChange({ type: 'custom', name: event.target.value })}
            placeholder={props.testDomain ?? 'app.example.com'}
            prefix={<Link2 aria-hidden />}
            mono
        />
    );
}

/** Body value of a choice: omitted when nothing was chosen (the server applies the organization default). */
export function domainPayload(choice: DomainPickerProps['value']): DomainPickerProps['value'] | undefined {
    if (!choice) return undefined;

    return choice.type === 'custom' ? { type: 'custom', name: (choice.name ?? '').trim() } : { type: choice.type };
}
