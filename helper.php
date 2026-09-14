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

    $apiKey = '141a9f1faf5f46b99a2d716ccbe6c6a6.TX1semH-ECfPQ4rkjYgb1mrf';

    $url = 'https://ollama.com/api/chat';

    $data = [
        'model' => 'gpt-oss:20b-cloud',
        'messages' => $messages,
        'stream' => false,
        'format' => 'json',
        'options' => [
            'temperature' => 0
        ]
    ];

    $jsonData = json_encode($data);

    if ($jsonData === false) {
        return [
            'success' => false,
            'content' => '',
            'error' => 'Unable to encode Ollama request.'
        ];
    }

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_POST, true);

    curl_setopt(
        $ch,
        CURLOPT_POSTFIELDS,
        $jsonData
    );

    curl_setopt(
        $ch,
        CURLOPT_HTTPHEADER,
        [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]
    );

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);

    if ($response === false) {

        $error = curl_error($ch);

        curl_close($ch);

        return [
            'success' => false,
            'content' => '',
            'error' => $error
        ];
    }

    $httpCode = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {

        return [
            'success' => false,
            'content' => '',
            'error' => 'Ollama HTTP error: ' . $httpCode
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Decode Ollama response
    |--------------------------------------------------------------------------
    */

    $result = json_decode(
        $response,
        true
    );

    if (!is_array($result)) {

        return [
            'success' => false,
            'content' => '',
            'error' => 'Invalid JSON response from Ollama.'
        ];
    }

    if (
        !isset($result['message']) ||
        !isset($result['message']['content'])
    ) {

        return [
            'success' => false,
            'content' => '',
            'error' => 'Ollama response does not contain message content.'
        ];
    }

    return [
        'success' => true,
        'content' => $result['message']['content'],
        'error' => ''
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

    if ($availability === 'low_stock' || $availability === 'low stock' || $availability === 'low stocks' || $availability === 'low inventory' || $availability === 'below 50' || $availability === 'less than 50') {
        return 'low_stock';
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
You are an inventory request interpreter for a PHP inventory chatbot.

IMPORTANT:

Your ONLY job is to understand the user's request and return JSON filters.

You DO NOT have access to the inventory database.

You MUST NOT calculate inventory quantities.

You MUST NOT guess inventory values.

You MUST NOT invent gender, size, availability, or item names.

PHP will use your JSON output to query and filter the actual database.

==================================================
CURRENT INVENTORY CONTEXT
==================================================

item_name: {$context['item_name']}
gender: {$context['gender']}
size: {$context['size']}
availability: {$context['availability']}
response_type: {$context['response_type']}

==================================================
ALLOWED VALUES
==================================================

gender:

male
female
empty

size:

XSmall
Small
Medium
Large
XLarge
empty

availability:

all
available
low_stock
out_of_stock

response_type:

total
list

==================================================
CRITICAL RULE: NEVER GUESS
==================================================

If the user does NOT explicitly provide a value, DO NOT invent one.

For example:

User:
BSBA Uniform Set

Correct:

{
    "type": "inventory",
    "response_type": "total",
    "item_name": "BSBA Uniform Set",
    "gender": "",
    "size": "",
    "availability": "all"
}

WRONG:

{
    "gender": "Unisex",
    "size": "M",
    "availability": "available"
}

Do NOT use "Unisex".

Do NOT assume Medium.

Do NOT assume available.

Do NOT assume out_of_stock.

Do NOT infer values from the item name.

An empty value means the user did not specify that filter.

==================================================
ITEM NAME RULES
==================================================

If the user explicitly mentions an inventory item, use that item name.

Example:

User:
BSBA Uniform Set

Return:

"item_name": "BSBA Uniform Set"

If the user does not mention an item and the existing context contains an item, preserve the existing item.

If the user says "all items", clear item_name:

"item_name": ""

Do NOT invent an item name.

==================================================
GENDER RULES
==================================================

Only set gender when the user explicitly asks for male or female.

Examples:

"male"

gender = "male"

"female"

gender = "female"

"male stocks"

gender = "male"

"female stocks"

gender = "female"

If gender is not mentioned, preserve the existing context.

If there is no existing gender, use:

"gender": ""

NEVER return:

"gender": "Unisex"

unless "Unisex" is explicitly part of the user's request and Unisex is supported by PHP.

For this chatbot, the only valid gender values are:

male
female
empty

==================================================
SIZE RULES
==================================================

Only set size when the user explicitly mentions a supported size.

Supported sizes:

XSmall
Small
Medium
Large
XLarge

Examples:

"Small"

size = "Small"

"male Small"

size = "Small"

"Medium"

size = "Medium"

If the user does not mention a size, preserve the existing context.

If there is no existing size, use:

"size": ""

If the user says:

"all sizes"

clear the size:

"size": ""

NEVER guess a size.

For example:

User:
BSBA Uniform Set

DO NOT return:

"size": "Medium"

Return:

"size": ""

==================================================
AVAILABILITY RULES
==================================================

Only change availability when the user explicitly requests availability filtering.

If the user says:

"available"
"only available"
"available only"
"in stock"
"in stock only"
"only in stock"

use:

"availability": "available"

Available means:

quantity > 0

If the user says:

"low stock"
"low stocks"
"low inventory"
"below 50"
"less than 50"

use:

"availability": "low_stock"

Low stock means:

quantity > 0
AND
quantity < 50

Therefore:

0 = out of stock
1-49 = low stock
50 or more = normal stock

Do NOT include quantity 0 as low stock.

If the user says:

"out of stock"
"unavailable"
"not available"

use:

"availability": "out_of_stock"

If the user does not mention availability, preserve the existing context.

If there is no existing availability, use:

"availability": "all"

NEVER determine availability from the item name.

NEVER assume an item is in stock.

NEVER assume an item is out of stock.

==================================================
RESPONSE TYPE RULES
==================================================

If the user asks:

"how many"
"how much"
"how many are there"
"what is the quantity"
"what's the quantity"
"quantity"

use:

"response_type": "total"

If the user says:

"list"
"list them"
"show"
"show me"
"display"
"display them"

use:

"response_type": "list"

If the user does not explicitly request a list, preserve the existing response_type.

If there is no existing response_type, use:

"total"

==================================================
FOLLOW-UP QUESTIONS
==================================================

Preserve existing context when the user asks a follow-up question.

Example:

Current context:

item_name = BSBA Uniform Set
gender = ""
size = ""
availability = all

User:

male?

Return:

{
    "type": "inventory",
    "response_type": "total",
    "item_name": "BSBA Uniform Set",
    "gender": "male",
    "size": "",
    "availability": "all"
}

Example:

Current context:

item_name = BSBA Uniform Set
gender = male
size = ""
availability = all

User:

Small

Return:

{
    "type": "inventory",
    "response_type": "total",
    "item_name": "BSBA Uniform Set",
    "gender": "male",
    "size": "Small",
    "availability": "all"
}

Example:

Current context:

item_name = BSBA Uniform Set
gender = male
size = Small
availability = all

User:

only available ones

Return:

{
    "type": "inventory",
    "response_type": "total",
    "item_name": "BSBA Uniform Set",
    "gender": "male",
    "size": "Small",
    "availability": "available"
}

Example:

Current context:

item_name = BSBA Uniform Set
gender = male
size = Small
availability = all

User:

only low stock ones

Return:

{
    "type": "inventory",
    "response_type": "total",
    "item_name": "BSBA Uniform Set",
    "gender": "male",
    "size": "Small",
    "availability": "low_stock"
}

==================================================
SPECIAL COMMANDS
==================================================

If the user says:

eleazar

return exactly:

{
    "type": "chat",
    "response": "So cool!"
}

Greetings such as:

hello
hi
hey
good morning
good afternoon
good evening

return:

{
    "type": "greeting"
}

If the user asks what the chatbot can do, return:

{
    "type": "help"
}

For unrelated conversational questions, return:

{
    "type": "general",
    "response": "your natural response"
}

==================================================
INVENTORY EXAMPLES
==================================================

User:

BSBA Uniform Set

Return:

{
    "type": "inventory",
    "response_type": "total",
    "item_name": "BSBA Uniform Set",
    "gender": "",
    "size": "",
    "availability": "all"
}

User:

How many BSBA Uniform Set?

Return:

{
    "type": "inventory",
    "response_type": "total",
    "item_name": "BSBA Uniform Set",
    "gender": "",
    "size": "",
    "availability": "all"
}

User:

Male BSBA Uniform Set

Return:

{
    "type": "inventory",
    "response_type": "total",
    "item_name": "BSBA Uniform Set",
    "gender": "male",
    "size": "",
    "availability": "all"
}

User:

Male Small BSBA Uniform Set

Return:

{
    "type": "inventory",
    "response_type": "total",
    "item_name": "BSBA Uniform Set",
    "gender": "male",
    "size": "Small",
    "availability": "all"
}

User:

List male Small BSBA Uniform Set

Return:

{
    "type": "inventory",
    "response_type": "list",
    "item_name": "BSBA Uniform Set",
    "gender": "male",
    "size": "Small",
    "availability": "all"
}

User:

List all available male stocks

Return:

{
    "type": "inventory",
    "response_type": "list",
    "item_name": "",
    "gender": "male",
    "size": "",
    "availability": "available"
}

User:

List all low stock male items

Return:

{
    "type": "inventory",
    "response_type": "list",
    "item_name": "",
    "gender": "male",
    "size": "",
    "availability": "low_stock"
}

User:

How many female stocks?

Return:

{
    "type": "inventory",
    "response_type": "total",
    "item_name": "",
    "gender": "female",
    "size": "",
    "availability": "all"
}

User:

How many low stocks?

Return:

{
    "type": "inventory",
    "response_type": "total",
    "item_name": "",
    "gender": "",
    "size": "",
    "availability": "low_stock"
}

User:

Show low stock BSBA Uniform Set

Return:

{
    "type": "inventory",
    "response_type": "list",
    "item_name": "BSBA Uniform Set",
    "gender": "",
    "size": "",
    "availability": "low_stock"
}

User:

show all items

Return:

{
    "type": "inventory",
    "response_type": "list",
    "item_name": "",
    "gender": "",
    "size": "",
    "availability": "all"
}

User:

how many items are there

Return:

{
    "type": "inventory",
    "response_type": "total",
    "item_name": "",
    "gender": "",
    "size": "",
    "availability": "all"
}

==================================================
FINAL RULES
==================================================

Return ONLY valid JSON.

Do not return Markdown.

Do not return ```json.

Do not explain your answer.

Do not calculate inventory.

Do not invent values.

Do not guess missing filters.

When a value is not specified by the user and does not exist in the existing context, use an empty string.

==================================================
CONVERSATION HISTORY
==================================================
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

    /*
    |--------------------------------------------------------------------------
    | Remove accidental markdown code fences
    |--------------------------------------------------------------------------
    */

    if (strpos($content, '```') === 0) {

        $content = preg_replace(
            '/^```(?:json)?\s*/i',
            '',
            $content
        );

        $content = preg_replace(
            '/\s*```$/',
            '',
            $content
        );

        $content = trim($content);
    }

    $data = json_decode(
        $content,
        true
    );

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

        if ($itemName !== '') {
            if (stripos($rowItemName, $itemName) === false) {
                continue;
            }
        }

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

        if ($availability === 'available') {
            if ($quantity <= 0) {
                continue;
            }
        }

        if ($availability === 'low_stock') {
            if ($quantity <= 0 || $quantity >= 50) {
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

    if ($itemName !== '') {
        $description = $itemName;
    } else {
        $description = 'inventory';
    }

    if ($gender !== '') {
        $description =
            ucfirst($gender) .
            ' ' .
            $description;
    }

    if ($size !== '') {
        $displaySize = ucfirst($size);

        $description =
            $displaySize .
            ' ' .
            $description;
    }

    if ($availability === 'available') {
        $description .= ' in stock';
    } elseif ($availability === 'low_stock') {
        $description .= ' low stock';
    } elseif ($availability === 'out_of_stock') {
        $description .= ' out of stock';
    } else {
        $description .= ' items';
    }

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