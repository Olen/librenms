# LibreNMS AI Assistant — Design Specification

**Date:** 2026-04-08
**Status:** Approved
**Branch:** feature/ai-assistant

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
│   Layer (Tools)    │   Ring buffer, pattern       │
│   Device, Alert,   │   detection, alert           │
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

### AlertInjectionHook

```php
interface AlertInjectionHook {
    public function getAlerts(): array;
}
```

Called during the alert processing cycle. Allows plugins to inject alerts into the existing transport system without creating alert rules.

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
- `ai.temperature` — creativity setting (low for monitoring, adjustable for chat)

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

The `$user` parameter enables RBAC — tools filter results based on what the user is allowed to see. When called from the monitoring engine (no user context), full access is granted.

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
  → yes: execute tool, append result, send back to LLM → repeat
  → no: return final text response
```

Max iterations capped (e.g., 10 tool calls per query) to prevent runaway costs.

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

---

## Section 5: Conversation Manager

Handles sessions, message history, and bridges interfaces to the LLM Service.

### Session management

- Each conversation gets a session ID (persisted in DB)
- IRC: session per user-channel combination
- Web chat: session per browser tab/window, tied to authenticated user
- Sessions store message history for conversation context
- Sessions expire after configurable inactivity (default: 30 minutes)
- Max history length per session (default: 50 messages) — oldest messages trimmed

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

### Sliding window

- Queries recent events from the database each cycle: device state changes, alerts, syslog entries, sensor threshold crossings, port status changes
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

### LLM analysis prompt

The flush sends the full sliding window to the LLM with:
- All events in the window (30 min of context)
- Currently active learned patterns
- Current time and day of week (for recurring pattern correlation)
- Instructions: "Analyze these events. Flag anything concerning. Note any recurring patterns. Compare against known patterns."

### Structured output

The LLM returns JSON with:
- `alerts[]` — things needing immediate attention, with severity and suggested message
- `patterns[]` — newly observed correlations, flagged as `pending_review`
- `notes[]` — observations that aren't actionable but worth logging
- `suppress[]` — events matching known patterns that don't need alerting

### Alert injection

- Items in `alerts[]` are pushed through the `AlertInjectionHook` into existing transports
- Delivered via whatever transports the admin has configured (email, Slack, IRC, etc.)
- Alert messages include an `[AI]` tag so recipients know the source
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
| schedule | string | When it occurs ("daily 00:00-00:30", "weekdays 08:00-09:00") |
| category | enum | `recurring`, `correlation`, `baseline`, `suppression` |
| status | enum | `pending_review`, `approved`, `rejected`, `expired` |
| confidence | float | LLM's confidence score (0-1) |
| occurrences | int | How many times observed |
| first_seen | datetime | First detection |
| last_seen | datetime | Most recent observation |
| created_by | enum | `ai`, `human` — admins can manually add patterns |
| reviewed_by | int | User ID who approved/rejected |
| reviewed_at | datetime | When reviewed |

### Pattern lifecycle

1. LLM detects a correlation during monitoring flush → saves with status `pending_review`
2. Same pattern detected again → `occurrences` increments, `confidence` may increase
3. Admin reviews in web UI — approves or rejects
4. Approved patterns included in LLM context during future flushes, can suppress alerts
5. Patterns expire if not seen for configurable period (default: 90 days)

### How patterns are used

- **During monitoring flushes** — LLM sees approved patterns and can suppress known-benign events
- **During chat** — user asks "why did those alerts fire at midnight?" and LLM references the pattern
- **Alert suppression** — only `approved` patterns with category `suppression` can prevent alerts
- **Pending patterns** — visible to LLM but cannot suppress; it can flag "I think this is the same pattern I noticed yesterday" but still alerts

### Admin UI (on the dedicated AI page)

- List all patterns with filtering by status, category, device
- Approve/reject pending patterns
- Edit pattern details
- Manually create patterns
- Delete patterns

### Safety rails

- Only `approved` patterns can suppress alerts
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
        MonitoringEngine.php          # Ring buffer, flush cycle, pattern detection
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
        create_ai_alerts_table.php
        create_ai_cost_log_table.php
        create_ai_reports_table.php

    Models/
        AiSession.php
        AiMessage.php
        AiPattern.php
        AiAlert.php
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
- Temperature (slider, 0-1, default 0.3)
- Max tokens per response

### Cost Controls
- Cost per input/output token
- Max daily budget (with currency, warning at 80%)
- Max monthly budget
- Max cost per single query
- Action when budget exceeded: disable all / chat only / monitoring only

### Monitoring
- Enable/disable proactive monitoring (default: **disabled** — must be explicitly enabled)
- Tie to poller interval (toggle, default on when monitoring is enabled)
- Forced flush interval in minutes (default 15)
- Sliding window size in minutes (default 30)
- Enable/disable alert injection

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
