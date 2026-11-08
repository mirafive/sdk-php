<?php

declare(strict_types=1);

namespace MiraFive\Internal;

/** @internal A property value that is a scalar, so that `null` can be told apart from "not a scalar". */
final readonly class ScalarValue
{
    public function __construct(public string|int|float|bool|null $value) {}
}
