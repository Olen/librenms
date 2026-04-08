# AI Assistant — Plan 1: Foundation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the core plugin hooks, LLM provider layer, data access tools, LLM service, and conversation manager — resulting in a working AI chat endpoint that can answer questions about LibreNMS data.

**Architecture:** Four new plugin hooks are added to LibreNMS core. The AI Assistant plugin uses these hooks and implements a layered service: LLM Provider → Data Access Tools → LLM Service → Conversation Manager → HTTP endpoint. Everything is testable end-to-end via a POST endpoint.

**Tech Stack:** PHP 8.2+, Laravel 12, PHPUnit, Eloquent ORM, OpenAI-compatible API (guzzlehttp/guzzle)

**Spec:** `docs/superpowers/specs/2026-04-08-ai-assistant-design.md`

**Depends on:** Nothing (this is the first plan)

**Produces:** A working `/plugin/ai/chat` POST endpoint that accepts a message and returns an AI-generated response about the network, with full RBAC filtering.

---

## File Map

### Core Hook Extensions (PR 1)

| Action | File | Purpose |
|---|---|---|
| Create | `LibreNMS/Interfaces/Plugins/Hooks/ScheduledTaskHook.php` | Interface for plugins to register scheduled tasks |
| Create | `LibreNMS/Interfaces/Plugins/Hooks/EventListenerHook.php` | Interface for plugins to receive system events |
| Create | `LibreNMS/Interfaces/Plugins/Hooks/AlertInjectionHook.php` | Interface for plugins to inject alerts |
| Create | `LibreNMS/Interfaces/Plugins/Hooks/GlobalWidgetHook.php` | Interface for plugins to render UI on every page |
| Create | `app/Plugins/Hooks/ScheduledTaskHook.php` | Abstract base class for ScheduledTaskHook |
| Create | `app/Plugins/Hooks/EventListenerHook.php` | Abstract base class for EventListenerHook |
| Create | `app/Plugins/Hooks/AlertInjectionHook.php` | Abstract base class for AlertInjectionHook |
| Create | `app/Plugins/Hooks/GlobalWidgetHook.php` | Abstract base class for GlobalWidgetHook |
| Modify | `app/Plugins/PluginManager.php` | Add dispatch methods for new hooks |
| Create | `tests/Unit/Plugins/HookDispatchTest.php` | Tests for new hook dispatch |

### AI Plugin (PR 2)

| Action | File | Purpose |
|---|---|---|
| Create | `app/Plugins/AiAssistant/Providers/LlmProviderInterface.php` | LLM provider contract |
| Create | `app/Plugins/AiAssistant/Providers/LlmResponse.php` | Response value object |
| Create | `app/Plugins/AiAssistant/Providers/OpenAiCompatibleProvider.php` | OpenAI-compatible implementation |
| Create | `app/Plugins/AiAssistant/Tools/AiToolInterface.php` | Tool contract |
| Create | `app/Plugins/AiAssistant/Tools/AbstractAiTool.php` | Base class with shared toFunctionDefinition() |
| Create | `app/Plugins/AiAssistant/Tools/GetNetworkSummary.php` | Network overview tool |
| Create | `app/Plugins/AiAssistant/Tools/GetDevices.php` | Device listing tool |
| Create | `app/Plugins/AiAssistant/Tools/GetDeviceDetail.php` | Single device deep-dive tool |
| Create | `app/Plugins/AiAssistant/Tools/GetActiveAlerts.php` | Current alerts tool |
| Create | `app/Plugins/AiAssistant/Tools/GetAlertHistory.php` | Alert history tool |
| Create | `app/Plugins/AiAssistant/Tools/GetPorts.php` | Port status tool |
| Create | `app/Plugins/AiAssistant/Tools/GetSensors.php` | Sensor readings tool |
| Create | `app/Plugins/AiAssistant/Tools/GetEventLog.php` | Event log tool |
| Create | `app/Plugins/AiAssistant/Tools/GetSyslog.php` | Syslog tool |
| Create | `app/Plugins/AiAssistant/Tools/GetDeviceOutages.php` | Device outage history tool |
| Create | `app/Plugins/AiAssistant/Tools/GetServices.php` | Service status tool |
| Create | `app/Plugins/AiAssistant/Tools/GetRouting.php` | Routing status tool |
| Create | `app/Plugins/AiAssistant/Services/ContextBuilder.php` | Builds network status snapshot |
| Create | `app/Plugins/AiAssistant/Services/CostTracker.php` | Tracks and enforces cost budgets |
| Create | `app/Plugins/AiAssistant/Services/LlmService.php` | Orchestrates prompt assembly + tool loop |
| Create | `app/Plugins/AiAssistant/Services/ConversationManager.php` | Session and message history management |
| Create | `app/Plugins/AiAssistant/Migrations/create_ai_sessions_table.php` | Sessions table |
| Create | `app/Plugins/AiAssistant/Migrations/create_ai_messages_table.php` | Message history table |
| Create | `app/Plugins/AiAssistant/Migrations/create_ai_cost_log_table.php` | Cost tracking table |
| Create | `app/Plugins/AiAssistant/Models/AiSession.php` | Session model |
| Create | `app/Plugins/AiAssistant/Models/AiMessage.php` | Message model |
| Create | `app/Plugins/AiAssistant/Models/AiCostLog.php` | Cost log model |
| Create | `app/Plugins/AiAssistant/Services/AiServiceFactory.php` | Factory for building the service stack from settings |
| Create | `app/Plugins/AiAssistant/Http/AiChatController.php` | Chat API endpoint |
| Create | `app/Plugins/AiAssistant/Settings.php` | SettingsHook implementation |
| Create | `app/Plugins/AiAssistant/routes.php` | Plugin routes |
| Create | `app/Plugins/AiAssistant/resources/views/settings.blade.php` | Settings page |
| Create | `tests/Unit/AiAssistant/LlmResponseTest.php` | LlmResponse unit tests |
| Create | `tests/Unit/AiAssistant/OpenAiCompatibleProviderTest.php` | Provider unit tests |
| Create | `tests/Unit/AiAssistant/ContextBuilderTest.php` | Context builder tests |
| Create | `tests/Unit/AiAssistant/CostTrackerTest.php` | Cost tracker tests |
| Create | `tests/Unit/AiAssistant/ToolsTest.php` | Data access tool tests |
| Create | `tests/Unit/AiAssistant/LlmServiceTest.php` | LLM service integration tests |
| Create | `tests/Unit/AiAssistant/ConversationManagerTest.php` | Conversation manager tests |

---

## Task 1: Core Plugin Hook Interfaces

Create the four new hook interfaces that will be needed by the AI plugin and any future plugins.

**Files:**
- Create: `LibreNMS/Interfaces/Plugins/Hooks/ScheduledTaskHook.php`
- Create: `LibreNMS/Interfaces/Plugins/Hooks/EventListenerHook.php`
- Create: `LibreNMS/Interfaces/Plugins/Hooks/AlertInjectionHook.php`
- Create: `LibreNMS/Interfaces/Plugins/Hooks/GlobalWidgetHook.php`

- [ ] **Step 1: Create ScheduledTaskHook interface**

```php
<?php

namespace LibreNMS\Interfaces\Plugins\Hooks;

use Illuminate\Console\Scheduling\Schedule;

interface ScheduledTaskHook
{
    public function authorize(): bool;

    public function handle(Schedule $schedule, string $pluginName, array $settings): void;
}
```

- [ ] **Step 2: Create EventListenerHook interface**

```php
<?php

namespace LibreNMS\Interfaces\Plugins\Hooks;

interface EventListenerHook
{
    public function authorize(): bool;

    public function handle(string $eventType, array $eventData, string $pluginName, array $settings): void;
}
```

- [ ] **Step 3: Create AlertInjectionHook interface**

```php
<?php

namespace LibreNMS\Interfaces\Plugins\Hooks;

interface AlertInjectionHook
{
    public function authorize(): bool;

    public function handle(string $pluginName, array $settings): array;
}
```

- [ ] **Step 4: Create GlobalWidgetHook interface**

```php
<?php

namespace LibreNMS\Interfaces\Plugins\Hooks;

use Illuminate\Contracts\Auth\Authenticatable;

interface GlobalWidgetHook
{
    public function authorize(Authenticatable $user): bool;

    public function handle(string $pluginName, array $settings): string;
}
```

- [ ] **Step 5: Commit**

```bash
git add LibreNMS/Interfaces/Plugins/Hooks/ScheduledTaskHook.php \
        LibreNMS/Interfaces/Plugins/Hooks/EventListenerHook.php \
        LibreNMS/Interfaces/Plugins/Hooks/AlertInjectionHook.php \
        LibreNMS/Interfaces/Plugins/Hooks/GlobalWidgetHook.php
git commit -m "feat(plugins): add four new hook interfaces for scheduled tasks, events, alerts, and global widgets"
```

---

## Task 2: Core Plugin Hook Abstract Classes

Create the abstract base classes that plugins extend. These follow the same pattern as existing hooks (DeviceOverviewHook, PageHook, etc.).

**Files:**
- Create: `app/Plugins/Hooks/ScheduledTaskHook.php`
- Create: `app/Plugins/Hooks/EventListenerHook.php`
- Create: `app/Plugins/Hooks/AlertInjectionHook.php`
- Create: `app/Plugins/Hooks/GlobalWidgetHook.php`

- [ ] **Step 1: Create ScheduledTaskHook abstract class**

```php
<?php

namespace App\Plugins\Hooks;

use Illuminate\Console\Scheduling\Schedule;
use LibreNMS\Interfaces\Plugins\Hooks\ScheduledTaskHook as ScheduledTaskHookInterface;

abstract class ScheduledTaskHook implements ScheduledTaskHookInterface
{
    public function authorize(): bool
    {
        return true;
    }

    abstract public function handle(Schedule $schedule, string $pluginName, array $settings): void;
}
```

- [ ] **Step 2: Create EventListenerHook abstract class**

```php
<?php

namespace App\Plugins\Hooks;

use LibreNMS\Interfaces\Plugins\Hooks\EventListenerHook as EventListenerHookInterface;

abstract class EventListenerHook implements EventListenerHookInterface
{
    public function authorize(): bool
    {
        return true;
    }

    abstract public function handle(string $eventType, array $eventData, string $pluginName, array $settings): void;
}
```

- [ ] **Step 3: Create AlertInjectionHook abstract class**

```php
<?php

namespace App\Plugins\Hooks;

use LibreNMS\Interfaces\Plugins\Hooks\AlertInjectionHook as AlertInjectionHookInterface;

abstract class AlertInjectionHook implements AlertInjectionHookInterface
{
    public function authorize(): bool
    {
        return true;
    }

    abstract public function handle(string $pluginName, array $settings): array;
}
```

- [ ] **Step 4: Create GlobalWidgetHook abstract class**

```php
<?php

namespace App\Plugins\Hooks;

use Illuminate\Contracts\Auth\Authenticatable;
use LibreNMS\Interfaces\Plugins\Hooks\GlobalWidgetHook as GlobalWidgetHookInterface;

abstract class GlobalWidgetHook implements GlobalWidgetHookInterface
{
    public function authorize(Authenticatable $user): bool
    {
        return true;
    }

    abstract public function handle(string $pluginName, array $settings): string;
}
```

- [ ] **Step 5: Commit**

```bash
git add app/Plugins/Hooks/ScheduledTaskHook.php \
        app/Plugins/Hooks/EventListenerHook.php \
        app/Plugins/Hooks/AlertInjectionHook.php \
        app/Plugins/Hooks/GlobalWidgetHook.php
git commit -m "feat(plugins): add abstract base classes for new hook types"
```

---

## Task 3: Wire Hooks into PluginManager

Add dispatch methods for the new hooks to the PluginManager. The EventListenerHook needs special handling: non-blocking with timeout and try/catch per the design spec.

**This is the highest-risk task in the plan.** The code below assumes specific internal APIs (`hooksFor()`, `getSettings()`). These must be verified against the actual PluginManager before implementation.

**Files:**
- Modify: `app/Plugins/PluginManager.php`

- [ ] **Step 1: Read the current PluginManager carefully**

Read `app/Plugins/PluginManager.php` in full. Verify:
1. The `hooksFor()` method exists and its signature (it takes `$hookType`, `$args`, `$onlyPlugin` — returns a Collection of `['plugin_name' => string, 'instance' => object]`)
2. The `getSettings()` method exists and returns an array
3. The `publishHook()` method — how it registers hooks and discovers implementing classes
4. How existing hooks are dispatched in `call()` — follow the same error handling pattern

**If the internal API differs from assumptions, adapt all dispatch methods below accordingly.** The key contract is: iterate over registered hooks, call `handle()`, catch exceptions.

