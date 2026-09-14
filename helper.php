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

if (!isset($_SESSION['chatbot_session_id'])) {

    $_SESSION['chatbot_session_id'] =
        session_id() . '_' . uniqid();
}

$sessionId =
    $_SESSION['chatbot_session_id'];


/*
|--------------------------------------------------------------------------
| INVENTORY CONTEXT
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['inventory_context'])) {

    $_SESSION['inventory_context'] = [
        'item_name' => '',
        'size' => '',
        'gender' => '',
        'availability' => 'all',
        'response_type' => 'total',
        'scope' => 'specific_item'
    ];
}


/*
|--------------------------------------------------------------------------
| CONVERSATION
|--------------------------------------------------------------------------
*/

function saveConversation(
    $conn,
    $sessionId,
    $role,
    $message
) {
    $sessionId =
        $conn->real_escape_string($sessionId);

    $role =
        $conn->real_escape_string($role);

    $message =
        $conn->real_escape_string($message);

    $sql = "
        INSERT INTO chatbot_conversations
        (
            session_id,
            role,
            message
        )
        VALUES
        (
            '$sessionId',
            '$role',
            '$message'
        )
    ";

    if (!$conn->query($sql)) {

        return false;
    }

    return true;
}


function getConversationHistory(
    $conn,
    $sessionId,
    $limit = 30
) {
    $sessionId =
        $conn->real_escape_string($sessionId);

    $limit =
        (int) $limit;

    $sql = "
        SELECT
            role,
            message,
            created_at
        FROM chatbot_conversations
        WHERE session_id = '$sessionId'
        ORDER BY id DESC
        LIMIT $limit
    ";

    $result =
        $conn->query($sql);

    if (!$result) {
        return [];
    }

    $messages = [];

    while ($row = $result->fetch_assoc()) {

        $messages[] = [
            'role' => $row['role'],
            'message' => $row['message']
        ];
    }

    return array_reverse($messages);
}


/*
|--------------------------------------------------------------------------
| INVENTORY CONTEXT
|--------------------------------------------------------------------------
*/

function getInventoryContext()
{
    return $_SESSION['inventory_context'];
}


function saveInventoryContext($context)
{
    $_SESSION['inventory_context'] = [
        'item_name' =>
            isset($context['item_name'])
                ? $context['item_name']
                : '',

        'size' =>
            isset($context['size'])
                ? $context['size']
                : '',

        'gender' =>
            isset($context['gender'])
                ? $context['gender']
                : '',

        'availability' =>
            isset($context['availability'])
                ? $context['availability']
                : 'all',

        'response_type' =>
            isset($context['response_type'])
                ? $context['response_type']
                : 'total',

        'scope' =>
            isset($context['scope'])
                ? $context['scope']
                : 'specific_item'
    ];
}


function clearInventoryContext()
{
    $_SESSION['inventory_context'] = [
        'item_name' => '',
        'size' => '',
        'gender' => '',
        'availability' => 'all',
        'response_type' => 'total',
        'scope' => 'specific_item'
    ];
}


/*
|--------------------------------------------------------------------------
| OLLAMA
|--------------------------------------------------------------------------
*/

function askOllama($messages, $format = 'json')
{
    $payload = [
        'model' => 'llama3.2',
        'messages' => $messages,
        'stream' => false
    ];

    if ($format == 'json') {
        $payload['format'] = 'json';
    }

    $jsonPayload =
        json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
        );

    $ch =
        curl_init(
            'http://localhost:11434/api/chat'
        );

    curl_setopt(
        $ch,
        CURLOPT_POST,
        true
    );

    curl_setopt(
        $ch,
        CURLOPT_POSTFIELDS,
        $jsonPayload
    );

    curl_setopt(
        $ch,
        CURLOPT_HTTPHEADER,
        [
            'Content-Type: application/json'
        ]
    );

    curl_setopt(
        $ch,
        CURLOPT_RETURNTRANSFER,
        true
    );

    curl_setopt(
        $ch,
        CURLOPT_TIMEOUT,
        120
    );

    $response =
        curl_exec($ch);

    if ($response === false) {

        $error =
            curl_error($ch);

        curl_close($ch);

        return [
            'success' => false,
            'error' => $error
        ];
    }

    $httpCode =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    curl_close($ch);

    if ($httpCode != 200) {

        return [
            'success' => false,
            'error' =>
                'Ollama returned HTTP ' .
                $httpCode
        ];
    }

    $result =
        json_decode(
            $response,
            true
        );

    if (!is_array($result)) {

        return [
            'success' => false,
            'error' =>
                'Invalid JSON response from Ollama.'
        ];
    }

    if (
        !isset(
            $result['message']['content']
        )
    ) {

        return [
            'success' => false,
            'error' =>
                'Ollama did not return message content.'
        ];
    }

    return [
        'success' => true,
        'content' =>
            $result['message']['content']
    ];
}


