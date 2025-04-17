<?php
// Include required files
require_once 'config/database.php';
require_once 'includes/chatbot.php';

// Initialize session if needed for complex questions
session_start();

// Create a default conversation
$conversationId = null;

// Check if there's a session conversation ID
if (!isset($_SESSION['conversation_id'])) {
    // Create a new conversation with a system user ID of 1
    try {
        // First check if the users table has at least one user
        $userExists = getRow("SELECT id FROM users LIMIT 1");
        
        if (!$userExists) {
            // Insert a default user if none exists
            $defaultUserId = insertData(
                "INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)",
                ['guest', password_hash('guest', PASSWORD_DEFAULT), 'guest@example.com', 'client'],
                "ssss"
            );
            $clientId = $defaultUserId;
        } else {
            // Use the first user as the client
            $clientId = $userExists['id']; 
        }
        
        // Create conversation
        $conversationId = insertData(
            "INSERT INTO conversations (client_id, status) VALUES (?, 'bot')",
            [$clientId],
            "i"
        );
        
        // Add welcome message
        addMessage($conversationId, 'bot', 'Welcome to our Bus Rental service! How can I assist you today? You can use the quick question buttons above or type your own question.');
        
        // Store conversation ID in session
        $_SESSION['conversation_id'] = $conversationId;
    } catch (Exception $e) {
        // If there's an error, show a friendly message
        $error = "Sorry, there was an issue setting up the chat. Please make sure your database is set up correctly.";
        echo $error;
        exit;
    }
} else {
    // Use existing conversation
    $conversationId = $_SESSION['conversation_id'];
}

// Get current conversation status
$conversationStatus = getConversationStatus($conversationId);

// Handle predefined button click
if (isset($_GET['ask_question'])) {
    $predefinedQuestion = htmlspecialchars_decode(urldecode($_GET['ask_question']));
    
    // Add client message to conversation
    addMessage($conversationId, 'client', $predefinedQuestion);
    
    // Get the latest conversation status again (in case it changed)
    $conversationStatus = getConversationStatus($conversationId);
    
    // Only process with bot if not already talking to a human
    if ($conversationStatus !== 'human_assigned') {
        // Process with bot, passing the conversation ID
        $botResponse = processBotMessage($predefinedQuestion, $conversationId);
        
        if ($botResponse !== null) {
            // Bot can handle the question
            addMessage($conversationId, 'bot', $botResponse);
        } else {
            // Bot cannot handle the question, provide option to connect with a human agent
            $complexMessage = "I'm sorry, but I don't have enough information to answer your question properly. Would you like to talk to a customer service representative who can help you better?";
            
            // Add the complex message with buttons - use pure HTML, not escaped HTML
            $buttonsHtml = '<div class="mt-2 button-container"><button onclick="requestHumanAssistance()" class="btn btn-sm btn-primary">Yes, connect me with an agent</button> <button onclick="resetChat()" class="btn btn-sm btn-outline-secondary">No, I\'ll ask something else</button></div>';
            
            addMessage($conversationId, 'bot', $complexMessage . ' ' . $buttonsHtml);
        }
    }
    
    // Redirect to prevent resubmission
    header('Location: index.php');
    exit;
}

// Handle connect to admin request
if (isset($_GET['connect_to_admin'])) {
    // Get the problem description if provided
    $problemDescription = isset($_GET['problem']) ? $_GET['problem'] : '';
    
    // Add the user's question/problem as a client message
    if (!empty($problemDescription)) {
        addMessage($conversationId, 'client', $problemDescription);
    }
    
    // Update conversation status to human_requested for admin attention
    requestHumanAssistance($conversationId);
    
    // Add a message explaining that an admin will be connected
    $adminMessage = "Thank you for your patience. I'm connecting you with one of our customer service representatives who will be able to help you better. Please wait a moment while I transfer your conversation to an available agent. They'll join the chat as soon as possible.";
    addMessage($conversationId, 'bot', $adminMessage);
    
    // Redirect to prevent resubmission
    header('Location: index.php');
    exit;
}

