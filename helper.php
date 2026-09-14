<?php

session_start();

require_once 'database.php';
require_once 'inventory.php';

$conn = Database::connection();

header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| SESSION
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['chat_session_id'])) {
    $_SESSION['chat_session_id'] = session_id();
}

if (!isset($_SESSION['inventory_context'])) {
    $_SESSION['inventory_context'] = [
        'item_name' => '',
        'gender' => '',
        'size' => '',
        'availability' => 'all',
        'response_type' => 'total'
    ];
}

/*
|--------------------------------------------------------------------------
| Get inventory context
|--------------------------------------------------------------------------
*/

function getInventoryContext() {
    if (!isset($_SESSION['inventory_context'])) {
        $_SESSION['inventory_context'] = [
            'item_name' => '',
            'gender' => '',
            'size' => '',
            'availability' => 'all',
            'response_type' => 'total'
        ];
    }

    return $_SESSION['inventory_context'];
}

/*
|--------------------------------------------------------------------------
| Save inventory context
|--------------------------------------------------------------------------
*/

function saveInventoryContext($context) {
    $_SESSION['inventory_context'] = $context;
}

/*
|--------------------------------------------------------------------------
| Save conversation
|--------------------------------------------------------------------------
*/

function saveConversation($conn, $sessionId, $role, $message) {
    if (!isset($_SESSION['conversation'])) {
        $_SESSION['conversation'] = [];
    }

    $_SESSION['conversation'][] = [
        'role' => $role,
        'message' => $message
    ];

    if (count($_SESSION['conversation']) > 10) {
        array_shift($_SESSION['conversation']);
    }
}

/*
|--------------------------------------------------------------------------
| Get conversation history
|--------------------------------------------------------------------------
*/

function getConversationHistory() {
    if (!isset($_SESSION['conversation'])) {
        return [];
    }

    return $_SESSION['conversation'];
}

/*
|--------------------------------------------------------------------------
| Ask Ollama
|--------------------------------------------------------------------------
*/

function askOllama($messages) {
    $url = 'http://localhost:11434/api/chat';

    $data = [
        'model' => 'llama3.2',
        'messages' => $messages,
        'stream' => false,
        'format' => 'json',
        'options' => [
            'temperature' => 0
        ]
    ];

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);

        curl_close($ch);

        return [
            'success' => false,
            'error' => $error
        ];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($httpCode !== 200) {
        return [
            'success' => false,
            'error' => 'Ollama returned HTTP ' . $httpCode
        ];
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        return [
            'success' => false,
            'error' => 'Invalid response from Ollama.'
        ];
    }

    if (!isset($decoded['message']['content'])) {
        return [
            'success' => false,
            'error' => 'Ollama returned an empty response.'
        ];
    }

    return [
        'success' => true,
        'content' => $decoded['message']['content']
    ];
}

/*
|--------------------------------------------------------------------------
| Normalize size
|--------------------------------------------------------------------------
*/

function normalizeSize($size) {
    $size = strtolower(trim($size));

    if ($size === '') {
        return '';
    }

    switch ($size) {
        case 'xs':
        case 'x-small':
        case 'extra small':
        case 'extra-small':
            return 'xsmall';

        case 's':
        case 'small':
            return 'small';

        case 'm':
        case 'medium':
            return 'medium';

        case 'l':
        case 'large':
            return 'large';

        case 'xl':
        case 'x-large':
        case 'extra large':
        case 'extra-large':
            return 'xlarge';

        default:
            return $size;
    }
}

/*
|--------------------------------------------------------------------------
| Normalize gender
|--------------------------------------------------------------------------
*/

function normalizeGender($gender) {
    $gender = strtolower(trim($gender));

    if ($gender === 'm' || $gender === 'male' || $gender === 'men' || $gender === 'man') {
        return 'male';
    }

    if ($gender === 'f' || $gender === 'female' || $gender === 'women' || $gender === 'woman') {
        return 'female';
    }

    return '';
}

/*
|--------------------------------------------------------------------------
| Normalize availability
|--------------------------------------------------------------------------
*/

function normalizeAvailability($availability) {
    $availability = strtolower(trim($availability));

    if ($availability === 'available' || $availability === 'in stock' || $availability === 'instock') {
        return 'available';
    }

    if ($availability === 'out_of_stock' || $availability === 'out of stock' || $availability === 'outofstock') {
        return 'out_of_stock';
    }

    return 'all';
}

