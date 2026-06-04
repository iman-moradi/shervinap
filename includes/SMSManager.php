<?php
class SMSManager {
    private $apiKey;
    private $senderNumber;
    private $isActive;
    private $apiUrl;
    private $db;

    public function __construct($db) {
        $this->db = $db;
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

    private function prepareRecipients($recipients) {
        if (is_array($recipients)) {
            return json_encode($recipients);
        }
        return $recipients;
    }

    private function logSms($recipients, $message, $type, $ticket_no, $status, $errorMessage = null, $providerRefId = null) {
        $recipientsJson = $this->prepareRecipients($recipients);
        $stmt = $this->db->prepare("INSERT INTO sms_logs (recipients, message, type, ticket_no, status, error_message, provider_ref_id, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        return $stmt->execute([$recipientsJson, $message, $type, $ticket_no, $status, $errorMessage, $providerRefId]);
    }

    public function send($recipients, $message, $type = 'manual', $ticket_no = null) {
        if (!$this->isActive) {
            $this->logSms($recipients, $message, $type, $ticket_no, 'failed', 'سرویس پیامک غیرفعال است.');
            return ['success' => false, 'error' => 'سرویس پیامک غیرفعال است.'];
        }
        if (empty($this->apiKey)) {
            $this->logSms($recipients, $message, $type, $ticket_no, 'failed', 'کلید API تنظیم نشده است.');
            return ['success' => false, 'error' => 'کلید API تنظیم نشده است.'];
        }
        if (empty($message)) {
            $this->logSms($recipients, $message, $type, $ticket_no, 'failed', 'متن پیامک خالی است.');
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
            $this->logSms($recipients, $message, $type, $ticket_no, 'failed', 'شماره موبایل نامعتبر است.');
            return ['success' => false, 'error' => 'شماره موبایل نامعتبر است.'];
        }

        $payload = [
            'line_number'   => $this->senderNumber,
            'recipients'    => $numbers,
            'text'          => $message,
            'number_format' => 'persian'
        ];

        $ch = curl_init($this->apiUrl);
        if ($ch === false) {
            $this->logSms($numbers, $message, $type, $ticket_no, 'failed', 'خطا در مقداردهی cURL');
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
            $this->logSms($numbers, $message, $type, $ticket_no, 'failed', 'cURL: ' . $curlError);
            return ['success' => false, 'error' => 'cURL: ' . $curlError];
        }

        $decoded = json_decode($response, true);
        $isSuccess = ($httpCode == 200 && isset($decoded['status']) && $decoded['status'] == 'success');
        $status = $isSuccess ? 'sent' : 'failed';
        $errorMessage = $isSuccess ? null : ($decoded['message'] ?? $decoded['error'] ?? $response);
        $messageId = $isSuccess ? ($decoded['data']['id'] ?? null) : null;

        $this->logSms($numbers, $message, $type, $ticket_no, $status, $errorMessage, $messageId);

        if ($isSuccess) {
            return ['success' => true, 'message_id' => $messageId];
        } else {
            return ['success' => false, 'error' => "HTTP $httpCode - " . $errorMessage, 'raw_response' => $response];
        }
    }

    public function resend($logId) {
        $stmt = $this->db->prepare("SELECT * FROM sms_logs WHERE id = ?");
        $stmt->execute([$logId]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$log) {
            return ['success' => false, 'error' => 'رکورد یافت نشد.'];
        }

        $recipients = json_decode($log['recipients'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $recipients = [$log['recipients']];
        }

        $newRetryCount = ($log['retry_count'] ?? 0) + 1;
        $result = $this->send($recipients, $log['message'], $log['type'], $log['ticket_no']);

        $updateStmt = $this->db->prepare("UPDATE sms_logs SET retry_count = ?, status = ?, error_message = ?, sent_at = NOW() WHERE id = ?");
        $updateStmt->execute([$newRetryCount, $result['success'] ? 'sent' : 'failed', $result['success'] ? null : $result['error'], $logId]);

        return $result;
    }

    public function isAvailable() {
        return $this->isActive && !empty($this->apiKey);
    }
}
?>