- [ ] **Step 2: Add dispatchEvent method to PluginManager**

Add after the existing `call()` method. This is the non-blocking event dispatch with timeout protection:

```php
/**
 * Dispatch an event to all plugins implementing EventListenerHook.
 * Non-blocking: each handler is wrapped in try/catch with a timeout.
 * Errors are logged but do not prevent other plugins from receiving the event.
 */
public function dispatchEvent(string $eventType, array $eventData): void
{
    $hookType = \LibreNMS\Interfaces\Plugins\Hooks\EventListenerHook::class;

    foreach ($this->hooksFor($hookType, [], null) as $hook) {
        try {
            $hook['instance']->handle(
                $eventType,
                $eventData,
                $hook['plugin_name'],
                $this->getSettings($hook['plugin_name'])
            );
        } catch (\Throwable $e) {
            \Log::error("Plugin {$hook['plugin_name']} EventListenerHook failed: " . $e->getMessage());
        }
    }
}

/**
 * Collect alerts from all plugins implementing AlertInjectionHook.
 * Returns merged array of alert data from all plugins.
 */
public function collectPluginAlerts(): array
{
    $hookType = \LibreNMS\Interfaces\Plugins\Hooks\AlertInjectionHook::class;
    $alerts = [];

    foreach ($this->hooksFor($hookType, [], null) as $hook) {
        try {
            $pluginAlerts = $hook['instance']->handle(
                $hook['plugin_name'],
                $this->getSettings($hook['plugin_name'])
            );
            if (is_array($pluginAlerts)) {
                $alerts = array_merge($alerts, $pluginAlerts);
            }
        } catch (\Throwable $e) {
            \Log::error("Plugin {$hook['plugin_name']} AlertInjectionHook failed: " . $e->getMessage());
        }
    }

    return $alerts;
}

/**
 * Register scheduled tasks from all plugins implementing ScheduledTaskHook.
 */
public function registerPluginSchedules(\Illuminate\Console\Scheduling\Schedule $schedule): void
{
    $hookType = \LibreNMS\Interfaces\Plugins\Hooks\ScheduledTaskHook::class;

    foreach ($this->hooksFor($hookType, [], null) as $hook) {
        try {
            $hook['instance']->handle(
                $schedule,
                $hook['plugin_name'],
                $this->getSettings($hook['plugin_name'])
            );
        } catch (\Throwable $e) {
            \Log::error("Plugin {$hook['plugin_name']} ScheduledTaskHook failed: " . $e->getMessage());
        }
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Plugins/PluginManager.php
git commit -m "feat(plugins): add dispatch methods for event, alert injection, and scheduled task hooks"
```

---

## Task 4: Hook Dispatch Tests

Write tests for the new dispatch methods.

**Files:**
- Create: `tests/Unit/Plugins/HookDispatchTest.php`

- [ ] **Step 1: Write test for dispatchEvent**

```php
<?php

namespace LibreNMS\Tests\Unit\Plugins;

use App\Plugins\PluginManager;
use LibreNMS\Interfaces\Plugins\Hooks\EventListenerHook;
use LibreNMS\Tests\TestCase;

class HookDispatchTest extends TestCase
{
    public function testDispatchEventCallsHandlerWithEventData(): void
    {
        $manager = new PluginManager();

        $mock = new class extends \App\Plugins\Hooks\EventListenerHook {
            public static bool $called = false;
            public static string $receivedType = '';
            public static array $receivedData = [];

            public function handle(string $eventType, array $eventData, string $pluginName, array $settings): void
            {
                self::$called = true;
                self::$receivedType = $eventType;
                self::$receivedData = $eventData;
            }
        };

        $manager->publishHook('TestPlugin', EventListenerHook::class, get_class($mock));

        $manager->dispatchEvent('device_status', ['device_id' => 1, 'status' => 'down']);

        $this->assertTrue($mock::$called);
        $this->assertEquals('device_status', $mock::$receivedType);
        $this->assertEquals(['device_id' => 1, 'status' => 'down'], $mock::$receivedData);
    }

    public function testDispatchEventCatchesExceptionsWithoutPropagating(): void
    {
        $manager = new PluginManager();

        $mock = new class extends \App\Plugins\Hooks\EventListenerHook {
            public function handle(string $eventType, array $eventData, string $pluginName, array $settings): void
            {
                throw new \RuntimeException('Plugin crashed');
            }
        };

        $manager->publishHook('CrashPlugin', EventListenerHook::class, get_class($mock));

        // Should not throw
        $manager->dispatchEvent('alert', ['alert_id' => 5]);
        $this->assertTrue(true);
    }

    public function testCollectPluginAlertsMergesResults(): void
    {
        $manager = new PluginManager();

        $mock = new class extends \App\Plugins\Hooks\AlertInjectionHook {
            public function handle(string $pluginName, array $settings): array
            {
                return [
                    ['device_id' => 1, 'severity' => 'critical', 'message' => 'AI detected anomaly'],
                ];
            }
        };

        $manager->publishHook('AiPlugin', \LibreNMS\Interfaces\Plugins\Hooks\AlertInjectionHook::class, get_class($mock));

        $alerts = $manager->collectPluginAlerts();

        $this->assertCount(1, $alerts);
        $this->assertEquals('critical', $alerts[0]['severity']);
    }
}
```

- [ ] **Step 2: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Plugins/HookDispatchTest.php --verbose`

Note: These tests may need adjustment based on how `publishHook` handles the mock classes. The PluginManager checks `pluginEnabled()` — you may need to insert a Plugin record into the DB or mock the check. If tests fail because the plugin isn't registered in DB, wrap the test in `DatabaseTransactions` and create a Plugin model entry:

```php
\App\Models\Plugin::create([
    'plugin_name' => 'TestPlugin',
    'plugin_active' => 1,
    'version' => 2,
]);
```

- [ ] **Step 3: Fix any failing tests and commit**

```bash
git add tests/Unit/Plugins/HookDispatchTest.php
git commit -m "test(plugins): add tests for new hook dispatch methods"
```

---

## Task 5: LLM Provider Interface and Response Object

Create the pluggable LLM provider contract and the response value object.

**Files:**
- Create: `app/Plugins/AiAssistant/Providers/LlmProviderInterface.php`
- Create: `app/Plugins/AiAssistant/Providers/LlmResponse.php`
- Create: `tests/Unit/AiAssistant/LlmResponseTest.php`

- [ ] **Step 1: Create LlmProviderInterface**

```php
<?php

namespace App\Plugins\AiAssistant\Providers;

interface LlmProviderInterface
{
    /**
     * Send a chat completion request to the LLM.
     *
     * @param  array  $messages  Array of message objects: [{role: 'system'|'user'|'assistant'|'tool', content: string, ...}]
     * @param  array  $tools  Array of tool definitions in OpenAI function-calling format
     * @return LlmResponse
     */
    public function chat(array $messages, array $tools = []): LlmResponse;

    public function getModel(): string;

    public function getMaxContextTokens(): int;
}
```

- [ ] **Step 2: Create LlmResponse value object**

```php
<?php

namespace App\Plugins\AiAssistant\Providers;

