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
 * The kind of structural progress the streaming decoder reports.
 *
 * @author Niels van Beuningen <nielsvanbeuningen@gmail.com>
 */
enum ProgressKind
{
    /** A scalar leaf (string, number, bool, null) was fully read and written into the partial tree. */
    case ScalarSet;

    /** An object or array was opened; an empty container shell now exists at the pointer. */
    case ContainerOpen;

    /** An object or array was fully closed; the subtree at the pointer is complete. */
    case ContainerClose;
}
