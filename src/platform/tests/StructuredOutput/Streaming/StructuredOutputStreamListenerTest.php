<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Tests\StructuredOutput\Streaming;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\IncompleteJsonException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\ObjectCompleteDelta;
use Symfony\AI\Platform\Result\Stream\Delta\PartialObjectDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\StructuredOutput\Serializer;
use Symfony\AI\Platform\StructuredOutput\Streaming\StructuredOutputStreamListener;
use Symfony\AI\Platform\Tests\Fixtures\StructuredOutput\ShippingOrder\ShippingOrder;
use Symfony\AI\Platform\Tests\Fixtures\StructuredOutput\ShippingOrder\ShippingPriority;

final class StructuredOutputStreamListenerTest extends TestCase
{
    public function testUntypedStreamEmitsPartialsThenSingleComplete(): void
    {
        $json = '{"recipe":"Soup","servings":4,"vegan":true}';
        $deltas = $this->stream($json, new StructuredOutputStreamListener());

        $partials = array_filter($deltas, static fn (DeltaInterface $d): bool => $d instanceof PartialObjectDelta);
        $completes = array_values(array_filter($deltas, static fn (DeltaInterface $d): bool => $d instanceof ObjectCompleteDelta));

        $this->assertNotEmpty($partials, 'expected at least one partial snapshot');
        $this->assertCount(1, $completes, 'expected exactly one terminal delta');
        $this->assertSame(['recipe' => 'Soup', 'servings' => 4, 'vegan' => true], $completes[0]->object);
    }

    public function testPartialSnapshotsAreLooseAndGrow(): void
    {
        $json = '{"a":1,"b":2}';
        $deltas = $this->stream($json, new StructuredOutputStreamListener());

        $snapshots = [];
        foreach ($deltas as $delta) {
            if ($delta instanceof PartialObjectDelta) {
                $snapshots[] = $delta->snapshot;
            }
        }

        $this->assertContains(['a' => 1], $snapshots);
        $this->assertSame(['a' => 1, 'b' => 2], end($snapshots));
    }

    public function testTerminalDeltaIsLastEmitted(): void
    {
        $deltas = $this->stream('{"a":1}', new StructuredOutputStreamListener());

        $this->assertInstanceOf(ObjectCompleteDelta::class, end($deltas));
    }

    public function testTypedStreamProducesValidatedDtoEqualToNonStream(): void
    {
        $json = '{"recipientName":"Jane Doe","priority":"express","deliverBy":"2026-03-15T10:00:00+00:00",'
            .'"address":{"street":"1 Main St","city":"Springfield","zip":"12345"},'
            .'"items":[{"name":"Widget","quantity":2,"price":9.99},{"name":"Gadget","quantity":1,"price":19.5}]}';

        $serializer = new Serializer();
        $listener = new StructuredOutputStreamListener(denormalizer: $serializer, outputType: ShippingOrder::class);

        $deltas = $this->stream($json, $listener);
        $complete = end($deltas);

        $this->assertInstanceOf(ObjectCompleteDelta::class, $complete);
        $this->assertInstanceOf(ShippingOrder::class, $complete->object);

        $expected = $serializer->deserialize($json, ShippingOrder::class, 'json');

        $this->assertEquals($expected, $complete->object);
        $this->assertSame('Jane Doe', $complete->object->recipientName);
        $this->assertSame(ShippingPriority::Express, $complete->object->priority);
        $this->assertCount(2, $complete->object->items);
    }

    public function testNonJsonDeltasPassThroughUntouched(): void
    {
        $thinking = new ThinkingDelta('reasoning...');
        $listener = new StructuredOutputStreamListener();

        $generator = (static function () use ($thinking): \Generator {
            yield $thinking;
            yield new TextDelta('{"a":1}');
        })();

        $deltas = iterator_to_array((new StreamResult($generator, [$listener]))->getContent(), false);

        $this->assertSame($thinking, $deltas[0], 'thinking delta must pass through unchanged');
        $this->assertInstanceOf(ObjectCompleteDelta::class, end($deltas));
    }

    public function testRefusalStreamWithoutJsonThrows(): void
    {
        $this->expectException(RuntimeException::class);

        $generator = (static function (): \Generator {
            yield new ThinkingDelta('I cannot help with that.');
        })();

        iterator_to_array((new StreamResult($generator, [new StructuredOutputStreamListener()]))->getContent(), false);
    }

    public function testTruncatedStreamThrows(): void
    {
        $this->expectException(IncompleteJsonException::class);

        $this->stream('{"a":1', new StructuredOutputStreamListener());
    }

    /**
     * Splits the JSON into single-byte TextDeltas, runs it through the listener-equipped StreamResult, and
     * returns the flattened list of emitted deltas.
     *
     * @return list<DeltaInterface>
     */
    private function stream(string $json, StructuredOutputStreamListener $listener): array
    {
        $chunks = mb_str_split($json, 1, '8bit');

        $generator = (static function () use ($chunks): \Generator {
            foreach ($chunks as $chunk) {
                yield new TextDelta($chunk);
            }
        })();

        return iterator_to_array((new StreamResult($generator, [$listener]))->getContent(), false);
    }
}
