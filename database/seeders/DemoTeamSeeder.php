<?php

namespace Database\Seeders;

use App\Actions\Teams\CreateTeam;
use App\Enums\BillingInterval;
use App\Enums\BusinessType;
use App\Enums\DiscountDuration;
use App\Enums\DiscountType;
use App\Enums\PlanStatus;
use App\Enums\PriceType;
use App\Enums\TrialUnit;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The NaijaStream fixture from BILLING_SIMULATIONS.md — the shared catalog
 * every SIM-01…04 / ADV-01…10 trace runs against. The simulation test suite
 * reuses {@see seedCatalog()} through a Pest helper so the demo environment
 * and the acceptance tests can never drift apart.
 *
 * All amounts are minor units (kobo): ₦5,000 = 500000.
 */
class DemoTeamSeeder extends Seeder
{
    /**
     * Seed a demo login, the NaijaStream team, and its catalog.
     */
    public function run(): void
    {
        $owner = User::query()->firstWhere('email', 'demo@naijastream.test')
            ?? User::factory()->create([
                'first_name' => 'Demo',
                'last_name' => 'Owner',
                'email' => 'demo@naijastream.test',
            ]);

        $team = Team::query()->firstWhere('name', 'NaijaStream')
            ?? app(CreateTeam::class)->handle($owner, 'NaijaStream', attributes: [
                'business_type' => BusinessType::Private,
                'country' => 'NG',
                'line1' => '1 Marina Road',
                'city' => 'Lagos',
                'default_currency' => 'NGN',
            ]);

        $this->seedCatalog($team);
    }

    /**
     * Build the NaijaStream catalog on an existing team, exactly as the
     * simulations spec it. Idempotent per team. Returns every fixture object
     * keyed by the doc's refs (`price_prem_m`, …).
     *
     * @return array{
     *     naijastream: Product, sportsPack: Product,
     *     premium: Plan, sportsPackPlan: Plan, teamPlan: Plan,
     *     price_prem_m: Price, price_sports_m: Price, price_seat_m: Price,
     *     hdStreaming: Entitlement, sportsChannels: Entitlement,
     *     welcome20: Discount, amina: Customer,
     * }
     */
    public function seedCatalog(Team $team): array
    {
        // Products: the streaming service and the add-on product.
        $naijastream = $team->products()->firstOrCreate(
            ['name' => 'NaijaStream'],
            ['description' => 'Streaming service', 'category' => 'streaming'],
        );

        $sportsPack = $team->products()->firstOrCreate(
            ['name' => 'Sports Pack'],
            ['description' => 'Live sports add-on', 'category' => 'streaming'],
        );

        // Plans: the tiers customers actually pick.
        $premium = $team->plans()->firstOrCreate(
            ['product_id' => $naijastream->id, 'name' => 'Premium'],
            ['code' => 'premium', 'status' => PlanStatus::Active],
        );

        $sportsPackPlan = $team->plans()->firstOrCreate(
            ['product_id' => $sportsPack->id, 'name' => 'Sports Pack'],
            ['code' => 'sports-pack', 'status' => PlanStatus::Active],
        );

        // Per-seat plan used by SIM-02/03 (mid-cycle quantity changes).
        $teamPlan = $team->plans()->firstOrCreate(
            ['product_id' => $naijastream->id, 'name' => 'Team'],
            ['code' => 'team', 'status' => PlanStatus::Active],
        );

        // Prices — ₦5,000/mo Premium with a 7-day card-required trial,
        // ₦1,500/mo Sports Pack add-on, ₦1,000/seat/mo Team.
        $premiumMonthly = $team->prices()->firstOrCreate(
            ['plan_id' => $premium->id, 'name' => 'Premium Monthly'],
            [
                'product_id' => $naijastream->id,
                'type' => PriceType::Recurring,
                'unit_amount' => 500000,
                'currency' => 'NGN',
                'billing_interval' => BillingInterval::Month,
                'trial_length' => 7,
                'trial_unit' => TrialUnit::Day,
                'trial_requires_payment_info' => true,
                'trial_once_per_customer' => true,
                'purchasable' => true,
            ],
        );

        $sportsMonthly = $team->prices()->firstOrCreate(
            ['plan_id' => $sportsPackPlan->id, 'name' => 'Sports Pack Monthly'],
            [
                'product_id' => $sportsPack->id,
                'type' => PriceType::Recurring,
                'unit_amount' => 150000,
                'currency' => 'NGN',
                'billing_interval' => BillingInterval::Month,
                'purchasable' => true,
            ],
        );

        $seatMonthly = $team->prices()->firstOrCreate(
            ['plan_id' => $teamPlan->id, 'name' => 'Team Seat Monthly'],
            [
                'product_id' => $naijastream->id,
                'type' => PriceType::Recurring,
                'unit_amount' => 100000,
                'currency' => 'NGN',
                'billing_interval' => BillingInterval::Month,
                'purchasable' => true,
            ],
        );

        // Entitlements: hd_streaming ← plan:Premium,
        // sports_channels ← product:Sports Pack.
        $hdStreaming = $team->entitlements()->firstOrCreate(
            ['code' => 'hd_streaming'],
            ['name' => 'HD Streaming'],
        );

        $hdStreaming->grants()->firstOrCreate([
            'grantor_type' => $premium->getMorphClass(),
            'grantor_id' => $premium->id,
        ], ['team_id' => $team->id]);

        $sportsChannels = $team->entitlements()->firstOrCreate(
            ['code' => 'sports_channels'],
            ['name' => 'Sports Channels'],
        );

        $sportsChannels->grants()->firstOrCreate([
            'grantor_type' => $sportsPack->getMorphClass(),
            'grantor_id' => $sportsPack->id,
        ], ['team_id' => $team->id]);

        // WELCOME20 — 20% off Premium for the first 3 billing intervals.
        $welcome20 = $team->discounts()->firstOrCreate(
            ['code' => 'WELCOME20'],
            [
                'type' => DiscountType::Percentage,
                'percentage' => '20.00',
                'duration' => DiscountDuration::Repeating,
                'duration_in_intervals' => 3,
                'eligible_plan_ids' => [$premium->id],
                'active' => true,
            ],
        );

        $amina = $team->customers()->firstOrCreate(
            ['email' => 'amina@example.test'],
            ['name' => 'Amina', 'currency' => 'NGN'],
        );

        return [
            'naijastream' => $naijastream,
            'sportsPack' => $sportsPack,
            'premium' => $premium,
            'sportsPackPlan' => $sportsPackPlan,
            'teamPlan' => $teamPlan,
            'price_prem_m' => $premiumMonthly,
            'price_sports_m' => $sportsMonthly,
            'price_seat_m' => $seatMonthly,
            'hdStreaming' => $hdStreaming,
            'sportsChannels' => $sportsChannels,
            'welcome20' => $welcome20,
            'amina' => $amina,
        ];
    }
}
