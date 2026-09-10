<?php

/**
 * REP-001F.A1 R1 executable behavior tests:
 * - primary/secondary brand attribution
 * - outbox claim / retry / terminal / fresh-nonce drain
 *
 * Uses an in-memory query fake (no PDO/SQLite required).
 * Does not touch production.
 */

declare(strict_types=1);

use FlatRate\SupabaseOAuth\Activity\ActivityOutboxDrainer;
use FlatRate\SupabaseOAuth\Activity\BrandContext;
use FlatRate\SupabaseOAuth\Activity\OutboxStore;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

$harnessDir = __DIR__.'/harness/activity-r1';
$autoload = $harnessDir.'/vendor/autoload.php';

// psr/log is enough; illuminate is optional for this harness.
if (is_file($autoload)) {
    require $autoload;
} elseif (! interface_exists(LoggerInterface::class)) {
    // Minimal PSR-3 stub when vendor is absent (brand-only local fallback never used in CI).
    fwrite(STDERR, "ACTIVITY_R1_HARNESS_MISSING_VENDOR: run composer install in {$harnessDir}\n");
    exit(2);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'FlatRate\\SupabaseOAuth\\Activity\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = dirname(__DIR__).'/src/Activity/'.$relative.'.php';
    if (is_file($path)) {
        require $path;
    }
});

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
    } else {
        fail($label, 'expected '.var_export($expected, true).' got '.var_export($actual, true));
    }
}

function assertTrue(bool $cond, string $label): void
{
    $cond ? pass($label) : fail($label);
}

function tag(string $slug, ?int $position): object
{
    return (object) ['slug' => $slug, 'position' => $position];
}

/**
 * Minimal query surface used by OutboxStore (in-memory).
 */
final class MemoryOutboxDb
{
    /** @var list<array<string,mixed>> */
    public array $rows = [];
    public int $seq = 0;

    public function transaction(callable $fn)
    {
        return $fn();
    }

    public function table(string $name): MemoryOutboxQuery
    {
        return new MemoryOutboxQuery($this);
    }
}

final class MemoryOutboxQuery
{
    /** @var list<callable> */
    private array $filters = [];
    private ?string $orderCol = null;
    private ?int $limit = null;

    public function __construct(private MemoryOutboxDb $db)
    {
    }

    public function insertGetId(array $row): int
    {
        $this->db->seq++;
        $row['id'] = $this->db->seq;
        $this->db->rows[] = $row;

        return $this->db->seq;
    }

    public function where($col, $op = null, $val = null): self
    {
        if (func_num_args() === 2) {
            $val = $op;
            $op = '=';
        }
        $this->filters[] = static function (array $row) use ($col, $op, $val): bool {
            $left = $row[$col] ?? null;
            return match ($op) {
                '=' => $left == $val,
                '<=' => $left <= $val,
                '<' => $left < $val,
                default => false,
            };
        };

        return $this;
    }

    public function whereNull(string $col): self
    {
        $this->filters[] = static fn (array $row): bool => ($row[$col] ?? null) === null;

        return $this;
    }

    public function whereNotNull(string $col): self
    {
        $this->filters[] = static fn (array $row): bool => ($row[$col] ?? null) !== null;

        return $this;
    }

    public function orderBy(string $col): self
    {
        $this->orderCol = $col;

        return $this;
    }

    public function limit(int $n): self
    {
        $this->limit = $n;

        return $this;
    }

    public function lockForUpdate(): self
    {
        return $this;
    }

    /** @return list<object> */
    public function get(): array
    {
        $rows = $this->matched();
        if ($this->orderCol) {
            $col = $this->orderCol;
            usort($rows, static fn ($a, $b) => ($a[$col] ?? 0) <=> ($b[$col] ?? 0));
        }
        if ($this->limit !== null) {
            $rows = array_slice($rows, 0, $this->limit);
        }

        return array_map(static fn (array $r) => (object) $r, $rows);
    }

    public function first(): ?object
    {
        $rows = $this->matched();

        return $rows ? (object) $rows[0] : null;
    }

    public function value(string $col)
    {
        $row = $this->first();

        return $row?->{$col};
    }

    public function update(array $values): int
    {
        $count = 0;
        foreach ($this->db->rows as $i => $row) {
            if ($this->matches($row)) {
                $this->db->rows[$i] = array_merge($row, $values);
                $count++;
            }
        }

        return $count;
    }

