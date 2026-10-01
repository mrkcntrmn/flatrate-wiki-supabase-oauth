#!/usr/bin/env php
<?php declare(strict_types=1);

const EXPECTED_PACKAGE = 'league/flysystem';
const EXPECTED_ABANDONED = [
    'doctrine/cache',
    'swiftmailer/swiftmailer',
];
const EXPECTED_ADVISORIES = [
    'PKSA-w9tt-7782-78jx' => 'Required transitively by supported Flarum 1.8.x; allow dependency resolution in CI while keeping the advisory visible to audit.',
    'PKSA-pwh8-d4fr-nywn' => 'Required transitively by supported Flarum 1.8.x; allow dependency resolution in CI while keeping the advisory visible to audit.',
];

function fail(string $message): never
{
    fwrite(STDERR, "COMPOSER_SECURITY_POLICY=FAIL {$message}\n");
    exit(1);
}

/** @return array<string, mixed> */
function readJsonFile(string $path): array
{
    if (!is_file($path)) {
        fail("missing_json path={$path}");
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        fail("invalid_json path={$path}");
    }

    return $decoded;
}

/** @param array<string, mixed> $data */
function writeJsonFile(string $path, array $data): void
{
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        fail("encode_failed path={$path}");
    }

    if (file_put_contents($path, $encoded . PHP_EOL) === false) {
        fail("write_failed path={$path}");
    }
}

/** @return array<string, array{on-block: bool, on-audit: bool, reason: string}> */
function expectedIgnoreId(): array
{
    $result = [];
    foreach (EXPECTED_ADVISORIES as $id => $reason) {
        $result[$id] = [
            'on-block' => true,
            'on-audit' => false,
            'reason' => $reason,
        ];
    }

    return $result;
}

/** @param array<string, mixed> $root */
function policyFromRoot(array $root): array
{
    $config = $root['config'] ?? [];
    if (!is_array($config)) {
        fail('config_not_object');
    }

    if (($config['policy'] ?? null) === false) {
        fail('global_policy_disabled');
    }

    $policy = $config['policy'] ?? [];
    if (!is_array($policy)) {
        fail('policy_not_object');
    }

    $advisories = $policy['advisories'] ?? [];
    if ($advisories === false || !is_array($advisories)) {
        fail('advisories_policy_disabled_or_invalid');
    }

    return $advisories;
}

/** @param array<string, mixed> $root */
function verifyPolicy(array $root): void
{
    $advisories = policyFromRoot($root);

    if (($advisories['block'] ?? true) !== true) {
        fail('advisory_blocking_not_enabled');
    }

    if (($advisories['audit'] ?? 'fail') !== 'fail') {
        fail('advisory_audit_not_fail');
    }

    foreach (['ignore', 'ignore-severity'] as $broadKey) {
        if (isset($advisories[$broadKey]) && $advisories[$broadKey] !== []) {
            fail("broad_exception_present key={$broadKey}");
        }
    }

    $ignoreId = $advisories['ignore-id'] ?? [];
    if (!is_array($ignoreId)) {
        fail('ignore_id_not_object');
    }

    if ($ignoreId !== expectedIgnoreId()) {
        fail('ignore_id_not_exact_expected_set');
    }

    echo "COMPOSER_SECURITY_COMPAT_MODE=EXACT_ID_BLOCKING_EXCEPTION\n";
    echo "COMPOSER_POLICY_ADVISORIES_BLOCK=true\n";
    echo "COMPOSER_POLICY_ADVISORIES_AUDIT=fail\n";
    foreach (array_keys(EXPECTED_ADVISORIES) as $id) {
        echo "ACCEPTED_ADVISORY_ID={$id}\n";
    }
    echo "ACCEPTED_ON_BLOCK=true\n";
    echo "ACCEPTED_ON_AUDIT=false\n";
    echo "GLOBAL_SECURITY_BLOCKING_DISABLED=false\n";
    echo "PACKAGE_WIDE_IGNORE=false\n";

    $policy = $root['config']['policy'] ?? [];
    $abandonedPolicy = is_array($policy) ? ($policy['abandoned'] ?? null) : null;
    if ($abandonedPolicy === false) {
        fail('abandoned_policy_disabled');
    }
    if (is_array($abandonedPolicy)) {
        if (($abandonedPolicy['audit'] ?? 'fail') !== 'fail') {
            fail('abandoned_audit_not_fail');
        }
        $abandonedIgnore = $abandonedPolicy['ignore'] ?? [];
        if ($abandonedIgnore !== []) {
            fail('abandoned_package_ignore_present');
        }
    }
    echo "COMPOSER_ABANDONED_AUDIT=fail\n";
}

