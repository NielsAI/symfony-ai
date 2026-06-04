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
use Symfony\AI\Platform\StructuredOutput\Streaming\PartialTree;

final class PartialTreeTest extends TestCase
{
    public function testRootStartsUninitialized(): void
    {
        $tree = new PartialTree();

        $this->assertFalse($tree->isInitialized());
        $this->assertNull($tree->root());
    }

    public function testScalarRoot(): void
    {
        $tree = new PartialTree();
        $tree->set('', 42);

        $this->assertTrue($tree->isInitialized());
        $this->assertSame(42, $tree->root());
    }

    public function testAutoVivifiesNestedContainers(): void
    {
        $tree = new PartialTree();
        $tree->set('/recipe', []);
        $tree->set('/recipe/ingredients', []);
        $tree->set('/recipe/ingredients/0', 'salt');
        $tree->set('/recipe/ingredients/1', 'water');
        $tree->set('/recipe/name', 'soup');

        $this->assertSame([
            'recipe' => [
                'ingredients' => ['salt', 'water'],
                'name' => 'soup',
            ],
        ], $tree->root());
    }

    public function testNumericKeysBecomeIntegerIndexes(): void
    {
        $tree = new PartialTree();
        $tree->set('/0', 'a');
        $tree->set('/10', 'b');

        $root = $tree->root();

        $this->assertSame([0 => 'a', 10 => 'b'], $root);
        $this->assertArrayHasKey(0, $root);
        $this->assertArrayHasKey(10, $root);
    }

    public function testPointerEscaping(): void
    {
        $tree = new PartialTree();
        $tree->set('/'.PartialTree::escapeToken('a/b'), 1);
        $tree->set('/'.PartialTree::escapeToken('c~d'), 2);

        $this->assertSame(['a/b' => 1, 'c~d' => 2], $tree->root());
    }

    public function testResetClearsState(): void
    {
        $tree = new PartialTree();
        $tree->set('/a', 1);
        $tree->reset();

        $this->assertFalse($tree->isInitialized());
        $this->assertNull($tree->root());
    }
}
