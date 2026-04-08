# LibreNMS AI Assistant — Design Specification

**Date:** 2026-04-08
**Status:** Approved (revised after review)
**Branch:** feature/ai-assistant
**Review:** [2026-04-08-ai-assistant-design-review.md](2026-04-08-ai-assistant-design-review.md)

## Overview

A conversational AI module for LibreNMS that enables natural language interaction with network monitoring data. The system provides three capabilities:

1. **Interactive chat** — Users ask questions via IRC bot or web chat widget ("what's the network status?", "when was the last time router-1 went down?")
2. **Scheduled reports** — Automated daily/weekly summaries delivered via existing alert transports
3. **Proactive monitoring** — LLM analyzes events in real-time, detects anomalies and patterns that no manual rule covers, and injects alerts through existing transport channels

The module is built as a **LibreNMS plugin** (`AiAssistant`) that depends on four new generic plugin hooks added to core. This separation keeps the AI functionality optional and self-contained while the hook extensions benefit the entire plugin ecosystem.

## Architecture

Layered service architecture with clean interfaces between layers:

```
┌─────────────────────────────────────────────────┐
│           Interfaces (thin adapters)             │
│   IRC Command  │  Web Chat Widget  │  (future)  │
├─────────────────────────────────────────────────┤
│              Conversation Manager                │
│   Session handling, message history, auth/RBAC   │
├─────────────────────────────────────────────────┤
│                 LLM Service                      │
│   Provider interface, tool execution, prompt     │
│   assembly (system prompt + context snapshot)    │
├────────────────────┬────────────────────────────┤
│   Data Access      │   Monitoring Engine         │
│   Layer (Tools)    │   Sliding window query,      │
│   Device, Alert,   │   pattern detection, alert   │
│   Port, Sensor,    │   injection, scheduled       │
│   Log queries      │   reports                    │
├────────────────────┴────────────────────────────┤
│            Pattern Store                         │
│   Learned correlations, suppression rules,       │
│   human-reviewed knowledge base                  │
├─────────────────────────────────────────────────┤
│         LibreNMS Core (Models, DB, Alerts)       │
└─────────────────────────────────────────────────┘
```

## Key Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| LLM backend strategy | Pluggable interface, ship one provider | OpenAI-compatible covers ~90% of providers (OpenAI, Ollama, LM Studio, Groq, Azure, etc.). Adding new providers is one class. |
| Data access method | Direct Eloquent model access | The REST API wasn't designed for conversational queries ("what changed in the last hour?"). Eloquent enables purpose-built aggregations and joins. |
| Context strategy | Hybrid: context injection + tools | Cheap status snapshot on every request for instant answers to common questions. Tools available for deep dives. |
| Monitoring approach | Event-buffered with sliding window | Tied to poller interval for event detection, forced flush on longer interval for trend analysis. Balances responsiveness vs API cost. |
| Pattern persistence | Yes, with human review lifecycle | Learned patterns make the system smarter over time. Human approval required for alert suppression. |
| Permission model | Defense-in-depth RBAC | Data filtered at tool level before reaching LLM. No path for restricted data to leak. |
| Deployment model | Plugin with new core hooks | Easier upstream acceptance. Hooks are generic infrastructure improvements. AI functionality is optional. |
| All three use cases in v1 | Yes, monitoring opt-in | Proactive monitoring is the killer feature — not a later phase. However, it must be explicitly enabled by the admin in configuration (default: disabled) to avoid alert fatigue before the system has learned the network's baseline patterns. |

---

## Section 1: Plugin Hook Extensions (Core PRs)

Four new generic plugin hooks added to LibreNMS core. These are infrastructure improvements that don't mention AI and benefit any future plugin.

### ScheduledTaskHook

```php
interface ScheduledTaskHook {
    public function scheduledTasks(Schedule $schedule): void;
}
```

Plugins register Laravel scheduled tasks. The kernel's `schedule()` method calls all plugins implementing this hook. Drives the monitoring flush cycle and report generation.

### EventListenerHook

```php
interface EventListenerHook {
    public function handleEvent(string $type, array $data): void;
}
```

Called when key events occur in LibreNMS. The PluginManager dispatches to all implementing plugins. Dispatch calls are added at these specific points:
- `RunAlerts::issueAlert()` — when an alert fires or recovers (type: `alert`)
- Device status changes during polling — when a device goes up/down (type: `device_status`)
- Syslog ingestion — when new syslog entries are received (type: `syslog`)
- Sensor threshold crossings — when a sensor value exceeds warning/critical limits (type: `sensor`)
- Port status changes — when a port goes up/down or error counters spike (type: `port_status`)

