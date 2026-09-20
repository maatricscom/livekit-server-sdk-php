<?php

declare(strict_types=1);

namespace LiveKit\Tests;

use LiveKit\LiveKitAPI;
use LiveKit\Tests\Support\TestCase;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every PHP example in README.md, checked against the code it describes.
 *
 * Documentation is the one part of a package nothing else verifies: a snippet can
 * go on naming a class that was renamed, a parameter that was dropped, or a
 * constant that never existed, and the whole suite stays green. Someone finds out
 * by pasting it.
 *
 * This does not execute the examples -- most need a server. It parses each one and
 * resolves what it can: that the block is valid PHP at all, that every imported and
 * constructed class exists, that every named argument matches a real parameter, that
 * every `Class::CONSTANT` is defined, and that a method called on a receiver whose
 * type is knowable exists on it.
 */
final class ReadmeCodeBlocksTest extends TestCase
{
    /**
     * Blocks that are deliberately fragments of a larger listing: a class body
     * continued from the block above, or a test method that relies on the enclosing
     * file's imports. `ReadmeExamplesTest` runs that example for real.
     *
     * Keyed by the first two non-empty lines, so a block moving in the file does
     * not silently widen the exemption and a bare `try {` does not exempt everything.
     *
     * @var list<array{string, string}>
     */
    private const array FRAGMENTS = [
        // Two further catch bodies for the try shown earlier in the same section, under
        // that block's imports. Repeating three use statements to vary one branch would
        // be noise in prose.
        ['try {', '    $livekit->room->deleteRoom(\'my-room\');'],
        ['try {', '    $livekit->sip->createSipParticipant(/* ... */);'],
        // The dependency-injection example: a class body, and the test method that
        // exercises it. Both rely on the enclosing file's imports, and ReadmeExamplesTest
        // runs that example for real.
        ['use LiveKit\\Contracts\\RoomServiceClientInterface;', 'final readonly class RoomProvisioner'],
        ['$rooms = $this->createStub(RoomServiceClientInterface::class);', '$rooms->method(\'createRoom\')->willReturn(new Room()->setSid(\'RM_test\'));'],
    ];

    /** @return iterable<string, array{string, int}> */
    public static function phpBlocks(): iterable
    {
        $md = (string) file_get_contents(dirname(__DIR__) . '/README.md');

        preg_match_all('/^```php\n(.*?)^```/sm', $md, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as $i => [$code, $offset]) {
            $line = substr_count(substr($md, 0, (int) $offset), "\n") + 1;
            yield sprintf('block %d (README.md:%d)', $i + 1, $line) => [$code, $line];
        }
    }

    #[DataProvider('phpBlocks')]
    public function test_a_readme_example_is_valid_php(string $code, int $line): void
    {
        self::assertNotNull(
            self::parse($code),
            sprintf('README.md:%d is not parseable PHP, so it cannot be pasted anywhere.', $line)
        );
    }

    #[DataProvider('phpBlocks')]
    public function test_a_readme_example_names_things_that_exist(string $code, int $line): void
    {
        $ast = self::parse($code);
        self::assertNotNull($ast);

        // Keyed on the first two non-empty lines. One is not distinctive enough:
        // `try {` alone would quietly exempt any block that happens to start with it.
        $significant = array_slice(array_values(array_filter(
            array_map(rtrim(...), explode("\n", trim($code))),
            static fn (string $line): bool => trim($line) !== '',
        )), 0, 2);

        $problems = self::inspect($ast, in_array($significant, self::FRAGMENTS, true));

        self::assertSame([], $problems, sprintf(
            "README.md:%d refers to things that do not exist:\n  - %s",
            $line,
            implode("\n  - ", $problems)
        ));
    }

