<?php
// chat_widget.php — Floating AI Chat Widget
// Include at the bottom of every page, just before </body>
// Usage: include __DIR__ . '/chat_widget.php';
?>
<style>
  /* ── Chat bubble button ── */
  #chat-bubble {
    position: fixed;
    bottom: 28px;
    right: 28px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #4D148C, #FF6200);
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 16px rgba(77,20,140,0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    transition: transform 0.2s, box-shadow 0.2s;
  }
  #chat-bubble:hover {
    transform: scale(1.08);
    box-shadow: 0 6px 20px rgba(77,20,140,0.5);
  }
  #chat-bubble svg {
    width: 26px;
    height: 26px;
    fill: white;
  }
  #chat-bubble .chat-badge {
    position: absolute;
    top: -2px;
    right: -2px;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: #FF6200;
    border: 2px solid white;
    display: none;
  }

  /* ── Chat panel ── */
  #chat-panel {
    position: fixed;
    bottom: 96px;
    right: 28px;
    width: 360px;
    max-height: 520px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.18);
    display: none;
    flex-direction: column;
    z-index: 9998;
    overflow: hidden;
    font-family: 'Open Sans', sans-serif;
  }
  #chat-panel.open {
    display: flex;
  }

  /* Panel header */
  .chat-header {
    background: linear-gradient(135deg, #4D148C, #6a1fcb);
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .chat-header-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
  }
  .chat-header-info { flex: 1; }
  .chat-header-name {
    color: white;
    font-size: 14px;
    font-weight: 700;
    line-height: 1.2;
  }
  .chat-header-status {
    color: rgba(255,255,255,0.7);
    font-size: 11px;
  }
  .chat-close-btn {
    background: none;
    border: none;
    color: rgba(255,255,255,0.7);
    cursor: pointer;
    font-size: 18px;
    padding: 0;
    line-height: 1;
    transition: color 0.15s;
  }
  .chat-close-btn:hover { color: white; }

  /* Messages area */
  #chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    background: #f8f6ff;
    min-height: 200px;
    max-height: 340px;
  }

  .chat-msg {
    display: flex;
    flex-direction: column;
    max-width: 85%;
  }
  .chat-msg.user { align-self: flex-end; align-items: flex-end; }
  .chat-msg.ai   { align-self: flex-start; align-items: flex-start; }

  .chat-msg-bubble {
    padding: 9px 13px;
    border-radius: 14px;
    font-size: 13px;
    line-height: 1.5;
    word-break: break-word;
  }
  .chat-msg.user .chat-msg-bubble {
    background: #4D148C;
    color: white;
    border-bottom-right-radius: 4px;
  }
  .chat-msg.ai .chat-msg-bubble {
    background: white;
    color: #1a1a1a;
    border: 1px solid #e0e0e0;
    border-bottom-left-radius: 4px;
  }
  .chat-msg-time {
    font-size: 10px;
    color: #aaa;
    margin-top: 3px;
    padding: 0 4px;
  }

  /* Typing indicator */
  .chat-typing {
    display: none;
    align-self: flex-start;
  }
  .chat-typing.visible { display: flex; }
  .chat-typing-bubble {
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 14px;
    border-bottom-left-radius: 4px;
    padding: 10px 14px;
    display: flex;
    gap: 4px;
    align-items: center;
  }
  .chat-typing-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: #4D148C;
    opacity: 0.4;
    animation: typingBounce 1.2s infinite;
  }
  .chat-typing-dot:nth-child(2) { animation-delay: 0.2s; }
  .chat-typing-dot:nth-child(3) { animation-delay: 0.4s; }
  @keyframes typingBounce {
    0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
    30% { transform: translateY(-4px); opacity: 1; }
  }

  /* Welcome message */
  .chat-welcome {
    text-align: center;
    padding: 20px 10px;
    color: #888;
    font-size: 13px;
  }
  .chat-welcome .chat-welcome-icon { font-size: 28px; margin-bottom: 8px; }
  .chat-welcome strong { color: #4D148C; }

  /* Input area */
  .chat-input-area {
    padding: 12px;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 8px;
    background: white;
    align-items: flex-end;
  }
  #chat-input {
    flex: 1;
    border: 1px solid #d0d0d0;
    border-radius: 20px;
    padding: 9px 14px;
    font-size: 13px;
    font-family: 'Open Sans', sans-serif;
    resize: none;
    outline: none;
    max-height: 80px;
    overflow-y: auto;
    line-height: 1.4;
    transition: border-color 0.15s;
  }
  #chat-input:focus { border-color: #4D148C; }
  #chat-input::placeholder { color: #bbb; }
  #chat-send-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #4D148C;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: background 0.15s, opacity 0.15s;
  }
  #chat-send-btn:hover { background: #6a1fcb; }
  #chat-send-btn:disabled { opacity: 0.5; cursor: not-allowed; }
  #chat-send-btn svg { width: 16px; height: 16px; fill: white; }

  /* Clear history link */
  .chat-clear {
    text-align: center;
    padding: 6px;
    font-size: 11px;
    color: #bbb;
    cursor: pointer;
    border-top: 1px solid #f0f0f0;
    background: white;
    transition: color 0.15s;
  }
  .chat-clear:hover { color: #c0392b; }
</style>

<!-- Chat bubble button -->
<button id="chat-bubble" onclick="toggleChat()" title="Ask CandyClaw">
  <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
    <path d="M20 2H4C2.9 2 2 2.9 2 4v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/>
  </svg>
  <div class="chat-badge" id="chat-badge"></div>
</button>

<!-- Chat panel -->
<div id="chat-panel">
  <div class="chat-header">
    <div class="chat-header-avatar">🦞</div>
    <div class="chat-header-info">
      <div class="chat-header-name">CandyClaw AI</div>
      <div class="chat-header-status" id="chat-status">Ask me anything about the workforce</div>
    </div>
    <button class="chat-close-btn" onclick="toggleChat()">✕</button>
  </div>

  <div id="chat-messages">
    <div class="chat-welcome" id="chat-welcome">
      <div class="chat-welcome-icon">🦞</div>
      <div>Hi, <strong><?php echo htmlspecialchars($_SESSION['first_name'] ?? 'there'); ?></strong>!</div>
      <div style="margin-top:6px;">Ask me anything about the workforce data.</div>
    </div>
  </div>

  <div class="chat-typing" id="chat-typing">
    <div class="chat-typing-bubble">
      <div class="chat-typing-dot"></div>
      <div class="chat-typing-dot"></div>
      <div class="chat-typing-dot"></div>
    </div>
  </div>

  <div class="chat-input-area">
    <textarea id="chat-input" placeholder="Ask a question..." rows="1"
      onkeydown="handleChatKey(event)"
      oninput="autoResize(this)"></textarea>
    <button id="chat-send-btn" onclick="sendChatMessage()" title="Send">
      <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
    </button>
  </div>
  <div class="chat-clear" onclick="clearChatHistory()">Clear conversation</div>
</div>

<script>
let chatOpen = false;
let chatHistory = [];

function toggleChat() {
  chatOpen = !chatOpen;
  document.getElementById('chat-panel').classList.toggle('open', chatOpen);
  if (chatOpen) {
    document.getElementById('chat-input').focus();
    document.getElementById('chat-badge').style.display = 'none';
    scrollChatToBottom();
  }
}

function handleChatKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendChatMessage();
  }
}

