<?php

declare(strict_types=1);

namespace Azanumer\ExchangeRates;

/**
 * Thrown when rates cannot be fetched, parsed or a currency is unknown.
 */
class ExchangeRateException extends \RuntimeException
{
}
