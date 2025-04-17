/**
 * Bus Rental Chat Service - Main JavaScript
 * Contains all client-side functionality for chatbot and admin interfaces
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize the chat widget
    setupChatWidget();
    
    // Scroll chat to bottom on load
    scrollChatToBottom();
    
    // Set up form handlers
    setupFormHandlers();
    
    // Set initial height for textarea
    const textarea = document.querySelector('.chat-input textarea');
    if (textarea) {
        textarea.style.height = 'auto';
    }
    
    // Set up auto-refresh for waiting status
    setupAutoRefresh();
    
    // Set up quick reply buttons in admin chat
    setupQuickReplyButtons();
    
    // Set up real-time chat functionality
    setupRealTimeChat();
});

/**
 * Scroll the chat messages container to the bottom
 */
function scrollChatToBottom() {
    var chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
}

/**
 * Set up form submission handlers
 */
function setupFormHandlers() {
    const form = document.querySelector('.chat-input form');
    // Track last message sent to prevent rapid duplicates
    let lastMessageSent = '';
    let lastMessageTime = 0;
    
    if (form) {
        // Add keyboard event handler for the textarea
        const textarea = form.querySelector('textarea');
        if (textarea) {
            // Auto-resize functionality
            textarea.addEventListener('input', function() {
                // Reset height to auto to get the correct scrollHeight
                this.style.height = 'auto';
                // Set to scrollHeight but cap at 120px max height
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
            });
            
            textarea.addEventListener('keydown', function(event) {
                // Enter key without shift key should submit the form
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault(); // Prevent newline
                    
                    // Only submit if textarea has content
                    if (this.value.trim()) {
                        form.dispatchEvent(new Event('submit'));
                    }
                }
                
                // When Shift+Enter is pressed, resize after a short delay
                if (event.key === 'Enter' && event.shiftKey) {
                    setTimeout(() => {
                        // Reset height to auto to get the correct scrollHeight
                        this.style.height = 'auto';
                        // Set to scrollHeight but cap at 120px max height
                        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
                    }, 0);
                }
            });
        }
        
        form.addEventListener('submit', function(event) {
            // Always prevent default form submission for real-time chat
            event.preventDefault();
            
            // Get the message
            const textarea = this.querySelector('textarea');
            const message = textarea.value.trim();
            
            // Skip if empty message
            if (!message) {
                if (this.getAttribute('data-assistance-mode') === 'true') {
                    alert("Please provide a brief description to help our agents assist you better.");
                }
                return;
            }
            
            // Prevent duplicate submissions within 2 seconds
            const now = Date.now();
            if (message === lastMessageSent && now - lastMessageTime < 2000) {
                console.log('Prevented duplicate message:', message);
                textarea.value = '';
                return;
            }
            
            // Update last message tracking
            lastMessageSent = message;
            lastMessageTime = now;
            
            // Clear the textarea immediately to prevent duplicate submissions
            textarea.value = '';
            
            // Reset textarea height
            textarea.style.height = 'auto';
            
            // Disable submit button temporarily to prevent double-clicks
            const button = this.querySelector('button');
            if (button) {
                button.disabled = true;
                setTimeout(() => {
                    button.disabled = false;
                }, 500);
            }
            
            // Check if we're in assistance mode
            if (this.getAttribute('data-assistance-mode') === 'true') {
                // Use Ajax to request human assistance
                requestHumanAssistance(message);
            } else {
                // Handle normal message submission
                sendChatMessage(message);
            }
        });
    }
    
    // Set up quick question buttons
    const quickButtons = document.querySelectorAll('.quick-btn');
    if (quickButtons.length) {
        quickButtons.forEach(button => {
            button.addEventListener('click', function(event) {
                event.preventDefault();
                
                // Extract the question from the URL
                const href = this.getAttribute('href');
                const urlParams = new URLSearchParams(href.split('?')[1]);
                const encodedQuestion = urlParams.get('ask_question');
                
                if (encodedQuestion) {
                    // Let sendQuickQuestion handle the decoding
                    sendQuickQuestion(encodedQuestion);
                }
            });
        });
    }
}

/**
 * Send a chat message via Ajax
 */
