<?php

declare(strict_types=1);

namespace MiraFive\Internal;

use JsonException;
use MiraFive\MiraError;

/** @internal */
final class Json
{
    public const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @throws MiraError
     */
    public static function encode(mixed $value): string
    {
        try {
            return json_encode($value, self::FLAGS);
        } catch (JsonException $exception) {
            throw new MiraError('cannot encode as JSON: '.$exception->getMessage(), previous: $exception);
        }
    }
}
