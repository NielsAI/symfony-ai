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
use Symfony\AI\Platform\Result\Stream\Delta\ChoiceDelta;
use Symfony\AI\Platform\Result\Stream\Delta\MetadataDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\StructuredOutput\Streaming\ChannelDemux;

final class ChannelDemuxTest extends TestCase
{
    private ChannelDemux $demux;

    protected function setUp(): void
    {
        $this->demux = new ChannelDemux();
    }

    public function testTextDeltaYieldsItsText(): void
    {
        $this->assertSame('{"a":', $this->demux->fragment(new TextDelta('{"a":')));
    }

    public function testToolInputDeltaYieldsPartialJson(): void
    {
        $this->assertSame('1}', $this->demux->fragment(new ToolInputDelta('id', 'name', '1}')));
    }

    public function testChoiceDeltaTargetsFirstChoice(): void
    {
        $delta = new ChoiceDelta([new TextDelta('{"x":1}'), new TextDelta('{"y":2}')]);

        $this->assertSame('{"x":1}', $this->demux->fragment($delta));
    }

    public function testEmptyChoiceDeltaIsSkipped(): void
    {
        $this->assertNull($this->demux->fragment(new ChoiceDelta([])));
    }

    public function testNonJsonDeltasAreSkipped(): void
    {
        $this->assertNull($this->demux->fragment(new ThinkingDelta('let me think')));
        $this->assertNull($this->demux->fragment(new MetadataDelta('k', 'v')));
    }
}
