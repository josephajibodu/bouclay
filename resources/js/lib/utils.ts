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
 * Drop entries that already match their default, so a filter's URL query
 * string only ever shows what the user actually changed — landing on a
 * list page (or clearing its filters) stays a clean, param-free URL.
 */
export function nonDefaultParams<T extends Record<string, string>>(
    values: T,
    defaults: Partial<T>,
): Partial<T> {
    const result: Partial<T> = {};

    for (const key in values) {
        if (values[key] !== defaults[key]) {
            result[key] = values[key];
        }
    }

    return result;
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

/**
 * Format a major-currency-unit amount (e.g. Naira, not kobo) for display.
 */
export function formatMoney(amount: number, currency: string): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        minimumFractionDigits: amount % 1 === 0 ? 0 : 2,
        maximumFractionDigits: 2,
    }).format(amount);
}

/**
 * Format a recurring price as a short label, e.g. "₦15,000/mo".
 */
export function formatPriceInterval(
    amount: number,
    currency: string,
    interval: 'day' | 'week' | 'month' | 'year',
    frequency: number = 1,
): string {
    const unit = { day: 'day', week: 'wk', month: 'mo', year: 'yr' }[interval];
    const suffix = frequency === 1 ? `/${unit}` : `/${frequency} ${unit}s`;

    return `${formatMoney(amount, currency)}${suffix}`;
}

/**
 * Summarize a graduated tier table as it's being built, e.g.
 * "1-20: NGN 20/unit · 21+: NGN 67/unit" — so users see the shape of what
 * they're creating before submitting, same as the standard-price preview.
 */
export function formatTierSummary(
    tiers: { upTo: number | null; unitAmount: number }[],
    currency: string,
): string {
    let floor = 1;

    return tiers
        .map((tier) => {
            const range =
                tier.upTo !== null ? `${floor}-${tier.upTo}` : `${floor}+`;

            if (tier.upTo !== null) {
                floor = tier.upTo + 1;
            }

            return `${range}: ${formatMoney(tier.unitAmount, currency)}/unit`;
        })
        .join(' · ');
}

/**
 * Normalize any recurring interval to an approximate monthly amount, for
 * comparing plans of different cadences at a glance.
 */
export function toMonthlyEquivalent(
    amount: number,
    interval: 'day' | 'week' | 'month' | 'year',
    frequency: number = 1,
): number {
    const daysPerUnit = { day: 1, week: 7, month: 30, year: 365 }[interval];
    const totalDays = daysPerUnit * frequency;

    return (amount / totalDays) * 30;
}
