export type CustomerStatus = 'active' | 'archived';
export type AddressType = 'billing' | 'shipping';
export type PaymentMethodType = 'card' | 'bank' | 'wallet';
export type PaymentMethodStatus = 'active' | 'expired' | 'revoked';

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type CustomerListItem = {
    id: number;
    publicId: string;
    name: string | null;
    email: string;
    status: CustomerStatus;
    createdAt: string | null;
};

export type CustomerFilters = {
    search: string;
    status: CustomerStatus;
};

export type CustomerAddress = {
    id: number;
    type: AddressType;
    name: string | null;
    line1: string;
    line2: string | null;
    city: string | null;
    region: string | null;
    postalCode: string | null;
    country: string;
    phone: string | null;
    isDefault: boolean;
    singleLine: string;
};

export type CustomerPaymentMethod = {
    id: number;
    publicId: string;
    processor: string;
    type: PaymentMethodType;
    brand: string | null;
    last4: string | null;
    expMonth: number | null;
    expYear: number | null;
    issuer: string | null;
    isDefault: boolean;
    isExpired: boolean;
    status: PaymentMethodStatus;
    billingAddressId: number | null;
    createdAt: string | null;
};

export type CustomerActivityEvent = {
    type: string;
    label: string;
    at: string | null;
};

export type CustomerDetail = {
    id: number;
    publicId: string;
    name: string | null;
    email: string;
    phone: string | null;
    currency: string | null;
    externalRef: string | null;
    status: CustomerStatus;
    defaultPaymentMethodId: number | null;
    customData: Record<string, string> | null;
    createdAt: string | null;
    archivedAt: string | null;
};
