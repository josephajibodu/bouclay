import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

/**
 * Format an ISO timestamp as a short relative time (e.g. "2 minutes ago").
 */
export function formatRelativeTime(isoDate: string): string {
    const seconds = Math.max(
        0,
        Math.floor((Date.now() - new Date(isoDate).getTime()) / 1000),
    );

    const units: [number, string][] = [
        [60, 'second'],
        [60, 'minute'],
        [24, 'hour'],
        [30, 'day'],
        [12, 'month'],
        [Number.POSITIVE_INFINITY, 'year'],
    ];

    let value = seconds;
    let unit = 'second';

    for (const [amount, name] of units) {
        if (value < amount) {
            unit = name;
            break;
        }

        value = Math.floor(value / amount);
        unit = name;
    }

    if (unit === 'second' && value < 5) {
        return 'just now';
    }

    return `${value} ${unit}${value === 1 ? '' : 's'} ago`;
}
