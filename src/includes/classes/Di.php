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
     * Add a new rule.
     *
     * @since 151115 Initial release.
     *
     * @param string $name Rule name.
     * @param array  $rule Rule properties.
     *
     * @return array An array of rule properties.
     */
    public function addRule(string $name, array $rule): array
    {
        $name = ltrim($name, '\\');
        $key  = strtolower($name);

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
        return $this->rules[$key];
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
        $parent_classes = null; // Initialize only.

        foreach ($this->rules as $_key => $_rule) {
            if ($_rule['allow_inheritance'] && $_rule['name'] !== '*' && $name !== '*') {
                if (is_subclass_of($name, $_rule['name'], true)) {
                    return $_rule; // Inherit parent rule.
                }
            }
        } // unset($_key, $_rule); // Housekeeping.

        return $this->rules['*'];
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
     * @param \ReflectionMethod A reflection method.
     * @param array $class_rule A class-specific rule.
     *
     * @return callable Closure that returns an array of parameters.
     */
    protected function getParamsClosure(\ReflectionMethod $method, array $class_rule): callable
    {
        $param_details = []; // Initialize parameter details.

        foreach ($method->getParameters() as $_parameter) {
            $_name       = $_parameter->getName();
            $_class      = $_parameter->getClass();
            $_class_name = $_class->name ?? '';

            $_allows_null       = $_parameter->allowsNull();
            $_has_default_value = $_parameter->isDefaultValueAvailable();
            $_default_value     = $_has_default_value ? $_parameter->getDefaultValue() : null;

            $param_details[] = [$_name, $_class_name, $_allows_null, $_has_default_value, $_default_value];
        } // unset($_parameter, $_name, $_class, $_class_name, $_allows_null, $_has_default_value, $_default_value);

        return function (array $args) use ($param_details, $class_rule) {
            $parameters = []; // Initialize parameters.

            if ($class_rule['construct_params']) { // `$args` precedence.
                $args = array_merge($class_rule['construct_params'], $args);
            }
            foreach ($param_details as list($_name, $_class_name, $_allows_null, $_has_default_value, $_default_value)) {
                if ($_name && $args && array_key_exists($_name, $args)) {
                    $parameters[] = $args[$_name];
                } elseif ($_class_name) {
                    $parameters[] = $this->get($_class_name);
                } elseif ($_has_default_value) {
                    $parameters[] = $_default_value;
                }
            } // unset($_name, $_class_name, $_allows_null, $_has_default_value, $_default_value);

            return $parameters; // With deep dependency injection.
        };
    }
}

/**
 * Version.
 *
 * @since 151115
 *
 * @type string Version.
 */
const VERSION = '151113'; //version//
