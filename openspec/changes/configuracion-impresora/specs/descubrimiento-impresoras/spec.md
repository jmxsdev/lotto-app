# Delta for descubrimiento-impresoras

## Purpose

Discovery becomes a UI-ready surface: enumerate available printers with their metadata, suggest a default, and validate configured names against the enumerated list.

## ADDED Requirements

### Requirement: Printer listing

`listPrinters()` MUST return, for each enumerated printer, its `name`, `displayName`, and every available Electron metadata field; no metadata MUST be discarded.

#### Scenario: List preserves metadata

- GIVEN `getPrintersAsync` returns entries with `name`, `displayName`, and extra fields
- WHEN `listPrinters` runs
- THEN each result carries `name`, `displayName`, and every extra field

### Requirement: Printer suggestion

`suggested` MUST be the only printer when exactly one is enumerated. Otherwise it MUST be the first `name` or `displayName` matching `/pos|thermal|ticket|receipt|58|80|xp|tm/i`, else `null`. A suggestion MUST NOT be saved automatically.

#### Scenario: Single printer wins

- GIVEN exactly one enumerated printer
- WHEN a suggestion is computed
- THEN `suggested` equals that printer

#### Scenario: Token match picks the first candidate

- GIVEN several printers, one named `POS-58`
- WHEN a suggestion is computed
- THEN `suggested` is that printer

#### Scenario: No match is null

- GIVEN printers with none matching the token pattern
- WHEN a suggestion is computed
- THEN `suggested` is `null`
- AND nothing is persisted

### Requirement: Printer resolution

Resolution MUST match the configured name exactly, then case-insensitively exactly. Substring matches MUST NOT occur (`PostScript` ≠ `POS`). The configured name MUST exist in `getPrintersAsync`.

#### Scenario: Exact then case-insensitive

- GIVEN queue `POS-58` is enumerated
- WHEN resolving `POS-58`, then `pos-58`
- THEN both resolve to `POS-58`

#### Scenario: No substring matching

- GIVEN only `PostScript` and `POS-58` are enumerated
- WHEN resolving `POS`
- THEN no match occurs

### Requirement: Name validation

Enumerated names containing parentheses and other printable characters MUST be accepted. Names containing control characters or newlines MUST be rejected. The real gate MUST be existence in the system list.

#### Scenario: Printable names accepted

- GIVEN queue `POS-58 (Copy 1)` is enumerated
- WHEN the name is validated
- THEN it is accepted

#### Scenario: Control characters rejected

- GIVEN a name containing a newline or control character
- WHEN the name is validated
- THEN it is rejected

### Requirement: Typed discovery errors

Discovery and validation failures MUST raise typed errors: `E_PRINTER_UNCONFIGURED`, `E_PRINTER_NOT_FOUND`, `E_DISCOVERY_TIMEOUT`, `E_CONFIG_INVALID`, each carrying available names or the suggestion where applicable. Error messages MUST NOT instruct the user to set environment variables.

#### Scenario: Unconfigured lists queues

- GIVEN no configured name
- WHEN resolution runs
- THEN `E_PRINTER_UNCONFIGURED` carries the available queue names

#### Scenario: No env hint in messages

- GIVEN any discovery error message
- WHEN the operator reads it
- THEN it never references `LOTTO_*` or environment variables

## Out of Scope

IPC channel `print:list-printers`, preload bridge, settings UI modal, auto-prompt, page error surfacing, office mode, and version bump/release are future phases, not requirements here.
