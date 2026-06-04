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
use Symfony\AI\Platform\StructuredOutput\Streaming\StreamCursor;
use Symfony\AI\Platform\StructuredOutput\Streaming\Suspended;

final class StreamCursorTest extends TestCase
{
    public function testPeekSuspendsWhenStarvedThenResumesAfterPush(): void
    {
        $cursor = new StreamCursor();
        $run = $cursor->peek();

        $this->assertTrue($run->valid());
        $this->assertInstanceOf(Suspended::class, $run->current());

        $cursor->push('a');
        $run->next();

        $this->assertFalse($run->valid());
        $this->assertSame('a', $run->getReturn());
    }

    public function testPeekDoesNotConsume(): void
    {
        $cursor = new StreamCursor();
        $cursor->push('ab');

        $this->assertSame('a', $this->drainToReturn($cursor->peek()));
        $this->assertSame('a', $this->drainToReturn($cursor->peek()));
        $this->assertTrue($cursor->hasBuffered());
    }

    public function testConsumeAdvances(): void
    {
        $cursor = new StreamCursor();
        $cursor->push('ab');

        $this->assertSame('a', $this->drainToReturn($cursor->consume()));
        $this->assertSame('b', $this->drainToReturn($cursor->consume()));
    }

    public function testReturnsNullAtEndOfStream(): void
    {
        $cursor = new StreamCursor();
        $cursor->end();

        $run = $cursor->peek();

        $this->assertFalse($run->valid());
        $this->assertNull($run->getReturn());
    }

    public function testEndUnblocksAStarvedPeek(): void
    {
        $cursor = new StreamCursor();
        $run = $cursor->peek();

        $this->assertInstanceOf(Suspended::class, $run->current());

        $cursor->end();
        $run->next();

        $this->assertFalse($run->valid());
        $this->assertNull($run->getReturn());
    }

    /**
     * @param \Generator<int, Suspended, mixed, string|null> $run
     */
    private function drainToReturn(\Generator $run): ?string
    {
        while ($run->valid()) {
            $run->next();
        }

        return $run->getReturn();
    }
}
