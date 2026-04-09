# Security Review: feature/ai-endpoint

**Date:** 2026-04-09
**Branch:** feature/ai-endpoint
**Reviewer:** Claude Opus 4.6
**Focus:** Access control, security, general code quality

---

## Summary

The branch adds an AI chat assistant as a LibreNMS plugin with 17 data-access tools, an LLM service layer, conversation management, and a web UI. The tool-calling architecture (LLM must call tools, tools enforce device-level RBAC) is a strong defense-in-depth design against prompt injection. However, the higher-level authorization layers are missing or incomplete.

---

## Critical Issues

### 1. Session Hijacking — No User Ownership Verification

**File:** `app/Plugins/AiAssistant/Services/ConversationManager.php:107`

Sessions are looked up by `session_id` alone:
```php
$session = AiSession::where('session_id', $sessionId)->first();
```

Session IDs are generated client-side with low entropy (`web-${Date.now()}-${random 9 chars}`). An attacker can guess another user's session ID and read their conversation history, which contains network topology, alerts, and device details. The expired-session path is worse — it reuses the session object without updating `user_id`.

**Fix:** Add `->where('user_id', $user->user_id)` to the session lookup.

---

### 2. Path Traversal in GetTimeSeries

**File:** `app/Plugins/AiAssistant/Tools/GetTimeSeries.php:245-248`

The `custom` metric type passes `$metricName` directly into a file path:
```php
case 'custom':
    $file = $rrdDir . '/' . $metricName . '.rrd';
    return file_exists($file) ? $file : null;
```

A tool call with `metric_name="../../etc/secret"` escapes the RRD directory. The `glob()` calls on lines 236 and 241 also concatenate `$metricName` unsanitized.

**Fix:** Use `basename()` and reject any input containing `..` or `/`.

---

### 3. Settings Page Missing Authorization — API Key Exposure

**File:** `app/Plugins/AiAssistant/Settings.php:31-39`

Does not override `authorize()`. The base `SettingsHook` returns `true` for all users. Combined with `settings.blade.php:18` populating the API key in the HTML `value` attribute, any authenticated user can steal the LLM API key.

**Fix:**
- Override `authorize()` to require admin level
- Never populate the API key value in the form — use "leave blank to keep current" pattern

---

## High-Priority Issues

### 4. Chat Endpoint Missing Authorization

**File:** `app/Plugins/AiAssistant/Http/AiChatController.php` and `routes.php:30-32`

The `/plugin/ai/chat` route requires only `auth` middleware. The Page and Menu hooks check `$user->can('global-read')`, but the API endpoint does not. Any authenticated user can POST directly to the endpoint and query network data through the LLM tools.

**Fix:** Add authorization check in the controller or route middleware.

---

### 5. No Tool-Level Access Control

**Files:** `AiToolInterface.php`, `AbstractAiTool.php`, `LlmService.php:206-215`

There is no mechanism to restrict which tools a user can access. All 17 tools are available to every authenticated user. The interface has no `authorize()` method, `AbstractAiTool` has no authorization logic, and `LlmService::executeTool()` performs no permission check.

The only access control is device-level scoping via `hasAccess($user)` within individual tool queries. This means:
- Users with access to zero devices can still call all tools
- Device-independent data (external events, network summaries with empty device lists) leaks to all users
- No way to restrict sensitive tools (e.g., syslog, event logs) to admin-only

**Fix:** Add an `authorize(User $user): bool` method to `AiToolInterface` and check it in `LlmService::executeTool()`. At minimum, mirror the `global-read` check from Page/Menu.

---

### 6. GetEventLog Device Lookup Bypasses Access Control

**File:** `app/Plugins/AiAssistant/Tools/GetEventLog.php:78`

When filtering by `device_id`, the tool fetches the hostname without checking user access:
```php
$hostname = \App\Models\Device::where('device_id', $deviceId)->value('hostname');
```

This leaks the hostname of any device regardless of user permissions, then searches external events mentioning that hostname.

**Fix:** Apply `hasAccess($user)` to the device lookup query.

---

## Medium-Priority Issues

### 7. No Rate Limiting

The `/plugin/ai/chat` endpoint has no rate limiting. A single user can exhaust the entire API budget or create heavy database load.

**Fix:** Add `throttle` middleware (e.g., `throttle:30,1`).

### 8. Error Messages Leak Infrastructure Details to LLM Provider

**File:** `app/Plugins/AiAssistant/Services/LlmService.php:217-221`

Tool execution errors include raw exception messages, sent to the external LLM API:
```php
return ['error' => "Tool execution failed: {$e->getMessage()}"];
```

Database errors could expose table names, column names, or connection details.