function sendChatMessage(message) {
    // Generate a temporary ID for this message
    const tempId = 'temp-' + Date.now();
    
    // Add message to the UI immediately (optimistic UI update)
    addMessageToUI({
        id: tempId,
        content: message,
        sender_type: 'client',
        sender_name: 'You'
    });
    
    // Send message to server
    const formData = new FormData();
    formData.append('action', 'send_message');
    formData.append('message', message);
    
    fetch('includes/ajax_handlers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update the temporary message with the real ID
            const tempMessage = document.querySelector(`[data-message-id="${tempId}"]`);
            if (tempMessage) {
                tempMessage.setAttribute('data-message-id', data.message_id);
                tempMessage.classList.remove('temp-message');
                
                // Register this message as sent to prevent duplicates from polling
                if (window.registerMessageAsSent && data.message_id) {
                    window.registerMessageAsSent(data.message_id, message);
                }
            }
            
            // Handle bot responses if any
            if (data.responses && data.responses.length) {
                data.responses.forEach(response => {
                    addMessageToUI(response);
                    
                    // Register bot responses to prevent duplicates from polling
                    if (window.registerMessageAsSent && response.id) {
                        window.registerMessageAsSent(response.id, response.content);
                    }
                });
            }
            
            // Update chat status if needed
            updateChatStatus(data.status);
        } else {
            console.error('Error sending message:', data.message);
        }
    })
    .catch(error => {
        console.error('Error sending message:', error);
    });
}

/**
 * Helper function to properly decode and sanitize messages
 * This helps fix issues with URL encoding, special characters, and plus signs
 */