/*
|--------------------------------------------------------------------------
| INVENTORY INTENT
|--------------------------------------------------------------------------
*/

function getInventoryIntent(
    $message,
    $context,
    $history
) {
    $contextJson =
        json_encode(
            $context,
            JSON_UNESCAPED_UNICODE
        );

    $historyText = '';

    foreach ($history as $row) {

        $role =
            $row['role'] == 'assistant'
                ? 'Assistant'
                : 'User';

        $historyText .=
            $role .
            ': ' .
            $row['message'] .
            "\n";
    }


    $systemPrompt = <<<PROMPT
You are the language understanding system for an inventory chatbot.

Your job is ONLY to understand what the user wants.

Do NOT answer the user.

Do NOT calculate inventory quantities.

Do NOT write SQL.

Do NOT invent inventory information.

Return JSON only.

The PHP application will use your JSON to query the real inventory database.

CURRENT INVENTORY CONTEXT:

$contextJson

CONVERSATION HISTORY:

$historyText

Return exactly this structure:

{
    "intent": "inventory",
    "item": null,
    "size": null,
    "gender": null,
    "availability": "all",
    "response_type": "total",
    "scope": "specific_item"
}

Allowed intent values:

inventory
search
summary
out_of_stock
greeting
help
general

Allowed gender values:

male
female
null

Allowed size values:

XSmall
Small
Medium
Large
XLarge
null

Allowed availability values:

all
available
out_of_stock
null

Allowed response_type values:

total
list

Allowed scope values:

specific_item
all_items

IMPORTANT CONTEXT RULES:

1. Use the current inventory context when the user asks a follow-up.

2. If the user mentions an item, use that item.

3. If the user does not mention an item and the current context has an item,
   keep the current item.

4. "male", "men", "man", "males", "for men", and "for male"
   mean gender = male.

5. "female", "women", "woman", "females", "for women", and "for female"
   mean gender = female.

6. "small" means Small.

7. "medium" means Medium.

8. "large" means Large.

9. "xsmall", "x-small", "extra small", or "XS"
   means XSmall.

10. "xlarge", "x-large", "extra large", or "XL"
    means XLarge.

11. "how many" normally means response_type = total.

12. "list", "show", "display", "breakdown", or "break down"
    normally means response_type = list.

13. "available", "available stocks", "in stock", or "remaining"
    means availability = available.

14. "out of stock" means availability = out_of_stock.

15. "all items", "all stocks", "everything", or "all inventory"
    means scope = all_items.

16. "list all male stocks" means:
    gender = male
    scope = all_items
    response_type = list

17. "list all female stocks" means:
    gender = female
    scope = all_items
    response_type = list

18. "list all available male stocks" means:
    gender = male
    availability = available
    scope = all_items
    response_type = list

19. "how many female stocks" means:
    gender = female
    scope = all_items
    response_type = total

20. "how many male Small stocks" means:
    gender = male
    size = Small
    scope = all_items
    response_type = total

21. "list male Small stocks" means:
    gender = male
    size = Small
    scope = all_items
    response_type = list

22. "list male BSBA Uniform Set" means:
    item = BSBA Uniform Set
    gender = male
    scope = specific_item
    response_type = list

23. "how many male BSBA Uniform Set" means:
    item = BSBA Uniform Set
    gender = male
    scope = specific_item
    response_type = total

24. "male?" after asking about an item means:
    keep the previous item
    gender = male

25. "female?" after asking about an item means:
    keep the previous item
    gender = female

26. If the user asks for a gender without mentioning a size,
    do NOT invent a size.

27. If the user says "all sizes" or "all size",
    clear the size filter.

28. If the user says "how many is that?",
    keep the previous inventory filters and use response_type = total.

29. If the user says "list them",
    keep the previous inventory filters and use response_type = list.

30. If the user says "only the available ones",
    keep the previous filters and change availability to available.

31. Never return inventory quantities.

32. Never invent an item name.

33. If the user is simply greeting, use intent = greeting.

34. If the user asks what you can do, use intent = help.

35. If the user asks for an inventory summary, use intent = summary.

36. If the user asks for out-of-stock items, use intent = out_of_stock.

37. For a normal inventory request, use intent = inventory.

USER MESSAGE:

$message

Return JSON only.
PROMPT;


    $messages = [
        [
            'role' => 'system',
            'content' => $systemPrompt
        ]
    ];

    /*
    | Only send the recent conversation to Ollama.
    */

    $recentHistory =
        array_slice(
            $history,
            -10
        );

    foreach (
        $recentHistory as $row
    ) {

        $messages[] = [
            'role' =>
                $row['role'] == 'assistant'
                    ? 'assistant'
                    : 'user',

            'content' =>
                $row['message']
        ];
    }

    /*
    | Send the current message again as
    | the latest user message.
    */

    $messages[] = [
        'role' => 'user',
        'content' => $message
    ];

    return askOllama(
        $messages,
        'json'
    );
}


