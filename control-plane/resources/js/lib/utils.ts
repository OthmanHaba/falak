import { type ClassValue, clsx } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

// Teach tailwind-merge the Kiln-only theme keys so `text-2xs` is a font size (not a color) and `shadow-panel` a shadow.
const twMerge = extendTailwindMerge({
    extend: {
        theme: {
            text: ['2xs'],
            shadow: ['panel'],
        },
    },
});

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}
