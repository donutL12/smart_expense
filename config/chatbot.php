<?php
/**
 * Chatbot Configuration
 */

return [
    // Enable/disable chatbot
    'enabled' => true,
    
    // Max message length
    'max_message_length' => 500,
    
    // Response delay (milliseconds) for natural feel
    'typing_delay' => 1000,
    
    // Log conversations
    'log_enabled' => true,
    
    // Welcome messages
    'welcome_messages' => [
        'Hi! How can I help you today?',
        'Hello! Ready to manage your finances?',
        'Kumusta! Ask me about your budget!'
    ],
    
    // Error messages
    'error_messages' => [
        'Sorry, something went wrong. Please try again.',
        'I encountered an error. Can you rephrase that?',
    ],
    
    // Rate limiting (messages per minute)
    'rate_limit' => 30
];