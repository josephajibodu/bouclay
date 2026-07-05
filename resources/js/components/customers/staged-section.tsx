import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * A placeholder for a section whose feature lands in a later phase. It reads
 * as intentionally staged — a real (disabled) action, forward-looking copy —
 * rather than missing (CUSTOMERS_DESIGN §11).
 */
export function StagedSection({
    title,
    icon: Icon,
    heading,
    body,
    availability,
    action,
}: {
    title: string;
    icon: LucideIcon;
    heading: string;
    body: string;
    availability: string;
    action?: ReactNode;
}) {
    return (
        <section className="space-y-3">
            <div className="flex items-center justify-between">
                <h2 className="text-lg font-semibold">{title}</h2>
                {action}
            </div>
            <div className="space-y-2 rounded-lg border border-dashed p-6 text-center">
                <div className="mx-auto flex size-10 items-center justify-center rounded-full bg-muted">
                    <Icon className="size-5 text-muted-foreground" />
                </div>
                <p className="font-medium">{heading}</p>
                <p className="mx-auto max-w-md text-sm text-muted-foreground">
                    {body}
                </p>
                <p className="text-xs text-muted-foreground">{availability}</p>
            </div>
        </section>
    );
}
