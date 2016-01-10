<?php
declare (strict_types = 1);
namespace WebSharks\Dicer;

/**
 * Dicer.
 *
 * @since 151115
 */
class Di
{
    /**
     * Closure cache.
     *
     * @since 151115
     *
     * @type array Cache.
     */
    protected $closures = [];

    /**
     * Instance cache.
     *
     * @since 151115
     *
     * @type array Cache.
     */
    protected $instances = [];

    /**
     * Rules.
     *
     * @since 151115
     *
     * @type array Rules.
     */
    protected $rules = [
        '*' => [
            'new_instances'    => [],
            'constructor_args' => [],
        ],
    ];

    /**
     * Total rules.
     *
     * @since 151115
     *
     * @type int Total rules.
     */
    protected $total_rules = 1;

    /**
     * Version.
     *
     * @since 151115
     *
     * @type string Version.
     */
    const VERSION = '160110'; //v//

    /**
     * Constructor.
     *
     * @since 151115 Initial release.
     *
     * @param array $global_default_rule Global/default rule.
     */
    public function __construct(array $global_default_rule = [])
    {
        if ($global_default_rule) {
            $this->addRule('*', $global_default_rule);
        }
    }

    /**
     * Get a class instance.
     *
     * @since 151115 Initial release.
     *
     * @param string $class_name Class name.
     * @param array  $args       Constructor args.
     *
     * @return object An object class instance.
     */
    public function get(string $class_name, array $args = [])
    {
        $class_name = ltrim($class_name, '\\');
        $class_key  = strtolower($class_name);

        if (isset($this->instances[$class_key])) {
            return $this->instances[$class_key];
        }
        if (!isset($this->closures[$class_key])) {
            $this->closures[$class_key] = $this->getClosure($class_name, $class_key);
        }
        return $this->closures[$class_key]($args);
    }

    /**
     * Add new instances.
     *
     * @since 151115 Initial release.
     *
     * @param array $instances Class instances.
     *
     * @return self Reference; for chaining.
     */
    public function addInstances(array $instances): self
    {
        foreach ($instances as $_key => $_instance) {
            if (is_string($_key)) {
                $_class_name = ltrim($_key, '\\');
            } else {
                $_class_name = get_class($_instance);
            }
            $_class_key                   = strtolower($_class_name);
            $this->instances[$_class_key] = $_instance; // Cache the instance.
        } // unset($_key, $_instance, $_class_name, $_class_key);

        return $this;
    }

    /**
     * Add a new rule.
     *
     * @since 151115 Initial release.
     *
     * @param string $name Rule name.
     * @param array  $rule Rule properties.
     *
     * @return self Reference; for chaining.
     */
    public function addRule(string $name, array $rule): self
    {
        $name = ltrim($name, '\\');
        $key  = strtolower($name);

        $global_default_rule = $this->rules['*'];
        $this->rules[$key]   = array_merge($global_default_rule, $rule);
        $this->rules[$key]   = array_intersect_key($this->rules[$key], $global_default_rule);

        $this->rules[$key]['new_instances']    = (array) $this->rules[$key]['new_instances'];
        $this->rules[$key]['constructor_args'] = (array) $this->rules[$key]['constructor_args'];

        if ($key !== '*') { // Cannot contain this global-only key.
            unset($this->rules[$key]['new_instances']); // Not applicable.
        }
        $this->total_rules = count($this->rules);

        return $this;
    }

    /**
     * Gets a specific rule.
     *
     * @since 151115 Initial release.
     *
     * @param string $name         Rule name.
     * @param string $key          Rule key.
     * @param array  $parent_names Parent names.
     *
     * @return array An array of rule properties.
     */
    protected function getRule(string $name, string $key, array $parent_names = []): array
    {
        if (isset($this->rules[$key])) {
            return $this->rules[$key];
        }
        if ($this->total_rules === 1 || !$parent_names) {
            return $this->rules['*']; // Done here.
        }
        foreach (array_map('strtolower', $parent_names) as $_parent_key) {
            if (isset($this->rules[$_parent_key])) {
                return $this->rules[$_parent_key];
            }
        } // unset($_parent_key); // Housekeeping.

        return $this->rules['*'];
    }

