<?php
declare (strict_types = 1);
namespace WebSharks\Dicer;

/**
 * Dicer core.
 *
 * @since 150507 Initial release.
 */
class Core
{
    /**
     * Closure cache.
     *
     * @since 150507 Initial release.
     *
     * @type array Cache.
     */
    protected $closures = [];

    /**
     * Instance cache.
     *
     * @since 150507 Initial release.
     *
     * @type array Cache.
     */
    protected $instances = [];

    /**
     * Rules.
     *
     * @since 150507 Initial release.
     *
     * @type array Rules.
     */
    protected $rules = [];

    /**
     * Rule defaults.
     *
     * @since 150507 Initial release.
     *
     * @type array Defaults.
     */
    protected $rule_defaults = [
        'class_name' => '*',

        'shared'  => false,
        'inherit' => true,

        'construct_params' => [],

        'call'          => [],
        'substitutions' => [],
        'instance_of'   => '',

        'new_instances'   => [],
        'share_instances' => [],
    ];

    /**
     * Constructor.
     *
     * @since 150507 Initial release.
     *
     * @param array $default_rule Construct with an altered default rule.
     */
    public function __construct(array $default_rule = [])
    {
        $this->rules['*'] = $this->rule_defaults;

        if ($default_rule) {
            $this->addRule('*', $default_rule);
        }
    }

    /**
     * Add a new class rule.
     *
     * @since 150507 Initial release.
     *
     * @param string $class_name Class name (i.e., Namespace\Class).
     * @param array  $rule       An array of rule properties.
     */
    public function addRule(string $class_name, array $rule)
    {
        $class_name    = ltrim($class_name, '\\');
        $class_name_lc = strtolower($class_name);

        $this->rules[$class_name_lc] = array_merge($this->rules['*'], $rule);
        $this->rules[$class_name_lc] = array_intersect_key($this->rules[$class_name_lc], $this->rules['*']);
        $rule                        = &$this->rules[$class_name_lc];

        $rule['class_name'] = $class_name;

        $rule['shared']  = (bool) $rule['shared'];
        $rule['inherit'] = (bool) $rule['inherit'];

        $rule['construct_params'] = (array) $rule['construct_params'];

        $rule['call']          = (array) $rule['call'];
        $rule['substitutions'] = (array) $rule['substitutions'];
        $rule['instance_of']   = ltrim((string) $rule['instance_of'], '\\');

        $rule['new_instances']   = $rule['shared'] ? (array) $rule['new_instances'] : [];
        $rule['share_instances'] = !$rule['shared'] ? (array) $rule['share_instances'] : [];
    }

    /**
     * Gets a specific class rule.
     *
     * @since 150507 Initial release.
     *
     * @param string $class_name Class name (i.e., Namespace\Class).
     *
     * @return array An array of rule properties.
     */
    public function getRule(string $class_name): array
    {
        $class_name    = ltrim($class_name, '\\');
        $class_name_lc = strtolower($class_name);

        if (isset($this->rules[$class_name_lc])) {
            return $this->rules[$class_name_lc];
        }
        foreach ($this->rules as $_class_name_lc => $_rule) {
            if ($_rule['inherit'] && $_rule['class_name'] !== '*') {
                if (!$_rule['instance_of'] && is_subclass_of($class_name, $_rule['class_name'], true)) {
                    return $_rule;
                }
            }
        } // unset($_class_name_lc, $_rule); // Housekeeping.

        return $this->rules['*'];
    }

    /**
     * Get a specific class instance.
     *
     * @since 150507 Initial release.
     *
     * @param string            $class_name         Class name (i.e., Namespace\Class).
     * @param array             $args               An array of arguments to the constructor.
     * @param bool              $force_new_instance Force a new instance of the class? Default is `false`.
     * @param string[]|object[] $share              Any array of any class names (or instances) to share.
     *
     * @return object An object class instance.
     */
    public function get(string $class_name, array $args = [], bool $force_new_instance = false, array $share = [])
    {
        $class_name    = ltrim($class_name, '\\');
        $class_name_lc = strtolower($class_name);

        if (!$force_new_instance && isset($this->instances[$class_name_lc])) {
            return $this->instances[$class_name_lc];
        }
        if (!isset($this->closures[$class_name_lc])) {
            $rule                           = $this->getRule($class_name);
            $this->closures[$class_name_lc] = $this->getClosure($class_name, $rule);
        }
        foreach ($share as &$_share) {
            if (is_string($_share)) {
                $_share = $this->get($_share);
            }
        } // unset($_share); // Housekeeping.

        return $this->closures[$class_name_lc]($args, $share);
    }