function sanitizeMessageContent(content) {
    if (!content || typeof content !== 'string') return content;
    
    // First try to decode if it's URL encoded
    try {
        // Replace plus signs with spaces (common in URL encoding)
        content = content.replace(/\+/g, ' ');
        
        // Decode URI components
        if (content.includes('%')) {
            content = decodeURIComponent(content);
        }
    } catch (e) {
        console.error('Error decoding content:', e);
    }
    
    // Clean up any HTML special characters that might have been decoded incorrectly
    content = content.replace(/&amp;/g, '&')
                    .replace(/&lt;/g, '<')
                    .replace(/&gt;/g, '>')
                    .replace(/&quot;/g, '"')
                    .replace(/&#39;/g, "'");
    
    return content;
}

/**
 * Add a message to the UI
 */
function addMessageToUI(message) {
    // Sanitize message content to fix any encoding issues
    if (message.content) {
        message.content = sanitizeMessageContent(message.content);
    }
    
    const chatMessages = document.getElementById('chatMessages');
    if (!chatMessages) return;
    
    // First check if this message already exists in the DOM by ID
    const existingMessage = document.querySelector(`[data-message-id="${message.id}"]`);
    if (existingMessage) {
        return; // Skip adding if it already exists
    }
    
    // For short messages (1-3 characters), also check if there's a recent identical message
    if (message.content && message.content.length <= 3 && message.sender_type === 'client') {
        // Look for existing messages with the same content in the last few messages
        const recentClientMessages = Array.from(
            chatMessages.querySelectorAll('.client-message')
        ).slice(-5); // Check the last 5 client messages
        
        const duplicateMessage = recentClientMessages.find(msg => {
            const messageText = msg.querySelector('.message-text')?.textContent.trim();
            return messageText === message.content.trim();
        });
        
        if (duplicateMessage) {
            console.log('Prevented duplicate short message:', message.content);
            return; // Skip adding if a recent message with the same content exists
        }
    }
    
    const messageDiv = document.createElement('div');
    
    // Add appropriate classes
    messageDiv.className = `message ${message.sender_type}-message`;
    if (message.id && String(message.id).startsWith('temp-')) {
        messageDiv.classList.add('temp-message');
    }
    
    // Set message ID for tracking
    if (message.id) {
        messageDiv.setAttribute('data-message-id', message.id);
    }
    
    // Set a timestamp for deduplication purposes
    messageDiv.setAttribute('data-timestamp', Date.now().toString());
    
    let senderNameHTML = '';
    if (message.sender_type === 'client') {
        senderNameHTML = `<div class="message-meta"><span class="sender-name">${message.sender_name}</span></div>`;
    } else {
        senderNameHTML = `<div class="message-meta"><small class="text-muted">${message.sender_name}</small></div>`;
    }
    
    messageDiv.innerHTML = `
        <div class="message-content">
            ${senderNameHTML}
            <div class="message-text">${message.content}</div>
        </div>
    `;
    
    chatMessages.appendChild(messageDiv);
    scrollChatToBottom();
    
    // Register this message for deduplication in the polling system
    if (window.registerMessageAsSent && message.content) {
        // For IDs that might not be reliable (like temp IDs), use a combination of sender type and content
        const idToUse = message.id && !String(message.id).startsWith('temp-') 
            ? message.id 
            : `${message.sender_type}-${Date.now()}`;
        window.registerMessageAsSent(idToUse, message.content);
    }
}

/**
 * Update chat status UI based on conversation status
 */
function updateChatStatus(status) {
    const chatInput = document.querySelector('.chat-input');
    const textarea = chatInput.querySelector('textarea');
    const sendButton = chatInput.querySelector('button');
    const statusContainers = document.querySelectorAll('.conversation-status');
    
    // Remove old status containers
    statusContainers.forEach(container => container.remove());
    
    // Handle the quick buttons visibility
    const quickButtonsContainer = document.querySelector('.quick-buttons');
    
    // Handle different statuses
    if (status === 'human_requested') {
        // Show waiting status
        const waitingStatus = document.createElement('div');
        waitingStatus.className = 'conversation-status status-human-requested';
        waitingStatus.innerHTML = `
            <div class="status-container">
                <i class="fas fa-spinner fa-spin"></i>
                <div><strong>Connecting to an agent...</strong> Our customer service team has been notified.</div>
            </div>
        `;
        chatInput.parentNode.insertBefore(waitingStatus, chatInput);
        
        // Disable chat input
        textarea.disabled = true;
        textarea.placeholder = "Waiting for an agent to connect...";
        sendButton.disabled = true;
        
        // Hide quick buttons during connection
        if (quickButtonsContainer) {
            quickButtonsContainer.style.display = 'none';
        }
        
        // Start polling for status changes
        startStatusPolling();
    } else if (status === 'human_assigned') {
        // Show connected status
        const connectedStatus = document.createElement('div');
        connectedStatus.className = 'conversation-status status-human-assigned';
        connectedStatus.innerHTML = `
            <div class="status-container">
                <i class="fas fa-user-check text-success"></i>
                <div><strong>Agent connected!</strong>Admin will assist you with your inquiry</div>
            </div>
        `;
        chatInput.parentNode.insertBefore(connectedStatus, chatInput);
        
        // Enable chat input
        textarea.disabled = false;
        textarea.placeholder = "Type your message here...";
        sendButton.disabled = false;
        
        // Hide assistance button if present
        const assistanceBtn = document.querySelector('.btn-assistance');
        if (assistanceBtn) {
            assistanceBtn.style.display = 'none';
        }
        
        // Keep quick buttons hidden during human conversation
        if (quickButtonsContainer) {
            quickButtonsContainer.style.display = 'none';
        }
    } else if (status === 'bot') {
        // In bot mode, show quick buttons
        if (quickButtonsContainer) {
            quickButtonsContainer.style.display = '';
        }
        
        // Show assistance button if it exists
        const assistanceBtn = document.querySelector('.btn-assistance');
        if (assistanceBtn) {
            assistanceBtn.style.display = '';
        }
    }
}

/**
 * Send a quick question via Ajax
 */
function sendQuickQuestion(question) {
    // Properly sanitize the question to handle any encoding issues
    question = sanitizeMessageContent(question);
    
    const clientTempId = 'temp-client-' + Date.now();
    
    addMessageToUI({
        id: clientTempId,
        content: question,
        sender_type: 'client',
        sender_name: 'You'
    });
    
    const formData = new FormData();
    formData.append('action', 'quick_question');
    formData.append('question', question);
    
    fetch('includes/ajax_handlers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            
            const tempClientMessage = document.querySelector(`[data-message-id="${clientTempId}"]`);
            if (tempClientMessage) {
                tempClientMessage.setAttribute('data-message-id', data.client_message.id);
                tempClientMessage.classList.remove('temp-message');
                
                if (window.registerMessageAsSent && data.client_message.id) {
                    window.registerMessageAsSent(data.client_message.id, question);
                }
            }
            
            // Add bot response to UI if it's not already there
            const existingBotMessage = document.querySelector(`[data-message-id="${data.bot_message.id}"]`);
            if (!existingBotMessage) {
                addMessageToUI(data.bot_message);
                
                // Register bot response to prevent duplicates from polling
                if (window.registerMessageAsSent && data.bot_message.id) {
                    window.registerMessageAsSent(data.bot_message.id, data.bot_message.content);
                }
            }
            
            // Update chat status if needed
            updateChatStatus(data.status);
        } else {
            console.error('Error sending quick question:', data.message);
        }
    })
    .catch(error => {
        console.error('Error sending quick question:', error);
    });
}