class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly array $toolCalls,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $stopReason,
    ) {}

    public function hasToolCalls(): bool
    {
        return ! empty($this->toolCalls);
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
```

- [ ] **Step 3: Write LlmResponse tests**

```php
<?php

namespace LibreNMS\Tests\Unit\AiAssistant;

use App\Plugins\AiAssistant\Providers\LlmResponse;
use LibreNMS\Tests\TestCase;

class LlmResponseTest extends TestCase
{
    public function testHasToolCallsReturnsTrueWhenPresent(): void
    {
        $response = new LlmResponse(
            content: '',
            toolCalls: [['id' => 'call_1', 'function' => ['name' => 'get_devices', 'arguments' => '{}']]],
            inputTokens: 100,
            outputTokens: 50,
            stopReason: 'tool_use',
        );

        $this->assertTrue($response->hasToolCalls());
    }

    public function testHasToolCallsReturnsFalseWhenEmpty(): void
    {
        $response = new LlmResponse(
            content: 'The network looks healthy.',
            toolCalls: [],
            inputTokens: 100,
            outputTokens: 50,
            stopReason: 'end',
        );

        $this->assertFalse($response->hasToolCalls());
    }

    public function testTotalTokens(): void
    {
        $response = new LlmResponse(
            content: 'test',
            toolCalls: [],
            inputTokens: 150,
            outputTokens: 75,
            stopReason: 'end',
        );

        $this->assertEquals(225, $response->totalTokens());
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Unit/AiAssistant/LlmResponseTest.php --verbose`
Expected: 3 tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Plugins/AiAssistant/Providers/LlmProviderInterface.php \
        app/Plugins/AiAssistant/Providers/LlmResponse.php \
        tests/Unit/AiAssistant/LlmResponseTest.php
git commit -m "feat(ai): add LLM provider interface and response value object"
```

---

## Task 6: OpenAI-Compatible Provider

Implement the first LLM provider using the OpenAI chat completions API format.

**Files:**
- Create: `app/Plugins/AiAssistant/Providers/OpenAiCompatibleProvider.php`
- Create: `tests/Unit/AiAssistant/OpenAiCompatibleProviderTest.php`

- [ ] **Step 1: Write tests for the provider**

The provider makes HTTP calls, so tests mock the HTTP client. Test that it correctly formats requests and parses responses.

```php
<?php

namespace LibreNMS\Tests\Unit\AiAssistant;

use App\Plugins\AiAssistant\Providers\LlmResponse;
use App\Plugins\AiAssistant\Providers\OpenAiCompatibleProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LibreNMS\Tests\TestCase;

class OpenAiCompatibleProviderTest extends TestCase
{
    public function testChatReturnsTextResponse(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message' => ['role' => 'assistant', 'content' => 'The network is healthy.'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
            ])),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mockHandler)]);
        $provider = new OpenAiCompatibleProvider(
            apiUrl: 'https://api.example.com/v1',
            apiKey: 'test-key',
            model: 'gpt-4o',
            maxTokens: 1000,
            temperature: 0.3,
            httpClient: $client,
        );

        $response = $provider->chat([
            ['role' => 'user', 'content' => 'How is the network?'],
        ]);

        $this->assertInstanceOf(LlmResponse::class, $response);
        $this->assertEquals('The network is healthy.', $response->content);
        $this->assertFalse($response->hasToolCalls());
        $this->assertEquals(100, $response->inputTokens);
        $this->assertEquals(20, $response->outputTokens);
        $this->assertEquals('end', $response->stopReason);
    }

    public function testChatReturnsToolCallResponse(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [
                                [
                                    'id' => 'call_abc123',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'get_devices',
                                        'arguments' => '{"status":"down"}',
                                    ],
                                ],
                            ],
                        ],
                        'finish_reason' => 'tool_calls',
                    ],
                ],
                'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 30],
            ])),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mockHandler)]);
        $provider = new OpenAiCompatibleProvider(
            apiUrl: 'https://api.example.com/v1',
            apiKey: 'test-key',
            model: 'gpt-4o',
            maxTokens: 1000,
            temperature: 0.3,
            httpClient: $client,
        );

        $response = $provider->chat(
            [['role' => 'user', 'content' => 'Which devices are down?']],
            [['type' => 'function', 'function' => ['name' => 'get_devices', 'parameters' => []]]]
        );

        $this->assertTrue($response->hasToolCalls());
        $this->assertEquals('call_abc123', $response->toolCalls[0]['id']);
        $this->assertEquals('get_devices', $response->toolCalls[0]['function']['name']);
        $this->assertEquals('tool_use', $response->stopReason);
    }

    public function testChatThrowsOnApiError(): void
    {
        $mockHandler = new MockHandler([
            new Response(429, [], json_encode(['error' => ['message' => 'Rate limited']])),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mockHandler)]);
        $provider = new OpenAiCompatibleProvider(
            apiUrl: 'https://api.example.com/v1',
            apiKey: 'test-key',
            model: 'gpt-4o',
            maxTokens: 1000,
            temperature: 0.3,
            httpClient: $client,
        );

        $this->expectException(\RuntimeException::class);
        $provider->chat([['role' => 'user', 'content' => 'test']]);
    }

    public function testGetModelReturnsConfiguredModel(): void
    {
        $provider = new OpenAiCompatibleProvider(
            apiUrl: 'https://api.example.com/v1',
            apiKey: 'test-key',
            model: 'llama3-70b',
            maxTokens: 1000,
            temperature: 0.3,
        );

        $this->assertEquals('llama3-70b', $provider->getModel());
    }
}
```

- [ ] **Step 2: Run tests — they should fail (class doesn't exist)**

Run: `php artisan test tests/Unit/AiAssistant/OpenAiCompatibleProviderTest.php --verbose`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement OpenAiCompatibleProvider**

```php
<?php

namespace App\Plugins\AiAssistant\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class OpenAiCompatibleProvider implements LlmProviderInterface
{
    private Client $httpClient;

    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens = 1000,
        private readonly float $temperature = 0.3,
        private readonly int $maxContextTokens = 128000,
        ?Client $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new Client([
            'base_uri' => rtrim($this->apiUrl, '/') . '/',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 120,
        ]);
    }

    public function chat(array $messages, array $tools = []): LlmResponse
    {
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
        ];

        if (! empty($tools)) {
            $body['tools'] = $tools;
        }

        try {
            $response = $this->httpClient->post('chat/completions', [
                'json' => $body,
            ]);
        } catch (ClientException $e) {
            $status = $e->getResponse()->getStatusCode();
            $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);
            $message = $responseBody['error']['message'] ?? 'Unknown API error';
            throw new \RuntimeException("LLM API error ($status): $message", $status, $e);
        }

        $data = json_decode($response->getBody()->getContents(), true);
        $choice = $data['choices'][0] ?? null;

        if (! $choice) {
            throw new \RuntimeException('LLM API returned no choices');
        }

        $message = $choice['message'];
        $finishReason = $choice['finish_reason'] ?? 'stop';
        $usage = $data['usage'] ?? [];

        $toolCalls = $message['tool_calls'] ?? [];
        $stopReason = match ($finishReason) {
            'tool_calls' => 'tool_use',
            'length' => 'max_tokens',
            default => 'end',
        };

        return new LlmResponse(
            content: $message['content'] ?? '',
            toolCalls: $toolCalls,
            inputTokens: $usage['prompt_tokens'] ?? 0,
            outputTokens: $usage['completion_tokens'] ?? 0,
            stopReason: $stopReason,
        );
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getMaxContextTokens(): int
    {
        return $this->maxContextTokens;
    }
}
```

- [ ] **Step 4: Run tests — they should pass**

Run: `php artisan test tests/Unit/AiAssistant/OpenAiCompatibleProviderTest.php --verbose`
Expected: 4 tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Plugins/AiAssistant/Providers/OpenAiCompatibleProvider.php \
        tests/Unit/AiAssistant/OpenAiCompatibleProviderTest.php
git commit -m "feat(ai): implement OpenAI-compatible LLM provider"
```

---

## Task 7: AiTool Interface and First Tool (GetNetworkSummary)

Create the tool interface and implement the first tool. This establishes the pattern all other tools follow.

**Files:**
- Create: `app/Plugins/AiAssistant/Tools/AiToolInterface.php`
- Create: `app/Plugins/AiAssistant/Tools/GetNetworkSummary.php`
- Create: `tests/Unit/AiAssistant/ToolsTest.php`

- [ ] **Step 1: Create AiToolInterface and AbstractAiTool base class**

```php
<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\User;

interface AiToolInterface
{
    /**
     * Tool name used in LLM function calling (e.g., 'get_network_summary').
     */
    public function name(): string;

    /**
     * Human-readable description for the LLM to understand when to use this tool.
     */
    public function description(): string;

    /**
     * JSON Schema for the tool's parameters.
     */
    public function parameters(): array;

    /**
     * Execute the tool and return structured data.
     * When $user is null, full access is granted (system-level calls).
     * When $user is provided, results are filtered by that user's permissions.
     */
    public function execute(array $params, ?User $user = null): array;

    /**
     * Convert this tool to OpenAI function-calling format.
     */
    public function toFunctionDefinition(): array;
}
```

Create `app/Plugins/AiAssistant/Tools/AbstractAiTool.php` — base class that provides the shared `toFunctionDefinition()` so tools don't duplicate it:

```php
<?php

namespace App\Plugins\AiAssistant\Tools;

abstract class AbstractAiTool implements AiToolInterface
{
    public function toFunctionDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => $this->parameters(),
            ],
        ];
    }
}
```

All tools extend `AbstractAiTool` instead of implementing `AiToolInterface` directly.
```

- [ ] **Step 2: Write test for GetNetworkSummary**

```php
<?php

namespace LibreNMS\Tests\Unit\AiAssistant;

use App\Models\Device;
use App\Models\User;
use App\Plugins\AiAssistant\Tools\GetNetworkSummary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Tests\DBTestCase;

class ToolsTest extends DBTestCase
{
    use DatabaseTransactions;

    public function testGetNetworkSummaryReturnsStructuredData(): void
    {
        $user = User::factory()->create();

        // Create test devices
        Device::factory()->count(3)->create(['status' => 1, 'disabled' => 0, 'ignore' => 0]);
        Device::factory()->create(['status' => 0, 'disabled' => 0, 'ignore' => 0]);

        $tool = new GetNetworkSummary();
        $result = $tool->execute([], $user);

        $this->assertArrayHasKey('total_devices', $result);
        $this->assertArrayHasKey('devices_up', $result);
        $this->assertArrayHasKey('devices_down', $result);
        $this->assertArrayHasKey('active_alerts', $result);
    }

    public function testGetNetworkSummaryToFunctionDefinition(): void
    {
        $tool = new GetNetworkSummary();
        $def = $tool->toFunctionDefinition();

        $this->assertEquals('function', $def['type']);
        $this->assertEquals('get_network_summary', $def['function']['name']);
        $this->assertNotEmpty($def['function']['description']);
        $this->assertArrayHasKey('parameters', $def['function']);
    }

    public function testGetNetworkSummaryName(): void
    {
        $tool = new GetNetworkSummary();
        $this->assertEquals('get_network_summary', $tool->name());
    }
}
```

- [ ] **Step 3: Run tests — they should fail**

Run: `php artisan test tests/Unit/AiAssistant/ToolsTest.php --verbose`
Expected: FAIL — GetNetworkSummary not found.

- [ ] **Step 4: Implement GetNetworkSummary**

```php
<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Alert;
use App\Models\Device;
use App\Models\Port;
use App\Models\Service;
use App\Models\User;

class GetNetworkSummary extends AbstractAiTool
{
    public function name(): string
    {
        return 'get_network_summary';
    }

    public function description(): string
    {
        return 'Get a high-level summary of the network: total devices, devices up/down, active alerts, port counts, and service status. Use this to answer general questions about network health.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $deviceQuery = Device::query()->where('disabled', 0)->where('ignore', 0);
        if ($user) {
            $deviceQuery->hasAccess($user);
        }

        $totalDevices = (clone $deviceQuery)->count();
        $devicesUp = (clone $deviceQuery)->where('status', 1)->count();
        $devicesDown = (clone $deviceQuery)->where('status', 0)->count();

        $downDevices = (clone $deviceQuery)->where('status', 0)
            ->select('hostname', 'sysName', 'device_id')
            ->limit(20)
            ->get()
            ->map(fn ($d) => $d->hostname ?: $d->sysName)
            ->toArray();

        $alertQuery = Alert::query()->where('state', '>=', 1);
        if ($user) {
            $alertQuery->whereHas('device', fn ($q) => $q->hasAccess($user));
        }
        $activeAlerts = $alertQuery->count();

        $portQuery = Port::query()->where('disabled', 0)->where('ignore', 0)->where('deleted', 0);
        if ($user) {
            $portQuery->hasAccess($user);
        }
        $totalPorts = (clone $portQuery)->count();
        $portsUp = (clone $portQuery)->where('ifOperStatus', 'up')->count();
        $portsDown = (clone $portQuery)->where('ifOperStatus', 'down')->where('ifAdminStatus', 'up')->count();

        $result = [
            'total_devices' => $totalDevices,
            'devices_up' => $devicesUp,
            'devices_down' => $devicesDown,
            'down_device_names' => $downDevices,
            'active_alerts' => $activeAlerts,
            'total_ports' => $totalPorts,
            'ports_up' => $portsUp,
            'ports_down' => $portsDown,
        ];

        // Services may not be enabled
        if (\LibreNMS\Config::get('show_services')) {
            $serviceQuery = Service::query();
            if ($user) {
                $serviceQuery->whereHas('device', fn ($q) => $q->hasAccess($user));
            }
            $result['total_services'] = (clone $serviceQuery)->count();
            $result['services_ok'] = (clone $serviceQuery)->where('service_status', 0)->count();
            $result['services_warning'] = (clone $serviceQuery)->where('service_status', 1)->count();
            $result['services_critical'] = (clone $serviceQuery)->where('service_status', 2)->count();
        }

        return $result;
    }
}
```

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Unit/AiAssistant/ToolsTest.php --verbose`
Expected: 3 tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Plugins/AiAssistant/Tools/AiToolInterface.php \
        app/Plugins/AiAssistant/Tools/AbstractAiTool.php \
        app/Plugins/AiAssistant/Tools/GetNetworkSummary.php \
        tests/Unit/AiAssistant/ToolsTest.php
git commit -m "feat(ai): add AiToolInterface, AbstractAiTool, and GetNetworkSummary tool"
```

---

## Task 8: Remaining Data Access Tools

Implement the remaining 11 tools. Each follows the same pattern as GetNetworkSummary: implements AiToolInterface, uses Eloquent with `hasAccess()` scoping, returns structured arrays.

**Files:**
- Create: `app/Plugins/AiAssistant/Tools/GetDevices.php`
- Create: `app/Plugins/AiAssistant/Tools/GetDeviceDetail.php`
- Create: `app/Plugins/AiAssistant/Tools/GetActiveAlerts.php`
- Create: `app/Plugins/AiAssistant/Tools/GetAlertHistory.php`
- Create: `app/Plugins/AiAssistant/Tools/GetPorts.php`
- Create: `app/Plugins/AiAssistant/Tools/GetSensors.php`
- Create: `app/Plugins/AiAssistant/Tools/GetEventLog.php`
- Create: `app/Plugins/AiAssistant/Tools/GetSyslog.php`
- Create: `app/Plugins/AiAssistant/Tools/GetDeviceOutages.php`
- Create: `app/Plugins/AiAssistant/Tools/GetServices.php`
- Create: `app/Plugins/AiAssistant/Tools/GetRouting.php`

Since these all follow an identical pattern, this task provides the implementation for each tool. Each tool extends `AbstractAiTool` and:
1. Defines `name()`, `description()`, `parameters()` (with JSON Schema for inputs)
2. Implements `execute()` with `hasAccess($user)` filtering (via `whereHas('device', ...)` for models that don't have their own `hasAccess` scope)
3. Inherits `toFunctionDefinition()` from `AbstractAiTool`

- [ ] **Step 1: Create GetDevices**

```php
<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Device;
use App\Models\User;

class GetDevices extends AbstractAiTool
{
    public function name(): string { return 'get_devices'; }

    public function description(): string
    {
        return 'List devices, optionally filtered by status (up/down), device group, OS type, or location. Returns hostname, status, OS, uptime, and location for each device. Use for questions like "which devices are down?" or "list all Cisco devices".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['up', 'down', 'all'], 'description' => 'Filter by device status. Default: all'],
                'os' => ['type' => 'string', 'description' => 'Filter by OS type (e.g., "ios", "linux", "junos")'],
                'location' => ['type' => 'string', 'description' => 'Filter by location (substring match)'],
                'group' => ['type' => 'string', 'description' => 'Filter by device group name'],
                'limit' => ['type' => 'integer', 'description' => 'Max results to return. Default: 50'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = Device::query()->where('disabled', 0)->where('ignore', 0);

        if ($user) {
            $query->hasAccess($user);
        }

        if (isset($params['status'])) {
            match ($params['status']) {
                'up' => $query->where('status', 1),
                'down' => $query->where('status', 0),
                default => null,
            };
        }

        if (! empty($params['os'])) {
            $query->where('os', $params['os']);
        }

        if (! empty($params['location'])) {
            $query->whereHas('location', fn ($q) => $q->where('location', 'like', '%' . $params['location'] . '%'));
        }

        if (! empty($params['group'])) {
            $query->whereHas('groups', fn ($q) => $q->where('name', $params['group']));
        }

        $limit = min($params['limit'] ?? 50, 100);

        $devices = $query->select('device_id', 'hostname', 'sysName', 'status', 'os', 'uptime', 'hardware', 'version')
            ->with('location:id,location')
            ->limit($limit)
            ->get();

        return [
            'count' => $devices->count(),
            'devices' => $devices->map(fn ($d) => [
                'device_id' => $d->device_id,
                'hostname' => $d->hostname,
                'sysName' => $d->sysName,
                'status' => $d->status ? 'up' : 'down',
                'os' => $d->os,
                'uptime' => $d->uptime,
                'hardware' => $d->hardware,
                'version' => $d->version,
                'location' => $d->location?->location,
            ])->toArray(),
        ];
    }
}
```

- [ ] **Step 2: Create GetDeviceDetail**

```php
<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Device;
use App\Models\User;

class GetDeviceDetail extends AbstractAiTool
{
    public function name(): string { return 'get_device_detail'; }

    public function description(): string
    {
        return 'Get detailed information about a single device by hostname or device_id. Returns hardware, software, uptime, sensors, port summary, and recent alerts. Use for deep-dive questions about a specific device.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'hostname' => ['type' => 'string', 'description' => 'Device hostname (or partial match)'],
                'device_id' => ['type' => 'integer', 'description' => 'Device ID'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = Device::query();
        if ($user) {
            $query->hasAccess($user);
        }

        if (isset($params['device_id'])) {
            $query->where('device_id', $params['device_id']);
        } elseif (isset($params['hostname'])) {
            $query->where('hostname', 'like', '%' . $params['hostname'] . '%');
        } else {
            return ['error' => 'Provide either hostname or device_id'];
        }

        $device = $query->first();

        if (! $device) {
            return ['error' => 'Device not found or access denied'];
        }

        $ports = $device->ports()->where('disabled', 0)->where('deleted', 0);
        $sensors = $device->sensors()->select('sensor_class', 'sensor_descr', 'sensor_current', 'sensor_limit', 'sensor_limit_low')->get();
        $recentAlerts = $device->alerts()->where('state', '>=', 1)->with('rule:id,name,severity')->limit(10)->get();

        return [
            'device_id' => $device->device_id,
            'hostname' => $device->hostname,
            'sysName' => $device->sysName,
            'sysDescr' => $device->sysDescr,
            'status' => $device->status ? 'up' : 'down',
            'os' => $device->os,
            'hardware' => $device->hardware,
            'version' => $device->version,
            'serial' => $device->serial,
            'uptime' => $device->uptime,
            'uptime_human' => \LibreNMS\Util\Time::formatInterval($device->uptime),
            'location' => $device->location?->location,
            'ports_total' => (clone $ports)->count(),
            'ports_up' => (clone $ports)->where('ifOperStatus', 'up')->count(),
            'ports_down' => (clone $ports)->where('ifOperStatus', 'down')->where('ifAdminStatus', 'up')->count(),
            'sensors' => $sensors->map(fn ($s) => [
                'class' => $s->sensor_class,
                'description' => $s->sensor_descr,
                'current' => $s->sensor_current,
                'limit_high' => $s->sensor_limit,
                'limit_low' => $s->sensor_limit_low,
            ])->toArray(),
            'active_alerts' => $recentAlerts->map(fn ($a) => [
                'alert_id' => $a->id,
                'rule' => $a->rule?->name,
                'severity' => $a->rule?->severity,
                'state' => $a->state,
            ])->toArray(),
        ];
    }
}
```

- [ ] **Step 3: Create GetActiveAlerts**

```php
<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Alert;
use App\Models\User;

class GetActiveAlerts extends AbstractAiTool
{
    public function name(): string { return 'get_active_alerts'; }

    public function description(): string
    {
        return 'Get currently active (firing) alerts with severity, device, rule name, and duration. Use for "any alerts?" or "what is currently broken?"';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'severity' => ['type' => 'string', 'enum' => ['critical', 'warning', 'ok'], 'description' => 'Filter by severity'],
                'device_id' => ['type' => 'integer', 'description' => 'Filter to a specific device'],
                'limit' => ['type' => 'integer', 'description' => 'Max results. Default: 50'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = Alert::query()
            ->where('state', '>=', 1)
            ->with(['device:device_id,hostname,sysName', 'rule:id,name,severity']);

        if ($user) {
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (isset($params['severity'])) {
            $query->whereHas('rule', fn ($q) => $q->where('severity', $params['severity']));
        }

        if (isset($params['device_id'])) {
            $query->where('device_id', $params['device_id']);
        }

        $limit = min($params['limit'] ?? 50, 100);
        $alerts = $query->limit($limit)->get();

        return [
            'count' => $alerts->count(),
            'alerts' => $alerts->map(fn ($a) => [
                'alert_id' => $a->id,
                'device' => $a->device?->hostname ?? $a->device?->sysName,
                'device_id' => $a->device_id,
                'rule' => $a->rule?->name,
                'severity' => $a->rule?->severity,
                'state' => match ($a->state) {
                    1 => 'active',
                    2 => 'acknowledged',
                    default => 'unknown',
                },
                'timestamp' => $a->timestamp,
            ])->toArray(),
        ];
    }

}
```

- [ ] **Step 4: Create GetAlertHistory**

```php
<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\AlertLog;
use App\Models\User;

class GetAlertHistory extends AbstractAiTool
{
    public function name(): string { return 'get_alert_history'; }

    public function description(): string
    {
        return 'Get historical alert log entries within a time range. Shows when alerts fired and recovered. Use for "how many alerts last week?" or "what happened yesterday?"';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'hours' => ['type' => 'integer', 'description' => 'Look back this many hours. Default: 24'],
                'device_id' => ['type' => 'integer', 'description' => 'Filter to a specific device'],
                'limit' => ['type' => 'integer', 'description' => 'Max results. Default: 50'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $hours = $params['hours'] ?? 24;
        $since = now()->subHours($hours);

        $query = AlertLog::query()
            ->where('time_logged', '>=', $since)
            ->with(['device:device_id,hostname,sysName', 'rule:id,name,severity'])
            ->orderBy('time_logged', 'desc');

        if ($user) {
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (isset($params['device_id'])) {
            $query->where('device_id', $params['device_id']);
        }

        $limit = min($params['limit'] ?? 50, 100);
        $logs = $query->limit($limit)->get();

        return [
            'period' => "Last $hours hours",
            'count' => $logs->count(),
            'entries' => $logs->map(fn ($l) => [
                'device' => $l->device?->hostname ?? $l->device?->sysName,
                'rule' => $l->rule?->name,
                'severity' => $l->rule?->severity,
                'state' => match ($l->state) {
                    1 => 'fired',
                    0 => 'recovered',
                    2 => 'acknowledged',
                    default => 'unknown',
                },
                'time' => $l->time_logged,
            ])->toArray(),
        ];
    }

}
```

- [ ] **Step 5: Create GetPorts**

```php
<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Port;
use App\Models\User;

class GetPorts extends AbstractAiTool
{
    public function name(): string { return 'get_ports'; }

    public function description(): string
    {
        return 'Get port/interface status and statistics. Can filter by status, device, errors, or speed. Use for "which ports have errors?" or "show trunk ports on switch-1".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => ['type' => 'integer', 'description' => 'Filter to a specific device'],
                'status' => ['type' => 'string', 'enum' => ['up', 'down', 'admin_down'], 'description' => 'Filter by operational status'],
                'has_errors' => ['type' => 'boolean', 'description' => 'Only show ports with errors'],
                'limit' => ['type' => 'integer', 'description' => 'Max results. Default: 50'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = Port::query()
            ->where('disabled', 0)
            ->where('deleted', 0)
            ->with('device:device_id,hostname,sysName');

        if ($user) {
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (isset($params['device_id'])) {
            $query->where('device_id', $params['device_id']);
        }

        if (isset($params['status'])) {
            match ($params['status']) {
                'up' => $query->where('ifOperStatus', 'up'),
                'down' => $query->where('ifOperStatus', 'down')->where('ifAdminStatus', 'up'),
                'admin_down' => $query->where('ifAdminStatus', 'down'),
                default => null,
            };
        }

        if (! empty($params['has_errors'])) {
            $query->where(fn ($q) => $q->where('ifInErrors_delta', '>', 0)->orWhere('ifOutErrors_delta', '>', 0));
        }

        $limit = min($params['limit'] ?? 50, 100);
        $ports = $query->limit($limit)->get();

        return [
            'count' => $ports->count(),
            'ports' => $ports->map(fn ($p) => [
                'port_id' => $p->port_id,
                'device' => $p->device?->hostname,
                'ifName' => $p->ifName,
                'ifAlias' => $p->ifAlias,
                'ifOperStatus' => $p->ifOperStatus,
                'ifAdminStatus' => $p->ifAdminStatus,
                'ifSpeed' => $p->ifSpeed,
                'ifInOctets_rate' => $p->ifInOctets_rate,
                'ifOutOctets_rate' => $p->ifOutOctets_rate,
                'ifInErrors_delta' => $p->ifInErrors_delta,
                'ifOutErrors_delta' => $p->ifOutErrors_delta,
            ])->toArray(),
        ];
    }

}
```

- [ ] **Step 6: Create GetSensors**

```php
<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Sensor;
use App\Models\User;

class GetSensors extends AbstractAiTool
{
    public function name(): string { return 'get_sensors'; }

    public function description(): string
    {
        return 'Get sensor readings (temperature, voltage, humidity, fan speed, power, etc.). Can filter by device or sensor class. Use for "are any devices running hot?" or "show power readings".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => ['type' => 'integer', 'description' => 'Filter to a specific device'],
                'class' => ['type' => 'string', 'description' => 'Sensor class: temperature, voltage, humidity, fanspeed, power, current, etc.'],
                'alert_only' => ['type' => 'boolean', 'description' => 'Only show sensors exceeding warning/critical thresholds'],
                'limit' => ['type' => 'integer', 'description' => 'Max results. Default: 50'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = Sensor::query()->with('device:device_id,hostname,sysName');

        if ($user) {
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (isset($params['device_id'])) {
            $query->where('device_id', $params['device_id']);
        }

        if (! empty($params['class'])) {
            $query->where('sensor_class', $params['class']);
        }

        if (! empty($params['alert_only'])) {
            $query->where(fn ($q) => $q
                ->whereColumn('sensor_current', '>', 'sensor_limit')
                ->orWhereColumn('sensor_current', '<', 'sensor_limit_low'));
        }

        $limit = min($params['limit'] ?? 50, 100);
        $sensors = $query->limit($limit)->get();

        return [
            'count' => $sensors->count(),
            'sensors' => $sensors->map(fn ($s) => [
                'device' => $s->device?->hostname,
                'class' => $s->sensor_class,
                'description' => $s->sensor_descr,
                'current' => $s->sensor_current,
                'limit_high' => $s->sensor_limit,
                'limit_low' => $s->sensor_limit_low,
                'limit_warn' => $s->sensor_limit_warn,
            ])->toArray(),
        ];
    }

}
```

- [ ] **Step 7: Create GetEventLog**

```php
<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Eventlog;
use App\Models\User;

class GetEventLog extends AbstractAiTool
{
    public function name(): string { return 'get_event_log'; }

    public function description(): string
    {
        return 'Get recent event log entries, optionally filtered by device or time range. Events include device status changes, interface changes, configuration changes, etc.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => ['type' => 'integer', 'description' => 'Filter to a specific device'],
                'hours' => ['type' => 'integer', 'description' => 'Look back this many hours. Default: 24'],
                'type' => ['type' => 'string', 'description' => 'Event type filter'],
                'limit' => ['type' => 'integer', 'description' => 'Max results. Default: 50'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $hours = $params['hours'] ?? 24;
        $query = Eventlog::query()
            ->where('datetime', '>=', now()->subHours($hours))
            ->with('device:device_id,hostname,sysName')
            ->orderBy('datetime', 'desc');

        if ($user) {
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (isset($params['device_id'])) {
            $query->where('device_id', $params['device_id']);
        }

        if (! empty($params['type'])) {
            $query->where('type', $params['type']);
        }

        $limit = min($params['limit'] ?? 50, 100);
        $events = $query->limit($limit)->get();

        return [
            'period' => "Last $hours hours",
            'count' => $events->count(),
            'events' => $events->map(fn ($e) => [
                'datetime' => $e->datetime,
                'device' => $e->device?->hostname,
                'type' => $e->type,
                'message' => $e->message,
                'severity' => $e->severity,
            ])->toArray(),
        ];
    }

}
```

- [ ] **Step 8: Create GetSyslog**

```php
<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Syslog;
use App\Models\User;

class GetSyslog extends AbstractAiTool
{
    public function name(): string { return 'get_syslog'; }

    public function description(): string
    {
        return 'Get syslog entries, optionally filtered by device, severity, or program. Use for "show me syslog from the firewalls" or "any critical syslog messages?"';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => ['type' => 'integer', 'description' => 'Filter to a specific device'],
                'hours' => ['type' => 'integer', 'description' => 'Look back this many hours. Default: 24'],
                'priority' => ['type' => 'string', 'description' => 'Syslog priority/severity filter (e.g., "err", "crit", "warning")'],
                'program' => ['type' => 'string', 'description' => 'Filter by syslog program name'],
                'search' => ['type' => 'string', 'description' => 'Search in syslog message text'],
                'limit' => ['type' => 'integer', 'description' => 'Max results. Default: 50'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $hours = $params['hours'] ?? 24;
        $query = Syslog::query()
            ->where('timestamp', '>=', now()->subHours($hours))
            ->with('device:device_id,hostname,sysName')
            ->orderBy('timestamp', 'desc');

        if ($user) {
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (isset($params['device_id'])) {
            $query->where('device_id', $params['device_id']);
        }

        if (! empty($params['priority'])) {
            $query->where('priority', $params['priority']);
        }

        if (! empty($params['program'])) {
            $query->where('program', $params['program']);
        }

        if (! empty($params['search'])) {
            $query->where('msg', 'like', '%' . $params['search'] . '%');
        }

        $limit = min($params['limit'] ?? 50, 100);
        $entries = $query->limit($limit)->get();

        return [
            'period' => "Last $hours hours",
            'count' => $entries->count(),
            'entries' => $entries->map(fn ($s) => [
                'timestamp' => $s->timestamp,
                'device' => $s->device?->hostname,
                'program' => $s->program,
                'priority' => $s->priority,
                'facility' => $s->facility,
                'message' => $s->msg,
            ])->toArray(),
        ];
    }

}
```

- [ ] **Step 9: Create GetDeviceOutages**

```php
<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\DeviceOutage;
use App\Models\User;

class GetDeviceOutages extends AbstractAiTool
{
    public function name(): string { return 'get_device_outages'; }

    public function description(): string
    {
        return 'Get device outage/downtime history. Use for "when was the last time router-1 went down?" or "which devices had outages this week?"';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => ['type' => 'integer', 'description' => 'Filter to a specific device'],
                'hostname' => ['type' => 'string', 'description' => 'Filter by hostname (partial match)'],
                'hours' => ['type' => 'integer', 'description' => 'Look back this many hours. Default: 168 (7 days)'],
                'limit' => ['type' => 'integer', 'description' => 'Max results. Default: 50'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $hours = $params['hours'] ?? 168;
        $query = DeviceOutage::query()
            ->where('going_down', '>=', now()->subHours($hours)->timestamp)
            ->with('device:device_id,hostname,sysName')
            ->orderBy('going_down', 'desc');

        if ($user) {
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (isset($params['device_id'])) {
            $query->where('device_id', $params['device_id']);
        }

        if (! empty($params['hostname'])) {
            $query->whereHas('device', fn ($q) => $q->where('hostname', 'like', '%' . $params['hostname'] . '%'));
        }

        $limit = min($params['limit'] ?? 50, 100);
        $outages = $query->limit($limit)->get();

        return [
            'period' => "Last $hours hours",
            'count' => $outages->count(),
            'outages' => $outages->map(fn ($o) => [
                'device' => $o->device?->hostname,
                'device_id' => $o->device_id,
                'going_down' => date('Y-m-d H:i:s', $o->going_down),
                'up_again' => $o->up_again ? date('Y-m-d H:i:s', $o->up_again) : 'still down',
                'duration_seconds' => $o->up_again ? ($o->up_again - $o->going_down) : null,
            ])->toArray(),
        ];
    }

}
```

- [ ] **Step 10: Create GetServices**

```php
<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\Service;
use App\Models\User;

class GetServices extends AbstractAiTool
{
    public function name(): string { return 'get_services'; }

    public function description(): string
    {
        return 'Get service monitoring status (HTTP, DNS, SMTP, etc.). Use for "are all services healthy?" or "which services are failing?"';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => ['type' => 'integer', 'description' => 'Filter to a specific device'],
                'status' => ['type' => 'string', 'enum' => ['ok', 'warning', 'critical'], 'description' => 'Filter by status'],
                'type' => ['type' => 'string', 'description' => 'Filter by service type (e.g., "http", "dns")'],
                'limit' => ['type' => 'integer', 'description' => 'Max results. Default: 50'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = Service::query()->with('device:device_id,hostname,sysName');

        if ($user) {
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (isset($params['device_id'])) {
            $query->where('device_id', $params['device_id']);
        }

        if (isset($params['status'])) {
            $statusMap = ['ok' => 0, 'warning' => 1, 'critical' => 2];
            $query->where('service_status', $statusMap[$params['status']] ?? 0);
        }

        if (! empty($params['type'])) {
            $query->where('service_type', $params['type']);
        }

        $limit = min($params['limit'] ?? 50, 100);
        $services = $query->limit($limit)->get();

        return [
            'count' => $services->count(),
            'services' => $services->map(fn ($s) => [
                'service_id' => $s->service_id,
                'device' => $s->device?->hostname,
                'type' => $s->service_type,
                'description' => $s->service_desc,
                'status' => match ($s->service_status) {
                    0 => 'ok',
                    1 => 'warning',
                    2 => 'critical',
                    default => 'unknown',
                },
                'message' => $s->service_message,
                'changed' => $s->service_changed,
            ])->toArray(),
        ];
    }

}
```

- [ ] **Step 11: Create GetRouting**

```php
<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\BgpPeer;
use App\Models\User;

class GetRouting extends AbstractAiTool
{
    public function name(): string { return 'get_routing'; }

    public function description(): string
    {
        return 'Get routing protocol status — BGP peers and their states. Use for "any BGP sessions down?" or "show BGP peers for router-1".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'device_id' => ['type' => 'integer', 'description' => 'Filter to a specific device'],
                'status' => ['type' => 'string', 'enum' => ['established', 'down', 'all'], 'description' => 'Filter by BGP state. Default: all'],
                'limit' => ['type' => 'integer', 'description' => 'Max results. Default: 50'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params, ?User $user = null): array
    {
        $query = BgpPeer::query()->with('device:device_id,hostname,sysName');

        if ($user) {
            $query->whereHas('device', fn ($q) => $q->hasAccess($user));
        }

        if (isset($params['device_id'])) {
            $query->where('device_id', $params['device_id']);
        }

        if (isset($params['status'])) {
            match ($params['status']) {
                'established' => $query->where('bgpPeerState', 'established'),
                'down' => $query->where('bgpPeerState', '!=', 'established'),
                default => null,
            };
        }

        $limit = min($params['limit'] ?? 50, 100);
        $peers = $query->limit($limit)->get();

        return [
            'count' => $peers->count(),
            'peers' => $peers->map(fn ($p) => [
                'device' => $p->device?->hostname,
                'peer_address' => $p->bgpPeerIdentifier,
                'remote_as' => $p->bgpPeerRemoteAs,
                'state' => $p->bgpPeerState,
                'admin_status' => $p->bgpPeerAdminStatus,
                'local_as' => $p->bgpLocalAs,
            ])->toArray(),
        ];
    }

}
```

- [ ] **Step 12: Commit all tools**

```bash
git add app/Plugins/AiAssistant/Tools/GetDevices.php \
        app/Plugins/AiAssistant/Tools/GetDeviceDetail.php \
        app/Plugins/AiAssistant/Tools/GetActiveAlerts.php \
        app/Plugins/AiAssistant/Tools/GetAlertHistory.php \
        app/Plugins/AiAssistant/Tools/GetPorts.php \
        app/Plugins/AiAssistant/Tools/GetSensors.php \
        app/Plugins/AiAssistant/Tools/GetEventLog.php \
        app/Plugins/AiAssistant/Tools/GetSyslog.php \
        app/Plugins/AiAssistant/Tools/GetDeviceOutages.php \
        app/Plugins/AiAssistant/Tools/GetServices.php \
        app/Plugins/AiAssistant/Tools/GetRouting.php
git commit -m "feat(ai): add data access tools for devices, alerts, ports, sensors, logs, services, and routing"
```

---

## Task 9: Database Migrations and Models

Create the database tables for sessions, messages, and cost tracking.

**Files:**
- Create: `app/Plugins/AiAssistant/Migrations/2026_04_08_000001_create_ai_sessions_table.php`
- Create: `app/Plugins/AiAssistant/Migrations/2026_04_08_000002_create_ai_messages_table.php`
- Create: `app/Plugins/AiAssistant/Migrations/2026_04_08_000003_create_ai_cost_log_table.php`
- Create: `app/Plugins/AiAssistant/Models/AiSession.php`
- Create: `app/Plugins/AiAssistant/Models/AiMessage.php`
- Create: `app/Plugins/AiAssistant/Models/AiCostLog.php`

- [ ] **Step 0: Investigate plugin migration and route loading**

Before creating migrations, check how LibreNMS handles migrations for plugins. Read:
1. `app/Providers/PluginProvider.php` — does it load migrations from plugin directories?
2. `database/migrations/` — are there patterns for plugin-specific migrations?
3. Check if `loadMigrationsFrom()` is called anywhere for plugins

If the PluginProvider doesn't auto-load plugin migrations, add migration loading to the plugin's boot process. The most likely approach is:

Option A: Place migrations in the standard `database/migrations/` directory with a plugin prefix (e.g., `2026_04_08_000001_ai_assistant_create_sessions_table.php`).

Option B: Create an `AiAssistantServiceProvider` that calls `$this->loadMigrationsFrom(__DIR__.'/Migrations')` in its `boot()` method, and register it in `config/app.php` or via plugin discovery.

Option C: If the PluginProvider already supports migration discovery from plugin directories, follow that pattern.

**Choose the approach that matches existing LibreNMS patterns.** If Option A is used, adjust the migration file paths below accordingly.

The same investigation applies to route loading for Task 14. Check how existing plugins register routes and follow the same mechanism.

- [ ] **Step 1: Create sessions migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 64)->unique()->index();
            $table->unsignedInteger('user_id');
            $table->string('interface', 20); // 'web', 'irc', 'api'
            $table->timestamp('last_activity')->useCurrent();
            $table->timestamps();

            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
            $table->index('last_activity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_sessions');
    }
};
```

- [ ] **Step 2: Create messages migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_session_id')->constrained('ai_sessions')->onDelete('cascade');
            $table->string('role', 20); // 'user', 'assistant', 'system', 'tool'
            $table->longText('content');
            $table->json('tool_calls')->nullable(); // For assistant messages with tool calls
            $table->string('tool_call_id', 64)->nullable(); // For tool result messages
            $table->integer('tokens')->nullable(); // Estimated token count
            $table->timestamps();

            $table->index('ai_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
```

