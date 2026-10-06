<?php

/**
 * Strict beta_tester_active parsing. No database and no Flarum runtime.
 */

declare(strict_types=1);

require dirname(__DIR__).'/src/Sso/SsoException.php';
require dirname(__DIR__).'/src/Sso/BetaTesterPayload.php';

use FlatRate\SupabaseOAuth\Sso\BetaTesterPayload;
use FlatRate\SupabaseOAuth\Sso\SsoException;

$failures = 0;

function pass(string $label): void
{
    echo "[PASS] {$label}\n";
}

function fail(string $label, string $detail = ''): void
{
    global $failures;
    $failures++;
    echo "[FAIL] {$label}".($detail !== '' ? " — {$detail}" : '')."\n";
}

function assertSame($expected, $actual, string $label): void
{
    if ($expected === $actual) {
        pass($label);

        return;
    }
    fail($label, 'expected '.var_export($expected, true).' got '.var_export($actual, true));
}

function rejected(mixed $value, string $label): void
{
    try {
        BetaTesterPayload::optional(['beta_tester_active' => $value]);
        fail($label, 'accepted invalid value');
    } catch (SsoException $error) {
        if ($error->errorCode === 'invalid_beta_tester_active' && $error->statusCode === 400) {
            pass($label);

            return;
        }
        fail($label, $error->errorCode.' '.$error->statusCode);
    }
}

assertSame(true, BetaTesterPayload::optional(['beta_tester_active' => true]), 'true accepted');
assertSame(false, BetaTesterPayload::optional(['beta_tester_active' => false]), 'false accepted');
assertSame(null, BetaTesterPayload::optional(['sub' => 'abc']), 'missing field is no-mutation signal');
assertSame(true, BetaTesterPayload::required(['beta_tester_active' => true]), 'required true');
assertSame(false, BetaTesterPayload::required(['beta_tester_active' => false]), 'required false');

try {
    BetaTesterPayload::required(['sub' => 'abc']);
    fail('required missing rejected');
} catch (SsoException $error) {
    $error->errorCode === 'invalid_beta_tester_active'
        ? pass('required missing rejected')
        : fail('required missing rejected', $error->errorCode);
}

rejected('true', 'string true rejected');
rejected('false', 'string false rejected');
rejected(1, 'integer 1 rejected');
rejected(0, 'integer 0 rejected');
rejected(null, 'null rejected');
rejected([], 'array rejected');
rejected(['nested' => true], 'object rejected');

$source = file_get_contents(dirname(__DIR__).'/src/Sso/BetaTesterPayload.php');
if (is_string($source) && ! str_contains($source, 'FILTER_VALIDATE_BOOLEAN')) {
    pass('parser does not use FILTER_VALIDATE_BOOLEAN');
} else {
    fail('parser does not use FILTER_VALIDATE_BOOLEAN');
}

if ($failures > 0) {
    fwrite(STDERR, "BETA_TESTER_PAYLOAD_FAILURES={$failures}\n");
    exit(1);
}

echo "BETA_TESTER_PAYLOAD=PASS\n";
exit(0);
