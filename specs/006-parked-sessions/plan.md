# Plan — 006 parked sessions API

1. `UserSessionStore::statesOf()` (DBAL, bound `IN`, datetime conversion through the DBAL type).
2. `SessionStatus` enum + `SessionState` value object.
3. `UserSessionManager::sessionState()`, `sessionStates()`, `endSession()`, `rekey()` (stateless, worker-safe).
4. Integration tests on `SessionsKernelTestCase` (`ParkedSessionsTest`).
5. Docs: USAGE section, CHANGELOG Unreleased, UPGRADING Unreleased.