- [ ] **Step 3: Create cost log migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_cost_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->nullable(); // null for system calls (monitoring, reports)
            $table->string('context', 20); // 'chat', 'monitoring', 'report'
            $table->string('provider', 50);
            $table->string('model', 100);
            $table->integer('input_tokens');
            $table->integer('output_tokens');
            $table->decimal('cost', 10, 6); // Calculated cost
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_cost_log');
    }
};
```

- [ ] **Step 4: Create AiSession model**

```php
<?php

namespace App\Plugins\AiAssistant\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiSession extends Model
{
    protected $table = 'ai_sessions';

    protected $fillable = ['session_id', 'user_id', 'interface', 'last_activity'];

    protected function casts(): array
    {
        return [
            'last_activity' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'session_id');
    }
}
```

- [ ] **Step 5: Create AiMessage model**

```php
<?php

namespace App\Plugins\AiAssistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    protected $table = 'ai_messages';

    protected $fillable = ['ai_session_id', 'role', 'content', 'tool_calls', 'tool_call_id', 'tokens'];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'tokens' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'ai_session_id');
    }
}
```

- [ ] **Step 6: Create AiCostLog model**

```php
<?php

namespace App\Plugins\AiAssistant\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCostLog extends Model
{
    protected $table = 'ai_cost_log';

