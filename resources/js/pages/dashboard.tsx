import type { InertiaLinkProps } from '@inertiajs/react';
import { Head, Link, usePage } from '@inertiajs/react';
import { Check, ChevronRight } from 'lucide-react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import PendingInvitationsModal from '@/components/pending-invitations-modal';
import { Button } from '@/components/ui/button';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { edit as editGeneralSettings } from '@/routes/general';
import type { DashboardInvitation, OnboardingState } from '@/types';

type Props = {
    pendingInvitations?: DashboardInvitation[];
    onboarding: OnboardingState | null;
};

type ChecklistItem = {
    key: string;
    title: string;
    description: string;
    href: NonNullable<InertiaLinkProps['href']> | null;
    cta: string;
    done: boolean;
};

export default function Dashboard({
    pendingInvitations = [],
    onboarding,
}: Props) {
    const { currentTeam } = usePage().props;
    const [showInvitations, setShowInvitations] = useState(
        pendingInvitations.length > 0,
    );

    const dismissKey = currentTeam
        ? `onboarding-dismissed-${currentTeam.slug}`
        : null;
    const toastedKey = currentTeam
        ? `onboarding-complete-toasted-${currentTeam.slug}`
        : null;

    const [dismissed, setDismissed] = useState(
        () => (dismissKey && sessionStorage.getItem(dismissKey) === '1') ?? false,
    );
    const [expanded, setExpanded] = useState(false);

    const items: ChecklistItem[] = useMemo(() => {
        if (!onboarding) {
            return [];
        }

        return [
            {
                key: 'business',
                title: 'Confirm your business details',
                description: 'We use this for invoices and tax settings.',
                href: editGeneralSettings(),
                cta: 'Review',
                done: onboarding.businessConfirmed,
            },
            {
                key: 'nomba',
                title: 'Connect your Nomba account',
                description:
                    "Bouclay never touches your money — Nomba does. Start with test keys, no risk.",
                href: onboarding.links.nomba,
                cta: 'Connect',
                done: onboarding.nombaConnected,
            },
            {
                key: 'apiKey',
                title: 'Generate a Bouclay API key',
                description: 'This is how your app talks to Bouclay.',
                href: onboarding.links.apiKeys,
                cta: 'Generate',
                done: onboarding.apiKeyGenerated,
            },
            {
                key: 'webhook',
                title: 'Confirm your webhook is reachable',
                description:
                    'So subscription events reach your app in real time.',
                href: onboarding.links.webhooks,
                cta: 'Verify',
                done: onboarding.webhookVerified,
            },
            {
                key: 'firstProduct',
                title: 'Create your first product',
                description:
                    "What you'll actually sell — add a price to start billing for it.",
                href: onboarding.links.products,
                cta: 'Create',
                done: onboarding.firstProductCreated,
            },
        ];
    }, [onboarding]);

    const doneCount = items.filter((item) => item.done).length;
    const allDone = onboarding !== null && doneCount === items.length;

    useEffect(() => {
        if (
            allDone &&
            toastedKey &&
            sessionStorage.getItem(toastedKey) !== '1'
        ) {
            toast.success("You're ready to build");
            sessionStorage.setItem(toastedKey, '1');
        }
    }, [allDone, toastedKey]);

    const dismiss = () => {
        setDismissed(true);
        setExpanded(false);

        if (dismissKey) {
            sessionStorage.setItem(dismissKey, '1');
        }
    };

    const showFullChecklist =
        onboarding !== null &&
        !allDone &&
        !dismissed &&
        (doneCount === 0 || expanded);
    const showBanner =
        onboarding !== null &&
        !allDone &&
        !dismissed &&
        doneCount > 0 &&
        !expanded;
    const showCompletePill = onboarding !== null && allDone && !expanded;
    const showCompletedCard = onboarding !== null && allDone && expanded;

    let onboardingView: React.ReactNode = null;

    if (showFullChecklist || showCompletedCard) {
        onboardingView = (
            <OnboardingChecklist
                key="checklist"
                teamName={currentTeam?.name ?? 'your business'}
                items={items}
                doneCount={doneCount}
                allDone={allDone}
                onDismiss={expanded ? () => setExpanded(false) : dismiss}
                onSkip={doneCount === 0 ? dismiss : undefined}
            />
        );
    } else if (showBanner) {
        onboardingView = (
            <OnboardingBanner
                key="banner"
                items={items}
                doneCount={doneCount}
                onExpand={() => setExpanded(true)}
                onDismiss={dismiss}
            />
        );
    } else if (showCompletePill) {
        onboardingView = (
            <button
                key="pill"
                type="button"
                onClick={() => setExpanded(true)}
                className="flex w-fit animate-in items-center gap-2 rounded-full border bg-muted/30 px-3 py-1.5 text-sm text-muted-foreground duration-300 ease-out fade-in slide-in-from-top-2 hover:bg-muted/60"
                data-test="onboarding-complete-pill"
            >
                <Check className="size-3.5 text-emerald-500" />
                Setup complete
            </button>
        );
    }

    return (
        <>
            <Head title="Overview" />
            <PendingInvitationsModal
                invitations={pendingInvitations}
                open={pendingInvitations.length > 0 && showInvitations}
                onOpenChange={setShowInvitations}
            />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <AnimatedHeight>{onboardingView}</AnimatedHeight>

                <div className="grid auto-rows-min gap-4 md:grid-cols-3">
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                </div>
                <div className="relative min-h-[100vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
                    <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                </div>
            </div>
        </>
    );
}

