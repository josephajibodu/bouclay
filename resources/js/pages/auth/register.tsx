import { Form, Head } from '@inertiajs/react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { countries } from '@/lib/countries';
import { cn } from '@/lib/utils';
import { login } from '@/routes';
import { store } from '@/routes/register';
import type { BusinessType, BusinessTypeOption } from '@/types';

type Props = {
    passwordRules: string;
    businessTypes: BusinessTypeOption[];
};

const MILESTONES = [
    { number: 1, title: 'Account' },
    { number: 2, title: 'Business' },
] as const;

const STEP_FIELDS: Record<number, string[]> = {
    1: ['first_name', 'last_name', 'email', 'password'],
    2: [
        'business_name',
        'business_type',
        'website',
        'country',
        'line1',
        'line2',
        'city',
        'postal_code',
    ],
};

export default function Register({ passwordRules, businessTypes }: Props) {
    const [step, setStep] = useState(1);
    const [businessType, setBusinessType] =
        useState<BusinessType>('individual');
    const [country, setCountry] = useState(countries[0].code);
    const [addressExpanded, setAddressExpanded] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    function goToStep(next: number) {
        const form = containerRef.current?.querySelector('form');

        if (next > step && form && !form.reportValidity()) {
            return;
        }

        setStep(next);
    }

    return (
        <>
            <Head title="Set up your business" />
            <div ref={containerRef} className="flex flex-col gap-6">
                <Form
                    {...store.form()}
                    resetOnSuccess={['password']}
                    disableWhileProcessing
                    className="flex flex-col gap-6"
                    onError={(errors) => {
                        const erroredStep = Object.entries(STEP_FIELDS).find(
                            ([, fields]) =>
                                fields.some((field) => field in errors),
                        );

                        if (erroredStep) {
                            setStep(Number(erroredStep[0]));
                        }
                    }}
                >
                    {({ processing, errors }) => {
                        const showAddressDetails =
                            addressExpanded ||
                            Boolean(
                                errors.line1 ||
                                errors.line2 ||
                                errors.city ||
                                errors.postal_code,
                            );

                        return (
                            <>
                                <div className="flex items-center justify-center gap-2 text-sm text-muted-foreground">
                                    {MILESTONES.map(({ number, title }) => (
                                        <span
                                            key={number}
                                            className={
                                                number === step
                                                    ? 'font-medium text-foreground'
                                                    : undefined
                                            }
                                        >
                                            {title}
                                            {number !== MILESTONES.length && (
                                                <span className="mx-2">
                                                    &rarr;
                                                </span>
                                            )}
                                        </span>
                                    ))}
                                </div>

                                <div className="grid gap-6" hidden={step !== 1}>
                                    <div className="space-y-1 text-center">
                                        <h2 className="text-lg font-medium">
                                            Create your account
                                        </h2>
                                        <p className="text-sm text-muted-foreground">
                                            You'll set up your business next —
                                            it only takes a minute.
                                        </p>
                                    </div>

                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="first_name">
                                                First name
                                            </Label>
                                            <Input
                                                id="first_name"
                                                type="text"
                                                required={step === 1}
                                                autoFocus
                                                tabIndex={1}
                                                autoComplete="given-name"
                                                name="first_name"
                                                placeholder="First name"
                                            />
                                            <InputError
                                                message={errors.first_name}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="last_name">
                                                Last name
                                            </Label>
                                            <Input
                                                id="last_name"
                                                type="text"
                                                required={step === 1}
                                                tabIndex={2}
                                                autoComplete="family-name"
                                                name="last_name"
                                                placeholder="Last name"
                                            />
                                            <InputError
                                                message={errors.last_name}
                                            />
                                        </div>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="email">
                                            Email address
                                        </Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            required={step === 1}
                                            tabIndex={3}
                                            autoComplete="email"
                                            name="email"
                                            placeholder="email@example.com"
                                        />
                                        <InputError message={errors.email} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="password">
                                            Password
                                        </Label>
                                        <PasswordInput
                                            id="password"
                                            required={step === 1}
                                            tabIndex={4}
                                            autoComplete="new-password"
                                            name="password"
                                            placeholder="Password"
                                            passwordrules={passwordRules}
                                        />
                                        <InputError message={errors.password} />
                                    </div>

                                    <Button
                                        type="button"
                                        className="mt-2 w-full"
                                        tabIndex={5}
                                        data-test="register-next-button"
                                        onClick={() => goToStep(2)}
                                    >
                                        Continue
                                    </Button>
                                </div>

                                <div className="grid gap-6" hidden={step !== 2}>
                                    <div className="space-y-1 text-center">
                                        <h2 className="text-lg font-medium">
                                            Tell us about your business
                                        </h2>
                                        <p className="text-sm text-muted-foreground">
                                            This becomes your workspace — invite
                                            your team once you're in. We use
                                            your address for invoices and tax
                                            settings.
                                        </p>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="business_name">
                                            Business name
                                        </Label>
                                        <Input
                                            id="business_name"
                                            type="text"
                                            required={step === 2}
                                            tabIndex={1}
                                            name="business_name"
                                            placeholder="Acme Inc"
                                        />
                                        <InputError
                                            message={errors.business_name}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="business_type">
                                            Business type
                                        </Label>
                                        <Select
                                            name="business_type"
                                            value={businessType}
                                            onValueChange={(value) =>
                                                setBusinessType(
                                                    value as BusinessType,
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id="business_type"
                                                className="w-full"
                                            >
                                                <SelectValue placeholder="Select a business type" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {businessTypes.map((type) => (
                                                    <SelectItem
                                                        key={type.value}
                                                        value={type.value}
                                                    >
                                                        {type.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={errors.business_type}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="website">
                                            Website (optional)
                                        </Label>
                                        <Input
                                            id="website"
                                            type="url"
                                            tabIndex={2}
                                            name="website"
                                            placeholder="https://example.com"
                                        />
                                        <InputError message={errors.website} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="country">Country</Label>
                                        <Select
                                            name="country"
                                            value={country}
                                            onValueChange={setCountry}
                                        >
                                            <SelectTrigger
                                                id="country"
                                                className="w-full"
                                            >
                                                <SelectValue placeholder="Select a country" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {countries.map((c) => (
                                                    <SelectItem
                                                        key={c.code}
                                                        value={c.code}
                                                    >
                                                        {c.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.country} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="line1">Address</Label>
                                        <Input
                                            id="line1"
                                            type="text"
                                            required={step === 2}
                                            tabIndex={3}
                                            autoComplete="address-line1"
                                            name="line1"
                                            placeholder="Start typing your address"
                                            onChange={(e) => {
                                                if (e.target.value.length > 0) {
                                                    setAddressExpanded(true);
                                                }
                                            }}
                                        />
                                        <InputError message={errors.line1} />
                                    </div>

                                    <div
                                        className={cn(
                                            'grid transition-[grid-template-rows] duration-300 ease-out',
                                            showAddressDetails
                                                ? 'grid-rows-[1fr]'
                                                : 'grid-rows-[0fr]',
                                        )}
                                    >
                                        <div className="overflow-hidden">
                                            <div
                                                className={cn(
                                                    'flex flex-col gap-6 pt-6 transition-opacity duration-300',
                                                    showAddressDetails
                                                        ? 'opacity-100'
                                                        : 'opacity-0',
                                                )}
                                            >
                                                <div className="grid gap-2">
                                                    <Label htmlFor="line2">
                                                        Street address line 2
                                                        (optional)
                                                    </Label>
                                                    <Input
                                                        id="line2"
                                                        type="text"
                                                        tabIndex={4}
                                                        autoComplete="address-line2"
                                                        name="line2"
                                                        placeholder="Apartment, suite, etc."
                                                    />
                                                    <InputError
                                                        message={errors.line2}
                                                    />
                                                </div>

                                                <div className="grid grid-cols-2 gap-4">
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="city">
                                                            City / Town
                                                        </Label>
                                                        <Input
                                                            id="city"
                                                            type="text"
                                                            required={
                                                                step === 2
                                                            }
                                                            tabIndex={5}
                                                            autoComplete="address-level2"
                                                            name="city"
                                                            placeholder="City"
                                                        />
                                                        <InputError
                                                            message={
                                                                errors.city
                                                            }
                                                        />
                                                    </div>

                                                    <div className="grid gap-2">
                                                        <Label htmlFor="postal_code">
                                                            Postal / Zip code
                                                            (optional)
                                                        </Label>
                                                        <Input
                                                            id="postal_code"
                                                            type="text"
                                                            tabIndex={6}
                                                            autoComplete="postal-code"
                                                            name="postal_code"
                                                            placeholder="Postal code"
                                                        />
                                                        <InputError
                                                            message={
                                                                errors.postal_code
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="flex gap-2">
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            className="w-full"
                                            onClick={() => setStep(1)}
                                        >
                                            Back
                                        </Button>
                                        <Button
                                            type="submit"
                                            className="w-full"
                                            data-test="register-user-button"
                                        >
                                            {processing && <Spinner />}
                                            Finish setup
                                        </Button>
                                    </div>
                                </div>
                            </>
                        );
                    }}
                </Form>

                <div className="text-center text-sm text-muted-foreground">
                    Already have an account?{' '}
                    <TextLink href={login()} data-test="login-link">
                        Log in
                    </TextLink>
                </div>
            </div>
        </>
    );
}

Register.layout = {
    title: 'Set up your business',
    description: 'Create your account, then tell us about your business.',
};
