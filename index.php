<?php
header('Content-Type: text/html; charset=utf-8');
?>
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
        <button type="button" id="new-chat-button" class="new-chat-button">New Chat</button>
    </div>

    <div id="chat-box" class="chat-box"></div>

    <form id="chat-form" class="chat-input">
        <input type="text" id="message" placeholder="Ask about inventory..." autocomplete="off">
        <button type="submit" id="send-button">Send</button>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script>
    const form = $('#chat-form');
    const input = $('#message');
    const chatBox = $('#chat-box');
    const sendButton = $('#send-button');
    const newChatButton = $('#new-chat-button');

    /*
    |--------------------------------------------------------------------------
    | ADD MESSAGE
    |--------------------------------------------------------------------------
    */
    function addMessage(message,type) {
        const div = $('<div>');
        div.addClass('message ' + type);
        div.text(message);
        chatBox.append(div);
        chatBox.scrollTop(chatBox[0].scrollHeight);
        return div;
    }

    /*
    |--------------------------------------------------------------------------
    | SHOW INITIAL MESSAGE
    |--------------------------------------------------------------------------
    */
    function showWelcomeMessage() {
        chatBox.html('');
        addMessage('Hello! How can I help you with the inventory?','bot');
    }

    /*
    |--------------------------------------------------------------------------
    | LOAD CONVERSATION HISTORY
    |--------------------------------------------------------------------------
    */
    function loadHistory() {
        $.ajax({
            url: 'helper.php',
            type: 'GET',
            data: {
                action: 'get_history'
            },
            dataType: 'JSON',
            cache: false,
            success: function(result) {
                chatBox.html('');
                if (result.status && Array.isArray(result.history) && result.history.length > 0) {
                    $.each(result.history,function(index,row) {
                        if (row.role === 'user') {
                            addMessage(row.message,'user');
                        } else if (row.role === 'assistant') {
                            addMessage(row.message,'bot');
                        }
                    });
                } else {
                    showWelcomeMessage();
                }
            },
            error: function(xhr,status,error) {
                console.error(error);
                showWelcomeMessage();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | SEND MESSAGE
    |--------------------------------------------------------------------------
    */
    form.on('submit',function(e) {
        e.preventDefault();

        const message = $.trim(input.val());
        if (!message) return; 
        addMessage(message,'user');
        input.val('');
        input.prop('disabled',true);
        sendButton.prop('disabled',true);
        const thinkingMessage = addMessage('Thinking...','bot');
        $.ajax({
            url: 'helper.php',
            type: 'POST',
            data: {
                message: message
            },
            dataType: 'JSON',
            success: function(result) {
                thinkingMessage.remove();
                if (result.status && result.reply) {
                    addMessage(result.reply,'bot');
                } else {
                    addMessage(result.reply || 'Sorry, I could not process your request.','bot');
                }
            },
            error: function(xhr,status,error) {
                thinkingMessage.remove();
                addMessage('Unable to connect to the chatbot.','bot');
                console.error(error);
            },
            complete: function() {
                input.prop('disabled',false);
                sendButton.prop('disabled',false);
                input.focus();
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | NEW CHAT
    |--------------------------------------------------------------------------
    */
    newChatButton.on('click',function() {
        const confirmed = confirm('Start a new chat? Your current chat will no longer be shown here.');
        if (!confirmed) return;

        newChatButton.prop('disabled',true);
        $.ajax({
            url: 'helper.php',
            type: 'GET',
            data: {
                action: 'new_chat'
            },
            dataType: 'JSON',
            cache: false,
            success: function(result) {
                if (result.status) {
                    chatBox.html('');
                    addMessage('Hello! How can I help you with the inventory?','bot');
                    input.val('');
                    input.focus();
                } else {
                    alert('Unable to start a new chat.');
                }
            },
            error: function(xhr,status,error) {
                console.error(error);
                alert('Unable to start a new chat.');
            },
            complete: function() {
                newChatButton.prop('disabled',false);
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | LOAD HISTORY WHEN PAGE OPENS
    |--------------------------------------------------------------------------
    */
    $(document).ready(function() {
        loadHistory();
    });
</script>
</body>
</html>