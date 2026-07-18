import type { CatalogStatus, PlanStatus } from '@/types';

type StatusMeta = {
    label: string;
    /** Tailwind background class for the badge dot. */
    dot: string;
};

/** active/archived — products, prices, pricing journeys. */
export const CATALOG_STATUS_META: Record<CatalogStatus, StatusMeta> = {
    active: { label: 'Active', dot: 'bg-emerald-500' },
    archived: { label: 'Archived', dot: 'bg-zinc-400' },
};

/** draft/active/archived — plans. */
export const PLAN_STATUS_META: Record<PlanStatus, StatusMeta> = {
    draft: { label: 'Draft', dot: 'bg-amber-500' },
    active: { label: 'Active', dot: 'bg-emerald-500' },
    archived: { label: 'Archived', dot: 'bg-zinc-400' },
};

/** Generic enabled/disabled toggle — webhook endpoints, gateway connections, etc. */
export const ENABLED_STATUS_META: Record<'enabled' | 'disabled', StatusMeta> =
    {
        enabled: { label: 'Active', dot: 'bg-emerald-500' },
        disabled: { label: 'Disabled', dot: 'bg-zinc-400' },
    };

/** pending/succeeded/failed — webhook delivery attempts. */
export const DELIVERY_STATUS_META: Record<
    'pending' | 'succeeded' | 'failed',
    StatusMeta
> = {
    pending: { label: 'Pending', dot: 'bg-amber-500' },
    succeeded: { label: 'Delivered', dot: 'bg-emerald-500' },
    failed: { label: 'Failed', dot: 'bg-red-500' },
};

/** active/completed/canceled — subscription item (pricing journey) schedules. */
export const SCHEDULE_STATUS_META: Record<
    'active' | 'completed' | 'canceled',
    StatusMeta
> = {
    active: { label: 'Active', dot: 'bg-emerald-500' },
    completed: { label: 'Completed', dot: 'bg-blue-500' },
    canceled: { label: 'Canceled', dot: 'bg-zinc-400' },
};

/** Generic active/inactive toggle — discounts, etc. */
export const ACTIVE_STATUS_META: Record<'active' | 'inactive', StatusMeta> = {
    active: { label: 'Active', dot: 'bg-emerald-500' },
    inactive: { label: 'Inactive', dot: 'bg-zinc-400' },
};
