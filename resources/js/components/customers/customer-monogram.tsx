import { cn } from '@/lib/utils';

const HUES = [
    'bg-violet-500',
    'bg-blue-500',
    'bg-emerald-500',
    'bg-amber-500',
    'bg-rose-500',
    'bg-cyan-500',
];

/**
 * Deterministic colored initial tile giving every customer an instant visual
 * identity (CUSTOMERS_DESIGN §5.1, §7.2). Falls back to the email's first
 * letter when a customer has no name.
 */
export function CustomerMonogram({
    id,
    name,
    email,
    className,
}: {
    id: number;
    name: string | null;
    email: string;
    className?: string;
}) {
    const source = (name?.trim() || email).trim();
    const initial = source[0]?.toUpperCase() ?? '?';
    const hue = HUES[id % HUES.length];

    return (
        <div
            className={cn(
                'flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white',
                hue,
                className,
            )}
        >
            {initial}
        </div>
    );
}
