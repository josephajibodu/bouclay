import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export function PortalCard({
    title,
    action,
    children,
    className,
}: {
    title?: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn(
                'rounded-xl border border-border bg-white p-5 shadow-sm',
                className,
            )}
        >
            {(title || action) && (
                <div className="mb-4 flex items-center justify-between gap-3">
                    {title && (
                        <h2 className="text-base font-semibold text-foreground">
                            {title}
                        </h2>
                    )}
                    {action}
                </div>
            )}
            {children}
        </section>
    );
}

export function PortalProductIcon({ name }: { name: string }) {
    return (
        <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-lg font-semibold text-zinc-600">
            {name.charAt(0).toUpperCase()}
        </div>
    );
}

export function PortalStatusDot({ color }: { color: string }) {
    const tones: Record<string, string> = {
        emerald: 'bg-emerald-500',
        amber: 'bg-amber-500',
        red: 'bg-red-500',
        blue: 'bg-blue-500',
        violet: 'bg-violet-500',
        zinc: 'bg-zinc-400',
    };

    return (
        <span
            className={cn(
                'size-2 rounded-full',
                tones[color] ?? tones.zinc,
            )}
        />
    );
}

export function PortalListRow({
    className,
    children,
}: {
    className?: string;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'rounded-lg transition-colors hover:bg-zinc-50',
                className,
            )}
        >
            {children}
        </div>
    );
}

export function PortalInteractiveCard({
    className,
    children,
}: {
    className?: string;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'rounded-xl border border-border bg-white p-5 shadow-sm transition-all hover:border-zinc-300 hover:shadow-md',
                className,
            )}
        >
            {children}
        </div>
    );
}
