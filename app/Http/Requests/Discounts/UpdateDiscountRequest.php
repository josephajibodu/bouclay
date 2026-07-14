<?php

namespace App\Http\Requests\Discounts;

use App\Models\Discount;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class UpdateDiscountRequest extends StoreDiscountRequest
{
    /**
     * Same shape as create, but the code-uniqueness check ignores the discount
     * being edited.
     */
    protected function codeUniqueRule(): Unique
    {
        /** @var Discount $discount */
        $discount = $this->route('discount');

        return Rule::unique('discounts', 'code')
            ->where('team_id', $this->user()?->currentTeam?->id)
            ->ignore($discount->id);
    }
}