/*
|--------------------------------------------------------------------------
| Normalize response type
|--------------------------------------------------------------------------
*/

function normalizeResponseType($responseType) {
    $responseType = strtolower(trim($responseType));

    if ($responseType === 'list' || $responseType === 'show' || $responseType === 'display') {
        return 'list';
    }

    return 'total';
}

/*
|--------------------------------------------------------------------------
| Analyze inventory request using Ollama
|--------------------------------------------------------------------------
*/

function analyzeInventoryRequest($message, $context, $history) {
    $systemPrompt = <<<PROMPT
You are the inventory request interpreter for a PHP inventory chatbot.

Your job is NOT to calculate inventory.

Your job is only to understand the user's message and return JSON filters.

The actual inventory database is handled by PHP.

Current inventory context:

item_name: {$context['item_name']}
gender: {$context['gender']}
size: {$context['size']}
availability: {$context['availability']}
response_type: {$context['response_type']}

Possible gender values:

male
female
empty

Possible size values:

XSmall
Small
Medium
Large
XLarge
empty

Possible availability values:

all
available
out_of_stock

Possible response_type values:

total
list

Rules:

1. Preserve the existing context when the user is asking a follow-up question.

Example:

Previous item:
BSBA Uniform Set

User:
"male?"

Return:

{
    "type": "inventory",
    "response_type": "total",
    "item_name": "BSBA Uniform Set",
    "gender": "male",
    "size": "",
    "availability": "all"
}

2. If the user says "female", change gender to female while preserving the existing item, size and availability.

3. If the user says "male", change gender to male while preserving the existing item, size and availability.

4. If the user says "all sizes", clear the size filter.

5. If the user says "all items", clear the item_name filter.

6. If the user says "only available", "available only", or "in stock only", set availability to available.

7. If the user says "out of stock", set availability to out_of_stock.

8. If the user asks "how many", use response_type total.

9. If the user says "list", "show", "display", or "show me", use response_type list.

10. If the user asks about a specific inventory item, put the item name in item_name.

11. Do not invent item names.

12. Keep item_name empty if the user is asking for all inventory.

13. "show all items" means:
    type = inventory
    response_type = list
    item_name = empty
    gender = empty
    size = empty
    availability = all

14. "how many items are there" means:
    type = inventory
    response_type = total
    item_name = empty
    gender = empty
    size = empty
    availability = all

15. If the user says "eleazar", return:
{
    "type": "chat",
    "response": "So cool!"
}

16. Greetings should return:
{
    "type": "greeting"
}

17. If the user asks what you can do, return:
{
    "type": "help"
}

18. For unrelated conversational questions, return:
{
    "type": "general",
    "response": "your natural response"
}

19. Return ONLY valid JSON.

JSON format for inventory:

{
    "type": "inventory",
    "response_type": "total",
    "item_name": "",
    "gender": "",
    "size": "",
    "availability": "all"
}

Conversation history:
PROMPT;

    foreach ($history as $item) {
        $role = $item['role'] ?? '';
        $text = $item['message'] ?? '';

        $systemPrompt .= "\n";
        $systemPrompt .= $role . ': ' . $text;
    }

    $messages = [
        [
            'role' => 'system',
            'content' => $systemPrompt
        ],
        [
            'role' => 'user',
            'content' => $message
        ]
    ];

    return askOllama($messages);
}

/*
|--------------------------------------------------------------------------
| Parse Ollama response
|--------------------------------------------------------------------------
*/

function parseOllamaResponse($content) {
    $content = trim($content);

    $data = json_decode($content, true);

    if (is_array($data)) {
        return $data;
    }

    return [
        'type' => 'general',
        'response' => $content
    ];
}

/*
|--------------------------------------------------------------------------
| Filter inventory
|--------------------------------------------------------------------------
*/