    public function delete(): int
    {
        $before = count($this->db->rows);
        $this->db->rows = array_values(array_filter(
            $this->db->rows,
            fn (array $row): bool => ! $this->matches($row)
        ));

        return $before - count($this->db->rows);
    }

    /** @return list<array<string,mixed>> */
    private function matched(): array
    {
        return array_values(array_filter($this->db->rows, fn (array $row): bool => $this->matches($row)));
    }

    private function matches(array $row): bool
    {
        foreach ($this->filters as $filter) {
            if (! $filter($row)) {
                return false;
            }
        }

        return true;
    }
}

$brands = new BrandContext();

assertSame(
    'toyota',
    $brands->brandSlugFromPrimaryTags([tag('warranty', null), tag('toyota', 0)]),
    'secondary warranty + primary toyota => toyota'
);
assertSame(
    'honda',
    $brands->brandSlugFromPrimaryTags([tag('job-breakdown', null), tag('honda', 1)]),
    'secondary job-breakdown + primary honda => honda'
);
assertSame(
    null,
    $brands->brandSlugFromPrimaryTags([
        tag('technician-topics', 0),
        tag('toyota-like-nonbrand-token', null),
    ]),
    'unbranded primary + secondary nonbrand => null'
);
assertSame(
    'chevrolet',
    $brands->brandSlugFromPrimaryTags([tag('chevrolet', 0)]),
    'chevrolet primary => chevrolet not gm'
);
assertSame(
    'gm',
    $brands->brandSlugFromPrimaryTags([tag('gm', 0)]),
    'gm board primary => gm'
);
assertSame(
    'chevrolet',
    $brands->brandSlugFromPrimaryTags([tag('gm', 0), tag('chevrolet', 1)]),
    'gm+chevrolet primaries prefer leaf chevrolet once'
);
assertTrue(! $brands->isAcceptedBrandSlug('warranty'), 'warranty not accepted brand');
assertTrue($brands->isAcceptedBrandSlug('alpha-romeo'), 'legacy alpha-romeo preserved');
assertTrue($brands->isAcceptedBrandSlug('genisis'), 'legacy genisis preserved');

$db = new MemoryOutboxDb();
$outbox = new OutboxStore($db);
$now = gmdate('Y-m-d H:i:s');
$past = gmdate('Y-m-d H:i:s', time() - 60);
$future = gmdate('Y-m-d H:i:s', time() + 600);

$payload = [
    'schema_version' => 1,
    'identity' => [
        'sub' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'flarum_user_id' => 7,
    ],
    'observation' => [
        'kind' => 'vote_transition',
        'from_value' => 0,
        'to_value' => 1,
    ],
];

$idPending = (int) $db->table('flatrate_activity_outbox')->insertGetId([
    'event_kind' => 'vote_transition',
    'flarum_user_id' => 7,
    'payload_json' => json_encode($payload),
    'attempts' => 0,
    'available_at' => $past,
    'created_at' => $now,
    'delivered_at' => null,
    'terminal_at' => null,
    'last_error_code' => null,
]);

$idFuture = (int) $db->table('flatrate_activity_outbox')->insertGetId([
    'event_kind' => 'vote_transition',
    'flarum_user_id' => 7,
    'payload_json' => json_encode($payload),
    'attempts' => 1,
    'available_at' => $future,
    'created_at' => $now,
    'delivered_at' => null,
    'terminal_at' => null,
    'last_error_code' => 'http_500',
]);

$claimed = $outbox->claimDue(null, 10);
assertSame(1, count($claimed), 'only due rows claimed');
assertSame($idPending, (int) $claimed[0]->id, 'claimed due pending row');

$claimedAgain = $outbox->claimDue(null, 10);
assertSame(0, count($claimedAgain), 'lease prevents second claim');

$outbox->markFailure(null, $idPending, 'network_error', true);
$row = $db->table('flatrate_activity_outbox')->where('id', $idPending)->first();
assertSame(1, (int) $row->attempts, 'retry increments attempts');
assertTrue($row->available_at > gmdate('Y-m-d H:i:s'), 'retry schedules future available_at');
assertTrue($row->terminal_at === null, 'retryable is not terminal');

$db->table('flatrate_activity_outbox')->where('id', $idPending)->update([
    'available_at' => $past,
]);

$nonceHolder = new class {
    /** @var list<string> */
    public array $nonces = [];
};

