<?php

namespace App\Exceptions;

use App\Actions\Catalog\ReplacePrice;
use App\Models\Price;
use LogicException;

/**
 * Thrown by the Price::saving guard when code tries to mutate a frozen
 * column on a price that a subscription item or invoice line references
 * (schema.md §3). The only legal edit path for a referenced price is
 * {@see ReplacePrice} — successor row, archive the
 * original.
 */
class ImmutablePriceViolation extends LogicException
{
    /**
     * @param  list<string>  $columns
     */
    public static function forColumns(Price $price, array $columns): self
    {
        $list = implode(', ', $columns);

        return new self(
            "Price {$price->public_id} is referenced by subscriptions or invoices; ".
            "[{$list}] can no longer be mutated in place. Use ReplacePrice to issue a successor row.",
        );
    }
}
