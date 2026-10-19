<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SplFileInfo;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function dirname;
use function file_get_contents;
use function in_array;
use function is_array;
use function ltrim;
use function preg_match_all;
use function preg_replace;
use function sort;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strrpos;
use function substr;
use function token_get_all;

/**
 * The package's README.md, read for the Docs suite's drift checks: its fenced code blocks and the
 * public API they may use.
 */
final readonly class Readme
{
    public const string PACKAGE_NAMESPACE = 'EarthlingOllie\\SystemOneClassification\\';

    public function __construct(public string $text) {}

    public static function load(): self
    {
        return new self((string) file_get_contents(self::root().'/README.md'));
    }

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The README's fenced blocks in one language, in order. A fence indented inside a list item
     * counts too, its content dedented by the fence's indent.
     *
     * @return list<string>
     */
    public function blocks(string $language): array
    {
        preg_match_all('/^( *)```'.$language.'\n(.*?)^\1```$/ms', $this->text, $matches, PREG_SET_ORDER);

        return array_map(
            static fn (array $match): string => preg_replace('/^'.($match[1] ?? '').'/m', '', $match[2] ?? '') ?? '',
            $matches,
        );
    }

    /**
     * Each ```php block as a PHP file: an opening tag is added where the block has none.
     *
     * @return list<string>
     */
    public function phpFiles(): array
    {
        $files = [];

        foreach ($this->blocks('php') as $block) {
            $files[] = str_starts_with($block, '<?php') ? $block : "<?php\n{$block}";
        }

        return $files;
    }

    /**
     * The names of every public method and property of the package's classes, interfaces and
     * enums under src/, sorted: what `->name` in a snippet may refer to.
     *
     * @return list<string>
     */
    public static function publicMembers(): array
    {
        $members = [];

        foreach (self::packageClasses() as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $members[] = $method->getName();
            }

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $members[] = $property->getName();
            }
        }

        $members = array_values(array_unique($members));
        sort($members);

        return $members;
    }

    /**
     * Every class, interface, trait and enum under src/, by name.
     *
     * @return list<class-string>
     */
    public static function packageClasses(): array
    {
        $classes = [];
        $src = self::root().'/src/';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            /** @var class-string $class src/ follows PSR-4 */
            $class = self::PACKAGE_NAMESPACE.str_replace('/', '\\', substr($file->getPathname(), strlen($src), -4));
            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /**
     * The package's classes by short name, where the name is unique, as a snippet that leaves
     * out its imports would mean them.
     *
     * @return array<string, class-string>
     */
    public static function shortNames(): array
    {
        $byName = [];

        foreach (self::packageClasses() as $class) {
            $byName[self::shortName($class)][] = $class;
        }

        $unique = [];

        foreach ($byName as $name => $classes) {
            if (count($classes) === 1) {
                $unique[$name] = $classes[0];
            }
        }

        return $unique;
    }

    /**
     * The `use` imports of a PHP snippet, alias => class.
     *
     * @return array<string, string>
     */
    public static function imports(string $php): array
    {
        preg_match_all('/^use ([A-Za-z0-9_\\\\]+)(?: as (\w+))?;$/m', $php, $matches, PREG_SET_ORDER);
        $imports = [];

        foreach ($matches as $match) {
            $alias = $match[2] ?? '';
            $imports[$alias === '' ? self::shortName($match[1]) : $alias] = $match[1];
        }

        return $imports;
    }

    /**
     * The members a PHP snippet reaches: `->name` and `?->name` accesses, and `Class::name`
     * static calls and constants with the class as written.
     *
     * @return array{instance: list<string>, static: list<array{class: string, member: string}>}
     */
    public static function memberAccesses(string $php): array
    {
        $tokens = array_values(array_filter(
            token_get_all($php),
            static fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $instance = [];
        $static = [];

        foreach ($tokens as $i => $token) {
            $next = $tokens[$i + 1] ?? null;

            if (! is_array($token) || ! is_array($next) || $next[0] !== T_STRING) {
                continue;
            }

            if ($token[0] === T_OBJECT_OPERATOR || $token[0] === T_NULLSAFE_OBJECT_OPERATOR) {
                $instance[] = $next[1];
            }

            $class = $tokens[$i - 1] ?? null;

            if ($token[0] === T_DOUBLE_COLON && is_array($class) && in_array($class[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $static[] = ['class' => ltrim($class[1], '\\'), 'member' => $next[1]];
            }
        }

        return ['instance' => $instance, 'static' => $static];
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