    /**
     * A sentinel that does not live under the prefix it stands for can never be
     * found, so the prefix would be exempt forever and the `use` check silently off
     * for it. That is the failure this cannot be allowed to have: it looks exactly
     * like a suite that passes.
     */
    public function test_every_optional_package_is_vouched_for_from_inside_itself(): void
    {
        foreach (self::OPTIONAL_PACKAGES as $prefix => $sentinel) {
            self::assertStringStartsWith($prefix, $sentinel, sprintf(
                '%s is meant to prove %s is installed, but does not belong to it.',
                $sentinel,
                $prefix
            ));
        }
    }

    /** @return array<Node\Stmt>|null */
    private static function parse(string $code): ?array
    {
        $src = str_starts_with(ltrim($code), '<?php') ? $code : "<?php\n" . $code;

        try {
            return new ParserFactory()->createForNewestSupportedVersion()->parse($src);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Packages the README documents but Composer may not have installed, each keyed
     * to a class that exists if and only if the package does.
     *
     * The PSR-18 clients are alternatives: the CI matrix removes Guzzle to run this
     * suite against Symfony's, so `use GuzzleHttp\Client` is correct documentation
     * that cell cannot confirm. Absent is not the same as wrong.
     *
     * Typed as plain strings, not class-string: a class-string is one that resolves,
     * and these are exactly the names that may not.
     *
     * @var array<string, string>
     */
    private const OPTIONAL_PACKAGES = [
        'GuzzleHttp\\' => 'GuzzleHttp\\Client',
        'Symfony\\Component\\HttpClient\\' => 'Symfony\\Component\\HttpClient\\Psr18Client',
        'Nyholm\\Psr7\\' => 'Nyholm\\Psr7\\Factory\\Psr17Factory',
    ];

    /**
     * @param  array<Node\Stmt> $ast
     * @return list<string>
     */
    private static function inspect(array $ast, bool $isFragment): array
    {
        // Only while the package is missing. Where it is installed the names under it
        // are checked like any other, so a typo still fails in the cell that has it.
        $absent = [];

        foreach (self::OPTIONAL_PACKAGES as $prefix => $sentinel) {
            if (! class_exists($sentinel) && ! interface_exists($sentinel)) {
                $absent[] = $prefix;
            }
        }

        $facade = [];

        foreach (new \ReflectionClass(LiveKitAPI::class)->getProperties() as $property) {
            $type = $property->getType();

            if ($type instanceof \ReflectionNamedType) {
                $facade[$property->getName()] = $type->getName();
            }
        }

        $visitor = new class ($facade, $isFragment, $absent) extends NodeVisitorAbstract {
            /** @var list<string> */
            public array $problems = [];

            /** @var array<string, string> */
            private array $aliases = [];

            /**
             * README examples build on one another, and the facade is called $livekit
             * throughout. Seeding it lets a method call be checked in the blocks that
             * do not construct it themselves -- which is most of them.
             *
             * @var array<string, class-string>
             */
            private array $vars = ['livekit' => LiveKitAPI::class];

            /**
             * @param array<string, string> $facade
             * @param list<string>          $absent  namespace prefixes not installed here
             */
            public function __construct(
                private readonly array $facade,
                private readonly bool $isFragment,
                private readonly array $absent,
            ) {
            }

            private function isUninstallable(string $fqcn): bool
            {
                foreach ($this->absent as $prefix) {
                    if (str_starts_with($fqcn, $prefix)) {
                        return true;
                    }
                }

                return false;
            }

            private function resolve(string $name): string
            {
                $name = ltrim($name, '\\');
                $head = explode('\\', $name)[0];

                return isset($this->aliases[$head])
                    ? $this->aliases[$head] . substr($name, strlen($head))
                    : $name;
            }

            /** @param array<Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder> $args */
            private function checkNamedArguments(?\ReflectionFunctionAbstract $fn, array $args, string $what): void
            {
                if ($fn === null) {
                    return;
                }

                $valid = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $fn->getParameters());

                foreach ($args as $arg) {
                    if ($arg instanceof Node\Arg && $arg->name !== null
                        && ! in_array($arg->name->toString(), $valid, true)) {
                        $this->problems[] = sprintf(
                            '%s has no parameter $%s (accepts: %s)',
                            $what,
                            $arg->name->toString(),
                            implode(', ', $valid)
                        );
                    }
                }
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Use_) {
                    foreach ($node->uses as $use) {
                        $fqcn = $use->name->toString();
                        $this->aliases[$use->getAlias()->toString()] = $fqcn;

                        if (! class_exists($fqcn) && ! interface_exists($fqcn) && ! enum_exists($fqcn)
                            && ! $this->isUninstallable($fqcn)) {
                            $this->problems[] = sprintf('use %s — no such class', $fqcn);
                        }
                    }
                }

                if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
                    // A class the example defines itself is not one it has to import.
                    $this->aliases[$node->name->toString()] = $node->name->toString();
                }

                if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
                    $written = $node->class->toString();
                    $fqcn = $this->resolve($written);

                    if (class_exists($fqcn)) {
                        $this->checkNamedArguments(
                            new \ReflectionClass($fqcn)->getConstructor(),
                            $node->args,
                            sprintf('new %s()', $written)
                        );
                    } elseif (! $this->isFragment && ! isset($this->aliases[$written])) {
                        $this->problems[] = sprintf('new %s — no such class, and the block does not import it', $written);
                    }
                }

                if ($node instanceof Node\Expr\Assign
                    && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)
                    && $node->expr instanceof Node\Expr\New_ && $node->expr->class instanceof Node\Name) {
                    $fqcn = $this->resolve($node->expr->class->toString());

                    if (class_exists($fqcn)) {
                        $this->vars[$node->var->name] = $fqcn;
                    }
                }

