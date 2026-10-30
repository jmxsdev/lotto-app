# Apply Progress — `pago-devoluciones-anulacion`

Worktree: `/home/gzuz/Documentos/lotto-app-worktrees/pago-devoluciones-anulacion`
Branch: `feature/pago-devoluciones-anulacion`
Strategy: `feature-branch-chain` / `auto-chain`
Test execution: user-run only (the agent never executes tests).

## WU1 — `ApuestaPolicy` reason extraction

Status: **complete / GREEN (user-reported)**

Files:
- `backend/app/Policies/ApuestaPolicy.php`
- `backend/app/Http/Controllers/Api/TicketController.php`
- `backend/tests/Feature/EliminacionApuestasTest.php`
- `backend/tests/Unit/ApuestaPolicyAnulacionTest.php` (new)

Behavior:
- `motivoNoAnulable(User, Apuesta, ?int $minutosEfectivos): ?string` added with codes:
  `estado`, `con_resultado`, `sorteo_ocurrido`, `fuera_de_ventana`, `sin_ownership`.
- Precedence preserved: estado -> con_resultado -> sorteo_ocurrido -> fuera_de_ventana -> sin_ownership.
- `delete()` delegates to the new method; rule order/behavior unchanged.
- `TicketController::destroy` uses one mapper (`mensajeRechazo`) instead of re-deriving reasons per line.
- Existing message strings preserved byte-identical except the new `sin_ownership` mapping described below.

Test command (user-run):
```bash
cd backend && php artisan test --filter='ApuestaPolicyAnulacion|EliminacionApuestas|TicketEliminacion'
```
Result: **GREEN** (user-reported).

Deviations / notes:
- WU1 maps `sin_ownership` to `No autorizado.` while keeping status **422**. WU3-A will flip the status to 403 when `TicketController::destroy` delegates to `TicketAnnulacionService`/`TicketAnulacionException`.
- The cross-tenant test uses a `grupo` user from another grupo because a `taquilla` user requires `X-Device-MAC` (`VerifyMac`) and an `agencia` user is blocked by the route role middleware before reaching the policy.
- Review size is above the 400-line inner target for WU1 (new unit test scaffolding); still below the 800-line session budget.

## Next

WU2 — `PagoController` egreso-only + transaction/lock + engine-authoritative amounts + tests + payments collection.