/** @param array<string, mixed> $root */
function applyPolicy(array $root): array
{
    policyFromRoot($root);

    $config = $root['config'] ?? [];
    if (!is_array($config)) {
        fail('config_not_object');
    }
    $policy = $config['policy'] ?? [];
    if (!is_array($policy)) {
        $policy = [];
    }
    $advisories = $policy['advisories'] ?? [];
    if (!is_array($advisories)) {
        $advisories = [];
    }

    if (($advisories['block'] ?? true) !== true) {
        fail('preexisting_advisory_blocking_disabled');
    }
    foreach (['ignore', 'ignore-severity'] as $broadKey) {
        if (isset($advisories[$broadKey]) && $advisories[$broadKey] !== []) {
            fail("preexisting_broad_exception key={$broadKey}");
        }
    }
    $existingIgnoreId = $advisories['ignore-id'] ?? [];
    if (!is_array($existingIgnoreId)) {
        fail('preexisting_ignore_id_invalid');
    }
    $unexpected = array_diff(array_keys($existingIgnoreId), array_keys(EXPECTED_ADVISORIES));
    if ($unexpected !== []) {
        fail('preexisting_unexpected_ignore_id=' . implode(',', $unexpected));
    }

    $advisories['block'] = true;
    $advisories['audit'] = 'fail';
    $advisories['ignore-id'] = expectedIgnoreId();
    $policy['advisories'] = $advisories;
    $config['policy'] = $policy;
    $root['config'] = $config;

    return $root;
}

/**
 * @param mixed $grouped
 * @param list<string> $invalid
 * @return list<array{id: string, package: string}>
 */
function collectAdvisoryGroup(mixed $grouped, string $label, array &$invalid): array
{
    if (!is_array($grouped)) {
        $invalid[] = "{$label}_not_object";

        return [];
    }

    $entries = [];
    foreach ($grouped as $package => $packageAdvisories) {
        if (!is_string($package) || $package === '' || !is_array($packageAdvisories)) {
            $invalid[] = "{$label}_package_invalid";
            continue;
        }
        foreach ($packageAdvisories as $advisory) {
            if (!is_array($advisory)) {
                $invalid[] = "{$label}_advisory_invalid";
                continue;
            }
            $id = $advisory['advisoryId'] ?? null;
            $packageName = $advisory['packageName'] ?? $package;
            if (!is_string($id) || $id === '' || !is_string($packageName) || $packageName === '') {
                $invalid[] = "{$label}_identity_invalid";
                continue;
            }
            $entries[] = ['id' => $id, 'package' => $packageName];
        }
    }

    return $entries;
}

/** @param list<string> $values */
function echoRepeated(string $key, array $values): void
{
    foreach ($values as $value) {
        echo "{$key}={$value}\n";
    }
}

