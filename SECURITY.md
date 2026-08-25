# Security policy

## Supported versions

This package is pre-1.0. Security fixes land on the latest minor, and there is
no backporting to earlier ones. Track the current release.

## Reporting a vulnerability

Please report privately rather than opening a public issue.

Use GitHub's private vulnerability reporting on this repository: go to the
**Security** tab and choose **Report a vulnerability**. That opens a private
thread visible only to the maintainers.

Useful things to include, as far as you have them:

- what an attacker can do, and what they need to already have
- the affected version
- steps to reproduce, or a failing test
- whether the issue is in Clutch itself or in how it uses Laravel AI or Laravel

You can expect an acknowledgement within a few days. Once a fix is out, you
will be credited in the changelog unless you would rather not be.

## Scope

Worth reporting: anything that lets one participant reach another's sessions,
runs, approvals or artifacts; anything that lets a tool call bypass an approval
or a permission mode; anything that leaks secrets into events, logs or
artifacts past the redactor.

Out of scope: findings that require an already-compromised application, and
misconfiguration in an application using this package rather than a defect in
the package itself. The routes ship behind configurable middleware, so an
application that opens them to unauthenticated traffic is a configuration
problem, not a vulnerability here.