/**
 * Request human assistance with optional problem description
 */
function requestHumanAssistance(problemDescription = '') {
    // Check if the user's message already appears to avoid duplicates
    if (problemDescription) {
        const chatMessages = document.getElementById('chatMessages');
        const existingMessage = Array.from(chatMessages.querySelectorAll('.client-message')).find(msg => 
            msg.querySelector('.message-text') && 
            msg.querySelector('.message-text').textContent.trim() === problemDescription.trim()
        );
        
        // If we find a matching client message that was just added, don't add it again
        // Check if message was added in the last 3 seconds (to prevent blocking legit duplicate messages over time)
        const messageTimestamp = existingMessage?.getAttribute('data-timestamp');
        const isRecentMessage = messageTimestamp && (Date.now() - parseInt(messageTimestamp) < 3000);
        
        if (existingMessage && isRecentMessage) {
            problemDescription = ''; // Don't add the message again
        }
    }
    
    const formData = new FormData();
    formData.append('action', 'request_human');
    
    // Generate temporary ID for client message
    const clientTempId = problemDescription ? 'temp-client-' + Date.now() : null;
    
    if (problemDescription) {
        formData.append('problem', problemDescription);
        
        // Add client message to UI immediately
        const messageObj = {
            id: clientTempId,
            content: problemDescription,
            sender_type: 'client',
            sender_name: 'You'
        };
        addMessageToUI(messageObj);
        
        // Add timestamp to the message element for deduplication purposes
        const messageElement = document.querySelector(`[data-message-id="${clientTempId}"]`);
        if (messageElement) {
            messageElement.setAttribute('data-timestamp', Date.now().toString());
        }
    }
    
    fetch('includes/ajax_handlers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update temporary client message with real ID if it exists
            if (clientTempId && problemDescription) {
                const tempClientMessage = document.querySelector(`[data-message-id="${clientTempId}"]`);
                if (tempClientMessage) {
                    // We may not know the exact ID from the server, but we can register the content
                    // to prevent duplicates from polling
                    if (window.registerMessageAsSent) {
                        window.registerMessageAsSent('client-msg', problemDescription);
                    }
                    
                    // Remove the temp class
                    tempClientMessage.classList.remove('temp-message');
                }
            }
            
            // Check if this bot message already exists
            const existingBotMessage = document.querySelector(`[data-message-id="${data.message.id}"]`);
            if (!existingBotMessage) {
                // Add bot message to UI
                addMessageToUI(data.message);
                
                // Register bot message to prevent duplicates from polling
                if (window.registerMessageAsSent && data.message.id) {
                    window.registerMessageAsSent(data.message.id, data.message.content);
                }
            }
            
            // Update chat status
            updateChatStatus(data.status);
            
            // Reset any assistance mode UI
            const form = document.querySelector('.chat-input form');
            if (form) {
                form.removeAttribute('data-assistance-mode');
                
                // Remove assistance indicator if it exists
                const indicator = form.querySelector('.assistance-indicator');
                if (indicator) {
                    indicator.remove();
                }
                
                // Reset button styles
                const button = form.querySelector('button');
                if (button) {
                    button.classList.remove('btn-success');
                    button.classList.add('btn-primary');
                }
            }
        } else {
            console.error('Error requesting human assistance:', data.message);
        }
    })
    .catch(error => {
        console.error('Error requesting human assistance:', error);
    });
}

