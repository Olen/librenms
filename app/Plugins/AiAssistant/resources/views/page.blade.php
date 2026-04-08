<style>
    #ai-chat-container {
        max-width: 900px;
        margin: 0 auto;
    }
    #ai-chat-messages {
        height: 500px;
        overflow-y: auto;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 15px;
        background: #fafafa;
        margin-bottom: 10px;
    }
    .ai-msg {
        margin-bottom: 12px;
        line-height: 1.5;
    }
    .ai-msg-user {
        text-align: right;
    }
    .ai-msg-user .ai-msg-bubble {
        background: #337ab7;
        color: #fff;
        display: inline-block;
        padding: 8px 14px;
        border-radius: 12px 12px 2px 12px;
        max-width: 75%;
        text-align: left;
    }
    .ai-msg-assistant .ai-msg-bubble {
        background: #e8e8e8;
        color: #333;
        display: inline-block;
        padding: 8px 14px;
        border-radius: 12px 12px 12px 2px;
        max-width: 75%;
    }
    .ai-msg-assistant .ai-msg-bubble p:last-child {
        margin-bottom: 0;
    }
    .ai-msg-status {
        text-align: center;
        color: #999;
        font-style: italic;
        font-size: 0.9em;
    }
    #ai-chat-input {
        resize: none;
    }
    .ai-msg-bubble code {
        background: rgba(0,0,0,0.06);
        padding: 1px 4px;
        border-radius: 3px;
        font-size: 0.9em;
    }
    .ai-msg-bubble pre {
        background: #2d2d2d;
        color: #f8f8f2;
        padding: 10px;
        border-radius: 4px;
        overflow-x: auto;
        margin: 6px 0;
    }
    .ai-msg-bubble pre code {
        background: none;
        padding: 0;
        color: inherit;
    }
    .ai-msg-bubble ul, .ai-msg-bubble ol {
        margin: 4px 0;
        padding-left: 20px;
    }
    .ai-msg-bubble p {
        margin: 4px 0;
    }
    .ai-msg-bubble strong {
        font-weight: 600;
    }
</style>

