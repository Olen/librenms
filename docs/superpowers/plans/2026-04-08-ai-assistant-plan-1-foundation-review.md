# AI Assistant Plan 1: Foundation — Review

**Date:** 2026-04-08
**Reviewer:** Claude / olen
**Plan:** [2026-04-08-ai-assistant-plan-1-foundation.md](2026-04-08-ai-assistant-plan-1-foundation.md)
**Status:** Review complete

---

## Overall Assessment

Well-structured plan with correct dependency ordering across 16 tasks. Test-first approach for most layers, incremental commits, and consistent RBAC filtering throughout. Ready to implement with the caveats below.

---

## Concerns

### 1. PluginManager Integration Assumptions (Task 3)

The plan calls `$this->hooksFor($hookType, [], null)` and `$this->getSettings($hook['plugin_name'])` without verifying these methods exist on the current PluginManager. The `call()` method's internal API may differ significantly. This is the highest-risk task in the plan.

**Recommendation:** Read `app/Plugins/PluginManager.php` before starting Task 3 to validate the assumptions. Adapt the dispatch methods to match the actual internal API.

### 2. Migration Loading for Plugins

Migrations are created in `app/Plugins/AiAssistant/Migrations/` but Laravel's migrator doesn't auto-discover migrations from plugin directories. The plan doesn't explain how these migrations will be registered — a migration service provider or manual registration in the plugin boot process is needed.

**Recommendation:** Add a step to Task 9 (or a new task before it) that wires up migration loading, following whatever pattern other LibreNMS plugins use for schema changes.

### 3. Route Loading Gap

Same issue as migrations — `app/Plugins/AiAssistant/routes.php` is created in Task 14 but there's no mechanism to load it into Laravel's router. Task 15 acknowledges this ("check how ExamplePlugin's routes are loaded") but leaves it as a debugging exercise.

**Recommendation:** Resolve route loading upfront in Task 14 rather than discovering it as a test failure in Task 15. Check how existing plugins register routes.

### 4. AiChatController Builds Everything Inline

The controller manually instantiates `OpenAiCompatibleProvider`, `CostTracker`, `LlmService`, and `ConversationManager` on every request. This creates tight coupling and makes testing harder.

**Recommendation:** Extract a factory method or use a service provider to build the service stack. This also avoids duplicating the construction logic when the IRC adapter or monitoring engine needs the same services.

### 5. `hasAccess` Scope Availability on Models

Several tools call `Port::query()->hasAccess($user)` and `Eventlog::query()->hasAccess($user)` directly. In LibreNMS, the `hasAccess` scope lives on Device, not necessarily on Port or Eventlog. The `GetSensors` tool correctly uses `whereHas('device', fn($q) => $q->hasAccess($user))`, but `GetPorts` and `GetEventLog` may not work as written.

**Recommendation:** Verify which models have `hasAccess` scopes. For models that don't, use the `whereHas('device', ...)` pattern consistently.

### 6. Duplicated `toFunctionDefinition()` Across All Tools

Every tool implements `toFunctionDefinition()` identically — 12 copies of the same 5-line method. This is unnecessary duplication.

**Recommendation:** Create an `AbstractAiTool` base class (or trait) with the default `toFunctionDefinition()` implementation. Tools only override if they need custom behavior.

### 7. Foreign Key Naming Confusion on Messages Table

The `ai_messages` migration uses `$table->foreignId('session_id')->constrained('ai_sessions')` which creates a column referencing `ai_sessions.id` (the auto-increment PK). The `AiSession` model also has a string `session_id` column (the UUID-style identifier). The `messages()` relationship works correctly via `HasMany` on the PK, but the column naming overlap (`session_id` meaning two different things) is a bug waiting to happen.

**Recommendation:** Rename the foreign key column in `ai_messages` to `ai_session_id` to disambiguate from the string session identifier.

---

## What's Good

- **Layered TDD approach** — tests written before implementations for Tasks 6-13
- **Consistent RBAC** — every tool filters via `hasAccess()`
- **Cost controls from the start** — daily/monthly/per-query budgets, not an afterthought
- **Retry with exponential backoff** in `LlmService::callWithRetry()` addresses the error handling gap from the design review
- **Status callbacks** for tool execution feedback during multi-tool queries
- **Tool auto-discovery** via glob + class reflection matches LibreNMS patterns
- **Incremental commits** — each task produces a working, committable unit
- **Correct dependency ordering** — each task builds on the previous

---

## Implementation Priority

Start with Task 3 (PluginManager wiring) after reading the current PluginManager code. This is the highest-risk task and the one most likely to require plan adjustments. If the PluginManager's internal API differs from assumptions, it will cascade into Tasks 4, 9, 14, and 15.
