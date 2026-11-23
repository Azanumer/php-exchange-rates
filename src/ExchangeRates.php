<?php

declare(strict_types=1);

namespace Azanumer\ExchangeRates;

/**
 * Dependency-free exchange-rate client backed by the European Central
 * Bank's free daily reference rates (eurofxref-daily.xml).
 *
 * The ECB publishes ~30 currencies quoted against the euro, once per
 * working day around 16:00 CET. Rates are cached to a local JSON file so
 * a page that converts prices does not hit the ECB on every request.
 *
 *     $rates = new ExchangeRates();
 *     $rates->convert(100, 'USD', 'EUR');   // e.g. 92.41
 *     $rates->getRate('GBP', 'USD');        // cross rate via EUR
 *     $rates->format(49.99, 'USD');         // $49.99
 *
 * A custom fetcher can be injected for tests or for a different source:
 *
 *     $rates = new ExchangeRates('/tmp/rates.json', 43200, function () {
 *         return file_get_contents('https://example.com/rates.xml');
 *     });
 *
 * PHP 7.4+.
 */
class ExchangeRates
{
    /** ECB daily reference rates feed (free, no key required). */
    public const ECB_FEED = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    /** @var string Path of the JSON cache file. */
    private $cacheFile;

    /** @var int Cache lifetime in seconds (default 12 hours). */
    private $cacheTtl;

    /** @var callable|null Optional fetcher returning the raw ECB XML string. */
    private $fetcher;

    /** @var array|null In-memory rates keyed by ISO code, EUR = 1.0. */
    private $rates;

    /** @var string|null Date of the rates (ECB publication date, Y-m-d). */
    private $ratesDate;

    /**
     * @param string        $cacheFile Path of the JSON cache file.
     * @param int           $cacheTtl  Cache lifetime in seconds.
     * @param callable|null $fetcher   Optional callable returning raw ECB XML.
     */
    public function __construct(string $cacheFile = '', int $cacheTtl = 43200, ?callable $fetcher = null)
    {
        $this->cacheFile = '' !== $cacheFile ? $cacheFile : sys_get_temp_dir() . '/ecb-exchange-rates.json';
        $this->cacheTtl  = $cacheTtl;
        $this->fetcher   = $fetcher;
    }

    /**
     * Convert an amount from one currency to another.
     *
     * @param float  $amount Amount in $from.
     * @param string $from   ISO 4217 code, e.g. USD.
     * @param string $to     ISO 4217 code, e.g. EUR.
     * @return float Amount in $to, rounded to 4 decimals.
     */
    public function convert(float $amount, string $from, string $to): float
    {
        return round($amount * $this->getRate($from, $to), 4);
    }

    /**
     * Exchange rate: how many units of $to one unit of $from buys.
     * Cross rates are computed through EUR (the ECB's base currency).
     */
    public function getRate(string $from, string $to): float
    {
        $from = strtoupper(trim($from));
        $to   = strtoupper(trim($to));

        if ($from === $to) {
            return 1.0;
        }

        $rates = $this->rates();

        if (!isset($rates[$from])) {
            throw new ExchangeRateException("Unknown currency: {$from}");
        }
        if (!isset($rates[$to])) {
            throw new ExchangeRateException("Unknown currency: {$to}");
        }

        // ECB quotes "1 EUR = X currency", so EUR-per-unit is 1 / rate.
        return $rates[$to] / $rates[$from];
    }

    /** All known rates (units per 1 EUR), including EUR itself. */
    public function allRates(): array
    {
        return $this->rates();
    }

    /** ISO codes this client knows about (from the last fetch). */
    public function supportedCurrencies(): array
    {
        return array_keys($this->rates());
    }

    /** Publication date of the loaded rates (Y-m-d), or null if unknown. */
    public function ratesDate(): ?string
    {
        $this->rates();
        return $this->ratesDate;
    }

    /**
     * Format an amount with the currency's symbol, e.g. $49.99 or €49.99.
     * Unknown currencies fall back to "49.99 USD".
     */
    public function format(float $amount, string $currency, int $decimals = 2): string
    {
        $currency = strtoupper(trim($currency));
        $symbols  = self::symbols();
        $number   = number_format($amount, $decimals, '.', ',');

        if (isset($symbols[$currency])) {
            return $symbols[$currency] . $number;
        }

        return $number . ' ' . $currency;
    }

