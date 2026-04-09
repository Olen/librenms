<?php

/*
 * ConversationManager.php
 *
 * Manages AI assistant conversation sessions, message history,
 * and session lifecycle.
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

use App\Models\User;
use App\Plugins\AiAssistant\Models\AiMessage;
use App\Plugins\AiAssistant\Models\AiSession;
use Carbon\Carbon;

class ConversationManager
{
    private int $sessionTimeoutMinutes = 30;

    private int $maxHistoryMessages = 50;

    public function __construct(
        private readonly LlmService $llmService,
    ) {
    }

    /**
     * Set the session timeout in minutes.
     */
    public function setSessionTimeout(int $minutes): self
    {
        $this->sessionTimeoutMinutes = $minutes;

        return $this;
    }

    /**
     * Set the maximum number of history messages to include in context.
     */
    public function setMaxHistoryMessages(int $max): self
    {
        $this->maxHistoryMessages = $max;

        return $this;
    }

    /**
     * Handle an incoming user message and return the assistant's response.
     */
    public function handleMessage(string $message, string $sessionId, User $user, string $interface, ?callable $statusCallback = null): string
    {
        // Get or create the session
        $session = $this->getOrCreateSession($sessionId, $user, $interface);

        // Store user message
        AiMessage::create([
            'ai_session_id' => $session->id,
            'role' => 'user',
            'content' => $message,
        ]);

        // Build conversation history from stored messages
        $history = $this->buildConversationHistory($session);

        // Query the LLM
        $result = $this->llmService->query($history, $user, 'chat', $statusCallback);

        // Store assistant response
        AiMessage::create([
            'ai_session_id' => $session->id,
            'role' => 'assistant',
            'content' => $result['content'],
            'tokens' => $result['total_tokens'],
        ]);

        // Update session last_activity
        $session->update(['last_activity' => Carbon::now()]);

        return $result['content'];
    }

    /**
     * Get an existing session or create a new one.
     * If an existing session has expired, its messages are deleted and it is reset.
     *
     * Session lookups are scoped to the current user. A session_id alone is
     * not sufficient to reach someone else's conversation history — the row
     * must also belong to the authenticated user. If a session_id collides
     * across users (or an attacker guesses one), the query will miss and a
     * fresh session is created for the current user instead.
     */
    private function getOrCreateSession(string $sessionId, User $user, string $interface): AiSession
    {
        $session = AiSession::where('session_id', $sessionId)
            ->where('user_id', $user->user_id)
            ->first();

        if ($session) {
            // Check if session has expired
            if ($session->last_activity && $session->last_activity->diffInMinutes(Carbon::now()) > $this->sessionTimeoutMinutes) {
                // Session expired - delete old messages and reset
                $session->messages()->delete();
                $session->update([
                    'last_activity' => Carbon::now(),
                    'interface' => $interface,
                ]);
            }

            return $session;
        }

        // Create new session
        return AiSession::create([
            'session_id' => $sessionId,
            'user_id' => $user->user_id,
            'interface' => $interface,
            'last_activity' => Carbon::now(),
        ]);
    }

    /**
     * Build the conversation history array from stored messages.
     *
     * @return array<int, array{role: string, content: string, tool_calls?: array, tool_call_id?: string}>
     */
    private function buildConversationHistory(AiSession $session): array
    {
        $messages = $session->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($this->maxHistoryMessages)
            ->get();

        return $messages->map(function (AiMessage $msg) {
            $entry = [
                'role' => $msg->role,
                'content' => $msg->content,
            ];

            if ($msg->tool_calls) {
                $entry['tool_calls'] = $msg->tool_calls;
            }

            if ($msg->tool_call_id) {
                $entry['tool_call_id'] = $msg->tool_call_id;
            }

            return $entry;
        })->toArray();
    }
}
