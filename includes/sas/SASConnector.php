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
    public $lastOnlineListOk = false;

    private $secretKey = 'abcdefghijuklmno0123456789012345';
    private $reclaimUser = '';
    private $reclaimPass = '';

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

    public function post($route, $payload, $withAuth = true, $httpMethod = 'POST')
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
        $res = $this->curlRequest($httpMethod, $this->base_url . $route, $bodyJson, $headers);

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
        $origin = 'https://' . $this->host;
        $headers = array(
            'Accept: application/json, text/plain, */*',
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

        $methodUp = strtoupper((string) $method);
        if ($methodUp === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($methodUp === 'PUT' || $methodUp === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $methodUp);
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

    public function activateUserReward($username, $profileId, $units = 1, $userId = 0)
    {
        return $this->activateUserCredit($username, $profileId, $units, $userId, 'reward_points');
    }

    public function activateUserCredit($username, $profileId, $units = 1, $userId = 0, $payMethod = 'credit')
    {
        if (!$this->token && !$this->login()) {
            return array('__auth_error' => true, 'message' => 'SAS login failed');
        }

        $payMethod = ($payMethod === 'reward_points') ? 'reward_points' : 'credit';
        $username = (string) $username;
        $profileId = (int) $profileId;
        $units = max(1, (int) $units);
        $userId = (int) $userId;
        if ($userId <= 0) {
            $userId = $this->connectorUserId($this->findUserByUsername($username));
        }

        $beforeTs = $this->liveExpireTs($userId, $username);
        if ($userId > 0 && method_exists($this, 'getActivationData')) {
            $this->getActivationData($userId);
        }
        $payloads = array();
        if ($userId > 0) {
            $payloads[] = array(
                'id' => $userId,
                'user_id' => $userId,
                'username' => $username,
                'profile_id' => $profileId,
                'units' => $units,
                'from_expiration' => 0,
                'method' => $payMethod,
            );
            $payloads[] = array(
                'id' => $userId,
                'user_id' => $userId,
                'username' => $username,
                'profile_id' => $profileId,
                'units' => $units,
                'from_expiration' => 1,
                'from_expire' => 1,
                'method' => $payMethod,
            );
            $payloads[] = array(
                'id' => $userId,
                'user_id' => $userId,
                'username' => $username,
                'profile_id' => $profileId,
                'units' => $units,
                'from_expiration' => 1,
                'method' => 'manager_balance',
            );
            $payloads[] = array(
                'id' => $userId,
                'user_id' => $userId,
                'username' => $username,
                'profile_id' => $profileId,
                'units' => $units,
            );
            $payloads[] = array(
                'user_id' => $userId,
                'profile_id' => $profileId,
                'units' => $units,
                'from_expiration' => 1,
            );
            $payloads[] = array(
                'id' => $userId,
                'profile_id' => $profileId,
                'units' => $units,
            );
        }
        $payloads[] = array(
            'username' => $username,
            'profile_id' => $profileId,
            'units' => $units,
            'from_expiration' => 1,
        );
        $payloads[] = array(
            'username' => $username,
            'profile_id' => $profileId,
            'units' => $units,
        );

        if ($payMethod === 'reward_points') {
            $routes = array(
                'user/activate/reward_points',
                'user/activate/rewardPoints',
                'user/activate/points',
            );
            if ($userId > 0) {
                $routes[] = 'user/' . $userId . '/activate/reward_points';
            }
        } else {
            $routes = array(
                'user/activate/credit',
                'user/activate/managerBalance',
                'user/activate/manager_balance',
                'user/renew',
                'user/purchase',
            );
            if ($userId > 0) {
                $routes[] = 'user/' . $userId . '/activate/credit';
                $routes[] = 'user/' . $userId . '/renew';
            }
        }

        $last = array();
        foreach ($routes as $route) {
            foreach ($payloads as $payload) {
                $last = $this->postActivate($route, $payload);
                if ($this->isRouteMissing($last) || $this->isMethodNotAllowed($last)) {
                    break;
                }
                if ($this->isHardActivateFail($last)) {
                    return $last;
                }
                if ($this->isActivateOk($last)) {
                    $afterTs = $this->liveExpireTs($userId, $username);
                    if ($this->expireMoved($beforeTs, $afterTs)) {
                        $last['_verified'] = 1;
                        return $last;
                    }
                    continue;
                }
                $msg = isset($last['message']) ? strtolower((string) $last['message']) : '';
                if ($msg !== '' && strpos($msg, 'missing') === false && strpos($msg, 'required') === false
                    && strpos($msg, 'ناقص') === false) {
                    continue;
                }
            }
        }
        if (!is_array($last) || count($last) === 0) {
            $last = array();
        }
        $last['success'] = false;
        $hint = isset($last['message']) ? trim((string) $last['message']) : '';
        $last['message'] = ($hint !== '' ? ($hint . ' — ') : '')
            . 'الساس ما غيّر تاريخ الانتهاء بعد طلب التفعيل';
        return $last;
    }

    public function setTimeout($seconds)
    {
        $this->timeout = max(3, (int) $seconds);
    }

    public function setReclaimAccount($username, $password)
    {
        $this->reclaimUser = trim((string) $username);
        $this->reclaimPass = (string) $password;
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

    public function getFinanceDashboard()
    {
        if (!$this->token && !$this->login()) {
            return array();
        }
        foreach (array('advancedDashboard/finance', 'advancedDashboard/Finance') as $route) {
            foreach (array('get', 'post') as $how) {
                if ($how === 'get') {
                    $full = $this->decodeApiBody($this->get($route, true), false);
                } else {
                    $full = $this->decodeApiBody($this->post($route, array(), true), false);
                }
                if (!is_array($full) || isset($full['__http_error']) || isset($full['__auth_error'])
                    || isset($full['__curl_error'])) {
                    continue;
                }
                return $full;
            }
        }
        return array();
    }

    public function getCurrentManagerLive()
    {
        if (!$this->token && !$this->login()) {
            return array();
        }
        $login = is_array($this->loginUser) ? $this->loginUser : array();
        if (isset($login['user']) && is_array($login['user'])) {
            $login = $login['user'];
        } elseif (isset($login['manager']) && is_array($login['manager'])) {
            $login = $login['manager'];
        }
        $mid = 0;
        if (isset($login['id']) && is_numeric($login['id'])) {
            $mid = (int) $login['id'];
        }
        $uname = '';
        if (!empty($login['username'])) {
            $uname = trim((string) $login['username']);
        }
        if ($uname === '') {
            $uname = trim((string) $this->username);
        }
        $out = array();
        $want = strtolower($uname);

        if (method_exists($this, 'getFinanceDashboard')) {
            $fin = $this->getFinanceDashboard();
            if (is_array($fin) && $fin) {
                array_unshift($out, $fin);
                if (isset($fin['data']) && is_array($fin['data']) && !isset($fin['data'][0])) {
                    array_unshift($out, $fin['data']);
                }
            }
        }

        $push = function ($full) use (&$out) {
            if (!is_array($full) || isset($full['__http_error']) || isset($full['__auth_error'])
                || isset($full['__curl_error'])) {
                return;
            }
            $out[] = $full;
            if (isset($full['data']) && is_array($full['data'])) {
                if (!isset($full['data'][0])) {
                    $out[] = $full['data'];
                } elseif (is_array($full['data'][0])) {
                    $out[] = $full['data'][0];
                }
            }
            if (isset($full['manager']) && is_array($full['manager'])) {
                $out[] = $full['manager'];
            }
            if (isset($full['user']) && is_array($full['user']) && !isset($full['user'][0])) {
                $out[] = $full['user'];
            }
            if (isset($full['data']['manager']) && is_array($full['data']['manager'])) {
                $out[] = $full['data']['manager'];
            }
        };

        if ($mid > 0) {
            foreach (array('manager/' . $mid, 'manager/overview/' . $mid, 'manager/show/' . $mid) as $route) {
                $push($this->decodeApiBody($this->get($route, true), false));
                $push($this->decodeApiBody($this->post($route, array(), true), false));
            }
        }

        $payload = array(
            'page' => 1,
            'count' => 50,
            'sortBy' => 'username',
            'direction' => 'asc',
            'search' => $uname,
        );
        $idx = $this->decodeApiBody($this->post('index/manager', $payload, true), false);
        $rows = $this->normalizeUserList($idx);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $u = isset($row['username']) ? strtolower(trim((string) $row['username'])) : '';
            $rid = (isset($row['id']) && is_numeric($row['id'])) ? (int) $row['id'] : 0;
            if (($want !== '' && $u === $want) || ($mid > 0 && $rid === $mid)) {
                $out[] = $row;
            }
        }

        $dash = $this->decodeApiBody($this->post('dashboardManager', array(), true), false);
        $push($dash);

        return $out;
    }

    public function getFirstUser()
    {
        $page = $this->listUsersPage(0, 1, '');
        if (!empty($page['ok']) && isset($page['rows'][0]) && is_array($page['rows'][0])) {
            return $page['rows'][0];
        }
        return null;
    }

    /**
     * قائمة المستخدمين — صيغة SAS الرسمية: page / count / sortBy
     */
    public function listUsersPage($start, $length, $search = '', $parentId = 0)
    {
        if (!$this->token && !$this->login()) {
            return array(
                'ok' => false,
                'rows' => array(),
                'total' => 0,
                'filtered' => 0,
                'complete' => false,
                'via' => '',
                'message' => $this->lastError !== '' ? $this->lastError : 'SAS login failed',
            );
        }

        $length = max(10, (int) $length);
        $page = (int) floor(max(0, (int) $start) / $length) + 1;
        $payload = array(
            'page' => $page,
            'count' => $length,
            'sortBy' => 'username',
            'direction' => 'asc',
            'search' => (string) $search,
            'status' => '',
            'with_traffic' => 1,
        );
        $parentId = (int) $parentId;
        if ($parentId > 0) {
            $payload['parent_id'] = $parentId;
            $payload['manager_id'] = $parentId;
        }
        $full = $this->decodeApiBody($this->post('index/user', $payload, true), false);
        if (isset($full['__http_error']) || isset($full['__auth_error']) || isset($full['__curl_error'])
            || isset($full['__decrypt_error']) || isset($full['__exception']) || isset($full['__json_error'])) {
            return array(
                'ok' => false,
                'rows' => array(),
                'total' => 0,
                'filtered' => 0,
                'complete' => false,
                'via' => 'post:index/user',
                'message' => isset($full['message']) ? (string) $full['message'] : 'SAS list failed',
            );
        }

        $rows = $this->normalizeUserList($full);
        $meta = $full;
        if (isset($full['meta']) && is_array($full['meta'])) {
            $meta = array_merge($full, $full['meta']);
        }
        $total = 0;
        foreach (array('total', 'recordsTotal', 'recordsFiltered') as $k) {
            if (isset($meta[$k]) && is_numeric($meta[$k])) {
                $total = (int) $meta[$k];
                break;
            }
        }
        $lastPage = isset($meta['last_page']) ? (int) $meta['last_page'] : 0;
        $current = isset($meta['current_page']) ? (int) $meta['current_page'] : $page;
        $perPage = isset($meta['per_page']) ? (int) $meta['per_page'] : 0;
        if ($perPage <= 0) {
            $perPage = count($rows) > 0 ? count($rows) : $length;
        }
        if ($total <= 0) {
            $total = ($lastPage > 0) ? ($lastPage * $perPage) : count($rows);
        }
        if ($lastPage > 1) {
            $complete = $current >= $lastPage;
        } elseif ($lastPage === 1) {
            $complete = true;
        } elseif ($total > $perPage) {
            $complete = (($current - 1) * $perPage + count($rows)) >= $total;
        } else {
            $complete = count($rows) === 0 || count($rows) < $perPage;
        }
        if ($current <= 1 && count($rows) >= $perPage && $lastPage <= 1 && $total <= count($rows)) {
            $complete = false;
        }

        return array(
            'ok' => true,
            'rows' => $rows,
            'total' => $total,
            'filtered' => $total,
            'complete' => $complete,
            'page' => $current,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'via' => 'post:index/user page=' . $current,
            'message' => '',
        );
    }

    public function updateUser($userId, $fields)
    {
        $userId = (int) $userId;
        if ($userId <= 0 || (!$this->token && !$this->login())) {
            return array('message' => 'SAS user update failed', 'status' => -1);
        }
        if (!is_array($fields)) {
            $fields = array();
        }
        $current = $this->getUserById($userId);
        if (is_array($current) && !isset($current['__http_error']) && !isset($current['__auth_error'])) {
            if (isset($current['data']) && is_array($current['data']) && !isset($current['username'])) {
                $current = $current['data'];
            }
            $keep = array(
                'username', 'firstname', 'lastname', 'phone', 'email', 'city', 'company',
                'enabled', 'profile_id', 'parent_id',
            );
            foreach ($keep as $k) {
                if (!array_key_exists($k, $fields) && isset($current[$k]) && !is_array($current[$k])) {
                    $fields[$k] = $current[$k];
                }
            }
        }
        $fields['id'] = $userId;
        $fields['user_id'] = $userId;
        $last = array();
        $tries = array(
            array('user/' . $userId, 'PUT'),
            array('user/' . $userId, 'POST'),
            array('user/update', 'POST'),
            array('user', 'POST'),
        );
        foreach ($tries as $t) {
            $payload = $fields;
            if ($t[1] === 'POST' && strpos($t[0], 'user/') === 0) {
                $payload['_method'] = 'PUT';
            }
            $last = $this->parseApiResponse($this->post($t[0], $payload, true, $t[1]));
            if ($this->isActivateOk($last)) {
                return $last;
            }
        }
        return $last;
    }

    public function setUserExpiration($userId, $expireSql, $profileId = 0)
    {
        $userId = (int) $userId;
        $expireSql = trim((string) $expireSql);
        $profileId = (int) $profileId;
        if ($userId <= 0 || $expireSql === '') {
            return array('success' => false, 'message' => 'ماكو تاريخ تفعيل');
        }
        $beforeTs = $this->liveExpireTs($userId, '');
        $wantTs = strtotime($expireSql);
        $dateOnly = substr($expireSql, 0, 10);
        $ts = $wantTs ? $wantTs : 0;
        $payloads = array(
            array(
                'id' => $userId,
                'user_id' => $userId,
                'expiration' => $expireSql,
                'enabled' => 1,
            ),
            array(
                'id' => $userId,
                'user_id' => $userId,
                'expiration' => $dateOnly,
                'enabled' => 1,
            ),
            array(
                'id' => $userId,
                'expiration' => $expireSql,
                'expire_at' => $expireSql,
                'expiry' => $expireSql,
                'expiration_date' => $dateOnly,
                'acctexpiration' => $expireSql,
                'enabled' => 1,
            ),
        );
        if ($ts) {
            $payloads[] = array(
                'id' => $userId,
                'user_id' => $userId,
                'expiration' => $ts,
                'enabled' => 1,
            );
        }
        if ($profileId > 0) {
            $withProfile = array();
            foreach ($payloads as $p) {
                $p['profile_id'] = $profileId;
                $withProfile[] = $p;
            }
            $payloads = array_merge($withProfile, $payloads);
        }
        $routes = array(
            array('user/' . $userId, 'PUT'),
            array('user/' . $userId, 'PATCH'),
            array('user/update', 'POST'),
            array('user/' . $userId . '/expiration', 'PUT'),
            array('user/expiration', 'POST'),
            array('user/changeExpiration', 'POST'),
            array('user/setExpiration', 'POST'),
        );
        $last = array();
        foreach ($routes as $t) {
            foreach ($payloads as $payload) {
                $last = $this->parseApiResponse($this->post($t[0], $payload, true, $t[1]));
                if ($this->isRouteMissing($last) || $this->isMethodNotAllowed($last)) {
                    break;
                }
                $afterTs = $this->liveExpireTs($userId, '');
                if ($this->expireMoved($beforeTs, $afterTs)
                    || ($wantTs && $afterTs > 0 && abs($afterTs - $wantTs) < 120)) {
                    if (!is_array($last)) {
                        $last = array();
                    }
                    $last['_verified'] = 1;
                    return $last;
                }
            }
        }
        $merged = $this->updateUser($userId, array(
            'expiration' => $expireSql,
            'enabled' => 1,
            'profile_id' => $profileId,
        ));
        $afterTs = $this->liveExpireTs($userId, '');
        if ($this->expireMoved($beforeTs, $afterTs)
            || ($wantTs && $afterTs > 0 && abs($afterTs - $wantTs) < 120)) {
            if (!is_array($merged)) {
                $merged = array();
            }
            $merged['_verified'] = 1;
            return $merged;
        }
        return array(
            'success' => false,
            'message' => 'تعديل تاريخ الانتهاء بالساس ما نجح',
            'status' => -1,
        );
    }

    public function renameUser($userId, $oldUsername, $newUsername)
    {
        $userId = (int) $userId;
        $oldUsername = trim((string) $oldUsername);
        $newUsername = trim((string) $newUsername);
        if ($userId <= 0 || $newUsername === '' || (!$this->token && !$this->login())) {
            return array('message' => 'SAS rename failed', 'status' => -1);
        }
        if ($oldUsername === $newUsername || $oldUsername === '') {
            return array('success' => true);
        }
        $payload = array(
            'id' => $userId,
            'user_id' => $userId,
            'username' => $oldUsername,
            'old_username' => $oldUsername,
            'new_username' => $newUsername,
            'newUsername' => $newUsername,
        );
        $last = array();
        foreach (array('user/rename', 'user/changeUsername', 'user/' . $userId . '/rename') as $route) {
            $last = $this->parseApiResponse($this->post($route, $payload, true));
            if ($this->isActivateOk($last)) {
                return $last;
            }
        }
        return $this->updateUser($userId, array('username' => $newUsername));
    }

    public function setUserEnabled($userId, $enabled)
    {
        $userId = (int) $userId;
        $on = $enabled ? 1 : 0;
        $action = $on ? 'enable' : 'disable';
        $tries = array(
            array('user/' . $action, array('id' => $userId, 'user_id' => $userId, 'enabled' => $on)),
            array('user/' . $userId, array('id' => $userId, 'enabled' => $on)),
        );
        $last = array();
        foreach ($tries as $t) {
            $last = $this->parseApiResponse($this->post($t[0], $t[1], true));
            if ($this->isActivateOk($last)) {
                return $last;
            }
        }
        return $this->updateUser($userId, array('enabled' => $on));
    }

    /**
     * قطع جلسة المشترك الأونلاين (Disconnect) — يرجع يتصل لحاله.
     */
    public function disconnectUser($userId, $username = '')
    {
        $userId = (int) $userId;
        $username = trim((string) $username);
        if (!$this->token && !$this->login()) {
            return array('__auth_error' => true, 'message' => 'SAS login failed');
        }
        $payloads = array(
            array('user/disconnect', array('id' => $userId, 'user_id' => $userId, 'username' => $username)),
            array('user/kick', array('id' => $userId, 'user_id' => $userId, 'username' => $username)),
            array('user/drop', array('id' => $userId, 'user_id' => $userId, 'username' => $username)),
            array('user/' . $userId . '/disconnect', array('id' => $userId, 'username' => $username)),
            array('online/disconnect', array('id' => $userId, 'user_id' => $userId, 'username' => $username)),
            array('index/disconnect', array('id' => $userId, 'username' => $username)),
            array('index/disconnectUser', array('id' => $userId, 'username' => $username)),
            array('user/dropSession', array('id' => $userId, 'username' => $username)),
        );
        $last = array();
        foreach ($payloads as $t) {
            $last = $this->parseApiResponse($this->post($t[0], $t[1], true));
            if ($this->isActivateOk($last)) {
                return $last;
            }
        }
        return is_array($last) ? $last : array('message' => 'disconnect failed', 'status' => -1);
    }

    public function changeUserProfile($userId, $profileId)
    {
        $userId = (int) $userId;
        $profileId = (int) $profileId;
        $payload = array(
            'id' => $userId,
            'user_id' => $userId,
            'profile_id' => $profileId,
            'when' => 'immediate',
        );
        $last = array();
        foreach (array('user/changeProfile', 'user/change-profile', 'user/' . $userId . '/changeProfile') as $route) {
            $last = $this->parseApiResponse($this->post($route, $payload, true));
            if ($this->isActivateOk($last)) {
                return $last;
            }
        }
        return $this->updateUser($userId, array('profile_id' => $profileId));
    }

    private function sasCardPinValue($row)
    {
        if (!is_array($row)) {
            return '';
        }
        foreach (array('pin', 'pincode', 'pin_code', 'card_number', 'serialnumber', 'serial_number', 'card_pin') as $k) {
            if (!empty($row[$k]) && !is_array($row[$k])) {
                $v = trim((string) $row[$k]);
                if ($v === '' || strpos($v, '-') !== false) {
                    continue;
                }
                if (preg_match('/^\d{6,16}$/', $v) || ($k === 'pin' && preg_match('/^[A-Za-z0-9]{6,20}$/', $v))) {
                    return $v;
                }
            }
        }
        return '';
    }

    private function sasCardLooksLikeSeries($row)
    {
        if (!is_array($row)) {
            return false;
        }
        if (isset($row['quantity']) || isset($row['qty']) || isset($row['unused_count']) || isset($row['count_unused'])) {
            return true;
        }
        $pin = $this->sasCardPinValue($row);
        if ($pin === '' && (!empty($row['name']) || !empty($row['title']))) {
            return true;
        }
        return false;
    }

    private function sasLooksLikeRecordRow($row)
    {
        if (!is_array($row) || isset($row[0]) || isset($row['current_page']) || isset($row['last_page'])
            || isset($row['recordsTotal']) || isset($row['per_page'])) {
            return false;
        }
        return isset($row['id']) || isset($row['pin']) || isset($row['username'])
            || isset($row['quantity']) || isset($row['series']);
    }

    private function sasCardRowsFromDecoded($full)
    {
        if (!is_array($full) || isset($full['__http_error']) || isset($full['__auth_error'])
            || isset($full['__curl_error'])) {
            return array();
        }
        foreach (array('pins', 'cards', 'unused', 'items') as $k) {
            if (isset($full[$k]) && is_array($full[$k]) && isset($full[$k][0])) {
                return $full[$k];
            }
        }
        $got = $this->normalizeUserList($full);
        if ($got) {
            return $got;
        }
        if ($this->sasCardPinValue($full) !== '' || $this->sasLooksLikeRecordRow($full)) {
            return array($full);
        }
        return array();
    }

    private function sasCardIsUsed($row)
    {
        if (!is_array($row)) {
            return true;
        }
        if (!empty($row['qty']) || !empty($row['quantity'])) {
            return false;
        }
        if (isset($row['used']) && is_array($row['used']) && $row['used']) {
            return true;
        }
        if (isset($row['used']) && !is_array($row['used']) && $row['used'] !== '' && $row['used'] !== null) {
            if ($row['used'] === 1 || $row['used'] === '1' || $row['used'] === true) {
                return true;
            }
            if (is_numeric($row['used']) && (int) $row['used'] > 0) {
                return true;
            }
            if (!is_numeric($row['used']) && $row['used'] !== '0' && $row['used'] !== false) {
                return true;
            }
        }
        if (isset($row['is_used']) && ($row['is_used'] === 1 || $row['is_used'] === '1' || $row['is_used'] === true)) {
            return true;
        }
        foreach (array('used_at', 'usedAt', 'used_date', 'date_used', 'activated_at', 'used_time') as $k) {
            if (empty($row[$k]) || is_array($row[$k])) {
                continue;
            }
            $usedAt = trim((string) $row[$k]);
            if ($usedAt !== '' && $usedAt !== '0' && $usedAt !== '0000-00-00' && $usedAt !== '0000-00-00 00:00:00') {
                return true;
            }
        }
        foreach (array('used_by', 'usedBy', 'used_username', 'used_user') as $k) {
            if (!empty($row[$k]) && !is_array($row[$k])) {
                return true;
            }
        }
        if (isset($row['user_details']) && is_array($row['user_details'])) {
            if (!empty($row['user_details']['username']) && !is_array($row['user_details']['username'])) {
                return true;
            }
            if (isset($row['user_details']['id']) && is_numeric($row['user_details']['id'])
                && (int) $row['user_details']['id'] > 0) {
                return true;
            }
        }
        if (isset($row['user']) && is_array($row['user']) && !empty($row['user']['username'])
            && !is_array($row['user']['username'])) {
            return true;
        }
        if (!empty($row['username']) && !is_array($row['username'])) {
            $u = trim((string) $row['username']);
            if ($u !== '' && strpos($u, '@') === false) {
                return true;
            }
        }
        return false;
    }

    private function sasCardFetchList($routes, $payload)
    {
        $rows = array();
        foreach ($routes as $route) {
            $full = $this->decodeApiBody($this->post($route, $payload, true), false);
            if (isset($full['__http_error']) || isset($full['__auth_error']) || isset($full['__curl_error'])) {
                continue;
            }
            $got = $this->sasCardRowsFromDecoded($full);
            if ($got) {
                return $got;
            }
        }
        return $rows;
    }

    private function sasCardListMeta($full)
    {
        $meta = is_array($full) ? $full : array();
        if (isset($full['meta']) && is_array($full['meta'])) {
            $meta = array_merge($meta, $full['meta']);
        }
        if (isset($full['data']) && is_array($full['data']) && !isset($full['data'][0])
            && (isset($full['data']['current_page']) || isset($full['data']['last_page']) || isset($full['data']['total']))) {
            $meta = array_merge($meta, $full['data']);
        }
        return $meta;
    }

    private function sasCardFetchPaged($routes, $payload, $maxPages = 20)
    {
        $all = array();
        if (!is_array($payload)) {
            $payload = array();
        }
        if (!isset($payload['count'])) {
            $payload['count'] = 100;
        }
        $maxPages = max(1, (int) $maxPages);
        foreach ($routes as $route) {
            $payload['page'] = 1;
            $full = $this->decodeApiBody($this->post($route, $payload, true), false);
            if (isset($full['__http_error']) || isset($full['__auth_error']) || isset($full['__curl_error'])) {
                continue;
            }
            $got = $this->sasCardRowsFromDecoded($full);
            if (!$got) {
                continue;
            }
            $all = $got;
            $meta = $this->sasCardListMeta($full);
            $total = 0;
            foreach (array('total', 'recordsTotal', 'recordsFiltered') as $k) {
                if (isset($meta[$k]) && is_numeric($meta[$k])) {
                    $total = (int) $meta[$k];
                    break;
                }
            }
            $lastPage = isset($meta['last_page']) ? (int) $meta['last_page'] : 0;
            $perPage = isset($meta['per_page']) ? (int) $meta['per_page'] : 0;
            if ($perPage <= 0) {
                $perPage = count($got) > 0 ? count($got) : (int) $payload['count'];
            }
            if ($lastPage < 1 && $total > 0 && $perPage > 0) {
                $lastPage = (int) ceil($total / $perPage);
            }
            $page = 2;
            while ($page <= $maxPages) {
                if ($lastPage > 0 && $page > $lastPage) {
                    break;
                }
                if ($total > 0 && count($all) >= $total) {
                    break;
                }
                if ($lastPage < 2 && $total <= count($all) && count($got) < $perPage) {
                    break;
                }
                $payload['page'] = $page;
                $full2 = $this->decodeApiBody($this->post($route, $payload, true), false);
                $got2 = $this->sasCardRowsFromDecoded($full2);
                if (!$got2) {
                    break;
                }
                $all = array_merge($all, $got2);
                if (count($got2) < $perPage) {
                    break;
                }
                $page++;
            }
            if ($all) {
                return $all;
            }
        }
        return $all;
    }

    private function sasCardPinsFromSeries($seriesId, $profileId = 0, $seriesCode = '', $unusedOnly = true)
    {
        $seriesId = (int) $seriesId;
        $seriesCode = trim((string) $seriesCode);
        if ($seriesId <= 0 && $seriesCode === '') {
            return array();
        }
        $page = array(
            'page' => 1,
            'count' => $unusedOnly ? 100 : 120,
            'sortBy' => 'id',
            'direction' => 'desc',
            'search' => '',
        );
        $pageUnused = $page;
        $pageUnused['used'] = 0;
        $pageUsed = $page;
        $pageUsed['used'] = 1;
        $routes = array();
        if ($seriesCode !== '' && $this->sasLooksLikeSeriesCode($seriesCode)) {
            $routes[] = 'index/card/' . $seriesCode;
        }
        if ($seriesId > 0) {
            $routes[] = 'index/card/' . $seriesId;
        }
        $payloads = $unusedOnly ? array($pageUnused) : array($page, $pageUnused, $pageUsed);
        foreach ($payloads as $payload) {
            if ($routes) {
                $paged = $this->sasCardFetchPaged($routes, $payload, $unusedOnly ? 8 : 3);
                if ($this->sasCardListHasPin($paged)) {
                    // عند unusedOnly: لا نرجع قائمة عامة قد تخلط المستخدم مع الشاغر
                    if ($unusedOnly) {
                        $clean = array();
                        foreach ($paged as $pr) {
                            if (is_array($pr) && !$this->sasCardLooksLikeSeries($pr)
                                && $this->sasCardPinValue($pr) !== '' && !$this->sasCardIsUsed($pr)) {
                                $clean[] = $pr;
                            }
                        }
                        return $clean;
                    }
                    return $paged;
                }
            }
        }
        if ($unusedOnly) {
            return array();
        }
        $tries = array();
        if ($seriesCode !== '' && $this->sasLooksLikeSeriesCode($seriesCode)) {
            $tries[] = array('index/card/' . $seriesCode, $page);
        }
        if ($seriesId > 0) {
            $tries[] = array('index/card/' . $seriesId, $page);
            $tries[] = array('list/card/' . $seriesId, $page);
        }
        foreach ($tries as $t) {
            $full = $this->decodeApiBody($this->post($t[0], $t[1], true), false);
            $got = $this->sasCardRowsFromDecoded($full);
            if ($this->sasCardListHasPin($got)) {
                return $got;
            }
        }
        return array();
    }

    private function sasCardListHasPin($rows)
    {
        if (!is_array($rows) || !$rows) {
            return false;
        }
        foreach ($rows as $row) {
            if (is_array($row) && $this->sasCardPinValue($row) !== '') {
                return true;
            }
        }
        return false;
    }

    private function sasCardSeriesProfileId($row)
    {
        if (!is_array($row)) {
            return 0;
        }
        if (isset($row['profile_id']) && is_numeric($row['profile_id'])) {
            return (int) $row['profile_id'];
        }
        if (isset($row['profile_details']['id']) && is_numeric($row['profile_details']['id'])) {
            return (int) $row['profile_details']['id'];
        }
        if (isset($row['profile']) && is_numeric($row['profile']) && !is_array($row['profile'])) {
            return (int) $row['profile'];
        }
        if (isset($row['profile']['id']) && is_numeric($row['profile']['id'])) {
            return (int) $row['profile']['id'];
        }
        return 0;
    }

    private function sasCardSeriesName($row)
    {
        return $this->sasRowProfileName($row);
    }

    private function sasRowProfileName($row)
    {
        if (!is_array($row)) {
            return '';
        }
        foreach (array('profile_name', 'profileName', 'tariff_name', 'package_name') as $k) {
            if (!empty($row[$k]) && !is_array($row[$k])) {
                $v = trim((string) $row[$k]);
                if ($v !== '') {
                    return $v;
                }
            }
        }
        if (isset($row['profile_details']) && is_array($row['profile_details'])) {
            if (!empty($row['profile_details']['name']) && !is_array($row['profile_details']['name'])) {
                return trim((string) $row['profile_details']['name']);
            }
        }
        if (isset($row['profile'])) {
            if (is_array($row['profile']) && !empty($row['profile']['name']) && !is_array($row['profile']['name'])) {
                return trim((string) $row['profile']['name']);
            }
            if (!is_array($row['profile'])) {
                $v = trim((string) $row['profile']);
                if ($v !== '' && !is_numeric($v)) {
                    return $v;
                }
            }
        }
        return '';
    }

    private function sasLooksLikeSeriesCode($v)
    {
        $v = trim((string) $v);
        if ($v === '' || strpos($v, ' ') !== false) {
            return false;
        }
        if (preg_match('/^\d{4}-\d+$/', $v) || preg_match('/^\d+$/', $v)) {
            return true;
        }
        if (preg_match('/^[A-Za-z0-9._-]+-\d+$/', $v) && strlen($v) <= 40) {
            return true;
        }
        return false;
    }

    private function sasCardSeriesCode($row)
    {
        if (!is_array($row)) {
            return '';
        }
        foreach (array('series', 'series_name', 'series_code', 'seriesCode') as $k) {
            if (!empty($row[$k]) && !is_array($row[$k])) {
                $v = trim((string) $row[$k]);
                if ($this->sasLooksLikeSeriesCode($v)) {
                    return $v;
                }
            }
        }
        return '';
    }

    private function sasCardSeriesUnusedCount($row)
    {
        if (!is_array($row)) {
            return -1;
        }
        $total = null;
        $used = null;
        if (isset($row['qty']) && is_numeric($row['qty'])) {
            $total = (int) $row['qty'];
        } elseif (isset($row['quantity']) && is_numeric($row['quantity'])) {
            $total = (int) $row['quantity'];
        }
        if (array_key_exists('used', $row)) {
            if ($row['used'] === '' || $row['used'] === null || $row['used'] === false) {
                $used = 0;
            } elseif (is_numeric($row['used'])) {
                $used = (int) $row['used'];
            } elseif (is_array($row['used']) && !$row['used']) {
                $used = 0;
            }
        } elseif (isset($row['used_count']) && is_numeric($row['used_count'])) {
            $used = (int) $row['used_count'];
        }
        if ($total !== null && $used !== null) {
            return max(0, $total - $used);
        }
        return -1;
    }

    private function sasSeriesMatchesProfile($row, $profileId, $profileName)
    {
        $profileId = (int) $profileId;
        $profileName = trim((string) $profileName);
        if ($profileId <= 0 && $profileName === '') {
            return true;
        }
        $sPid = $this->sasCardSeriesProfileId($row);
        if ($profileId > 0 && $sPid > 0 && $sPid === $profileId) {
            return true;
        }
        if ($profileName === '') {
            return $profileId > 0 ? false : true;
        }
        $pn = $this->sasRowProfileName($row);
        if ($pn === '') {
            return false;
        }
        $norm = function ($s) {
            $s = strtolower(trim((string) $s));
            $s = preg_replace('/[\s_\-]+/', '', $s);
            $s = preg_replace('/msl$/', '', $s);
            return $s;
        };
        return $norm($pn) === $norm($profileName);
    }

    public function listUnusedCards($profileId = 0, $profileName = '')
    {
        if (!$this->token && !$this->login()) {
            return array();
        }
        $profileId = (int) $profileId;
        $profileName = trim((string) $profileName);
        $hasFilter = ($profileId > 0 || $profileName !== '');
        $routes = array('index/series');
        $payload = array(
            'page' => 1,
            'count' => 200,
            'sortBy' => 'series_date',
            'direction' => 'desc',
            'search' => '',
        );
        $series = $this->sasCardFetchPaged($routes, $payload, 8);

        $unused = array();
        foreach ($series as $srow) {
            if (!is_array($srow)) {
                continue;
            }
            if (!empty($srow['suspended']) && (string) $srow['suspended'] === '1') {
                continue;
            }
            $unusedHint = $this->sasCardSeriesUnusedCount($srow);
            if ($unusedHint === 0) {
                continue;
            }
            // بدون عدّاد واضح نجلب pins فعلياً بدون تضخيم وهمي
            if ($unusedHint < 0) {
                $unusedHint = 0;
            }
            $srow['_unused_hint'] = $unusedHint;
            $unused[] = $srow;
        }
        $prefer = array();
        $rest = array();
        foreach ($unused as $srow) {
            if ($this->sasSeriesMatchesProfile($srow, $profileId, $profileName)) {
                $prefer[] = $srow;
            } else {
                $rest[] = $srow;
            }
        }
        $ordered = $hasFilter ? ($prefer ? $prefer : array()) : array_merge($prefer, $rest);
        usort($ordered, function ($a, $b) {
            $ua = isset($a['_unused_hint']) ? (int) $a['_unused_hint'] : 0;
            $ub = isset($b['_unused_hint']) ? (int) $b['_unused_hint'] : 0;
            if ($ua !== $ub) {
                return ($ua > $ub) ? -1 : 1;
            }
            return 0;
        });

        $rows = array();
        $seriesTried = 0;
        foreach ($ordered as $srow) {
            $sPid = $this->sasCardSeriesProfileId($srow);
            $sName = $this->sasRowProfileName($srow);
            if ($sName === '' && $profileName !== '') {
                $sName = $profileName;
            }
            $sid = (isset($srow['id']) && is_numeric($srow['id'])) ? (int) $srow['id'] : 0;
            $scode = $this->sasCardSeriesCode($srow);
            $nested = array();
            // نجلب كل الـ pins ونصفّي الشاغر محلياً — فلتر used=0 من الساس يخلط المستخدمة
            if (($sid > 0 || $scode !== '') && $seriesTried < 40) {
                $seriesTried++;
                $nested = $this->sasCardPinsFromSeries($sid, $sPid > 0 ? $sPid : $profileId, $scode, false);
            }
            foreach ($nested as $pinRow) {
                if (!is_array($pinRow) || $this->sasCardLooksLikeSeries($pinRow)) {
                    continue;
                }
                $pin = $this->sasCardPinValue($pinRow);
                if ($pin === '' || strlen($pin) < 6 || $this->sasCardIsUsed($pinRow)) {
                    continue;
                }
                if (empty($pinRow['profile_name']) && $sName !== '') {
                    $pinRow['profile_name'] = $sName;
                }
                if ((empty($pinRow['profile_id']) || !is_numeric($pinRow['profile_id'])) && $sPid > 0) {
                    $pinRow['profile_id'] = $sPid;
                }
                $pinRow['_from_matched_series'] = 1;
                $rows[] = $pinRow;
            }
        }

        $out = array();
        $seen = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['qty']) || !empty($row['quantity']) || $this->sasCardLooksLikeSeries($row)) {
                continue;
            }
            $pin = $this->sasCardPinValue($row);
            if ($pin === '' || strlen($pin) < 6 || $this->sasCardIsUsed($row)) {
                continue;
            }
            $fromMatched = !empty($row['_from_matched_series']);
            if ($hasFilter && !$fromMatched && !$this->sasSeriesMatchesProfile($row, $profileId, $profileName)) {
                continue;
            }
            $key = strtolower($pin);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = 1;
            $out[] = $row;
        }
        usort($out, function ($a, $b) {
            $ia = isset($a['id']) ? (int) $a['id'] : 0;
            $ib = isset($b['id']) ? (int) $b['id'] : 0;
            if ($ia === $ib) {
                return 0;
            }
            return ($ia > $ib) ? -1 : 1;
        });
        return $out;
    }

    /**
     * عدّاد ويدجت الداشبورد: كروت شاغرة فقط، مجمّعة بالفئة.
     * يعتمد pins حقيقية (مو qty الوصفي) ويمر على أكبر السلاسل أولاً.
     */
    public function listDashUnusedCardGroups($maxSeries = 40)
    {
        if (!$this->token && !$this->login()) {
            return array();
        }
        $maxSeries = max(8, min(60, (int) $maxSeries));
        $payload = array(
            'page' => 1,
            'count' => 200,
            'sortBy' => 'series_date',
            'direction' => 'desc',
            'search' => '',
        );
        $series = $this->sasCardFetchPaged(array('index/series', 'index/card', 'index/cards', 'index/cardSeries'), $payload, 12);
        $candidates = array();
        foreach ($series as $srow) {
            if (!is_array($srow)) {
                continue;
            }
            if (!empty($srow['suspended']) && (string) $srow['suspended'] === '1') {
                continue;
            }
            $hint = $this->sasCardSeriesUnusedCount($srow);
            if ($hint === 0) {
                continue;
            }
            $srow['_unused_hint'] = ($hint > 0) ? $hint : 0;
            $candidates[] = $srow;
        }
        usort($candidates, function ($a, $b) {
            $ua = isset($a['_unused_hint']) ? (int) $a['_unused_hint'] : 0;
            $ub = isset($b['_unused_hint']) ? (int) $b['_unused_hint'] : 0;
            if ($ua !== $ub) {
                return ($ua > $ub) ? -1 : 1;
            }
            return 0;
        });

        $groups = array();
        $seenPin = array();
        $tried = 0;
        foreach ($candidates as $srow) {
            if ($tried >= $maxSeries) {
                break;
            }
            $sid = (isset($srow['id']) && is_numeric($srow['id'])) ? (int) $srow['id'] : 0;
            $scode = $this->sasCardSeriesCode($srow);
            if ($sid <= 0 && $scode === '') {
                continue;
            }
            $tried++;
            $sPid = $this->sasCardSeriesProfileId($srow);
            $sName = $this->sasRowProfileName($srow);
            if ($sName === '') {
                $sName = $sPid > 0 ? ('#' . $sPid) : ($scode !== '' ? $scode : 'كروت');
            }
            // نفس منطق صفحة الكروت: نجلب كل الـ pins ونصفّي الشاغر محلياً
            // (فلتر used=0 من الساس أحياناً يرجع مستخدمة → رقم وهمي مثل 7 بدل 2)
            $pins = $this->sasCardPinsFromSeries($sid, $sPid, $scode, false);
            $n = 0;
            foreach ($pins as $pinRow) {
                if (!is_array($pinRow) || $this->sasCardLooksLikeSeries($pinRow)) {
                    continue;
                }
                $pin = $this->sasCardPinValue($pinRow);
                if ($pin === '' || strlen($pin) < 6) {
                    continue;
                }
                if ($this->sasCardIsUsed($pinRow)) {
                    continue;
                }
                $pkey = strtolower($pin);
                if (isset($seenPin[$pkey])) {
                    continue;
                }
                $seenPin[$pkey] = 1;
                $n++;
            }
            if ($n <= 0) {
                continue;
            }
            $key = strtolower($sName);
            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    'profile_id' => $sPid,
                    'name' => $sName,
                    'count' => 0,
                );
            }
            $groups[$key]['count'] += $n;
            if ($groups[$key]['profile_id'] <= 0 && $sPid > 0) {
                $groups[$key]['profile_id'] = $sPid;
            }
        }

        $out = array_values($groups);
        usort($out, function ($a, $b) {
            if ($a['count'] === $b['count']) {
                return strcasecmp($a['name'], $b['name']);
            }
            return ($a['count'] > $b['count']) ? -1 : 1;
        });
        return $out;
    }

    public function listCardSeriesSummary()
    {
        if (!$this->token && !$this->login()) {
            return array();
        }
        $payload = array(
            'page' => 1,
            'count' => 100,
            'sortBy' => 'series_date',
            'direction' => 'desc',
            'search' => '',
        );
        $series = $this->sasCardFetchPaged(array('index/series', 'index/card', 'index/cards', 'index/cardSeries'), $payload, 20);
        $grouped = array();
        foreach ($series as $srow) {
            if (!is_array($srow)) {
                continue;
            }
            $name = $this->sasRowProfileName($srow);
            if ($name === '' && !empty($srow['profile']['name']) && !is_array($srow['profile']['name'])) {
                $name = trim((string) $srow['profile']['name']);
            }
            if ($name === '') {
                $pid = $this->sasCardSeriesProfileId($srow);
                $name = $pid > 0 ? ('#' . $pid) : '';
            }
            if ($name === '') {
                $name = $this->sasCardSeriesCode($srow);
            }
            if ($name === '') {
                $name = 'كروت';
            }
            if (!empty($srow['suspended']) && (string) $srow['suspended'] === '1') {
                continue;
            }
            $count = $this->sasCardSeriesUnusedCount($srow);
            if ($count <= 0) {
                continue;
            }
            $key = strtolower($name);
            if (!isset($grouped[$key])) {
                $grouped[$key] = array(
                    'name' => $name,
                    'count' => 0,
                    'profile_id' => $this->sasCardSeriesProfileId($srow),
                );
            }
            $grouped[$key]['count'] += $count;
        }
        $out = array_values($grouped);
        usort($out, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    /**
     * جرد الكروت حسب الفئة: كل كارت مع حالة مستخدم/شاغر.
     */
    public function listCardsInventory($maxSeries = 14)
    {
        if (!$this->token && !$this->login()) {
            return array();
        }
        $maxSeries = max(1, min(30, (int) $maxSeries));
        $groups = array();

        // ابدأ من بروفايلات الساس حتى تظهر الفئات الفارغة
        if (method_exists($this, 'getProfiles')) {
            $profiles = $this->getProfiles();
            if (is_array($profiles)) {
                if (isset($profiles['data']) && is_array($profiles['data'])) {
                    $profiles = $profiles['data'];
                }
                foreach ($profiles as $pr) {
                    if (!is_array($pr)) {
                        continue;
                    }
                    $pid = 0;
                    if (isset($pr['id']) && is_numeric($pr['id'])) {
                        $pid = (int) $pr['id'];
                    }
                    $pname = '';
                    if (!empty($pr['name']) && !is_array($pr['name'])) {
                        $pname = trim((string) $pr['name']);
                    } elseif (!empty($pr['profile_name']) && !is_array($pr['profile_name'])) {
                        $pname = trim((string) $pr['profile_name']);
                    }
                    if ($pname === '' && $pid <= 0) {
                        continue;
                    }
                    if ($pname === '') {
                        $pname = '#' . $pid;
                    }
                    $key = strtolower($pname);
                    if (!isset($groups[$key])) {
                        $groups[$key] = array(
                            'name' => $pname,
                            'profile_id' => $pid,
                            'total' => 0,
                            'used' => 0,
                            'unused' => 0,
                            'cards' => array(),
                        );
                    }
                }
            }
        }

        $payload = array(
            'page' => 1,
            'count' => 100,
            'sortBy' => 'series_date',
            'direction' => 'desc',
            'search' => '',
        );
        $series = $this->sasCardFetchPaged(array('index/series', 'index/card', 'index/cards', 'index/cardSeries'), $payload, 20);
        $tried = 0;
        foreach ($series as $srow) {
            if (!is_array($srow)) {
                continue;
            }
            if (!empty($srow['suspended']) && (string) $srow['suspended'] === '1') {
                continue;
            }
            $name = $this->sasRowProfileName($srow);
            if ($name === '' && !empty($srow['profile']['name']) && !is_array($srow['profile']['name'])) {
                $name = trim((string) $srow['profile']['name']);
            }
            if ($name === '') {
                $pidTmp = $this->sasCardSeriesProfileId($srow);
                $name = $pidTmp > 0 ? ('#' . $pidTmp) : $this->sasCardSeriesCode($srow);
            }
            if ($name === '') {
                $name = 'كروت';
            }
            $sid = (isset($srow['id']) && is_numeric($srow['id'])) ? (int) $srow['id'] : 0;
            $scode = $this->sasCardSeriesCode($srow);
            $sPid = $this->sasCardSeriesProfileId($srow);
            $pins = array();
            if ($tried < $maxSeries && ($sid > 0 || $scode !== '')) {
                $tried++;
                $rawPins = $this->sasCardPinsFromSeries($sid, $sPid, $scode, false);
                foreach ($rawPins as $pinRow) {
                    if (!is_array($pinRow)) {
                        continue;
                    }
                    // تجاهل صفوف السلسلة الوصفية (qty/unused_count) حتى لا تظهر أعداد وهمية
                    if ($this->sasCardLooksLikeSeries($pinRow)) {
                        continue;
                    }
                    $pin = $this->sasCardPinValue($pinRow);
                    if ($pin === '' || strlen($pin) < 4) {
                        continue;
                    }
                    $pins[] = array(
                        'pin' => $pin,
                        'used' => $this->sasCardIsUsed($pinRow) ? 1 : 0,
                        'used_by' => $this->sasCardUsedBy($pinRow),
                        'used_at' => $this->sasCardUsedAt($pinRow),
                    );
                }
            }
            // بدون pins حقيقية لا نعتمد أرقام الساس الوصفية (تسبب عدّ خاطئ مثل 34)
            if (!$pins) {
                continue;
            }
            $key = strtolower($name);
            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    'name' => $name,
                    'profile_id' => $sPid,
                    'total' => 0,
                    'used' => 0,
                    'unused' => 0,
                    'cards' => array(),
                );
            }
            foreach ($pins as $pc) {
                $pk = $pc['pin'];
                $dup = false;
                foreach ($groups[$key]['cards'] as $ex) {
                    if ($ex['pin'] === $pk) {
                        $dup = true;
                        break;
                    }
                }
                if (!$dup) {
                    $groups[$key]['cards'][] = $pc;
                }
            }
        }
        foreach ($groups as &$g) {
            $u = 0;
            $nu = 0;
            foreach ($g['cards'] as $c) {
                if (!empty($c['used'])) {
                    $u++;
                } else {
                    $nu++;
                }
            }
            $g['used'] = $u;
            $g['unused'] = $nu;
            $g['total'] = $u + $nu;
            usort($g['cards'], function ($a, $b) {
                if ((int) $a['used'] !== (int) $b['used']) {
                    return ((int) $a['used'] < (int) $b['used']) ? -1 : 1;
                }
                return strcmp($a['pin'], $b['pin']);
            });
        }
        unset($g);
        $out = array_values($groups);
        usort($out, function ($a, $b) {
            if ($a['total'] !== $b['total']) {
                return ($a['total'] > $b['total']) ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    private function sasCardUsedBy($row)
    {
        if (!is_array($row)) {
            return '';
        }
        foreach (array('used_by', 'usedBy', 'used_username', 'used_user', 'username', 'user_name') as $k) {
            if (!empty($row[$k]) && !is_array($row[$k])) {
                $v = trim((string) $row[$k]);
                if ($v !== '' && strpos($v, '@') === false) {
                    return $v;
                }
            }
        }
        if (isset($row['user_details']) && is_array($row['user_details'])
            && !empty($row['user_details']['username']) && !is_array($row['user_details']['username'])) {
            return trim((string) $row['user_details']['username']);
        }
        if (isset($row['user']) && is_array($row['user'])
            && !empty($row['user']['username']) && !is_array($row['user']['username'])) {
            return trim((string) $row['user']['username']);
        }
        return '';
    }

    private function sasCardUsedAt($row)
    {
        if (!is_array($row)) {
            return '';
        }
        foreach (array('used_at', 'usedAt', 'used_date', 'date_used', 'activated_at', 'used_time', 'use_date') as $k) {
            if (empty($row[$k]) || is_array($row[$k])) {
                continue;
            }
            $v = trim((string) $row[$k]);
            if ($v === '' || $v === '0' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') {
                continue;
            }
            $ts = strtotime($v);
            if ($ts > 0) {
                return date('Y-m-d H:i', $ts);
            }
            return $v;
        }
        return '';
    }

    public function loggedManagerId()
    {
        if (!$this->token && !$this->login()) {
            return 0;
        }
        $id = $this->sasLoginManagerId(is_array($this->loginUser) ? $this->loginUser : array());
        if ($id > 0) {
            return $id;
        }
        $id = $this->sasManagerIdByUsername($this->username);
        if ($id > 0) {
            return $id;
        }
        $jwt = $this->getJwtPayload();
        if (is_array($jwt) && isset($jwt['sub']) && is_numeric($jwt['sub']) && (int) $jwt['sub'] > 0) {
            return (int) $jwt['sub'];
        }
        return 0;
    }

    private function sasManagerIdByUsername($username)
    {
        $username = strtolower(trim((string) $username));
        if ($username === '') {
            return 0;
        }
        $payload = array(
            'page' => 1,
            'count' => 20,
            'sortBy' => 'username',
            'direction' => 'asc',
            'search' => $username,
        );
        $full = $this->decodeApiBody($this->post('index/manager', $payload, true), false);
        $data = (is_array($full) && isset($full['data']) && is_array($full['data'])) ? $full['data'] : array();
        foreach ($data as $row) {
            if (!is_array($row) || empty($row['username']) || !isset($row['id']) || !is_numeric($row['id'])) {
                continue;
            }
            if (strtolower(trim((string) $row['username'])) === $username && (int) $row['id'] > 0) {
                return (int) $row['id'];
            }
        }
        return 0;
    }

    private function sasLoginManagerId($row)
    {
        if (!is_array($row)) {
            return 0;
        }
        if (isset($row['manager_id']) && is_numeric($row['manager_id']) && (int) $row['manager_id'] > 0) {
            return (int) $row['manager_id'];
        }
        if (isset($row['id']) && is_numeric($row['id']) && (int) $row['id'] > 0) {
            return (int) $row['id'];
        }
        if (isset($row['user']) && is_array($row['user'])) {
            return $this->sasLoginManagerId($row['user']);
        }
        return 0;
    }

    private function sasSeriesOwnerId($row)
    {
        if (!is_array($row)) {
            return 0;
        }
        foreach (array('owner', 'owner_id', 'manager_id') as $k) {
            if (isset($row[$k]) && is_numeric($row[$k]) && (int) $row[$k] > 0) {
                return (int) $row[$k];
            }
        }
        if (isset($row['owner_details']) && is_array($row['owner_details'])
            && isset($row['owner_details']['id']) && is_numeric($row['owner_details']['id'])) {
            return (int) $row['owner_details']['id'];
        }
        return 0;
    }

    private function sasNameKey($name)
    {
        $s = strtolower(trim((string) $name));
        $s = str_replace(array('_', '–', '—', ' '), '-', $s);
        $s = preg_replace('/-+/', '-', $s);
        return trim((string) $s, '-');
    }

    /**
     * سلاسل الكروت كما يراها دخول الساس: المالك، الفئة، الشاغر (qty - used).
     * null إذا الدخول فشل.
     */
    public function listSeriesStock()
    {
        if (!$this->token && !$this->login()) {
            return null;
        }
        $payload = array(
            'page' => 1,
            'count' => 100,
            'sortBy' => 'series_date',
            'direction' => 'desc',
            'search' => '',
        );
        $series = $this->sasCardFetchPaged(array('index/series'), $payload, 20);
        $me = $this->loggedManagerId();
        $out = array();
        $seenSeries = array();
        foreach ($series as $srow) {
            if (!is_array($srow)) {
                continue;
            }
            if (!empty($srow['suspended']) && (string) $srow['suspended'] === '1') {
                continue;
            }
            $unused = $this->sasCardSeriesUnusedCount($srow);
            if ($unused < 0) {
                $unused = 0;
            }
            $seriesKey = '';
            if (!empty($srow['series']) && !is_array($srow['series'])) {
                $seriesKey = trim((string) $srow['series']);
            }
            if ($seriesKey === '') {
                $seriesKey = $this->sasCardSeriesCode($srow);
            }
            if ($seriesKey === '') {
                continue;
            }
            if (!empty($seenSeries[$seriesKey])) {
                continue;
            }
            $seenSeries[$seriesKey] = true;
            $owner = $this->sasSeriesOwnerId($srow);
            if ($owner <= 0) {
                $owner = $me;
            }
            $name = $this->sasRowProfileName($srow);
            if ($name === '' && !empty($srow['profile']['name']) && !is_array($srow['profile']['name'])) {
                $name = trim((string) $srow['profile']['name']);
            }
            $used = 0;
            if (isset($srow['used']) && is_numeric($srow['used'])) {
                $used = (int) $srow['used'];
            }
            $out[] = array(
                'series' => $seriesKey,
                'owner' => (int) $owner,
                'name' => $name,
                'profile_id' => $this->sasCardSeriesProfileId($srow),
                'unused' => (int) $unused,
                'used' => $used,
            );
        }
        return $out;
    }

    /**
     * سلاسل الوكيل ما تظهر بقائمة الأوفيس. آخر نقل بالساس يحدد مالكها.
     */
    public function listPinlessOwnerSeries($ownerId)
    {
        $ownerId = (int) $ownerId;
        if ($ownerId <= 0) {
            return array();
        }
        if (!$this->token && !$this->login()) {
            return array();
        }
        $payload = array(
            'page' => 1,
            'count' => 40,
            'sortBy' => 'id',
            'direction' => 'desc',
            'search' => '',
            'new_owner_id' => $ownerId,
        );
        $full = $this->decodeApiBody($this->post('index/cardsTransferLog', $payload, true), false);
        $rows = (is_array($full) && isset($full['data']) && is_array($full['data'])) ? $full['data'] : array();
        $out = array();
        $seen = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $series = isset($row['series']) ? trim((string) $row['series']) : '';
            if ($series === '' || isset($seen[$series])) {
                continue;
            }
            $seen[$series] = true;
            if ($this->sasLatestSeriesOwner($series) !== $ownerId) {
                continue;
            }
            $info = $this->sasSeriesRangeInfo($series);
            $len = isset($info['length']) ? (int) $info['length'] : 0;
            $start = isset($info['start']) ? (int) $info['start'] : 0;
            $end = isset($info['end']) ? (int) $info['end'] : 0;
            if ($len < 1 || $start <= 0) {
                continue;
            }
            $name = '';
            if (isset($row['profile_details']) && is_array($row['profile_details']) && !empty($row['profile_details']['name'])) {
                $name = trim((string) $row['profile_details']['name']);
            }
            $pid = (isset($row['profile_id']) && is_numeric($row['profile_id'])) ? (int) $row['profile_id'] : 0;
            $out[] = array(
                'series' => $series,
                'owner' => $ownerId,
                'name' => $name,
                'profile_id' => $pid,
                'unused' => $len,
                'used' => 0,
                'pinless' => 1,
                'range_start' => $start,
                'range_end' => $end > 0 ? $end : $start,
            );
            if (count($out) >= 20) {
                break;
            }
        }
        return $out;
    }

    private function sasLatestSeriesOwner($series)
    {
        $series = trim((string) $series);
        if ($series === '') {
            return 0;
        }
        $page = 1;
        while ($page <= 4) {
            $payload = array(
                'page' => $page,
                'count' => 50,
                'sortBy' => 'id',
                'direction' => 'desc',
                'search' => $series,
            );
            $full = $this->decodeApiBody($this->post('index/cardsTransferLog', $payload, true), false);
            $rows = (is_array($full) && isset($full['data']) && is_array($full['data'])) ? $full['data'] : array();
            if (!$rows) {
                return 0;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = isset($row['series']) ? trim((string) $row['series']) : '';
                if ($code !== $series) {
                    continue;
                }
                return (isset($row['new_owner_id']) && is_numeric($row['new_owner_id'])) ? (int) $row['new_owner_id'] : 0;
            }
            $total = (is_array($full) && isset($full['total']) && is_numeric($full['total'])) ? (int) $full['total'] : 0;
            if (count($rows) < 50 || ($total > 0 && ($page * 50) >= $total)) {
                return 0;
            }
            $page++;
        }
        return 0;
    }

    private function sasSeriesRangeInfo($series)
    {
        $series = trim((string) $series);
        $empty = array('start' => 0, 'end' => 0, 'length' => 0);
        if ($series === '') {
            return $empty;
        }
        $full = $this->decodeApiBody($this->get('series/rangeInfo/' . rawurlencode($series), true), false);
        $data = (is_array($full) && isset($full['data']) && is_array($full['data'])) ? $full['data'] : $full;
        if (!is_array($data)) {
            return $empty;
        }
        $start = (isset($data['range_start']) && is_numeric($data['range_start'])) ? (int) $data['range_start'] : 0;
        $end = (isset($data['range_end']) && is_numeric($data['range_end'])) ? (int) $data['range_end'] : 0;
        $len = (isset($data['range_length']) && is_numeric($data['range_length'])) ? (int) $data['range_length'] : 0;
        if ($len < 1 && $start > 0 && $end >= $start) {
            $len = $end - $start + 1;
        }
        return array('start' => $start, 'end' => $end, 'length' => $len);
    }

    private function sasCardPinIsFree($row)
    {
        if (!is_array($row)) {
            return false;
        }
        if (isset($row['used']) && !is_array($row['used']) && ($row['used'] === 1 || $row['used'] === '1' || $row['used'] === true)) {
            return false;
        }
        foreach (array('used_at', 'usedAt', 'used_date', 'date_used', 'activated_at') as $k) {
            if (empty($row[$k]) || is_array($row[$k])) {
                continue;
            }
            $v = trim((string) $row[$k]);
            if ($v !== '' && $v !== '0' && $v !== '0000-00-00' && $v !== '0000-00-00 00:00:00') {
                return false;
            }
        }
        if (isset($row['user_details']) && is_array($row['user_details'])) {
            if (!empty($row['user_details']['username']) && !is_array($row['user_details']['username'])) {
                return false;
            }
            if (isset($row['user_details']['id']) && is_numeric($row['user_details']['id']) && (int) $row['user_details']['id'] > 0) {
                return false;
            }
        }
        return true;
    }

    private function sasFreeCardIds($seriesCode)
    {
        $seriesCode = trim((string) $seriesCode);
        if ($seriesCode === '') {
            return array('ok' => false, 'ids' => array());
        }
        $page = array(
            'page' => 1,
            'count' => 100,
            'sortBy' => 'id',
            'direction' => 'asc',
            'search' => '',
            'used' => 0,
        );
        $routes = array('index/card/' . $seriesCode);
        $rows = $this->sasCardFetchPaged($routes, $page, 8);
        if (!$rows) {
            unset($page['used']);
            $rows = $this->sasCardFetchPaged($routes, $page, 4);
        }
        if (!$rows) {
            return array('ok' => false, 'ids' => array());
        }
        $ids = array();
        $saw = false;
        foreach ($rows as $row) {
            if (!is_array($row) || $this->sasCardLooksLikeSeries($row)) {
                continue;
            }
            $saw = true;
            if (!$this->sasCardPinIsFree($row)) {
                continue;
            }
            $id = (isset($row['id']) && is_numeric($row['id'])) ? (int) $row['id'] : 0;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $list = array_values($ids);
        sort($list);
        return array('ok' => $saw || $list, 'ids' => $list);
    }

    private function sasContiguousIdChunks($ids)
    {
        $chunks = array();
        if (!is_array($ids) || !$ids) {
            return $chunks;
        }
        $start = (int) $ids[0];
        $prev = $start;
        $n = count($ids);
        for ($i = 1; $i < $n; $i++) {
            $id = (int) $ids[$i];
            if ($id === $prev + 1) {
                $prev = $id;
                continue;
            }
            $chunks[] = array($start, $prev);
            $start = $id;
            $prev = $id;
        }
        $chunks[] = array($start, $prev);
        return $chunks;
    }

    private function sasSeriesRangeStart($series)
    {
        $series = trim((string) $series);
        if ($series === '') {
            return 0;
        }
        $full = $this->decodeApiBody($this->get('series/rangeInfo/' . rawurlencode($series), true), false);
        $data = (is_array($full) && isset($full['data']) && is_array($full['data'])) ? $full['data'] : $full;
        if (!is_array($data)) {
            return 0;
        }
        foreach (array('range_start', 'start', 'from_id', 'first_id') as $k) {
            if (isset($data[$k]) && is_numeric($data[$k]) && (int) $data[$k] > 0) {
                return (int) $data[$k];
            }
        }
        return 0;
    }

    private function sasWriteSucceeded($decoded)
    {
        if (!is_array($decoded) || !$decoded) {
            return false;
        }
        foreach (array('__http_error', '__auth_error', '__curl_error', '__decrypt_error', '__json_error') as $k) {
            if (!empty($decoded[$k])) {
                return false;
            }
        }
        if (isset($decoded['status']) && is_numeric($decoded['status']) && (int) $decoded['status'] !== 200) {
            return false;
        }
        if (isset($decoded['success']) && ($decoded['success'] === false || $decoded['success'] === 0 || $decoded['success'] === '0')) {
            return false;
        }
        return true;
    }

    private function sasWriteErrorText($decoded)
    {
        if (!is_array($decoded)) {
            return '';
        }
        if (!empty($decoded['message']) && !is_array($decoded['message'])) {
            return trim((string) $decoded['message']);
        }
        if (isset($decoded['status']) && is_numeric($decoded['status'])) {
            return 'HTTP ' . (int) $decoded['status'];
        }
        return '';
    }

    private function sasPostWrite($route, $payload)
    {
        $raw = $this->post($route, $payload, true);
        $decoded = $this->decodeApiBody($raw, false);
        if ($this->sasWriteSucceeded($decoded)) {
            return array(true, '');
        }
        $msg = $this->sasWriteErrorText($decoded);
        return array(false, $msg);
    }

    private function sasOwnerIs($series, $ownerId)
    {
        $ownerId = (int) $ownerId;
        if ($this->sasLatestSeriesOwner($series) === $ownerId) {
            return true;
        }
        sleep(1);
        return $this->sasLatestSeriesOwner($series) === $ownerId;
    }

    /**
     * نقل سلسلة كاملة. إذا حساب الوكالة رجع 403 لأن السلسلة عند وكيل تحته،
     * نسحبها لحساب الوكالة فقط عبر حساب الريسيلر الأعلى على نفس السيرفر.
     * @return array(bool, string)
     */
    private function sasReclaimSeries($series, $toOwnerId, $currentOwnerId)
    {
        $series = trim((string) $series);
        $toOwnerId = (int) $toOwnerId;
        $currentOwnerId = (int) $currentOwnerId;
        if ($series === '' || $toOwnerId <= 0) {
            return array(false, 'sas');
        }
        list($ok, $msg) = $this->sasPostWrite('series/changeOwner', array(
            'series' => array($series),
            'owner' => $toOwnerId,
        ));
        if ($ok && $this->sasOwnerIs($series, $toOwnerId)) {
            return array(true, '');
        }
        if ($ok) {
            return array(false, 'sas');
        }
        $denied = (strpos((string) $msg, '403') !== false);
        if ($denied) {
            list($okC, $msgC) = $this->sasReclaimByCount($series, $toOwnerId);
            if ($okC) {
                return array(true, '');
            }
            if ($msgC !== '' && strpos($msgC, '403') === false) {
                return array(false, $msgC);
            }
        }
        $me = (int) $this->loggedManagerId();
        if (!$denied || $toOwnerId !== $me || $this->reclaimUser === '' || $this->reclaimPass === '') {
            return array(false, $msg !== '' ? $msg : 'sas');
        }
        $lift = new self($this->host, $this->reclaimUser, $this->reclaimPass, $this->portal);
        $lift->setTimeout($this->timeout);
        if (!$lift->login()) {
            return array(false, $msg);
        }
        $before = (int) $lift->sasLatestSeriesOwner($series);
        if ($before === $toOwnerId) {
            return array(true, '');
        }
        if ($before > 0 && $currentOwnerId > 0 && $before !== $currentOwnerId) {
            return array(false, $msg);
        }
        list($ok2, $msg2) = $lift->sasPostWrite('series/changeOwner', array(
            'series' => array($series),
            'owner' => $toOwnerId,
        ));
        if ($lift->sasOwnerIs($series, $toOwnerId)) {
            return array(true, '');
        }
        if ($ok2) {
            return array(false, 'sas');
        }
        return array(false, $msg2 !== '' ? $msg2 : $msg);
    }

    /**
     * نقل السلسلة كاملة بالعدد. changeOwner يرفض سلسلة الوكيل، والعدّ يقبله.
     * @return array(bool, string)
     */
    private function sasReclaimByCount($series, $toOwnerId)
    {
        $series = trim((string) $series);
        $toOwnerId = (int) $toOwnerId;
        $info = $this->sasSeriesRangeInfo($series);
        $start = (int) $info['start'];
        $len = (int) $info['length'];
        if ($series === '' || $toOwnerId <= 0 || $start <= 0 || $len < 1 || $len > 500) {
            return array(false, 'pins');
        }
        list($ok, $msg) = $this->sasPostWrite('series/changeOwnerCount', array(
            'series' => $series,
            'selected_owner' => $toOwnerId,
            'count' => $len,
            'from_id' => $start,
            'to_id' => $start + $len - 1,
        ));
        if (!$ok) {
            return array(false, $msg !== '' ? $msg : 'sas');
        }
        $after = $this->sasSeriesRangeInfo($series);
        $left = (int) $after['length'];
        $afterStart = (int) $after['start'];
        if ($left === 0 || ($afterStart > 0 && $afterStart !== $start)) {
            return array(true, '');
        }
        if ($left < $len) {
            return array(false, 'undone');
        }
        return array(false, 'sas');
    }

    private function sasPrefixSnapshot($series)
    {
        $series = trim((string) $series);
        $idsPack = $this->sasFreeCardIds($series);
        $ids = (is_array($idsPack) && !empty($idsPack['ids']) && is_array($idsPack['ids'])) ? $idsPack['ids'] : array();
        $start = $this->sasSeriesRangeStart($series);
        if ($series === '' || $start <= 0 || !$ids) {
            return array('ok' => false, 'message' => 'pins');
        }
        if ((int) $ids[0] !== $start) {
            return array('ok' => false, 'message' => 'not_first');
        }
        return array('ok' => true, 'message' => '', 'start' => (int) $start, 'ids' => $ids);
    }

    private function sasSeriesOwnerNow($series)
    {
        $series = trim((string) $series);
        $rows = $this->listSeriesStock();
        if (!is_array($rows)) {
            return -1;
        }
        foreach ($rows as $s) {
            if (!is_array($s) || empty($s['series'])) {
                continue;
            }
            if (trim((string) $s['series']) === $series) {
                return (int) $s['owner'];
            }
        }
        return 0;
    }

    private function sasFindSeriesStartingAt($cardId, $prefer)
    {
        $cardId = (int) $cardId;
        if ($cardId <= 0) {
            return '';
        }
        $prefer = trim((string) $prefer);
        if ($prefer !== '' && $this->sasSeriesRangeStart($prefer) === $cardId) {
            return $prefer;
        }
        $rows = $this->listSeriesStock();
        if (!is_array($rows)) {
            return '';
        }
        $n = 0;
        foreach ($rows as $s) {
            if ($n >= 40) {
                break;
            }
            if (!is_array($s) || empty($s['series'])) {
                continue;
            }
            $code = trim((string) $s['series']);
            if ($code === '' || $code === $prefer) {
                continue;
            }
            $n++;
            if ($this->sasSeriesRangeStart($code) === $cardId) {
                return $code;
            }
        }
        return '';
    }

    /**
     * exact = انقص العدد المطلوب وصار أول الباقي هو الكارت التالي.
     * wrong = اننقل عدد غير المطلوب أو تبدل مالك السلسلة كلها.
     * unchanged = البداية والمالك ما تغيروا.
     */
    private function sasPrefixJudge($series, $snap, $count, $newOwner, $backOwner)
    {
        $count = (int) $count;
        $ids = $snap['ids'];
        $start = (int) $snap['start'];
        $newStart = $this->sasSeriesRangeStart($series);
        $keep = (count($ids) > $count) ? (int) $ids[$count] : 0;
        if ($keep > 0 && $newStart === $keep) {
            return 'exact';
        }
        if ($keep === 0 && $count === count($ids) && $newStart !== $start && $newStart > 0) {
            return 'exact';
        }
        if ($newStart === $start) {
            $after = $this->sasFreeCardIds($series);
            $afterIds = (is_array($after) && !empty($after['ids']) && is_array($after['ids'])) ? $after['ids'] : array();
            $ownerNow = $this->sasSeriesOwnerNow($series);
            if ($afterIds && count($afterIds) === $count && (int) $afterIds[0] === $start
                && $ownerNow === (int) $newOwner && (int) $newOwner !== (int) $backOwner) {
                return 'exact';
            }
            if ($ownerNow === (int) $newOwner && (int) $newOwner !== (int) $backOwner) {
                return 'wrong';
            }
            return 'unchanged';
        }
        return 'wrong';
    }

    private function sasUndoPrefix($startId, $backOwner, $hint)
    {
        $startId = (int) $startId;
        $backOwner = (int) $backOwner;
        if ($startId <= 0 || $backOwner <= 0) {
            return false;
        }
        $found = $this->sasFindSeriesStartingAt($startId, $hint);
        if ($found === '') {
            return false;
        }
        list($ok,) = $this->sasPostWrite('series/changeOwner', array(
            'series' => array($found),
            'owner' => $backOwner,
        ));
        return $ok ? true : false;
    }

    /**
     * ينقل أول N كارت شاغر ويقارن بداية السلسلة بعد الطلب.
     * إذا الساس نقل عدد ثاني نرجّع السلسلة المنفصلة كاملة ونتوقف.
     * @return array(bool, string, int fromId, int toId, string movedSeries)
     */
    private function sasMovePrefixExact($series, $count, $newOwner, $backOwner)
    {
        $series = trim((string) $series);
        $count = (int) $count;
        $newOwner = (int) $newOwner;
        $backOwner = (int) $backOwner;
        $fail = array(false, 'range', 0, 0, '');
        if ($series === '' || $count < 1 || $newOwner <= 0 || $backOwner <= 0) {
            return $fail;
        }
        $snap = $this->sasPrefixSnapshot($series);
        if (empty($snap['ok'])) {
            $fail[1] = !empty($snap['message']) ? $snap['message'] : 'pins';
            return $fail;
        }
        if (count($snap['ids']) < $count) {
            $fail[1] = 'short:' . count($snap['ids']);
            return $fail;
        }
        $attempt = $this->sasPrefixAttempt($series, $snap, $count, $newOwner, $backOwner, 'series/changeOwnerRange', $count);
        if (!empty($attempt['exact'])) {
            return array(true, '', (int) $snap['start'], (int) $snap['ids'][$count - 1], $attempt['moved_series']);
        }
        if (empty($attempt['wrote']) || !empty($attempt['unchanged'])) {
            $fail[1] = !empty($attempt['message']) ? $attempt['message'] : 'sas';
            return $fail;
        }
        if (!$this->sasUndoPrefix((int) $snap['start'], $backOwner, $attempt['moved_series'])) {
            $fail[1] = 'stuck';
            return $fail;
        }
        $series2 = $this->sasFindSeriesStartingAt((int) $snap['start'], '');
        if ($series2 === '') {
            $series2 = $series;
        }
        $snap2 = $this->sasPrefixSnapshot($series2);
        if (empty($snap2['ok']) || (int) $snap2['start'] !== (int) $snap['start'] || count($snap2['ids']) < $count) {
            $fail[1] = 'undone';
            return $fail;
        }
        $attempt2 = $this->sasPrefixAttempt($series2, $snap2, $count, $newOwner, $backOwner, 'series/changeOwnerCount', $count - 1);
        if (!empty($attempt2['exact'])) {
            return array(true, '', (int) $snap2['start'], (int) $snap2['ids'][$count - 1], $attempt2['moved_series']);
        }
        if (!empty($attempt2['wrote']) && empty($attempt2['unchanged'])) {
            if (!$this->sasUndoPrefix((int) $snap2['start'], $backOwner, $attempt2['moved_series'])) {
                $fail[1] = 'stuck';
                return $fail;
            }
        }
        $fail[1] = 'undone';
        return $fail;
    }

    private function sasPrefixAttempt($series, $snap, $count, $newOwner, $backOwner, $route, $payloadCount)
    {
        $start = (int) $snap['start'];
        $payload = array(
            'series' => $series,
            'selected_owner' => (int) $newOwner,
            'from_id' => $start,
            'to_id' => (int) $snap['ids'][$count - 1],
            'count' => (int) $payloadCount,
        );
        list($ok, $msg) = $this->sasPostWrite($route, $payload);
        $out = array(
            'wrote' => $ok ? true : false,
            'unchanged' => false,
            'exact' => false,
            'message' => $msg,
            'moved_series' => '',
        );
        if (!$ok) {
            return $out;
        }
        $judge = $this->sasPrefixJudge($series, $snap, $count, $newOwner, $backOwner);
        if ($judge === 'unchanged') {
            $out['unchanged'] = true;
            $out['message'] = $msg !== '' ? $msg : 'sas';
            return $out;
        }
        $moved = $this->sasFindSeriesStartingAt($start, $series);
        if ($moved === '' && $this->sasSeriesRangeStart($series) === $start) {
            $moved = $series;
        }
        $out['moved_series'] = $moved;
        if ($judge === 'exact' && $moved !== '') {
            $out['exact'] = true;
            return $out;
        }
        return $out;
    }

    private function sasChangeOwnerRange($series, $fromId, $toId, $newOwner)
    {
        $fromId = (int) $fromId;
        $toId = (int) $toId;
        $count = $toId - $fromId + 1;
        if ($series === '' || $count < 1 || (int) $newOwner <= 0) {
            return array(false, 'range');
        }
        $payload = array(
            'series' => (string) $series,
            'selected_owner' => (int) $newOwner,
            'from_id' => $fromId,
            'to_id' => $toId,
            'count' => $count,
        );
        $raw = $this->post('series/changeOwnerRange', $payload, true);
        $decoded = $this->decodeApiBody($raw, false);
        if ($this->sasWriteSucceeded($decoded)) {
            return array(true, '');
        }
        $st = (is_array($decoded) && isset($decoded['status'])) ? (int) $decoded['status'] : 0;
        $msg = $this->sasWriteErrorText($decoded);
        if ($st === 404 || $st === 405) {
            $raw2 = $this->post('series/changeOwnerCount', $payload, true);
            $decoded2 = $this->decodeApiBody($raw2, false);
            if ($this->sasWriteSucceeded($decoded2)) {
                return array(true, '');
            }
            $msg2 = $this->sasWriteErrorText($decoded2);
            if ($msg2 !== '') {
                $msg = $msg2;
            }
        }
        return array(false, $msg !== '' ? $msg : 'sas');
    }

    /**
     * ينقل كروت شاغرة من مالك إلى مالك داخل الساس.
     * @return array ok, message, moved, ranges
     */
    public function moveUnusedCardsToOwner($profileId, $profileName, $qty, $toOwnerId, $fromOwnerId, $foreignOwnerIds = array())
    {
        $qty = (int) $qty;
        $toOwnerId = (int) $toOwnerId;
        $fromOwnerId = (int) $fromOwnerId;
        $profileId = (int) $profileId;
        $empty = array('ok' => false, 'message' => 'qty', 'moved' => 0, 'ranges' => array());
        if ($qty <= 0 || $toOwnerId <= 0) {
            return $empty;
        }
        if (!$this->token && !$this->login()) {
            $empty['message'] = 'login';
            return $empty;
        }
        $me = $this->loggedManagerId();
        if ($fromOwnerId <= 0) {
            $fromOwnerId = $me;
        }
        $wantKey = $this->sasNameKey($profileName);
        $seriesRows = $this->listSeriesStock();
        if (!is_array($seriesRows)) {
            $empty['message'] = 'login';
            return $empty;
        }
        $foreign = array();
        $homePool = is_array($foreignOwnerIds) && count($foreignOwnerIds) > 0;
        if ($homePool) {
            foreach ($foreignOwnerIds as $fid) {
                $fid = (int) $fid;
                if ($fid > 0 && $fid !== $me && $fid !== $fromOwnerId) {
                    $foreign[$fid] = true;
                }
            }
        }
        if (!$homePool && $fromOwnerId <= 0) {
            $empty['message'] = 'login';
            return $empty;
        }
        $picked = array();
        $have = 0;
        $pickSeries = function ($s, $ignoreOwner) use (&$picked, &$have, $wantKey, $profileId, $homePool, $fromOwnerId, $foreign) {
            if (!is_array($s) || $s['series'] === '') {
                return;
            }
            $owner = (int) $s['owner'];
            if (!$ignoreOwner) {
                if ($homePool) {
                    if ($owner > 0 && !empty($foreign[$owner])) {
                        return;
                    }
                } elseif ($owner !== $fromOwnerId) {
                    return;
                }
            }
            $nameOk = ($wantKey !== '' && $this->sasNameKey($s['name']) === $wantKey);
            $idOk = ($profileId > 0 && (int) $s['profile_id'] > 0 && (int) $s['profile_id'] === $profileId);
            if (!$nameOk && !$idOk) {
                return;
            }
            foreach ($picked as $already) {
                if ($already['series'] === $s['series']) {
                    return;
                }
            }
            $n = (int) $s['unused'];
            $freeIds = array();
            if ($n <= 0) {
                $free = $this->sasFreeCardIds($s['series']);
                if (!empty($free['ok']) && !empty($free['ids'])) {
                    $freeIds = $free['ids'];
                    $n = count($freeIds);
                }
            }
            if ($n <= 0) {
                return;
            }
            $s['unused'] = $n;
            if ($freeIds) {
                $s['free_ids'] = $freeIds;
            }
            $picked[] = $s;
            $have += $n;
        };
        foreach ($seriesRows as $s) {
            $pickSeries($s, false);
        }
        if ($have < $qty && $homePool) {
            foreach ($seriesRows as $s) {
                $pickSeries($s, true);
            }
        }
        if ($have < $qty && !$homePool && $fromOwnerId > 0) {
            $pinlessRows = $this->listPinlessOwnerSeries($fromOwnerId);
            foreach ($pinlessRows as $s) {
                $pickSeries($s, false);
            }
        }
        if ($have < $qty) {
            $empty['message'] = 'short:' . $have;
            return $empty;
        }
        $need = $qty;
        $ranges = array();
        foreach ($picked as $s) {
            if ($need <= 0) {
                break;
            }
            $owner = (int) $s['owner'];
            $take = min($need, (int) $s['unused']);
            if ($take < 1) {
                continue;
            }
            $backOwner = $owner > 0 ? $owner : $fromOwnerId;
            if (!empty($s['pinless'])) {
                if ($take !== (int) $s['unused']) {
                    continue;
                }
                list($okW, $msgW) = $this->sasReclaimSeries((string) $s['series'], $toOwnerId, $backOwner);
                if (!$okW) {
                    $this->restoreCardRanges($ranges, $fromOwnerId);
                    $empty['message'] = ($msgW !== '') ? $msgW : 'sas';
                    return $empty;
                }
                $fromCard = isset($s['range_start']) ? (int) $s['range_start'] : 0;
                $toCard = isset($s['range_end']) ? (int) $s['range_end'] : $fromCard;
                $movedSeries = (string) $s['series'];
            } else {
                list($okM, $msgM, $fromCard, $toCard, $movedSeries) = $this->sasMovePrefixExact($s['series'], $take, $toOwnerId, $backOwner);
                if (!$okM) {
                    $this->restoreCardRanges($ranges, $fromOwnerId);
                    $empty['message'] = $msgM;
                    return $empty;
                }
            }
            $ranges[] = array(
                'series' => (string) $s['series'],
                'moved_series' => (string) $movedSeries,
                'from_id' => (int) $fromCard,
                'to_id' => (int) $toCard,
                'owner' => $backOwner,
            );
            $need -= $take;
        }
        if ($need > 0) {
            $this->restoreCardRanges($ranges, $fromOwnerId);
            $empty['message'] = ($have >= $qty) ? 'sas' : ('short:' . $have);
            return $empty;
        }
        return array('ok' => true, 'message' => '', 'moved' => $qty, 'ranges' => $ranges);
    }

    public function restoreCardRanges($ranges, $ownerId, $forceOwner = 0)
    {
        $ownerId = (int) $ownerId;
        $forceOwner = (int) $forceOwner;
        $this->lastError = '';
        if (!is_array($ranges) || !$ranges) {
            $this->lastError = 'prefix';
            return false;
        }
        if (!$this->token && !$this->login()) {
            $this->lastError = 'login';
            return false;
        }
        $movedAny = false;
        foreach ($ranges as $r) {
            if (!is_array($r)) {
                $this->lastError = 'prefix';
                return false;
            }
            $from = isset($r['from_id']) ? (int) $r['from_id'] : 0;
            $to = isset($r['to_id']) ? (int) $r['to_id'] : 0;
            $back = $forceOwner > 0 ? $forceOwner : ((isset($r['owner']) && (int) $r['owner'] > 0) ? (int) $r['owner'] : $ownerId);
            $count = ($to >= $from && $from > 0) ? ($to - $from + 1) : 0;
            $prefer = !empty($r['moved_series']) ? trim((string) $r['moved_series']) : '';
            $hint = isset($r['series']) ? trim((string) $r['series']) : '';
            if ($from <= 0 || $count < 1 || $back <= 0) {
                $this->lastError = 'prefix';
                return false;
            }
            $found = $this->sasFindSeriesStartingAt($from, $prefer);
            if ($found === '' && $hint !== '') {
                $found = $this->sasFindSeriesStartingAt($from, $hint);
            }
            if ($found === '') {
                $this->lastError = 'prefix';
                return false;
            }
            $pins = $this->sasFreeCardIds($found);
            $ids = (is_array($pins) && !empty($pins['ids']) && is_array($pins['ids'])) ? $pins['ids'] : array();
            $n = count($ids);
            $maxBack = ($prefer === '') ? ($count + 1) : $count;
            if ($n < 1 || (int) $ids[0] !== $from || $n > $maxBack) {
                $this->lastError = 'prefix';
                return false;
            }
            for ($i = 0; $i < $n; $i++) {
                if ((int) $ids[$i] !== $from + $i) {
                    $this->lastError = 'prefix';
                    return false;
                }
            }
            $ownerNow = $this->sasSeriesOwnerNow($found);
            if ($ownerNow <= 0) {
                $this->lastError = 'prefix';
                return false;
            }
            if ($ownerNow === $back) {
                continue;
            }
            list($one, $msgW) = $this->sasReclaimSeries($found, $back, $ownerNow);
            if (!$one) {
                $this->lastError = $msgW !== '' ? $msgW : 'sas';
                return false;
            }
            $movedAny = true;
        }
        if (!$movedAny) {
            $this->lastError = 'already_home';
        }
        return true;
    }

    public function listOnlineUsers()
    {
        if (!$this->token && !$this->login()) {
            return array();
        }
        $all = array();
        $seen = array();
        $this->lastOnlineListOk = false;
        foreach (array('index/online', 'index/onlineUser', 'index/session') as $route) {
            $page = 1;
            $gotAny = false;
            while ($page <= 6) {
                $payload = array(
                    'page' => $page,
                    'count' => 800,
                    'sortBy' => 'username',
                    'direction' => 'asc',
                    'search' => '',
                );
                $full = $this->decodeApiBody($this->post($route, $payload, true), false);
                if (isset($full['__http_error']) || isset($full['__auth_error']) || isset($full['__curl_error'])) {
                    break;
                }
                // رد ناجح من الساس (حتى لو الصفحة فاضية)
                $this->lastOnlineListOk = true;
                $batch = $this->normalizeUserList($full);
                if (!is_array($batch) || !$batch) {
                    break;
                }
                $gotAny = true;
                foreach ($batch as $row) {
                    $u = '';
                    if (is_array($row) && isset($row['username'])) {
                        $u = strtolower(trim((string) $row['username']));
                    }
                    if ($u === '' || isset($seen[$u])) {
                        continue;
                    }
                    $seen[$u] = 1;
                    $all[] = $row;
                }
                if (count($batch) < 800) {
                    break;
                }
                $page++;
            }
            if ($gotAny || $this->lastOnlineListOk) {
                break;
            }
        }
        return $all;
    }

    public function activateUserCard($username, $pin, $userId = 0, $cardId = 0, $profileId = 0)
    {
        if (!$this->token && !$this->login()) {
            return array('__auth_error' => true, 'message' => 'SAS login failed');
        }
        $pin = trim((string) $pin);
        $username = trim((string) $username);
        $userId = (int) $userId;
        $cardId = (int) $cardId;
        $profileId = (int) $profileId;
        if ($pin === '' && $cardId > 0) {
            $pin = (string) $cardId;
        }
        if ($pin === '') {
            return array('message' => 'ماكو رقم كرت', 'status' => -1, 'success' => false);
        }
        if ($userId <= 0) {
            $userId = $this->connectorUserId($this->findUserByUsername($username));
        }
        if ($userId > 0 && method_exists($this, 'getActivationData')) {
            $this->getActivationData($userId);
        }

        // باقة الكرت إن لزم — بدون إرسال profile غلط مع كل طلب
        if ($profileId > 0 && $userId > 0) {
            $cur = $this->getUserById($userId);
            $curPid = 0;
            if (is_array($cur)) {
                if (isset($cur['profile_id']) && is_numeric($cur['profile_id'])) {
                    $curPid = (int) $cur['profile_id'];
                } elseif (isset($cur['profile']['id']) && is_numeric($cur['profile']['id'])) {
                    $curPid = (int) $cur['profile']['id'];
                }
            }
            if ($curPid !== $profileId) {
                $ch = $this->changeUserProfile($userId, $profileId);
                if ($this->isInvalidProfileRes($ch)) {
                    return array(
                        'success' => false,
                        'message' => 'rsp_invalid_profile — ما قدرنا نحوّل المشترك لباقة الكرت',
                    );
                }
            }
        }

        $beforeTs = $this->liveExpireTs($userId, $username);
        $oldTimeout = $this->timeout;
        $this->setTimeout(5);

        // المسار الشائع على NBTel/SAS: user/activate + method=card (مو /pin اللي يرجّع 404)
        $attempts = array();
        if ($userId > 0) {
            $attempts[] = array('user/activate', array(
                'id' => $userId,
                'user_id' => $userId,
                'username' => $username,
                'pin' => $pin,
                'method' => 'card',
                'units' => 1,
            ));
            $attempts[] = array('user/activate', array(
                'id' => $userId,
                'user_id' => $userId,
                'username' => $username,
                'pin' => $pin,
                'method' => 'voucher',
                'units' => 1,
            ));
            if ($profileId > 0) {
                $attempts[] = array('user/activate', array(
                    'id' => $userId,
                    'user_id' => $userId,
                    'username' => $username,
                    'pin' => $pin,
                    'method' => 'card',
                    'profile_id' => $profileId,
                    'units' => 1,
                ));
            }
            $attempts[] = array('user/activateCard', array('id' => $userId, 'pin' => $pin));
            $attempts[] = array('user/usePin', array('id' => $userId, 'pin' => $pin, 'username' => $username));
        }
        $attempts[] = array('user/activate', array(
            'username' => $username,
            'pin' => $pin,
            'method' => 'card',
            'units' => 1,
        ));
        $attempts[] = array('user/useCard', array('username' => $username, 'pin' => $pin));
        if ($userId > 0) {
            $attempts[] = array('user/activate/pin', array('id' => $userId, 'pin' => $pin));
        }

        $last = array('success' => false, 'message' => 'فشل تفعيل الكرت');
        $deadRoutes = array();
        foreach ($attempts as $pair) {
            $route = $pair[0];
            $payload = $pair[1];
            if (isset($deadRoutes[$route])) {
                continue;
            }
            $last = $this->postActivateOnce($route, $payload);

            if ($this->isRouteMissing($last) || $this->isMethodNotAllowed($last)) {
                $deadRoutes[$route] = 1;
                continue;
            }
            if ($this->isInvalidProfileRes($last)) {
                // جرّب بدون profile_id إن كان موجود
                if (!empty($payload['profile_id'])) {
                    continue;
                }
                $this->setTimeout($oldTimeout);
                $last['success'] = false;
                $last['message'] = 'rsp_invalid_profile — الساس رفض باقة هذا الكرت/المشترك';
                return $last;
            }
            if ($this->isHardActivateFail($last)) {
                $this->setTimeout($oldTimeout);
                $last['success'] = false;
                return $last;
            }
            if (!$this->isActivateOkStrict($last)) {
                continue;
            }
            $afterTs = $this->liveExpireTs($userId, $username);
            if ($this->expireMoved($beforeTs, $afterTs)) {
                $this->setTimeout($oldTimeout);
                $last['_verified'] = 1;
                return $last;
            }
            $this->setTimeout($oldTimeout);
            $last['_verified'] = 0;
            $last['_api_ok'] = 1;
            return $last;
        }

        $this->setTimeout($oldTimeout);
        if (!is_array($last)) {
            $last = array();
        }
        $last['success'] = false;
        $hint = isset($last['message']) ? trim((string) $last['message']) : '';
        // لا ترجع 404 كسبب نهائي إذا جرّبنا مسارات ثانية
        if (strpos($hint, '404') !== false || strpos($hint, 'المسار غير موجود') !== false) {
            $hint = 'ماكو مسار تفعيل كرت شغّال على هذا الساس';
        }
        $last['message'] = ($hint !== '' ? ($hint . ' — ') : '')
            . 'تفعيل الكرت ما غيّر تاريخ الانتهاء على الساس';
        return $last;
    }

    /**
     * يفك التشفير ويبقي غلاف DataTables (recordsTotal + data)
     */
    private function parseApiEnvelope($response)
    {
        return $this->decodeApiBody($response, false);
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
        $last = array();
        foreach (array('user/extend', 'user/extendService', 'user/activate/test') as $route) {
            $raw = $this->post($route, $payload, true);
            $last = $this->parseApiResponse($raw);
            if ($this->isActivateOk($last)) {
                return $last;
            }
        }
        if (!is_array($last)) {
            $last = array('message' => 'SAS extend failed');
        }
        $base = isset($last['message']) ? trim((string) $last['message']) : '';
        $last['message'] = ($base !== '' ? ($base . ' — ') : '')
            . 'extend method=' . $method
            . ' user_id=' . $userId
            . ' profile_id=' . $extendProfileId;
        return $last;
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

    private function isMethodNotAllowed($res)
    {
        if (!is_array($res)) {
            return false;
        }
        $st = isset($res['status']) ? (int) $res['status'] : 0;
        if ($st === 405) {
            return true;
        }
        $msg = isset($res['message']) ? strtolower((string) $res['message']) : '';
        if ($msg === '') {
            return false;
        }
        return (strpos($msg, 'method is not supported') !== false
            || strpos($msg, 'supported methods') !== false
            || strpos($msg, 'method not allowed') !== false);
    }

    private function postActivate($route, $payload)
    {
        $payload = is_array($payload) ? $payload : array();
        $last = $this->parseApiResponse($this->post($route, $payload, true, 'POST'));
        if ($this->isActivateOk($last) || !$this->isMethodNotAllowed($last)) {
            return $last;
        }
        foreach (array('PUT', 'PATCH') as $m) {
            $last = $this->parseApiResponse($this->post($route, $payload, true, $m));
            if ($this->isActivateOk($last) || !$this->isMethodNotAllowed($last)) {
                return $last;
            }
        }
        return $this->parseApiResponse(
            $this->post($route, array_merge($payload, array('_method' => 'PUT')), true, 'POST')
        );
    }

    /** POST مرة واحدة — أسرع لتفعيل الكرت */
    private function postActivateOnce($route, $payload)
    {
        $payload = is_array($payload) ? $payload : array();
        return $this->parseApiResponse($this->post($route, $payload, true, 'POST'));
    }

    private function isRouteMissing($res)
    {
        if (!is_array($res)) {
            return false;
        }
        if (!empty($res['__http_error'])) {
            $st = isset($res['status']) ? (int) $res['status'] : 0;
            if ($st === 404 || $st === 405) {
                return true;
            }
        }
        $msg = isset($res['message']) ? strtolower((string) $res['message']) : '';
        if ($msg === '') {
            return false;
        }
        return (strpos($msg, 'http 404') !== false
            || strpos($msg, 'المسار غير موجود') !== false
            || strpos($msg, 'not found') !== false);
    }

    private function isActivateOk($res)
    {
        if (!is_array($res) || count($res) === 0) {
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
            if (is_numeric($st)) {
                $n = (int) $st;
                if ($n >= 100 && $n !== 200) {
                    return false;
                }
            } elseif (in_array(strtolower((string) $st), array('error', 'fail', 'failed'), true)) {
                return false;
            }
        }
        $msg = isset($res['message']) ? strtolower((string) $res['message']) : '';
        if ($msg !== '' && (strpos($msg, 'invalid_profile') !== false || strpos($msg, 'rsp_invalid_profile') !== false)) {
            return false;
        }
        if ($msg !== '' && $this->isHardActivateFail($res)) {
            return false;
        }
        return true;
    }

    /** نجاح أوضح لتفعيل الكرت — يقلل القبول الوهمي */
    private function isActivateOkStrict($res)
    {
        if ($this->isInvalidProfileRes($res)) {
            return false;
        }
        if (!$this->isActivateOk($res)) {
            return false;
        }
        if ($this->isHardActivateFail($res)) {
            return false;
        }
        if (isset($res['success']) && ($res['success'] === true || $res['success'] === 1 || $res['success'] === '1')) {
            return true;
        }
        if (isset($res['status']) && is_numeric($res['status'])) {
            $n = (int) $res['status'];
            if ($n === 200 || $n === 1) {
                return true;
            }
        }
        $msg = isset($res['message']) ? strtolower(trim((string) $res['message'])) : '';
        if ($msg !== '' && (
            strpos($msg, 'success') !== false
            || strpos($msg, 'activat') !== false
            || strpos($msg, 'تم') !== false
            || strpos($msg, 'ok') === 0
        )) {
            return true;
        }
        // بعض نسخ الساس ترجع data بدون success صريح بعد تفعيل ناجح
        if (isset($res['data']) && (is_array($res['data']) || $res['data'] === true || $res['data'] === 1)) {
            return true;
        }
        return false;
    }

    private function isInvalidProfileRes($res)
    {
        if (!is_array($res)) {
            return false;
        }
        $msg = isset($res['message']) ? strtolower((string) $res['message']) : '';
        if ($msg === '') {
            return false;
        }
        return (strpos($msg, 'invalid_profile') !== false
            || strpos($msg, 'rsp_invalid_profile') !== false
            || strpos($msg, 'invalid profile') !== false);
    }

    private function isHardActivateFail($res)
    {
        if (!is_array($res)) {
            return false;
        }
        if ($this->isInvalidProfileRes($res)) {
            return false; // يُعالج بمسار pin بدون profile_id
        }
        $msg = isset($res['message']) ? strtolower((string) $res['message']) : '';
        if ($msg === '') {
            return false;
        }
        $needles = array(
            'insufficient', 'not enough', 'no balance', 'no credit',
            'permission', 'forbidden', 'unauthorized',
            'invalid pin', 'wrong pin', 'already used', 'used card', 'card used',
            'ماكو رصيد', 'رصيد غير', 'غير كاف', 'ماكو صلاح',
            'كرت مستخدم', 'مستخدم مسبقا', 'غير صالح',
        );
        foreach ($needles as $n) {
            if (strpos($msg, $n) !== false) {
                return true;
            }
        }
        return false;
    }

    private function connectorUserId($row)
    {
        if (function_exists('sas_extract_user_id')) {
            return (int) sas_extract_user_id($row);
        }
        if (!is_array($row)) {
            return 0;
        }
        foreach (array('id', 'user_id', 'userid', 'userId') as $k) {
            if (isset($row[$k]) && is_numeric($row[$k]) && (int) $row[$k] > 0) {
                return (int) $row[$k];
            }
        }
        return 0;
    }

    private function expireTsFromRow($row)
    {
        if (function_exists('sas_unwrap_user_row')) {
            $inner = sas_unwrap_user_row($row);
            if (is_array($inner)) {
                $row = $inner;
            }
        }
        if (function_exists('sas_row_expire_ts')) {
            return (int) sas_row_expire_ts($row);
        }
        if (function_exists('sas_cache_expire_at')) {
            $sql = sas_cache_expire_at($row);
            if ($sql) {
                $ts = strtotime((string) $sql);
                return $ts ? (int) $ts : 0;
            }
        }
        return 0;
    }

    private function liveExpireTs($userId, $username)
    {
        $userId = (int) $userId;
        if ($userId > 0) {
            $ts = $this->expireTsFromRow($this->getUserById($userId));
            if ($ts > 0) {
                return $ts;
            }
        }
        $username = trim((string) $username);
        if ($username !== '') {
            return $this->expireTsFromRow($this->findUserByUsername($username));
        }
        return 0;
    }

    private function expireMoved($beforeTs, $afterTs)
    {
        $afterTs = (int) $afterTs;
        if ($afterTs <= 0) {
            return false;
        }
        $beforeTs = (int) $beforeTs;
        $now = time();
        if ($beforeTs <= 0) {
            return $afterTs > ($now - 3600);
        }
        if (($afterTs - $beforeTs) >= 3600) {
            return true;
        }
        if ($beforeTs < ($now - 60) && $afterTs > ($now + 3600)) {
            return true;
        }
        return false;
    }

    public function findUserByUsername($username)
    {
        if (!$this->token && !$this->login()) {
            return null;
        }

        $username = (string) $username;
        $payload = array(
            'page' => 1,
            'count' => 50,
            'sortBy' => 'username',
            'direction' => 'asc',
            'search' => $username,
        );

        $rows = $this->normalizeUserList($this->decodeApiBody($this->post('index/user', $payload, true), false));
        $found = $this->matchUserRow($rows, $username);
        if ($found) {
            return $found;
        }

        $digits = preg_replace('/\D+/', '', $username);
        if ($digits !== '' && $digits !== $username) {
            $payload['search'] = $digits;
            $rows = $this->normalizeUserList($this->decodeApiBody($this->post('index/user', $payload, true), false));
            $found = $this->matchUserRow($rows, $username);
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    private function normalizeUserList($rows, $depth = 0)
    {
        if ($depth > 6 || !is_array($rows) || isset($rows['__http_error']) || isset($rows['__exception'])
            || isset($rows['__auth_error']) || isset($rows['__curl_error'])) {
            return array();
        }
        if (isset($rows[0]) && is_array($rows[0])) {
            return $rows;
        }
        foreach (array('data', 'aaData', 'rows', 'users', 'extensions', 'profiles', 'allowedExtensions', 'items', 'cards', 'pins', 'series', 'list', 'result', 'records') as $k) {
            if (isset($rows[$k]) && is_array($rows[$k])) {
                return $this->normalizeUserList($rows[$k], $depth + 1);
            }
        }
        if ($this->sasLooksLikeRecordRow($rows)) {
            return array($rows);
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
        return $this->decodeApiBody($response, true);
    }

    private function decodeApiBody($response, $unwrapList)
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
                    } elseif ($st === 405) {
                        $hint .= ' — التمديد غير مسموح أو النقاط غير كافية';
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

        if (isset($data['success']) && ($data['success'] === false || $data['success'] === 0 || $data['success'] === '0')) {
            return $data;
        }

        if (!$unwrapList) {
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
