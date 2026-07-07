import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    Check,
    CreditCard,
    FileText,
    KeyRound,
    RadioTower,
    RefreshCcw,
    Repeat2,
    ShieldCheck,
    Webhook,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, login, register } from '@/routes';

const capabilities = [
    {
        title: 'Subscription engine',
        description:
            'Create plans, attach prices, manage trials, billing cycles, pauses, resumes, and cancellations without rebuilding the state machine.',
        icon: Repeat2,
    },
    {
        title: 'Invoices and payments',
        description:
            'Generate invoice records, collect through Nomba checkout and charge APIs, and keep every payment attempt tied to the customer.',
        icon: FileText,
    },
    {
        title: 'Dunning and retries',
        description:
            'Handle failed charges with retry windows, past-due states, and webhook events your product can react to in real time.',
        icon: RefreshCcw,
    },
];

const workflowSteps = [
    'Connect your own Nomba account',
    'Create products, prices, and trial offers',
    'Start subscriptions through the Bouclay API',
    'Receive lifecycle events in your app',
];

const productPillars = [
    {
        eyebrow: 'BYOK payments',
        title: 'Your Nomba keys, your money flow',
        description:
            'Bouclay coordinates billing while Nomba remains the payment processor. Merchant funds stay on your Nomba setup.',
        icon: KeyRound,
    },
    {
        eyebrow: 'API-first',
        title: 'Built for integrators',
        description:
            'Typed dashboard flows, public API docs, API keys, inbound webhooks, and outbound events are first-class from day one.',
        icon: Webhook,
    },
    {
        eyebrow: 'Multi-tenant',
        title: 'Clean team boundaries',
        description:
            'Every product, customer, subscription, invoice, payment, webhook, and processor connection is scoped to the right team.',
        icon: ShieldCheck,
    },
];

