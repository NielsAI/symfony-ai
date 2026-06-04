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
 * The terminal tier of the two-tier data contract: the fully assembled structured output, emitted once the
 * stream ends. When a target type was requested this carries the typed, validated DTO; otherwise it carries
 * the decoded associative array.
 *
 * @author Niels van Beuningen <nielsvanbeuningen@gmail.com>
 */
final class ObjectCompleteDelta implements DeltaInterface
{
    /**
     * @param object|array<string, mixed> $object
     */
    public function __construct(
        public readonly object|array $object,
    ) {
    }
}