**Non-blocking contract:** `handleEvent()` implementations MUST be non-blocking. The PluginManager's dispatch loop wraps each call in a try/catch with a configurable timeout (default: 100ms). If a plugin's handler exceeds the timeout or throws an exception, the error is logged and the dispatch continues to the next plugin. This prevents a misbehaving plugin from stalling the poller or alert pipeline.

The AI plugin's `EventListener` implementation simply writes the event to the `ai_events` buffer table — a single INSERT, guaranteed fast. All heavy processing (LLM calls, analysis) happens asynchronously in the scheduled flush cycle.

### AlertInjectionHook

```php
interface AlertInjectionHook {
    public function getAlerts(): array;
}
```

Called during the alert processing cycle. Allows plugins to inject alerts into the existing transport system without creating alert rules. Injected alerts pass through the same device-group and transport-group filtering as normal alerts, ensuring RBAC is respected in shared channels (see Section 6: Alert injection for details).

### GlobalWidgetHook

```php
interface GlobalWidgetHook {
    public function authorize(User $user): bool;
    public function globalWidget(): string;
}
```

Injected into the main layout template. Renders on every page. Used for the floating chat bubble.

---

## Section 2: LLM Provider Interface

Pluggable backend layer with a simple interface:

```php
interface LlmProviderInterface {
    public function chat(array $messages, array $tools = []): LlmResponse;
    public function getModel(): string;
    public function getMaxContextTokens(): int;
}
```

### LlmResponse

```php
class LlmResponse {
    public string $content;        // Text response
    public array $toolCalls;       // Tool calls the LLM wants to make
    public int $inputTokens;       // For cost tracking
    public int $outputTokens;
    public string $stopReason;     // 'end', 'tool_use', 'max_tokens'
}
```

### First implementation: OpenAiCompatibleProvider

- Covers OpenAI, Azure OpenAI, Ollama, LM Studio, Groq, Together, etc.
- Configuration: API URL, API key, model name
- Handles the OpenAI function calling / tool calling format

### Configuration keys

- `ai.provider` — which provider class to use
- `ai.api_url` — endpoint URL
- `ai.api_key` — API key
- `ai.model` — model identifier
- `ai.max_tokens` — response length limit
- `ai.temperature_chat` — temperature for interactive chat (default: 0.5, slightly creative for natural responses)
- `ai.temperature_monitoring` — temperature for monitoring analysis (default: 0.1, deterministic structured output)
- `ai.temperature_reports` — temperature for report generation (default: 0.3, balanced)

Adding a new provider means creating one class that implements `LlmProviderInterface`. No other changes needed.

---

## Section 3: Data Access Layer (Tools)

Functions the LLM can call to query LibreNMS data. Each tool is a self-contained class:

```php
interface AiToolInterface {
    public function name(): string;
    public function description(): string;
    public function parameters(): array;  // JSON Schema
    public function execute(array $params, ?User $user = null): array;
}
```

The `$user` parameter enables RBAC — tools filter results based on what the user is allowed to see. When called from the monitoring engine (no user context), full access is granted for analysis purposes. However, any alerts generated from monitoring analysis are filtered through device-group transport mappings before delivery, ensuring that shared channels only receive alerts about devices their recipients are authorized to see (see Section 6: Alert injection).

### Initial tool set

| Tool | Purpose | Example question |
|---|---|---|
| `get_network_summary` | Device/port/alert counts, overall health | "How's the network?" |
| `get_devices` | List/filter devices by status, group, OS, location | "Which devices are in the DC?" |
| `get_device_detail` | Deep info on one device — uptime, hardware, sensors, ports | "Tell me about router-1" |
| `get_active_alerts` | Current alerts with severity, age, affected device | "Any critical alerts?" |
| `get_alert_history` | Past alerts with time ranges, filtering | "How many alerts last week?" |
| `get_ports` | Port status, utilization, errors, traffic | "Which ports have errors?" |
| `get_sensors` | Temperature, voltage, humidity readings | "Are any devices running hot?" |
| `get_event_log` | Recent events filtered by device, type, time | "What happened on switch-2 today?" |
| `get_syslog` | Syslog entries with filtering | "Show me syslog from the firewalls" |
| `get_device_outages` | Downtime history for devices | "When was the last time router-1 went down?" |
| `get_services` | Application/service monitoring status | "Are all services healthy?" |
| `get_routing` | BGP peers, OSPF adjacencies, status | "Any BGP sessions down?" |
| `get_patterns` | Retrieve learned patterns from the pattern store | "What patterns have you learned?" |
| `save_pattern` | Store a new learned correlation (flagged for review) | Used by monitoring engine |

