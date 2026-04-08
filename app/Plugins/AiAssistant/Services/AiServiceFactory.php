<?php

/*
 * AiServiceFactory.php
 *
 * Static factory that builds the AI assistant service stack from plugin settings.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2026 LibreNMS
 * @author     LibreNMS Contributors
 */

namespace App\Plugins\AiAssistant\Services;

use App\Plugins\AiAssistant\Providers\LlmProviderInterface;
use App\Plugins\AiAssistant\Providers\OpenAiCompatibleProvider;

class AiServiceFactory
{
    /**
     * Build the full service stack from plugin settings.
     *
     * @param  array  $settings  Plugin settings from the database
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
        $manager->setMaxHistoryMessages((int) ($settings['max_messages_per_session'] ?? 50));

        return [
            'conversation_manager' => $manager,
            'llm_service' => $llmService,
        ];
    }

    /**
     * Build an LLM provider instance from settings.
     *
     * @param  array  $settings  Plugin settings
     * @param  string  $context  Context key for temperature lookup (e.g. 'chat', 'monitoring')
     */
    public static function buildProvider(array $settings, string $context = 'chat'): LlmProviderInterface
    {
        $temperatureKey = "temperature_{$context}";

        return new OpenAiCompatibleProvider(
            apiUrl: $settings['api_url'] ?? 'https://api.openai.com/v1',
            apiKey: $settings['api_key'] ?? '',
            model: $settings['model'] ?? 'gpt-4o',
            maxTokens: (int) ($settings['max_tokens'] ?? 1000),
            temperature: (float) ($settings[$temperatureKey] ?? 0.3),
        );
    }

    /**
     * Build a CostTracker instance from settings.
     */
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

    /**
     * Check if the AI assistant has been configured with at minimum an API key.
     */
    public static function isConfigured(array $settings): bool
    {
        return ! empty($settings['api_key'] ?? '');
    }
}