                if ($node instanceof Node\Expr\ClassConstFetch
                    && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier
                    && $node->name->toString() !== 'class') {
                    $written = $node->class->toString();
                    $fqcn = $this->resolve($written);

                    if (class_exists($fqcn)) {
                        if (! defined($fqcn . '::' . $node->name->toString())) {
                            $this->problems[] = sprintf(
                                '%s::%s — no such constant',
                                $written,
                                $node->name->toString()
                            );
                        }
                    } elseif (! $this->isFragment && ! isset($this->aliases[$written])) {
                        // A block reading Foo::BAR without importing Foo cannot be pasted.
                        // Missed once, on Kind::RELIABLE, because this branch only looked at
                        // classes it could already resolve.
                        $this->problems[] = sprintf(
                            '%s::%s — no such class, and the block does not import it',
                            $written,
                            $node->name->toString()
                        );
                    }
                }

                if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
                    $receiver = $this->receiverType($node);

                    if ($receiver !== null) {
                        if (! method_exists($receiver, $node->name->toString())) {
                            $this->problems[] = sprintf('%s::%s() — no such method', $receiver, $node->name->toString());
                        } else {
                            $this->checkNamedArguments(
                                new \ReflectionMethod($receiver, $node->name->toString()),
                                $node->args,
                                sprintf('%s::%s()', $receiver, $node->name->toString())
                            );
                        }
                    }
                }

                return null;
            }

            /** @return class-string|null */
            private function receiverType(Node\Expr\MethodCall $call): ?string
            {
                if ($call->var instanceof Node\Expr\Variable && is_string($call->var->name)) {
                    $type = $this->vars[$call->var->name] ?? null;

                    return $type !== null && class_exists($type) ? $type : null;
                }

                // $livekit->room->createRoom(...)
                if ($call->var instanceof Node\Expr\PropertyFetch
                    && $call->var->var instanceof Node\Expr\Variable
                    && is_string($call->var->var->name)
                    && ($this->vars[$call->var->var->name] ?? null) === LiveKitAPI::class
                    && $call->var->name instanceof Node\Identifier) {
                    $type = $this->facade[$call->var->name->toString()] ?? null;

                    return $type !== null && class_exists($type) ? $type : null;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->problems;
    }
}