Tools handle pagination internally — large result sets return summaries with drill-down capability, not raw dumps. New tools are added by dropping a class into the tools directory. The LLM Service auto-discovers them.

---

## Section 4: LLM Service

Orchestration layer that ties providers and tools together.

### System prompt assembly

Builds the system prompt from:
- Base identity ("You are a network monitoring assistant for LibreNMS")
- Network context snapshot (auto-injected summary: device counts, active alerts, critical issues)
- Active learned patterns relevant to the current time
- User permissions scope ("You can only see devices in groups: ...")
- Available tools listing

### Tool execution loop

```
User message → LLM → tool_call?
  → yes: execute tool, send status callback, append result, send back to LLM → repeat
  → no: return final text response
```

Max iterations capped (e.g., 10 tool calls per query) to prevent runaway costs.

**Intermediate status callbacks:** The tool execution loop accepts an optional status callback function. On each tool call, the callback is invoked with a human-readable description of what's happening (e.g., "Checking device status...", "Looking up alert history..."). Interface adapters use this to provide real-time feedback:
- Web chat: updates the "thinking..." indicator with specific status text
- IRC: sends intermediate notices to the channel (rate-limited to avoid flood)

This prevents users from staring at a blank screen during multi-tool queries that may take 15-30 seconds.

### Token-aware history management

Before sending conversation history to the LLM, the service estimates total token count (system prompt + history + tool definitions) and compares against `provider.getMaxContextTokens()` minus a reserve for the response (`ai.max_tokens`).

If the total exceeds the limit:
1. Oldest messages are dropped from history (keeping the most recent)
2. If still too large, tool call results in history are summarized (replaced with a compact version)
3. The system prompt context snapshot and tool listings are never trimmed — they're essential

This ensures compatibility with smaller context models (local Ollama with 4-8k context) without requiring manual tuning.

### Context snapshot generator

```php
public function buildContextSnapshot(?User $user = null): string
```

Returns a compact summary like:
```
Network Status: 47 devices (45 up, 2 down: fw-branch-2, sw-remote-7)
Active Alerts: 3 (1 critical: fw-branch-2 unreachable, 2 warning)
Last hour: 12 events, 47 syslog entries
Known patterns active now: none
```

Cheap to generate (a few DB queries), small token count, immediate situational awareness.

### Cost controls

- `ai.cost_per_input_token` — configurable per provider
- `ai.cost_per_output_token` — same
- `ai.max_cost_daily` — hard daily budget. All LLM calls blocked when reached. Warning alert at 80%.
- `ai.max_cost_monthly` — monthly cap
- `ai.max_cost_per_query` — cap on a single interactive query (prevents runaway tool-call loops)

The LLM Service tracks cumulative cost in the database. Before every LLM call, it checks per-query, daily, and monthly budgets. When a budget is exceeded, it returns a friendly message ("AI budget reached for today") rather than silently failing.

### Token/cost tracking

Every LLM call is logged with input/output tokens, provider, model, and triggering context (user chat, monitoring flush, report). Enables cost reporting and debugging.

### Rate limiting

Per-user rate limits for interactive chat. Monitoring and reports have separate budgets.

### Error handling and retry strategy

LLM API calls can fail for various reasons. The service handles these gracefully:

**Retry with exponential backoff:**
- HTTP 429 (rate limited): retry after the `Retry-After` header value, or 2^attempt seconds (max 60s), up to 3 retries
- HTTP 5xx (server error): retry with exponential backoff, up to 3 retries
- Network errors / timeouts: retry once after 5 seconds

**Circuit breaker:**
- After 5 consecutive failures within 10 minutes, the service enters a "circuit open" state
- In this state, all LLM calls immediately return a friendly error message ("AI service temporarily unavailable") without attempting the API call
- After a configurable cooldown (default: 5 minutes), the circuit enters "half-open" — the next request is attempted, and if it succeeds, the circuit closes and normal operation resumes

