<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A durable buyer-facing URL for one exact catalog price.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $product_id
 * @property int|null $price_id
 * @property int|null $trial_offer_id
 * @property bool $active
 * @property array<string, mixed>|null $custom_data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Product $product
 * @property-read Price|null $price
 * @property-read TrialOffer|null $trialOffer
 */
#[Fillable(['team_id', 'product_id', 'price_id', 'trial_offer_id', 'active', 'custom_data'])]
class PaymentLink extends Model
{
    use HasPublicId;

    public function publicIdPrefix(): string
    {
        return 'plink';
    }

    /**
     * Get the team this link belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the product being sold.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the exact price this link checks out.
     *
     * @return BelongsTo<Price, $this>
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }

    /**
     * Get the trial offer this link starts, when this is a trial link.
     *
     * @return BelongsTo<TrialOffer, $this>
     */
    public function trialOffer(): BelongsTo
    {
        return $this->belongsTo(TrialOffer::class);
    }

    public function url(): string
    {
        return route('hosted.payment-links.show', $this->public_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function toHostedArray(): array
    {
        $this->loadMissing(['team', 'product', 'price', 'trialOffer.trialPrice', 'trialOffer.transitionPrice']);

        $trialOffer = $this->trialOffer;
        $price = $trialOffer instanceof TrialOffer ? $trialOffer->trialPrice : $this->price;

        return [
            'publicId' => $this->public_id,
            'kind' => $trialOffer instanceof TrialOffer ? 'trial_offer' : 'price',
            'business' => [
                'name' => $this->team->name,
                'line1' => $this->team->line1,
                'line2' => $this->team->line2,
                'city' => $this->team->city,
                'postalCode' => $this->team->postal_code,
                'country' => $this->team->country,
            ],
            'product' => [
                'name' => $this->product->name,
                'description' => $this->product->description,
            ],
            'price' => [
                'name' => $price?->name,
                'type' => $price?->type->value,
                'currency' => $price?->currency,
                'unitAmount' => $price?->unit_amount,
                'billingInterval' => $price?->billing_interval?->value,
                'billingFrequency' => $price?->billing_frequency,
                'label' => $price?->toPickerLabel(),
            ],
            'trialOffer' => $trialOffer instanceof TrialOffer ? [
                'name' => $trialOffer->name,
                'durationIterations' => $trialOffer->duration_iterations,
                'transitionPrice' => [
                    'name' => $trialOffer->transitionPrice->name,
                    'type' => $trialOffer->transitionPrice->type->value,
                    'currency' => $trialOffer->transitionPrice->currency,
                    'unitAmount' => $trialOffer->transitionPrice->unit_amount,
                    'billingInterval' => $trialOffer->transitionPrice->billing_interval?->value,
                    'billingFrequency' => $trialOffer->transitionPrice->billing_frequency,
                    'label' => $trialOffer->transitionPrice->toPickerLabel(),
                ],
            ] : null,
            'returnUrl' => $this->product->website_url,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'custom_data' => 'array',
        ];
    }
}
