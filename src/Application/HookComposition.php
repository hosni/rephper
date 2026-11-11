<?php

declare(strict_types=1);

namespace Rephper\Application;

use Rephper\CodeGen\SourceGenerationException;
use Rephper\Ir\ClassDeclaration;
use Rephper\Ir\ClassProperty;
use Rephper\Ir\OpArray;

/**
 * Rejects a backed property whose get hook returns by reference and whose
 * set hook also exists, after traits and parents in this file are composed.
 *
 * PHP 8.4 applies that check when the class is linked. A class that uses a
 * trait is not linked during lint, so the exported class row does not yet
 * contain the trait's properties or a descendant's inherited hooks. The hook
 * op arrays and the trait, parent, and property rows are enough to compose
 * those hooks here. No IR field is added.
 *
 * A private parent property is a different property from a child's property
 * of the same name, so its hooks are not combined. `insteadof` and `as`
 * adapt methods, not properties; two hooked properties of the same name
 * still collide. An adaptation whose method is a hook name is rejected
 * because this pass does not model it.
 *
 * A parent or trait that is not in the file leaves a hooked property
 * unresolved, and generation fails. A property with no hooks and an external
 * parent is left alone: this file records no hook to compose.
 */
final class HookComposition
{
    /**
     * @var array<string, array{ClassDeclaration, list<OpArray>}>
     */
    private array $symbols = [];

    /**
     * Local hook bodies. The bool is true when a get hook returns by reference.
     *
     * @var array<string, array<string, array<string, bool>>>
     */
    private array $facts = [];

    /**
     * @var array<string, array{properties: array<string, array{virtual: bool, private: bool, hooks: array<string, bool|null>}>, missing: ?string}>
     */
    private array $cache = [];

    /**
     * @param list<array{ClassDeclaration, list<OpArray>, list<OpArray>}> $groups
     */
    public function assertValid(array $groups): void
    {
        foreach ($groups as $group) {
            $class = $group[0];
            $key = $this->key($class->name);
            $this->symbols[$key] = [$class, $group[2]];
        }
        foreach ($this->symbols as $key => [$class, $hooks]) {
            foreach ($hooks as $hook) {
                $meta = $hook->propertyHook;
                if ($meta === null || ($meta->hook !== 'get' && $meta->hook !== 'set')) {
                    continue;
                }
                $reference = $meta->hook === 'get' && in_array('return_reference', $hook->flags, true);
                $previous = $this->facts[$key][$meta->property][$meta->hook] ?? false;
                $this->facts[$key][$meta->property][$meta->hook] = $previous || $reference;
            }
        }
        foreach (array_keys($this->symbols) as $key) {
            $this->compose($key, []);
        }
    }

    /**
     * @param array<string, true> $stack
     * @return array{properties: array<string, array{virtual: bool, private: bool, hooks: array<string, bool|null>}>, missing: ?string}
     */
    private function compose(string $key, array $stack): array
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        if (isset($stack[$key])) {
            $this->fail($this->label($this->symbols[$key][0]) . ' has a circular parent or trait dependency, so its hook composition cannot be validated');
        }
        $stack[$key] = true;
        $class = $this->symbols[$key][0];
        $this->rejectHookAdaptations($class);

