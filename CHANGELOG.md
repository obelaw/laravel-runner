# Changelog

All notable changes to `obelaw/laravel-runner` will be documented in this file.

## [1.0.0] - 2026-11-26

### Added
- Stable `v1.0.0` release supporting PHP `^8.2` and Laravel `^11.0 | ^12.0 | ^13.0`.
- `RunnerPool::flush()` and automatic path deduplication in `RunnerPool::addPath()`.
- Comprehensive Pest test suite covering DAG dependency sorting, cycle/missing detection, cascade failure blocking, `TYPE_ONCE`/`TYPE_ALWAYS`, cron scheduling, and Artisan CLI commands.
- GitHub Actions CI matrix, `LICENSE`, `.gitattributes`, and `phpunit.xml`.

### Fixed
- `RunnerCommand::handle()` now returns `Command::SUCCESS` / `Command::FAILURE` exit codes for CI/CD pipelines.
- `RunnerLog::markCompleted()` now accepts `mixed` output and JSON-encodes non-string return values instead of throwing `TypeError`.
