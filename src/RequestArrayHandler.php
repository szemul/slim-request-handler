<?php

declare(strict_types=1);

namespace Szemul\SlimRequestHandler;

use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use InvalidArgumentException;
use Ramsey\Uuid\Exception\InvalidUuidStringException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Szemul\NotSetValue\NotSetValue;
use Szemul\RequestParameterErrorCollector\Enum\ParameterErrorReason;
use Szemul\RequestParameterErrorCollector\ParameterErrorCollectingInterface;
use Szemul\SlimRequestHandler\Enum\RequestValueType;
use ValueError;

class RequestArrayHandler
{
    /** @param array<string,mixed> $array */
    public function __construct(
        protected array $array,
        protected ?ParameterErrorCollectingInterface $errors,
        protected string $errorKeyPrefix,
        protected ?NotSetValue $defaultDefaultValue = null,
    ) {
    }

    public function getSingleValue(
        string $key,
        bool $isRequired,
        RequestValueType $type,
        callable $validationFunction = null,
        null|string|float|int|bool|NotSetValue $defaultValue = null,
    ): null|string|float|int|bool|NotSetValue {
        $default = $this->getDefaultValue($type, func_num_args() < 5 ? $this->defaultDefaultValue : $defaultValue);
        $result  = $default;

        if (!array_key_exists($key, $this->array)) {
            if ($isRequired) {
                $this->addError($key, ParameterErrorReason::MISSING);
            }
        } else {
            $result = $this->getTypedValue($type, $this->array[$key]);

            if (!empty($validationFunction) && !$validationFunction($result)) {
                $this->addError($key, ParameterErrorReason::INVALID);

                $result = $default;
            }
        }

        return $result;
    }

    /**
     * @param array<string|float|int|bool> $defaultValue
     *
     * @return NotSetValue|string[]|float[]|int[]|bool[]
     */
    public function getArrayValue(
        string $key,
        bool $isRequired,
        RequestValueType $elementType,
        callable $validationFunction = null,
        callable $elementValidationFunction = null,
        array $defaultValue = [],
    ): array|NotSetValue {
        $result = func_num_args() < 5 ? [] : $defaultValue;
        $exists = array_key_exists($key, $this->array);

        if (!$exists) {
            if ($isRequired) {
                $this->addError($key, ParameterErrorReason::MISSING);
            }
        } elseif (!is_array($this->array[$key])) {
            $this->addError($key, ParameterErrorReason::INVALID);
        } else {
            $result = [];

            foreach ($this->array[$key] as $index => $value) {
                $typedValue = $this->getTypedValue($elementType, $value);

                if (null !== $elementValidationFunction && !$elementValidationFunction($typedValue)) {
                    $this->addError($key . '.' . $index, ParameterErrorReason::INVALID);
                    continue;
                }

                $result[$index] = $typedValue;
            }

            if (!empty($validationFunction) && !$validationFunction($result)) {
                $this->addError($key, ParameterErrorReason::INVALID);

                $result = [];
            }
        }

        return $result;
    }

    public function getDateTime(
        string $key,
        bool $isRequired,
        bool $allowMicroseconds = false,
        NotSetValue|CarbonInterface|null $defaultValue = null,
    ): CarbonInterface|NotSetValue|null {
        $result = func_num_args() < 3 ? $this->defaultDefaultValue : $defaultValue;

        if (empty($this->array[$key])) {
            if ($isRequired) {
                $this->addError($key, ParameterErrorReason::MISSING);
            }
        } else {
            try {
                try {
                    $result = CarbonImmutable::createFromFormat(CarbonInterface::ATOM, $this->array[$key])->setMicrosecond(0);
                } catch (InvalidFormatException $e) {
                    if (!$allowMicroseconds) {
                        throw $e;
                    }
                    $result = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $this->array[$key]);
                }

                $result->setTimezone('UTC');
            } catch (InvalidArgumentException) {
                $this->addError($key, ParameterErrorReason::INVALID);
            }
        }

        return $result;
    }

    public function getDate(
        string $key,
        bool $isRequired,
        NotSetValue|CarbonInterface|null $defaultValue = null,
    ): CarbonInterface|NotSetValue|null {
        $result = func_num_args() < 3 ? $this->defaultDefaultValue : $defaultValue;

        if (empty($this->array[$key])) {
            if ($isRequired) {
                $this->addError($key, ParameterErrorReason::MISSING);
            }
        } else {
            try {
                $result = CarbonImmutable::createFromFormat('Y-m-d', $this->array[$key])->setTimezone('UTC')->startOfDay();
            } catch (InvalidArgumentException) {
                $this->addError($key, ParameterErrorReason::INVALID);
            }
        }

        return $result;
    }

    public function getEnum(
        string $key,
        string $enumClassName,
        bool $isRequired,
        ?NotSetValue $defaultValue = null,
    ): BackedEnum|NotSetValue|null {
        $result = func_num_args() < 4 ? $this->defaultDefaultValue : $defaultValue;

        if (empty($this->array[$key]) && $isRequired) {
            $this->addError($key, ParameterErrorReason::MISSING);
        } elseif (!empty($this->array[$key])) {
            try {
                $result = $enumClassName::from($this->array[$key]);
            } catch (ValueError $valueError) {
                $this->addError($key, ParameterErrorReason::INVALID);
            }
        }

        return $result;
    }

    public function getUuid(
        string $key,
        bool $isRequired,
        ?NotSetValue $defaultValue = null,
    ): UuidInterface|NotSetValue|null {
        $result = func_num_args() < 3 ? $this->defaultDefaultValue : $defaultValue;

        if (empty($this->array[$key]) && $isRequired) {
            $this->addError($key, ParameterErrorReason::MISSING);
        } elseif (!empty($this->array[$key])) {
            try {
                $result = Uuid::fromString($this->array[$key]);
            } catch (InvalidUuidStringException $exception) {
                $this->addError($key, ParameterErrorReason::INVALID);
            }
        }

        return $result;
    }

    /**
     * Returns TRUE if the specified date string is a valid date that matches the specified format
     */
    public function validateDateString(string $dateString, string $format): bool
    {
        try {
            $instance = CarbonImmutable::createFromFormat($format, $dateString);

            return $instance instanceof CarbonImmutable;
        } catch (InvalidArgumentException) { // @phpstan-ignore-line This exception can be thrown by carbon
            return false;
        }
    }

    public function getDefaultValue(
        RequestValueType $type,
        null|string|float|int|bool|NotSetValue $defaultValue = null,
    ): null|string|float|int|bool|NotSetValue {
        if ($defaultValue instanceof NotSetValue) {
            return $defaultValue;
        }

        return null === $defaultValue ? null : $this->getTypedValue($type, $defaultValue);
    }

    public function convertNotSetValue(mixed $value, mixed $defaultValue = null): mixed
    {
        return $value instanceof NotSetValue ? $defaultValue : $value;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function getTypedValue(RequestValueType $type, mixed $value): bool|int|float|string
    {
        return match ($type) {
            RequestValueType::TYPE_INT    => (int)$value,
            RequestValueType::TYPE_FLOAT  => (float)$value,
            RequestValueType::TYPE_STRING => (string)$value,
            RequestValueType::TYPE_BOOL   => (bool)$value,
            //@phpstan-ignore-next-line
            default                       => throw new InvalidArgumentException('Invalid type given'),
        };
    }

    protected function addError(string $key, ParameterErrorReason $reason): void
    {
        if (is_null($this->errors)) {
            return;
        }

        $this->errors->addParameterError($this->errorKeyPrefix . $key, $reason);
    }
}
