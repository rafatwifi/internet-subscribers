<?php

$aesFile = __DIR__ . '/aes.php';
if (is_file($aesFile)) {
    require_once $aesFile;
}

if (!class_exists('SASConnector')) {
class SASConnector
{
    private $host;
    private $username;
    private $password;
    private $portal;
    private $base_url;
    private $aes;
    private $token;
    private $loginUser = null;
    private $timeout = 45;
    private $lastError = '';
    private $lastDebug = array();

    private $secretKey = 'abcdefghijuklmno0123456789012345';

    public function __construct($host, $username, $password, $portal = 'acp')
    {
        $this->aes = new AESController();
        $this->username = $username;
        $this->password = $password;
        $this->host = $this->normalizeHost($host);
        $this->portal = $portal;

        $p = ($portal === 'ucp') ? 'user' : 'admin';
        $this->base_url = 'https://' . $this->host . '/' . $p . '/api/index.php/api/';
    }

    private function normalizeHost($host)
    {
        $host = trim((string) $host);
        $host = preg_replace('#^https?://#i', '', $host);
        $host = preg_replace('#/.*$#', '', $host);
        return rtrim($host, '/');
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    public function getLastDebug()
    {
        return $this->lastDebug;
    }

    public function getBaseUrl()
    {
        return $this->base_url;
    }

    public function post($route, $payload, $withAuth = true)
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            return array(
                '__json_error' => true,
                'message' => json_last_error_msg(),
            );
        }

        $e_json = $this->aes->encrypt($json, $this->secretKey);
        $origin = 'https://' . $this->host;

        $headers = array(
            'Accept: application/json, text/plain, */*',
            'Content-Type: application/json',
            'Accept-Language: en-US,en;q=0.9',
            'Origin: ' . $origin,
            'Referer: ' . $origin . '/',
            'User-Agent: Mozilla/5.0 (compatible; WiFi-Net-SALES/1.0)',
        );

        if ($withAuth) {
            if (!$this->token && !$this->login()) {
                return array(
                    '__auth_error' => true,
                    'message' => 'SAS login failed',
                );
            }
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $bodyJson = json_encode(array('payload' => $e_json));
        $res = $this->curlRequest('POST', $this->base_url . $route, $bodyJson, $headers);

        if (isset($res['__curl_error'])) {
            return $res;
        }

        if ($res['status'] >= 200 && $res['status'] < 400) {
            return $res['body'];
        }

        return array(
            '__http_error' => true,
            'status' => $res['status'],
            'body' => $res['body'],
            'route' => $route,
            'payload_sent' => $payload,
        );
    }

    public function get($route, $withAuth = true)
    {
        $headers = array(
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (compatible; WiFi-Net-SALES/1.0)',
        );

        if ($withAuth) {
            if (!$this->token && !$this->login()) {
                return array(
                    '__auth_error' => true,
                    'message' => 'SAS login failed',
                );
            }
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $res = $this->curlRequest('GET', $this->base_url . $route, null, $headers);

        if (isset($res['__curl_error'])) {
            return $res;
        }

        if ($res['status'] >= 200 && $res['status'] < 400) {
            return $res['body'];
        }

        return array(
            '__http_error' => true,
            'status' => $res['status'],
            'body' => $res['body'],
        );
    }

    private function curlRequest($method, $url, $body, $headers)
    {
        if (!function_exists('curl_init')) {
            return array(
                '__curl_error' => true,
                'message' => 'PHP cURL extension is required',
            );
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $timeout = isset($this->timeout) ? (int) $this->timeout : 45;
        if ($timeout < 3) {
            $timeout = 3;
        }
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(15, $timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        if (defined('CURL_SSLVERSION_TLSv1_2')) {
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            return array(
                '__curl_error' => true,
                'message' => $err !== '' ? $err : 'cURL request failed',
            );
        }

        return array(
            'status' => $status,
            'body' => $responseBody,
        );
    }

    public function login()
    {
        $this->lastError = '';
        $this->lastDebug = array(
            'url' => $this->base_url . (($this->portal === 'ucp') ? 'auth/login' : 'login'),
            'host' => $this->host,
            'username' => $this->username,
        );

        $payload = array(
            'username' => $this->username,
            'password' => $this->password,
        );

        $route = ($this->portal === 'ucp') ? 'auth/login' : 'login';
        $res = $this->post($route, $payload, false);

        if (is_array($res)) {
            $this->lastError = $this->describeErrorArray($res);
            $this->lastDebug['response_type'] = 'error_array';
            $this->lastDebug['http_status'] = isset($res['status']) ? $res['status'] : null;
            $this->lastDebug['body_snippet'] = $this->snippet(isset($res['body']) ? $res['body'] : (isset($res['message']) ? $res['message'] : ''));
            return false;
        }

        if (!is_string($res) || trim($res) === '') {
            $this->lastError = 'SAS رجع رد فارغ من مسار الدخول';
            $this->lastDebug['response_type'] = 'empty';
            return false;
        }

        $this->lastDebug['body_snippet'] = $this->snippet($res);
        $t = json_decode($res, true);
        if (!is_array($t)) {
            $this->lastError = 'رد الدخول ليس JSON صالح';
            $this->lastDebug['json_error'] = json_last_error_msg();
            return false;
        }

        if (!empty($t['payload'])) {
            try {
                $decrypted = $this->aes->decrypt($t['payload'], $this->secretKey);
                $decoded = json_decode($decrypted, true);
                if (is_array($decoded)) {
                    $t = $decoded;
                    $this->lastDebug['decrypted_keys'] = array_keys($t);
                } else {
                    $this->lastError = 'فشل فك تشفير رد الدخول';
                    return false;
                }
            } catch (Exception $e) {
                $this->lastError = 'فشل فك التشفير: ' . $e->getMessage();
                return false;
            }
        } else {
            $this->lastDebug['response_keys'] = array_keys($t);
        }

        $token = $this->extractToken($t);
        if ($token !== '') {
            $this->token = $token;
            if (isset($t['user']) && is_array($t['user'])) {
                $this->loginUser = $t['user'];
            } elseif (isset($t['manager']) && is_array($t['manager'])) {
                $this->loginUser = $t['manager'];
            } elseif (isset($t['data']) && is_array($t['data']) && !isset($t['data'][0])) {
                $this->loginUser = $t['data'];
            } else {
                $this->loginUser = $t;
            }
            return true;
        }

        $msg = '';
        if (!empty($t['message'])) {
            $msg = is_string($t['message']) ? $t['message'] : json_encode($t['message']);
        } elseif (!empty($t['error'])) {
            $msg = is_string($t['error']) ? $t['error'] : json_encode($t['error']);
        } elseif (!empty($t['status'])) {
            $msg = 'status=' . $t['status'];
        }
        $this->lastError = $msg !== ''
            ? ('SAS رفض الدخول: ' . $msg)
            : 'SAS رد بدون token — غالباً يوزر/باسورد غلط';
        return false;
    }

    private function extractToken($t)
    {
        if (!is_array($t)) {
            return '';
        }
        $keys = array('token', 'Token', 'access_token', 'accessToken', 'jwt');
        foreach ($keys as $k) {
            if (!empty($t[$k]) && is_string($t[$k])) {
                return $t[$k];
            }
        }
        if (isset($t['data']) && is_array($t['data'])) {
            return $this->extractToken($t['data']);
        }
        if (isset($t['user']) && is_array($t['user'])) {
            return $this->extractToken($t['user']);
        }
        return '';
    }

    private function describeErrorArray($res)
    {
        if (isset($res['__curl_error'])) {
            return 'cURL: ' . (isset($res['message']) ? $res['message'] : 'request failed');
        }
        if (isset($res['__http_error'])) {
            $st = isset($res['status']) ? (int) $res['status'] : 0;
            $body = $this->snippet(isset($res['body']) ? $res['body'] : '');
            return 'HTTP ' . $st . ($body !== '' ? (' — ' . $body) : '');
        }
        if (isset($res['__auth_error']) || isset($res['__json_error']) || isset($res['__exception'])) {
            return isset($res['message']) ? (string) $res['message'] : 'SAS request error';
        }
        return 'خطأ SAS غير معروف';
    }

    private function snippet($text)
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));
        if (strlen($text) > 240) {
            $text = substr($text, 0, 240) . '…';
        }
        return $text;
    }

    public function getProfiles()
    {
        if (!$this->token && !$this->login()) {
            return array();
        }
        return $this->parseApiResponse($this->get('list/profile/0', true));
    }

    public function getManagers()
    {
        if (!$this->token && !$this->login()) {
            return array();
        }

        $dtParams = array(
            'draw' => 1,
            'start' => 0,
            'length' => 1000,
            'search' => array('value' => '', 'regex' => false),
        );

        $res = $this->post('index/manager', $dtParams, true);
        if (is_string($res) && $res !== '') {
            $data = $this->parseApiResponse($res);
            if (!empty($data)) {
                return $data;
            }
        }

        return $this->parseApiResponse($this->get('index/manager?page=1&limit=1000', true));
    }

    public function getManagerById($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return array();
        }
        if (!$this->token && !$this->login()) {
            return array();
        }
        return $this->parseApiResponse($this->get('manager/' . $id, true));
    }

    public function createUser($payload)
    {
        if (!$this->token && !$this->login()) {
            return array('__auth_error' => true, 'message' => 'SAS login failed');
        }
        return $this->parseApiResponse($this->post('user', $payload, true));
    }

    public function activateUserCredit($username, $profileId, $units = 1)
    {
        if (!$this->token && !$this->login()) {
            return array('__auth_error' => true, 'message' => 'SAS login failed');
        }

        $payload = array(
            'username' => (string) $username,
            'profile_id' => (int) $profileId,
            'units' => max(1, (int) $units),
        );

        return $this->parseApiResponse($this->post('user/activate/credit', $payload, true));
    }

    public function setTimeout($seconds)
    {
        $this->timeout = max(3, (int) $seconds);
    }

    public function getLoginUser()
    {
        if (!$this->token && !$this->login()) {
            return null;
        }
        return is_array($this->loginUser) ? $this->loginUser : null;
    }

    public function getJwtPayload()
    {
        if (!$this->token && !$this->login()) {
            return null;
        }
        $parts = explode('.', (string) $this->token);
        if (count($parts) < 2) {
            return null;
        }
        $b64 = strtr($parts[1], '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $json = base64_decode($b64);
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    public function getUserById($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return array();
        }
        if (!$this->token && !$this->login()) {
            return array();
        }
        return $this->parseApiResponse($this->get('user/' . $id, true));
    }

    public function getAllowedExtensions($profileId)
    {
        $profileId = (int) $profileId;
        if ($profileId <= 0) {
            return array();
        }
        if (!$this->token && !$this->login()) {
            return array();
        }
        return $this->normalizeUserList(
            $this->parseApiResponse($this->get('allowedExtensions/' . $profileId, true))
        );
    }

    public function getExtensionData($userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return array();
        }
        if (!$this->token && !$this->login()) {
            return array();
        }
        return $this->parseApiResponse($this->get('user/extensionData/' . $userId, true));
    }

    public function getActivationData($userId)
    {
        $userId = (int) $userId;
        if ($userId < 0) {
            return array();
        }
        if (!$this->token && !$this->login()) {
            return array();
        }
        return $this->parseApiResponse($this->get('user/activationData/' . $userId, true));
    }

    public function getDashboardManager()
    {
        if (!$this->token && !$this->login()) {
            return array();
        }
        return $this->parseApiResponse($this->post('dashboardManager', array(), true));
    }

    public function getFirstUser()
    {
        if (!$this->token && !$this->login()) {
            return null;
        }
        $payload = array(
            'draw' => 1,
            'start' => 0,
            'length' => 1,
            'search' => array('value' => '', 'regex' => false),
        );
        $rows = $this->normalizeUserList($this->parseApiResponse($this->post('index/user', $payload, true)));
        return (isset($rows[0]) && is_array($rows[0])) ? $rows[0] : null;
    }

    /**
     * تمديد عبر نقاط تشجيعية أو رصيد المدير — بروفايل Extension فقط
     */
    public function extendUserService($userId, $extendProfileId, $method)
    {
        if (!$this->token && !$this->login()) {
            return array('__auth_error' => true, 'message' => 'SAS login failed');
        }

        $method = ($method === 'credit') ? 'credit' : 'reward_points';
        $userId = (int) $userId;
        $extendProfileId = (int) $extendProfileId;
        if ($userId <= 0) {
            return array('message' => 'ماكو user_id في SAS', 'status' => -1);
        }
        if ($extendProfileId <= 0) {
            return array('message' => 'ماكو بروفايل تمديد (Extension) في SAS', 'status' => -12);
        }

        $payload = array(
            'user_id' => $userId,
            'profile_id' => $extendProfileId,
            'method' => $method,
            'transaction_id' => uniqid('ext', true),
        );
        $raw = $this->post('user/extend', $payload, true);
        $res = $this->parseApiResponse($raw);
        if ($this->isActivateOk($res)) {
            return $res;
        }
        if (!is_array($res)) {
            $res = array('message' => 'SAS extend failed');
        }
        $base = isset($res['message']) ? trim((string) $res['message']) : '';
        $res['message'] = ($base !== '' ? ($base . ' — ') : '')
            . 'user/extend method=' . $method
            . ' user_id=' . $userId
            . ' profile_id=' . $extendProfileId
            . $this->httpTag($raw, $res);
        return $res;
    }

    private function httpTag($raw, $res)
    {
        if (is_array($raw) && isset($raw['status'])) {
            return ' HTTP ' . (int) $raw['status'];
        }
        if (is_array($res) && isset($res['status']) && is_numeric($res['status'])) {
            return ' status=' . (int) $res['status'];
        }
        return '';
    }

    private function isActivateOk($res)
    {
        if (!is_array($res)) {
            return false;
        }
        if (isset($res['__http_error']) || isset($res['__exception']) || isset($res['__auth_error'])
            || isset($res['__curl_error']) || isset($res['__decrypt_error'])) {
            return false;
        }
        if (isset($res['success']) && ($res['success'] === false || $res['success'] === 0 || $res['success'] === '0')) {
            return false;
        }
        if (isset($res['status'])) {
            $st = $res['status'];
            if (is_numeric($st) && (int) $st !== 200) {
                return false;
            }
            if (in_array(strtolower((string) $st), array('error', 'fail', 'failed'), true)) {
                return false;
            }
        }
        return true;
    }

    public function findUserByUsername($username)
    {
        if (!$this->token && !$this->login()) {
            return null;
        }

        $username = (string) $username;
        $payload = array(
            'draw' => 1,
            'start' => 0,
            'length' => 50,
            'search' => array('value' => $username, 'regex' => false),
            'order' => array(array('column' => 0, 'dir' => 'desc')),
        );

        $rows = $this->normalizeUserList($this->parseApiResponse($this->post('index/user', $payload, true)));
        $found = $this->matchUserRow($rows, $username);
        if ($found) {
            return $found;
        }

        $digits = preg_replace('/\D+/', '', $username);
        if ($digits !== '' && $digits !== $username) {
            $payload['search']['value'] = $digits;
            $rows = $this->normalizeUserList($this->parseApiResponse($this->post('index/user', $payload, true)));
            $found = $this->matchUserRow($rows, $username);
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    private function normalizeUserList($rows)
    {
        if (!is_array($rows) || isset($rows['__http_error']) || isset($rows['__exception'])
            || isset($rows['__auth_error']) || isset($rows['__curl_error'])) {
            return array();
        }
        if (isset($rows[0]) && is_array($rows[0])) {
            return $rows;
        }
        foreach (array('data', 'aaData', 'rows', 'users', 'extensions', 'profiles', 'allowedExtensions', 'items') as $k) {
            if (isset($rows[$k]) && is_array($rows[$k])) {
                return $this->normalizeUserList($rows[$k]);
            }
        }
        return array();
    }

    private function matchUserRow($rows, $username)
    {
        $want = strtolower(trim((string) $username));
        $wantDigits = preg_replace('/\D+/', '', $want);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $u = isset($row['username']) ? strtolower(trim((string) $row['username'])) : '';
            if ($u === '') {
                continue;
            }
            if ($u === $want) {
                return $row;
            }
            $uDigits = preg_replace('/\D+/', '', $u);
            if ($wantDigits !== '' && $uDigits !== '' && $uDigits === $wantDigits) {
                return $row;
            }
        }
        return null;
    }

    private function parseApiResponse($response)
    {
        if (is_array($response)) {
            if (isset($response['__http_error'])) {
                $body = isset($response['body']) ? $response['body'] : '';
                $decoded = is_string($body) ? json_decode($body, true) : null;
                if (is_array($decoded) && !empty($decoded['payload'])) {
                    try {
                        $decrypted = $this->aes->decrypt($decoded['payload'], $this->secretKey);
                        $inner = json_decode($decrypted, true);
                        if (is_array($inner) && isset($inner['message']) && (string) $inner['message'] !== '') {
                            $response['message'] = (string) $inner['message'];
                        }
                    } catch (Exception $e) {
                        // تجاهل
                    }
                } elseif (is_array($decoded) && isset($decoded['message']) && (string) $decoded['message'] !== '') {
                    $response['message'] = is_string($decoded['message'])
                        ? $decoded['message']
                        : json_encode($decoded['message']);
                }
                if (!isset($response['message']) || (string) $response['message'] === '') {
                    $st = isset($response['status']) ? (int) $response['status'] : 0;
                    $route = isset($response['route']) ? (string) $response['route'] : '';
                    $hint = 'HTTP ' . $st;
                    if ($route !== '') {
                        $hint .= ' (' . $route . ')';
                    }
                    if ($st === 403) {
                        $hint .= ' — ماكو صلاحية تست في حساب SAS';
                    } elseif ($st === 404) {
                        $hint .= ' — المسار غير موجود';
                    } elseif ($st === 400 || $st === 422) {
                        $hint .= ' — طلب مرفوض (رصيد تست صفر أو بيانات ناقصة)';
                    }
                    $response['message'] = $hint;
                }
                return $response;
            }
            if (isset($response['__exception']) || isset($response['__auth_error']) || isset($response['__curl_error'])) {
                return $response;
            }
            $data = $response;
        } else {
            if (!is_string($response) || trim($response) === '') {
                return array();
            }
            $data = json_decode($response, true);
        }

        if (is_array($data) && !empty($data['payload'])) {
            try {
                $decrypted = $this->aes->decrypt($data['payload'], $this->secretKey);
                if ($decrypted !== false && $decrypted !== null) {
                    $decoded = json_decode($decrypted, true);
                    if (is_array($decoded)) {
                        $data = $decoded;
                    }
                }
            } catch (Exception $e) {
                return array(
                    '__decrypt_error' => true,
                    'message' => $e->getMessage(),
                );
            }
        }

        if (!is_array($data)) {
            return array();
        }

        if (isset($data['status']) && is_numeric($data['status']) && (int) $data['status'] !== 200) {
            return $data;
        }

        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }
        if (isset($data['aaData']) && is_array($data['aaData'])) {
            return $data['aaData'];
        }
        if (isset($data['rows']) && is_array($data['rows'])) {
            return $data['rows'];
        }

        return $data;
    }
}
}
