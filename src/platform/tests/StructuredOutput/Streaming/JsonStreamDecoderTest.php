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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\IncompleteJsonException;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\StructuredOutput\Streaming\JsonStreamDecoder;
use Symfony\AI\Platform\StructuredOutput\Streaming\JsonTokenizer;
use Symfony\AI\Platform\StructuredOutput\Streaming\ProgressEvent;
use Symfony\AI\Platform\StructuredOutput\Streaming\ProgressKind;
use Symfony\AI\Platform\StructuredOutput\Streaming\StreamCursor;

final class JsonStreamDecoderTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function jsonCorpus(): iterable
    {
        yield 'int' => ['123'];
        yield 'zero' => ['0'];
        yield 'negative int' => ['-42'];
        yield 'float' => ['3.14159'];
        yield 'negative float' => ['-0.5'];
        yield 'exponent' => ['1.5e3'];
        yield 'exponent signed' => ['6.022e-23'];
        yield 'big int overflow' => ['123456789012345678901234567890'];
        yield 'true' => ['true'];
        yield 'false' => ['false'];
        yield 'null' => ['null'];
        yield 'empty string' => ['""'];
        yield 'simple string' => ['"hello world"'];
        yield 'string with escapes' => ['"a\"b\\\\c\/d\n\t\r\b\f"'];
        yield 'unicode escape (BMP)' => ['"caf\u00e9"'];
        yield 'surrogate pair (emoji)' => ['"\ud83d\ude00"'];
        yield 'literal multibyte' => ['"café — 日本語"'];
        yield 'literal emoji' => ['"😀🚀"'];
        yield 'empty object' => ['{}'];
        yield 'empty array' => ['[]'];
        yield 'flat array' => ['[1,2,3]'];
        yield 'mixed array' => ['[1,"two",true,null,3.5]'];
        yield 'flat object' => ['{"a":1,"b":"two","c":true,"d":null}'];
        yield 'nested' => ['{"recipe":{"name":"Soup","ingredients":[{"name":"Salt","qty":2},{"name":"Water","qty":500}]},"vegan":true}'];
        yield 'array of objects' => ['[{"a":[1,2]},{"b":null},{"c":{"d":"e"}}]'];
        yield 'whitespace heavy' => ["{ \n\t \"a\" : 1 , \"b\" : [ 2 , 3 ] }"];
        yield 'numeric string keys' => ['{"0":"a","1":"b","10":"c"}'];
        yield 'key needing pointer escaping' => ['{"a/b":1,"c~d":2}'];
    }

    #[DataProvider('jsonCorpus')]
    public function testDecodesWholeDocument(string $json): void
    {
        $this->assertSame(json_decode($json, true, flags: \JSON_THROW_ON_ERROR), $this->decode([$json]));
    }

    #[DataProvider('jsonCorpus')]
    public function testSplitAtEveryByteOffset(string $json): void
    {
        $expected = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        for ($i = 0; $i <= \strlen($json); ++$i) {
            $chunks = [substr($json, 0, $i), substr($json, $i)];

            $this->assertSame($expected, $this->decode($chunks), \sprintf('split at offset %d of %d', $i, \strlen($json)));
        }
    }

    #[DataProvider('jsonCorpus')]
    public function testSplitIntoSingleBytes(string $json): void
    {
        $expected = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        $chunks = '' === $json ? [''] : mb_str_split($json, 1, '8bit');

        $this->assertSame($expected, $this->decode($chunks));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function truncatedCorpus(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'open object' => ['{'];
        yield 'object missing value' => ['{"a":'];
        yield 'object missing close' => ['{"a":1'];
        yield 'open array' => ['['];
        yield 'array trailing comma open' => ['[1,'];
        yield 'unterminated string' => ['"abc'];
        yield 'unterminated unicode' => ['"\u12'];
        yield 'lone high surrogate then eof' => ['"\ud83d'];
    }

    #[DataProvider('truncatedCorpus')]
    public function testTruncatedDocumentThrows(string $json): void
    {
        $this->expectException(IncompleteJsonException::class);

        $this->decode([$json]);
    }

    public function testInvalidEscapeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->decode(['"a\\xb"']);
    }

    public function testTrailingCommaInObjectThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->decode(['{"a":1,}']);
    }

    public function testReturnsIntForIntegerFormAndFloatForDecimals(): void
    {
        $this->assertSame(7, $this->decode(['7']));
        $this->assertSame(7.0, $this->decode(['7.0']));
        $this->assertIsFloat($this->decode(['123456789012345678901234567890']));
    }

    public function testEmitsFineGrainedProgressEvents(): void
    {
        $events = [];
        $decoder = new JsonStreamDecoder(new StreamCursor(), new JsonTokenizer());

        $decoder->decodeAll(
            mb_str_split('{"a":1,"b":[2,3]}', 1, '8bit'),
            static function (ProgressEvent $event) use (&$events): void {
                $events[] = [$event->pointer, $event->kind, $event->value];
            },
        );

        $this->assertSame([
            ['', ProgressKind::ContainerOpen, null],
            ['/a', ProgressKind::ScalarSet, 1],
            ['/b', ProgressKind::ContainerOpen, null],
            ['/b/0', ProgressKind::ScalarSet, 2],
            ['/b/1', ProgressKind::ScalarSet, 3],
            ['/b', ProgressKind::ContainerClose, null],
            ['', ProgressKind::ContainerClose, null],
        ], $events);
    }

    public function testPartialSnapshotsGrowMonotonicallyAndAreStable(): void
    {
        $snapshots = [];
        $decoder = new JsonStreamDecoder(new StreamCursor(), new JsonTokenizer());

        $decoder->decodeAll(
            mb_str_split('{"a":1,"b":[2,3]}', 1, '8bit'),
            static function (ProgressEvent $event) use (&$snapshots, $decoder): void {
                if (ProgressKind::ScalarSet === $event->kind) {
                    $snapshots[] = $decoder->getTree()->root();
                }
            },
        );

        // One snapshot per scalar leaf, in order, each a strictly more complete loose tree.
        $this->assertSame(['a' => 1], $snapshots[0]);
        $this->assertSame(['a' => 1, 'b' => [2]], $snapshots[1]);
        $this->assertSame(['a' => 1, 'b' => [2, 3]], $snapshots[2]);

        // The earliest snapshot must not have been mutated by later writes (copy-on-write stability).
        $this->assertSame(['a' => 1], $snapshots[0]);
        $this->assertSame(['a' => 1, 'b' => [2, 3]], $decoder->getTree()->root());
    }

    /**
     * @param list<string> $chunks
     */
    private function decode(array $chunks): mixed
    {
        $decoder = new JsonStreamDecoder(new StreamCursor(), new JsonTokenizer());

        return $decoder->decodeAll($chunks);
    }
}