        $properties = [];
        foreach ($class->properties as $property) {
            $properties[$property->name] = $this->declared($class, $property);
        }
        $missing = null;
        if (!in_array('linked', $class->flags, true)) {
            foreach ($class->traits as $traitName) {
                $traitKey = $this->key($traitName);
                $trait = $this->symbols[$traitKey][0] ?? null;
                if (!$trait instanceof ClassDeclaration) {
                    $missing = $traitName;
                    continue;
                }
                if ($trait->kind !== 'trait') {
                    $this->fail($this->label($class) . ' uses ' . $traitName . ', which is not a trait, so its hook composition cannot be validated');
                }
                $composed = $this->compose($traitKey, $stack);
                if ($composed['missing'] !== null) {
                    $missing = $composed['missing'];
                }
                $properties = $this->addTraitProperties($class, $properties, $composed['properties'], $trait->name);
            }
            if ($missing !== null && $properties !== []) {
                $this->fail($this->label($class) . ' uses trait ' . $missing . ', which is not in this file, so the composition of its properties cannot be validated');
            }
        }
        $parentName = $class->parent;
        if ($parentName !== null) {
            $parentKey = $this->key($parentName);
            $parent = $this->symbols[$parentKey][0] ?? null;
            if (!$parent instanceof ClassDeclaration || $parent->kind !== 'class') {
                if ($this->requiresAncestor($properties)) {
                    $reason = $parent instanceof ClassDeclaration
                        ? 'is not a class'
                        : 'is not in this file';
                    $this->fail($this->label($class) . ' extends ' . $parentName . ', which ' . $reason . ', so its hook composition cannot be validated');
                }
            } else {
                $composed = $this->compose($parentKey, $stack);
                if ($composed['missing'] !== null && $class->properties !== []) {
                    $this->fail($this->label($class) . ' extends ' . $parent->name . ', which depends on ' . $composed['missing'] . ', so its hook composition cannot be validated');
                }
                $properties = $this->inheritProperties($properties, $composed['properties']);
            }
        }
        foreach ($properties as $name => $property) {
            $this->assertResolved($class, $name, $property);
        }