/**
 * Animates height and opacity when its child changes or becomes null, so
 * swapping/hiding onboarding views resizes smoothly instead of jumping.
 * Keeps rendering the outgoing child for one transition cycle so it fades
 * out in place rather than vanishing before the collapse finishes.
 */
function AnimatedHeight({ children }: { children: React.ReactNode }) {
    const innerRef = useRef<HTMLDivElement>(null);
    const isVisible = Boolean(children);

    const [wasVisible, setWasVisible] = useState(isVisible);
    const [renderedChildren, setRenderedChildren] = useState(children);
    const [height, setHeight] = useState(0);

    if (isVisible !== wasVisible) {
        setWasVisible(isVisible);

        if (!isVisible) {
            setHeight(0);
        }
    }

    if (isVisible && children !== renderedChildren) {
        setRenderedChildren(children);
    }

    useEffect(() => {
        if (isVisible) {
            return;
        }

        const timeout = setTimeout(() => setRenderedChildren(null), 300);

        return () => clearTimeout(timeout);
    }, [isVisible]);

    useLayoutEffect(() => {
        const node = innerRef.current;

        if (!node || !isVisible) {
            return;
        }

        setHeight(node.scrollHeight);
    }, [renderedChildren, isVisible]);

    return (
        <div
            className="overflow-hidden transition-[height,opacity] duration-300 ease-in-out"
            style={{ height, opacity: isVisible ? 1 : 0 }}
        >
            <div ref={innerRef}>{renderedChildren}</div>
        </div>
    );
}

function OnboardingChecklist({
    teamName,
    items,
    doneCount,
    allDone,
    onDismiss,
    onSkip,
}: {
    teamName: string;
    items: ChecklistItem[];
    doneCount: number;
    allDone: boolean;
    onDismiss?: () => void;
    onSkip?: () => void;
}) {
    return (
        <div
            className="animate-in space-y-4 rounded-xl border p-6 duration-300 ease-out fade-in slide-in-from-top-2"
            data-test="onboarding-checklist"
        >
            <div className="flex items-start justify-between gap-4">
                <div className="space-y-1">
                    <h2 className="text-lg font-semibold">
                        Welcome to {teamName}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Let's get you ready to accept real subscriptions.
                    </p>
                </div>
                {onDismiss && (
                    <Button variant="ghost" size="sm" onClick={onDismiss}>
                        {allDone ? 'Collapse' : 'Dismiss'}
                    </Button>
                )}
            </div>

            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <div className="flex gap-1">
                    {items.map((item) => (
                        <span
                            key={item.key}
                            className={cn(
                                'size-2 rounded-full transition-colors duration-300',
                                item.done ? 'bg-emerald-500' : 'bg-muted',
                            )}
                        />
                    ))}
                </div>
                {doneCount} of {items.length} done
            </div>

            <div className="divide-y rounded-lg border">
                {items.map((item) => (
                    <div
                        key={item.key}
                        className="flex items-center justify-between gap-4 p-4"
                        data-test={`onboarding-item-${item.key}`}
                    >
                        <div className="flex items-start gap-3">
                            <span
                                className={cn(
                                    'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border transition-colors duration-300',
                                    item.done &&
                                        'border-emerald-500 bg-emerald-500 text-white',
                                )}
                            >
                                {item.done && (
                                    <Check className="size-3 animate-in duration-200 spin-in-45 zoom-in" />
                                )}
                            </span>
                            <div>
                                <p className="font-medium">{item.title}</p>
                                <p className="text-sm text-muted-foreground">
                                    {item.description}
                                </p>
                            </div>
                        </div>

                        {item.href && !item.done ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={item.href}>
                                    {item.cta} <ChevronRight />
                                </Link>
                            </Button>
                        ) : item.done ? (
                            <span className="text-sm text-muted-foreground">
                                Done
                            </span>
                        ) : null}
                    </div>
                ))}
            </div>

            {onSkip && (
                <button
                    type="button"
                    onClick={onSkip}
                    className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    data-test="onboarding-skip"
                >
                    Skip for now, I'll explore first →
                </button>
            )}
        </div>
    );
}

function OnboardingBanner({
    items,
    doneCount,
    onExpand,
    onDismiss,
}: {
    items: ChecklistItem[];
    doneCount: number;
    onExpand: () => void;
    onDismiss: () => void;
}) {
    const nextItem = items.find((item) => !item.done);

    return (
        <div
            className="flex animate-in items-center justify-between gap-4 rounded-lg border bg-muted/30 px-4 py-2 duration-300 ease-out fade-in slide-in-from-top-2"
            data-test="onboarding-banner"
        >
            <button
                type="button"
                onClick={onExpand}
                className="flex items-center gap-2 text-sm"
            >
                <div className="flex gap-1">
                    {items.map((item) => (
                        <span
                            key={item.key}
                            className={cn(
                                'size-2 rounded-full transition-colors duration-300',
                                item.done ? 'bg-emerald-500' : 'bg-muted-foreground/30',
                            )}
                        />
                    ))}
                </div>
                <span className="font-medium">
                    {doneCount} of {items.length} · Finish setup
                </span>
            </button>

            <div className="flex items-center gap-2">
                {nextItem?.href && (
                    <Button asChild variant="ghost" size="sm">
                        <Link href={nextItem.href}>
                            {nextItem.cta} <ChevronRight />
                        </Link>
                    </Button>
                )}
                <Button variant="ghost" size="sm" onClick={onDismiss}>
                    Dismiss
                </Button>
            </div>
        </div>
    );
}

Dashboard.layout = () => ({
    breadcrumbs: [{ title: 'Overview', href: dashboard() }],
});