    /**
     * Resolve just-in-time closures.
     *
     * @since 151118 Resolve just-in-time closures.
     *
     * @param array Input array to scan for `di::jit` keys.
     *
     * @return array|mixed Output w/ resolved `di::jit` keys.
     *
     * @note Objects not iterated, on purpose! This avoids a deeper scan
     *  that really is unnecessary when trying to find `di::jit` keys.
     */
    protected function resolveJitClosures(array $array)
    {
        if (isset($array['di::jit'])) {
            return $array['di::jit']($this);
        }
        foreach ($array as $_key => &$_value) {
            if (is_array($_value)) {
                $_value = $this->resolveJitClosures($_value);
            }
        } // unset($_key, $_value);
        return $array;
    }

    /**
     * Get closure for a specific class.
     *
     * @since 151115 Initial release.
     *
     * @param string $class_name Class name.
     * @param string $class_key  Class key.
     *
     * @return callable Closure returns instance.
     */
    protected function getClosure(string $class_name, string $class_key): callable
    {
        $parent_class_names = class_parents($class_name);
        $all_class_names    = $parent_class_names;
        $all_class_names[]  = $class_name;

        $class                      = new \ReflectionClass($class_name);
        $constructor                = $class->getConstructor(); // Null if no constructor.
        $class_rule                 = $this->getRule($class_name, $class_key, $parent_class_names);
        $constructor_params_closure = $constructor ? $this->getParamsClosure($constructor, $class_rule) : null;

        if (!$this->rules['*']['new_instances'] || !array_intersect($all_class_names, $this->rules['*']['new_instances'])) {
            return function (array $args) use ($class, $class_key, $constructor, $constructor_params_closure) {
                if ($constructor && $constructor_params_closure) {
                    return ($this->instances[$class_key] = $class->newInstanceArgs($constructor_params_closure($args)));
                } else {
                    return ($this->instances[$class_key] = $class->newInstanceWithoutConstructor());
                }
            };
        } elseif ($constructor && $constructor_params_closure) {
            return function (array $args) use ($class, $constructor_params_closure) {
                return $class->newInstanceArgs($constructor_params_closure($args));
            };
        } else {
            return function (array $args) use ($class) {
                return $class->newInstanceWithoutConstructor();
            };
        }
    }

    /**
     * Magic happens here.
     *
     * @since 151115 Initial release.
     *
     * @param \ReflectionMethod $constructor Constructor.
     * @param array             $class_rule  A class-specific rule.
     *
     * @return callable Closure that returns an array of parameters.
     */
    protected function getParamsClosure(\ReflectionMethod $constructor, array $class_rule): callable
    {
        $param_details = []; // Initialize parameter details.

        foreach ($constructor->getParameters() as $_parameter) {
            $_name = $_parameter->getName();

            $_class      = $_parameter->getClass();
            $_class_name = $_class->name ?? '';

            $_is_variadic       = $_parameter->isVariadic();
            $_has_default_value = $_parameter->isDefaultValueAvailable();
            $_default_value     = $_has_default_value ? $_parameter->getDefaultValue() : null;

            $param_details[] = [$_name, $_class_name, $_is_variadic, $_has_default_value, $_default_value];
        } // unset($_parameter, $_name, $_class, $_class_name, $_is_variadic, $_has_default_value, $_default_value);

        return function (array $args) use ($param_details, $class_rule) {
            $parameters = []; // Initialize parameters.

            if ($class_rule['constructor_args']) { // Note: `$args` take precedence here.
                $args = array_merge($this->resolveJitClosures($class_rule['constructor_args']), $args);
            }
            foreach ($param_details as list($_name, $_class_name, $_is_variadic, $_has_default_value, $_default_value)) {
                if ($args && array_key_exists($_name, $args)) {
                    if ($_is_variadic && is_array($args[$_name])) {
                        $parameters = array_merge($parameters, $args[$_name]);
                    } else {
                        $parameters[] = $args[$_name];
                    }
                } elseif ($_class_name) {
                    $parameters[] = $this->get($_class_name);
                } elseif ($_has_default_value) {
                    $parameters[] = $_default_value;
                } else {
                    throw new \Exception(sprintf('Missing `$%1$s` to `%2$s` constructor.', $_name, $constructor->class));
                }
            } // unset($_name, $_class_name, $_is_variadic, $_has_default_value, $_default_value);

            return $parameters; // With deep dependency injection.
        };
    }
}
