<?php

/**
 * FORUM-MEMBER-DASHBOARD-001H.0B — trusted coarse region + admin probe gates.
 */

$failures = 0;

function expect(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        fwrite(STDERR, "[PASS] {$message}\n");
        return;
    }

    $failures++;
    fwrite(STDERR, "[FAIL] {$message}\n");
}

$root = dirname(__DIR__);
require $root.'/src/Presence/UsStateAllowlist.php';
require $root.'/src/Presence/TrustedCoarseRegion.php';

use FlatRate\SupabaseOAuth\Presence\TrustedCoarseRegion;
use FlatRate\SupabaseOAuth\Presence\UsStateAllowlist;

expect(count(UsStateAllowlist::CODES) === 51, '50 states + DC');
expect(UsStateAllowlist::contains('TX'), 'TX allowlisted');
expect(UsStateAllowlist::contains('dc'), 'DC case-insensitive');
expect(! UsStateAllowlist::contains('ZZ'), 'ZZ rejected');
expect(! UsStateAllowlist::contains('TXA'), 'non-2-letter rejected');
expect(! UsStateAllowlist::contains(''), 'empty rejected');

function geo(array $headers, array $server = []): array
{
    return TrustedCoarseRegion::fromHeaderMap($headers, $server);
}

$accepted = geo([
    TrustedCoarseRegion::COUNTRY_HEADER => ['US'],
    TrustedCoarseRegion::REGION_HEADER => ['tx'],
]);
expect($accepted['accepted'] === true, 'US+TX accepted');
expect($accepted['country'] === 'US', 'country normalized US');
expect($accepted['region_code'] === 'TX', 'region normalized TX');
expect($accepted['country_header_present'] === true, 'country header present');
expect($accepted['region_header_present'] === true, 'region header present');

$spoof = geo([
    TrustedCoarseRegion::COUNTRY_HEADER => ['ZZ'],
    TrustedCoarseRegion::REGION_HEADER => ['ZZ'],
]);
expect($spoof['accepted'] === false, 'ZZ/ZZ not accepted as geography');
expect($spoof['country'] === 'ZZ', 'probe may observe raw country string');
expect($spoof['region_code'] === null, 'non-allowlisted region_code null');

$ca = geo([
    TrustedCoarseRegion::COUNTRY_HEADER => ['CA'],
    TrustedCoarseRegion::REGION_HEADER => ['ON'],
]);
expect($ca['accepted'] === false, 'non-US country rejected');
expect($ca['region_code'] === null, 'non-US region_code null even if present');

$missing = geo([]);
expect($missing['country_header_present'] === false, 'missing country');
expect($missing['region_header_present'] === false, 'missing region');
expect($missing['accepted'] === false, 'missing headers not accepted');

$cgi = geo([], [
    'HTTP_X_FLATRATE_COUNTRY' => 'US',
    'HTTP_X_FLATRATE_REGION_CODE' => 'OK',
]);
expect($cgi['accepted'] === true, 'CGI-style server params accepted');
expect($cgi['region_code'] === 'OK', 'CGI region OK');

$extend = (string) file_get_contents($root.'/extend.php');
expect(
    str_contains($extend, '/flatrate/community-presence/touch'),
    'extend.php registers community-presence touch route'
);
expect(
    str_contains($extend, 'CommunityPresenceTouchProbeController::class'),
    'extend.php wires probe controller'
);

$probeSrc = (string) file_get_contents($root.'/src/Api/CommunityPresenceTouchProbeController.php');
expect(str_contains($probeSrc, 'isAdmin()'), 'probe requires administrator');
expect(str_contains($probeSrc, 'Cache-Control'), 'probe sets Cache-Control');
expect(str_contains($probeSrc, "'probe' => true"), 'probe flag in response');
expect(! str_contains($probeSrc, 'ActivityEmitter'), 'probe does not emit Activity');
expect(! preg_match('/\binsert\b/i', $probeSrc), 'probe has no insert');
expect(! str_contains($probeSrc, 'HMAC'), 'probe does not hash subjects');
expect(! str_contains($probeSrc, 'error_log'), 'probe does not log');

$trustedSrc = (string) file_get_contents($root.'/src/Presence/TrustedCoarseRegion.php');
expect(str_contains($trustedSrc, 'X-FlatRate-Country'), 'country header name frozen');
expect(str_contains($trustedSrc, 'X-FlatRate-Region-Code'), 'region header name frozen');
expect(! str_contains($trustedSrc, 'CF-Region-Code'), 'does not use reserved CF-Region-Code');
expect(! str_contains($trustedSrc, 'geolocation'), 'no browser geolocation');

fwrite(STDERR, 'TRUSTED_COUNTRY_HEADER='.TrustedCoarseRegion::COUNTRY_HEADER."\n");
fwrite(STDERR, 'TRUSTED_REGION_HEADER='.TrustedCoarseRegion::REGION_HEADER."\n");

if ($failures > 0) {
    fwrite(STDERR, "community-presence-touch-probe.php: {$failures} failure(s)\n");
    exit(1);
}

fwrite(STDERR, "community-presence-touch-probe.php: all checks passed\n");
exit(0);
