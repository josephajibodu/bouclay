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
 * Deterministic colored initials tile — stands in for a product image
 * until upload ships (CATALOG_DESIGN.md §5.1, §12).
 */
export function ProductMonogram({
    id,
    name,
    className,
}: {
    id: number;
    name: string;
    className?: string;
}) {
    const initials = name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase())
        .join('');

    const hue = HUES[id % HUES.length];

    return (
        <div
            className={cn(
                'flex size-10 shrink-0 items-center justify-center rounded-lg text-sm font-semibold text-white',
                hue,
                className,
            )}
        >
            {initials || '?'}
        </div>
    );
}
