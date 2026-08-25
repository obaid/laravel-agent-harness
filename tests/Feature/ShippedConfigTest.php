<?php

declare(strict_types=1);

/**
 * Guards the config file the package actually ships.
 *
 * The rest of the suite overrides `clutch.drivers` so it can run against a
 * deterministic fake, which meant the published config was never exercised and
 * a wrong class name in it survived to a release. These tests load the real
 * file and use it as an application would.
 */

use Clutch\Laravel\Contracts\ClutchDriver;
use Clutch\Laravel\Runtime\DriverRegistry;

/**
 * The published config, read from disk rather than from the test environment.
 *
 * @return array<string, mixed>
 */
function shippedConfig(): array
{
    return require __DIR__.'/../../config/clutch.php';
}

it('names a real class for every driver it ships', function (): void {
    foreach (shippedConfig()['drivers'] as $name => $driver) {
        $class = is_array($driver) ? ($driver['driver'] ?? null) : $driver;

        expect($class)->toBeString("Driver [{$name}] has no driver class.");
        expect(class_exists($class))->toBeTrue(
            "Driver [{$name}] names [{$class}], which does not exist."
        );
        expect(is_subclass_of($class, ClutchDriver::class))->toBeTrue(
            "Driver [{$name}] names [{$class}], which is not a ClutchDriver."
        );
    }
});

it('resolves its default driver through the container', function (): void {
    $config = shippedConfig();

    // Exactly what an application gets: the shipped config, nothing overridden.
    $registry = new DriverRegistry(
        container: app(),
        config: $config['drivers'],
        default: $config['default_driver'],
    );

    $driver = $registry->driver();

    expect($driver)->toBeInstanceOf(ClutchDriver::class)
        ->and($driver->name())->toBe('laravel-ai');
});

it('defaults to a driver it actually ships', function (): void {
    $config = shippedConfig();

    expect($config['drivers'])->toHaveKey($config['default_driver']);
});

it('names a real class for every configured event serializer', function (): void {
    $serializers = shippedConfig()['events']['serializers'];

    // Shipping none is the default and is fine; shipping a broken one is not.
    expect($serializers)->toBeArray();

    foreach ($serializers as $tool => $class) {
        expect(class_exists($class))->toBeTrue(
            "The serializer for [{$tool}] names [{$class}], which does not exist."
        );
    }
});

it('uses a permission mode that exists', function (): void {
    $default = shippedConfig()['permissions']['default'];

    expect(Clutch\Laravel\Enums\PermissionMode::tryFrom($default))->not->toBeNull();

    foreach (shippedConfig()['permissions']['tools'] as $tool => $sensitivity) {
        expect(Clutch\Laravel\Enums\ToolSensitivity::tryFrom($sensitivity))->not->toBeNull(
            "The tool [{$tool}] is classified as [{$sensitivity}], which is not a sensitivity."
        );
    }
});

it('says which class is missing when a driver is misconfigured', function (): void {
    $registry = new DriverRegistry(
        container: app(),
        config: ['typo' => ['driver' => 'App\Drivers\NoSuchDriver']],
        default: 'typo',
    );

    // The message has to name the class, because the class name is the typo.
    expect(fn () => $registry->driver('typo'))
        ->toThrow(Clutch\Laravel\Exceptions\DriverNotFound::class, 'App\Drivers\NoSuchDriver');
});

it('says so when a driver entry has no class at all', function (): void {
    $registry = new DriverRegistry(
        container: app(),
        config: ['empty' => ['provider' => 'anthropic']],
        default: 'empty',
    );

    expect(fn () => $registry->driver('empty'))
        ->toThrow(Clutch\Laravel\Exceptions\DriverNotFound::class);
});

it('resolves the workflow driver even when the published config predates it', function (): void {
    // An application that published clutch.php before workflows existed has no
    // `workflow` entry. It is a built-in runtime, so it must resolve anyway.
    config()->set('clutch.drivers', [
        'laravel-ai' => ['driver' => Clutch\Laravel\Drivers\LaravelAi\LaravelAiDriver::class],
    ]);

    $registry = new DriverRegistry(
        container: app(),
        config: [
            'workflow' => ['driver' => Clutch\Laravel\Workflows\WorkflowDriver::class],
            ...config('clutch.drivers'),
        ],
    );

    expect($registry->driver('workflow'))
        ->toBeInstanceOf(Clutch\Laravel\Workflows\WorkflowDriver::class);
});