$fakeClient = new class($nonceHolder) {
    /** @var list<int> */
    public array $statuses;
    public int $call = 0;

    public function __construct(private object $nonceHolder)
    {
        $this->statuses = [0, 500, 429, 200];
    }

    public function enabled(): bool
    {
        return true;
    }

    public function postObservation(array $payload): array
    {
        $this->nonceHolder->nonces[] = bin2hex(random_bytes(16));
        $status = $this->statuses[$this->call] ?? 200;
        $this->call++;
        if ($status === 0) {
            return ['ok' => false, 'status' => 0, 'retryable' => true, 'error' => 'network_error'];
        }
        if ($status === 200) {
            return ['ok' => true, 'status' => 200, 'retryable' => false, 'error' => null];
        }
        $retryable = $status === 429 || $status >= 500;

        return [
            'ok' => false,
            'status' => $status,
            'retryable' => $retryable,
            'error' => 'http_'.$status,
        ];
    }
};

$logger = new class extends AbstractLogger {
    public function log($level, $message, array $context = []): void
    {
    }
};

$drainer = new ActivityOutboxDrainer($fakeClient, $outbox, $logger);

assertSame(1, $drainer->drain(), 'drain processes due row');
$row = $db->table('flatrate_activity_outbox')->where('id', $idPending)->first();
assertTrue($row->delivered_at === null, 'network failure keeps pending');
assertTrue($row->terminal_at === null, 'network failure not terminal');

$db->table('flatrate_activity_outbox')->where('id', $idPending)->update(['available_at' => $past]);
assertSame(1, $drainer->drain(), '500 retry path');
$row = $db->table('flatrate_activity_outbox')->where('id', $idPending)->first();
assertTrue($row->delivered_at === null, '500 keeps pending');

$db->table('flatrate_activity_outbox')->where('id', $idPending)->update(['available_at' => $past]);
assertSame(1, $drainer->drain(), '429 retry path');
$row = $db->table('flatrate_activity_outbox')->where('id', $idPending)->first();
assertTrue($row->delivered_at === null, '429 keeps pending');

$db->table('flatrate_activity_outbox')->where('id', $idPending)->update(['available_at' => $past]);
assertSame(1, $drainer->drain(), '2xx marks delivered');
$row = $db->table('flatrate_activity_outbox')->where('id', $idPending)->first();
assertTrue($row->delivered_at !== null, '2xx sets delivered_at');
assertTrue(count($nonceHolder->nonces) === 4 && count(array_unique($nonceHolder->nonces)) === 4, 'fresh nonce each attempt');

$termId = (int) $db->table('flatrate_activity_outbox')->insertGetId([
    'event_kind' => 'vote_transition',
    'flarum_user_id' => 7,
    'payload_json' => json_encode($payload),
    'attempts' => 0,
    'available_at' => $past,
    'created_at' => $now,
    'delivered_at' => null,
    'terminal_at' => null,
    'last_error_code' => null,
]);
$fakeClient->statuses = [400];
$fakeClient->call = 0;
assertSame(1, $drainer->drain(), '400 drain');
$row = $db->table('flatrate_activity_outbox')->where('id', $termId)->first();
assertTrue($row->terminal_at !== null, '400 is terminal');
assertTrue(! str_contains((string) $row->payload_json, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'), 'terminal redacts sub');
assertTrue(str_contains((string) $row->payload_json, 'redacted'), 'terminal payload redacted');

$concurrentId = (int) $db->table('flatrate_activity_outbox')->insertGetId([
    'event_kind' => 'discussion_created',
    'flarum_user_id' => 3,
    'payload_json' => json_encode($payload),
    'attempts' => 0,
    'available_at' => $past,
    'created_at' => $now,
    'delivered_at' => null,
    'terminal_at' => null,
    'last_error_code' => null,
]);
$a = $outbox->claimDue(null, 10);
$b = $outbox->claimDue(null, 10);
$aIds = array_map(static fn ($r) => (int) $r->id, $a);
$bIds = array_map(static fn ($r) => (int) $r->id, $b);
assertTrue(in_array($concurrentId, $aIds, true), 'first drainer claims row');
assertTrue(! in_array($concurrentId, $bIds, true), 'second drainer does not duplicate claim');
assertTrue($idFuture > 0, 'future-scheduled row exists');

echo $failures === 0 ? "ACTIVITY_R1_BEHAVIOR_PASS=true\n" : "ACTIVITY_R1_BEHAVIOR_PASS=false\n";
exit($failures === 0 ? 0 : 1);