<div id="ai-chat-container">
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title"><i class="fa fa-robot"></i> AI Assistant</h3>
        </div>
        <div class="panel-body" style="padding: 10px;">
            <div id="ai-chat-messages">
                <div class="ai-msg ai-msg-assistant">
                    <div class="ai-msg-bubble">
                        Hello! I'm the LibreNMS AI Assistant. Ask me anything about your network &mdash; device status, alerts, port health, routing, and more.
                    </div>
                </div>
            </div>
            <form id="ai-chat-form" onsubmit="return aiSendMessage(event)">
                <div class="input-group">
                    <textarea id="ai-chat-input" class="form-control" rows="2"
                        placeholder="Ask about your network..."
                        onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault();aiSendMessage(event)}"></textarea>
                    <span class="input-group-btn" style="vertical-align: bottom;">
                        <button class="btn btn-primary" type="submit" id="ai-send-btn" style="height: 58px;">
                            <i class="fa fa-paper-plane"></i> Send
                        </button>
                    </span>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    var sessionId = 'web-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
    var messagesDiv = document.getElementById('ai-chat-messages');
    var input = document.getElementById('ai-chat-input');
    var sendBtn = document.getElementById('ai-send-btn');
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var sending = false;

    function scrollToBottom() {
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
    }

    /**
     * Render markdown text to safe DOM nodes.
     * All text content is set via textContent (never raw HTML injection).
     * Supports: paragraphs, code blocks, inline code, bold, italic, bullet/numbered lists.
     */
    function renderMarkdown(text) {
        var frag = document.createDocumentFragment();
        var parts = text.split(/```[\w]*\n?([\s\S]*?)```/g);
        for (var i = 0; i < parts.length; i++) {
            if (i % 2 === 1) {
                var pre = document.createElement('pre');
                var code = document.createElement('code');
                code.textContent = parts[i].replace(/^\n|\n$/g, '');
                pre.appendChild(code);
                frag.appendChild(pre);
            } else {
                var paragraphs = parts[i].split(/\n\n+/);
                paragraphs.forEach(function(para) {
                    para = para.trim();
                    if (!para) return;
                    var lines = para.split('\n');
                    var isBullets = lines.every(function(l) { return /^\s*[-*]\s/.test(l) || !l.trim(); });
                    var isNumbers = lines.every(function(l) { return /^\s*\d+[.)]\s/.test(l) || !l.trim(); });
                    if (isBullets && lines.some(function(l) { return l.trim(); })) {
                        var ul = document.createElement('ul');
                        lines.forEach(function(l) {
                            l = l.replace(/^\s*[-*]\s/, '').trim();
                            if (!l) return;
                            var li = document.createElement('li');
                            appendInline(li, l);
                            ul.appendChild(li);
                        });
                        frag.appendChild(ul);
                    } else if (isNumbers && lines.some(function(l) { return l.trim(); })) {
                        var ol = document.createElement('ol');
                        lines.forEach(function(l) {
                            l = l.replace(/^\s*\d+[.)]\s/, '').trim();
                            if (!l) return;
                            var li = document.createElement('li');
                            appendInline(li, l);
                            ol.appendChild(li);
                        });
                        frag.appendChild(ol);
                    } else {
                        var p = document.createElement('p');
                        lines.forEach(function(line, idx) {
                            if (idx > 0) p.appendChild(document.createElement('br'));
                            appendInline(p, line);
                        });
                        frag.appendChild(p);
                    }
                });
            }
        }
        return frag;
    }

    /** Append inline-formatted text (bold, italic, code) as safe DOM nodes. */
    function appendInline(parent, text) {
        var re = /(`[^`]+`|\*\*[^*]+\*\*|\*[^*]+\*)/g;
        var last = 0, m;
        while ((m = re.exec(text)) !== null) {
            if (m.index > last) parent.appendChild(document.createTextNode(text.substring(last, m.index)));
            var tok = m[0];
            var el;
            if (tok[0] === '`') { el = document.createElement('code'); el.textContent = tok.slice(1,-1); }
            else if (tok.startsWith('**')) { el = document.createElement('strong'); el.textContent = tok.slice(2,-2); }
            else { el = document.createElement('em'); el.textContent = tok.slice(1,-1); }
            parent.appendChild(el);
            last = re.lastIndex;
        }
        if (last < text.length) parent.appendChild(document.createTextNode(text.substring(last)));
    }

    function addMessage(role, content) {
        var wrapper = document.createElement('div');
        wrapper.className = 'ai-msg ai-msg-' + role;
        var bubble = document.createElement('div');
        bubble.className = 'ai-msg-bubble';
        if (role === 'assistant') {
            bubble.appendChild(renderMarkdown(content));
        } else {
            bubble.textContent = content;
        }
        wrapper.appendChild(bubble);
        messagesDiv.appendChild(wrapper);
        scrollToBottom();
        return wrapper;
    }

    function addStatus(text) {
        var wrapper = document.createElement('div');
        wrapper.className = 'ai-msg ai-msg-status';
        wrapper.textContent = text;
        messagesDiv.appendChild(wrapper);
        scrollToBottom();
        return wrapper;
    }

    function removeElement(el) {
        if (el && el.parentNode) {
            el.parentNode.removeChild(el);
        }
    }

    window.aiSendMessage = function(e) {
        e.preventDefault();
        var message = input.value.trim();
        if (!message || sending) return false;

        sending = true;
        sendBtn.disabled = true;
        input.disabled = true;

        addMessage('user', message);
        input.value = '';

        var statusEl = addStatus('Thinking...');

        fetch('/plugin/ai/chat', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                message: message,
                session_id: sessionId
            })
        })
        .then(function(response) {
            return response.json().then(function(data) {
                return { status: response.status, data: data };
            });
        })
        .then(function(result) {
            removeElement(statusEl);
            if (result.data.error) {
                addMessage('assistant', 'Error: ' + result.data.error);
            } else {
                addMessage('assistant', result.data.response);
                if (result.data.session_id) {
                    sessionId = result.data.session_id;
                }
            }
        })
        .catch(function(err) {
            removeElement(statusEl);
            addMessage('assistant', 'Sorry, something went wrong. Please try again.');
            console.error('AI chat error:', err);
        })
        .finally(function() {
            sending = false;
            sendBtn.disabled = false;
            input.disabled = false;
            input.focus();
        });

        return false;
    };

    input.focus();
})();
</script>
