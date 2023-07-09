<?php

declare(strict_types=1);

namespace Szemul\SlimRequestHandler\Test;

use Carbon\Carbon;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;
use Szemul\NotSetValue\NotSetValue;
use Szemul\RequestParameterErrorCollector\Enum\ParameterErrorReason;
use Szemul\RequestParameterErrorCollector\ParameterErrorCollectingInterface;
use Szemul\SlimErrorHandlerBridge\Exception\HttpUnprocessableEntityException;
use Szemul\SlimRequestHandler\Enum\RequestValueType;
use Szemul\SlimRequestHandler\RequestArrayHandler;
use Szemul\SlimRequestHandler\Test\Stub\EnumStub;

class RequestArrayHandlerTest extends TestCase
{
    private const ERROR_KEY_PREFIX = 'test.';

    private ParameterErrorCollectingInterface $errorCollector;

    protected function setUp(): void
    {
        parent::setUp();

        // @phpstan-ignore-next-line
        $this->errorCollector = new HttpUnprocessableEntityException(Mockery::mock(ServerRequestInterface::class));
    }

    public function testErrorHandlingWithNoErrorHandler_shouldDoNothing(): void
    {
        $sut = new RequestArrayHandler([], null, '');

        $result = $sut->getSingleValue('missing', true, RequestValueType::TYPE_STRING);

        $this->assertNull($result);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function convertNotSetValueProvider(): array
    {
        return [
            ['string', 'string', null],
            [null, new NotSetValue(), null],
            ['string', new NotSetValue(), 'string'],
        ];
    }

    /**
     * @dataProvider convertNotSetValueProvider
     */
    public function testConvertNotSetValue(?string $expectedResult, mixed $value, mixed $default): void
    {
        $sut = $this->getSut([]);

        $result = $sut->convertNotSetValue($value, $default);

        $this->assertSame($expectedResult, $result);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function singleValueProvider(): array
    {
        $data = [
            'string' => 'foo',
            'int'    => 123,
        ];

        return [
            [$data, 0, 'string', RequestValueType::TYPE_INT],
            [$data, 0.0, 'string', RequestValueType::TYPE_FLOAT],
            [$data, true, 'string', RequestValueType::TYPE_BOOL],
            [$data, '123', 'int', RequestValueType::TYPE_STRING],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @dataProvider singleValueProvider
     */
    public function testGetSingleValue_shouldConvertToDesiredType(array $data, mixed $expectedValue, string $parameterName, RequestValueType $type): void
    {
        $sut = $this->getSut($data);

        $result = $sut->getSingleValue($parameterName, true, $type);

        $this->assertSame($expectedValue, $result);
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetSingleValueWhenNotRequiredParamDoesNotExist_shouldReturnNull(): void
    {
        $sut = $this->getSut([]);

        $result = $sut->getSingleValue('missing', false, RequestValueType::TYPE_STRING);

        $this->assertNull($result);
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetSingleValueWhenNotSetValueGivenAsDefaultDefault_shouldReturnNotSetValue(): void
    {
        $notSetValue = new NotSetValue();
        $sut         = $this->getSut([], $notSetValue);

        $result = $sut->getSingleValue('missing', false, RequestValueType::TYPE_STRING);

        $this->assertSame($notSetValue, $result);
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function getSingleValueDefaultProvider(): array
    {
        return [
            [new NotSetValue()],
            ['default'],
        ];
    }

    /**
     * @dataProvider getSingleValueDefaultProvider
     */
    public function testGetSingleValueWhenNotRequiredParamDoesNotExistAndDefaultGiven_shouldReturnDefault(mixed $defaultValue): void
    {
        $sut = $this->getSut([]);

        $result = $sut->getSingleValue('missing', false, RequestValueType::TYPE_STRING, defaultValue: $defaultValue);

        $this->assertSame($defaultValue, $result);
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetSingleValueWhenRequiredParameterMissing_shouldSetError(): void
    {
        $sut = $this->getSut([]);

        $result = $sut->getSingleValue('missing', true, RequestValueType::TYPE_STRING);

        $this->assertNull($result);
        $this->assertCollectedErrorsMatch([self::ERROR_KEY_PREFIX . 'missing' => ParameterErrorReason::MISSING->value]);
    }

    public function testGetSingleValueWhenValidationFunctionFails_shouldSetError(): void
    {
        $sut = $this->getSut(['invalid' => 'string']);

        $validationFunction = function (string $value) {
            $this->assertSame($value, 'string');

            return false;
        };

        $result = $sut->getSingleValue('invalid', true, RequestValueType::TYPE_STRING, $validationFunction, null);

        $this->assertNull($result);
        $this->assertCollectedErrorsMatch([self::ERROR_KEY_PREFIX . 'invalid' => ParameterErrorReason::INVALID->value]);
    }

    public function testGetArrayValueWhenIntTypeGiven_shouldCastElementsToInt(): void
    {
        $sut = $this->getSut([
            'array' => ['foo' => '123bar'],
        ]);

        $result = $sut->getArrayValue('array', true, RequestValueType::TYPE_INT);

        $this->assertSame(['foo' => 123], $result);
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetArrayValueWhenNonRequiredIsMissing_shouldReturnEmptyArray(): void
    {
        $sut = $this->getSut([]);

        $result = $sut->getArrayValue('missing', false, RequestValueType::TYPE_STRING);

        $this->assertEquals([], $result);
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetArrayValueWhenRetrievingMissingWithDefaultValue_shouldReturnDefaultValue(): void
    {
        $defaultValue = ['foo' => 'bar'];
        $sut          = $this->getSut([]);

        $result = $sut->getArrayValue('missing', false, RequestValueType::TYPE_STRING, defaultValue: $defaultValue);

        $this->assertSame($defaultValue, $result);
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetArrayValueWhenValidationFunctionGiven_shouldUseFunctionToValidateArray(): void
    {
        $data               = [
            'array' => ['foo' => 'bar'],
        ];
        $validationFunction = function (array $value): bool {
            $this->assertSame(['foo' => 'bar'], $value);

            return false;
        };

        $result = $this->getSut($data)->getArrayValue('array', true, RequestValueType::TYPE_STRING, $validationFunction);

        $this->assertEmpty($result);
        $this->assertCollectedErrorsMatch(['test.array' => ParameterErrorReason::INVALID->value]);
    }

    public function testGetArrayValueWhenElementValidationFunctionGiven_shouldUseFunctionToValidateElements(): void
    {
        $data                      = [
            'array' => ['foo' => 'bar'],
        ];
        $elementValidationFunction = function ($value): bool {
            $this->assertSame('bar', $value);

            return false;
        };

        $result = $this->getSut($data)->getArrayValue('array', true, RequestValueType::TYPE_STRING, null, $elementValidationFunction);

        $this->assertEmpty($result);
        $this->assertCollectedErrorsMatch(['test.array.foo' => ParameterErrorReason::INVALID->value]);
    }

    public function testGetDateTime_shouldMapToCarbon(): void
    {
        $date = '2021-01-01T00:00:00Z';
        $sut  = $this->getSut(['date' => $date]);

        $result = $sut->getDateTime('date', true)->toIso8601ZuluString();

        $this->assertSame($date, $result);
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetDateTimeWhenMicroSecondsGiven_shouldMapToCarbon(): void
    {
        $date = '2021-01-01T00:00:00.000000Z';
        $sut  = $this->getSut(['date' => $date]);

        $result = $sut->getDateTime('date', true, true);

        $this->assertSame($date, $result->toIso8601ZuluString('microsecond'));
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetDateTimeWhenRequiredButNotFound_shouldSetError(): void
    {
        $sut = $this->getSut([]);

        $result = $sut->getDateTime('missing', true);

        $this->assertNull($result);
        $this->assertCollectedErrorsMatch([self::ERROR_KEY_PREFIX . 'missing' => ParameterErrorReason::MISSING->value]);
    }

    public function testGetDateTimeWhenDefaultGiven_shouldReturnGivenDefault(): void
    {
        $default = Carbon::now();
        $sut     = $this->getSut([]);

        $result = $sut->getDateTime('missing', false, false, $default);

        $this->assertSame($default, $result);
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetDateTimeWhenNotSetValueSetAsDefault_shouldReturnNotSetValue(): void
    {
        $notSetValue = new NotSetValue();
        $sut         = $this->getSut([], $notSetValue);

        $result = $sut->getDateTime('missing', false);

        $this->assertSame($notSetValue, $result);
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetDateWhenValidGiven_shouldReturnProperCarbon(): void
    {
        $date = '2021-01-01';
        $sut  = $this->getSut(['date' => $date]);

        $result = $sut->getDate('date', true);

        $this->assertSame('2021-01-01T00:00:00Z', $result->toIso8601ZuluString());
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetDateWhenNotPresentAndNotRequired_shouldReturnNull(): void
    {
        $sut = $this->getSut([]);

        $result = $sut->getDate('date', false);

        $this->assertNull($result);
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetDateWhenNotPresentAndNotRequiredAndDefaultGiven_shouldReturnGivenDefault(): void
    {
        $default = Carbon::now();
        $sut     = $this->getSut([]);

        $result = $sut->getDate('date', false, $default);

        $this->assertSame($default, $result);
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetDateWhenNotSetValueGivenAsDefaultDefault_shouldReturnNotSetValue(): void
    {
        $notSetValue = new NotSetValue();
        $sut         = $this->getSut([], $notSetValue);

        $result = $sut->getDate('date', false);

        $this->assertSame($notSetValue, $result);
    }

    public function testGetDateWhenNotPresentAndRequired_shouldSetError(): void
    {
        $sut = $this->getSut([]);

        $result = $sut->getDate('date', true);

        $this->assertNull($result);
        $this->assertCollectedErrorsMatch([self::ERROR_KEY_PREFIX . 'date' => ParameterErrorReason::MISSING->value]);
    }

    public function testGetEnumWhenNotPresentAndNotRequiredAndDefaultGiven_shouldReturnNull(): void
    {
        $sut     = $this->getSut([]);
        $default = new NotSetValue();

        $result = $sut->getEnum('enum', EnumStub::class, false, $default);

        $this->assertSame($default, $result);
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetEnumWhenNotPresentAndNotRequired_shouldReturnNotSetValue(): void
    {
        $sut = $this->getSut([]);

        $result = $sut->getEnum('enum', EnumStub::class, false);

        $this->assertNull($result);
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetEnumWhenRequiredNotPresent_shouldReturnNotSetValueAndSetError(): void
    {
        $sut = $this->getSut([]);

        $result = $sut->getEnum('enum', EnumStub::class, true);

        $this->assertNull($result);
        $this->assertCollectedErrorsMatch([self::ERROR_KEY_PREFIX . 'enum' => ParameterErrorReason::MISSING->value]);
    }

    public function testGetEnumWhenInvalid_shouldReturnNotSetValueAndSetError(): void
    {
        $sut = $this->getSut(['enum' => 'invalid']);

        $result = $sut->getEnum('enum', EnumStub::class, true);

        $this->assertNull($result);
        $this->assertCollectedErrorsMatch([self::ERROR_KEY_PREFIX . 'enum' => ParameterErrorReason::INVALID->value]);
    }

    public function testGetEnum_shouldReturnEnumObject(): void
    {
        $enum = EnumStub::FIRST;
        $sut  = $this->getSut(['enum' => $enum->value]);

        $result = $sut->getEnum('enum', EnumStub::class, true);

        $this->assertSame($enum, $result);
    }

    public function testGetUuidWhenNotPresentAndNotRequiredAndDefaultGiven_shouldReturnDefault(): void
    {
        $sut     = $this->getSut([]);
        $default = new NotSetValue();

        $result = $sut->getUuid('uuid', false, $default);

        $this->assertSame($default, $result);
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetUuidWhenNotPresentAndNotRequired_shouldReturnNull(): void
    {
        $sut = $this->getSut([]);

        $result = $sut->getUuid('uuid', false);

        $this->assertNull($result);
        $this->assertFalse($this->errorCollector->hasParameterErrors());
    }

    public function testGetUuidWhenRequiredNotPresent_shouldReturnNullValueAndSetError(): void
    {
        $sut = $this->getSut([]);

        $result = $sut->getUuid('uuid', true);

        $this->assertNull($result);
        $this->assertCollectedErrorsMatch([self::ERROR_KEY_PREFIX . 'uuid' => ParameterErrorReason::MISSING->value]);
    }

    public function testGetUuidWhenInvalid_shouldReturnNotSetValueAndSetError(): void
    {
        $sut = $this->getSut(['uuid' => 'invalid']);

        $result = $sut->getUuid('uuid', true);

        $this->assertNull($result);
        $this->assertCollectedErrorsMatch([self::ERROR_KEY_PREFIX . 'uuid' => ParameterErrorReason::INVALID->value]);
    }

    public function testGetUuid_shouldReturnUuidInterface(): void
    {
        $uuid = Uuid::uuid4();
        $sut  = $this->getSut(['uuid' => $uuid->toString()]);

        $result = $sut->getUuid('uuid', true);

        $this->assertSame($uuid->toString(), $result->toString());
    }

    public function testValidateDateString(): void
    {
        $sut = $this->getSut([]);

        $this->assertTrue($sut->validateDateString('2021-01-01', 'Y-m-d'));
        $this->assertTrue($sut->validateDateString('2021-01-01T00:00:00Z', DATE_ATOM));
        $this->assertFalse($sut->validateDateString('foo', 'Y-m-d'));
        $this->assertFalse($sut->validateDateString('foo', DATE_ATOM));
    }

    /**
     * @param array<string,string> $expected
     */
    private function assertCollectedErrorsMatch(array $expected): void
    {
        $this->assertTrue($this->errorCollector->hasParameterErrors());
        $this->assertEquals($expected, json_decode(json_encode($this->errorCollector->getParameterErrors()), true));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getSut(array $data, ?NotSetValue $defaultDefault = null): RequestArrayHandler
    {
        return new RequestArrayHandler($data, $this->errorCollector, self::ERROR_KEY_PREFIX, $defaultDefault);
    }
}