function autoResize(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 80) + 'px';
}

function scrollChatToBottom() {
  const msgs = document.getElementById('chat-messages');
  msgs.scrollTop = msgs.scrollHeight;
}

function appendMessage(role, text, time) {
  const welcome = document.getElementById('chat-welcome');
  if (welcome) welcome.remove();

  const msgs = document.getElementById('chat-messages');
  const div = document.createElement('div');
  div.className = 'chat-msg ' + role;

  const bubble = document.createElement('div');
  bubble.className = 'chat-msg-bubble';
  bubble.innerHTML = text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.*?)\*/g, '<em>$1</em>')
    .replace(/\n/g, '<br>');

  const timeEl = document.createElement('div');
  timeEl.className = 'chat-msg-time';
  timeEl.textContent = time || '';

  div.appendChild(bubble);
  div.appendChild(timeEl);
  msgs.appendChild(div);
  scrollChatToBottom();
}

function setTyping(visible) {
  document.getElementById('chat-typing').classList.toggle('visible', visible);
  const msgs = document.getElementById('chat-messages');
  msgs.scrollTop = msgs.scrollHeight;
}

function setStatus(text) {
  document.getElementById('chat-status').textContent = text;
}

async function sendChatMessage() {
  const input = document.getElementById('chat-input');
  const question = input.value.trim();
  if (!question) return;

  const sendBtn = document.getElementById('chat-send-btn');
  input.value = '';
  input.style.height = 'auto';
  sendBtn.disabled = true;

  const now = new Date().toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'});
  appendMessage('user', question, now);
  setTyping(true);
  setStatus('Thinking...');

  try {
    const response = await fetch('chat_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ question })
    });

    const data = await response.json();
    setTyping(false);

    if (data.error) {
      appendMessage('ai', '⚠️ ' + data.error, data.timestamp || now);
    } else {
      appendMessage('ai', data.answer, data.timestamp || now);
      // Show badge if panel is closed
      if (!chatOpen) {
        document.getElementById('chat-badge').style.display = 'block';
      }
    }
    setStatus('Ask me anything about the workforce');
  } catch (err) {
    setTyping(false);
    appendMessage('ai', '⚠️ Could not reach CandyClaw. Please try again.', now);
    setStatus('Ask me anything about the workforce');
  }

  sendBtn.disabled = false;
  input.focus();
}

async function clearChatHistory() {
  if (!confirm('Clear conversation history?')) return;
  await fetch('chat_api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ question: '__clear__' })
  });
  document.getElementById('chat-messages').innerHTML =
    '<div class="chat-welcome" id="chat-welcome">' +
    '<div class="chat-welcome-icon">🦞</div>' +
    '<div>Conversation cleared. Ask me anything!</div></div>';
}
</script>
