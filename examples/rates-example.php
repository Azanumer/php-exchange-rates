<?php

declare(strict_types=1);

require __DIR__ . '/../src/ExchangeRateException.php';
require __DIR__ . '/../src/ExchangeRates.php';

use Azanumer\ExchangeRates\ExchangeRates;

// A small sample of the real ECB feed, so this demo runs offline.
$sampleXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
  <Cube>
    <Cube time="2026-11-20">
      <Cube currency="USD" rate="1.0856"/>
      <Cube currency="GBP" rate="0.8542"/>
      <Cube currency="JPY" rate="162.34"/>
      <Cube currency="PKR" rate="303.12"/>
    </Cube>
  </Cube>
</gesmes:Envelope>
XML;

$rates = new ExchangeRates(sys_get_temp_dir() . '/rates-demo.json', 43200, function () use ($sampleXml) {
    return $sampleXml;
});

echo 'Rates date: ' . $rates->ratesDate() . PHP_EOL;
echo 'Currencies: ' . implode(', ', $rates->supportedCurrencies()) . PHP_EOL;
echo '100 USD in EUR: ' . $rates->convert(100, 'USD', 'EUR') . PHP_EOL;
echo '100 USD in GBP: ' . $rates->convert(100, 'USD', 'GBP') . PHP_EOL;
echo '1 USD = ' . $rates->getRate('USD', 'PKR') . ' PKR' . PHP_EOL;
echo 'Formatted: ' . $rates->format(49.99, 'USD') . ' / ' . $rates->format(49.99, 'EUR') . PHP_EOL;

// Unknown currency throws a clear exception.
try {
    $rates->convert(10, 'USD', 'XXX');
} catch (\Azanumer\ExchangeRates\ExchangeRateException $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