/**
 * Set up automatic refresh for waiting status
 */
function setupAutoRefresh() {
    // This will be handled by the polling mechanism
}

/**
 * Start polling for new messages and status changes
 */
function setupRealTimeChat() {
    // Keep track of the last message ID we've seen
    let lastMessageId = 0;
    // Track messages we've added via direct submission to avoid duplicates when polling returns them
    const recentlyAddedMessages = new Set();
    // Special tracker for very short messages (1-3 chars) to prevent duplicates
    const recentShortMessages = new Set();
    
    // Get initial last message ID
    const messages = document.querySelectorAll('.message');
    if (messages.length) {
        // Try to get IDs from data attributes if they exist
        const lastMessage = messages[messages.length - 1];
        if (lastMessage.hasAttribute('data-message-id')) {
            const msgId = lastMessage.getAttribute('data-message-id');
            // Only set lastMessageId if it's not a temporary ID
            if (!msgId.startsWith('temp-')) {
                lastMessageId = parseInt(msgId);
            }
        }
        
        // Track existing short messages to prevent duplicates
        messages.forEach(msg => {
            const messageText = msg.querySelector('.message-text')?.textContent.trim();
            if (messageText && messageText.length <= 3 && msg.classList.contains('client-message')) {
                recentShortMessages.add(messageText.toLowerCase());
                
                // Auto-expire short messages after 30 seconds to avoid over-blocking
                setTimeout(() => {
                    recentShortMessages.delete(messageText.toLowerCase());
                }, 30000);
            }
        });
    }
    
    // Poll for new messages every 3 seconds
    const pollInterval = setInterval(pollForNewMessages, 3000);
    
    // Expose a method to register a message as recently added to avoid duplicates
    window.registerMessageAsSent = function(messageId, content) {
        // Create a unique key using message ID and content
        const key = `${messageId}:${content}`;
        recentlyAddedMessages.add(key);
        
        // Also track short messages specially
        if (content && content.length <= 3) {
            recentShortMessages.add(content.toLowerCase());
            
            // Auto-expire short messages after 30 seconds to avoid over-blocking
            setTimeout(() => {
                recentShortMessages.delete(content.toLowerCase());
            }, 30000);
        }
        
        // Clean up the set after 10 seconds to avoid memory leaks
        setTimeout(() => {
            recentlyAddedMessages.delete(key);
        }, 10000);
    };
    
    // Function to poll for new messages
    function pollForNewMessages() {
        const formData = new FormData();
        formData.append('action', 'get_messages');
        formData.append('last_message_id', lastMessageId);
        
        fetch('includes/ajax_handlers.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Add new messages to UI
                if (data.messages && data.messages.length) {
                    data.messages.forEach(message => {
                        // Create a unique key for this message to check against recently added ones
                        const messageKey = `${message.id}:${message.content}`;
                        
                        // Check if this message already exists in the DOM
                        const existingMessage = document.querySelector(`[data-message-id="${message.id}"]`);
                        
                        // Check if this message was recently added manually (to avoid duplicates)
                        const wasRecentlyAdded = recentlyAddedMessages.has(messageKey);
                        
                        // Special check for short messages like 'd'
                        const isShortMessageDuplicate = message.content && 
                                            message.content.length <= 3 && 
                                            message.sender_type === 'client' && 
                                            recentShortMessages.has(message.content.toLowerCase());
                        
                        // Only add if it doesn't exist and wasn't recently added manually
                        if (!existingMessage && !wasRecentlyAdded && !isShortMessageDuplicate) {
                            addMessageToUI(message);
                        }
                        
                        // Update the last message ID regardless
                        lastMessageId = Math.max(lastMessageId, message.id);
                    });
                }
                
                // Check if conversation status has changed
                const currentStatusEl = document.querySelector('.status-human-assigned, .status-human-requested');
                const currentStatus = currentStatusEl ? 
                    (currentStatusEl.classList.contains('status-human-assigned') ? 'human_assigned' : 'human_requested') : 
                    'bot';
                
                if (currentStatus !== data.status) {
                    updateChatStatus(data.status);
                }
            }
        })
        .catch(error => {
            console.error('Error polling for new messages:', error);
        });
    }
}

