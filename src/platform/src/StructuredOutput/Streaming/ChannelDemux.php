<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\StructuredOutput\Streaming;

use Symfony\AI\Platform\Result\Stream\Delta\ChoiceDelta;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;

/**
 * The single provider-aware seam: maps a bridge {@see DeltaInterface} onto the canonical, JSON-bearing string
 * fragment the parsing engine consumes — or null for deltas that carry no structured-output JSON (thinking,
 * metadata, binary, tool start/stop, …), which the listener passes through untouched.
 *
 * This is what keeps {@see StreamCursor}/{@see JsonTokenizer}/{@see JsonStreamDecoder} provider-agnostic: they
 * never see a `TextDelta` or `ToolInputDelta`, only the strings extracted here.
 *
 * @author Niels van Beuningen <nielsvanbeuningen@gmail.com>
 */
final class ChannelDemux
{
    public function fragment(DeltaInterface $delta): ?string
    {
        if ($delta instanceof TextDelta) {
            return $delta->getText();
        }

        if ($delta instanceof ToolInputDelta) {
            return $delta->getPartialJson();
        }

        if ($delta instanceof ChoiceDelta) {
            // v1: structured output streams a single document; target the first choice only.
            $deltas = $delta->getDeltas();

            if ([] === $deltas) {
                return null;
            }

            return $this->fragment($deltas[array_key_first($deltas)]);
        }

        return null;
    }
}
