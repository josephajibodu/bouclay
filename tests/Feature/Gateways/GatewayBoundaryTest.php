<?php

use Symfony\Component\Finder\Finder;

/**
 * The V2-4 exit criterion, enforced: no gateway-specific class is referenced
 * outside `app/Services/Gateways/`.
 *
 * This is what makes "adding a gateway is one driver class + a registry entry"
 * true rather than aspirational — the moment a processor's name leaks into an
 * action, a controller, or a model, the abstraction has a hole in it and this
 * test says where.
 */
/**
 * Every PHP file under app/, as [relative path => contents].
 *
 * @return array<string, string>
 */
function appSourceFiles(): array
{
    $files = [];

    foreach (Finder::create()->files()->name('*.php')->in(app_path()) as $file) {
        $files[str_replace(base_path().'/', '', $file->getRealPath())] = $file->getContents();
    }

    return $files;
}

test('no gateway-specific class is referenced outside the driver boundary', function () {
    // Class-shaped references only: `NombaCheckout`, `App\Services\Nomba\…`,
    // `PaystackGateway`. A bare enum case (`PaymentProcessor::Nomba`) is the
    // driver registry key, not a class, and prose in a comment is not a
    // dependency — neither is matched.
    $gatewayClass = '/\b(?:Nomba|Paystack|Flutterwave)[A-Z]\w*/';

    $offenders = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (str_starts_with($path, 'app/Services/Gateways/')) {
            continue;
        }

        if (preg_match_all($gatewayClass, $contents, $matches)) {
            $offenders[$path] = array_values(array_unique($matches[0]));
        }
    }

    expect($offenders)->toBe([], 'Gateway-specific classes must live behind app/Services/Gateways/. Found: '
        .json_encode($offenders, JSON_PRETTY_PRINT));
});

test('no gateway-specific namespace is imported outside the driver boundary', function () {
    $offenders = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (str_starts_with($path, 'app/Services/Gateways/')) {
            continue;
        }

        if (preg_match_all('/^use\s+App\\\\[\w\\\\]*\\\\(?:Nomba|Paystack|Flutterwave)\\\\[\w\\\\]+;/m', $contents, $matches)) {
            $offenders[$path] = $matches[0];
        }
    }

    expect($offenders)->toBe([], 'Gateway namespaces must not be imported outside app/Services/Gateways/. Found: '
        .json_encode($offenders, JSON_PRETTY_PRINT));
});

test('no gateway credential shape is known outside the driver boundary', function () {
    // Class names were never the whole boundary: a shared model with a
    // `credentialsFor()` returning Nomba's `{accountId, clientId, …}` leaks
    // just as badly while passing every grep above. The credential blob is
    // opaque outside app/Services/Gateways/ — only a driver knows its keys.
    $credentialKeys = [
        // Nomba
        'account_id', 'subaccount_id', 'client_id', 'client_secret', 'webhook_secret',
        // Paystack / Flutterwave (V2-4b) — declared here before they ship, so
        // the boundary is enforced from the first line of those drivers.
        'secret_key', 'public_key', 'encryption_key', 'webhook_secret_hash',
    ];

    $pattern = '/[\'"]('.implode('|', $credentialKeys).')[\'"]/';

    $offenders = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (str_starts_with($path, 'app/Services/Gateways/')) {
            continue;
        }

        if (preg_match_all($pattern, $contents, $matches)) {
            $offenders[$path] = array_values(array_unique($matches[1]));
        }
    }

    expect($offenders)->toBe([], 'Gateway credential keys are the driver\'s business. Read them through the '
        .'driver\'s configSchema() manifest instead. Found: '.json_encode($offenders, JSON_PRETTY_PRINT));
});

/**
 * Every page a customer (not a merchant) can see, as [relative path => contents].
 *
 * @return array<string, string>
 */
function customerFacingFrontendFiles(): array
{
    $roots = array_filter([
        resource_path('js/pages/hosted'),
        resource_path('js/pages/portal'),
        resource_path('js/components/portal'),
        resource_path('js/layouts/portal'),
    ], 'is_dir');

    $files = [];

    foreach (Finder::create()->files()->name(['*.tsx', '*.ts'])->in($roots) as $file) {
        $files[str_replace(base_path().'/', '', $file->getRealPath())] = $file->getContents();
    }

    return $files;
}

test('no gateway is named in copy shown to customers', function () {
    // The greps above only scan app/, which is why "Secure Nomba checkout"
    // sat on the portal for a whole phase after the backend went
    // gateway-agnostic: a Paystack merchant's customers were told their card
    // goes to Nomba. The gateway's name is data — it arrives as the
    // `paymentGateway` prop (TeamProcessorConnection::processorLabel()), so a
    // literal here is always wrong for somebody.
    $files = customerFacingFrontendFiles();

    expect($files)->not->toBeEmpty('Customer-facing page roots not found — this guard would pass vacuously.');

    $offenders = [];

    foreach ($files as $path => $contents) {
        if (preg_match_all('/\b(?:Nomba|Paystack|Flutterwave)\b/i', $contents, $matches)) {
            $offenders[$path] = array_values(array_unique($matches[0]));
        }
    }

    expect($offenders)->toBe([], 'Customer-facing copy must not name a gateway — read the `paymentGateway` prop '
        .'instead. Found: '.json_encode($offenders, JSON_PRETTY_PRINT));
});

test('every money path resolves its driver through the manager', function () {
    // The driver boundary only holds if callers go through GatewayManager;
    // a `new NombaGateway` anywhere would satisfy the greps above while
    // hard-wiring a processor.
    foreach (appSourceFiles() as $path => $contents) {
        if (str_starts_with($path, 'app/Services/Gateways/')) {
            continue;
        }

        expect($contents)->not->toMatch('/new\s+\w*Gateway\s*\(/', "{$path} constructs a gateway directly");
    }
});