// Handle chat message submission
if (isset($_POST['send_message'])) {
    $message = $_POST['message'];
    
    // Add client message to conversation
    addMessage($conversationId, 'client', $message);
    
    // Get the latest conversation status again (in case it changed)
    $conversationStatus = getConversationStatus($conversationId);
    
    // Only process with bot if not talking to a human
    if ($conversationStatus !== 'human_assigned') {
        // Process with bot, passing the conversation ID
        $botResponse = processBotMessage($message, $conversationId);
        
        if ($botResponse !== null) {
            // Bot can handle the question
            addMessage($conversationId, 'bot', $botResponse);
        } else {
            // Bot cannot handle the question, provide option to connect with a human agent
            $complexMessage = "I'm sorry, but I don't have enough information to answer your question properly. Would you like to talk to a customer service representative who can help you better?";
            
            // Add the complex message with buttons - use pure HTML, not escaped HTML
            $buttonsHtml = '<div class="mt-2 button-container"><button onclick="requestHumanAssistance()" class="btn btn-sm btn-primary">Yes, connect me with an agent</button> <button onclick="resetChat()" class="btn btn-sm btn-outline-secondary">No, I\'ll ask something else</button></div>';
            
            addMessage($conversationId, 'bot', $complexMessage . ' ' . $buttonsHtml);
        }
    }
    // If human assigned, don't add a bot response - just let the admin respond
    
    // Redirect to prevent form resubmission
    header('Location: index.php');
    exit;
}

