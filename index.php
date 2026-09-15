<?php
ob_start();
include_once 'helper.php';
ob_end_clean();

header('Content-Type: text/html; charset=utf-8');
$chatHistory = getConversationHistory();

/*
|--------------------------------------------------------------------------
| HTML CHAT INTERFACE
|--------------------------------------------------------------------------
*/ ?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Inventory AI Chatbot</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            margin: 0;
        }
        .chat-container {
            width: 700px;
            max-width: 95%;
            margin: 40px auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 3px 15px rgba(0, 0, 0, .15);
            overflow: hidden;
        }
        .chat-header {
            background: #018136;
            color: white;
            padding: 18px;
            font-size: 20px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .new-chat-button {
            background: white;
            color: #018136;
            border: 0;
            border-radius: 5px;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }
        .new-chat-button:hover {
            background: #eeeeee;
        }
        .chat-box {
            height: 500px;
            overflow-y: auto;
            padding: 20px;
        }
        .message {
            margin-bottom: 15px;
            padding: 12px 15px;
            border-radius: 10px;
            max-width: 80%;
            line-height: 1.5;
            white-space: pre-line;
        }
        .user {
            background: #e8f5e9;
            margin-left: auto;
        }
        .bot {
            background: #f1f1f1;
            margin-right: auto;
        }
        .chat-input {
            display: flex;
            border-top: 1px solid #ddd;
        }
        .chat-input input {
            flex: 1;
            padding: 15px;
            border: 0;
            outline: none;
            font-size: 16px;
        }
        .chat-input button {
            padding: 15px 25px;
            border: 0;
            background: #018136;
            color: white;
            cursor: pointer;
        }
        .chat-input button:hover {
            background: #016b2d;
        }
        .chat-input button:disabled {
            background: #999;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
<div class="chat-container">
    <div class="chat-header">
        <span>Inventory AI Assistant</span>
        <button type="button" id="new-chat-button" class="new-chat-button"> New Chat </button>
    </div>

    <div id="chat-box" class="chat-box"></div>

    <form id="chat-form" class="chat-input">
        <input type="text" id="message" placeholder="Ask about inventory..." autocomplete="off">
        <button type="submit" id="send-button"> Send </button>
    </form>
</div>

<script>
    const form = document.getElementById('chat-form');
    const input = document.getElementById('message');
    const chatBox = document.getElementById('chat-box');
    const sendButton = document.getElementById('send-button');
    const newChatButton = document.getElementById('new-chat-button');

    /*
    |--------------------------------------------------------------------------
    | CHAT HISTORY FROM PHP SESSION
    |--------------------------------------------------------------------------
    */
    const chatHistory = <?= json_encode($chatHistory,JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT ); ?>;

    /*
    |--------------------------------------------------------------------------
    | ADD MESSAGE
    |--------------------------------------------------------------------------
    */
    function addMessage(message, type) {
        const div = document.createElement('div');
        div.className = 'message ' + type;
        div.textContent = message;
        chatBox.appendChild(div);
        chatBox.scrollTop = chatBox.scrollHeight;
        return div;
    }

    /*
    |--------------------------------------------------------------------------
    | SHOW INITIAL MESSAGE
    |--------------------------------------------------------------------------
    */
    function showWelcomeMessage() {
        chatBox.innerHTML = '';
        addMessage('Hello! How can I help you with the inventory?', 'bot');
    }

    /*
    |--------------------------------------------------------------------------
    | LOAD CONVERSATION HISTORY
    |--------------------------------------------------------------------------
    */
    function loadHistory() {
        chatBox.innerHTML = '';

        if (Array.isArray(chatHistory) && chatHistory.length > 0) {
            chatHistory.forEach(function(row) {
                if (row.role === 'user') {
                    addMessage(row.message, 'user');
                } else if (row.role === 'assistant') {
                    addMessage(row.message,'bot');
                }
            });
        } else {
            showWelcomeMessage();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SEND MESSAGE
    |--------------------------------------------------------------------------
    */
    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        const message = input.value.trim();
        if (!message) return;

        addMessage(message,'user');

        input.value = '';
        input.disabled = true;
        sendButton.disabled = true;

        const thinkingMessage = addMessage('Thinking...','bot');
        try {
            const response = await fetch('helper.php', {
                method: 'POST',
                headers: {
                    'Content-Type':
                    'application/x-www-form-urlencoded'
                },
                body: 'message=' + encodeURIComponent(message)
            });

            const result = await response.json();
            thinkingMessage.remove();

            if (result.reply) {
                addMessage(result.reply,'bot');
            } else {
                addMessage('Sorry, I could not process your request.','bot');
            }

        } catch (error) {
            thinkingMessage.remove();
            addMessage('Unable to connect to the chatbot.','bot');
            console.error(error);
        } finally {
            input.disabled = false;
            sendButton.disabled = false;
            input.focus();
        }
    });

    /*
    |--------------------------------------------------------------------------
    | NEW CHAT
    |--------------------------------------------------------------------------
    */
    newChatButton.addEventListener('click', async function() {
        const confirmed = confirm('Start a new chat? Your current chat will no longer be shown here.');
        if (!confirmed) return;

        try {
            newChatButton.disabled = true;
            const response =
                await fetch('helper.php?action=new_chat',{
                    method: 'GET',
                    cache: 'no-store'
                });

            const result = await response.json();
            if (result.status) {
                chatBox.innerHTML = '';
                addMessage('Hello! How can I help you with the inventory?','bot');
                input.value = '';
                input.focus();
            } else {
                alert('Unable to start a new chat.');
            }

        } catch (error) {
            console.error(error);
            alert('Unable to start a new chat.');
        } finally {
            newChatButton.disabled = false;
        }
    });

    /*
    |--------------------------------------------------------------------------
    | LOAD HISTORY WHEN PAGE OPENS
    |--------------------------------------------------------------------------
    */
    document.addEventListener('DOMContentLoaded', function() {
        loadHistory();
    });
</script>
</body>
</html>