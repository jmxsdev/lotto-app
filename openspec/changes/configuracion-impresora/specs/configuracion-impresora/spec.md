# Delta for configuracion-impresora

## Purpose

`printer-config.json` under `userData` becomes the single source of printer configuration. Environment variables and `process.env` leave the printing config path; a validated, atomic writer persists operator settings.

## ADDED Requirements

### Requirement: File configuration is the only source of truth

Printing configuration MUST come only from `userData/printer-config.json` and built-in defaults. The config module MUST NOT read `process.env`, `LOTTO_*`, or the `envValue`/`pick` helpers. `ResolveConfigInput` and `CreatePrintingOptions` MUST NOT expose an `env` field.

#### Scenario: Environment is ignored

- GIVEN `LOTTO_PRINTER_NAME` and other `LOTTO_*` variables are set in the process environment
- WHEN configuration resolves
- THEN the resolved values come only from the file and defaults
- AND no `process.env` read occurs in the printing config module

#### Scenario: Contracts drop env

- GIVEN the printing contracts
- WHEN a caller builds resolution or printing options
- THEN neither `ResolveConfigInput` nor `CreatePrintingOptions` has an `env` field

### Requirement: Default configuration

With no file present, the system MUST resolve `printerName` `null`, paper `58mm`, columns `32`, dots `384`, codePage `cp850`, cut `partial`, feedLines `3`, and cutPerBlock `true`.

#### Scenario: Missing file yields defaults

- GIVEN `printer-config.json` does not exist
- WHEN configuration resolves
- THEN every default above applies and `printerName` is `null`
- AND no warning is emitted

### Requirement: Configuration file reading

A valid JSON object MUST merge its known fields over defaults. A missing file MUST resolve defaults silently; malformed or non-object JSON MUST resolve defaults and append a warning.

#### Scenario: Valid file overrides defaults

- GIVEN a JSON object with `paper: "80mm"` and `codePage: "cp858"`
- WHEN configuration resolves
- THEN those fields override the defaults and the rest keep defaults

#### Scenario: Malformed file warns

- GIVEN `printer-config.json` contains invalid JSON
- WHEN configuration resolves
- THEN defaults apply and a warning names `printer-config.json`

### Requirement: Configuration writer

`saveConfig` MUST validate known fields, merge input over current config and defaults, and write the canonical object atomically (temp file in the same directory, then rename) to `printer-config.json`. It MUST return the persisted config. Printer existence MUST NOT be validated here (Phase 2 IPC). A write failure MUST raise typed `E_CONFIG_WRITE_FAILED`, not `E_CONFIG_INVALID`.

#### Scenario: Save persists atomically

- GIVEN valid field values
- WHEN `saveConfig` runs
- THEN the file is written via temp + rename
- AND a subsequent resolve reads the same values

#### Scenario: Write failure is typed

- GIVEN the target directory is not writable
- WHEN `saveConfig` runs
- THEN it rejects with `E_CONFIG_WRITE_FAILED`

### Requirement: Startup test hook removal

Phase 1 MUST remove `printTest` from `EffectiveConfig`, remove `maybeRunStartupTest` and `getWindow` from the printing facade, and delete the `main.cjs` startup hook. The commissioning slip's instruction MUST no longer advertise `LOTTO_PRINT_TEST=1`; its layout stays otherwise unchanged.

#### Scenario: No startup test runs

- GIVEN the app starts
- WHEN `whenReady` runs
- THEN no startup print-test invocation occurs
- AND no `maybeRunStartupTest` or `getWindow` symbol remains in the facade

## Out of Scope

IPC channels (`print:list-printers`, `get-config`, `set-config`, `test`), preload, settings UI, auto-prompt, page error surfacing, office mode, and version bump/release are future phases, not requirements here.
