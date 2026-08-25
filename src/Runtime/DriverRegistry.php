<?php

declare(strict_types=1);

namespace Clutch\Laravel\Runtime;

use Closure;
use Clutch\Laravel\Contracts\ClutchDriver;
use Clutch\Laravel\Exceptions\CapabilityUnsupported;
use Clutch\Laravel\Exceptions\DriverNotFound;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves configured drivers by name and validates their declared capabilities.
 *
 * A driver that cannot do what a caller asked for fails here, before any work
 * begins, rather than degrading quietly at execution time.
 */
class DriverRegistry
{
    /** @var array<string, ClutchDriver> */
    protected array $resolved = [];

    /** @var array<string, Closure(Container): ClutchDriver> */
    protected array $customCreators = [];

    /**
     * Driver configuration, exactly as it comes off disk.
     *
     * Typed loosely on purpose: this is a file a user edits, so it cannot be
     * promised to hold a class-string. `resolve()` is what turns it into one,
     * and says clearly what is wrong when it cannot.
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected Container $container,
        protected array $config = [],
        protected string $default = 'laravel-ai',
    ) {}

    /**
     * Resolve a driver, memoizing the instance for this request.
     */
    public function driver(?string $name = null): ClutchDriver
    {
        $name ??= $this->default;

        return $this->resolved[$name] ??= $this->resolve($name);
    }

    /**
     * Register a driver factory at runtime.
     *
     * @param  Closure(Container): ClutchDriver  $creator
     */
    public function extend(string $name, Closure $creator): static
    {
        $this->customCreators[$name] = $creator;

        unset($this->resolved[$name]);

        return $this;
    }

    /**
     * Register an already-built driver instance.
     */
    public function register(string $name, ClutchDriver $driver): static
    {
        $this->resolved[$name] = $driver;

        return $this;
    }

    /**
     * Determine whether a driver name is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->resolved[$name])
            || isset($this->customCreators[$name])
            || isset($this->config[$name]);
    }

    /**
     * The names of every registered driver.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_values(array_unique([
            ...array_keys($this->config),
            ...array_keys($this->customCreators),
            ...array_keys($this->resolved),
        ]));
    }

    public function defaultDriver(): string
    {
        return $this->default;
    }

    /**
     * Assert a driver supports a capability before a caller depends on it.
     *
     * @throws CapabilityUnsupported
     */
    public function requireCapability(ClutchDriver $driver, string $capability): void
    {
        if (! $driver->capabilities()->supports($capability)) {
            throw CapabilityUnsupported::for($driver->name(), $capability);
        }
    }

    /**
     * @throws DriverNotFound
     */
    protected function resolve(string $name): ClutchDriver
    {
        if (isset($this->customCreators[$name])) {
            return ($this->customCreators[$name])($this->container);
        }

        $config = $this->config[$name] ?? null;

        $class = is_array($config) ? ($config['driver'] ?? null) : $config;

        if (! is_string($class)) {
            throw DriverNotFound::named($name);
        }

        // Config is written by hand, so a typo here is likely and deserves a
        // message that names the class rather than just the driver.
        if (! class_exists($class)) {
            throw new DriverNotFound(
                "The driver [{$name}] is configured as [{$class}], which does not exist. ".
                'Check the class name in config/clutch.php.'
            );
        }

        $driver = $this->container->make($class, is_array($config) ? ['config' => $config] : []);

        if (! $driver instanceof ClutchDriver) {
            throw new DriverNotFound(
                "The class [{$class}] registered as driver [{$name}] does not implement ClutchDriver."
            );
        }

        return $driver;
    }
}
