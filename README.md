# lucida/openfeature (PHP)

OpenFeature server provider over the Lucida PHP SDK. Pinned to `open-feature/sdk ^2.0` (Apache-2.0).

```bash
composer require lucida/openfeature
```

```php
use OpenFeature\OpenFeatureAPI;

$lc = new Lucida\Client('lc_pk_...', secretKey: 'lc_sk_...', fallbacks: ['new-nav' => false]);
OpenFeatureAPI::getInstance()->setProvider(new Lucida\OpenFeature\LucidaProvider($lc));
$client = OpenFeatureAPI::getInstance()->getClient();
$on = $client->getBooleanValue('new-nav', false, new OpenFeature\implementation\flags\MutableEvaluationContext('user-1', new OpenFeature\implementation\flags\Attributes(['plan' => 'pro', 'groups' => ['company' => 'acme']])));
```

- Context: `targetingKey` is the distinct id (missing: `TARGETING_KEY_MISSING`); attribute `groups` (type => key) and
  `groupProperties` (type => map) feed Lucida groups; every other attribute is a person property.
- Boolean and string flags read the flag value; integer, float and object flags read its JSON payload. A wrong type is
  `TYPE_MISMATCH`, an unknown flag `FLAG_NOT_FOUND` (reason `ERROR`).
- Reasons map through `testdata/openfeature/reasons.json` (`condition_match` with a variant is `SPLIT`). A value from the
  client's `fallbacks` is returned with reason `DEFAULT` and no error. Flag metadata (when the OpenFeature SDK supports it):
  `lucidaReason`, `conditionIndex`, `requestId`, `evaluatedLocally`; `Lucida\OpenFeature\Mapping::metadata()` returns them.
- `track($eventName, $context, ['value' => 12.5, ...])` captures the event for the targeting key (`value` becomes `$value`).
  The PHP OpenFeature SDK has no provider events, so none are emitted; flags re-poll per `flagPollInterval`.

Tests: `composer install && php tests/run.php`.

---

This repository is a read-only mirror of the Lucida monorepo (synced by a script, never edited by hand); releases and provenance attestations are built here by GitHub Actions. Issues are welcome, changes land upstream.
