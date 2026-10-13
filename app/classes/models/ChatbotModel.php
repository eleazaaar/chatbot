<?php

if (!function_exists('fileExists')) {
    function fileExists($file="") {
        for ($i=0; $i<10 && !file_exists($file); $i++) {
            $file = "../" . $file;
        } return $file;
    }
}

include_once fileExists('app/classes/models/Extras.php');

class ChatbotModel extends Extras {
    protected $db;

    public function __construct() {
        parent::__construct();
    }

    public function getDefaultInventoryContext() {
        return [
            'item_name' => '',
            'gender' => '',
            'size' => '',
            'availability' => 'all',
            'response_type' => 'total'
        ];
    }

    public function getInventoryContext() {
        if (!isset($_SESSION['inventory_context'])) {
            $_SESSION['inventory_context'] = $this->getDefaultInventoryContext();
        }

        return $_SESSION['inventory_context'];
    }
    public function saveInventoryContext($context) {
        $_SESSION['inventory_context'] = $context;
    }

    public function saveConversation($role, $message, $pdfUrl = '') {
        if (!isset($_SESSION['conversation'])) {
            $_SESSION['conversation'] = [];
        }

        $entry = ['role' => $role, 'message' => $message];
        if ($pdfUrl !== '') {
            $entry['pdf_url'] = $pdfUrl;
        }
        $_SESSION['conversation'][] = $entry;

        if (count($_SESSION['conversation']) > 10) {
            array_shift($_SESSION['conversation']);
        }
    }

    public function getConversationHistory() {
        return $_SESSION['conversation'] ?? [];
    }
}