/** @param array<string, mixed> $audit */
function assertAudit(array $audit): void
{
    $approved = array_keys(EXPECTED_ADVISORIES);
    $invalid = [];

    if (!array_key_exists('advisories', $audit)) {
        $invalid[] = 'advisories_missing';
        $active = [];
    } else {
        $active = collectAdvisoryGroup($audit['advisories'], 'advisories', $invalid);
    }

    $ignored = collectAdvisoryGroup($audit['ignored-advisories'] ?? [], 'ignored-advisories', $invalid);

    $abandoned = [];
    $abandonedRaw = $audit['abandoned'] ?? [];
    if (!is_array($abandonedRaw)) {
        $invalid[] = 'abandoned_not_object';
    } else {
        foreach ($abandonedRaw as $package => $replacement) {
            if (!is_string($package) || $package === '') {
                $invalid[] = 'abandoned_package_invalid';
                continue;
            }
            $abandoned[] = [
                'package' => $package,
                'replacement' => is_string($replacement) ? $replacement : '',
            ];
        }
    }

    $unreachable = [];
    $unreachableRaw = $audit['unreachable-repositories'] ?? [];
    if (!is_array($unreachableRaw)) {
        $invalid[] = 'unreachable_repositories_not_list';
    } else {
        foreach ($unreachableRaw as $repository) {
            if (is_string($repository) && $repository !== '') {
                $unreachable[] = $repository;
                continue;
            }
            $invalid[] = 'unreachable_repository_invalid';
        }
    }

    $filterPackages = [];
    $filterRaw = $audit['filter'] ?? [];
    if (!is_array($filterRaw)) {
        $invalid[] = 'filter_not_object';
    } else {
        foreach ($filterRaw as $package => $entries) {
            if ($entries === [] || $entries === null) {
                continue;
            }
            $filterPackages[] = is_string($package) ? $package : 'invalid';
        }
    }

    $rawIds = [];
    $unknownPackages = [];
    $unknownIds = [];
    $approvedActive = [];
    foreach ($active as $entry) {
        $rawIds[] = $entry['id'];
        if ($entry['package'] !== EXPECTED_PACKAGE) {
            $unknownPackages[] = $entry['package'];
        }
        if (!array_key_exists($entry['id'], EXPECTED_ADVISORIES)) {
            $unknownIds[] = $entry['id'];
        } elseif ($entry['package'] === EXPECTED_PACKAGE) {
            $approvedActive[] = $entry['id'];
        }
    }

    $duplicateIds = [];
    foreach (array_count_values($rawIds) as $id => $count) {
        if ($count > 1) {
            $duplicateIds[] = (string) $id;
        }
    }

    $activeOrdered = [];
    $inactive = [];
    foreach ($approved as $id) {
        if (in_array($id, $approvedActive, true)) {
            $activeOrdered[] = $id;
        } else {
            $inactive[] = $id;
        }
    }

    $ignoredIds = [];
    foreach ($ignored as $entry) {
        $ignoredIds[] = $entry['id'];
    }
    $unknownPackages = array_values(array_unique($unknownPackages));
    $unknownIds = array_values(array_unique($unknownIds));

    echo 'BLOCKING_ALLOWLIST_COUNT=' . count($approved) . "\n";
    echo 'RAW_ACTIVE_ADVISORY_COUNT=' . count($rawIds) . "\n";
    echo 'UNIQUE_ACTIVE_ADVISORY_COUNT=' . count(array_unique($rawIds)) . "\n";
    echo 'ACTIVE_AUDIT_IDS_UNIQUE=' . ($duplicateIds === [] ? 'true' : 'false') . "\n";
    echo 'ACTIVE_AUDIT_ADVISORY_COUNT=' . count($activeOrdered) . "\n";
    echoRepeated('ACTIVE_AUDIT_ADVISORY_ID', $activeOrdered);
    echo 'INACTIVE_APPROVED_ADVISORY_COUNT=' . count($inactive) . "\n";
    echoRepeated('INACTIVE_APPROVED_ADVISORY_ID', $inactive);
    echo 'UNKNOWN_ACTIVE_ADVISORY_COUNT=' . count($unknownIds) . "\n";
    echoRepeated('UNKNOWN_ACTIVE_ADVISORY_ID', $unknownIds);
    echo 'UNKNOWN_ACTIVE_ADVISORY_PACKAGE_COUNT=' . count($unknownPackages) . "\n";
    echoRepeated('UNKNOWN_ACTIVE_ADVISORY_PACKAGE', $unknownPackages);
    echo 'IGNORED_ADVISORY_COUNT=' . count($ignoredIds) . "\n";
    echoRepeated('IGNORED_ADVISORY_ID', $ignoredIds);
    $unknownAbandoned = [];
    foreach ($abandoned as $package) {
        if (!in_array($package['package'], EXPECTED_ABANDONED, true)) {
            $unknownAbandoned[] = $package['package'];
        }
    }
    echo 'APPROVED_ABANDONED_COUNT=' . count(EXPECTED_ABANDONED) . "\n";
    echoRepeated('APPROVED_ABANDONED_PACKAGE', EXPECTED_ABANDONED);
    echo 'ABANDONED_COUNT=' . count($abandoned) . "\n";
    foreach ($abandoned as $package) {
        echo 'ABANDONED_PACKAGE=' . $package['package'] . "\n";
        echo 'ABANDONED_REPLACEMENT=' . $package['replacement'] . "\n";
    }
    echo 'UNKNOWN_ABANDONED_COUNT=' . count($unknownAbandoned) . "\n";
    echoRepeated('UNKNOWN_ABANDONED_PACKAGE', $unknownAbandoned);
    echo 'UNREACHABLE_REPOSITORY_COUNT=' . count($unreachable) . "\n";
    echoRepeated('UNREACHABLE_REPOSITORY', $unreachable);
    echo 'FILTER_FINDING_COUNT=' . count($filterPackages) . "\n";
    echoRepeated('FILTER_PACKAGE', $filterPackages);

    if ($invalid !== []) {
        echo "SECURITY_AUDIT_GATE=FAIL_INVALID_AUDIT_JSON\n";
        fail('invalid_audit=' . implode(',', array_values(array_unique($invalid))));
    }
    if ($unreachable !== []) {
        echo "SECURITY_AUDIT_GATE=FAIL_UNREACHABLE_REPOSITORIES\n";
        fail('unreachable_repositories=' . implode(',', $unreachable));
    }
    if ($ignored !== []) {
        echo "SECURITY_AUDIT_GATE=FAIL_AUDIT_VISIBILITY\n";
        fail('ignored_advisories=' . implode(',', $ignoredIds));
    }
    if ($unknownPackages !== []) {
        echo "SECURITY_AUDIT_GATE=FAIL_UNKNOWN_ADVISORY_PACKAGE\n";
        fail('unknown_active_advisory_package=' . implode(',', $unknownPackages));
    }
    if ($unknownIds !== []) {
        echo "SECURITY_AUDIT_GATE=FAIL_UNKNOWN_ADVISORY\n";
        fail('unknown_active_advisory=' . implode(',', $unknownIds));
    }
    if ($duplicateIds !== []) {
        echo "SECURITY_AUDIT_GATE=FAIL_DUPLICATE_ACTIVE_ADVISORY\n";
        fail('duplicate_active_advisory=' . implode(',', $duplicateIds));
    }
    if ($unknownAbandoned !== []) {
        echo "SECURITY_AUDIT_GATE=FAIL_ABANDONED_DEPENDENCIES\n";
        fail('unknown_abandoned_package=' . implode(',', $unknownAbandoned));
    }
    if ($filterPackages !== []) {
        echo "SECURITY_AUDIT_GATE=FAIL_FILTER_FINDING\n";
        fail('filter_packages=' . implode(',', $filterPackages));
    }

    echo "SECURITY_AUDIT_GATE=PASS_ACCEPTED_APPLICABLE_DEBT\n";
}

$command = $argv[1] ?? '';
$path = $argv[2] ?? '';

switch ($command) {
    case 'apply':
        if ($path === '') {
            fail('usage_apply_requires_composer_json');
        }
        $root = applyPolicy(readJsonFile($path));
        writeJsonFile($path, $root);
        verifyPolicy(readJsonFile($path));
        break;

    case 'verify':
        if ($path === '') {
            fail('usage_verify_requires_composer_json');
        }
        verifyPolicy(readJsonFile($path));
        break;

    case 'assert-audit':
        if ($path === '') {
            fail('usage_assert_audit_requires_json');
        }
        assertAudit(readJsonFile($path));
        break;

    default:
        fail('usage: ci-composer-advisory-policy.php apply|verify|assert-audit <json-file>');
}