    protected $fillable = ['user_id', 'context', 'provider', 'model', 'input_tokens', 'output_tokens', 'cost'];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost' => 'decimal:6',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
```

- [ ] **Step 7: Commit**

```bash
git add app/Plugins/AiAssistant/Migrations/ \
        app/Plugins/AiAssistant/Models/
git commit -m "feat(ai): add database migrations and models for sessions, messages, and cost tracking"
```

---

## Task 10: ContextBuilder

Builds the compact network status snapshot injected into the system prompt.

**Files:**
- Create: `app/Plugins/AiAssistant/Services/ContextBuilder.php`
- Create: `tests/Unit/AiAssistant/ContextBuilderTest.php`

- [ ] **Step 1: Write test**

```php
<?php

namespace LibreNMS\Tests\Unit\AiAssistant;

use App\Models\Device;
use App\Models\User;
use App\Plugins\AiAssistant\Services\ContextBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Tests\DBTestCase;

class ContextBuilderTest extends DBTestCase
{
    use DatabaseTransactions;

    public function testBuildContextSnapshotReturnsString(): void
    {
        $user = User::factory()->create();
        Device::factory()->count(2)->create(['status' => 1, 'disabled' => 0, 'ignore' => 0]);

        $builder = new ContextBuilder();
        $snapshot = $builder->buildContextSnapshot($user);

        $this->assertIsString($snapshot);
        $this->assertStringContainsString('devices', strtolower($snapshot));
    }

