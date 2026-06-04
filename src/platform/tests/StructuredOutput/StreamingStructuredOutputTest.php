<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Tests\StructuredOutput;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlainConverter;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\ObjectCompleteDelta;
use Symfony\AI\Platform\Result\Stream\Delta\PartialObjectDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\StructuredOutput\ResultConverter;
use Symfony\AI\Platform\StructuredOutput\Serializer;
use Symfony\AI\Platform\Tests\Fixtures\StructuredOutput\ShippingOrder\ShippingOrder;
use Symfony\AI\Platform\Tests\Fixtures\StructuredOutput\ShippingOrder\ShippingPriority;

/**
 * End-to-end: a streaming bridge result flowing through the StructuredOutput {@see ResultConverter} and the
 * public {@see DeferredResult::asObjectStream()} API.
 */
final class StreamingStructuredOutputTest extends TestCase
{
    public function testTypedObjectStreamYieldsPartialsThenValidatedDto()
    {
        $json = '{"recipientName":"Jane Doe","priority":"express","deliverBy":"2026-03-15T10:00:00+00:00",'
            .'"address":{"street":"1 Main St","city":"Springfield","zip":"12345"},'
            .'"items":[{"name":"Widget","quantity":2,"price":9.99}]}';

        $serializer = new Serializer();
        $deferred = $this->deferredFor($json, $serializer, ShippingOrder::class);

        $deltas = iterator_to_array($deferred->asObjectStream(), false);

        $partials = array_filter($deltas, static fn (DeltaInterface $d): bool => $d instanceof PartialObjectDelta);
        $complete = end($deltas);

        $this->assertNotEmpty($partials);
        $this->assertInstanceOf(ObjectCompleteDelta::class, $complete);

        $order = $complete->object;
        $this->assertInstanceOf(ShippingOrder::class, $order);
        $this->assertEquals($serializer->deserialize($json, ShippingOrder::class, 'json'), $order);
        $this->assertSame('Jane Doe', $order->recipientName);
        $this->assertSame(ShippingPriority::Express, $order->priority);
        $this->assertCount(1, $order->items);
    }

    public function testUntypedObjectStreamYieldsLooseArray()
    {
        $deferred = $this->deferredFor('{"a":1,"b":[2,3]}', new Serializer(), null);

        $deltas = iterator_to_array($deferred->asObjectStream(), false);
        $complete = end($deltas);

        $this->assertInstanceOf(ObjectCompleteDelta::class, $complete);
        $this->assertSame(['a' => 1, 'b' => [2, 3]], $complete->object);
    }

    public function testPlainStreamIsAdvertisedAsObjectDeltasOnly()
    {
        $deferred = $this->deferredFor('{"x":true}', new Serializer(), null);

        foreach ($deferred->asObjectStream() as $delta) {
            $this->assertTrue($delta instanceof PartialObjectDelta || $delta instanceof ObjectCompleteDelta);
        }
    }

    /**
     * @param class-string|null $outputType
     */
    private function deferredFor(string $json, Serializer $serializer, ?string $outputType): DeferredResult
    {
        $chunks = mb_str_split($json, 1, '8bit');

        $generator = (static function () use ($chunks): \Generator {
            foreach ($chunks as $chunk) {
                yield new TextDelta($chunk);
            }
        })();

        $innerConverter = new PlainConverter(new StreamResult($generator));
        $converter = new ResultConverter($innerConverter, $serializer, $outputType);

        return new DeferredResult($converter, new InMemoryRawResult(), ['stream' => true]);
    }
}
