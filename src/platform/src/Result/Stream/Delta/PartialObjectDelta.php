<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Result\Stream\Delta;

/**
 * A loose, intermediate snapshot of a structured output while it is still streaming.
 *
 * This is the "during the stream" tier of the two-tier data contract: an untyped DeepPartial structure with
 * no validation. The fully typed and validated object is delivered separately at stream termination via
 * {@see ObjectCompleteDelta}.
 *
 * @author Niels van Beuningen <nielsvanbeuningen@gmail.com>
 */
final class PartialObjectDelta implements DeltaInterface
{
    /**
     * @param array<string, mixed>|list<mixed> $snapshot the loose tree decoded so far
     * @param string                           $pointer  RFC 6901 JSON Pointer of the node that just changed ('' is the root)
     * @param bool                             $final    false while the changed node may still grow (e.g. a not-yet-terminated number)
     */
    public function __construct(
        public readonly array $snapshot,
        public readonly string $pointer,
        public readonly bool $final = true,
    ) {
    }
}
