<div style="margin: 15px;">
<h4>{{ $plugin_name }} Settings</h4>

<form method="post" style="margin: 15px">
    @csrf

    <div class="form-group">
        <label for="api_url">API URL</label>
        <input type="text" class="form-control" id="api_url" name="settings[api_url]"
               value="{{ $settings['api_url'] ?? 'https://api.openai.com/v1' }}"
               placeholder="https://api.openai.com/v1">
        <small class="form-text text-muted">Any OpenAI-compatible API endpoint.</small>
    </div>

    <div class="form-group">
        <label for="api_key">
            API Key
            @if(! empty($settings['api_key_is_set']))
                <span class="label label-success">configured</span>
            @else
                <span class="label label-warning">not configured</span>
            @endif
        </label>
        <input type="password" class="form-control" id="api_key" name="settings[api_key]"
               value="" autocomplete="off"
               placeholder="{{ ! empty($settings['api_key_is_set']) ? 'Re-enter key to save changes' : 'sk-...' }}">
        <small class="form-text text-muted">
            Required. Your API key for the LLM provider.
            @if(! empty($settings['api_key_is_set']))
                <strong>You must re-enter the API key whenever you save this form</strong> — for
                security reasons the stored key is never displayed here and blank values are
                treated as a clear.
            @endif
        </small>
    </div>

    <div class="form-group">
        <label for="model">Model</label>
        <input type="text" class="form-control" id="model" name="settings[model]"
               value="{{ $settings['model'] ?? 'gpt-4o' }}"
               placeholder="gpt-4o">
        <small class="form-text text-muted">The model identifier to use for chat completions.</small>
    </div>

    <div class="form-group">
        <label for="max_tokens">Max Tokens per Response</label>
        <input type="number" class="form-control" id="max_tokens" name="settings[max_tokens]"
               value="{{ $settings['max_tokens'] ?? 1000 }}" min="100" max="16000">
    </div>

    <div class="form-group">
        <label for="max_cost_daily">Max Daily Cost ($)</label>
        <input type="number" class="form-control" id="max_cost_daily" name="settings[max_cost_daily]"
               value="{{ $settings['max_cost_daily'] ?? 10 }}" min="0" step="0.01">
    </div>

    <div class="form-group">
        <label for="max_cost_monthly">Max Monthly Cost ($)</label>
        <input type="number" class="form-control" id="max_cost_monthly" name="settings[max_cost_monthly]"
               value="{{ $settings['max_cost_monthly'] ?? 100 }}" min="0" step="0.01">
    </div>

    <div class="form-group">
        <label for="session_timeout">Session Timeout (minutes)</label>
        <input type="number" class="form-control" id="session_timeout" name="settings[session_timeout]"
               value="{{ $settings['session_timeout'] ?? 30 }}" min="1" max="1440">
        <small class="form-text text-muted">Inactive sessions are reset after this many minutes.</small>
    </div>

    <div class="form-group">
        <label for="max_messages_per_session">Max Messages per Session</label>
        <input type="number" class="form-control" id="max_messages_per_session" name="settings[max_messages_per_session]"
               value="{{ $settings['max_messages_per_session'] ?? 50 }}" min="5" max="200">
    </div>

    <div>
        <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
</form>
</div>
