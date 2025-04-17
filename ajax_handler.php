<?php
/**
 * Ajax handler for chat functionality
 * This file handles AJAX requests for both admin and client chat interfaces
 */

// Include required files
require_once 'includes/chatbot.php';
require_once 'config/database.php';
require_once 'includes/auth.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Make sure the request is an Ajax request
header('Content-Type: application/json');

// Function to return JSON response
function sendJsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Check if action is specified
if (!isset($_POST['action'])) {
    sendJsonResponse(['success' => false, 'message' => 'No action specified'], 400);
}

$action = $_POST['action'];

// Get current user if logged in
$currentUser = isset($_SESSION['user_id']) ? getCurrentUser() : null;

// Handle different actions
switch ($action) {
    case 'send_message':
        // Check for required parameters
        if (!isset($_POST['message']) || !isset($_POST['conversation_id'])) {
            sendJsonResponse(['success' => false, 'message' => 'Missing parameters'], 400);
        }
        
        $message = $_POST['message'];
        $conversationId = $_POST['conversation_id'];
        
        // Get conversation details
        $conversation = getRow(
            "SELECT * FROM conversations WHERE id = ?",
            [$conversationId],
            "i"
        );
        
        if (!$conversation) {
            sendJsonResponse(['success' => false, 'message' => 'Conversation not found'], 404);
        }
        
        // Check if user is authorized for this conversation
        $isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
        
        if ($isAdmin) {
            // If this is the first response and the admin hasn't been assigned yet,
            // assign this admin to the conversation
            if ($conversation['status'] === 'human_requested' && $conversation['admin_id'] === null) {
                assignAdminToConversation($conversationId, $currentUser['id']);
                
                // Add system message about admin joining
                $joinMessage = "Customer service representative " . htmlspecialchars($currentUser['username']) . " has joined the conversation and will assist you shortly.";
                addMessage($conversationId, 'bot', $joinMessage);
            }
            
            // Add admin message to conversation
            $messageId = addMessage($conversationId, 'admin', $message);
        } else {
            // Client message
            // Add client message to conversation
            $messageId = addMessage($conversationId, 'client', $message);
        }
        
        sendJsonResponse([
            'success' => true,
            'message_id' => $messageId
        ]);
        break;
        
    case 'get_messages':
        // Check for required parameters
        if (!isset($_POST['conversation_id'])) {
            sendJsonResponse(['success' => false, 'message' => 'Missing conversation ID'], 400);
        }
        
        $conversationId = $_POST['conversation_id'];
        $lastMessageId = isset($_POST['last_message_id']) ? (int)$_POST['last_message_id'] : 0;
        
        // Get conversation details
        $conversation = getRow(
            "SELECT c.*, u.username as client_name, u.email 
            FROM conversations c
            JOIN users u ON c.client_id = u.id
            WHERE c.id = ?",
            [$conversationId],
            "i"
        );
        
        if (!$conversation) {
            sendJsonResponse(['success' => false, 'message' => 'Conversation not found'], 404);
        }
        
        // Get all messages or new messages since last_message_id
        if ($lastMessageId > 0) {
            $messages = getRows(
                "SELECT * FROM messages WHERE conversation_id = ? AND id > ? ORDER BY sent_at ASC",
                [$conversationId, $lastMessageId],
                "ii"
            );
        } else {
            $messages = getRows(
                "SELECT * FROM messages WHERE conversation_id = ? ORDER BY sent_at ASC",
                [$conversationId],
                "i"
            );
        }
        
        // Format messages for the frontend
        $formattedMessages = [];
        foreach ($messages as $message) {
            $formattedMessages[] = [
                'id' => $message['id'],
                'type' => $message['sender_type'],
                'message' => $message['message'],
                'timestamp' => $message['sent_at']
            ];
        }
        
        sendJsonResponse([
            'success' => true,
            'messages' => $formattedMessages,
            'status' => $conversation['status']
        ]);
        break;
        
    case 'close_conversation':
        // Check for required parameters
        if (!isset($_POST['conversation_id'])) {
            sendJsonResponse(['success' => false, 'message' => 'Missing conversation ID'], 400);
        }
        
        $conversationId = $_POST['conversation_id'];
        $closingMessage = isset($_POST['closing_message']) && !empty($_POST['closing_message']) 
            ? $_POST['closing_message'] 
            : "This conversation has been closed by the customer service agent. If you have additional questions, you can start a new conversation.";
        
        // Get conversation details
        $conversation = getRow(
            "SELECT * FROM conversations WHERE id = ?",
            [$conversationId],
            "i"
        );
        
        if (!$conversation) {
            sendJsonResponse(['success' => false, 'message' => 'Conversation not found'], 404);
        }
        
        // Check if user is authorized to close this conversation
        $isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
        
        if (!$isAdmin) {
            sendJsonResponse(['success' => false, 'message' => 'Not authorized to close this conversation'], 403);
        }
        
        // Close the conversation
        closeConversation($conversationId);
        
        // Add system message with custom closing message
        $messageId = addMessage($conversationId, 'bot', $closingMessage);
        
        sendJsonResponse([
            'success' => true,
            'status' => 'closed',
            'message' => 'Conversation closed successfully'
        ]);
        break;
        
    case 'request_human':
        // Check for required parameters
        if (!isset($_POST['conversation_id'])) {
            sendJsonResponse(['success' => false, 'message' => 'Missing conversation ID'], 400);
        }
        
        $conversationId = $_POST['conversation_id'];
        
        // Update conversation status to request human assistance
        requestHumanAssistance($conversationId);
        
        // Add a system message
        $message = "Thank you for your patience. We're connecting you with one of our customer service representatives who will assist you shortly.";
        $messageId = addMessage($conversationId, 'bot', $message);
        
        sendJsonResponse([
            'success' => true,
            'status' => 'human_requested',
            'message' => 'Human assistance requested successfully'
        ]);
        break;
        
    default:
        sendJsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
} 