**Graceful degradation:**
- If the monitoring flush fails, the error is logged and the next scheduled flush proceeds normally — one failure doesn't cascade
- If chat fails, the user gets a clear error message; the session is preserved so they can retry
- If a tool execution throws an exception mid-loop, the error is captured and sent to the LLM as a tool result ("Error: could not query devices"), allowing it to respond gracefully rather than crashing the entire loop
- Malformed JSON in structured output (monitoring analysis): the response is logged for debugging and the flush is skipped — no alerts are injected from unparseable output

---

## Section 5: Conversation Manager

Handles sessions, message history, and bridges interfaces to the LLM Service.

### Session management

- Each conversation gets a session ID (persisted in DB)
- IRC: session per authenticated user (not per channel — if a user moves from `#network` to a DM, the session follows them). Unauthenticated users cannot use the AI command.
- Web chat: session per browser tab/window, tied to authenticated user
- Sessions store message history for conversation context
- Sessions expire after configurable inactivity (default: 30 minutes)
- Max history length per session (default: 50 messages) — managed by token-aware trimming (see Section 4) rather than simple message count cutoff

### Core interface

```php
class ConversationManager {
    public function handleMessage(
        string $message,
        string $sessionId,
        User $user,
        string $interface  // 'irc', 'web', etc.
    ): string;
}
```

### Flow

1. Load or create session
2. Append user message to history
3. Build system prompt with context snapshot (via LLM Service), scoped to user's permissions
4. Send full conversation history + system prompt to LLM Service
5. Handle tool execution loop
6. Append assistant response to history
7. Return response text

### RBAC integration — defense in depth

Permission enforcement is layered so the LLM never sees data the user cannot access:

**Layer 1: Tool-level enforcement (hard gate)**
Every tool's `execute()` method filters queries through Eloquent's existing `hasAccess()` scopes:
```php
// In GetDevicesTool::execute()
$devices = Device::hasAccess($user)->where(...)->get();
```

**Layer 2: Context snapshot filtering**
The network status summary injected into the system prompt is filtered through user permissions. A restricted user sees only their permitted subset.

**Layer 3: Pattern store filtering**
Learned patterns reference specific devices. Only patterns involving devices the user can access are included in their prompt context.

**Layer 4: System prompt instruction (backup)**
The system prompt tells the LLM to only discuss devices the user can access. This is belt-and-suspenders — the data is already filtered, but this prevents hallucination from prior system-level sessions.

**Key principle:** The LLM operates in a filtered view of the world per user. It doesn't receive full data and get told to withhold — it only ever receives the permitted subset.

---

## Section 6: Monitoring Engine

The proactive system that watches the network without being asked.

### Sliding window query

Events are queried from the database each cycle — there is no in-memory buffer. This is simpler operationally (no separate daemon, survives restarts) and the queries are cheap (indexed timestamp lookups over recent data).

- Queries recent events from the `ai_events` buffer table (populated by `EventListenerHook`) and supplements with direct queries on `eventlog`, `syslog`, `alert_log` tables
- Window size is configurable (default: 30 minutes)
- Each event is a lightweight struct: `{timestamp, type, device_id, summary, raw_data}`

### Flush cycle — tied to poller interval

**Every poll interval:**
1. Query events/changes since last check
2. Quick diff check — has anything notable changed? (new alerts, state changes, sensor threshold crossings, new syslog errors)
3. If yes → flush to LLM immediately with the full sliding window
4. If no → skip, save the API cost

**Every N poll intervals (configurable, default: 15 minutes):**
- Force a flush regardless — this is the "trend detection" pass
- Even if nothing triggered the quick diff, the LLM sees the full window and can spot slow-moving trends

### Operating modes

The monitoring engine supports two modes, configurable in settings:

- **Learning mode** (default when first enabled) — The engine observes and builds patterns but does NOT inject alerts. All detected anomalies are logged as `pending_review` patterns. This allows the system to learn the network's baseline behavior (backup jobs, scheduled maintenance, recurring patterns) before it starts alerting. Admins review accumulated patterns and switch to active mode when satisfied.
- **Active mode** — Full operation: pattern detection AND alert injection. Only enable after the engine has had time to learn baseline patterns and the admin has reviewed initial findings.

### LLM analysis prompt

The flush sends the full sliding window to the LLM with:
- All events in the window (30 min of context)
- Currently active learned patterns
- Current time and day of week (for recurring pattern correlation)
- Instructions: "Analyze these events. Flag anything concerning. Note any recurring patterns. Compare against known patterns."