    /** Currency symbols for the most common codes. */
    public static function symbols(): array
    {
        return array(
            'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥',
            'CHF' => 'CHF ', 'AUD' => 'A$', 'CAD' => 'C$', 'NZD' => 'NZ$',
            'CNY' => '¥', 'INR' => '₹', 'PKR' => 'Rs ', 'BRL' => 'R$',
            'SEK' => 'kr ', 'NOK' => 'kr ', 'DKK' => 'kr ', 'PLN' => 'zł ',
            'CZK' => 'Kč ', 'HUF' => 'Ft ', 'ILS' => '₪', 'KRW' => '₩',
            'MXN' => 'MX$', 'ZAR' => 'R', 'TRY' => '₺', 'AED' => 'AED ',
            'SAR' => 'SR ', 'THB' => '฿', 'SGD' => 'S$', 'HKD' => 'HK$',
        );
    }

    /** Force a fresh fetch on the next call (ignores the cache). */
    public function refresh(): void
    {
        $this->rates     = null;
        $this->ratesDate = null;
        if (is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    /**
     * Load rates: memory -> fresh cache file -> ECB feed.
     *
     * @return array Rates keyed by ISO code, EUR = 1.0.
     */
    private function rates(): array
    {
        if (null !== $this->rates) {
            return $this->rates;
        }

        $cached = $this->readCache();
        if (null !== $cached) {
            $this->rates     = $cached['rates'];
            $this->ratesDate = $cached['date'];
            return $this->rates;
        }

        $xml = $this->fetchFeed();
        $parsed = self::parseEcbXml($xml);

        $this->rates     = $parsed['rates'];
        $this->ratesDate = $parsed['date'];
        $this->writeCache($parsed);

        return $this->rates;
    }

    /** Fetch the raw ECB XML (custom fetcher, cURL, or file_get_contents). */
    private function fetchFeed(): string
    {
        if (null !== $this->fetcher) {
            $xml = call_user_func($this->fetcher);
            if (!is_string($xml) || '' === trim($xml)) {
                throw new ExchangeRateException('Exchange-rate fetcher returned no data.');
            }
            return $xml;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init(self::ECB_FEED);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_USERAGENT      => 'php-exchange-rates/1.0',
            ));
            $xml = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if (false === $xml || '' === trim((string) $xml)) {
                throw new ExchangeRateException('Could not fetch ECB rates: ' . $err);
            }
            return (string) $xml;
        }

        $xml = @file_get_contents(self::ECB_FEED);
        if (false === $xml || '' === trim($xml)) {
            throw new ExchangeRateException('Could not fetch ECB rates (no cURL, file_get_contents failed).');
        }
        return $xml;
    }

    /**
     * Parse ECB eurofxref XML into ['date' => Y-m-d, 'rates' => [...]].
     * EUR is always present with rate 1.0.
     */
    public static function parseEcbXml(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (false === $doc) {
            $detail = $errors ? trim($errors[0]->message) : 'malformed XML';
            throw new ExchangeRateException('Could not parse ECB rates XML: ' . $detail);
        }

        // The feed nests Cube elements: <Cube><Cube time="..."><Cube currency="USD" rate="1.08"/>...
        $rates = array('EUR' => 1.0);
        $date  = null;

        foreach ($doc->Cube->Cube as $day) {
            $date = (string) $day['time'];
            foreach ($day->Cube as $cube) {
                $code = strtoupper((string) $cube['currency']);
                $rate = (float) $cube['rate'];
                if ('' !== $code && $rate > 0) {
                    $rates[$code] = $rate;
                }
            }
            break; // Only the latest day.
        }

        if (count($rates) < 2) {
            throw new ExchangeRateException('ECB rates XML contained no currency rates.');
        }

        return array('date' => $date, 'rates' => $rates);
    }

    /** Read the cache file when it exists and is still fresh. */
    private function readCache(): ?array
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }

        $raw = @file_get_contents($this->cacheFile);
        if (false === $raw) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['rates']) || !is_array($data['rates'])) {
            return null;
        }

        $mtime = filemtime($this->cacheFile);
        if (false === $mtime || (time() - $mtime) > $this->cacheTtl) {
            return null; // Stale.
        }

        return array(
            'date'  => $data['date'] ?? null,
            'rates' => $data['rates'],
        );
    }

    /** Write rates to the cache file atomically (best effort). */
    private function writeCache(array $parsed): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }

        $tmp = $this->cacheFile . '.' . getmypid() . '.tmp';
        $ok  = @file_put_contents(
            $tmp,
            json_encode(array('date' => $parsed['date'], 'rates' => $parsed['rates'])),
            LOCK_EX
        );
        if (false !== $ok) {
            @rename($tmp, $this->cacheFile);
        } else {
            @unlink($tmp);
        }
    }
}