const billingPreviewRestingTransform =
    'rotateX(46deg) rotateY(-5deg) rotateZ(-5deg) translate3d(0, 0.75rem, 0)';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Bouclay - Recurring billing on Nomba" />

            <main className="relative min-h-screen overflow-hidden bg-background text-foreground">
                <div className="absolute inset-x-0 top-0 -z-10 h-[42rem] bg-[radial-gradient(circle_at_top_left,oklch(0.52_0.105_223.128_/_0.18),transparent_34rem),linear-gradient(180deg,oklch(0.985_0.001_106.423),transparent)] dark:bg-[radial-gradient(circle_at_top_left,oklch(0.715_0.143_215.221_/_0.2),transparent_34rem),linear-gradient(180deg,oklch(0.216_0.006_56.043),transparent)]" />

                <header className="mx-auto flex w-full max-w-7xl items-center justify-between gap-4 px-6 py-6 lg:px-8">
                    <Link
                        href="/"
                        className="flex items-center gap-2 font-semibold tracking-tight"
                    >
                        <span className="flex size-6 items-center justify-center rounded-lg text-primary">
                            <AppLogoIcon className="size-full fill-current" />
                        </span>
                        <span>Bouclay</span>
                    </Link>

                    <nav className="flex items-center gap-2 text-sm">
                        <Link
                            href="/docs/api"
                            className="hidden rounded-md px-3 py-2 text-muted-foreground transition-colors hover:text-foreground sm:inline-flex"
                        >
                            API docs
                        </Link>

                        {auth.user ? (
                            <Link
                                href={dashboard()}
                                className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground shadow-sm transition-colors hover:bg-primary/90"
                            >
                                Dashboard
                                <ArrowRight className="size-4" />
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={login()}
                                    className="rounded-md px-3 py-2 text-muted-foreground transition-colors hover:text-foreground"
                                >
                                    Log in
                                </Link>
                                <Link
                                    href={register()}
                                    className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground shadow-sm transition-colors hover:bg-primary/90"
                                >
                                    Start building
                                    <ArrowRight className="size-4" />
                                </Link>
                            </>
                        )}
                    </nav>
                </header>

                <section className="mx-auto grid w-full max-w-7xl gap-12 px-6 py-16 lg:grid-cols-[minmax(0,1fr)_28rem] lg:items-center lg:px-8 lg:py-24">
                    <div className="max-w-3xl">
                        <div className="inline-flex items-center gap-2 rounded-full border bg-card px-3 py-1 text-sm text-muted-foreground shadow-sm">
                            <RadioTower className="size-4 text-primary" />
                            Managed recurring billing for Nomba integrators
                        </div>

                        <h1 className="mt-8 text-4xl font-semibold tracking-tight text-balance sm:text-6xl">
                            Stop rebuilding subscriptions around payment
                            primitives.
                        </h1>

                        <p className="mt-6 max-w-2xl text-lg leading-8 text-muted-foreground">
                            Bouclay sits on top of your Nomba account and gives
                            your product catalog, subscriptions, invoices,
                            dunning, and webhooks through one integration.
                        </p>

                        <div className="mt-10 flex flex-col gap-3 sm:flex-row">
                            <Link
                                href={auth.user ? dashboard() : register()}
                                className="inline-flex items-center justify-center gap-2 rounded-md bg-primary px-5 py-3 text-sm font-medium text-primary-foreground shadow-sm transition-colors hover:bg-primary/90"
                            >
                                {auth.user
                                    ? 'Open dashboard'
                                    : 'Create your workspace'}
                                <ArrowRight className="size-4" />
                            </Link>
                            <Link
                                href="/docs/api"
                                className="inline-flex items-center justify-center rounded-md border bg-background px-5 py-3 text-sm font-medium shadow-sm transition-colors hover:bg-accent"
                            >
                                Read the API docs
                            </Link>
                        </div>
                    </div>

                    <BillingPreview />
                </section>

                <section
                    id="capabilities"
                    className="mx-auto w-full max-w-7xl px-6 py-10 lg:px-8"
                >
                    <div className="grid gap-4 md:grid-cols-3">
                        {capabilities.map((capability) => (
                            <CapabilityCard
                                key={capability.title}
                                {...capability}
                            />
                        ))}
                    </div>
                </section>

                <section
                    id="workflow"
                    className="mx-auto grid w-full max-w-7xl gap-8 px-6 py-16 lg:grid-cols-[0.9fr_1.1fr] lg:px-8"
                >
                    <div>
                        <p className="text-sm font-medium tracking-[0.24em] text-primary uppercase">
                            How it works
                        </p>
                        <h2 className="mt-4 text-3xl font-semibold tracking-tight">
                            One billing layer between Nomba and your product.
                        </h2>
                        <p className="mt-4 text-muted-foreground">
                            Nomba handles payment primitives. Bouclay handles
                            the subscription lifecycle your app needs to stay in
                            sync.
                        </p>
                    </div>

                    <div className="rounded-2xl border bg-card p-6 shadow-sm">
                        <ol className="grid gap-4">
                            {workflowSteps.map((step, index) => (
                                <li
                                    key={step}
                                    className="flex items-start gap-4 rounded-xl border bg-background p-4"
                                >
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                                        {index + 1}
                                    </span>
                                    <div>
                                        <p className="font-medium">{step}</p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {workflowDescription(index)}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </div>
                </section>

                <section
                    id="platform"
                    className="mx-auto w-full max-w-7xl px-6 py-10 lg:px-8"
                >
                    <div className="grid gap-4 lg:grid-cols-3">
                        {productPillars.map((pillar) => (
                            <PillarCard key={pillar.title} {...pillar} />
                        ))}
                    </div>
                </section>

                <section className="mx-auto w-full max-w-7xl px-6 py-16 lg:px-8">
                    <div className="overflow-hidden rounded-3xl border bg-primary text-primary-foreground shadow-sm">
                        <div className="grid gap-8 p-8 lg:grid-cols-[1fr_auto] lg:items-center lg:p-10">
                            <div>
                                <p className="text-sm font-medium tracking-[0.24em] uppercase opacity-70">
                                    For African internet businesses
                                </p>
                                <h2 className="mt-4 max-w-2xl text-3xl font-semibold tracking-tight">
                                    Launch recurring revenue without turning
                                    your product team into a billing team.
                                </h2>
                            </div>
                            <Link
                                href={auth.user ? dashboard() : register()}
                                className="inline-flex items-center justify-center gap-2 rounded-md bg-primary-foreground px-5 py-3 text-sm font-medium text-primary shadow-sm transition-colors hover:bg-primary-foreground/90"
                            >
                                {auth.user ? 'Go to dashboard' : 'Get started'}
                                <ArrowRight className="size-4" />
                            </Link>
                        </div>
                    </div>
                </section>

                <Footer isAuthenticated={Boolean(auth.user)} />
            </main>
        </>
    );
}

function BillingPreview() {
    return (
        <aside className="group relative min-h-[38rem] [perspective:1200px]">
            <div className="absolute inset-x-10 bottom-12 h-20 rounded-full bg-primary/20 blur-3xl transition-opacity duration-500 group-hover:opacity-80" />
            <div className="absolute inset-x-8 bottom-20 h-28 rounded-[50%] bg-zinc-950/10 blur-2xl dark:bg-black/40" />

            <div
                className="relative rounded-3xl border bg-card p-4 shadow-2xl shadow-primary/10 [transform-style:preserve-3d]"
                style={{ transform: billingPreviewRestingTransform }}
            >
                <div className="absolute inset-x-8 -bottom-5 h-8 [transform:translateZ(-2.5rem)] rounded-b-3xl border-x border-b bg-card/80 shadow-xl" />
                <div className="absolute inset-y-6 -right-4 w-6 [transform:rotateY(78deg)_translateZ(-0.35rem)] rounded-r-2xl border-y border-r bg-muted shadow-md" />

                <div className="relative [transform:translateZ(2rem)] rounded-2xl border bg-background p-5 [transform-style:preserve-3d]">
                    <div className="flex items-center justify-between gap-4">
                        <div>
                            <p className="text-sm text-muted-foreground">
                                Subscription
                            </p>
                            <p className="mt-1 font-semibold">Growth Plan</p>
                        </div>
                        <span className="rounded-full bg-emerald-500/10 px-3 py-1 text-sm font-medium text-emerald-700 dark:text-emerald-300">
                            active
                        </span>
                    </div>

                    <div className="mt-6 grid gap-3 [transform-style:preserve-3d]">
                        <PreviewMetric
                            icon={CreditCard}
                            label="Payment processor"
                            value="Nomba checkout"
                        />
                        <PreviewMetric
                            icon={Activity}
                            label="Billing cadence"
                            value="NGN 45,000 / month"
                        />
                        <PreviewMetric
                            icon={Webhook}
                            label="Next event"
                            value="subscription.renewed"
                        />
                    </div>

                    <div className="mt-6 rounded-xl border border-dashed p-4">
                        <p className="text-sm font-medium">Renewal timeline</p>
                        <div className="mt-4 grid gap-3">
                            {[
                                'Invoice generated',
                                'Payment attempted',
                                'Webhook delivered',
                            ].map((item) => (
                                <div
                                    key={item}
                                    className="flex items-center gap-3 text-sm"
                                >
                                    <span className="flex size-5 items-center justify-center rounded-full bg-primary text-primary-foreground">
                                        <Check className="size-3" />
                                    </span>
                                    {item}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </aside>
    );
}

function CapabilityCard({
    title,
    description,
    icon: Icon,
}: {
    title: string;
    description: string;
    icon: LucideIcon;
}) {
    return (
        <article className="rounded-2xl border bg-card p-6 shadow-sm">
            <span className="flex size-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <Icon className="size-5" />
            </span>
            <h2 className="mt-5 text-lg font-semibold">{title}</h2>
            <p className="mt-3 text-sm leading-6 text-muted-foreground">
                {description}
            </p>
        </article>
    );
}

function PillarCard({
    eyebrow,
    title,
    description,
    icon: Icon,
}: {
    eyebrow: string;
    title: string;
    description: string;
    icon: LucideIcon;
}) {
    return (
        <article className="rounded-2xl border bg-card p-6 shadow-sm">
            <div className="flex items-center gap-3">
                <span className="flex size-10 items-center justify-center rounded-lg bg-accent text-primary">
                    <Icon className="size-5" />
                </span>
                <p className="text-xs font-medium tracking-[0.22em] text-muted-foreground uppercase">
                    {eyebrow}
                </p>
            </div>
            <h3 className="mt-5 text-xl font-semibold tracking-tight">
                {title}
            </h3>
            <p className="mt-3 text-sm leading-6 text-muted-foreground">
                {description}
            </p>
        </article>
    );
}

function PreviewMetric({
    icon: Icon,
    label,
    value,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
}) {
    return (
        <div className="group/metric relative flex [transform:translate3d(0,0,0)] items-center gap-3 rounded-xl border bg-card p-3 shadow-sm transition-[transform,box-shadow,border-color] duration-500 ease-out [will-change:transform] [backface-visibility:hidden] [transform-style:preserve-3d] hover:[transform:translate3d(0,-0.35rem,1.35rem)_scale(1.015)] hover:border-primary/25 hover:shadow-lg hover:shadow-primary/10">
            <span
                aria-hidden="true"
                className="pointer-events-none absolute inset-x-3 -bottom-1.5 h-2 [transform:translateZ(-0.7rem)] rounded-b-xl border-x border-b bg-muted/80 opacity-40 shadow-sm transition-opacity duration-500 group-hover/metric:opacity-70"
            />
            <span className="relative flex size-9 items-center justify-center rounded-lg bg-accent text-primary transition-transform duration-500 ease-out group-hover/metric:[transform:translateZ(0.45rem)]">
                <Icon className="size-4" />
            </span>
            <div className="relative">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className="text-sm font-medium">{value}</p>
            </div>
        </div>
    );
}

function Footer({ isAuthenticated }: { isAuthenticated: boolean }) {
    return (
        <footer className="border-t bg-card/60">
            <div className="mx-auto grid w-full max-w-7xl gap-8 px-6 py-10 md:grid-cols-[1.2fr_1fr_1fr] lg:px-8">
                <div>
                    <Link
                        href="/"
                        className="inline-flex items-center gap-3 font-semibold tracking-tight"
                    >
                        <span className="flex size-9 items-center justify-center rounded-lg bg-primary p-2 text-primary-foreground shadow-sm">
                            <AppLogoIcon className="size-full fill-current" />
                        </span>
                        <span>Bouclay</span>
                    </Link>
                    <p className="mt-4 max-w-sm text-sm leading-6 text-muted-foreground">
                        A managed subscription, invoicing, and dunning layer for
                        teams building on Nomba.
                    </p>
                </div>

                <div>
                    <h2 className="text-sm font-semibold">Product</h2>
                    <nav className="mt-4 grid gap-3 text-sm text-muted-foreground">
                        <a
                            href="#capabilities"
                            className="transition-colors hover:text-foreground"
                        >
                            Capabilities
                        </a>
                        <a
                            href="#workflow"
                            className="transition-colors hover:text-foreground"
                        >
                            How it works
                        </a>
                        <a
                            href="#platform"
                            className="transition-colors hover:text-foreground"
                        >
                            Platform
                        </a>
                    </nav>
                </div>

                <div>
                    <h2 className="text-sm font-semibold">Build</h2>
                    <nav className="mt-4 grid gap-3 text-sm text-muted-foreground">
                        <Link
                            href="/docs/api"
                            className="transition-colors hover:text-foreground"
                        >
                            API docs
                        </Link>
                        <Link
                            href={isAuthenticated ? dashboard() : login()}
                            className="transition-colors hover:text-foreground"
                        >
                            {isAuthenticated ? 'Dashboard' : 'Log in'}
                        </Link>
                        {!isAuthenticated && (
                            <Link
                                href={register()}
                                className="transition-colors hover:text-foreground"
                            >
                                Create workspace
                            </Link>
                        )}
                    </nav>
                </div>
            </div>

            <div className="mx-auto flex w-full max-w-7xl flex-col gap-3 border-t px-6 py-5 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between lg:px-8">
                <p>© {new Date().getFullYear()} Bouclay.</p>
                <p>Nomba handles payments. Bouclay handles billing logic.</p>
            </div>
        </footer>
    );
}

function workflowDescription(index: number): string {
    return [
        'Store encrypted processor keys per team and keep funds moving through your account.',
        'Model what you sell with recurring, one-off, and trial-aware catalog data.',
        'Let your app create customers and subscriptions through a stable billing API.',
        'Sync entitlements from invoices, payments, renewals, cancellations, and dunning events.',
    ][index];
}
