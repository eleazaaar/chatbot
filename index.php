<?php
    header('Content-Type: text/html; charset=utf-8');
    include_once 'app/classes/models/Extras.php';
    $Extras = new Extras();

    $inventoryUrl = getenv('INVENTORY_URL');
    $controllerUrl = Extras::fileExists("app/classes/controllers/ChatbotController.class.php");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#018136">
    <title>Inventory AI Assistant</title>
    <style>
        :root {
            --primary: #018136;
            --primary-dark: #016b2d;
            --primary-light: #e8f5e9;
            --background: #f4f7f5;
            --surface: #ffffff;
            --text: #202b24;
            --muted: #77817a;
            --border: #e5ebe6;
            --shadow: 0 12px 40px rgba(20, 55, 32, .09);
        }

        * { box-sizing: border-box; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: var(--background);
            color: var(--text);
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        button, input { font: inherit; }

        button:focus-visible, input:focus-visible, a:focus-visible {
            outline: 3px solid rgba(1, 129, 54, .3);
            outline-offset: 3px;
        }

        .chat-container {
            width: 100%;
            max-width: 850px;
            height: min(850px, calc(100vh - 48px));
            min-height: 480px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .chat-header {
            background: var(--primary);
            color: white;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .header-info {
            display: flex;
            align-items: center;
            gap: 13px;
            min-width: 0;
        }

        .assistant-icon {
            width: 45px;
            height: 45px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            border-radius: 13px;
            background: rgba(255, 255, 255, .17);
            font-size: 23px;
        }

        .header-title {
            font-size: 19px;
            font-weight: 700;
            margin: 0 0 5px;
        }

        .header-subtitle {
            font-size: 12px;
            opacity: .85;
            margin: 0;
        }

        .new-chat-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            background: white;
            color: var(--primary-dark);
            border: 0;
            border-radius: 9px;
            padding: 10px 13px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
            transition: background .2s, transform .2s;
        }

        .new-chat-button:hover { background: #eef7f0; }
        .new-chat-button:active { transform: scale(.97); }

        .chat-intro {
            padding: 14px 24px;
            border-bottom: 1px solid var(--border);
            background: #fbfdfb;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .chat-box {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            padding: 26px 24px;
            display: flex;
            flex-direction: column;
            scroll-behavior: smooth;
            background:
                radial-gradient(circle at 1px 1px, rgba(1, 129, 54, .035) 1px, transparent 0);
            background-size: 20px 20px;
        }

        .message-row {
            width: 100%;
            display: flex;
            margin-bottom: 18px;
            align-items: flex-end;
            gap: 9px;
        }

        .message-row.user-row { justify-content: flex-end; }
        .message-row.bot-row { justify-content: flex-start; }

        .message-avatar {
            width: 30px;
            height: 30px;
            flex-shrink: 0;
            border-radius: 10px;
            display: grid;
            place-items: center;
            font-size: 15px;
        }

        .bot-row .message-avatar {
            color: white;
            background: var(--primary);
        }

        .user-row .message-avatar {
            color: var(--primary-dark);
            background: var(--primary-light);
            order: 2;
        }

        .message {
            max-width: min(78%, 600px);
            padding: 13px 16px;
            border-radius: 15px;
            font-size: 14px;
            line-height: 1.65;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            box-shadow: 0 2px 5px rgba(0, 0, 0, .025);
        }

        .message.user {
            color: #174b2b;
            background: #e4f4e8;
            border-bottom-right-radius: 4px;
        }

        .message.bot {
            color: var(--text);
            background: white;
            border: 1px solid var(--border);
            border-bottom-left-radius: 4px;
        }

        .message-time {
            display: block;
            margin-top: 5px;
            color: var(--muted);
            font-size: 10px;
            line-height: 1.2;
            text-align: right;
        }

        .pdf-report-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
            padding: 10px 14px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            transition: background .2s, transform .2s;
        }

        .pdf-report-link:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .thinking-content {
            display: inline-flex;
            align-items: center;
            gap: 9px;
        }

        .thinking-dots {
            display: inline-flex;
            gap: 4px;
            align-items: center;
        }

        .thinking-dots span {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--primary);
            animation: bounce 1.1s infinite ease-in-out;
        }

        .thinking-dots span:nth-child(2) { animation-delay: .15s; }
        .thinking-dots span:nth-child(3) { animation-delay: .3s; }

        @keyframes bounce {
            0%, 60%, 100% { transform: translateY(0); opacity: .5; }
            30% { transform: translateY(-4px); opacity: 1; }
        }

        .chat-footer {
            padding: 16px 20px 12px;
            background: white;
            border-top: 1px solid var(--border);
        }

        .chat-input {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px;
            border: 1px solid #dce5dd;
            border-radius: 13px;
            background: #fbfdfb;
            transition: border-color .2s, box-shadow .2s;
        }

        .chat-input:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(1, 129, 54, .08);
        }

        .chat-input input {
            flex: 1;
            min-width: 0;
            padding: 12px 10px;
            border: 0;
            outline: none;
            background: transparent;
            color: var(--text);
            font-size: 14px;
        }

        .chat-input input::placeholder { color: #98a19a; }

        .chat-input input:disabled { opacity: .65; }

        .send-button {
            min-width: 90px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 16px;
            border: 0;
            border-radius: 9px;
            background: var(--primary);
            color: white;
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
            transition: background .2s;
        }

        .send-button:hover { background: var(--primary-dark); }

        .send-button:disabled,
        .new-chat-button:disabled {
            background: #aeb8b0;
            cursor: not-allowed;
            transform: none;
        }

        .footer-note {
            margin: 10px 4px 0;
            color: var(--muted);
            font-size: 11px;
            text-align: center;
        }

        .empty-state {
            margin: auto;
            padding: 25px 12px;
            text-align: center;
            max-width: 360px;
        }

        .empty-state-icon {
            width: 64px;
            height: 64px;
            display: grid;
            place-items: center;
            margin: 0 auto 16px;
            border-radius: 20px;
            background: var(--primary-light);
            font-size: 30px;
        }

        .empty-state h2 {
            margin: 0 0 8px;
            font-size: 19px;
        }

        .empty-state p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .suggestions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px;
            margin-top: 18px;
        }

        .suggestion-button {
            border: 1px solid #d9e8dc;
            border-radius: 20px;
            background: white;
            color: var(--primary-dark);
            padding: 8px 11px;
            cursor: pointer;
            font-size: 12px;
        }

        .suggestion-button:hover { background: var(--primary-light); }

        @media (max-width: 600px) {
            body { padding: 0; }

            .chat-container {
                width: 100%;
                max-width: none;
                height: 100vh;
                min-height: 0;
                border: 0;
                border-radius: 0;
            }

            .chat-header { padding: 15px 16px; }
            .assistant-icon { width: 39px; height: 39px; }
            .header-title { font-size: 16px; }
            .header-subtitle { font-size: 11px; }
            .new-chat-button { padding: 9px 10px; font-size: 12px; }
            .chat-intro { padding: 11px 16px; font-size: 12px; }
            .chat-box { padding: 20px 13px; }
            .message { max-width: 86%; padding: 11px 13px; font-size: 13px; }
            .chat-footer { padding: 12px 12px 10px; }
            .chat-input { gap: 5px; }
            .chat-input input { padding: 11px 8px; font-size: 13px; }
            .send-button { min-width: 74px; padding: 11px 12px; font-size: 13px; }
            .footer-note { font-size: 10px; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>
<body>
<div class="chat-container">
    <header class="chat-header">
        <div class="header-info">
            <div class="assistant-icon" aria-hidden="true">✦</div>
            <div>
                <h1 class="header-title">Inventory AI Assistant</h1>
                <p class="header-subtitle">Your inventory information assistant</p>
            </div>
        </div>
        <button type="button" id="new-chat-button" class="new-chat-button">
            <span aria-hidden="true">＋</span> New Chat
        </button>
    </header>

    <div class="chat-intro">
        Ask questions about stock levels, inventory records, or request a PDF report.
    </div>

    <main id="chat-box" class="chat-box" aria-live="polite" aria-label="Chat messages"></main>

    <footer class="chat-footer">
        <form id="chat-form" class="chat-input">
            <input type="text" id="message" name="message" placeholder="Ask about inventory or request a PDF report..." autocomplete="off" aria-label="Your message" required>
            <button type="submit" id="send-button" class="send-button"><span>Send</span><span aria-hidden="true">➤</span></button>
        </form>
        <p class="footer-note">Inventory AI Assistant · Responses may need verification</p>
    </footer>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    $(function () {
        const controllerUrl = <?= json_encode($controllerUrl, JSON_UNESCAPED_SLASHES) ?>;
        const inventoryUrl = <?= json_encode($inventoryUrl, JSON_UNESCAPED_SLASHES) ?>;

        const form = $('#chat-form');
        const input = $('#message');
        const chatBox = $('#chat-box');
        const sendButton = $('#send-button');
        const newChatButton = $('#new-chat-button');

        function scrollToBottom() {
            chatBox.scrollTop(chatBox[0].scrollHeight);
        }

        function normalizePdfUrl(pdfUrl) {
            if (!pdfUrl || typeof pdfUrl !== 'string') return '';

            try {
                const parsedUrl = new URL(pdfUrl, window.location.href);

                if (parsedUrl.pathname.endsWith('/admin/reports/xPDFLoader.php')) {
                    return inventoryUrl + '/admin/reports/xPDFLoader.php' + parsedUrl.search;
                }
            } catch (error) {
                console.error('Invalid PDF report URL:', error);
            }

            return '';
        }

        function addMessage(message, type, pdfUrl, isThinking) {
            const row = $('<div>').addClass('message-row ' + (type === 'user' ? 'user-row' : 'bot-row'));
            const avatar = $('<div>').addClass('message-avatar').attr('aria-hidden', 'true');
            avatar.text(type === 'user' ? '●' : '✦');

            const div = $('<div>').addClass('message ' + type);

            if (isThinking) {
                const thinking = $('<span>').addClass('thinking-content');
                thinking.append($('<span>').text('Thinking'));

                const dots = $('<span>').addClass('thinking-dots');
                dots.append($('<span>'), $('<span>'), $('<span>'));
                thinking.append(dots);
                div.append(thinking);
            } else {
                div.text(message == null ? '' : String(message));
            }

            const safePdfUrl = normalizePdfUrl(pdfUrl);

            if (safePdfUrl) {
                const link = $('<a>')
                    .addClass('pdf-report-link')
                    .attr('href', safePdfUrl)
                    .attr('target', '_blank')
                    .attr('rel', 'noopener noreferrer')
                    .text('▤  Open PDF Report');

                div.append($('<br>'), link);
            }

            row.append(avatar, div);
            chatBox.append(row);
            scrollToBottom();

            return row;
        }

        function showWelcomeMessage() {
            chatBox.html('');

            const emptyState = $('<div>').addClass('empty-state');
            emptyState.append(
                $('<div>').addClass('empty-state-icon').text('✦'),
                $('<h2>').text('How can I help you?'),
                $('<p>').text('Ask me about your inventory, check stock information, or request a PDF report.')
            );

            const suggestions = $('<div>').addClass('suggestions');

            [
                'Show remaining stock',
                'Generate an inventory report'
            ].forEach(function (suggestion) {
                const button = $('<button>')
                    .attr('type', 'button')
                    .addClass('suggestion-button')
                    .text(suggestion);

                button.on('click', function () {
                    input.val(suggestion);
                    form.trigger('submit');
                });

                suggestions.append(button);
            });

            emptyState.append(suggestions);
            chatBox.append(emptyState);
        }

        function loadHistory() {
            $.ajax({
                url: controllerUrl,
                type: 'GET',
                dataType: 'JSON',
                data: {action: 'get_history'},
                cache: false,
                success: function (result) {
                    chatBox.html('');

                    if (result.status && Array.isArray(result.history) && result.history.length > 0) {
                        $.each(result.history, function (index, row) {
                            if (row.role === 'user') {
                                addMessage(row.message, 'user');
                            } else if (row.role === 'assistant') {
                                addMessage(row.message, 'bot', row.pdf_url || '');
                            }
                        });
                    } else {
                        showWelcomeMessage();
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Unable to load chat history:', error);
                    showWelcomeMessage();
                }
            });
        }

        form.on('submit', function (e) {
            e.preventDefault();

            const message = $.trim(input.val());
            if (!message || sendButton.prop('disabled')) return;

            if (chatBox.find('.empty-state').length) {
                chatBox.html('');
            }

            addMessage(message, 'user');
            input.val('');
            input.prop('disabled', true);
            sendButton.prop('disabled', true);

            const thinkingMessage = addMessage('', 'bot', '', true);

            $.ajax({
                url: controllerUrl,
                type: 'POST',
                dataType: 'JSON',
                data: {message: message},
                success: function (result) {
                    thinkingMessage.remove();

                    if (result.status && result.reply) {
                        addMessage(result.reply, 'bot', result.pdf_url || '');
                    } else {
                        addMessage(result.reply || 'Sorry, I could not process your request.', 'bot');
                    }
                },
                error: function (xhr, status, error) {
                    thinkingMessage.remove();
                    addMessage('Unable to connect to the chatbot. Please try again.', 'bot');
                    console.error('Chat request failed:', error, xhr.responseText);
                },
                complete: function () {
                    input.prop('disabled', false);
                    sendButton.prop('disabled', false);
                    input.trigger('focus');
                }
            });
        });

        newChatButton.on('click', function () {
            const confirmed = confirm('Start a new chat? Your current chat will no longer be shown here.');
            if (!confirmed) return;

            newChatButton.prop('disabled', true);

            $.ajax({
                url: controllerUrl,
                type: 'GET',
                dataType: 'JSON',
                data: {action: 'new_chat'},
                cache: false,
                success: function (result) {
                    if (result.status) {
                        showWelcomeMessage();
                        input.val('');
                        input.trigger('focus');
                    } else {
                        alert('Unable to start a new chat.');
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Unable to start a new chat:', error);
                    alert('Unable to start a new chat.');
                },
                complete: function () {
                    newChatButton.prop('disabled', false);
                }
            });
        });

        loadHistory();
    });
</script>
</body>
</html>