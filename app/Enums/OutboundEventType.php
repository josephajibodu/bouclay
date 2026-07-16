<?php

namespace App\Enums;

/**
 * The billing events Bouclay emits to integrator webhook endpoints
 * (schema.md §9, IMPLEMENTATION_V2 §V2-6).
 *
 * One `*.created` when an object is instantiated; one `*.updated` reused for
 * every subsequent status change, renewal, or modification — never a new
 * event name per transition. Consumers read the object's `status` off the
 * payload rather than inferring it from the event name, which is why there is
 * no `invoice.paid`: whether an invoice was paid, went uncollectible, or was
 * voided is a property of the invoice, not a different kind of happening.
 *
 * "Transaction is not a Bouclay entity" (schema.md, Dashboard vocabulary)
 * applies to event names too, not just dashboard labels.
 *
 * schema.md §9 also lists `payment.created`/`payment.updated`. They are not
 * here yet on purpose: a payment already rides on `invoice.updated` as
 * `data.object.payment`, so emitting `payment.*` would announce the same
 * occurrence twice. The day a payment can change without its invoice changing
 * — an async refund settling, say — it earns its own pair.
 */
enum OutboundEventType: string
{
    case CustomerCreated = 'customer.created';
    case CustomerUpdated = 'customer.updated';

    case PaymentMethodCreated = 'payment_method.created';
    case PaymentMethodUpdated = 'payment_method.updated';

    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';

    case PlanCreated = 'plan.created';
    case PlanUpdated = 'plan.updated';

    case SubscriptionCreated = 'subscription.created';
    case SubscriptionUpdated = 'subscription.updated';

    case InvoiceCreated = 'invoice.created';
    case InvoiceUpdated = 'invoice.updated';

    /**
     * The object this event is about — `customer`, `invoice`, and so on.
     */
    public function object(): string
    {
        return str($this->value)->before('.')->value();
    }

    /**
     * Whether this announces a new object rather than a change to one the
     * integrator has already seen.
     */
    public function isCreated(): bool
    {
        return str($this->value)->endsWith('.created');
    }

    /**
     * Human-readable label for the deliveries log.
     */
    public function label(): string
    {
        return str($this->value)->replace(['.', '_'], ' ')->ucfirst()->value();
    }
}
