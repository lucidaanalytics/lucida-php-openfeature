<?php
declare(strict_types=1);

// Provider behaviour over an offline Client (testMode). Required by run.php.
use Lucida\Client;
use Lucida\OpenFeature\LucidaProvider;
use OpenFeature\implementation\flags\MutableEvaluationContext;

$flag = fn (string $key, array $extra = []): array => $extra + [
    'key' => $key, 'type' => 'boolean', 'active' => true, 'salt' => 's', 'aggregation' => ['kind' => 'person'],
    'conditions' => [['rollout_pct' => 100]],
];
$lc = new Client('k', secretKey: 's', testMode: [$flag('on'), $flag('off', ['active' => false])], fallbacks: ['ghost' => true]);
$p = new LucidaProvider($lc);
$ctx = new MutableEvaluationContext('user-1');

$r = $p->resolveBooleanValue('on', false, $ctx);
check($r->getValue() === true && $r->getReason() === 'TARGETING_MATCH' && $r->getError() === null, 'served flag');
$r = $p->resolveBooleanValue('off', true, $ctx);
check($r->getValue() === false && $r->getReason() === 'DISABLED', 'inactive flag is DISABLED');
$r = $p->resolveBooleanValue('ghost', false, $ctx);
check($r->getValue() === true && $r->getReason() === 'DEFAULT' && $r->getError() === null, 'fallback keeps the value, no error');
$r = $p->resolveBooleanValue('nope', false, $ctx);
check($r->getValue() === false && $r->getReason() === 'ERROR' && $r->getError() !== null, 'missing flag is an error');
$r = $p->resolveStringValue('on', 'd', $ctx);
check($r->getValue() === 'd' && $r->getError() !== null, 'type mismatch returns the default with an error');
$r = $p->resolveBooleanValue('on', false, new MutableEvaluationContext());
check($r->getError() !== null && $r->getReason() === 'ERROR', 'missing targeting key');

$lc->sink->clear();
$p->track('purchase', $ctx, ['value' => 12.5, 'plan' => 'pro']);
$lc->flush();
$e = $lc->sink->events[0] ?? null;
check($e !== null && $e['event'] === 'purchase' && $e['distinct_id'] === 'user-1'
    && $e['properties']['$value'] === 12.5 && $e['properties']['plan'] === 'pro' && !isset($e['properties']['value']), 'track captures');
