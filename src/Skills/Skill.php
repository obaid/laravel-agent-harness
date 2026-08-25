<?php

declare(strict_types=1);

namespace Clutch\Laravel\Skills;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

/**
 * A reusable bundle of instructions an agent can reach for when it needs them.
 *
 * Instructions describe how an agent always behaves. A skill describes how to
 * do one particular job, and only occupies context while that job is at hand:
 * the model sees the name and description of every skill, and pulls in the body
 * of the one it actually needs.
 *
 * That distinction is the point. Ten procedures pasted into instructions cost
 * tokens on every turn of every session. Ten skills cost a line each until one
 * is used.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class Skill implements Arrayable
{
    /**
     * @param  array<string, string>  $files  extra text files, keyed by skill-relative path
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $content,
        public array $files = [],
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A skill needs a name.');
        }

        if (trim($description) === '') {
            throw new InvalidArgumentException(
                "The skill [{$name}] needs a description. The model chooses skills by their ".
                'description alone, so an empty one makes the skill unreachable.'
            );
        }

        foreach (array_keys($files) as $path) {
            if (str_starts_with((string) $path, '/') || str_contains((string) $path, '..')) {
                throw new InvalidArgumentException(
                    "The skill [{$name}] has the file path [{$path}]. Paths must be relative to the ".
                    'skill and may not traverse upwards.'
                );
            }
        }
    }

    /**
     * Build a skill from an array, for configuration-defined skills.
     *
     * @param  array<string, mixed>  $skill
     */
    public static function fromArray(array $skill): self
    {
        return new self(
            name: (string) ($skill['name'] ?? ''),
            description: (string) ($skill['description'] ?? ''),
            content: (string) ($skill['content'] ?? ''),
            files: (array) ($skill['files'] ?? []),
        );
    }

    /**
     * Load a skill from a directory holding a SKILL.md and optional extra files.
     *
     * The convention mirrors how skills are written for coding agents, so the
     * same directory can serve both.
     */
    public static function fromDirectory(string $path, ?string $name = null): self
    {
        $path = rtrim($path, '/');
        $body = $path.'/SKILL.md';

        if (! is_readable($body)) {
            throw new InvalidArgumentException("No readable SKILL.md was found in [{$path}].");
        }

        $content = (string) file_get_contents($body);

        return new self(
            name: $name ?? basename($path),
            description: self::describeFrom($content),
            content: $content,
            files: self::readSupportingFiles($path),
        );
    }

    /**
     * The one-line summary the model uses to choose this skill.
     *
     * Prefers a `description:` front-matter key, and falls back to the first
     * line of prose so a plain markdown file still works.
     */
    private static function describeFrom(string $content): string
    {
        if (preg_match('/^description:\s*(.+)$/mi', $content, $matches) === 1) {
            return trim($matches[1], " \t\"'");
        }

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);

            if ($line !== '' && ! str_starts_with($line, '#') && ! str_starts_with($line, '---')) {
                return $line;
            }
        }

        return 'No description provided.';
    }

    /**
     * @return array<string, string>
     */
    private static function readSupportingFiles(string $path): array
    {
        $files = [];

        foreach (glob($path.'/{references,templates,scripts}/*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file) && is_readable($file)) {
                $files[ltrim(str_replace($path, '', $file), '/')] = (string) file_get_contents($file);
            }
        }

        return $files;
    }

    /**
     * Render the skill as the model sees it once selected.
     */
    public function render(): string
    {
        $rendered = "## Skill: {$this->name}\n\n{$this->content}";

        foreach ($this->files as $path => $contents) {
            $rendered .= "\n\n### {$path}\n\n{$contents}";
        }

        return $rendered;
    }

    /**
     * The single line advertising this skill in the system prompt.
     */
    public function summary(): string
    {
        return "- {$this->name}: {$this->description}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'content' => $this->content,
            'files' => $this->files,
        ];
    }
}
