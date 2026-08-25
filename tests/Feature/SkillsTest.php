<?php

declare(strict_types=1);

use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Skills\Skill;
use Clutch\Laravel\Skills\SkilledAgent;
use Clutch\Laravel\Skills\SkillRegistry;
use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;

it('requires a description, because that is how a skill gets chosen', function (): void {
    expect(fn () => new Skill('refactor', '', 'Body.'))
        ->toThrow(InvalidArgumentException::class, 'unreachable');
});

it('rejects a file path that escapes the skill', function (): void {
    expect(fn () => new Skill('refactor', 'Refactor safely.', 'Body.', ['../secrets.env' => 'x']))
        ->toThrow(InvalidArgumentException::class, 'traverse');
});

it('advertises only names and descriptions in the catalogue', function (): void {
    $registry = new SkillRegistry([
        new Skill('careful-refactors', 'Make small, low-risk code changes.', 'A very long body...'),
        new Skill('release-notes', 'Write release notes in our house style.', 'Another long body...'),
    ]);

    $catalogue = $registry->catalogue();

    expect($catalogue)
        ->toContain('careful-refactors: Make small, low-risk code changes.')
        ->toContain('release-notes: Write release notes in our house style.')
        // The bodies stay out until a skill is actually used.
        ->not->toContain('A very long body')
        ->not->toContain('Another long body');
});

it('renders a skill with its supporting files when used', function (): void {
    $skill = new Skill(
        'careful-refactors',
        'Make small, low-risk code changes.',
        'Prefer minimal diffs.',
        ['references/checklist.md' => 'Check the tests.'],
    );

    expect($skill->render())
        ->toContain('## Skill: careful-refactors')
        ->toContain('Prefer minimal diffs.')
        ->toContain('### references/checklist.md')
        ->toContain('Check the tests.');
});

it('loads a skill from a directory', function (): void {
    $path = sys_get_temp_dir().'/clutch-skill-'.uniqid();
    mkdir($path.'/references', 0755, true);

    file_put_contents($path.'/SKILL.md', "---\nname: shipping\ndescription: How we ship.\n---\n\nAlways tag the release.");
    file_put_contents($path.'/references/checklist.md', 'Run the tests.');

    $skill = Skill::fromDirectory($path);

    expect($skill->name)->toBe(basename($path))
        ->and($skill->description)->toBe('How we ship.')
        ->and($skill->content)->toContain('Always tag the release.')
        ->and($skill->files)->toHaveKey('references/checklist.md');

    array_map('unlink', glob($path.'/references/*'));
    rmdir($path.'/references');
    unlink($path.'/SKILL.md');
    rmdir($path);
});

it('discovers every skill directory under a path', function (): void {
    $root = sys_get_temp_dir().'/clutch-skills-'.uniqid();
    foreach (['alpha', 'beta'] as $name) {
        mkdir($root.'/'.$name, 0755, true);
        file_put_contents($root.'/'.$name.'/SKILL.md', "description: The {$name} skill.\n\nBody.");
    }

    $registry = (new SkillRegistry)->discover($root);

    expect($registry->all())->toHaveCount(2)
        ->and($registry->has('alpha'))->toBeTrue()
        ->and($registry->get('beta')->description)->toBe('The beta skill.');

    foreach (['alpha', 'beta'] as $name) {
        unlink($root.'/'.$name.'/SKILL.md');
        rmdir($root.'/'.$name);
    }
    rmdir($root);
});

it('narrows the registry to a named subset', function (): void {
    $registry = new SkillRegistry([
        new Skill('a', 'First.', 'x'),
        new Skill('b', 'Second.', 'y'),
    ]);

    expect($registry->only(['a'])->all())->toHaveCount(1)
        ->and($registry->only(['a'])->has('b'))->toBeFalse();
});

it('names the registered skills when one is missing', function (): void {
    $registry = new SkillRegistry([new Skill('a', 'First.', 'x')]);

    expect(fn () => $registry->get('nope'))
        ->toThrow(InvalidArgumentException::class, 'Registered skills: a');
});

it('appends the catalogue to an agent\'s instructions without touching anything else', function (): void {
    $agent = new ResearchAgent;
    $registry = new SkillRegistry([new Skill('shipping', 'How we ship.', 'Tag the release.')]);

    $wrapped = SkilledAgent::wrap($agent, $registry);

    expect($wrapped)->toBeInstanceOf(SkilledAgent::class)
        ->and($wrapped->instructions())
        ->toContain('You research competitors')
        ->toContain('shipping: How we ship.');
});

it('does not wrap an agent when there are no skills', function (): void {
    $agent = new ResearchAgent;

    expect(SkilledAgent::wrap($agent, new SkillRegistry))->toBe($agent);
});

it('gives a session the skills it was built with', function (): void {
    app()->instance(SkillRegistry::class, new SkillRegistry([
        new Skill('shipping', 'How we ship.', 'Tag the release.'),
        new Skill('support', 'How we answer tickets.', 'Be brief.'),
    ]));

    Clutch::fake();

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->user())
        ->withSkills(['shipping'])
        ->create();

    expect($session->configuration['skills'])->toBe(['shipping']);
});
