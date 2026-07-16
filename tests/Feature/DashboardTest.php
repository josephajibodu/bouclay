<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamProcessorConnection;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('users without a current team are redirected to choose one', function () {
    $user = User::factory()->create();
    $user->update(['current_team_id' => null]);

    $response = $this
        ->actingAs($user->fresh())
        ->get(route('dashboard'));

    $response->assertRedirect(route('teams.choose'));
});

test('the dashboard url no longer takes a team slug', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get('/'.$team->slug.'/dashboard');

    $response->assertNotFound();
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('dashboard includes a billing summary for the current team', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['default_currency' => 'NGN']);

    $customer = Customer::factory()
        ->for($team)
        ->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
    $product = Product::factory()->for($team)->create(['name' => 'Pro']);
    Product::factory()->for($team)->create();
    Price::factory()->for($team)->for($product)->create(['currency' => 'NGN']);

    Subscription::factory()->for($team)->for($customer)->create(['status' => SubscriptionStatus::Active]);
    Subscription::factory()->for($team)->for($customer)->trialing()->create();
    Subscription::factory()->for($team)->for($customer)->create(['status' => SubscriptionStatus::PastDue]);
    Subscription::factory()->for($team)->for($customer)->canceled()->create();

    $openInvoice = Invoice::factory()
        ->for($team)
        ->for($customer)
        ->create([
            'status' => InvoiceStatus::Open,
            'currency' => 'NGN',
            'total' => 4000_00,
            'amount_due' => 4000_00,
        ]);
    $paidInvoice = Invoice::factory()
        ->for($team)
        ->for($customer)
        ->create([
            'status' => InvoiceStatus::Paid,
            'currency' => 'NGN',
            'total' => 1500_00,
            'amount_paid' => 1500_00,
            'amount_due' => 0,
            'paid_at' => now(),
        ]);

    Payment::factory()
        ->for($team)
        ->for($customer)
        ->for($paidInvoice, 'invoice')
        ->create([
            'amount' => 1500_00,
            'currency' => 'NGN',
            'status' => PaymentStatus::Succeeded,
            'processed_at' => now(),
        ]);
    Payment::factory()
        ->for($team)
        ->for($customer)
        ->for($openInvoice, 'invoice')
        ->failed()
        ->create([
            'amount' => 4000_00,
            'currency' => 'NGN',
            'processed_at' => now(),
        ]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('summary.currency', 'NGN')
        ->where('summary.revenueLast30', 1500_00)
        ->where('summary.successfulPaymentsLast30', 1)
        ->where('summary.activeSubscriptions', 3)
        ->where('summary.trialingSubscriptions', 1)
        ->where('summary.pastDueSubscriptions', 1)
        ->where('summary.customers', 1)
        ->where('summary.activeProducts', 2)
        ->where('summary.activePrices', 1)
        ->where('summary.openInvoices', 1)
        ->where('summary.openInvoiceAmountDue', 4000_00)
        ->has('summary.recentPayments', 2)
        ->has('summary.recentInvoices', 2)
        ->where('summary.recentInvoices.0.customer.email', 'ada@example.com'),
    );
});

test('dashboard includes pending invitations for the authenticated user', function () {
    $owner = User::factory()->create(['first_name' => 'Taylor', 'last_name' => 'Otwell']);
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create(['name' => 'Laravel Team']);

    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 1)
        ->where('pendingInvitations.0.code', $invitation->code)
        ->where('pendingInvitations.0.inviterName', 'Taylor Otwell')
        ->where('pendingInvitations.0.team.name', 'Laravel Team')
        ->where('pendingInvitations.0.team.slug', $team->slug)
        ->missing('pendingInvitations.0.teamName'),
    );
});

test('dashboard does not include accepted invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);

    TeamInvitation::factory()->accepted()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );
});

test('dashboard excludes expired invitations without deleting them', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard does not include or delete other users invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    attachTeamOwner($team, $owner);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('a fresh team starts with an incomplete onboarding checklist', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('onboarding.businessConfirmed', false)
        ->where('onboarding.gatewayConnected', false)
        ->where('onboarding.apiKeyGenerated', false)
        ->where('onboarding.webhookVerified', false),
    );
});

test('the onboarding checklist reflects completed setup steps', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $team->update(['line1' => '1 Main St', 'city' => 'Lagos', 'country' => 'NG']);
    TeamProcessorConnection::factory()
        ->testConnected()
        ->create(['team_id' => $team->id, 'webhook_verified_at' => now()]);
    ApiKey::factory()->create(['team_id' => $team->id, 'created_by' => $user->id]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('onboarding.businessConfirmed', true)
        ->where('onboarding.gatewayConnected', true)
        ->where('onboarding.apiKeyGenerated', true)
        ->where('onboarding.webhookVerified', true)
        ->where('onboarding.links.gateways', route('developers.gateways.index'))
        ->where('onboarding.links.apiKeys', route('developers.api-keys.index'))
        ->where('onboarding.links.webhooks', route('developers.webhooks.show')),
    );
});

test('a revoked api key does not count toward the onboarding checklist', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    ApiKey::factory()->revoked()->create(['team_id' => $team->id, 'created_by' => $user->id]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('onboarding.apiKeyGenerated', false),
    );
});
