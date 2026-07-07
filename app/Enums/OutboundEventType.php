<?php

namespace App\Enums;

/**
 * Normalised billing events Bouclay emits to integrator webhook endpoints.
 */
enum OutboundEventType: string
{
    case CustomerCreated = 'customer.created';
    case PaymentMethodAdded = 'payment_method.added';
    case SubscriptionCreated = 'subscription.created';
    case SubscriptionUpdated = 'subscription.updated';
    case InvoicePaid = 'invoice.paid';
    case InvoicePaymentFailed = 'invoice.payment_failed';
}