    /**
     * Get a specific class closure.
     *
     * @since 150507 Initial release.
     *
     * @param string $class_name Class name (i.e., Namespace\Class).
     * @param array  $rule       A specific rule that goes with the class name.
     *
     * @return callable A closure that returns the class instance.
     */
    protected function getClosure(string $class_name, array $rule): callable
    {
        $class_name    = ltrim($class_name, '\\');
        $class_name_lc = strtolower($class_name);

        $class          = new \ReflectionClass($rule['instance_of'] ? $rule['instance_of'] : $class_name);
        $constructor    = $class->getConstructor(); // Returns null if class has no constructor.
        $params_closure = $constructor ? $this->getParamsClosure($constructor, $rule) : null;

        if ($rule['shared'] && (!$rule['new_instances'] || !in_array($class_name, $rule['new_instances'], true))) {
            $closure = function (array $args, array $share) use ($class_name_lc, $class, $constructor, $params_closure) {
                if ($constructor && $params_closure) {
                    $this->instances[$class_name_lc] = $class->newInstanceArgs($params_closure($args, $share));
                } else {
                    $this->instances[$class_name_lc] = $class->newInstanceWithoutConstructor();
                }
                return $this->instances[$class_name_lc];
            };
        } elseif ($constructor && $params_closure) {
            $closure = function (array $args, array $share) use ($class, $params_closure) {
                return $class->newInstanceArgs($params_closure($args, $share));
            };
        } else {
            $closure = function (array $args, array $share) use ($class) {
                return $class->newInstanceWithoutConstructor();
            };
        }
        if ($rule['call']) {
            $closure = function (array $args, array $share) use ($closure, $class, $rule) {
                $instance = $closure($args, $share);
                foreach ($rule['call'] as $_call) {
                    $_method = $class->getMethod($_call[0]);
                    $_args   = !empty($_call[1]) ? $this->expandInstanceKeys($_call[1], []) : [];
                    $_method->invokeArgs($instance, $_args);
                } // unset($_call, $_method, $_args); // Housekeeping.

                return $instance;
            };
        }
        return $closure;
    }

    /**
     * Expands parameters deeply; i.e., some magic happens here!
     *
     * @since 150507 Initial release.
     *
     * @param \ReflectionMethod A reflection method to parameterize.
     * @param array $rule A specific rule that goes with the parent class instance.
     *
     * @return callable A closure that returns an array of parameters; w/ dependencies injected deeply.
     */
    protected function getParamsClosure(\ReflectionMethod $method, array $rule): callable
    {
        $param_details = []; // Initialize parameter details.

        foreach ($method->getParameters() as $_parameter) {
            $_name               = $_parameter->getName();
            $_class              = $_parameter->getClass();
            $_class_name         = $_class ? $_class->name : '';
            $_allows_null        = $_parameter->allowsNull();
            $_has_default_value  = $_parameter->isDefaultValueAvailable();
            $_default_value      = $_has_default_value ? $_parameter->getDefaultValue() : null;
            $_has_substitution   = $_class_name && $rule['substitutions'] && array_key_exists($_class_name, $rule['substitutions']);
            $_force_new_instance = $_class_name && $rule['new_instances'] && in_array($_class_name, $rule['new_instances'], true);
            $param_details[]     = [$_name, $_class_name, $_allows_null, $_has_default_value, $_default_value, $_has_substitution, $_force_new_instance];
        } // unset($_parameter, $_name, $_class, $_class_name, $_allows_null, $_has_default_value, $_default_value, $_has_substitution, $_force_new_instance);

        return function (array $args, array $share) use ($param_details, $rule) {
            $parameters = []; // Initialize parameters.

            if ($rule['share_instances']) { // Merged shared instances.
                $share = array_merge($share, array_map([$this, 'get'], $rule['share_instances']));
            }
            if ($rule['construct_params']) { // In this specific order; `$args` take precedence.
                $args = array_merge($this->expandInstanceKeys($rule['construct_params'], $share), $args);
            }
            foreach ($param_details as list($_name, $_class_name, $_allows_null, $_has_default_value, $_default_value, $_has_substitution, $_force_new_instance)) {
                if ($_name && $args && array_key_exists($_name, $args)) {
                    $parameters[] = $args[$_name];
                } elseif ($_class_name) {
                    $parameters[] = $_has_substitution // Has substitution?
                        ? $this->expandInstanceKeys($rule['substitutions'][$_class_name], $share)
                        : $this->get($_class_name, [], $_force_new_instance, $share);
                } elseif ($_has_default_value) {
                    $parameters[] = $_default_value;
                }
            } // unset($_name, $_class_name, $_allows_null, $_has_default_value, $_default_value, $_has_substitution, $_force_new_instance);

            return $parameters; // With deep dependency injection.
        };
    }

    /**
     * Expands argument `::instance` keys deeply.
     *
     * @since 150507 Initial release.
     *
     * @param mixed    $value Any input value can be scanned deeply.
     * @param object[] $share An array of class instances to share.
     *
     * @return mixed Input `$value` w/ `::instance` keys expanded deeply.
     */
    protected function expandInstanceKeys($value, array $share)
    {
        if (is_array($value)) {
            if (isset($value['::instance'])) {
                return is_callable($value['::instance'])
                    ? call_user_func($value['::instance'], $this, $share)
                    : $this->get($value['::instance'], [], false, $share);
            } else {
                foreach ($value as $_key => &$_value) {
                    $_value = $this->expandInstanceKeys($_value, $share);
                } // unset($_key, $_value); // Housekeeping.
            }
        }
        return $value;
    }
}