**Fix:** Return generic error to the LLM; log details internally only.

### 9. Debug Logging Left In

**File:** `app/Plugins/AiAssistant/Tools/GetEventLog.php:55`

`\Log::info(...)` logs all tool parameters at INFO level. Several commits in branch history (`85f32a572`, `1d6b4ba07`) show explicit debug logging additions. Remove or change to `Log::debug()`.

### 10. Conversation Data Stored Unencrypted

All messages (user queries, LLM responses with network data, tool call results) are stored in plain text in `ai_messages`. Consider encrypting `content` and `tool_calls` columns.

### 11. External Events Visible to All Users

**File:** `app/Plugins/AiAssistant/Tools/GetEventLog.php:67-68`

Events with `device_id = NULL` or `0` (backups, fail2ban, etc.) are visible to all authenticated users, even those with restricted device access.

### 12. API Key in Error Context

**File:** `app/Plugins/AiAssistant/Providers/OpenAiCompatibleProvider.php:67-71`

LLM API error responses are included in thrown exceptions. If logged, these could expose API endpoint details or echoed request data.

---

## Low-Priority Issues

### 13. Missing Foreign Keys in Migrations

`ai_cost_log.user_id` and `ai_sessions.user_id` have no foreign key constraints. Orphaned records will accumulate when users are deleted.

### 14. Message History Ordering

**File:** `ConversationManager.php:139` — Messages ordered by `id` rather than `created_at`. Functionally fine but fragile.

### 15. `isApproachingDailyLimit()` Never Called

`CostTracker.php` has this method but it's never invoked. Users get no warning before budget exhaustion.

### 16. PluginManager Dispatch Methods Are Dead Code

`app/Plugins/PluginManager.php` adds `dispatchEvent`, `dispatchAlertInjection`, `dispatchScheduledTasks` but nothing calls them on this branch.

---

## Non-Security Issues

### 17. Copyright/Author Boilerplate

The new hook files and interfaces carry `@copyright 2024 Tony Murray` / `@author Tony Murray <murraytony@gmail.com>`, copied from existing hook files. The year and author should be updated for new code:

**Files affected:**
- `app/Plugins/Hooks/AlertInjectionHook.php`
- `app/Plugins/Hooks/EventListenerHook.php`
- `app/Plugins/Hooks/GlobalWidgetHook.php`
- `app/Plugins/Hooks/ScheduledTaskHook.php`
- `LibreNMS/Interfaces/Plugins/Hooks/AlertInjectionHook.php`
- `LibreNMS/Interfaces/Plugins/Hooks/EventListenerHook.php`
- `LibreNMS/Interfaces/Plugins/Hooks/GlobalWidgetHook.php`
- `LibreNMS/Interfaces/Plugins/Hooks/ScheduledTaskHook.php`

The AI Assistant plugin files use `@copyright 2026 LibreNMS` / `@author LibreNMS Contributors` — appropriate.

The Tools directory (`app/Plugins/AiAssistant/Tools/*.php`) has **no copyright headers at all** (except the interface and abstract class).

### 18. Unvalidated LLM Tool Call Arguments

**File:** `LlmService.php:206-215`

LLM tool arguments are JSON-decoded and passed to tools with no schema validation. Individual tools do some type casting but there's no centralized validation against the declared parameter schema.

### 19. No Response Size Validation from LLM

The provider accepts LLM responses without checking content length or number of tool calls. A compromised API endpoint could return excessive data.

---

## Architectural Strengths

- **Tool-calling pattern** provides strong defense-in-depth against prompt injection — even if the LLM is jailbroken, it can only access data through tools that enforce device-level RBAC
- **Consistent `hasAccess()` usage** across all 17 tools for device-scoped queries
- **CSRF protection** properly implemented in both the settings form and chat AJAX requests
- **Input validation** on the controller (message length, session_id length)
- **Cost tracking** with daily/monthly/per-query budget limits
- **Session timeout** with automatic message cleanup
- **Safe markdown rendering** using `textContent` (not `innerHTML`)
- **Generic error responses** to the user (only log details server-side)

---

## Recommended Fix Priority

### Must fix before merge:
1. Session ownership verification (Critical #1)
2. Path traversal in GetTimeSeries (Critical #2)
3. Settings authorization + API key exposure (Critical #3)
4. Chat endpoint authorization (High #4)
5. Tool-level authorization (High #5)

### Should fix before merge:
6. GetEventLog device lookup bypass (High #6)
7. Rate limiting (Medium #7)
8. Error message sanitization (Medium #8)
9. Remove debug logging (Medium #9)

### Can fix after merge:
10-19. Low-priority and non-security items
