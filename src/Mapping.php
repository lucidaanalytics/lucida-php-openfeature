<?php
declare(strict_types=1);

namespace Lucida\OpenFeature;

/** Pure Lucida -> OpenFeature mappings (no OpenFeature classes): reason table, flag metadata, context split. */
final class Mapping
{
    /** Mirrors testdata/openfeature/reasons.json; the tests compare the two. */
    public const REASONS = [
        'condition_match' => 'TARGETING_MATCH', 'sticky_bucket' => 'CACHED', 'no_condition_match' => 'DEFAULT',
        'out_of_rollout' => 'DEFAULT', 'holdout' => 'DEFAULT', 'namespace_excluded' => 'DEFAULT', 'no_bucketing_key' => 'DEFAULT',
        'prerequisite_failed' => 'DEFAULT', 'prerequisite_missing' => 'DEFAULT', 'prerequisite_cycle' => 'ERROR',
        'inactive' => 'DISABLED', 'kill_switch' => 'DISABLED', 'app_context_excluded' => 'DISABLED',
        'preview' => 'STATIC', 'user_preview' => 'STATIC', 'fallback' => 'DEFAULT',
    ];

    /** OpenFeature reason of a raw Lucida reason; a variant string under condition_match is SPLIT. */
    public static function reason(string $lucida, mixed $value): string
    {
        if ($lucida === 'condition_match' && is_string($value)) {
            return 'SPLIT';
        }
        return self::REASONS[$lucida] ?? 'UNKNOWN';
    }

    /** @param array $d Client::getFeatureFlagDetails() result @return array<string, string|int|bool> */
    public static function metadata(array $d): array
    {
        $m = ['lucidaReason' => (string) $d['reason']];
        if (($d['condition_index'] ?? -1) >= 0) {
            $m['conditionIndex'] = (int) $d['condition_index'];
        }
        if (($d['request_id'] ?? null) !== null && $d['request_id'] !== '') {
            $m['requestId'] = (string) $d['request_id'];
        }
        $m['evaluatedLocally'] = (bool) ($d['locally_evaluated'] ?? false);
        return $m;
    }

    /**
     * Evaluation attributes -> Lucida inputs: `groups` and `groupProperties` are their own maps, everything else is a
     * person property (dates become ISO-8601 strings, objects their public properties).
     *
     * @return array{0: array, 1: array, 2: array} [personProperties, groups, groupProperties]
     */
    public static function split(array $attributes): array
    {
        $groups = is_array($attributes['groups'] ?? null) ? $attributes['groups'] : [];
        $gprops = is_array($attributes['groupProperties'] ?? null) ? $attributes['groupProperties'] : [];
        unset($attributes['groups'], $attributes['groupProperties']);
        return [array_map(self::native(...), $attributes), array_map('strval', $groups), array_map(self::native(...), $gprops)];
    }

    public static function native(mixed $v): mixed
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format(\DateTimeInterface::ATOM);
        }
        if ($v instanceof \JsonSerializable) {
            return self::native($v->jsonSerialize());
        }
        if (is_object($v)) {
            return self::native(get_object_vars($v));
        }
        return is_array($v) ? array_map(self::native(...), $v) : $v;
    }
}