function filterInventory($inventory, $filters) {
    $filtered = [];

    $itemName = strtolower(trim($filters['item_name'] ?? ''));
    $gender = normalizeGender($filters['gender'] ?? '');
    $size = normalizeSize($filters['size'] ?? '');
    $availability = normalizeAvailability($filters['availability'] ?? 'all');

    foreach ($inventory as $row) {
        $rowItemName = strtolower(trim($row['item_name'] ?? ''));
        $sizeCode = trim($row['size_code'] ?? '');
        $quantity = (int)($row['quantity'] ?? 0);

        /*
        |--------------------------------------------------------------------------
        | Item name
        |--------------------------------------------------------------------------
        */

        if ($itemName !== '') {
            if (stripos($rowItemName, $itemName) === false) {
                continue;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Gender
        |--------------------------------------------------------------------------
        */

        if ($gender === 'male') {
            if (strpos($sizeCode, '(M)') !== 0) {
                continue;
            }
        }

        if ($gender === 'female') {
            if (strpos($sizeCode, '(F)') !== 0) {
                continue;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Size
        |--------------------------------------------------------------------------
        */

        if ($size !== '') {
            $actualSize = str_replace(
                [
                    '(M)',
                    '(F)'
                ],
                '',
                $sizeCode
            );

            $actualSize = normalizeSize($actualSize);

            if ($actualSize !== $size) {
                continue;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Availability
        |--------------------------------------------------------------------------
        */

        if ($availability === 'available') {
            if ($quantity <= 0) {
                continue;
            }
        }

        if ($availability === 'out_of_stock') {
            if ($quantity > 0) {
                continue;
            }
        }

        $filtered[] = $row;
    }

    return $filtered;
}

/*
|--------------------------------------------------------------------------
| Calculate total quantity
|--------------------------------------------------------------------------
*/

function calculateInventoryTotal($inventory) {
    $total = 0;

    foreach ($inventory as $row) {
        $total += (int)($row['quantity'] ?? 0);
    }

    return $total;
}

/*
|--------------------------------------------------------------------------
| Get gender label
|--------------------------------------------------------------------------
*/

function getGenderLabel($sizeCode) {
    if (strpos($sizeCode, '(M)') === 0) {
        return 'Male';
    }

    if (strpos($sizeCode, '(F)') === 0) {
        return 'Female';
    }

    return '';
}

/*
|--------------------------------------------------------------------------
| Get display size
|--------------------------------------------------------------------------
*/

function getDisplaySize($sizeCode) {
    return str_replace(
        [
            '(M)',
            '(F)'
        ],
        '',
        $sizeCode
    );
}

/*
|--------------------------------------------------------------------------
| Build total response
|--------------------------------------------------------------------------
*/

function buildInventoryTotal($inventory, $filters) {
    $total = calculateInventoryTotal($inventory);

    $itemName = trim($filters['item_name'] ?? '');
    $gender = normalizeGender($filters['gender'] ?? '');
    $size = normalizeSize($filters['size'] ?? '');
    $availability = normalizeAvailability($filters['availability'] ?? 'all');

    /*
    |--------------------------------------------------------------------------
    | Description
    |--------------------------------------------------------------------------
    */

    if ($itemName !== '') {
        $description = $itemName;
    } else {
        $description = 'inventory';
    }

    /*
    |--------------------------------------------------------------------------
    | Gender
    |--------------------------------------------------------------------------
    */

    if ($gender !== '') {
        $description =
            ucfirst($gender) .
            ' ' .
            $description;
    }

    /*
    |--------------------------------------------------------------------------
    | Size
    |--------------------------------------------------------------------------
    */

    if ($size !== '') {
        $displaySize = ucfirst($size);

        $description =
            $displaySize .
            ' ' .
            $description;
    }

    /*
    |--------------------------------------------------------------------------
    | Availability
    |--------------------------------------------------------------------------
    */

    if ($availability === 'available') {
        $description .= ' in stock';
    } elseif ($availability === 'out_of_stock') {
        $description .= ' out of stock';
    } else {
        $description .= ' items';
    }

    /*
    |--------------------------------------------------------------------------
    | Grammar
    |--------------------------------------------------------------------------
    */

    if ($total == 1) {
        $description = rtrim($description, 's');
    }

    return 'There are ' .
        number_format($total) .
        ' ' .
        $description .
        '.';
}

/*
|--------------------------------------------------------------------------
| Build inventory list
|--------------------------------------------------------------------------
*/

function buildInventoryList($inventory, $filters) {
    if (empty($inventory)) {
        return 'No matching inventory records were found.';
    }

    /*
    |--------------------------------------------------------------------------
    | Group inventory
    |--------------------------------------------------------------------------
    */

    $groups = [];

    foreach ($inventory as $row) {
        $itemName = trim($row['item_name'] ?? '');

        if ($itemName === '') {
            $itemName = 'Unnamed Item';
        }

        if (!isset($groups[$itemName])) {
            $groups[$itemName] = [];
        }

        $groups[$itemName][] = $row;
    }

    /*
    |--------------------------------------------------------------------------
    | Build response
    |--------------------------------------------------------------------------
    */

    $response = '';

    foreach ($groups as $itemName => $rows) {
        $response .= '• ' . $itemName . "\n";

        $itemTotal = 0;

        foreach ($rows as $row) {
            $sizeCode = trim($row['size_code'] ?? '');
            $quantity = (int)($row['quantity'] ?? 0);

            $displaySize = getDisplaySize($sizeCode);
            $gender = getGenderLabel($sizeCode);

            if ($displaySize === '') {
                $displaySize = 'Stock';
            }

            $label = '';

            if ($gender !== '') {
                $label .= $gender . ' ';
            }

            $label .= $displaySize;

            $response .=
                '  - ' .
                $label .
                ': ' .
                number_format($quantity) .
                "\n";

            $itemTotal += $quantity;
        }

        $response .=
            '  Total: ' .
            number_format($itemTotal) .
            "\n\n";
    }

    /*
    |--------------------------------------------------------------------------
    | Overall total
    |--------------------------------------------------------------------------
    */

    $overallTotal = calculateInventoryTotal($inventory);
    $gender = normalizeGender($filters['gender'] ?? '');

    if ($gender !== '') {
        $response .=
            'Total ' .
            ucfirst($gender) .
            ' Stock: ' .
            number_format($overallTotal);
    } else {
        $response .=
            'Total Stock: ' .
            number_format($overallTotal);
    }

    return trim($response);
}

/*
|--------------------------------------------------------------------------
| Greeting
|--------------------------------------------------------------------------
*/

function getGreetingResponse() {
    return 'Hello! How can I help you with the inventory?';
}

/*
|--------------------------------------------------------------------------
| Help
|--------------------------------------------------------------------------
*/

function getHelpResponse() {
    return "I can help you check inventory, quantities, sizes, gender, and availability.\n\nExamples:\n• How many BSBA Uniform Set?\n• How many male BSBA Uniform Set?\n• Show male Small stocks\n• List all available male stocks\n• Show all items\n• How many female stocks?\n• Only available ones";
}

/*
|--------------------------------------------------------------------------
| Main chatbot
|--------------------------------------------------------------------------
*/

function processChat($conn, $message) {
    $message = trim($message);

    if ($message === '') {
        return [
            'status' => false,
            'reply' => 'Please enter a message.'
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Save user message
    |--------------------------------------------------------------------------
    */

    saveConversation(
        $conn,
        $_SESSION['chat_session_id'],
        'user',
        $message
    );

    /*
    |--------------------------------------------------------------------------
    | Special custom response
    |--------------------------------------------------------------------------
    */

    if (strtolower($message) === 'eleazar') {
        $reply = 'So cool!';

        saveConversation(
            $conn,
            $_SESSION['chat_session_id'],
            'assistant',
            $reply
        );

        return [
            'status' => true,
            'reply' => $reply,
            'context' => getInventoryContext()
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Get current context
    |--------------------------------------------------------------------------
    */

    $context = getInventoryContext();
    $history = getConversationHistory();

    /*
    |--------------------------------------------------------------------------
    | Ask Ollama to interpret the request
    |--------------------------------------------------------------------------
    */

    $ollama = analyzeInventoryRequest(
        $message,
        $context,
        $history
    );

    if (!$ollama['success']) {
        return [
            'status' => false,
            'reply' => 'Unable to connect to the chatbot.',
            'error' => $ollama['error']
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Parse Ollama result
    |--------------------------------------------------------------------------
    */

    $ai = parseOllamaResponse($ollama['content']);
    $type = strtolower(trim($ai['type'] ?? 'general'));

    /*
    |--------------------------------------------------------------------------
    | Greeting
    |--------------------------------------------------------------------------
    */

    if ($type === 'greeting') {
        $reply = getGreetingResponse();

        saveConversation(
            $conn,
            $_SESSION['chat_session_id'],
            'assistant',
            $reply
        );

        return [
            'status' => true,
            'reply' => $reply,
            'context' => $context
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Help
    |--------------------------------------------------------------------------
    */

    if ($type === 'help') {
        $reply = getHelpResponse();

        saveConversation(
            $conn,
            $_SESSION['chat_session_id'],
            'assistant',
            $reply
        );

        return [
            'status' => true,
            'reply' => $reply,
            'context' => $context
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | General conversation
    |--------------------------------------------------------------------------
    */

    if ($type === 'general') {
        $reply = trim($ai['response'] ?? '');

        if ($reply === '') {
            $reply = 'How can I help you?';
        }

        saveConversation(
            $conn,
            $_SESSION['chat_session_id'],
            'assistant',
            $reply
        );

        return [
            'status' => true,
            'reply' => $reply,
            'context' => $context
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Chat response
    |--------------------------------------------------------------------------
    */

    if ($type === 'chat') {
        $reply = trim($ai['response'] ?? '');

        if ($reply === '') {
            $reply = 'How can I help you?';
        }

        saveConversation(
            $conn,
            $_SESSION['chat_session_id'],
            'assistant',
            $reply
        );

        return [
            'status' => true,
            'reply' => $reply,
            'context' => $context
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Summary
    |--------------------------------------------------------------------------
    */

    if ($type === 'summary') {
        $inventory = getInventory($conn);
        $total = calculateInventoryTotal($inventory);
        $itemCount = count($inventory);

        $reply =
            'There are ' .
            number_format($itemCount) .
            ' inventory records with a total quantity of ' .
            number_format($total) .
            '.';

        saveConversation(
            $conn,
            $_SESSION['chat_session_id'],
            'assistant',
            $reply
        );

        return [
            'status' => true,
            'reply' => $reply,
            'context' => $context
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Inventory
    |--------------------------------------------------------------------------
    */

    if ($type === 'inventory') {
        $filters = [
            'item_name' => trim($ai['item_name'] ?? ''),
            'gender' => normalizeGender($ai['gender'] ?? ''),
            'size' => normalizeSize($ai['size'] ?? ''),
            'availability' => normalizeAvailability($ai['availability'] ?? 'all'),
            'response_type' => normalizeResponseType($ai['response_type'] ?? 'total')
        ];

        /*
        |--------------------------------------------------------------------------
        | Update context
        |--------------------------------------------------------------------------
        */

        saveInventoryContext($filters);

        /*
        |--------------------------------------------------------------------------
        | Get real inventory
        |--------------------------------------------------------------------------
        */

        $inventory = getInventory($conn);

        /*
        |--------------------------------------------------------------------------
        | Filter real inventory
        |--------------------------------------------------------------------------
        */

        $filteredInventory = filterInventory(
            $inventory,
            $filters
        );

        /*
        |--------------------------------------------------------------------------
        | Build response
        |--------------------------------------------------------------------------
        */

        if ($filters['response_type'] === 'list') {
            $reply = buildInventoryList(
                $filteredInventory,
                $filters
            );
        } else {
            $reply = buildInventoryTotal(
                $filteredInventory,
                $filters
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Save response
        |--------------------------------------------------------------------------
        */

        saveConversation(
            $conn,
            $_SESSION['chat_session_id'],
            'assistant',
            $reply
        );

        return [
            'status' => true,
            'reply' => $reply,
            'context' => $filters
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Fallback
    |--------------------------------------------------------------------------
    */

    $reply = 'How can I help you with the inventory?';

    saveConversation(
        $conn,
        $_SESSION['chat_session_id'],
        'assistant',
        $reply
    );

    return [
        'status' => true,
        'reply' => $reply,
        'context' => $context
    ];
}

/*
|--------------------------------------------------------------------------
| New chat
|--------------------------------------------------------------------------
*/

if (isset($_GET['action']) && $_GET['action'] === 'new_chat') {
    $_SESSION['conversation'] = [];

    $_SESSION['inventory_context'] = [
        'item_name' => '',
        'gender' => '',
        'size' => '',
        'availability' => 'all',
        'response_type' => 'total'
    ];

    echo json_encode([
        'status' => true
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Chat request
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = $_POST['message'] ?? '';

    echo json_encode(
        processChat(
            $conn,
            $message
        )
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Invalid request
|--------------------------------------------------------------------------
*/

echo json_encode([
    'status' => false,
    'reply' => 'Invalid request.'
]);