        return $this->cache[$key] = ['properties' => $properties, 'missing' => $missing];
    }

    /**
     * @return array{virtual: bool, private: bool, hooks: array<string, bool|null>}
     */
    private function declared(ClassDeclaration $class, ClassProperty $property): array
    {
        $hooks = [];
        foreach ($property->hooks as $kind) {
            if ($kind === 'get' || $kind === 'set') {
                $hooks[$kind] = null;
            }
        }
        foreach ($this->facts[$this->key($class->name)][$property->name] ?? [] as $kind => $reference) {
            $hooks[$kind] = $reference;
        }

        return [
            'virtual' => in_array('virtual', $property->flags, true),
            'private' => in_array('private', $property->flags, true),
            'hooks' => $hooks,
        ];
    }

    /**
     * @param array<string, array{virtual: bool, private: bool, hooks: array<string, bool|null>}> $properties
     * @param array<string, array{virtual: bool, private: bool, hooks: array<string, bool|null>}> $traitProperties
     * @return array<string, array{virtual: bool, private: bool, hooks: array<string, bool|null>}>
     */
    private function addTraitProperties(ClassDeclaration $class, array $properties, array $traitProperties, string $traitName): array
    {
        foreach ($traitProperties as $name => $traitProperty) {
            if (!isset($properties[$name])) {
                $properties[$name] = $traitProperty;
                continue;
            }
            $declared = $properties[$name];
            if ($declared['hooks'] === [] && $traitProperty['hooks'] === []) {
                continue;
            }
            $this->rejectCollision($class, $name, $declared, $traitProperty, $traitName);
        }

        return $properties;
    }

    /**
     * @param array<string, array{virtual: bool, private: bool, hooks: array<string, bool|null>}> $properties
     * @param array<string, array{virtual: bool, private: bool, hooks: array<string, bool|null>}> $parentProperties
     * @return array<string, array{virtual: bool, private: bool, hooks: array<string, bool|null>}>
     */
    private function inheritProperties(array $properties, array $parentProperties): array
    {
        foreach ($parentProperties as $name => $parentProperty) {
            if (!isset($properties[$name])) {
                $properties[$name] = $parentProperty;
                continue;
            }
            if ($parentProperty['private']) {
                continue;
            }
            $child = $properties[$name];
            if (!$parentProperty['virtual']) {
                $child['virtual'] = false;
            }
            foreach (['get', 'set'] as $kind) {
                $present = array_key_exists($kind, $child['hooks']);
                $inherited = array_key_exists($kind, $parentProperty['hooks']);
                if ((!$present || $child['hooks'][$kind] === null) && $inherited) {
                    $child['hooks'][$kind] = $parentProperty['hooks'][$kind];
                }
            }
            $properties[$name] = $child;
        }

        return $properties;
    }

    /**
     * @param array{virtual: bool, private: bool, hooks: array<string, bool|null>} $left
     * @param array{virtual: bool, private: bool, hooks: array<string, bool|null>} $right
     */
    private function rejectCollision(ClassDeclaration $class, string $name, array $left, array $right, string $other): never
    {
        $virtual = $left['virtual'] && $right['virtual'];
        $hooks = $left['hooks'];
        foreach ($right['hooks'] as $kind => $reference) {
            if (!array_key_exists($kind, $hooks) || $hooks[$kind] === null || $reference === true) {
                $hooks[$kind] = $reference;
            }
        }
        if (!$virtual && ($hooks['get'] ?? null) === true && array_key_exists('set', $hooks) && $hooks['set'] !== null) {
            $this->fail($this->label($class) . ' property $' . $name . ' combines a by-reference get with a set hook');
        }
        $this->fail($this->label($class) . ' property $' . $name . ' is also declared with hooks by ' . $other . ', which PHP 8.4 does not compose');
    }

    /**
     * @param array{virtual: bool, private: bool, hooks: array<string, bool|null>} $property
     */
    private function assertResolved(ClassDeclaration $class, string $name, array $property): void
    {
        $hooks = $property['hooks'];
        foreach ($hooks as $kind => $reference) {
            if ($reference !== null) {
                continue;
            }
            if ($kind === 'set' && ($hooks['get'] ?? null) === false) {
                continue;
            }
            if ($kind === 'get' && !array_key_exists('set', $hooks)) {
                continue;
            }
            if ($class->parent !== null || $class->traits !== []) {
                $this->fail($this->label($class) . ' property $' . $name . ' hook ' . $kind . ' has no body in this file, so its composition cannot be validated');
            }
        }
        if (!$property['virtual'] && ($property['hooks']['get'] ?? null) === true && array_key_exists('set', $property['hooks'])) {
            $this->fail($this->label($class) . ' property $' . $name . ' combines a by-reference get with a set hook');
        }
    }

    /**
     * @param array<string, array{virtual: bool, private: bool, hooks: array<string, bool|null>}> $properties
     */
    private function requiresAncestor(array $properties): bool
    {
        foreach ($properties as $property) {
            if (!$this->isClosed($property)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A closed property does not gain a by-reference get or a set hook from
     * an ancestor. A virtual by-reference get can become backed when the
     * parent property is backed, so that pair stays open.
     *
     * @param array{virtual: bool, private: bool, hooks: array<string, bool|null>} $property
     */
    private function isClosed(array $property): bool
    {
        $hooks = $property['hooks'];
        if ($hooks === []) {
            return true;
        }
        if (!isset($hooks['get'], $hooks['set'])) {
            return false;
        }
        if ($hooks['get'] === null || $hooks['set'] === null) {
            return false;
        }

        return !($hooks['get'] === true && $property['virtual']);
    }

    private function rejectHookAdaptations(ClassDeclaration $class): void
    {
        foreach ($class->traitAliases as $alias) {
            if ($this->hookMethod($alias->method) || ($alias->alias !== null && $this->hookMethod($alias->alias))) {
                $this->fail($this->label($class) . ' adapts ' . $alias->method . ', which is a property hook, so its composition cannot be validated');
            }
        }
        foreach ($class->traitPrecedences as $precedence) {
            if ($this->hookMethod($precedence->method)) {
                $this->fail($this->label($class) . ' adapts ' . $precedence->method . ', which is a property hook, so its composition cannot be validated');
            }
        }
    }

    private function hookMethod(string $method): bool
    {
        return str_starts_with($method, '$') && str_contains($method, '::');
    }

    private function key(string $name): string
    {
        return strtolower(ltrim($name, '\\'));
    }

    private function label(ClassDeclaration $class): string
    {
        return $class->kind . ' ' . $class->name;
    }

    private function fail(string $reason): never
    {
        throw new SourceGenerationException('Cannot generate a PHP file: ' . $reason . '.');
    }
}