### Structured output

The LLM returns JSON with:
- `alerts[]` — things needing immediate attention, with severity and suggested message, including `device_ids` for each alert
- `patterns[]` — newly observed correlations, flagged as `pending_review`
- `notes[]` — observations that aren't actionable but worth logging
- `suppress[]` — events matching known patterns that don't need alerting

If the LLM returns malformed JSON, the response is logged for debugging and the flush is skipped — no alerts are injected from unparseable output.

### Alert injection

- Items in `alerts[]` are pushed through the `AlertInjectionHook` into existing transports
- **RBAC filtering:** Each AI-generated alert includes the `device_ids` it relates to. The alert injection pipeline uses the same device-group → transport-group mappings that normal LibreNMS alerts use. This ensures that alerts about device X only reach transports configured to receive alerts for device X's group. Shared channels (Slack, IRC) never receive alerts about devices their audience shouldn't know about.
- Alert messages include an `[AI]` tag so recipients know the source
- In learning mode, alerts are logged but not delivered
- Logged in `ai_alerts` table for audit trail

### Scheduled reports

- Registered via `ScheduledTaskHook`
- Configurable schedule (daily, weekly, custom cron)
- Report prompt: "Summarize the last 24 hours. What happened, what's concerning, what trends do you see?"
- LLM has access to all tools for historical queries during report generation
- Reports delivered via configured transports and stored in DB for web UI viewing

### Configuration

- `ai.monitor_with_poller` — enable/disable (default: **false** — must be explicitly enabled)
- `ai.monitor_force_interval` — minutes between forced flushes (default: 15)
- `ai.monitor_window` — sliding window size in minutes (default: 30)

---

## Section 7: Pattern Store

Persistent memory that makes the system smarter over time.

### Database table: `ai_patterns`

| Column | Type | Purpose |
|---|---|---|
| id | int | Primary key |
| title | string | Short description ("Backup job causes I/O spike") |
| description | text | Full explanation of the pattern |
| devices | json | Device IDs involved |
| category | enum | `recurring`, `correlation`, `baseline`, `suppression` |
| status | enum | `pending_review`, `approved`, `rejected`, `expired` |
| occurrences | int | How many times observed — primary trust signal |
| first_seen | datetime | First detection |
| last_seen | datetime | Most recent observation |
| created_by | enum | `ai`, `human` — admins can manually add patterns |
| reviewed_by | int | User ID who approved/rejected |
| reviewed_at | datetime | When reviewed |

**Note on confidence:** LLMs are unreliable at self-calibrating confidence scores. Instead of storing an LLM-generated confidence value, the `occurrences` count serves as the primary trust signal. A pattern observed 15 times is more trustworthy than one the LLM rates at 0.95 confidence. The admin UI sorts pending patterns by occurrence count to surface the most-observed patterns first.

### Suppression conditions table: `ai_pattern_conditions`

Suppression patterns use structured, verifiable conditions rather than free-text descriptions that the LLM interprets at runtime. This makes suppression deterministic and auditable.

| Column | Type | Purpose |
|---|---|---|
| id | int | Primary key |
| pattern_id | int | FK to `ai_patterns` |
| device_ids | json | Specific device IDs this condition applies to |
| time_window | string | Cron expression for when suppression is active (e.g., `0-30 0 * * *` for daily 00:00-00:30) |
| alert_rule_ids | json | Specific alert rule IDs to suppress (null = any) |
| event_types | json | Event types to suppress (e.g., `["sensor", "port_status"]`) |

When the monitoring engine considers raising an alert, it checks active suppression conditions against structured fields — device ID, current time vs cron window, alert rule ID. No LLM interpretation is involved in the suppression decision. The LLM proposes suppression conditions when it detects recurring patterns, but the admin reviews and edits the structured fields before approving.

### Pattern lifecycle

1. LLM detects a correlation during monitoring flush → saves pattern with status `pending_review`
2. If the pattern is a recurring event, the LLM also proposes structured suppression conditions (device IDs, time window as cron, event types)
3. Same pattern detected again → `occurrences` increments
4. Admin reviews in web UI — approves or rejects. For suppression patterns, the admin can edit the structured conditions (cron expression, device IDs, alert rule IDs) before approving
5. Approved patterns included in LLM context during future flushes. Suppression patterns with approved conditions actively suppress matching alerts.
6. Patterns expire if not seen for configurable period (default: 90 days)

