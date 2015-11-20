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
            'class_name'        => '*',
            'new_instances'     => [],
            'construct_params'  => [],
            'allow_inheritance' => true,
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
    const VERSION = '151118'; //v//

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
        $class_key  = mb_strtolower($class_name);

        if (isset($this->instances[$class_key])) {
            return $this->instances[$class_key];
        }
        if (!isset($this->closures[$class_key])) {
            $this->closures[$class_key] = $this->getClosure($class_name, $class_key);
        }
        return $this->closures[$class_key]($args);
    }

    /**
     * Create a new class instance.
     *
     * @since 151115 Initial release.
     *
     * @param string $class_name Class name.
     * @param array  $args       Constructor args.
     *
     * @return object An object class instance.
     */
    public function create(string $class_name, array $args = [])
    {
        $class_name = ltrim($class_name, '\\');
        $class_key  = mb_strtolower($class_name);

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
            if (!is_object($_instance)) {
                throw new \Exception('Invalid instance.');
            }
            if (is_string($_key)) {
                $_class_name = ltrim($_key, '\\');
            } else {
                $_class_name = get_class($_instance);
            }
            $_class_key = mb_strtolower($_class_name);

            if (!isset($this->instances[$_class_key])) {
                $this->instances[$_class_key] = $_instance;
            }
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
        $key  = mb_strtolower($name);

        $global_default_rule = $this->rules['*']; // Copy.
        $this->rules[$key]   = array_merge($global_default_rule, $rule);
        $this->rules[$key]   = array_intersect_key($this->rules[$key], $global_default_rule);

        $this->rules[$key]['name']              = $name; // Preserve caSe.
        $this->rules[$key]['new_instances']     = (array) $this->rules[$key]['new_instances'];
        $this->rules[$key]['construct_params']  = (array) $this->rules[$key]['construct_params'];
        $this->rules[$key]['allow_inheritance'] = (bool) $this->rules[$key]['allow_inheritance'];

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
     * @param string $name Rule name.
     * @param string $key  Rule key.
     *
     * @return array An array of rule properties.
     */
    protected function getRule(string $name, string $key): array
    {
        if (isset($this->rules[$key])) {
            return $this->rules[$key];
        }
        // Note: `class_parents()` returns in reverse inheritance order.
        if ($this->total_rules === 1 || !($parent_class_names = class_parents($name))) {
            return $this->rules['*']; // No other rules to consider.
        }
        foreach (array_map('strtolower', $parent_class_names) as $_parent_class_key) {
            if (isset($this->rules[$_parent_class_key]) && $this->rules[$_parent_class_key]['allow_inheritance']) {
                return $this->rules[$_parent_class_key]; // Closest parent rule.
            }
        } // unset($_parent_class_key); // Housekeeping.

        return $this->rules['*'];
    }

    /**
     * Resolve just-in-time closures.
     *
     * @since 151118 Resolve just-in-time closures.
     *
     * @param mixed Any input value to scan for `di::jit` keys.
     *
     * @return mixed Output value w/ resolved `di::jit` keys
     */
    protected function resolveJitClosures($value)
    {
        $is_array  = is_array($value);
        $is_object = !$is_array && is_object($value);

        if ($is_array && isset($value['di::jit'])) {
            if ($value['di::jit'] instanceof \Closure) {
                return $value['di::jit']($this);
            } else { // Unexpected `di::jit` value.
                throw new \Exception('Unexpected `di::jit`.');
            }
        } elseif ($is_array || $is_object) {
            foreach ($value as $_key_prop => &$_value) {
                $_value = $this->resolveJitClosures($_value);
            } // unset($_key_prop, $_value);
        }
        return $value; // Resolved deeply.
    }

    /**
     * Get a specific class closure.
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
        $class                      = new \ReflectionClass($class_name);
        $class_rule                 = $this->getRule($class_name, $class_key);
        $constructor                = $class->getConstructor(); // Null if no constructor.
        $constructor_params_closure = $constructor ? $this->getParamsClosure($constructor, $class_rule) : null;

        if (!$this->rules['*']['new_instances'] || !in_array($name, $this->rules['*']['new_instances'], true)) {
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

            $_allows_null       = $_parameter->allowsNull();
            $_has_default_value = $_parameter->isDefaultValueAvailable();
            $_default_value     = $_has_default_value ? $_parameter->getDefaultValue() : null;

            $param_details[] = [$_name, $_class_name, $_allows_null, $_has_default_value, $_default_value];
        } // unset($_parameter, $_name, $_class, $_class_name, $_allows_null, $_has_default_value, $_default_value);

        return function (array $args) use ($param_details, $class_rule, $resolveInstanceKeys) {
            $parameters = []; // Initialize parameters.

            if ($class_rule['construct_params']) { // Note: `$args` take precedence here.
                $args = array_merge($this->resolveJitClosures($class_rule['construct_params']), $args);
            }
            foreach ($param_details as list($_name, $_class_name, $_allows_null, $_has_default_value, $_default_value)) {
                if ($_name && $args && array_key_exists($_name, $args)) {
                    $parameters[] = $args[$_name];
                } elseif ($_class_name) {
                    $parameters[] = $this->get($_class_name);
                } elseif ($_has_default_value) {
                    $parameters[] = $_default_value;
                } else {
                    throw new \Exception(sprintf('Missing parameter `%1$s` to `%2$s` constructor.', $_name, $constructor->class));
                }
            } // unset($_name, $_class_name, $_allows_null, $_has_default_value, $_default_value);

            return $parameters; // With deep dependency injection.
        };
    }
}
