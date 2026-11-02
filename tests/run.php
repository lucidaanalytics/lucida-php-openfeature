<?php
declare(strict_types=1);

// Dependency-light runner: `composer install && php tests/run.php` (exit 1 on any failure).
$vendor = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
} else {
    require __DIR__ . '/../../php/src/Evaluator.php';
    foreach (['OpsV2', 'FlagSnapshot', 'FlagDefinitionCacheProvider', 'InMemoryFlagDefinitionCache', 'FlagCache', 'LucidaException', 'Context', 'Sink', 'Delivery', 'FlagApi', 'Identity', 'Client'] as $f) {
        require __DIR__ . "/../../php/src/$f.php";
    }
}
require __DIR__ . '/../src/Mapping.php';

use Lucida\Client;
use Lucida\OpenFeature\LucidaProvider;
use Lucida\OpenFeature\Mapping;

$fails = 0;
$checks = 0;
function check(bool $ok, string $msg): void
{
    global $fails, $checks;
    $checks++;
    if (!$ok) {
        $fails++;
        fwrite(STDERR, "FAIL $msg\n");
    }
}

// reason table, driven by the shared testdata/openfeature/reasons.json
$table = json_decode(file_get_contents((getenv('LUCIDA_TESTDATA') ?: __DIR__ . '/../../../testdata') . '/openfeature/reasons.json'), true, 512, JSON_THROW_ON_ERROR)['reasons'];
foreach ($table as $raw => $row) {
    check(Mapping::reason($raw, true) === $row['reason'], "reason $raw");
    check(Mapping::reason($raw, 'variant-a') === ($row['variant_reason'] ?? $row['reason']), "variant reason $raw");
}
check(Mapping::reason('something_new', true) === 'UNKNOWN', 'unknown reason');
check(count(Mapping::REASONS) === count($table) - 1, 'embedded table matches reasons.json');

// metadata + context split
$m = Mapping::metadata(['reason' => 'fallback', 'condition_index' => -1, 'request_id' => null, 'locally_evaluated' => false]);
check($m === ['lucidaReason' => 'fallback', 'evaluatedLocally' => false], 'metadata omits unset keys');
$m = Mapping::metadata(['reason' => 'condition_match', 'condition_index' => 2, 'request_id' => 'r1', 'locally_evaluated' => true]);
check($m === ['lucidaReason' => 'condition_match', 'conditionIndex' => 2, 'requestId' => 'r1', 'evaluatedLocally' => true], 'metadata keys');
[$p, $g, $gp] = Mapping::split(['plan' => 'pro', 'when' => new DateTimeImmutable('2020-01-02T03:04:05+00:00'), 'groups' => ['company' => 'acme'], 'groupProperties' => ['company' => ['seats' => 5]]]);
check($p === ['plan' => 'pro', 'when' => '2020-01-02T03:04:05+00:00'] && $g === ['company' => 'acme'] && $gp === ['company' => ['seats' => 5]], 'context split');

// provider (needs open-feature/sdk)
if (class_exists(LucidaProvider::class)) {
    require __DIR__ . '/provider.php';
} else {
    fwrite(STDERR, "skip provider tests: open-feature/sdk not installed\n");
}

echo $fails ? "FAILED $fails of $checks checks\n" : "ok $checks checks\n";
exit($fails ? 1 : 0);