### How patterns are used

- **During monitoring flushes** — LLM sees approved patterns and can note "this matches known pattern X" in its analysis
- **During chat** — user asks "why did those alerts fire at midnight?" and LLM references the pattern
- **Alert suppression** — only `approved` suppression patterns with valid structured conditions can prevent alerts. Suppression is evaluated by code against the conditions table, not by the LLM at runtime.
- **Pending patterns** — visible to LLM but cannot suppress; it can flag "I think this is the same pattern I noticed yesterday" but still alerts

### Admin UI (on the dedicated AI page)

- List all patterns with filtering by status, category, device — sorted by occurrences (most-observed first)
- Approve/reject pending patterns
- Edit pattern details and suppression conditions (cron expressions, device IDs, alert rule IDs)
- Manually create patterns with structured conditions
- Delete patterns

### Safety rails

- Only `approved` patterns with valid structured conditions can suppress alerts
- Suppression is deterministic — evaluated by code, not LLM interpretation
- Patterns involving devices a user can't access are hidden from that user
- All pattern changes are audit-logged
- Admins can bulk-reject or wipe patterns if the LLM learns incorrect correlations

---

## Section 8: Interface Adapters

Thin layers connecting the Conversation Manager to communication channels.

### IRC Adapter

- Added as an external IRC bot command via `includes/ircbot/ai.inc.php`
- Triggered by configurable prefix (e.g., `.ai how's the network?`)
- Session: per user-channel combination, using existing IRC bot auth
- Maps authenticated IRC user to LibreNMS user for RBAC
- Response formatting: strips markdown, converts to IRC color codes via existing `_html2irc()`
- Long responses split into multiple messages with flood control
- Monitoring alerts and reports can be delivered to configured IRC channels via existing IRC alert transport

### Web Chat Widget

**Floating bubble:**
- Injected via `GlobalWidgetHook` on every page
- Small chat icon in bottom-right corner
- Clicking opens a chat panel overlay

**Dedicated page:**
- Registered via `MenuEntryHook` under "AI Assistant" menu item
- Full-page chat with conversation history
- Pattern management, report viewing, cost stats

**API endpoints (added by the plugin):**
- `POST /plugin/ai/chat` — send message, get response
- `GET /plugin/ai/sessions` — list user's sessions
- `GET /plugin/ai/patterns` — list patterns (admin)
- `PUT /plugin/ai/patterns/{id}` — approve/reject pattern
- `GET /plugin/ai/reports` — list generated reports
- `GET /plugin/ai/cost` — cost stats (admin)

**Frontend:**
- Alpine.js component (consistent with existing LibreNMS frontend)
- Simple message input and scrollable response area
- Responses rendered as markdown
- "Thinking..." indicator during LLM tool-calling loop
- Initial version uses request/response (no streaming)

### Future adapters (not in v1)

Slack, Discord, Telegram, API-only. Each would be ~50-100 lines wrapping `ConversationManager::handleMessage()`.

---

## Section 9: File Structure

**Core hook extensions (PR 1):**
```
LibreNMS/Interfaces/Plugins/Hooks/
    ScheduledTaskHook.php
    EventListenerHook.php
    AlertInjectionHook.php
    GlobalWidgetHook.php
```
Plus modifications to `PluginManager.php`, scheduler kernel, alert pipeline, event dispatch points, and main layout blade template.

