# includes/root-boot/

Procedural bootstrap functions for the plugin entry point (`404-solution.php`).

## What belongs here

The global `abj404_*` functions that the WordPress plugin entry-point file
delegates to: early settings/path helpers, the runtime clock/logging helpers,
request benchmarking instrumentation, the boot-failure shutdown handler, the
degraded-install admin surface, cron-action listeners, the locale filter,
admin-notice renderers, localhost diagnostics, and the `admin_init` readiness
handlers.

The class autoloader itself stays in `404-solution.php` (it cannot be moved here
because `AutoloaderTraitDependenciesCompletenessTest` reads its
`$traitDependencyClasses` array from the entry-point source text).

These are **procedural global functions**, not classes. They are registered
with WordPress as hook callbacks by string name (e.g.
`add_action('template_redirect', 'abj404_404listener')`), so they must remain
global functions with stable names and signatures. They are intentionally NOT
converted to class methods or traits: doing so would change the hook
registration contract.

Each file here is `require_once`'d from `404-solution.php` immediately after the
autoloader is registered, so every function is defined before any top-level
executable statement (the `add_action`/`add_filter` registrations, the option
read, the benchmark calls) that references it runs.

## What does NOT belong here

- Service-container registration. That lives in `includes/bootstrap/`
  (`CoreServiceRegistration.php`, etc.) and is wired through
  `includes/bootstrap.php` / `includes/Loader.php`. Do not mix DI registration
  into this directory.
- Business logic, data access, or view rendering of the plugin proper. Those
  live in their own `includes/` subtrees and are loaded lazily via the
  autoloader. Functions here only *bootstrap* and *route to* that code.

## Why some entry-point functions stay in `404-solution.php`

A set of structural tests (e.g. `CronListenerServiceWiringTest`,
`OpcacheStaleAfterUpgradeTest`, `PageLoadFallbackHookRegistrationTest`,
`FeedbackTransportCronListenerTest`, `SqlFileIntegrityListCompletenessTest`,
`BlankPagePreventionTest`, `CollationAutoRecoveryTest`) read the source *text*
of `404-solution.php` and assert that specific `abj404_*` functions are defined
there (and inspect their bodies). Those functions cannot be moved without
breaking those tests, so they remain in the entry-point file. Everything not
pinned by such a test is extracted here.

## Direct-access guard

Every file in this directory begins with `<?php` and the standard
`if (!defined('ABSPATH')) { exit; }` guard, enforced by `DirectAccessGuardTest`.
