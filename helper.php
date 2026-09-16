<?php
session_start();
require_once 'database.php';
require_once 'inventory.php';

header('Content-Type: application/json; charset=utf-8');

class InventoryChatbot {
    private $conn, $inventory;
    public function __construct() {
        date_default_timezone_set('Asia/Manila');
        $this->conn = Database::connection();
        $this->inventory = new Inventory();

        if (!isset($_SESSION['chat_session_id'])) {
            $_SESSION['chat_session_id'] = session_id();
        }

        if (!isset($_SESSION['inventory_context'])) {
            $_SESSION['inventory_context'] = $this->getDefaultInventoryContext();
        }
    }

    private function getDefaultInventoryContext() {
        return [
            'item_name' => '',
            'gender' => '',
            'size' => '',
            'availability' => 'all',
            'response_type' => 'total'
        ];
    }

    private function getInventoryContext() {
        if (!isset($_SESSION['inventory_context'])) {
            $_SESSION['inventory_context'] = $this->getDefaultInventoryContext();
        }

        return $_SESSION['inventory_context'];
    }

    private function saveInventoryContext($context) {
        $_SESSION['inventory_context'] = $context;
    }

    private function saveConversation($role, $message) {
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

    public function getConversationHistory() {
        return $_SESSION['conversation'] ?? [];
    }

    private function askOllama($messages) {
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

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60
        ]);

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

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'success' => false,
                'content' => '',
                'error' => 'Ollama HTTP error: ' . $httpCode
            ];
        }

        $result = json_decode($response, true);

        if (!is_array($result)) {
            return [
                'success' => false,
                'content' => '',
                'error' => 'Invalid JSON response from Ollama.'
            ];
        }

        if (!isset($result['message']) || !isset($result['message']['content'])) {
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

    private function normalizeSize($size) {
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

    private function normalizeGender($gender) {
        $gender = strtolower(trim($gender));

        if ($gender === 'm' || $gender === 'male' || $gender === 'men' || $gender === 'man') {
            return 'male';
        }

        if ($gender === 'f' || $gender === 'female' || $gender === 'women' || $gender === 'woman') {
            return 'female';
        }

        return '';
    }

    private function normalizeAvailability($availability) {
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

    private function normalizeResponseType($responseType) {
        $responseType = strtolower(trim($responseType));

        if ($responseType === 'list' || $responseType === 'show' || $responseType === 'display') {
            return 'list';
        }

        return 'total';
    }

    private function analyzeInventoryRequest($message, $context, $history) {
        $systemPrompt = <<<'PROMPT'
        You are the request interpreter for an inventory management chatbot.

        Your job is to understand what the user means from the current message and conversation history.

        You do NOT access the database.
        You do NOT calculate inventory quantities.
        You do NOT guess inventory values.

        Return ONLY valid JSON.

        For inventory requests, ALWAYS return all of these fields:

        {
            "type": "inventory",
            "response_type": "total",
            "item_name": "",
            "gender": "",
            "size": "",
            "availability": "all"
        }

        Allowed type values:
        inventory
        greeting
        help
        general
        chat

        Allowed response_type values:
        total
        list

        Allowed gender values:
        male
        female
        ""

        Allowed size values:
        XSmall
        Small
        Medium
        Large
        XLarge
        ""

        Allowed availability values:
        all
        available
        low_stock
        out_of_stock

        Important conversation rules:

        1. Use the previous inventory context when the user asks a follow-up question.

        2. Words such as "that", "this", "it", "those", "the result", "the stock", "the stocks", "same", and "previous" normally refer to the previous inventory request.

        3. If the user asks a yes/no question about the previous inventory result, understand the question using the previous inventory context.

        4. Do not remove an existing item_name, gender, size, or availability unless the user clearly changes it.

        5. If the user asks about "all items", "all inventory", or "everything", remove the previous item_name restriction and use item_name = "".

        6. If the user asks "is that for BSBA only or all items?" and the previous item_name is "BSBA Uniform Set", this is a follow-up about the previous result. Return:
        {
            "type": "chat",
            "response": "Yes, that is for BSBA Uniform Set only."
        }

        7. If the user asks "does that include all items?" and the previous item_name is specific, return:
        {
            "type": "chat",
            "response": "No, that is only for the previously requested item."
        }

        8. If the user asks "what about all items?" or "show all items", use:
        item_name = ""
        and process it as an inventory request.

        9. "available" means quantity greater than 0.

        10. "low stock" means quantity greater than 0 and less than 50.

        11. "out of stock" means quantity less than or equal to 0.

        12. "show", "list", "display", "what are", and similar wording normally means response_type = "list".

        13. "how many", "how much", "total", and similar wording normally means response_type = "total".

        14. A greeting should return:
        {
            "type": "greeting"
        }

        15. A help request should return:
        {
            "type": "help"
        }

        16. If the user says "eleazar", return:
        {
            "type": "chat",
            "response": "So cool!"
        }

        17. For normal conversation that is not an inventory request, use:
        {
            "type": "chat",
            "response": "..."
        }

        18. Do not invent database results.

        Previous inventory context:
        PROMPT;

        $systemPrompt .= "\n" . json_encode($context);

        $systemPrompt .= "\n\nPrevious conversation:\n";

        foreach ($history as $entry) {
            $systemPrompt .= ucfirst($entry['role']) . ': ' . $entry['message'] . "\n";
        }

        $systemPrompt .= "\nCurrent user message:\n" . $message;

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

        return $this->askOllama($messages);
    }

    private function generateNaturalResponse($message, $context, $result) {
        $systemPrompt = <<<'PROMPT'
        You are the conversational response part of an inventory management chatbot.

        Respond naturally, briefly, and conversationally, like a helpful human assistant.

        The inventory result provided to you is the source of truth.

        Rules:

        - Do not invent quantities or inventory information.
        - Do not change numbers.
        - Do not calculate different numbers.
        - Do not return JSON.
        - Do not mention that you are an AI.
        - Do not say "according to the database".
        - Do not unnecessarily repeat the entire result.
        - Answer the user's actual question directly.
        - Understand words such as "that", "this", "it", "those", and "the result" using the conversation context.
        - If the user asks a yes/no question, answer yes or no directly.
        - If the user asks for clarification about the scope of a result, clearly state which item the result applies to.
        - If the user asks for all items but the provided result is only for one item, explain that the previous result was only for that item.
        - Keep the response natural and concise.

        Previous inventory context:
        PROMPT;

        $systemPrompt .= "\n" . json_encode($context);

        $systemPrompt .= "\n\nUser message:\n" . $message;
        $systemPrompt .= "\n\nActual inventory result:\n" . $result;

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

        $ollama = $this->askOllama($messages);

        if (!$ollama['success']) {
            return $result;
        }

        $response = trim($ollama['content']);

        $decoded = json_decode($response, true);

        if (is_array($decoded) && isset($decoded['response'])) {
            $response = trim($decoded['response']);
        }

        if ($response === '') {
            return $result;
        }

        return $response;
    }

    private function parseOllamaResponse($content) {
        $data = json_decode($content, true);

        if (!is_array($data)) {
            return [];
        }

        return $data;
    }

    private function filterInventory($inventory, $filters) {
        $filtered = [];

        $itemName = strtolower(trim($filters['item_name'] ?? ''));
        $gender = $this->normalizeGender($filters['gender'] ?? '');
        $size = $this->normalizeSize($filters['size'] ?? '');
        $availability = $this->normalizeAvailability($filters['availability'] ?? 'all');

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
                $actualSize = str_replace(['(M)', '(F)'], '', $sizeCode);
                $actualSize = $this->normalizeSize($actualSize);

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

    private function calculateInventoryTotal($inventory) {
        $total = 0;

        foreach ($inventory as $row) {
            $total += (int)($row['quantity'] ?? 0);
        }

        return $total;
    }

    private function getGenderLabel($sizeCode) {
        if (strpos($sizeCode, '(M)') === 0) {
            return 'Male';
        }

        if (strpos($sizeCode, '(F)') === 0) {
            return 'Female';
        }

        return '';
    }

    private function getDisplaySize($sizeCode) {
        $sizeCode = trim($sizeCode);
        $sizeCode = str_replace(['(M)', '(F)'], '', $sizeCode);

        return trim($sizeCode);
    }

    private function buildInventoryTotal($inventory, $filters) {
        $total = $this->calculateInventoryTotal($inventory);
        $itemName = trim($filters['item_name'] ?? '');
        $gender = $this->normalizeGender($filters['gender'] ?? '');
        $size = $this->normalizeSize($filters['size'] ?? '');
        $availability = $this->normalizeAvailability($filters['availability'] ?? 'all');

        $description = $itemName !== '' ? $itemName : 'inventory';

        if ($gender !== '') {
            $description = ucfirst($gender) . ' ' . $description;
        }

        if ($size !== '') {
            $description = ucfirst($size) . ' ' . $description;
        }

        if ($availability === 'available') {
            $description .= ' in stock';
        } else if ($availability === 'low_stock') {
            $description .= ' low stock';
        } else if ($availability === 'out_of_stock') {
            $description .= ' out of stock';
        } else {
            $description .= ' items';
        }

        if ($total == 1) {
            $description = rtrim($description, 's');
        }

        return 'There are ' . number_format($total) . ' ' . $description . '.';
    }

    private function buildInventoryList($inventory, $filters) {
        if (empty($inventory)) {
            return 'No matching inventory records were found.';
        }

        $groups = [];

        foreach ($inventory as $row) {
            $itemName = trim($row['item_name'] ?? '');

            if ($itemName === '') {
                $itemName = 'Unnamed Item';
            }

            $groups[$itemName][] = $row;
        }

        $response = '';

        foreach ($groups as $itemName => $rows) {
            $response .= '• ' . $itemName . "\n\n";

            $femaleRows = [];
            $maleRows = [];
            $otherRows = [];

            foreach ($rows as $row) {
                $sizeCode = trim($row['size_code'] ?? '');
                $gender = $this->getGenderLabel($sizeCode);

                if ($gender === 'Female') {
                    $femaleRows[] = $row;
                } else if ($gender === 'Male') {
                    $maleRows[] = $row;
                } else {
                    $otherRows[] = $row;
                }
            }

            $femaleTotal = 0;

            if (!empty($femaleRows)) {
                $response .= "Female\n";

                foreach ($femaleRows as $row) {
                    $sizeCode = trim($row['size_code'] ?? '');
                    $quantity = (int)($row['quantity'] ?? 0);
                    $displaySize = $this->getDisplaySize($sizeCode);

                    if ($displaySize === '') {
                        $displaySize = 'Stock';
                    }

                    $response .= '- ' . $displaySize . ': ' . number_format($quantity) . "\n";
                    $femaleTotal += $quantity;
                }

                $response .= 'Female Total: ' . number_format($femaleTotal) . "\n\n";
            }

            $maleTotal = 0;

            if (!empty($maleRows)) {
                $response .= "Male\n";

                foreach ($maleRows as $row) {
                    $sizeCode = trim($row['size_code'] ?? '');
                    $quantity = (int)($row['quantity'] ?? 0);
                    $displaySize = $this->getDisplaySize($sizeCode);

                    if ($displaySize === '') {
                        $displaySize = 'Stock';
                    }

                    $response .= '- ' . $displaySize . ': ' . number_format($quantity) . "\n";
                    $maleTotal += $quantity;
                }

                $response .= 'Male Total: ' . number_format($maleTotal) . "\n\n";
            }

            $otherTotal = 0;

            foreach ($otherRows as $row) {
                $quantity = (int)($row['quantity'] ?? 0);
                $displaySize = $this->getDisplaySize($row['size_code'] ?? '');

                if ($displaySize === '') {
                    $displaySize = 'Stock';
                }

                $response .= '- ' . $displaySize . ': ' . number_format($quantity) . "\n";
                $otherTotal += $quantity;
            }

            $itemTotal = $femaleTotal + $maleTotal + $otherTotal;

            if ($otherTotal > 0) {
                $response .= 'Other Total: ' . number_format($otherTotal) . "\n";
            }

            if ($filters['availability'] === 'low_stock') {
                $response .= 'Total Low Stock: ' . number_format($itemTotal) . "\n\n";
            } else if ($filters['availability'] === 'available') {
                $response .= 'Total Available: ' . number_format($itemTotal) . "\n\n";
            } else if ($filters['availability'] === 'out_of_stock') {
                $response .= 'Total Out of Stock: ' . number_format($itemTotal) . "\n\n";
            } else {
                $response .= 'Total: ' . number_format($itemTotal) . "\n\n";
            }
        }

        $overallTotal = $this->calculateInventoryTotal($inventory);
        $gender = $this->normalizeGender($filters['gender'] ?? '');

        if ($filters['availability'] === 'low_stock') {
            $response .= 'Total Low Stock: ' . number_format($overallTotal);
        } else if ($filters['availability'] === 'available') {
            $response .= 'Total Available: ' . number_format($overallTotal);
        } else if ($filters['availability'] === 'out_of_stock') {
            $response .= 'Total Out of Stock: ' . number_format($overallTotal);
        } else if ($gender === 'male') {
            $response .= 'Total Male Stock: ' . number_format($overallTotal);
        } else if ($gender === 'female') {
            $response .= 'Total Female Stock: ' . number_format($overallTotal);
        } else {
            $response .= 'Total Stock: ' . number_format($overallTotal);
        }

        return trim($response);
    }

    private function getGreetingResponse() {
        return 'Hello! How can I help you with the inventory?';
    }

    private function getHelpResponse() {
        return 'You can ask me about stock quantities, available items, low stocks, out-of-stock items, sizes, or specific inventory items.';
    }

    private function finishChatResponse($reply, $context = null) {
        $reply = trim($reply);

        $this->saveConversation('assistant', $reply);

        if ($context !== null) {
            $this->saveInventoryContext($context);
        }

        return [
            'status' => true,
            'reply' => $reply
        ];
    }

    public function processChat($message) {
        $message = trim($message);

        if ($message === '') {
            return [
                'status' => false,
                'reply' => 'Please enter a message.'
            ];
        }

        $context = $this->getInventoryContext();
        $history = $this->getConversationHistory();

        $this->saveConversation('user', $message);

        $ollama = $this->analyzeInventoryRequest($message, $context, $history);

        if (!$ollama['success']) {
            return [
                'status' => false,
                'reply' => 'Unable to connect to the chatbot.',
                'error' => $ollama['error']
            ];
        }

        $ai = $this->parseOllamaResponse($ollama['content']);
        $type = strtolower(trim($ai['type'] ?? 'general'));

        if ($type === 'greeting') {
            return $this->finishChatResponse($this->getGreetingResponse(), $context);
        }

        if ($type === 'help') {
            return $this->finishChatResponse($this->getHelpResponse(), $context);
        }

        if ($type === 'general') {
            $reply = trim($ai['response'] ?? '');

            if ($reply === '') {
                $reply = 'How can I help you?';
            }

            return $this->finishChatResponse($reply, $context);
        }

        if ($type === 'chat') {
            $reply = trim($ai['response'] ?? '');

            if ($reply === '') {
                $reply = 'How can I help you?';
            }

            return $this->finishChatResponse($reply, $context);
        }

        if ($type === 'inventory') {
            $filters = [
                'item_name' => trim($ai['item_name'] ?? ''),
                'gender' => $this->normalizeGender($ai['gender'] ?? ''),
                'size' => $this->normalizeSize($ai['size'] ?? ''),
                'availability' => $this->normalizeAvailability($ai['availability'] ?? 'all'),
                'response_type' => $this->normalizeResponseType($ai['response_type'] ?? 'total')
            ];

            $this->saveInventoryContext($filters);

            $inventory = $this->inventory->getInventory();
            $filteredInventory = $this->filterInventory($inventory, $filters);

            if ($filters['response_type'] === 'list') {
                $reply = $this->buildInventoryList($filteredInventory, $filters);
            } else {
                $reply = $this->buildInventoryTotal($filteredInventory, $filters);
            }

            $naturalResponse = $this->generateNaturalResponse($message, $filters, $reply);

            return $this->finishChatResponse($naturalResponse, $filters);
        }

        return $this->finishChatResponse('How can I help you with the inventory?', $context);
    }

    public function newChat() {
        $_SESSION['conversation'] = [];
        $_SESSION['inventory_context'] = $this->getDefaultInventoryContext();

        return [
            'status' => true,
            'reply' => 'New chat started.'
        ];
    }
}

$chatbot = new InventoryChatbot();

if (isset($_GET['action']) && $_GET['action'] === 'get_history') {
    echo json_encode([
        'status' => true,
        'history' => $chatbot->getConversationHistory()
    ]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'new_chat') {
    echo json_encode($chatbot->newChat());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = $_POST['message'] ?? '';
    echo json_encode($chatbot->processChat($message));
    exit;
}

echo json_encode([
    'status' => false,
    'reply' => 'Invalid request.'
]);