/*
|--------------------------------------------------------------------------
| CLEAN JSON FROM OLLAMA
|--------------------------------------------------------------------------
*/

function parseOllamaJson($content)
{
    $content =
        trim($content);

    $intent =
        json_decode(
            $content,
            true
        );

    if (is_array($intent)) {
        return $intent;
    }

    /*
    | In case the model accidentally returns
    | markdown code fences.
    */

    $content =
        str_replace(
            [
                '```json',
                '```'
            ],
            '',
            $content
        );

    $content =
        trim($content);

    $intent =
        json_decode(
            $content,
            true
        );

    if (is_array($intent)) {
        return $intent;
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| INVENTORY DATABASE QUERY
|--------------------------------------------------------------------------
*/

function getInventoryRecords(
    $conn,
    $itemName = '',
    $size = '',
    $gender = '',
    $availability = 'all'
) {
    $conditions = [];


    /*
    | Item
    */

    if ($itemName != '') {

        $itemName =
            $conn->real_escape_string(
                $itemName
            );

        $conditions[] =
            "i.item_name LIKE '%$itemName%'";
    }


    /*
    | Gender
    */

    if ($gender == 'male') {

        $conditions[] =
            "LEFT(d.size_code, 3) = '(M)'";
    }

    if ($gender == 'female') {

        $conditions[] =
            "LEFT(d.size_code, 3) = '(F)'";
    }


    /*
    | Size
    */

    if ($size != '') {

        $size =
            $conn->real_escape_string(
                $size
            );

        if ($gender == 'male') {

            $conditions[] =
                "d.size_code = '(M)$size'";

        } elseif ($gender == 'female') {

            $conditions[] =
                "d.size_code = '(F)$size'";

        } else {

            $conditions[] =
                "d.size_code IN
                (
                    '(M)$size',
                    '(F)$size'
                )";
        }
    }


    /*
    | Availability
    */

    if ($availability == 'available') {

        $conditions[] =
            'd.quantity > 0';
    }

    if ($availability == 'out_of_stock') {

        $conditions[] =
            'd.quantity <= 0';
    }


    /*
    | WHERE
    */

    $where = '';

    if (!empty($conditions)) {

        $where =
            'WHERE ' .
            implode(
                ' AND ',
                $conditions
            );
    }


    $sql = "
        SELECT
            i.item_name,
            d.size_code,
            d.quantity
        FROM inventory_item i
        LEFT JOIN inventory_item_details d
            ON d.item_id = i.id
        $where
        ORDER BY
            i.item_name,
            d.size_code
    ";


    $result =
        $conn->query($sql);

    if (!$result) {
        return [];
    }


    $records = [];

    while (
        $row =
        $result->fetch_assoc()
    ) {

        $records[] = $row;
    }

    return $records;
}


/*
|--------------------------------------------------------------------------
| INVENTORY BREAKDOWN
|--------------------------------------------------------------------------
*/

function buildInventoryBreakdown(
    $records,
    $gender = '',
    $availability = 'all'
) {
    if (empty($records)) {

        return 'No matching inventory records were found.';
    }


    $grouped = [];


    foreach ($records as $row) {

        $itemName =
            isset($row['item_name'])
                ? $row['item_name']
                : '';

        $sizeCode =
            isset($row['size_code'])
                ? $row['size_code']
                : '';

        $quantity =
            isset($row['quantity'])
                ? (int) $row['quantity']
                : 0;


        /*
        | Remove gender prefix from display.
        */

        $sizeName =
            str_replace(
                ['(M)', '(F)'],
                '',
                $sizeCode
            );


        if ($sizeName == '') {
            $sizeName = 'Unspecified';
        }


        if (!isset($grouped[$itemName])) {

            $grouped[$itemName] = [];
        }


        if (
            !isset(
                $grouped[$itemName][$sizeName]
            )
        ) {

            $grouped[$itemName][$sizeName] = 0;
        }


        $grouped[$itemName][$sizeName] +=
            $quantity;
    }


    $title = 'Inventory Stock';

    if ($gender == 'male') {
        $title = 'Male Inventory Stock';
    }

    if ($gender == 'female') {
        $title = 'Female Inventory Stock';
    }


    if ($availability == 'available') {

        $title =
            'Available ' . $title;
    }

    if ($availability == 'out_of_stock') {

        $title =
            'Out-of-Stock ' . $title;
    }


    $reply =
        $title . "\n\n";


    $grandTotal = 0;


    foreach (
        $grouped as $itemName => $sizes
    ) {

        $reply .=
            '• ' .
            $itemName .
            "\n";


        $itemTotal = 0;


        foreach (
            $sizes as $sizeName => $quantity
        ) {

            $reply .=
                '  - ' .
                $sizeName .
                ': ' .
                number_format($quantity) .
                "\n";


            $itemTotal +=
                $quantity;
        }


        $reply .=
            '  Total: ' .
            number_format($itemTotal) .
            "\n\n";


        $grandTotal +=
            $itemTotal;
    }


    $reply .=
        'Total ' .
        ($gender == 'male'
            ? 'Male '
            : (
                $gender == 'female'
                    ? 'Female '
                    : ''
            )
        ) .
        'Stock: ' .
        number_format($grandTotal);


    return trim($reply);
}


/*
|--------------------------------------------------------------------------
| TOTAL INVENTORY
|--------------------------------------------------------------------------
*/

function getInventoryTotal($records)
{
    $total = 0;

    foreach ($records as $row) {

        $total +=
            isset($row['quantity'])
                ? (int) $row['quantity']
                : 0;
    }

    return $total;
}


/*
|--------------------------------------------------------------------------
| BUILD TOTAL RESPONSE
|--------------------------------------------------------------------------
*/

function buildInventoryTotalResponse(
    $total,
    $context
) {
    $label = 'Inventory';


    if (
        $context['gender'] != ''
    ) {

        $label =
            ucfirst(
                $context['gender']
            );
    }


    if (
        $context['size'] != ''
    ) {

        $label .=
            ' ' .
            $context['size'];
    }


    if (
        $context['item_name'] != ''
    ) {

        $label .=
            ' ' .
            $context['item_name'];
    }


    if (
        $context['availability'] == 'available'
    ) {

        return
            'There are ' .
            number_format($total) .
            ' available ' .
            $label .
            ' items in stock.';
    }


    if (
        $context['availability'] == 'out_of_stock'
    ) {

        return
            'There are ' .
            number_format($total) .
            ' ' .
            $label .
            ' out-of-stock items.';
    }


    return
        'There are ' .
        number_format($total) .
        ' ' .
        $label .
        ' items in stock.';
}


/*
|--------------------------------------------------------------------------
| GREETING
|--------------------------------------------------------------------------
*/

function buildGreeting()
{
    return
        'Hello! How can I help you with the inventory?';
}


/*
|--------------------------------------------------------------------------
| HELP
|--------------------------------------------------------------------------
*/

function buildHelp()
{
    return
        'I can help you check inventory stock, ' .
        'search items, check stock by size or gender, ' .
        'list available stocks, and identify out-of-stock items.';
}


/*
|--------------------------------------------------------------------------
| GET ACTION
|--------------------------------------------------------------------------
*/

$action =
    isset($_GET['action'])
        ? $_GET['action']
        : '';


/*
|--------------------------------------------------------------------------
| HISTORY
|--------------------------------------------------------------------------
*/

if ($action == 'history') {

    echo json_encode([
        'status' => true,
        'session_id' => $sessionId,
        'history' =>
            getConversationHistory(
                $conn,
                $sessionId,
                50
            ),
        'context' =>
            getInventoryContext()
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| POST ONLY
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] != 'POST'
) {

    echo json_encode([
        'status' => false,
        'message' => 'Invalid request.'
    ]);

    exit;
}


$action =
    isset($_POST['action'])
        ? $_POST['action']
        : '';


/*
|--------------------------------------------------------------------------
| NEW CHAT
|--------------------------------------------------------------------------
*/

if ($action == 'new_chat') {

    $_SESSION['chatbot_session_id'] =
        session_id() . '_' . uniqid();

    $sessionId =
        $_SESSION['chatbot_session_id'];

    clearInventoryContext();

    echo json_encode([
        'status' => true,
        'session_id' => $sessionId,
        'context' =>
            getInventoryContext()
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| POST HISTORY
|--------------------------------------------------------------------------
*/

if ($action == 'history') {

    echo json_encode([
        'status' => true,
        'session_id' => $sessionId,
        'history' =>
            getConversationHistory(
                $conn,
                $sessionId,
                50
            ),
        'context' =>
            getInventoryContext()
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| USER MESSAGE
|--------------------------------------------------------------------------
*/

$message =
    isset($_POST['message'])
        ? trim($_POST['message'])
        : '';


if ($message == '') {

    echo json_encode([
        'status' => false,
        'reply' => 'Please enter a message.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| SAVE USER MESSAGE
|--------------------------------------------------------------------------
*/

saveConversation(
    $conn,
    $sessionId,
    'user',
    $message
);


/*
|--------------------------------------------------------------------------
| GET CONTEXT + HISTORY
|--------------------------------------------------------------------------
*/

$context =
    getInventoryContext();

$history =
    getConversationHistory(
        $conn,
        $sessionId,
        30
    );


/*
|--------------------------------------------------------------------------
| GET AI INTENT
|--------------------------------------------------------------------------
*/

$aiResult =
    getInventoryIntent(
        $message,
        $context,
        $history
    );


if (
    !$aiResult['success']
) {

    $reply =
        'Sorry, I could not connect to the inventory assistant. ' .
        'Please make sure Ollama is running.';

    saveConversation(
        $conn,
        $sessionId,
        'assistant',
        $reply
    );

    echo json_encode([
        'status' => false,
        'reply' => $reply,
        'error' =>
            $aiResult['error']
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| PARSE AI JSON
|--------------------------------------------------------------------------
*/

$intent =
    parseOllamaJson(
        $aiResult['content']
    );


if (!is_array($intent)) {

    $reply =
        'Sorry, I could not understand your request.';

    saveConversation(
        $conn,
        $sessionId,
        'assistant',
        $reply
    );

    echo json_encode([
        'status' => false,
        'reply' => $reply
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| GET INTENT VALUES
|--------------------------------------------------------------------------
*/

$intentType =
    isset($intent['intent'])
        ? $intent['intent']
        : 'general';

$item =
    isset($intent['item']) &&
    $intent['item'] !== null
        ? trim($intent['item'])
        : '';

$size =
    isset($intent['size']) &&
    $intent['size'] !== null
        ? trim($intent['size'])
        : '';

$gender =
    isset($intent['gender']) &&
    $intent['gender'] !== null
        ? strtolower(
            trim($intent['gender'])
        )
        : '';

$availability =
    isset($intent['availability']) &&
    $intent['availability'] !== null
        ? strtolower(
            trim($intent['availability'])
        )
        : '';

$responseType =
    isset($intent['response_type'])
        ? strtolower(
            trim($intent['response_type'])
        )
        : '';

$scope =
    isset($intent['scope'])
        ? strtolower(
            trim($intent['scope'])
        )
        : '';


/*
|--------------------------------------------------------------------------
| VALIDATE AI VALUES
|--------------------------------------------------------------------------
*/

$validSizes = [
    'XSmall',
    'Small',
    'Medium',
    'Large',
    'XLarge'
];

if (
    $size != '' &&
    !in_array(
        $size,
        $validSizes,
        true
    )
) {

    $size = '';
}


if (
    $gender != 'male' &&
    $gender != 'female'
) {

    $gender = '';
}


if (
    $availability != 'all' &&
    $availability != 'available' &&
    $availability != 'out_of_stock'
) {

    $availability = '';
}


if (
    $responseType != 'total' &&
    $responseType != 'list'
) {

    $responseType = '';
}


if (
    $scope != 'specific_item' &&
    $scope != 'all_items'
) {

    $scope = '';
}


/*
|--------------------------------------------------------------------------
| MERGE CONTEXT
|--------------------------------------------------------------------------
|
| AI values that are present replace the previous context.
|
*/

if ($item != '') {

    $context['item_name'] =
        $item;
}

if (
    isset($intent['size']) &&
    $intent['size'] === ''
) {

    $context['size'] = '';

} elseif ($size != '') {

    $context['size'] =
        $size;
}

if (
    isset($intent['gender']) &&
    $intent['gender'] === ''
) {

    $context['gender'] = '';

} elseif ($gender != '') {

    $context['gender'] =
        $gender;
}

if ($availability != '') {

    $context['availability'] =
        $availability;
}

if ($responseType != '') {

    $context['response_type'] =
        $responseType;
}

if ($scope != '') {

    $context['scope'] =
        $scope;
}


/*
|--------------------------------------------------------------------------
| ALL ITEMS
|--------------------------------------------------------------------------
|
| If AI explicitly says all_items,
| the item should not restrict the query.
|
*/

if (
    $context['scope'] == 'all_items'
) {

    /*
    | Only keep an item if the AI explicitly
    | identified one and the scope is not all_items.
    */

    if (
        $intent['item'] === null ||
        $intent['item'] === ''
    ) {

        $context['item_name'] = '';
    }
}


/*
|--------------------------------------------------------------------------
| SAVE CONTEXT
|--------------------------------------------------------------------------
*/

saveInventoryContext(
    $context
);


/*
|--------------------------------------------------------------------------
| GREETING
|--------------------------------------------------------------------------
*/

if ($intentType == 'greeting') {

    $reply =
        buildGreeting();

    saveConversation(
        $conn,
        $sessionId,
        'assistant',
        $reply
    );

    echo json_encode([
        'status' => true,
        'reply' => $reply,
        'context' =>
            getInventoryContext()
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| HELP
|--------------------------------------------------------------------------
*/

if ($intentType == 'help') {

    $reply =
        buildHelp();

    saveConversation(
        $conn,
        $sessionId,
        'assistant',
        $reply
    );

    echo json_encode([
        'status' => true,
        'reply' => $reply,
        'context' =>
            getInventoryContext()
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| INVENTORY
|--------------------------------------------------------------------------
*/

if ($intentType == 'inventory') {

    $context =
        getInventoryContext();


    /*
    | Query all matching inventory records.
    */

    $records =
        getInventoryRecords(
            $conn,
            $context['item_name'],
            $context['size'],
            $context['gender'],
            $context['availability']
        );


    /*
    | LIST
    */

    if (
        $context['response_type'] == 'list'
    ) {

        $reply =
            buildInventoryBreakdown(
                $records,
                $context['gender'],
                $context['availability']
            );

    } else {

        /*
        | TOTAL
        */

        $total =
            getInventoryTotal(
                $records
            );

        $reply =
            buildInventoryTotalResponse(
                $total,
                $context
            );
    }


    saveConversation(
        $conn,
        $sessionId,
        'assistant',
        $reply
    );


    echo json_encode([
        'status' => true,
        'reply' => $reply,
        'context' =>
            getInventoryContext(),
        'intent' => $intent
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

if ($intentType == 'search') {

    $search =
        $item != ''
            ? $item
            : $message;

    $items =
        searchInventory(
            $conn,
            $search
        );


    if (empty($items)) {

        $reply =
            'I could not find any inventory items matching "' .
            $search .
            '".';

    } else {

        $reply =
            'Inventory items found:' .
            "\n\n";

        foreach (
            $items as $row
        ) {

            $itemName =
                isset($row['item_name'])
                    ? $row['item_name']
                    : '';

            $sizeCode =
                isset($row['size_code'])
                    ? $row['size_code']
                    : '';

            $quantity =
                isset($row['quantity'])
                    ? (int) $row['quantity']
                    : 0;

            $reply .=
                '• ' .
                $itemName .
                ' - ' .
                $sizeCode .
                ' - Qty: ' .
                number_format($quantity) .
                "\n";
        }
    }


    saveConversation(
        $conn,
        $sessionId,
        'assistant',
        $reply
    );


    echo json_encode([
        'status' => true,
        'reply' => $reply
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

if ($intentType == 'summary') {

    $summary =
        getInventorySummary(
            $conn
        );

    $reply =
        json_encode(
            $summary,
            JSON_PRETTY_PRINT
        );


    saveConversation(
        $conn,
        $sessionId,
        'assistant',
        $reply
    );


    echo json_encode([
        'status' => true,
        'reply' => $reply
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| OUT OF STOCK
|--------------------------------------------------------------------------
*/

if (
    $intentType == 'out_of_stock'
) {

    $records =
        getInventoryRecords(
            $conn,
            '',
            '',
            '',
            'out_of_stock'
        );


    if (empty($records)) {

        $reply =
            'There are currently no out-of-stock items.';

    } else {

        $reply =
            buildInventoryBreakdown(
                $records,
                '',
                'out_of_stock'
            );
    }


    saveConversation(
        $conn,
        $sessionId,
        'assistant',
        $reply
    );


    echo json_encode([
        'status' => true,
        'reply' => $reply
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| GENERAL
|--------------------------------------------------------------------------
*/

$generalHistory =
    getConversationHistory(
        $conn,
        $sessionId,
        20
    );


$generalMessages = [
    [
        'role' => 'system',
        'content' =>
            'You are a helpful inventory assistant. ' .
            'Answer naturally and briefly. ' .
            'Do not invent inventory quantities. ' .
            'If the user asks for actual inventory quantities, ' .
            'the PHP application must obtain the data from MySQL.'
    ]
];


foreach (
    $generalHistory as $row
) {

    $generalMessages[] = [
        'role' =>
            $row['role'] == 'assistant'
                ? 'assistant'
                : 'user',

        'content' =>
            $row['message']
    ];
}


$generalResult =
    askOllama(
        $generalMessages,
        'text'
    );


if (
    $generalResult['success']
) {

    $reply =
        trim(
            $generalResult['content']
        );

} else {

    $reply =
        'Sorry, I could not process your request right now.';
}


saveConversation(
    $conn,
    $sessionId,
    'assistant',
    $reply
);


echo json_encode([
    'status' => true,
    'reply' => $reply,
    'context' =>
        getInventoryContext()
]);

exit;
?>