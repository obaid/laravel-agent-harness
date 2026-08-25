<?php

declare(strict_types=1);

namespace Clutch\Laravel\Skills;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The skills available to a session.
 *
 * Registered globally in configuration or on a service provider, and narrowed
 * per session by the builder. The registry renders the catalogue the model
 * chooses from, and hands back the body of whichever skill it picks.
 */
class SkillRegistry
{
    /** @var array<string, Skill> */
    protected array $skills = [];

    /**
     * @param  array<int, Skill|array<string, mixed>>  $skills
     */
    public function __construct(array $skills = [])
    {
        foreach ($skills as $skill) {
            $this->add($skill instanceof Skill ? $skill : Skill::fromArray($skill));
        }
    }

    public function add(Skill $skill): static
    {
        $this->skills[$skill->name] = $skill;

        return $this;
    }

    /**
     * Register every skill directory under a path.
     *
     * Each subdirectory holding a SKILL.md becomes one skill, which lets an
     * application keep its skills as files rather than as PHP arrays.
     */
    public function discover(string $path): static
    {
        foreach (glob(rtrim($path, '/').'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_readable($directory.'/SKILL.md')) {
                $this->add(Skill::fromDirectory($directory));
            }
        }

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->skills[$name]);
    }

    public function get(string $name): Skill
    {
        return $this->skills[$name] ?? throw new InvalidArgumentException(
            "No skill named [{$name}] is registered. Registered skills: ".
            (implode(', ', array_keys($this->skills)) ?: 'none').'.'
        );
    }

    /**
     * @return Collection<string, Skill>
     */
    public function all(): Collection
    {
        return new Collection($this->skills);
    }

    /**
     * Narrow the registry to a named subset, for a session that should only
     * see some of what the application registered.
     *
     * @param  array<int, string>  $names
     */
    public function only(array $names): self
    {
        $subset = new self;

        foreach ($names as $name) {
            $subset->add($this->get($name));
        }

        return $subset;
    }

    /**
     * The catalogue advertised to the model.
     *
     * Only names and descriptions, so a large library of skills costs a line
     * each rather than their full bodies.
     */
    public function catalogue(): string
    {
        if ($this->skills === []) {
            return '';
        }

        $lines = (new Collection($this->skills))
            ->map(fn (Skill $skill): string => $skill->summary())
            ->implode("\n");

        return <<<TEXT
        The following skills are available. Each one holds detailed instructions
        for a particular kind of task. When a task matches a skill, follow that
        skill's instructions in preference to your general approach.

        {$lines}
        TEXT;
    }

    public function isEmpty(): bool
    {
        return $this->skills === [];
    }
}
