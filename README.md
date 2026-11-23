# php-exchange-rates

Dependency-free PHP exchange-rate client backed by the **European Central Bank's free daily reference rates** — no API key, no account, no composer dependency.

## Features

- **Free data source** — ECB `eurofxref-daily.xml`, ~30 currencies quoted against the euro, published every working day
- **Convert + cross rates** — `convert(100, 'USD', 'GBP')` works through EUR automatically; `getRate()` returns the direct rate
- **File cache** — rates are cached to a JSON file (default 12h, configurable) so a page that converts prices never hits the ECB on every request
- **Formatting** — `format(49.99, 'USD')` → `$49.99`, with a built-in symbol table for 30 currencies
- **Testable** — inject a custom fetcher callable to feed canned XML instead of the network
- **Clear errors** — unknown currencies and fetch/parse failures throw `ExchangeRateException`

## Usage

```php
require 'src/ExchangeRateException.php';
require 'src/ExchangeRates.php';

use Azanumer\ExchangeRates\ExchangeRates;

$rates = new ExchangeRates(); // cache: sys temp dir, 12h TTL

$rates->convert(100, 'USD', 'EUR');   // e.g. 92.12
$rates->getRate('GBP', 'USD');        // cross rate via EUR
$rates->format(49.99, 'USD');         // $49.99
$rates->supportedCurrencies();        // ['EUR', 'USD', 'GBP', ...]
$rates->ratesDate();                  // '2026-11-20' (ECB publication date)
$rates->refresh();                    // drop the cache, fetch fresh next call
```

Custom cache location and TTL:

```php
$rates = new ExchangeRates('/var/cache/rates.json', 3600); // 1 hour
```

## How it works

The ECB quotes every currency as "1 EUR = X". A cross rate is therefore `rate(to) / rate(from)` — e.g. with EUR→USD 1.0856 and EUR→GBP 0.8542, one USD buys 0.8542 / 1.0856 = 0.7869 GBP. The cache file stores the parsed rates plus the ECB publication date, and is written atomically (temp file + rename).

## Requirements

- PHP 7.4+ (SimpleXML extension, enabled by default)
- Network access to `www.ecb.europa.eu` (or an injected fetcher)

## License

MIT — see [LICENSE](LICENSE).
