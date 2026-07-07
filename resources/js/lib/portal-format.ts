export function formatPortalDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export function formatPortalMoney(amountMinor: number, currency: string): string {
    return `${currency} ${(amountMinor / 100).toLocaleString('en-US', {
        minimumFractionDigits: 2,
    })}`;
}

export function formatPortalPeriod(
    start: string | null,
    end: string | null,
): string | null {
    if (!start || !end) {
        return null;
    }

    const startDate = new Date(start);
    const endDate = new Date(end);

    const startLabel = startDate.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
    });
    const endLabel = endDate.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });

    return `${startLabel} – ${endLabel}`;
}