**AI Plugin (PR 2):**
```
app/Plugins/AiAssistant/
    # Hook implementations
    Menu.php                          # MenuEntryHook
    GlobalChat.php                    # GlobalWidgetHook
    Scheduler.php                     # ScheduledTaskHook
    EventListener.php                 # EventListenerHook
    AlertInjector.php                 # AlertInjectionHook
    Settings.php                      # SettingsHook

    # Core service layer
    Services/
        LlmService.php               # Prompt assembly, tool loop, cost tracking
        ConversationManager.php       # Sessions, history, RBAC
        MonitoringEngine.php          # Sliding window, flush cycle, pattern detection
        ReportGenerator.php           # Scheduled report creation
        ContextBuilder.php            # Network snapshot generator
        CostTracker.php               # Budget enforcement

    # LLM provider interface + implementations
    Providers/
        LlmProviderInterface.php
        LlmResponse.php
        OpenAiCompatibleProvider.php

    # Data access tools
    Tools/
        AiToolInterface.php
        GetNetworkSummary.php
        GetDevices.php
        GetDeviceDetail.php
        GetActiveAlerts.php
        GetAlertHistory.php
        GetPorts.php
        GetSensors.php
        GetEventLog.php
        GetSyslog.php
        GetDeviceOutages.php
        GetServices.php
        GetRouting.php
        GetPatterns.php
        SavePattern.php

    # Database
    Migrations/
        create_ai_sessions_table.php
        create_ai_messages_table.php
        create_ai_patterns_table.php
        create_ai_pattern_conditions_table.php
        create_ai_alerts_table.php
        create_ai_events_table.php
        create_ai_cost_log_table.php
        create_ai_reports_table.php

    Models/
        AiSession.php
        AiMessage.php
        AiPattern.php
        AiPatternCondition.php
        AiAlert.php
        AiEvent.php
        AiCostLog.php
        AiReport.php

    # Web interface
    Http/
        AiChatController.php          # Chat API endpoints
        AiPatternController.php       # Pattern management
        AiReportController.php        # Report viewing
        AiSettingsController.php      # Cost stats, config

    resources/views/
        chat-bubble.blade.php         # Floating widget
        chat-page.blade.php           # Full page
        patterns.blade.php            # Pattern management
        reports.blade.php             # Report viewing

    resources/js/
        chat-widget.js                # Alpine.js chat component

    # IRC integration
    Irc/
        ai.inc.php                    # IRC bot external command

    routes.php                        # Plugin route definitions
```

---

## Section 10: Configuration & Settings UI

All configuration managed via the `SettingsHook`, grouped into sections:

### LLM Provider
- Provider type (dropdown)
- API URL
- API key (masked)
- Model name
- Temperature — chat (slider, 0-1, default 0.5)
- Temperature — monitoring (slider, 0-1, default 0.1)
- Temperature — reports (slider, 0-1, default 0.3)
- Max tokens per response

### Cost Controls
- Cost per input/output token
- Max daily budget (with currency, warning at 80%)
- Max monthly budget
- Max cost per single query
- Action when budget exceeded: disable all / chat only / monitoring only

### Monitoring
- Enable/disable proactive monitoring (default: **disabled** — must be explicitly enabled)
- Monitoring mode: **learning** (observe and build patterns only) or **active** (observe + inject alerts). Default: learning when first enabled.
- Tie to poller interval (toggle, default on when monitoring is enabled)
- Forced flush interval in minutes (default 15)
- Sliding window size in minutes (default 30)
- Enable/disable alert injection (only available in active mode)

### Reports
- Enable/disable scheduled reports
- Schedule (cron expression or preset: daily/weekly)
- Report delivery transports (reuse existing alert transport configuration)
- Report time range (default: since last report)

### Chat
- Enable/disable web chat
- Enable/disable IRC integration
- IRC command prefix (default `.ai`)
- Session timeout in minutes (default 30)
- Max messages per session (default 50)
- Rate limit per user per hour (default 20)

### Patterns
- Auto-expire after N days without observation (default 90)
- Max pending patterns before oldest are pruned (default 100)
- Allow suppression patterns (toggle, default on)

### Settings dashboard (read-only)
- Today's cost / daily budget
- This month's cost / monthly budget
- Queries today (chat / monitoring / reports breakdown)
- Active patterns count
- Active sessions count

---

## Section 11: Plugin Data Lifecycle

### Installation
- Plugin migrations run via Laravel's migration system when the plugin is enabled
- All tables are prefixed with `ai_` to avoid collisions

### Upgrades
- Plugin migrations are versioned and tracked in Laravel's `migrations` table like any other migration
- New migrations are added for schema changes — never modify existing migrations
- Plugin version is tracked in the `plugins` table

### Uninstall
- Disabling the plugin stops all scheduled tasks and event listeners
- Database tables are NOT dropped on disable — data is preserved in case the plugin is re-enabled
- A separate "Purge data" button in the admin UI drops all `ai_*` tables and removes migration records. This is a destructive action requiring confirmation.

### Core model compatibility
- Tools reference Eloquent models (`Device`, `Port`, `Alert`, etc.) by their public API (scopes, relationships, attributes)
- If a core upgrade changes a model's interface, the plugin may need updating — this is standard for any plugin that integrates deeply with core
- The plugin should declare a minimum LibreNMS version in its metadata
