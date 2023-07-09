<?php

declare(strict_types=1);

namespace Szemul\SlimRequestHandler;

use JetBrains\PhpStorm\Pure;
use Szemul\NotSetValue\NotSetValue;
use Szemul\RequestParameterErrorCollector\ParameterErrorCollectingInterface;

class RequestArrayHandlerFactory
{
    /**
     * @param array<string|mixed> $data
     *
     * @codeCoverageIgnore
     */
    #[Pure]
    public function getHandler(
        array $data,
        ?ParameterErrorCollectingInterface $errors = null,
        string $errorKeyPrefix = '',
        ?NotSetValue $defaultDefaultValue = null,
    ): RequestArrayHandler {
        return new RequestArrayHandler($data, $errors, $errorKeyPrefix, $defaultDefaultValue);
    }
}