// Get all messages for this conversation
$messages = getConversationMessages($conversationId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bus Rental Chat Service</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        /* Floating chat widget styles */
        .chat-widget-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            transition: all 0.3s ease;
        }
        
        .chat-bubble {
            background-color: #4e54c8;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            color: white;
            cursor: pointer;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .chat-bubble:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.3);
        }
        
        .chat-bubble i {
            font-size: 24px;
        }
        
        .chat-panel {
            position: fixed;
            bottom: 90px;
            right: 20px;
            width: 350px;
            height: 500px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            display: none;
            flex-direction: column;
            overflow: hidden;
            z-index: 999;
            transition: all 0.3s ease;
        }
        
        .chat-panel.active {
            display: flex;
        }
        
        .chat-close {
            position: relative;
            cursor: pointer;
            color: rgba(255, 255, 255, 0.7);
            transition: color 0.3s ease;
            z-index: 2;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .chat-close:hover {
            color: rgba(255, 255, 255, 1);
        }
        
        .chat-close i {
            font-size: 16px;
        }
        
        .chat-header {
            background: #4e54c8;
            color: white;
            padding: 15px;
            font-weight: bold;
            border-radius: 10px 10px 0 0;
            position: relative;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .chat-header h4 {
            font-size: 16px;
            margin-bottom: 0;
            padding-right: 5px;
            flex-grow: 1;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            margin-left: 10px;
        }
        
        .btn-assistance {
            font-size: 11px;
            padding: 5px 10px;
            background-color: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            margin-right: 12px;
            white-space: nowrap;
            z-index: 2;
            border-radius: 4px;
        }
        
        .btn-assistance:hover {
            background-color: rgba(255, 255, 255, 0.3);
            color: white;
            text-decoration: none;
        }
        
        /* Adjust existing styles for the widget */
        .chat-widget-container .chat-container {
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow: hidden;
            border-radius: 10px;
        }
        
        .chat-widget-container .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
            background-color: #f8f9fa;
        }
        
        .chat-widget-container .message {
            margin-bottom: 15px;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .chat-widget-container .quick-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            padding: 10px;
            background-color: #f0f2f5;
            border-bottom: 1px solid #dee2e6;
        }
        
        .chat-widget-container .quick-btn {
            padding: 6px 12px;
            font-size: 12px;
            margin-bottom: 5px;
            white-space: nowrap;
        }
        
        /* Bot message buttons styling */
        .message-text .btn {
            margin-top: 8px;
            margin-right: 5px;
            margin-bottom: 5px;
            white-space: normal;
            text-align: center;
            display: inline-block;
        }
        
        /* Ensure bot response buttons stack properly */
        .message-text .mt-2 {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 10px !important;
        }
        
        /* Style for agent connection buttons */
        .message-text .btn-primary,
        .message-text .btn-outline-secondary {
            margin-bottom: 5px;
            width: auto;
            min-width: 120px;
        }
        
        .unread-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ff5252;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 12px;
            font-weight: bold;
            display: none;
        }
        
        /* Style for bot message response buttons */
        .message-text .btn-primary,
        .message-text .btn-outline-secondary {
            display: inline-block;
            margin: 5px 5px 5px 0;
            width: auto;
        }
        
        .message-text .mt-2.button-container {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 12px !important;
            max-width: 200px;
        }
        
        @media (max-width: 400px) {
            .chat-panel {
                width: 300px;
            }
            
            .btn-assistance {
                font-size: 10px;
                padding: 3px 6px;
            }
            
            .btn-text {
                display: none;
            }
            
            .chat-header h4 {
                font-size: 14px;
            }
        }
        
        /* Improve the look of status messages */
        .conversation-status {
            padding: 5px 10px;
        }
        
        /* Status container styling */
        .status-container {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 10px 15px;
            margin: 8px 0;
            display: flex;
            align-items: center;
            font-size: 14px;
            color: #495057;
        }
        
        .status-container i {
            margin-right: 10px;
            font-size: 16px;
        }
        
        /* Chat input styling with auto-resize textarea */
        .chat-input {
            padding: 10px;
            background-color: #fff;
            border-top: 1px solid #e9ecef;
        }
        
        .chat-input textarea {
            min-height: 38px;
            max-height: 120px;
            resize: none;
            overflow-y: auto;
            transition: height 0.2s ease;
            padding-right: 30px;
            appearance: none;
            -webkit-appearance: none;
        }
        
        /* Remove dropdown arrows in specific browsers */
        .chat-input textarea::-webkit-calendar-picker-indicator,
        .chat-input textarea::-webkit-inner-spin-button,
        .chat-input textarea::-webkit-clear-button {
            display: none !important;
            -webkit-appearance: none;
        }
        
        /* Specifically target the no-arrow class */
        textarea.no-arrow {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: none !important;
        }
        
        /* Hide any potential browser UI elements */
        textarea.no-arrow::-ms-expand {
            display: none;
        }
        
        textarea.no-arrow::-ms-clear,
        textarea.no-arrow::-ms-reveal {
            display: none;
        }
        
        /* Force override any combobox styling */
        .chat-input textarea,
        .chat-input textarea.form-control {
            background-image: none !important;
            background-position: unset !important;
            background-repeat: unset !important;
            background-size: unset !important;
            padding-right: 12px;
        }
    </style>
