<?php
// includes/SMSManager.php
class SMSManager {
    private $apiKey;
    private $senderNumber;
    private $isActive;
    private $apiUrl;

    public function __construct($db) {
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_group = 'general'");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        $this->apiKey       = trim($settings['sms_api_key'] ?? '');
        $this->senderNumber = trim($settings['sms_sender'] ?? '');
        $this->isActive     = ($settings['sms_status'] ?? 0) == 1;
        
        $rawUrl = trim($settings['sms_api_url'] ?? '');
        $this->apiUrl = !empty($rawUrl) ? $rawUrl : 'https://api.iranpayamak.com/ws/v1/sms/simple';
        
        if (empty($this->senderNumber)) {
            $this->senderNumber = '90008361';
        }
    }

    public function send($recipients, $message) {
        if (!$this->isActive) {
            return ['success' => false, 'error' => 'سرویس پیامک غیرفعال است.'];
        }
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'کلید API تنظیم نشده است.'];
        }
        if (empty($message)) {
            return ['success' => false, 'error' => 'متن پیامک خالی است.'];
        }

        $numbers = is_array($recipients) ? $recipients : [$recipients];
        foreach ($numbers as &$num) {
            $num = preg_replace('/[^0-9]/', '', $num);
            if (substr($num, 0, 2) == '98') {
                $num = '0' . substr($num, 2);
            }
            if (strlen($num) != 11) {
                $num = null;
            }
        }
        $numbers = array_filter($numbers);
        if (empty($numbers)) {
            return ['success' => false, 'error' => 'شماره موبایل نامعتبر است.'];
        }

        // ساختار صحیح بر اساس تست موفق
        $payload = [
            'line_number'   => $this->senderNumber,
            'recipients'    => $numbers,
            'text'          => $message,
            'number_format' => 'persian'   // ← مقدار صحیح
        ];

        $ch = curl_init($this->apiUrl);
        if ($ch === false) {
            return ['success' => false, 'error' => 'خطا در مقداردهی cURL'];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Api-Key: ' . $this->apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL: ' . $curlError];
        }

        $decoded = json_decode($response, true);

        if ($httpCode == 200 && isset($decoded['status']) && $decoded['status'] == 'success') {
            return [
                'success'    => true,
                'message_id' => $decoded['data']['id'] ?? null
            ];
        } else {
            $errorMsg = "HTTP $httpCode - " . ($decoded['message'] ?? $decoded['error'] ?? $response);
            return [
                'success'      => false,
                'error'        => $errorMsg,
                'raw_response' => $response
            ];
        }
    }

    public function isAvailable() {
        return $this->isActive && !empty($this->apiKey);
    }
}
?>