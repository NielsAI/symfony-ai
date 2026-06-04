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

/**
 * Fine-grained, provider-agnostic progress reported by the streaming decoder as the loose ("DeepPartial")
 * tree is assembled. The stream listener turns these into {@see \Symfony\AI\Platform\Result\Stream\Delta\PartialObjectDelta}s.
 *
 * The pointer is an RFC 6901 JSON Pointer locating the touched node within the document ('' is the root).
 * For {@see ProgressKind::ScalarSet} the value carries the decoded scalar; for container events it is null
 * (read the current loose tree for the subtree).
 *
 * @author Niels van Beuningen <nielsvanbeuningen@gmail.com>
 */
final class ProgressEvent
{
    public function __construct(
        public readonly string $pointer,
        public readonly ProgressKind $kind,
        public readonly mixed $value = null,
    ) {
    }
}
