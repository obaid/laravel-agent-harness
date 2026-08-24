<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Runtime;

use AgentHarness\Laravel\Contracts\HarnessDriver;
use AgentHarness\Laravel\Exceptions\DriverNotFound;
use AgentHarness\Laravel\Exceptions\HarnessCapabilityUnsupported;
use Closure;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves configured drivers by name and validates their declared capabilities.
 *
 * A driver that cannot do what a caller asked for fails here, before any work
 * begins, rather than degrading quietly at execution time.
 */
class DriverRegistry
{
    /** @var array<string, HarnessDriver> */
    protected array $resolved = [];

    /** @var array<string, Closure(Container): HarnessDriver> */
    protected array $customCreators = [];

    /**
     * @param  array<string, array{driver: class-string<HarnessDriver>}|class-string<HarnessDriver>>  $config
     */
    public function __construct(
        protected Container $container,
        protected array $config = [],
        protected string $default = 'laravel-ai',
    ) {}

    /**
     * Resolve a driver, memoizing the instance for this request.
     */
    public function driver(?string $name = null): HarnessDriver
    {
        $name ??= $this->default;

        return $this->resolved[$name] ??= $this->resolve($name);
    }

    /**
     * Register a driver factory at runtime.
     *
     * @param  Closure(Container): HarnessDriver  $creator
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
    public function register(string $name, HarnessDriver $driver): static
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
     * @throws HarnessCapabilityUnsupported
     */
    public function requireCapability(HarnessDriver $driver, string $capability): void
    {
        if (! $driver->capabilities()->supports($capability)) {
            throw HarnessCapabilityUnsupported::for($driver->name(), $capability);
        }
    }

    /**
     * @throws DriverNotFound
     */
    protected function resolve(string $name): HarnessDriver
    {
        if (isset($this->customCreators[$name])) {
            return ($this->customCreators[$name])($this->container);
        }

        $config = $this->config[$name] ?? null;

        $class = is_array($config) ? $config['driver'] : $config;

        if (! is_string($class) || ! class_exists($class)) {
            throw DriverNotFound::named($name);
        }

        $driver = $this->container->make($class, is_array($config) ? ['config' => $config] : []);

        if (! $driver instanceof HarnessDriver) {
            throw new DriverNotFound(
                "The class [{$class}] registered as harness driver [{$name}] does not implement HarnessDriver."
            );
        }

        return $driver;
    }
}