/**
 * Set up quick reply buttons in admin chat
 */
function setupQuickReplyButtons() {
    const quickReplyButtons = document.querySelectorAll('.quick-reply-btn');
    const messageInput = document.getElementById('messageInput');
    
    if (quickReplyButtons.length && messageInput) {
        quickReplyButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                messageInput.value = this.getAttribute('data-reply');
                messageInput.focus();
            });
        });
    }
}

/**
 * Function to confirm human assistance request
 */
function confirmAssistance(event) {
    event.preventDefault();
    
    // Check if we're already in assistance mode to prevent duplicates
    const form = document.querySelector('.chat-input form');
    if (form && form.getAttribute('data-assistance-mode') === 'true') {
        return; // Already in assistance mode, don't add another message
    }
    
    // Show a message in the chat asking for assistance details
    const chatMessages = document.getElementById('chatMessages');
    
    // Check if the assistance message already exists to prevent duplicates
    const existingAssistanceMsg = Array.from(chatMessages.querySelectorAll('.bot-message')).find(msg => 
        msg.textContent.includes('Please describe what you need'));
    
    if (!existingAssistanceMsg) {
        const botMessage = document.createElement('div');
        botMessage.className = 'message bot-message';
        botMessage.setAttribute('data-assistance-request', 'true'); // Mark this as assistance request
        botMessage.innerHTML = `
            <div class="message-content">
                <div><small class="text-muted">Bus Rental Bot</small></div>
                <div class="status-container">
                    <i class="fas fa-info-circle text-primary"></i>
                    <div>Please describe what you need help with in the text box below and click send. This will connect you with a customer service representative.</div>
                </div>
            </div>
        `;
        chatMessages.appendChild(botMessage);
        scrollChatToBottom();
    }
    
    // Modify the form to submit to connect_to_admin instead
    if (form) {
        form.setAttribute('data-assistance-mode', 'true');
        
        // Change the UI to indicate assistance mode
        const chatInput = document.querySelector('.chat-input');
        chatInput.classList.add('assistance-mode');
        
        // Change the placeholder text and ensure it's not disabled
        const textarea = form.querySelector('textarea');
        textarea.placeholder = "Describe what you need help";
        textarea.disabled = false;
        textarea.focus();
        
        // Change the button color and ensure it's not disabled
        const button = form.querySelector('button');
        button.classList.remove('btn-primary');
        button.classList.add('btn-success');
    }
}

/**
 * Reset chat after deciding not to connect to human
 */
function resetChat() {
    const chatInput = document.querySelector('.chat-input');
    const textarea = chatInput.querySelector('textarea');
    const button = chatInput.querySelector('button');
    
    // Just re-enable the input
    textarea.disabled = false;
    textarea.placeholder = "Type your message here.";
    button.disabled = false;
    textarea.focus();
}

/**
 * Start polling for status changes when waiting for a human agent
 */
function startStatusPolling() {
    // Check if we already have an active polling interval
    if (window.statusPollingInterval) {
        clearInterval(window.statusPollingInterval);
    }
    
    // Create a new polling interval specifically for status changes
    window.statusPollingInterval = setInterval(() => {
        const formData = new FormData();
        formData.append('action', 'check_status');
        
        fetch('includes/ajax_handlers.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // If status has changed to human_assigned, update the UI
                if (data.status === 'human_assigned') {
                    updateChatStatus(data.status);
                    
                    // Stop polling once agent is assigned
                    clearInterval(window.statusPollingInterval);
                    window.statusPollingInterval = null;
                } else if (data.status === 'bot') {
                    // If status has changed back to bot (e.g., admin cancelled), update UI
                    updateChatStatus(data.status);
                    
                    // Stop polling
                    clearInterval(window.statusPollingInterval);
                    window.statusPollingInterval = null;
                }
            }
        })
        .catch(error => {
            console.error('Error checking status:', error);
        });
    }, 5000); // Check every 5 seconds
} 