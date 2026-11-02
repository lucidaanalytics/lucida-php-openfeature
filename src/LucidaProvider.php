<?php
declare(strict_types=1);

namespace Lucida\OpenFeature;

use Lucida\Client;
use OpenFeature\implementation\common\Metadata;
use OpenFeature\implementation\provider\AbstractProvider;
use OpenFeature\implementation\provider\ResolutionDetailsBuilder;
use OpenFeature\implementation\provider\ResolutionError;
use OpenFeature\interfaces\common\Metadata as MetadataInterface;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\Provider;
use OpenFeature\interfaces\provider\ResolutionDetails;

/**
 * OpenFeature server provider over Lucida\Client. targetingKey is the distinct id; the attribute `groups` (groupType =>
 * key) and `groupProperties` (groupType => map) feed Lucida groups, every other attribute is a person property.
 * Integer, float and object flags read the flag's JSON payload; boolean and string flags read its value.
 */
final class LucidaProvider extends AbstractProvider implements Provider
{
    public function __construct(private readonly Client $client)
    {
    }

    public function getMetadata(): MetadataInterface
    {
        return new Metadata('Lucida');
    }

    public function resolveBooleanValue(string $flagKey, bool $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->resolve($flagKey, $defaultValue, $context, 'is_bool', false);
    }

    public function resolveStringValue(string $flagKey, string $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->resolve($flagKey, $defaultValue, $context, 'is_string', false);
    }

    public function resolveIntegerValue(string $flagKey, int $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->resolve($flagKey, $defaultValue, $context, 'is_int', true);
    }

    public function resolveFloatValue(string $flagKey, float $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->resolve($flagKey, $defaultValue, $context, fn ($v) => is_float($v) || is_int($v), true);
    }

    /** @param array $defaultValue */
    public function resolveObjectValue(string $flagKey, array $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->resolve($flagKey, $defaultValue, $context, 'is_array', true);
    }

    /**
     * OpenFeature tracking: event = $eventName, distinct id = targetingKey, properties = $details minus `value`, plus
     * `$value` when `value` is set. Dropped silently without a targeting key.
     *
     * @param array<string, mixed> $details
     */
    public function track(string $eventName, ?EvaluationContext $context = null, array $details = []): void
    {
        $id = $context?->getTargetingKey();
        if ($id === null || $id === '') {
            return;
        }
        $props = array_map(Mapping::native(...), $details);
        if (array_key_exists('value', $props)) {
            if ($props['value'] !== null) {
                $props['$value'] = $props['value'];
            }
            unset($props['value']);
        }
        $this->client->capture($id, $eventName, $props, Mapping::split($context->getAttributes()->toArray())[1] ?: null);
    }

    private function resolve(string $key, mixed $default, ?EvaluationContext $context, callable $typeOk, bool $fromPayload): ResolutionDetails
    {
        $id = $context?->getTargetingKey();
        if ($id === null || $id === '') {
            return $this->error($default, ErrorCode::TARGETING_KEY_MISSING(), 'targetingKey is required');
        }
        [$person, $groups, $gprops] = Mapping::split($context->getAttributes()->toArray());
        $d = $this->client->getFeatureFlagDetails($key, $id, personProperties: $person, groups: $groups, groupProperties: $gprops);
        if ($d === null) {
            return $this->error($default, ErrorCode::FLAG_NOT_FOUND(), "flag $key not found");
        }
        $value = $fromPayload && $d['reason'] !== 'fallback' ? $d['payload'] : $d['value'];
        if (!$typeOk($value)) {
            return $this->error($default, ErrorCode::TYPE_MISMATCH(), "flag $key does not resolve to the requested type", $d);
        }
        return $this->build($value, $d, Mapping::reason((string) $d['reason'], $d['value']), null);
    }

    private function error(mixed $default, ErrorCode $code, string $message, ?array $d = null): ResolutionDetails
    {
        return $this->build($default, $d, 'ERROR', new ResolutionError($code, $message));
    }

    private function build(mixed $value, ?array $d, string $reason, ?ResolutionError $error): ResolutionDetails
    {
        $b = (new ResolutionDetailsBuilder())->withValue($value)->withReason($reason);
        if ($d !== null && is_string($d['variant'] ?? null)) {
            $b->withVariant($d['variant']);
        }
        if ($error !== null) {
            $b->withError($error);
        }
        // flagMetadata needs a php-sdk release whose builder has it; older ones return the same data from Mapping::metadata().
        if ($d !== null && method_exists($b, 'withFlagMetadata')) {
            $b->withFlagMetadata($this->flagMetadata(Mapping::metadata($d)));
        }
        return $b->build();
    }

    /** @param array<string, string|int|bool> $m */
    private function flagMetadata(array $m): mixed
    {
        $cls = 'OpenFeature\\implementation\\flags\\FlagMetadata';
        return class_exists($cls) ? new $cls($m) : $m;
    }
}