    public function testBuildContextSnapshotWithNullUserGivesFullAccess(): void
    {
        Device::factory()->count(3)->create(['status' => 1, 'disabled' => 0, 'ignore' => 0]);

        $builder = new ContextBuilder();
        $snapshot = $builder->buildContextSnapshot(null);

        $this->assertIsString($snapshot);
        $this->assertStringContainsString('3', $snapshot);
    }
}
```

- [ ] **Step 2: Run tests — should fail**

Run: `php artisan test tests/Unit/AiAssistant/ContextBuilderTest.php --verbose`

- [ ] **Step 3: Implement ContextBuilder**

```php
<?php

namespace App\Plugins\AiAssistant\Services;

use App\Models\Alert;
use App\Models\Device;
use App\Models\Eventlog;
use App\Models\Port;
use App\Models\Syslog;
use App\Models\User;

class ContextBuilder
{
    public function buildContextSnapshot(?User $user = null): string
    {
        $lines = [];

        // Device summary
        $deviceQuery = Device::query()->where('disabled', 0)->where('ignore', 0);
        if ($user) {
            $deviceQuery->hasAccess($user);
        }
        $total = (clone $deviceQuery)->count();
        $up = (clone $deviceQuery)->where('status', 1)->count();
        $down = (clone $deviceQuery)->where('status', 0)->count();

        $downNames = [];
        if ($down > 0) {
            $downNames = (clone $deviceQuery)->where('status', 0)
                ->limit(10)
                ->pluck('hostname')
                ->toArray();
        }

        $deviceLine = "Network Status: $total devices ($up up, $down down";
        if (! empty($downNames)) {
            $deviceLine .= ': ' . implode(', ', $downNames);
            if ($down > 10) {
                $deviceLine .= ' and ' . ($down - 10) . ' more';
            }
        }
        $deviceLine .= ')';
        $lines[] = $deviceLine;

        // Alert summary
        $alertQuery = Alert::query()->where('state', '>=', 1);
        if ($user) {
            $alertQuery->whereHas('device', fn ($q) => $q->hasAccess($user));
        }
        $alertCount = $alertQuery->count();
        $lines[] = "Active Alerts: $alertCount";

        // Port summary
        $portQuery = Port::query()->where('disabled', 0)->where('deleted', 0);
        if ($user) {
            $portQuery->hasAccess($user);
        }
        $portsDown = (clone $portQuery)->where('ifOperStatus', 'down')->where('ifAdminStatus', 'up')->count();
        if ($portsDown > 0) {
            $lines[] = "Ports: $portsDown down (admin up but oper down)";
        }

        // Recent activity
        $eventQuery = Eventlog::query()->where('datetime', '>=', now()->subHour());
        if ($user) {
            $eventQuery->hasAccess($user);
        }
        $recentEvents = $eventQuery->count();
        $lines[] = "Last hour: $recentEvents events";

        $lines[] = 'Current time: ' . now()->format('Y-m-d H:i:s (l)');

        return implode("\n", $lines);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Unit/AiAssistant/ContextBuilderTest.php --verbose`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Plugins/AiAssistant/Services/ContextBuilder.php \
        tests/Unit/AiAssistant/ContextBuilderTest.php
git commit -m "feat(ai): add ContextBuilder for network status snapshot injection"
```

---

## Task 11: CostTracker

Tracks LLM API costs and enforces budget limits.

**Files:**
- Create: `app/Plugins/AiAssistant/Services/CostTracker.php`
- Create: `tests/Unit/AiAssistant/CostTrackerTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

namespace LibreNMS\Tests\Unit\AiAssistant;

use App\Plugins\AiAssistant\Models\AiCostLog;
use App\Plugins\AiAssistant\Providers\LlmResponse;
use App\Plugins\AiAssistant\Services\CostTracker;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Tests\DBTestCase;

class CostTrackerTest extends DBTestCase
{
    use DatabaseTransactions;

    public function testRecordCostCreatesLogEntry(): void
    {
        $tracker = new CostTracker(
            costPerInputToken: 0.000003,
            costPerOutputToken: 0.000015,
            maxDailyCost: 10.0,
            maxMonthlyCost: 100.0,
            maxQueryCost: 1.0,
        );

        $response = new LlmResponse('test', [], 1000, 500, 'end');
        $tracker->recordCost($response, 'chat', 'openai', 'gpt-4o', 1);

        $log = AiCostLog::latest()->first();
        $this->assertNotNull($log);
        $this->assertEquals(1000, $log->input_tokens);
        $this->assertEquals(500, $log->output_tokens);
        $this->assertEquals('chat', $log->context);
    }

    public function testCheckBudgetReturnsTrueWhenUnderLimit(): void
    {
        $tracker = new CostTracker(
            costPerInputToken: 0.000003,
            costPerOutputToken: 0.000015,
            maxDailyCost: 10.0,
            maxMonthlyCost: 100.0,
            maxQueryCost: 1.0,
        );

        $this->assertTrue($tracker->checkBudget());
    }

    public function testCheckBudgetReturnsFalseWhenDailyLimitExceeded(): void
    {
        $tracker = new CostTracker(
            costPerInputToken: 0.000003,
            costPerOutputToken: 0.000015,
            maxDailyCost: 0.001,
            maxMonthlyCost: 100.0,
            maxQueryCost: 1.0,
        );

        // Insert a cost log that exceeds the daily limit
        AiCostLog::create([
            'user_id' => null,
            'context' => 'chat',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'input_tokens' => 100000,
            'output_tokens' => 50000,
            'cost' => 1.0,
        ]);

        $this->assertFalse($tracker->checkBudget());
    }

    public function testEstimateCost(): void
    {
        $tracker = new CostTracker(
            costPerInputToken: 0.000003,
            costPerOutputToken: 0.000015,
            maxDailyCost: 10.0,
            maxMonthlyCost: 100.0,
            maxQueryCost: 1.0,
        );

        $response = new LlmResponse('test', [], 1000, 500, 'end');
        $cost = $tracker->calculateCost($response);

        // 1000 * 0.000003 + 500 * 0.000015 = 0.003 + 0.0075 = 0.0105
        $this->assertEqualsWithDelta(0.0105, $cost, 0.0001);
    }
}
```

- [ ] **Step 2: Implement CostTracker**

```php
<?php

namespace App\Plugins\AiAssistant\Services;

use App\Plugins\AiAssistant\Models\AiCostLog;
use App\Plugins\AiAssistant\Providers\LlmResponse;

class CostTracker
{
    public function __construct(
        private readonly float $costPerInputToken,
        private readonly float $costPerOutputToken,
        private readonly float $maxDailyCost,
        private readonly float $maxMonthlyCost,
        private readonly float $maxQueryCost,
    ) {}

    public function calculateCost(LlmResponse $response): float
    {
        return ($response->inputTokens * $this->costPerInputToken)
             + ($response->outputTokens * $this->costPerOutputToken);
    }

    public function recordCost(LlmResponse $response, string $context, string $provider, string $model, ?int $userId = null): void
    {
        AiCostLog::create([
            'user_id' => $userId,
            'context' => $context,
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost' => $this->calculateCost($response),
        ]);
    }

    public function checkBudget(): bool
    {
        if ($this->maxDailyCost > 0) {
            $dailyCost = AiCostLog::where('created_at', '>=', now()->startOfDay())->sum('cost');
            if ($dailyCost >= $this->maxDailyCost) {
                return false;
            }
        }

        if ($this->maxMonthlyCost > 0) {
            $monthlyCost = AiCostLog::where('created_at', '>=', now()->startOfMonth())->sum('cost');
            if ($monthlyCost >= $this->maxMonthlyCost) {
                return false;
            }
        }

        return true;
    }

    public function checkQueryBudget(float $accumulatedCost): bool
    {
        return $this->maxQueryCost <= 0 || $accumulatedCost < $this->maxQueryCost;
    }

    public function getDailyCost(): float
    {
        return (float) AiCostLog::where('created_at', '>=', now()->startOfDay())->sum('cost');
    }

    public function getMonthlyCost(): float
    {
        return (float) AiCostLog::where('created_at', '>=', now()->startOfMonth())->sum('cost');
    }

    public function isApproachingDailyLimit(): bool
    {
        return $this->maxDailyCost > 0 && $this->getDailyCost() >= ($this->maxDailyCost * 0.8);
    }
}
```

- [ ] **Step 3: Run tests**

Run: `php artisan test tests/Unit/AiAssistant/CostTrackerTest.php --verbose`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Plugins/AiAssistant/Services/CostTracker.php \
        tests/Unit/AiAssistant/CostTrackerTest.php
git commit -m "feat(ai): add CostTracker for LLM API budget enforcement"
```

---

## Task 12: LLM Service

The orchestration layer: assembles prompts, runs the tool-calling loop, manages cost tracking and error handling.

**Files:**
- Create: `app/Plugins/AiAssistant/Services/LlmService.php`
- Create: `tests/Unit/AiAssistant/LlmServiceTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

namespace LibreNMS\Tests\Unit\AiAssistant;

use App\Models\User;
use App\Plugins\AiAssistant\Providers\LlmProviderInterface;
use App\Plugins\AiAssistant\Providers\LlmResponse;
use App\Plugins\AiAssistant\Services\ContextBuilder;
use App\Plugins\AiAssistant\Services\CostTracker;
use App\Plugins\AiAssistant\Services\LlmService;
use App\Plugins\AiAssistant\Tools\AiToolInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Tests\DBTestCase;

class LlmServiceTest extends DBTestCase
{
    use DatabaseTransactions;

    private function makeMockProvider(array $responses): LlmProviderInterface
    {
        return new class ($responses) implements LlmProviderInterface {
            private int $callIndex = 0;

            public function __construct(private array $responses) {}

            public function chat(array $messages, array $tools = []): LlmResponse
            {
                return $this->responses[$this->callIndex++] ?? throw new \RuntimeException('No more mock responses');
            }

            public function getModel(): string { return 'mock-model'; }
            public function getMaxContextTokens(): int { return 128000; }
        };
    }

    private function makeCostTracker(): CostTracker
    {
        return new CostTracker(
            costPerInputToken: 0.000003,
            costPerOutputToken: 0.000015,
            maxDailyCost: 100.0,
            maxMonthlyCost: 1000.0,
            maxQueryCost: 5.0,
        );
    }

    public function testSimpleTextResponse(): void
    {
        $provider = $this->makeMockProvider([
            new LlmResponse('The network is healthy.', [], 100, 20, 'end'),
        ]);

        $service = new LlmService($provider, new ContextBuilder(), $this->makeCostTracker(), []);

        $user = User::factory()->create();
        $result = $service->query(
            [['role' => 'user', 'content' => 'How is the network?']],
            $user,
            'chat',
        );

        $this->assertEquals('The network is healthy.', $result['content']);
        $this->assertEmpty($result['tool_calls_made']);
    }

    public function testToolCallingLoop(): void
    {
        $provider = $this->makeMockProvider([
            // First response: LLM wants to call a tool
            new LlmResponse('', [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'mock_tool', 'arguments' => '{}']],
            ], 100, 20, 'tool_use'),
            // Second response: LLM gives final answer
            new LlmResponse('There are 5 devices.', [], 200, 30, 'end'),
        ]);

        $mockTool = new class implements AiToolInterface {
            public function name(): string { return 'mock_tool'; }
            public function description(): string { return 'Mock tool'; }
            public function parameters(): array { return ['type' => 'object', 'properties' => new \stdClass()]; }
            public function execute(array $params, ?\App\Models\User $user = null): array { return ['count' => 5]; }
            public function toFunctionDefinition(): array { return ['type' => 'function', 'function' => ['name' => 'mock_tool', 'description' => 'Mock', 'parameters' => $this->parameters()]]; }
        };

        $service = new LlmService($provider, new ContextBuilder(), $this->makeCostTracker(), [$mockTool]);

        $user = User::factory()->create();
        $result = $service->query(
            [['role' => 'user', 'content' => 'How many devices?']],
            $user,
            'chat',
        );

        $this->assertEquals('There are 5 devices.', $result['content']);
        $this->assertCount(1, $result['tool_calls_made']);
        $this->assertEquals('mock_tool', $result['tool_calls_made'][0]);
    }

