// Chat functionality
document.addEventListener('DOMContentLoaded', function() {
    // Get the chat form and textarea
    const chatForm = document.getElementById('chatForm');
    const chatTextarea = document.getElementById('chatTextarea');
    
    if (chatForm && chatTextarea) {
        // Handle Enter key press
        chatTextarea.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (this.value.trim() !== '') {
                    chatForm.submit();
                }
                return false;
            }
        });
        
        // Auto-resize textarea
        chatTextarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (Math.min(this.scrollHeight, 120)) + 'px';
        });
    }
    
    // Scroll chat to bottom
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
});

// Validate form before submission
function validateForm() {
    const textarea = document.getElementById('chatTextarea');
    if (textarea && textarea.value.trim() === '') {
        return false; // Prevent submission if empty
    }
    return true;
}

// Setup chat widget
function setupChatWidget() {
    const chatBubble = document.getElementById('chatBubble');
    const chatPanel = document.getElementById('chatPanel');
    const chatClose = document.getElementById('chatClose');
    const unreadBadge = document.querySelector('.unread-badge');
    
    // Show chat panel when bubble is clicked
    if (chatBubble) {
        chatBubble.addEventListener('click', function() {
            chatPanel.classList.add('active');
            chatBubble.style.display = 'none';
            
            // Hide unread badge when opening chat
            if (unreadBadge) {
                unreadBadge.style.display = 'none';
            }
            
            // Scroll chat to bottom
            const chatMessages = document.getElementById('chatMessages');
            if (chatMessages) {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
        });
    }
    
    // Hide chat panel when close is clicked
    if (chatClose) {
        chatClose.addEventListener('click', function() {
            chatPanel.classList.remove('active');
            chatBubble.style.display = 'flex';
        });
    }
}

// Function to scroll chat to bottom
function scrollChatToBottom() {
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
}

// Request human assistance
function requestHumanAssistance(problemDescription = '') {
    const params = new URLSearchParams();
    params.append('connect_to_admin', 'true');
    
    if (problemDescription) {
        params.append('problem', problemDescription);
    }
    
    window.location.href = 'index.php?' + params.toString();
}

// Function to show modal for assistance
function confirmAssistance(event) {
    event.preventDefault();
    const assistanceModal = new bootstrap.Modal(document.getElementById('assistanceModal'));
    assistanceModal.show();
}

// Function to reset chat
function resetChat() {
    window.location.href = 'index.php?reset_chat=true';
}

// Connect to admin with problem description via the modal
function connectToAdmin() {
    const problemDescription = document.getElementById('problemDescription').value;
    
    // Disable the modal button to prevent double-clicks
    const connectButton = document.querySelector('.modal-footer .btn-primary');
    if (connectButton) {
        connectButton.disabled = true;
        connectButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Connecting...';
    }
    
    // Call the requestHumanAssistance function 
    requestHumanAssistance(problemDescription);
    
    // Hide the modal
    const assistanceModal = bootstrap.Modal.getInstance(document.getElementById('assistanceModal'));
    assistanceModal.hide();
    
    // Re-enable the button after a short delay
    setTimeout(() => {
        if (connectButton) {
            connectButton.disabled = false;
            connectButton.innerHTML = 'Connect with Agent';
        }
        // Also clear the textarea
        document.getElementById('problemDescription').value = '';
    }, 1000);
}

// Show welcome message with badge
document.addEventListener('DOMContentLoaded', function() {
    const chatPanel = document.getElementById('chatPanel');
    const unreadBadge = document.querySelector('.unread-badge');
    
    // Show welcome message after a short delay
    setTimeout(function() {
        if (chatPanel && !chatPanel.classList.contains('active')) {
            // Only show if the chat panel isn't already open
            if (unreadBadge) {
                unreadBadge.style.display = 'flex';
            }
        }
    }, 3000);
}); 