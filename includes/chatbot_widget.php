<?php
/**
 * Chatbot Widget Include (No API - Direct Processing)
 * File: includes/chatbot_widget.php
 * AJAX handler is now in dashboard.php
 */

// Only show chatbot for logged-in users
if (!isset($_SESSION['user_id'])) {
    return;
}

$first_name = explode(' ', $_SESSION['username'] ?? 'User')[0];
?>

<!-- Chatbot Widget Styles -->
<style>
.chatbot-widget-container {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 100000 !important;
    pointer-events: none;
}

.chatbot-widget-container * {
    pointer-events: auto;
}

.chatbot-toggle-btn {
    width: 65px;
    height: 65px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    color: white;
    cursor: pointer;
    box-shadow: 0 4px 20px rgba(102, 126, 234, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    position: fixed;
    bottom: 20px;
    right: 20px;
    user-select: none;
    z-index: 100001 !important;
}

.chatbot-toggle-btn:hover {
    transform: scale(1.05);
    box-shadow: 0 6px 25px rgba(102, 126, 234, 0.7);
}

.chatbot-toggle-btn.is-dragging {
    cursor: grabbing !important;
}

.chatbot-window-main {
    position: fixed;
    bottom: 100px;
    right: 20px;
    width: 400px;
    max-width: calc(100vw - 40px);
    height: 600px;
    max-height: calc(100vh - 130px);
    background: white;
    border-radius: 16px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    display: flex;
    flex-direction: column;
    animation: slideUpFade 0.3s ease;
    overflow: hidden;
    z-index: 100000 !important;
}

@keyframes slideUpFade {
    from {
        opacity: 0;
        transform: translateY(20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.chatbot-window-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: move;
    user-select: none;
}

.chatbot-header-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.chatbot-avatar {
    width: 42px;
    height: 42px;
    background: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
}

.chatbot-title {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
}

.status-indicator {
    font-size: 12px;
    opacity: 0.95;
}

.btn-minimize-chat {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
}

.btn-minimize-chat:hover {
    background: rgba(255,255,255,0.3);
}

.chatbot-messages-area {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    background: #f9fafb;
}

.message {
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    max-width: 80%;
    animation: messageSlide 0.3s ease;
}

@keyframes messageSlide {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.user-message {
    align-self: flex-end;
    align-items: flex-end;
}

.bot-message {
    align-self: flex-start;
    align-items: flex-start;
}

.message-bubble {
    padding: 12px 16px;
    border-radius: 14px;
    line-height: 1.5;
}

.user-message .message-bubble {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-bottom-right-radius: 4px;
}

.bot-message .message-bubble {
    background: white;
    color: #1f2937;
    border-bottom-left-radius: 4px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.message-bubble p {
    margin: 0;
    font-size: 14px;
}

.message-bubble p + p {
    margin-top: 8px;
}

.message-timestamp {
    font-size: 11px;
    color: #9ca3af;
    margin-top: 4px;
    padding: 0 8px;
}

.typing-indicator .typing-dots {
    display: flex;
    gap: 5px;
    padding: 8px 0;
}

.typing-dots span {
    width: 8px;
    height: 8px;
    background: #9ca3af;
    border-radius: 50%;
    animation: typingBounce 1.4s infinite;
}

.typing-dots span:nth-child(2) {
    animation-delay: 0.2s;
}

.typing-dots span:nth-child(3) {
    animation-delay: 0.4s;
}

@keyframes typingBounce {
    0%, 60%, 100% { transform: translateY(0); }
    30% { transform: translateY(-10px); }
}

.chatbot-input-area {
    display: flex;
    padding: 12px;
    background: white;
    border-top: 1px solid #e5e7eb;
    gap: 8px;
}

.chat-input-field {
    flex: 1;
    padding: 12px 16px;
    border: 2px solid #e5e7eb;
    border-radius: 24px;
    font-size: 14px;
    outline: none;
    transition: border-color 0.3s;
}

.chat-input-field:focus {
    border-color: #667eea;
}

.btn-send-message {
    width: 44px;
    height: 44px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    border-radius: 50%;
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s;
}

.btn-send-message:hover {
    transform: scale(1.05);
}

.btn-send-message:active {
    transform: scale(0.95);
}

.quick-actions-area {
    display: flex;
    gap: 6px;
    padding: 12px;
    background: white;
    flex-wrap: wrap;
}

.quick-action-btn {
    padding: 8px 14px;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-radius: 18px;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.3s;
    font-weight: 500;
}

.quick-action-btn:hover {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-color: transparent;
    transform: translateY(-1px);
}

.chatbot-messages-area::-webkit-scrollbar {
    width: 6px;
}

.chatbot-messages-area::-webkit-scrollbar-track {
    background: #f3f4f6;
}

.chatbot-messages-area::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

.chatbot-messages-area::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

@media (max-width: 768px) {
    .chatbot-toggle-btn {
        width: 56px;
        height: 56px;
        bottom: 15px;
        right: 15px;
    }
    
    .chatbot-window-main {
        width: calc(100vw - 20px);
        height: calc(100vh - 90px);
        max-height: calc(100vh - 90px);
        bottom: 80px;
        right: 10px;
        left: 10px;
        border-radius: 12px;
    }
    
    .message {
        max-width: 85%;
    }
}
.chatbot-toggle-btn.is-dragging {
    cursor: grabbing !important;
    transition: none !important; /* Remove transitions during drag */
    pointer-events: auto !important;
}

.chatbot-toggle-btn {
    touch-action: none; /* Prevent default touch behaviors */
    user-select: none;
    -webkit-user-select: none;
}
</style>

<!-- Chatbot Widget HTML -->
<div id="chatbotWidget" class="chatbot-widget-container">
    <button id="chatbotToggle" class="chatbot-toggle-btn" title="Chat with Assistant">
        <svg class="chat-icon" width="28" height="28" viewBox="0 0 24 24" fill="none">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" 
                  stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <svg class="close-icon" width="28" height="28" viewBox="0 0 24 24" fill="none" style="display:none;">
            <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
    </button>

    <div id="chatbotWindow" class="chatbot-window-main" style="display:none;">
        <div class="chatbot-window-header" id="chatHeader">
            <div class="chatbot-header-info">
                <div class="chatbot-avatar">🤖</div>
                <div>
                    <h4 class="chatbot-title">FinSight Assistant</h4>
                    <span class="status-indicator">● Online</span>
                </div>
            </div>
            <button id="minimizeChat" class="btn-minimize-chat">−</button>
        </div>

        <div class="chatbot-messages-area" id="chatMessages">
            <div class="message bot-message">
                <div class="message-bubble">
                    <p>👋 Hi <?php echo htmlspecialchars($first_name); ?>!</p>
                    <p>I'm your financial assistant. Ask me about your budget, expenses, or type <strong>'help'</strong>!</p>
                </div>
                <span class="message-timestamp"><?php echo date('g:i A'); ?></span>
            </div>
        </div>

        <div class="chatbot-input-area">
            <input type="text" 
                   id="chatInput" 
                   class="chat-input-field"
                   placeholder="Type your message..."
                   autocomplete="off">
            <button id="sendBtn" class="btn-send-message">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                    <path d="M22 2L11 13M22 2L15 22L11 13M22 2L2 8L11 13" 
                          stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </div>

        <div class="quick-actions-area">
            <button class="quick-action-btn" data-message="show my budget">💰 Budget</button>
            <button class="quick-action-btn" data-message="how much left">💵 Left</button>
            <button class="quick-action-btn" data-message="recent expenses">📝 Recent</button>
            <button class="quick-action-btn" data-message="help">❓ Help</button>
        </div>
    </div>
</div>

<!-- Chatbot Widget JavaScript -->
<script>
(function() {
    console.log('Initializing chatbot widget...');
    
    const chatbotToggle = document.getElementById('chatbotToggle');
    const chatbotWindow = document.getElementById('chatbotWindow');
    const minimizeChat = document.getElementById('minimizeChat');
    const chatMessages = document.getElementById('chatMessages');
    const chatInput = document.getElementById('chatInput');
    const sendBtn = document.getElementById('sendBtn');
    const quickBtns = document.querySelectorAll('.quick-action-btn');
    const chatIcon = document.querySelector('.chat-icon');
    const closeIcon = document.querySelector('.close-icon');

    if (!chatbotToggle) {
        console.error('Chatbot elements not found!');
        return;
    }

    let isChatOpen = false;
    let dragState = {
        isDragging: false,
        startX: 0,
        startY: 0,
        startLeft: 0,
        startTop: 0,
        hasMoved: false
    };

    // Toggle chat
chatbotToggle.addEventListener('click', function(e) {
    if (hasMoved) {
        return; // Don't toggle if we just finished dragging
    }

    isChatOpen = !isChatOpen;
    
    if (isChatOpen) {
        chatbotWindow.style.display = 'flex';
        chatIcon.style.display = 'none';
        closeIcon.style.display = 'block';
        setTimeout(() => chatInput.focus(), 100);
    } else {
        chatbotWindow.style.display = 'none';
        chatIcon.style.display = 'block';
        closeIcon.style.display = 'none';
    }
});

    minimizeChat.addEventListener('click', function(e) {
        e.stopPropagation();
        chatbotWindow.style.display = 'none';
        chatIcon.style.display = 'block';
        closeIcon.style.display = 'none';
        isChatOpen = false;
    });

    // Make button draggable
let isDragging = false;
let hasMoved = false;
let currentX = 0;
let currentY = 0;
let initialX = 0;
let initialY = 0;

chatbotToggle.addEventListener('mousedown', dragStart);
chatbotToggle.addEventListener('touchstart', dragStart, { passive: false });

function dragStart(e) {
    const touch = e.type === 'touchstart' ? e.touches[0] : e;
    
    // Get current computed position
    const rect = chatbotToggle.getBoundingClientRect();
    currentX = rect.left;
    currentY = rect.top;
    
    initialX = touch.clientX - currentX;
    initialY = touch.clientY - currentY;
    
    isDragging = true;
    hasMoved = false;
    
    chatbotToggle.classList.add('is-dragging');
    
    document.addEventListener('mousemove', drag);
    document.addEventListener('touchmove', drag, { passive: false });
    document.addEventListener('mouseup', dragEnd);
    document.addEventListener('touchend', dragEnd);
}

function drag(e) {
    if (!isDragging) return;
    
    e.preventDefault();
    
    const touch = e.type === 'touchmove' ? e.touches[0] : e;
    
    // Calculate new position
    let newX = touch.clientX - initialX;
    let newY = touch.clientY - initialY;
    
    // Check if moved significantly
    if (Math.abs(newX - currentX) > 3 || Math.abs(newY - currentY) > 3) {
        hasMoved = true;
    }
    
    // Constrain to viewport
    const maxX = window.innerWidth - chatbotToggle.offsetWidth;
    const maxY = window.innerHeight - chatbotToggle.offsetHeight;
    
    newX = Math.max(0, Math.min(newX, maxX));
    newY = Math.max(0, Math.min(newY, maxY));
    
    // Apply position immediately with transform for smoothness
    chatbotToggle.style.left = newX + 'px';
    chatbotToggle.style.top = newY + 'px';
    chatbotToggle.style.right = 'auto';
    chatbotToggle.style.bottom = 'auto';
    
    currentX = newX;
    currentY = newY;
}

function dragEnd() {
    isDragging = false;
    chatbotToggle.classList.remove('is-dragging');
    
    document.removeEventListener('mousemove', drag);
    document.removeEventListener('touchmove', drag);
    document.removeEventListener('mouseup', dragEnd);
    document.removeEventListener('touchend', dragEnd);
    
    // Reset hasMoved after a short delay
    setTimeout(() => {
        hasMoved = false;
    }, 10);
}

    function onDrag(e) {
        if (!dragState.isDragging) return;
        
        e.preventDefault();
        const touch = e.type === 'touchmove' ? e.touches[0] : e;
        const deltaX = touch.clientX - dragState.startX;
        const deltaY = touch.clientY - dragState.startY;

        if (Math.abs(deltaX) > 3 || Math.abs(deltaY) > 3) {
            dragState.hasMoved = true;
        }
        
        const newLeft = dragState.startLeft + deltaX;
        const newTop = dragState.startTop + deltaY;

        const maxX = window.innerWidth - chatbotToggle.offsetWidth;
        const maxY = window.innerHeight - chatbotToggle.offsetHeight;

        chatbotToggle.style.left = Math.max(0, Math.min(newLeft, maxX)) + 'px';
        chatbotToggle.style.top = Math.max(0, Math.min(newTop, maxY)) + 'px';
        chatbotToggle.style.right = 'auto';
        chatbotToggle.style.bottom = 'auto';
    }

    function stopDrag() {
        dragState.isDragging = false;
        chatbotToggle.classList.remove('is-dragging');
        
        document.removeEventListener('mousemove', onDrag);
        document.removeEventListener('touchmove', onDrag);
        document.removeEventListener('mouseup', stopDrag);
        document.removeEventListener('touchend', stopDrag);
    }

    // Send message
    function sendMessage(messageText) {
        const message = messageText || chatInput.value.trim();
        if (!message) return;
        
        addMessage(message, 'user');
        if (!messageText) {
            chatInput.value = '';
        }
        
        showTyping();
        
        // Send to chatbot AJAX handler
        const formData = new FormData();
        formData.append('chatbot_message', message);
        
        fetch('chatbot_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(res => {
            console.log('Response status:', res.status);
            return res.text();
        })
        .then(text => {
            console.log('Raw response (first 200 chars):', text.substring(0, 200));
            try {
                const data = JSON.parse(text);
                removeTyping();
                if (data.success) {
                    addMessage(data.message, 'bot');
                } else {
                    addMessage(data.message || 'Sorry, I encountered an error.', 'bot');
                    if (data.debug) {
                        console.error('Debug info:', data.debug);
                    }
                }
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Full response:', text);
                removeTyping();
                addMessage('Error: Received invalid response from server.', 'bot');
            }
        })
        .catch(err => {
            removeTyping();
            addMessage('Error processing your message. Please try again.', 'bot');
            console.error('Chatbot error:', err);
        });
    }

    function addMessage(text, sender) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${sender}-message`;
        
        const time = new Date().toLocaleTimeString('en-US', { 
            hour: 'numeric', 
            minute: '2-digit' 
        });
        
        // Format message with line breaks and bold
        const formattedText = text
            .replace(/\n/g, '<br>')
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        
        messageDiv.innerHTML = `
            <div class="message-bubble">
                <p>${formattedText}</p>
            </div>
            <span class="message-timestamp">${time}</span>
        `;
        
        chatMessages.appendChild(messageDiv);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function showTyping() {
        const typingDiv = document.createElement('div');
        typingDiv.className = 'message bot-message typing-indicator';
        typingDiv.id = 'typingIndicator';
        typingDiv.innerHTML = `
            <div class="message-bubble">
                <div class="typing-dots">
                    <span></span><span></span><span></span>
                </div>
            </div>
        `;
        chatMessages.appendChild(typingDiv);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function removeTyping() {
        const typing = document.getElementById('typingIndicator');
        if (typing) typing.remove();
    }

    sendBtn.addEventListener('click', function() {
        sendMessage();
    });
    
    chatInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            sendMessage();
        }
    });

    quickBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const message = btn.dataset.message;
            if (message) {
                sendMessage(message);
            }
        });
    });

    console.log('Chatbot widget initialized successfully!');
})();
</script>