</head>
<body>
    <div class="container my-5">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <h2>Welcome to Our Bus Rental Service</h2>
                        <p class="lead">We offer a wide range of buses for all your transportation needs.</p>
                        <p>Click the chat bubble in the bottom right corner for assistance or to make a booking.</p>
                        
                        <div class="row mt-4">
                            <div class="col-md-4">
                                <div class="card mb-4">
                                    <div class="card-body text-center">
                                        <i class="fas fa-bus fa-3x mb-3 text-primary"></i>
                                        <h5>Wide Selection</h5>
                                        <p>Choose from standard coaches, mini buses, luxury coaches, and school buses.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card mb-4">
                                    <div class="card-body text-center">
                                        <i class="fas fa-calendar-check fa-3x mb-3 text-primary"></i>
                                        <h5>Easy Booking</h5>
                                        <p>Our simple booking process makes it easy to reserve your transportation.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card mb-4">
                                    <div class="card-body text-center">
                                        <i class="fas fa-headset fa-3x mb-3 text-primary"></i>
                                        <h5>24/7 Support</h5>
                                        <p>Our customer support team is always ready to assist you.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Chat Widget -->
    <div class="chat-widget-container">
        <!-- Chat Bubble -->
        <div class="chat-bubble" id="chatBubble">
            <i class="fas fa-comment-dots"></i>
            <div class="unread-badge">1</div>
        </div>
        
        <!-- Chat Panel -->
        <div class="chat-panel" id="chatPanel">
            <div class="chat-container">
                <div class="chat-header">
                    <h4 class="mb-0">
                        <i class="fas fa-comment-dots me-2"></i>
                        <?php if ($conversationStatus === 'human_assigned'): ?>
                        Bus Rental Customer Service
                        <?php else: ?>
                        Bus Rental Assistant
                        <?php endif; ?>
                    </h4>
                    
                    <div class="header-actions">
                        <?php if ($conversationStatus !== 'human_assigned' && $conversationStatus !== 'human_requested'): ?>
                        <a href="#" onclick="confirmAssistance(event)" class="btn btn-sm btn-assistance" title="Request human assistance">
                            <i class="fas fa-headset me-1"></i>
                            <span class="btn-text">Human</span>
                        </a>
                        <?php endif; ?>
                        
                        <div class="chat-close" id="chatClose">
                            <i class="fas fa-times"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Quick question buttons -->
                <?php if ($conversationStatus !== 'human_assigned' && $conversationStatus !== 'human_requested'): ?>
                <div class="quick-buttons">
                    <a href="index.php?ask_question=<?php echo urlencode('How do I book a bus?'); ?>" class="btn btn-sm btn-outline-primary quick-btn">
                        <i class="fas fa-calendar-check me-1"></i> <span class="btn-label">How to Book</span>
                    </a>
                    <a href="index.php?ask_question=<?php echo urlencode('What are your pricing options?'); ?>" class="btn btn-sm btn-outline-primary quick-btn">
                        <i class="fas fa-tag me-1"></i> <span class="btn-label">Pricing</span>
                    </a>
                    <a href="index.php?ask_question=<?php echo urlencode('What types of buses do you offer?'); ?>" class="btn btn-sm btn-outline-primary quick-btn">
                        <i class="fas fa-bus me-1"></i> <span class="btn-label">Bus Types</span>
                    </a>
                    <a href="index.php?ask_question=<?php echo urlencode('How do I cancel my reservation?'); ?>" class="btn btn-sm btn-outline-primary quick-btn">
                        <i class="fas fa-ban me-1"></i> <span class="btn-label">Cancellation</span>
                    </a>
                    <a href="index.php?ask_question=<?php echo urlencode('How can I contact customer service?'); ?>" class="btn btn-sm btn-outline-primary quick-btn">
                        <i class="fas fa-phone me-1"></i> <span class="btn-label">Contact Us</span>
                    </a>
                </div>
                <?php endif; ?>
                
                <!-- Display conversation status if needed -->
                <?php if ($conversationStatus === 'human_requested'): ?>
                <div class="conversation-status status-human-requested">
                    <div class="status-container">
                        <i class="fas fa-spinner fa-spin"></i>
                        <div>
                            <strong>Request sent!</strong> Waiting for a human agent to join the conversation...
                        </div>
                    </div>
                </div>
                <?php elseif ($conversationStatus === 'human_assigned'): ?>
                <div class="conversation-status status-human-assigned">
                    <div class="status-container">
                        <i class="fas fa-user-check text-success"></i>
                        <div><strong>Agent connected!</strong> You are now chatting with Admin.
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="chat-messages" id="chatMessages">
                    <?php
                    foreach ($messages as $message) {
                        // Handle bot messages differently when admin is assigned
                        if ($conversationStatus === 'human_assigned' && $message['type'] === 'bot') {
                            // Only show the "customer service agent has joined" message, hide all other bot messages
                            $joinMessage = false;
                            $joinPhrases = ['service agent has joined', 'customer service representative', 'has joined the conversation'];
                            
                            foreach ($joinPhrases as $phrase) {
                                if (strpos($message['message'], $phrase) !== false) {
                                    $joinMessage = true;
                                    break;
                                }
                            }
                            
                            // Skip all bot messages except join notifications when admin is assigned
                            if (!$joinMessage) {
                                continue;
                            }
                        }
                        
                        $messageClass = $message['type'] . '-message';
                        $senderName = $message['type'] === 'bot' ? 'Bus Rental Bot' : 'You';
                        if ($message['type'] === 'admin') {
                            $senderName = 'Customer Service';
                        }
                        
                        // Add proper HTML structure and classes with message ID for real-time tracking
                        echo '<div class="message ' . $messageClass . '" data-message-id="' . $message['id'] . '">';
                        echo '<div class="message-content">';
                        
                        // Direct styling for client messages to completely avoid Bootstrap's text-muted
                        if ($message['type'] === 'client') {
                            echo '<div class="message-meta"><span class="sender-name">' . htmlspecialchars($senderName) . '</span></div>';
                        } else {
                            echo '<div class="message-meta"><small class="text-muted">' . htmlspecialchars($senderName) . '</small></div>';
                        }
                        
                        // Process message content but allow HTML in messages for buttons
                        // For bot messages where we need to allow HTML for buttons
                        if ($message['type'] === 'bot') {
                            // Allow HTML for bot messages but remove any literal tag displays
                            // Fix the common issue where HTML tags are displayed as text
                            $cleanContent = html_entity_decode($message['message'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            
                            // Replace malformed div class attributes if needed
                            $cleanContent = str_replace('&lt;div class=&quot;mt-2 button-container&quot;&gt;', '<div class="mt-2 button-container">', $cleanContent);
                            $cleanContent = str_replace('&lt;/div&gt;', '</div>', $cleanContent);
                            
                            // Replace malformed button tags if needed
                            $cleanContent = str_replace('&lt;button', '<button', $cleanContent);
                            $cleanContent = str_replace('&lt;/button&gt;', '</button>', $cleanContent);
                            
                            $messageContent = $cleanContent;
                        } else {
                            // For client and admin messages, escape HTML
                            $messageContent = nl2br(htmlspecialchars($message['message']));
                        }
                        
                        // Replace empty messages with a space to ensure they're visible
                        if (trim(strip_tags($messageContent)) === '') {
                            $messageContent = '&nbsp;';
                        }
                        
                        // Wrap the message text in a div with proper styling
                        echo '<div class="message-text">' . $messageContent . '</div>';
                        echo '</div></div>';
                    }
                    ?>
                </div>
                <div class="chat-input">
                    <form method="post" action="" id="chatForm" onsubmit="return validateForm()">
                        <div class="d-flex">
                            <textarea name="message" id="chatTextarea" class="form-control me-2 no-arrow" rows="1" placeholder="Type your message." required onkeydown="if(event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); if(this.value.trim() !== '') document.getElementById('chatForm').submit(); return false; }" <?php echo ($conversationStatus === 'human_requested' && !isset($_GET['connect_to_admin'])) ? 'disabled placeholder="Waiting for an agent to connect..."' : ''; ?>></textarea>
                            <button type="submit" name="send_message" class="btn btn-primary" <?php echo ($conversationStatus === 'human_requested' && !isset($_GET['connect_to_admin'])) ? 'disabled' : ''; ?>>
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add confirmation modal for human assistance -->
    <div class="modal fade" id="assistanceModal" tabindex="-1" aria-labelledby="assistanceModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="assistanceModalLabel">Connect with a Human Agent</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Would you like to connect with a human customer service agent? They can help with complex questions or provide personalized assistance.</p>
                    <form id="problemForm">
                        <div class="mb-3">
                            <label for="problemDescription" class="form-label">Briefly describe your question (optional):</label>
                            <textarea class="form-control" id="problemDescription" rows="3" placeholder="What would you like help with?"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="connectToAdmin()">Connect with Agent</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
    <script src="assets/js/chat.js"></script>
    <script>
        // Inline validation function to ensure it's loaded
        function validateForm() {
            const textarea = document.getElementById('chatTextarea');
            return textarea && textarea.value.trim() !== '';
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            setupChatWidget();
        });
    </script>
</body>
</html> 