    public function testMaxIterationsPreventedRunaway(): void
    {
        // Create a provider that always returns tool calls
        $responses = array_fill(0, 15, new LlmResponse('', [
            ['id' => 'call_x', 'type' => 'function', 'function' => ['name' => 'mock_tool', 'arguments' => '{}']],
        ], 100, 20, 'tool_use'));

        $provider = $this->makeMockProvider($responses);

        $mockTool = new class implements AiToolInterface {
            public function name(): string { return 'mock_tool'; }
            public function description(): string { return 'Mock tool'; }
            public function parameters(): array { return ['type' => 'object', 'properties' => new \stdClass()]; }
            public function execute(array $params, ?\App\Models\User $user = null): array { return ['ok' => true]; }
            public function toFunctionDefinition(): array { return ['type' => 'function', 'function' => ['name' => 'mock_tool', 'description' => 'Mock', 'parameters' => $this->parameters()]]; }
        };

        $service = new LlmService($provider, new ContextBuilder(), $this->makeCostTracker(), [$mockTool]);

        $user = User::factory()->create();
        $result = $service->query(
            [['role' => 'user', 'content' => 'Loop forever']],
            $user,
            'chat',
        );

        // Should stop after max iterations and return a message
        $this->assertStringContainsString('limit', strtolower($result['content']));
    }
}
```

- [ ] **Step 2: Run tests — should fail**

Run: `php artisan test tests/Unit/AiAssistant/LlmServiceTest.php --verbose`

- [ ] **Step 3: Implement LlmService**

```php
<?php

namespace App\Plugins\AiAssistant\Services;

use App\Models\User;
use App\Plugins\AiAssistant\Providers\LlmProviderInterface;
use App\Plugins\AiAssistant\Providers\LlmResponse;
use App\Plugins\AiAssistant\Tools\AiToolInterface;

class LlmService
{
    private const MAX_TOOL_ITERATIONS = 10;

    /** @var array<string, AiToolInterface> */
    private array $toolsByName = [];

    /**
     * @param  AiToolInterface[]  $tools
     */
    public function __construct(
        private readonly LlmProviderInterface $provider,
        private readonly ContextBuilder $contextBuilder,
        private readonly CostTracker $costTracker,
        array $tools,
    ) {
        foreach ($tools as $tool) {
            $this->toolsByName[$tool->name()] = $tool;
        }
    }

    /**
     * @param  array  $messages  Conversation history [{role, content, ...}]
     * @param  ?User  $user  User for RBAC filtering (null = system-level)
     * @param  string  $context  'chat', 'monitoring', 'report'
     * @param  ?callable  $statusCallback  fn(string $status) called during tool execution for UI feedback
     * @return array{content: string, tool_calls_made: string[], total_tokens: int, cost: float}
     */
    public function query(array $messages, ?User $user, string $context, ?callable $statusCallback = null): array
    {
        if (! $this->costTracker->checkBudget()) {
            return [
                'content' => 'AI budget has been reached. Interactive queries are paused until the budget resets.',
                'tool_calls_made' => [],
                'total_tokens' => 0,
                'cost' => 0.0,
            ];
        }

        $systemPrompt = $this->buildSystemPrompt($user);
        $fullMessages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages,
        );

        $toolDefs = array_map(fn ($t) => $t->toFunctionDefinition(), array_values($this->toolsByName));
        $toolCallsMade = [];
        $totalTokens = 0;
        $totalCost = 0.0;
        $iteration = 0;

        while ($iteration < self::MAX_TOOL_ITERATIONS) {
            $iteration++;

            $response = $this->callWithRetry($fullMessages, $toolDefs);

            $cost = $this->costTracker->calculateCost($response);
            $totalCost += $cost;
            $totalTokens += $response->totalTokens();

            $this->costTracker->recordCost(
                $response, $context, get_class($this->provider), $this->provider->getModel(),
                $user?->user_id
            );

            if (! $response->hasToolCalls()) {
                return [
                    'content' => $response->content,
                    'tool_calls_made' => $toolCallsMade,
                    'total_tokens' => $totalTokens,
                    'cost' => $totalCost,
                ];
            }

            if (! $this->costTracker->checkQueryBudget($totalCost)) {
                return [
                    'content' => 'This query has reached its cost limit. Please try a simpler question.',
                    'tool_calls_made' => $toolCallsMade,
                    'total_tokens' => $totalTokens,
                    'cost' => $totalCost,
                ];
            }

            // Add assistant message with tool calls
            $fullMessages[] = [
                'role' => 'assistant',
                'content' => $response->content,
                'tool_calls' => $response->toolCalls,
            ];

            // Execute each tool call
            foreach ($response->toolCalls as $toolCall) {
                $toolName = $toolCall['function']['name'] ?? '';
                $toolCallsMade[] = $toolName;

                if ($statusCallback) {
                    $statusCallback("Calling $toolName...");
                }

                $toolResult = $this->executeTool($toolName, $toolCall['function']['arguments'] ?? '{}', $user);

                $fullMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => json_encode($toolResult),
                ];
            }
        }

