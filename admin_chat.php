<?php
// Include required files
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/chatbot.php';

// Initialize session
initSession();

// Auto login as admin (bypassing login requirement)
if (!isLoggedIn() || !isAdmin()) {
    // Set admin session variables manually
    $_SESSION['user_id'] = 1; // Assuming admin user ID is 1
    $_SESSION['username'] = 'admin';
    $_SESSION['email'] = 'admin@example.com';
    $_SESSION['role'] = 'admin';
}

$currentUser = getCurrentUser();

// Check if conversation ID is provided
if (!isset($_GET['id'])) {
    header('Location: admin.php');
    exit;
}

$conversationId = $_GET['id'];

// Get conversation details
$conversation = getRow(
    "SELECT c.*, u.username as client_name, u.email 
    FROM conversations c
    JOIN users u ON c.client_id = u.id
    WHERE c.id = ?",
    [$conversationId],
    "i"
);

// If conversation doesn't exist, redirect to admin panel
if (!$conversation) {
    header('Location: admin.php');
    exit;
}

// Check if this admin is assigned to this conversation or if it's closed
if ($conversation['status'] === 'human_assigned' && $conversation['admin_id'] != $currentUser['id'] && $conversation['status'] !== 'closed') {
    header('Location: admin.php');
    exit;
}

// Handle assign admin to conversation
if (isset($_GET['assign']) && $conversation['status'] === 'human_requested') {
    // Assign admin to this conversation
    assignAdminToConversation($conversationId, $currentUser['id']);
    
    // Add system message about admin joining
    addMessage($conversationId, 'bot', "Customer service representative " . htmlspecialchars($currentUser['username']) . " has joined the conversation and will assist you shortly.");
    
    // Redirect to prevent resubmission
    header('Location: admin_chat.php?id=' . $conversationId);
    exit;
}

// Handle message submission
if (isset($_POST['send_message'])) {
    $message = $_POST['message'];
    
    // If this is the first response and the admin hasn't been assigned yet,
    // assign this admin to the conversation
    if ($conversation['status'] === 'human_requested' && $conversation['admin_id'] === null) {
        assignAdminToConversation($conversationId, $currentUser['id']);
        
        // Add system message about admin joining
        addMessage($conversationId, 'bot', "Customer service representative " . htmlspecialchars($currentUser['username']) . " has joined the conversation and will assist you shortly.");
    }
    
    // Add admin message to conversation
    addMessage($conversationId, 'admin', $message);
    
    // Redirect to prevent form resubmission
    header('Location: admin_chat.php?id=' . $conversationId);
    exit;
}

// Handle closing conversation
if (isset($_POST['close_conversation'])) {
    // Get closing message from form
    $closingMessage = isset($_POST['closing_message']) && !empty($_POST['closing_message']) 
        ? $_POST['closing_message'] 
        : "This conversation has been closed by the customer service agent. If you have additional questions, you can start a new conversation.";
    
    // Close the conversation
    closeConversation($conversationId);
    
    // Add system message with custom closing message
    addMessage($conversationId, 'bot', $closingMessage);
    
    // Redirect to admin panel
    header('Location: admin.php');
    exit;
}

// Get all messages for this conversation
$messages = getConversationMessages($conversationId);

