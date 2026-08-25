# Contributing

Thanks for looking. Bug reports and pull requests are both welcome.

## Getting set up

You need PHP 8.3 or newer and Composer.

```bash
git clone https://github.com/obaid/laravel-clutch
cd laravel-clutch
composer install
```

The suite runs against SQLite in memory, so there is nothing else to start:

```bash
vendor/bin/pest
```

Three more checks run in CI and are worth running before you push:

```bash
vendor/bin/pest --parallel      # the suite, faster
vendor/bin/phpstan analyse      # level 6, via larastan
vendor/bin/pint --test          # formatting, --test to check without writing
```

CI additionally runs the suite against real PostgreSQL and Redis, and across
PHP 8.3 and 8.4 with Laravel 12 and 13, at both `prefer-lowest` and
`prefer-stable`. Most changes do not need you to reproduce that locally, but if
you touch locking, leases, or anything transactional, be aware that SQLite
hides problems PostgreSQL will not.

## What a good pull request looks like

**Start with an issue for anything substantial.** A quick conversation about
the approach saves both of us rewriting it later. Typo fixes and obvious bugs
can go straight to a pull request.

**Bring a test that fails without your change.** The tests are named after the
behaviour they protect rather than the method they call, and the important ones
name an invariant, for example `a run cannot be resumed by two workers at
once`. Follow that when you add one.

**Match the surrounding code.** Pint settles formatting. For everything else,
read the file you are editing and write what it would have written.

**Say what you changed and why in the pull request body.** If it changes
behaviour, say what breaks.

## The parts that are easy to get wrong

Some of this package is more subtle than it looks. Three things worth knowing
before you touch them:

`RunCoordinator` is the only place run and session state changes. If you find
yourself writing a status transition somewhere else, that is the bug.

Events are written before they are broadcast, and the queue job is dispatched
only after the run's state has committed. Both orderings are load-bearing and
both have tests that will tell you so.

Tool protections only apply to tools that went through `Clutch::policy()`.
Laravel AI runs tools inside its own generation loop, so that wrapper is the
single point where the ledger, guards, deadlines and spill policy can sit. This
has already been the cause of one release where every protection was silently
inert, so treat changes near it carefully.

## Documentation

The README is the guide. The docs site is built from it, so edit the README
rather than a copy under `docs/`.

`docs/RECIPES.md` and `docs/AGENT-SETUP.md` are their own pages.
`docs/bin/stage` prepares all of it for Jekyll and also generates
`llms-full.txt`, so run it after changing any of those files.

Building the site locally needs Ruby 3.2 or newer:

```bash
docs/bin/stage
cd docs && bundle install && bundle exec jekyll serve
```

## Versioning

The project follows [Semantic Versioning](https://semver.org). Before v1.0 a
breaking change needs a changelog entry and an upgrade note, so if your change
breaks something, add both.

The `laravel/ai` constraint deliberately pins the minor. Laravel AI is pre-1.0
and a new minor may move the interfaces this package builds on, so bumping it
is its own reviewed change rather than something that rides along.

## Reporting a security issue

Please do not open a public issue. See [SECURITY.md](SECURITY.md).