        return [
            'content' => 'I reached the tool call limit while processing your request. Here is what I found so far based on the data gathered.',
            'tool_calls_made' => $toolCallsMade,
            'total_tokens' => $totalTokens,
            'cost' => $totalCost,
        ];
    }

    private function buildSystemPrompt(?User $user): string
    {
        $snapshot = $this->contextBuilder->buildContextSnapshot($user);

        $prompt = <<<PROMPT
You are a network monitoring assistant for LibreNMS. You help network administrators understand their network status, investigate issues, and identify trends.

Current network status:
$snapshot

Guidelines:
- Be concise and factual. Lead with the answer.
- When referencing devices, use their hostname.
- If data seems concerning, proactively mention it.
- Use the available tools to look up specific data when needed.
- If you don't have enough information, say so rather than guessing.
PROMPT;

        if ($user) {
            $prompt .= "\n\nYou are responding to user: {$user->username}. Only discuss devices and data this user has access to.";
        }

        return $prompt;
    }

    private function executeTool(string $toolName, string $argumentsJson, ?User $user): array
    {
        if (! isset($this->toolsByName[$toolName])) {
            return ['error' => "Unknown tool: $toolName"];
        }

        try {
            $params = json_decode($argumentsJson, true) ?? [];

            return $this->toolsByName[$toolName]->execute($params, $user);
        } catch (\Throwable $e) {
            \Log::error("AI tool $toolName failed: " . $e->getMessage());

            return ['error' => "Tool execution failed: " . $e->getMessage()];
        }
    }

    private function callWithRetry(array $messages, array $tools, int $maxRetries = 3): LlmResponse
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->provider->chat($messages, $tools);
            } catch (\RuntimeException $e) {
                $attempt++;
                $statusCode = $e->getCode();

                if ($attempt >= $maxRetries) {
                    throw $e;
                }

                // Retry on 429 (rate limit) and 5xx (server error)
                if ($statusCode === 429 || $statusCode >= 500) {
                    $delay = min(pow(2, $attempt), 60);
                    sleep($delay);

                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Auto-discover and instantiate all tool classes in the Tools directory.
     *
     * @return AiToolInterface[]
     */
    public static function discoverTools(): array
    {
        $toolsDir = __DIR__ . '/../Tools';
        $tools = [];

        foreach (glob($toolsDir . '/*.php') as $file) {
            $className = 'App\\Plugins\\AiAssistant\\Tools\\' . basename($file, '.php');
            if ($className === 'App\\Plugins\\AiAssistant\\Tools\\AiToolInterface') {
                continue;
            }
            if (class_exists($className) && is_subclass_of($className, AiToolInterface::class)) {
                $tools[] = new $className();
            }
        }

        return $tools;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Unit/AiAssistant/LlmServiceTest.php --verbose`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Plugins/AiAssistant/Services/LlmService.php \
        tests/Unit/AiAssistant/LlmServiceTest.php
git commit -m "feat(ai): add LlmService with tool-calling loop, retry, and cost tracking"
```

---

## Task 13: ConversationManager

Handles sessions, message history, and bridges interfaces to the LLM Service.

**Files:**
- Create: `app/Plugins/AiAssistant/Services/ConversationManager.php`
- Create: `tests/Unit/AiAssistant/ConversationManagerTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

namespace LibreNMS\Tests\Unit\AiAssistant;

use App\Models\User;
use App\Plugins\AiAssistant\Models\AiMessage;
use App\Plugins\AiAssistant\Models\AiSession;
use App\Plugins\AiAssistant\Providers\LlmProviderInterface;
use App\Plugins\AiAssistant\Providers\LlmResponse;
use App\Plugins\AiAssistant\Services\ContextBuilder;
use App\Plugins\AiAssistant\Services\ConversationManager;
use App\Plugins\AiAssistant\Services\CostTracker;
use App\Plugins\AiAssistant\Services\LlmService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Tests\DBTestCase;

class ConversationManagerTest extends DBTestCase
{
    use DatabaseTransactions;

    private function makeManager(array $llmResponses): ConversationManager
    {
        $provider = new class ($llmResponses) implements LlmProviderInterface {
            private int $i = 0;
            public function __construct(private array $responses) {}
            public function chat(array $messages, array $tools = []): LlmResponse
            {
                return $this->responses[$this->i++] ?? new LlmResponse('fallback', [], 10, 5, 'end');
            }
            public function getModel(): string { return 'mock'; }
            public function getMaxContextTokens(): int { return 128000; }
        };

        $costTracker = new CostTracker(0.000003, 0.000015, 100.0, 1000.0, 5.0);
        $llmService = new LlmService($provider, new ContextBuilder(), $costTracker, []);

        return new ConversationManager($llmService);
    }

    public function testHandleMessageCreatesSession(): void
    {
        $manager = $this->makeManager([
            new LlmResponse('Hello!', [], 50, 10, 'end'),
        ]);

        $user = User::factory()->create();
        $result = $manager->handleMessage('Hi there', 'test-session-1', $user, 'web');

        $this->assertEquals('Hello!', $result);

        $session = AiSession::where('session_id', 'test-session-1')->first();
        $this->assertNotNull($session);
        $this->assertEquals($user->user_id, $session->user_id);
        $this->assertEquals('web', $session->interface);
    }

    public function testHandleMessageStoresHistory(): void
    {
        $manager = $this->makeManager([
            new LlmResponse('Response 1', [], 50, 10, 'end'),
            new LlmResponse('Response 2', [], 50, 10, 'end'),
        ]);

        $user = User::factory()->create();
        $manager->handleMessage('Question 1', 'test-session-2', $user, 'web');
        $manager->handleMessage('Question 2', 'test-session-2', $user, 'web');

        $session = AiSession::where('session_id', 'test-session-2')->first();
        $messages = $session->messages()->orderBy('id')->get();

        // 2 user messages + 2 assistant messages = 4
        $this->assertCount(4, $messages);
        $this->assertEquals('user', $messages[0]->role);
        $this->assertEquals('Question 1', $messages[0]->content);
        $this->assertEquals('assistant', $messages[1]->role);
        $this->assertEquals('Response 1', $messages[1]->content);
    }

    public function testExpiredSessionCreatesNew(): void
    {
        $manager = $this->makeManager([
            new LlmResponse('Old response', [], 50, 10, 'end'),
            new LlmResponse('New response', [], 50, 10, 'end'),
        ]);

        $user = User::factory()->create();

        // Create an expired session
        $oldSession = AiSession::create([
            'session_id' => 'expired-session',
            'user_id' => $user->user_id,
            'interface' => 'web',
            'last_activity' => now()->subHours(2),
        ]);

        $result = $manager->handleMessage('Hello', 'expired-session', $user, 'web');

        // Should get a fresh session (old one expired)
        $this->assertEquals('New response', $result);
    }
}
```

- [ ] **Step 2: Run tests — should fail**

Run: `php artisan test tests/Unit/AiAssistant/ConversationManagerTest.php --verbose`

- [ ] **Step 3: Implement ConversationManager**

```php
<?php

namespace App\Plugins\AiAssistant\Services;

use App\Models\User;
use App\Plugins\AiAssistant\Models\AiMessage;
use App\Plugins\AiAssistant\Models\AiSession;

class ConversationManager
{
    private int $sessionTimeoutMinutes = 30;

    private int $maxHistoryMessages = 50;

    public function __construct(
        private readonly LlmService $llmService,
    ) {}

    public function setSessionTimeout(int $minutes): void
    {
        $this->sessionTimeoutMinutes = $minutes;
    }

    public function setMaxHistory(int $messages): void
    {
        $this->maxHistoryMessages = $messages;
    }

    public function handleMessage(string $message, string $sessionId, User $user, string $interface, ?callable $statusCallback = null): string
    {
        $session = $this->getOrCreateSession($sessionId, $user, $interface);

        // Store user message
        AiMessage::create([
            'ai_session_id' => $session->id,
            'role' => 'user',
            'content' => $message,
        ]);

        // Build conversation history from stored messages
        $history = $this->buildHistory($session);

        // Query LLM
        $result = $this->llmService->query($history, $user, 'chat', $statusCallback);

        // Store assistant response
        AiMessage::create([
            'ai_session_id' => $session->id,
            'role' => 'assistant',
            'content' => $result['content'],
        ]);

        // Update session activity
        $session->update(['last_activity' => now()]);

        return $result['content'];
    }

    private function getOrCreateSession(string $sessionId, User $user, string $interface): AiSession
    {
        $session = AiSession::where('session_id', $sessionId)->first();

        if ($session) {
            // Check if expired
            if ($session->last_activity->diffInMinutes(now()) > $this->sessionTimeoutMinutes) {
                // Delete old messages and reset
                $session->messages()->delete();
                $session->update([
                    'last_activity' => now(),
                    'user_id' => $user->user_id,
                    'interface' => $interface,
                ]);

                return $session;
            }

            return $session;
        }

        return AiSession::create([
            'session_id' => $sessionId,
            'user_id' => $user->user_id,
            'interface' => $interface,
            'last_activity' => now(),
        ]);
    }

    private function buildHistory(AiSession $session): array
    {
        $messages = $session->messages()
            ->orderBy('id', 'desc')
            ->limit($this->maxHistoryMessages)
            ->get()
            ->reverse()
            ->values();

        return $messages->map(fn (AiMessage $m) => array_filter([
            'role' => $m->role,
            'content' => $m->content,
            'tool_calls' => $m->tool_calls,
            'tool_call_id' => $m->tool_call_id,
        ]))->toArray();
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Unit/AiAssistant/ConversationManagerTest.php --verbose`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Plugins/AiAssistant/Services/ConversationManager.php \
        tests/Unit/AiAssistant/ConversationManagerTest.php
git commit -m "feat(ai): add ConversationManager with session handling and message history"
```

---

## Task 14: Chat API Endpoint and Plugin Wiring

Create the HTTP endpoint that ties everything together, plus the plugin registration files.

**Files:**
- Create: `app/Plugins/AiAssistant/Http/AiChatController.php`
- Create: `app/Plugins/AiAssistant/routes.php`
- Create: `app/Plugins/AiAssistant/Settings.php`
- Create: `app/Plugins/AiAssistant/resources/views/settings.blade.php`

- [ ] **Step 1: Create AiServiceFactory**

Extract service construction into a factory so it can be reused by the chat controller, IRC adapter, and monitoring engine without duplication.

Create `app/Plugins/AiAssistant/Services/AiServiceFactory.php`:

```php
<?php

namespace App\Plugins\AiAssistant\Services;

use App\Plugins\AiAssistant\Providers\LlmProviderInterface;
use App\Plugins\AiAssistant\Providers\OpenAiCompatibleProvider;

class AiServiceFactory
{
    /**
     * Build the full service stack from plugin settings.
     *
     * @return array{conversation_manager: ConversationManager, llm_service: LlmService}
     */
    public static function fromSettings(array $settings): array
    {
        $provider = self::buildProvider($settings);
        $costTracker = self::buildCostTracker($settings);
        $tools = LlmService::discoverTools();
        $llmService = new LlmService($provider, new ContextBuilder(), $costTracker, $tools);

        $manager = new ConversationManager($llmService);
        $manager->setSessionTimeout((int) ($settings['session_timeout'] ?? 30));
        $manager->setMaxHistory((int) ($settings['max_messages_per_session'] ?? 50));

        return [
            'conversation_manager' => $manager,
            'llm_service' => $llmService,
        ];
    }

    public static function buildProvider(array $settings, string $context = 'chat'): LlmProviderInterface
    {
        $temperatureKey = "temperature_$context";

        return new OpenAiCompatibleProvider(
            apiUrl: $settings['api_url'] ?? 'https://api.openai.com/v1',
            apiKey: $settings['api_key'] ?? '',
            model: $settings['model'] ?? 'gpt-4o',
            maxTokens: (int) ($settings['max_tokens'] ?? 1000),
            temperature: (float) ($settings[$temperatureKey] ?? 0.3),
        );
    }

    public static function buildCostTracker(array $settings): CostTracker
    {
        return new CostTracker(
            costPerInputToken: (float) ($settings['cost_per_input_token'] ?? 0.000003),
            costPerOutputToken: (float) ($settings['cost_per_output_token'] ?? 0.000015),
            maxDailyCost: (float) ($settings['max_cost_daily'] ?? 10.0),
            maxMonthlyCost: (float) ($settings['max_cost_monthly'] ?? 100.0),
            maxQueryCost: (float) ($settings['max_cost_per_query'] ?? 1.0),
        );
    }

    public static function isConfigured(array $settings): bool
    {
        return ! empty($settings['api_key'] ?? '');
    }
}
```

- [ ] **Step 2: Create AiChatController using the factory**

```php
<?php

namespace App\Plugins\AiAssistant\Http;

use App\Http\Controllers\Controller;
use App\Plugins\AiAssistant\Services\AiServiceFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiChatController extends Controller
{
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'session_id' => 'nullable|string|max:64',
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Authentication required'], 401);
        }

        $settings = app(\LibreNMS\Interfaces\Plugins\PluginManagerInterface::class)
            ->getSettings('AiAssistant');

        if (! AiServiceFactory::isConfigured($settings)) {
            return response()->json(['error' => 'AI Assistant is not configured. Set an API key in settings.'], 503);
        }

        $services = AiServiceFactory::fromSettings($settings);
        $manager = $services['conversation_manager'];

        $sessionId = $request->input('session_id', 'web-' . $user->user_id . '-' . uniqid());

        try {
            $response = $manager->handleMessage(
                $request->input('message'),
                $sessionId,
                $user,
                'web',
            );

            return response()->json([
                'response' => $response,
                'session_id' => $sessionId,
            ]);
        } catch (\Throwable $e) {
            \Log::error('AI chat error: ' . $e->getMessage());

            return response()->json([
                'error' => 'An error occurred while processing your request. Please try again.',
                'session_id' => $sessionId,
            ], 500);
        }
    }
}
```

- [ ] **Step 2: Create routes.php**

```php
<?php

use App\Plugins\AiAssistant\Http\AiChatController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('plugin/ai')->group(function () {
    Route::post('/chat', [AiChatController::class, 'chat'])->name('plugin.ai.chat');
});
```

- [ ] **Step 3: Create Settings hook**

```php
<?php

namespace App\Plugins\AiAssistant;

use App\Plugins\Hooks\SettingsHook;

class Settings extends SettingsHook
{
    public string $view = 'resources.views.settings';

    public function authorize(\Illuminate\Contracts\Auth\Authenticatable $user): bool
    {
        return $user->can('admin');
    }

    public function data(array $settings = []): array
    {
        return [
            'settings' => $settings,
        ];
    }
}
```

- [ ] **Step 4: Create settings blade template**

```blade
<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title">AI Assistant Settings</h3>
    </div>
    <div class="panel-body">
        <form method="POST" action="{{ route('plugin.settings.update', ['plugin' => 'AiAssistant']) }}">
            @csrf

            <h4>LLM Provider</h4>
            <div class="form-group">
                <label for="api_url">API URL</label>
                <input type="text" class="form-control" name="settings[api_url]"
                       value="{{ $settings['api_url'] ?? 'https://api.openai.com/v1' }}"
                       placeholder="https://api.openai.com/v1">
            </div>
            <div class="form-group">
                <label for="api_key">API Key</label>
                <input type="password" class="form-control" name="settings[api_key]"
                       value="{{ $settings['api_key'] ?? '' }}"
                       placeholder="sk-...">
            </div>
            <div class="form-group">
                <label for="model">Model</label>
                <input type="text" class="form-control" name="settings[model]"
                       value="{{ $settings['model'] ?? 'gpt-4o' }}"
                       placeholder="gpt-4o">
            </div>

            <h4>Cost Controls</h4>
            <div class="form-group">
                <label for="max_cost_daily">Max Daily Cost ($)</label>
                <input type="number" step="0.01" class="form-control" name="settings[max_cost_daily]"
                       value="{{ $settings['max_cost_daily'] ?? '10.00' }}">
            </div>
            <div class="form-group">
                <label for="max_cost_monthly">Max Monthly Cost ($)</label>
                <input type="number" step="0.01" class="form-control" name="settings[max_cost_monthly]"
                       value="{{ $settings['max_cost_monthly'] ?? '100.00' }}">
            </div>

            <h4>Chat</h4>
            <div class="form-group">
                <label for="session_timeout">Session Timeout (minutes)</label>
                <input type="number" class="form-control" name="settings[session_timeout]"
                       value="{{ $settings['session_timeout'] ?? '30' }}">
            </div>

            <button type="submit" class="btn btn-primary">Save Settings</button>
        </form>
    </div>
</div>
```

- [ ] **Step 5: Commit**

```bash
git add app/Plugins/AiAssistant/Services/AiServiceFactory.php \
        app/Plugins/AiAssistant/Http/AiChatController.php \
        app/Plugins/AiAssistant/routes.php \
        app/Plugins/AiAssistant/Settings.php \
        app/Plugins/AiAssistant/resources/views/settings.blade.php
git commit -m "feat(ai): add service factory, chat API endpoint, plugin routes, and settings UI"
```

---

## Task 15: Integration Smoke Test

Verify the full stack works end-to-end with a mock provider.

**Files:**
- Create: `tests/Feature/AiAssistant/ChatEndpointTest.php`

- [ ] **Step 1: Write feature test**

```php
<?php

namespace LibreNMS\Tests\Feature\AiAssistant;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Tests\DBTestCase;

class ChatEndpointTest extends DBTestCase
{
    use DatabaseTransactions;

    public function testChatEndpointRequiresAuth(): void
    {
        $response = $this->postJson('/plugin/ai/chat', [
            'message' => 'How is the network?',
        ]);

        $response->assertStatus(401);
    }

    public function testChatEndpointRequiresMessage(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/plugin/ai/chat', []);

        $response->assertStatus(422);
    }

    public function testChatEndpointReturnsErrorWithoutConfig(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/plugin/ai/chat', [
            'message' => 'Hello',
        ]);

        // Should return 503 because API key is not configured
        $response->assertStatus(503);
        $response->assertJsonFragment(['error' => 'AI Assistant is not configured. Set an API key in settings.']);
    }
}
```

- [ ] **Step 2: Run the feature test**

Run: `php artisan test tests/Feature/AiAssistant/ChatEndpointTest.php --verbose`

Note: The route registration may need adjustment depending on how LibreNMS loads plugin routes. If routes aren't found, check how the ExamplePlugin's routes are loaded and follow the same pattern. The PluginProvider may need to be extended to load `routes.php` from plugin directories.

- [ ] **Step 3: Fix any route loading issues and commit**

```bash
git add tests/Feature/AiAssistant/ChatEndpointTest.php
git commit -m "test(ai): add integration smoke tests for chat endpoint"
```

---

## Task 16: Run Full Test Suite

Verify nothing is broken.

- [ ] **Step 1: Run all AI assistant tests**

Run: `php artisan test tests/Unit/AiAssistant/ tests/Feature/AiAssistant/ --verbose`

- [ ] **Step 2: Run existing LibreNMS tests to verify no regressions**

Run: `php artisan test --verbose`

Note: Some existing tests may require `DBTEST=1` environment variable. Focus on ensuring no regressions in the plugin system tests.

- [ ] **Step 3: Fix any failures**

Address any test failures. Common issues:
- Autoloading: ensure `composer dump-autoload` picks up the new classes
- Database: migrations need to be run for test DB
- Plugin discovery: the PluginProvider may need to scan the AiAssistant directory

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "fix(ai): resolve test suite issues from foundation implementation"
```

---

## Completion Checklist

After all tasks are done, verify:

- [ ] 4 new hook interfaces exist in `LibreNMS/Interfaces/Plugins/Hooks/`
- [ ] 4 new hook abstract classes exist in `app/Plugins/Hooks/`
- [ ] PluginManager has `dispatchEvent()`, `collectPluginAlerts()`, `registerPluginSchedules()` methods
- [ ] `LlmProviderInterface` and `OpenAiCompatibleProvider` work with mocked HTTP
- [ ] 12 data access tools exist and respect RBAC via `hasAccess()`
- [ ] `ContextBuilder` generates a compact network snapshot
- [ ] `CostTracker` enforces daily/monthly/per-query budgets
- [ ] `LlmService` handles the tool-calling loop with retry and cost tracking
- [ ] `ConversationManager` manages sessions and message history
- [ ] `POST /plugin/ai/chat` endpoint accepts messages and returns responses
- [ ] Settings UI allows configuring API key, model, and cost limits
- [ ] All tests pass