// Function to format date for display
function formatDate($dateString) {
    $date = new DateTime($dateString);
    return $date->format('M j, Y g:i A');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat with <?php echo htmlspecialchars($conversation['client_name']); ?> - Bus USA Rental</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #e74c3c;
            --light-gray: #f5f5f5;
            --dark-gray: #333;
            --medium-gray: #6c757d;
            --border-radius: 12px;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        body {
            background-color: #f8f9fa;
            color: var(--dark-gray);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            height: 100vh;
            margin: 0;
            padding: 0;
        }
        
        .chat-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            height: 100vh;
            background-color: white;
            box-shadow: var(--shadow);
        }
        
        .chat-header {
            padding: 15px 20px;
            background-color: white;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .client-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .client-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--light-gray);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .client-name {
            font-weight: 600;
            font-size: 1.1rem;
            margin: 0;
        }
        
        .client-email {
            font-size: 0.85rem;
            color: var(--medium-gray);
            margin: 0;
        }
        
        .chat-header-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-refresh {
            background-color: transparent;
            border: none;
            color: var(--medium-gray);
            padding: 5px 8px;
            border-radius: 6px;
            transition: all 0.2s;
        }
        
        .btn-refresh:hover, .btn-refresh.active {
            background-color: var(--light-gray);
            color: var(--primary-color);
        }
        
        .btn-refresh.active i {
            animation: spin 0.5s linear;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .btn-close-conversation {
            padding: 8px 16px;
            font-size: 0.9rem;
            border-radius: 6px;
            background-color: var(--secondary-color);
            border: none;
            color: white;
            transition: all 0.2s;
        }
        
        .btn-close-conversation:hover {
            background-color: #c0392b;
        }
        
        .chat-body {
            flex: 1;
            overflow: hidden;
            display: flex;
            padding: 0;
        }
        
        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background-color: #f8f9fa;
            display: flex;
            flex-direction: column;
        }
        
        .message {
            margin-bottom: 8px;
            max-width: 80%;
            position: relative;
            width: 100%;
            box-sizing: border-box;
        }
        
        /* Add rules to fix excessive whitespace */
        .message + .message.admin-message,
        .message + .message.client-message,
        .message + .message.bot-message {
            margin-top: -8px;
        }
        
        /* Add a smaller margin between consecutive messages from the same sender */
        .admin-message + .admin-message,
        .client-message + .client-message,
        .bot-message + .bot-message {
            margin-top: -12px;
        }
        
        .admin-message {
            margin-left: auto;
            margin-right: 0;
            max-width: fit-content;
            width: auto;
            min-width: 40px;
            max-width: 60%;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            align-self: flex-end;
        }
        
        .admin-message .message-content {
            background-color: var(--primary-color);
            color: white;
            padding: 8px 12px;
            text-align: left;
        }
        
        .client-message {
            margin-right: auto;
            margin-left: 0;
            max-width: fit-content;
            width: auto;
            min-width: 40px;
            max-width: 65%;
            align-self: flex-start;
        }
        
        .bot-message {
            margin-right: auto;
            margin-left: 0;
            max-width: fit-content;
            width: auto;
            min-width: 40px;
            max-width: 65%;
            align-self: flex-start;
        }
        
        .client-message .message-content {
            background-color: #e9ecef;
            color: var(--dark-gray);
            padding: 8px 12px;
        }
        
        .bot-message .message-content {
            background-color: #dfe6e9;
            color: var(--dark-gray);
            border-left: 3px solid #0984e3;
            padding: 8px 12px;
        }
        
        .message-content {
            padding: 8px 12px;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            position: relative;
            display: inline-block;
            width: auto;
            max-width: 100%;
        }
        
        /* Only adjust short messages for admin */
        .admin-message .message-content:not(:has(> .message-text:empty)) {
            min-width: 40px;
            max-width: 100%;
        }
        
        /* Add a special class for very short admin messages */
        .admin-message.short-message {
            align-self: flex-end;
            max-width: max-content;
        }
        
        .admin-message.short-message .message-content {
            min-width: 40px;
            max-width: max-content;
            text-align: center;
        }
        
        .message-text {
            line-height: 1.3;
            word-break: break-word;
        }
        
        .message-meta {
            font-size: 0.75rem;
            margin-bottom: 5px;
            opacity: 0.8;
        }
        
        .admin-message .message-meta {
            color: rgba(255, 255, 255, 0.9);
        }
        
        .client-message .message-meta,
        .bot-message .message-meta {
            color: var(--medium-gray);
        }
        
        .chat-sidebar {
            width: 300px;
            background-color: white;
            border-left: 1px solid rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }
        
        .sidebar-section {
            padding: 20px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        .sidebar-section h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--dark-gray);
        }
        
        .client-details-list {
            list-style-type: none;
            padding: 0;
            margin: 0;
        }
        
        .client-details-list li {
            margin-bottom: 12px;
            font-size: 0.9rem;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--medium-gray);
            display: block;
            margin-bottom: 3px;
            font-size: 0.8rem;
        }
        
        .detail-value {
            word-break: break-word;
        }
        
        .quick-replies {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        
        .btn-quick-reply {
            background-color: #f1f3f5;
            border: 1px solid #dee2e6;
            border-radius: 20px;
            padding: 6px 15px;
            font-size: 0.85rem;
            color: var(--dark-gray);
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-quick-reply:hover {
            background-color: #e9ecef;
            border-color: #ced4da;
        }
        
        .chat-input {
            padding: 15px;
            background-color: white;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        .chat-input form {
            display: flex;
            gap: 10px;
        }
        
        .chat-input textarea {
            flex: 1;
            border: 1px solid #dee2e6;
            border-radius: var(--border-radius);
            padding: 12px 15px;
            resize: none;
            min-height: 50px;
            max-height: 150px;
            font-size: 0.95rem;
            transition: border-color 0.2s;
        }
        
        .chat-input textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }
        
        .chat-input button {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: var(--primary-color);
            border: none;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .chat-input button:hover {
            background-color: #2980b9;
        }
        
        .chat-input button i {
            font-size: 1.25rem;
        }
        
        .conversation-closed-message {
            background-color: #fee2e2;
            color: #b91c1c;
            padding: 10px 15px;
            border-radius: var(--border-radius);
            margin-bottom: 15px;
            text-align: center;
            font-size: 0.9rem;
        }
        
        /* Modal styles */
        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: var(--shadow);
        }
        
        .modal-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: 15px 20px;
        }
        
        .modal-title {
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--dark-gray);
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .closing-templates {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .closing-template {
            background-color: #f1f3f5;
            border: 1px solid #dee2e6;
            border-radius: 20px;
            padding: 6px 15px;
            font-size: 0.85rem;
            color: var(--dark-gray);
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .closing-template:hover {
            background-color: #e9ecef;
            border-color: #ced4da;
        }
        
        .modal-footer {
            border-top: 1px solid #dee2e6;
            padding: 15px 20px;
            background-color: #f8f9fa;
        }
        
        .btn-cancel {
            background-color: #f1f3f5;
            border: 1px solid #dee2e6;
            color: var(--dark-gray);
            border-radius: 6px;
            padding: 8px 16px;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        
        .btn-cancel:hover {
            background-color: #e9ecef;
            border-color: #ced4da;
        }
        
        .btn-confirm-close {
            background-color: var(--secondary-color);
            border: none;
            color: white;
            border-radius: 6px;
            padding: 8px 16px;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        
        .btn-confirm-close:hover {
            background-color: #c0392b;
        }
        
        @media (max-width: 991px) {
            .chat-sidebar {
                display: none;
            }
        }
        
        @media (max-width: 576px) {
            .chat-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .chat-header-actions {
                width: 100%;
                margin-top: 10px;
                justify-content: space-between;
            }
            
            .message {
                max-width: 90%;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="chat-container">
            <div class="chat-header">
                <div class="client-info">
                    <div class="client-avatar">
                        <?php echo htmlspecialchars(substr($conversation['client_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <h5 class="client-name"><?php echo htmlspecialchars($conversation['client_name']); ?></h5>
                        <p class="client-email"><?php echo htmlspecialchars($conversation['email']); ?></p>
                    </div>
                </div>
                <div class="chat-header-actions">
                    <button id="refreshButton" type="button" class="btn-refresh" title="Refresh Messages">
                        <i class="bi bi-arrow-clockwise"></i>
                        </button>
                        <?php if ($conversation['status'] !== 'closed'): ?>
                        <button type="button" class="btn-close-conversation" data-bs-toggle="modal" data-bs-target="#closeConversationModal">
                            <i class="bi bi-x-circle"></i> Close Chat
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            
            <div class="chat-body">
                <div class="chat-main">
                    <?php if ($conversation['status'] === 'closed'): ?>
                        <div class="conversation-closed-message">
                            <i class="bi bi-info-circle"></i> This conversation has been closed
            </div>
                    <?php endif; ?>
                    
                    <div id="chatMessages" class="chat-messages">
                        <?php foreach ($messages as $message): ?>
                            <div class="message <?php echo $message['type']; ?>-message">
                                <div class="message-meta">
                                    <?php echo htmlspecialchars($message['type'] === 'client' ? $conversation['client_name'] : ($message['type'] === 'admin' ? 'Support Agent' : 'System')); ?> • 
                                    <span class="message-time"><?php echo date('M j, Y g:i A', strtotime($message['timestamp'])); ?></span>
                    </div>
                                <div class="message-content">
                                    <?php echo nl2br(htmlspecialchars($message['message'])); ?>
                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($conversation['status'] !== 'closed'): ?>
                        <div class="chat-input">
                            <form id="replyForm">
                                <textarea id="messageInput" name="message" placeholder="Type your message..." rows="1" onkeydown="if(event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); sendMessage(); return false; }"></textarea>
                                <button type="submit">
                                    <i class="bi bi-send"></i>
                                </button>
                            </form>
            </div>
            <?php endif; ?>
                </div>
                
                <div class="chat-sidebar">
                    <div class="sidebar-section">
                        <h3>Client Details</h3>
                        <ul class="client-details-list">
                            <li>
                                <span class="detail-label">Name</span>
                                <span class="detail-value"><?php echo htmlspecialchars($conversation['client_name']); ?></span>
                            </li>
                            <li>
                                <span class="detail-label">Email</span>
                                <span class="detail-value"><?php echo htmlspecialchars($conversation['email']); ?></span>
                            </li>
                            <li>
                                <span class="detail-label">Started</span>
                                <span class="detail-value">
                <?php
                                    if (isset($conversation['created_at'])) {
                                        echo date('M j, Y g:i A', strtotime($conversation['created_at']));
                                    } elseif (isset($conversation['started_at'])) {
                                        echo date('M j, Y g:i A', strtotime($conversation['started_at']));
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </span>
                            </li>
                            <li>
                                <span class="detail-label">Status</span>
                                <span class="detail-value"><?php echo ucfirst(str_replace('_', ' ', $conversation['status'])); ?></span>
                            </li>
                            <?php if (!empty($conversation['browser'])): ?>
                            <li>
                                <span class="detail-label">Browser</span>
                                <span class="detail-value"><?php echo htmlspecialchars($conversation['browser']); ?></span>
                            </li>
                            <?php endif; ?>
                            <?php if (!empty($conversation['ip_address'])): ?>
                            <li>
                                <span class="detail-label">IP Address</span>
                                <span class="detail-value"><?php echo htmlspecialchars($conversation['ip_address']); ?></span>
                            </li>
                            <?php endif; ?>
                        </ul>
            </div>
            
                <?php if ($conversation['status'] !== 'closed'): ?>
                    <div class="sidebar-section">
                        <h3>Quick Replies</h3>
                        <div class="quick-replies">
                            <button class="btn-quick-reply" data-reply="Thanks for reaching out. How can I help you today?">Greeting</button>
                            <button class="btn-quick-reply" data-reply="I'll be happy to assist you with your bus rental inquiry.">Bus Rental</button>
                            <button class="btn-quick-reply" data-reply="Could you please provide more details about your trip?">More Details</button>
                            <button class="btn-quick-reply" data-reply="Our team will get back to you with a quote within 24 hours.">Quote Timeline</button>
                            <button class="btn-quick-reply" data-reply="Is there anything else I can help you with today?">Anything Else</button>
                            <button class="btn-quick-reply" data-reply="Thank you for contacting Bus USA Rental. Have a great day!">Thank You</button>
                        </div>
                    </div>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Close Conversation Modal -->
    <div class="modal fade" id="closeConversationModal" tabindex="-1" aria-labelledby="closeConversationModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="closeConversationModalLabel">Close Conversation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                    <div class="modal-body">
                        <p>Are you sure you want to close this conversation?</p>
                    
                    <h6>Select a closing message (optional):</h6>
                    <div class="closing-templates">
                        <button class="closing-template" data-message="Thank you for contacting us. Is there anything else you need help with?">Thank you</button>
                        <button class="closing-template" data-message="I'm glad we could resolve your issue. Please feel free to contact us again if you need further assistance.">Issue resolved</button>
                        <button class="closing-template" data-message="Thank you for your inquiry. We'll follow up with more information via email.">Follow-up email</button>
                        </div>
                    
                    <div class="form-group">
                        <label for="closeMessage">Closing message:</label>
                        <textarea class="form-control" id="closeMessage" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn-confirm-close" id="confirmCloseBtn">Close Conversation</button>
                    </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const chatMessages = document.getElementById('chatMessages');
            const messageInput = document.getElementById('messageInput');
            const replyForm = document.getElementById('replyForm');
            const refreshButton = document.getElementById('refreshButton');
            const quickReplyButtons = document.querySelectorAll('.btn-quick-reply');
            const closingTemplates = document.querySelectorAll('.closing-template');
            const closeMessageTextarea = document.getElementById('closeMessage');
            const confirmCloseBtn = document.getElementById('confirmCloseBtn');
            
            // Scroll to bottom of chat
            function scrollToBottom() {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
            
            // Load on initial page load
            scrollToBottom();
            
            // Auto-resize textarea as user types
            if (messageInput) {
                messageInput.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                });
            }
            
            // Function to send message
            window.sendMessage = function() {
                if (!messageInput.value.trim()) return;
                
                const formData = new FormData();
                formData.append('message', messageInput.value);
                formData.append('conversation_id', <?php echo $conversationId; ?>);
                formData.append('action', 'send_message');
                
                fetch('ajax_handler.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Clear input
                        messageInput.value = '';
                        messageInput.style.height = 'auto';
                        
                        // Refresh messages
                        refreshMessages();
                    } else {
                        alert('Error sending message: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
            };
            
            // Handle sending messages
            if (replyForm) {
                replyForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    sendMessage();
                });
            }
            
            // Handle refresh button
            if (refreshButton) {
                refreshButton.addEventListener('click', refreshMessages);
            }
            
            // Refresh messages function
            function refreshMessages() {
                const formData = new FormData();
                formData.append('conversation_id', <?php echo $conversationId; ?>);
                formData.append('action', 'get_messages');
                
                fetch('ajax_handler.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        chatMessages.innerHTML = '';
                            
                            data.messages.forEach(message => {
                            const messageDiv = document.createElement('div');
                            messageDiv.className = `message ${message.type}-message`;
                            
                            const metaDiv = document.createElement('div');
                            metaDiv.className = 'message-meta';
                            
                            const senderName = message.type === 'client' 
                                ? '<?php echo htmlspecialchars($conversation['client_name']); ?>' 
                                : (message.type === 'admin' ? 'Support Agent' : 'System');
                            
                            metaDiv.innerHTML = `${senderName} • <span class="message-time">${formatDate(message.timestamp)}</span>`;
                            
                            const contentDiv = document.createElement('div');
                            contentDiv.className = 'message-content';
                            contentDiv.innerHTML = message.message.replace(/\n/g, '<br>');
                            
                            messageDiv.appendChild(metaDiv);
                            messageDiv.appendChild(contentDiv);
                            chatMessages.appendChild(messageDiv);
                        });
                        
                        scrollToBottom();
                    } else {
                        alert('Error refreshing messages: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
            }
            
            // Format date for display
            function formatDate(timestamp) {
                const date = new Date(timestamp);
                return date.toLocaleString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true
                });
            }
            
            // Quick reply buttons
            if (quickReplyButtons.length > 0) {
                quickReplyButtons.forEach(button => {
                    button.addEventListener('click', function() {
                        messageInput.value = this.getAttribute('data-reply');
                        messageInput.focus();
                        messageInput.style.height = 'auto';
                        messageInput.style.height = (messageInput.scrollHeight) + 'px';
                    });
                });
            }
            
            // Closing templates
            if (closingTemplates.length > 0) {
                closingTemplates.forEach(button => {
                    button.addEventListener('click', function() {
                        closeMessageTextarea.value = this.getAttribute('data-message');
                    });
                });
            }
            
            // Handle closing conversation
            if (confirmCloseBtn) {
                confirmCloseBtn.addEventListener('click', function() {
            const formData = new FormData();
            formData.append('conversation_id', <?php echo $conversationId; ?>);
                    formData.append('closing_message', closeMessageTextarea.value);
                    formData.append('action', 'close_conversation');
            
                    fetch('ajax_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                            location.reload();
                } else {
                            alert('Error closing conversation: ' + data.message);
                }
            })
            .catch(error => {
                        console.error('Error:', error);
                    });
                });
            }
            
            // Poll for new messages every 10 seconds if conversation is active
            <?php if ($conversation['status'] !== 'closed'): ?>
            setInterval(refreshMessages, 10000);
            <?php endif; ?>
        });
    </script>
</body>
</html> 