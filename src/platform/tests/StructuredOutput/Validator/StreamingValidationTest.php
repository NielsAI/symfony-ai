<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Tests\StructuredOutput\Validator;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\IncompleteJsonException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\ValidationException;
use Symfony\AI\Platform\PlainConverter;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\ObjectCompleteDelta;
use Symfony\AI\Platform\Result\Stream\Delta\PartialObjectDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\StructuredOutput\ResultConverter;
use Symfony\AI\Platform\StructuredOutput\Serializer;
use Symfony\AI\Platform\StructuredOutput\Validator\ValidatorResultConverter;
use Symfony\AI\Platform\Tests\Fixtures\StructuredOutput\UserWithConstraints;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class StreamingValidationTest extends TestCase
{
    public function testValidTerminalObjectPassesAndYieldsCompleteDelta()
    {
        $json = '{"id":7,"name":"Alice","isActive":true,"age":30}';
        $deltas = iterator_to_array($this->deferred($json, UserWithConstraints::class)->asObjectStream(), false);

        $complete = end($deltas);
        $this->assertInstanceOf(ObjectCompleteDelta::class, $complete);
        $this->assertInstanceOf(UserWithConstraints::class, $complete->object);
        $this->assertSame('Alice', $complete->object->name);
    }

    public function testInvalidTerminalObjectThrowsValidationException()
    {
        $this->expectException(ValidationException::class);

        $json = '{"id":-1,"name":"","age":-3}';
        iterator_to_array($this->deferred($json, UserWithConstraints::class)->asObjectStream(), false);
    }

    public function testValidationNeverRunsOnHalfBuiltPartials()
    {
        // Every partial snapshot before completion has name="" / id=0, which violates @NotBlank/@Positive.
        // The stream must still succeed because validation is strictly terminal — never on partials.
        $json = '{"id":7,"name":"Alice","age":30}';

        $partialsSeen = 0;
        foreach ($this->deferred($json, UserWithConstraints::class)->asObjectStream() as $delta) {
            if ($delta instanceof PartialObjectDelta) {
                ++$partialsSeen;
            }
        }

        $this->assertGreaterThan(0, $partialsSeen, 'expected loose partials to flow without validation');
    }

    public function testCodexOneShotWholeDocumentInSingleDelta()
    {
        $json = '{"id":1,"name":"Bob","isActive":false,"age":42}';

        $stream = new StreamResult((static function () use ($json): \Generator {
            yield new TextDelta($json);
        })());

        $deferred = $this->wrap($stream, UserWithConstraints::class);
        $deltas = iterator_to_array($deferred->asObjectStream(), false);

        $partials = array_filter($deltas, static fn (DeltaInterface $d): bool => $d instanceof PartialObjectDelta);
        $complete = end($deltas);

        $this->assertNotEmpty($partials);
        $this->assertInstanceOf(ObjectCompleteDelta::class, $complete);
        $this->assertInstanceOf(UserWithConstraints::class, $complete->object);
        $this->assertSame('Bob', $complete->object->name);
    }

    public function testRefusalStreamThrowsRuntimeException()
    {
        $this->expectException(RuntimeException::class);

        $stream = new StreamResult((static function (): \Generator {
            yield new ThinkingDelta('I cannot comply.');
        })());

        iterator_to_array($this->wrap($stream, UserWithConstraints::class)->asObjectStream(), false);
    }

    public function testTruncatedStreamThrowsIncompleteJsonException()
    {
        $this->expectException(IncompleteJsonException::class);

        iterator_to_array($this->deferred('{"id":7,"name":"Alice"', UserWithConstraints::class)->asObjectStream(), false);
    }

    /**
     * @param class-string $outputType
     */
    private function deferred(string $json, string $outputType): DeferredResult
    {
        $stream = new StreamResult((static function () use ($json): \Generator {
            foreach (mb_str_split($json, 1, '8bit') as $chunk) {
                yield new TextDelta($chunk);
            }
        })());

        return $this->wrap($stream, $outputType);
    }

    /**
     * @param class-string $outputType
     */
    private function wrap(StreamResult $stream, string $outputType): DeferredResult
    {
        $structured = new ResultConverter(new PlainConverter($stream), new Serializer(), $outputType);
        $validated = new ValidatorResultConverter($structured, $this->validator());

        return new DeferredResult($validated, new InMemoryRawResult(), ['stream' => true]);
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
