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
    public function listUsersPage($start, $length, $search = '')
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

    private function sasCardPinsFromSeries($seriesId, $profileId = 0, $seriesCode = '')
    {
        $seriesId = (int) $seriesId;
        $seriesCode = trim((string) $seriesCode);
        if ($seriesId <= 0 && $seriesCode === '') {
            return array();
        }
        $page = array(
            'page' => 1,
            'count' => 50,
            'sortBy' => 'id',
            'direction' => 'desc',
            'search' => '',
        );
        $pageUnused = $page;
        $pageUnused['used'] = 0;
        $routes = array();
        if ($seriesCode !== '' && $this->sasLooksLikeSeriesCode($seriesCode)) {
            $routes[] = 'index/card/' . $seriesCode;
        }
        if ($seriesId > 0) {
            $routes[] = 'index/card/' . $seriesId;
        }
        foreach (array($pageUnused, $page) as $payload) {
            if ($routes) {
                $paged = $this->sasCardFetchPaged($routes, $payload, 2);
                if ($this->sasCardListHasPin($paged)) {
                    return $paged;
                }
            }
        }
        $tries = array();
        if ($seriesCode !== '' && $this->sasLooksLikeSeriesCode($seriesCode)) {
            $tries[] = array('index/card/' . $seriesCode, $pageUnused);
        }
        if ($seriesId > 0) {
            $tries[] = array('index/card/' . $seriesId, $pageUnused);
            $tries[] = array('list/card/' . $seriesId, $pageUnused);
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
        if ($profileId > 0 && $sPid === $profileId) {
            return true;
        }
        if ($profileName === '') {
            return false;
        }
        $names = array();
        $pn = $this->sasRowProfileName($row);
        if ($pn !== '') {
            $names[] = $pn;
        }
        $want = strtolower($profileName);
        foreach ($names as $n) {
            $have = strtolower($n);
            if ($have === $want || strpos($have, $want) !== false || strpos($want, $have) !== false) {
                return true;
            }
        }
        $val = null;
        if (isset($row['value']) && is_numeric($row['value'])) {
            $val = (float) $row['value'];
        } elseif (isset($row['price']) && is_numeric($row['price'])) {
            $val = (float) $row['price'];
        }
        if ($val !== null) {
            if ((strpos($want, 'max') !== false || strpos($want, '1.5') !== false || strpos($want, '1,5') !== false)
                && abs($val - 1.5) < 0.06) {
                return true;
            }
            if ((strpos($want, 'nb2') !== false || strpos($want, 'nb-2') !== false) && abs($val - 2) < 0.06) {
                return true;
            }
        }
        return false;
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
            if ($unusedHint <= 0) {
                continue;
            }
            $usedCnt = -1;
            if (array_key_exists('used', $srow)) {
                if ($srow['used'] === '' || $srow['used'] === null || $srow['used'] === false
                    || (is_array($srow['used']) && !$srow['used'])) {
                    $usedCnt = 0;
                } elseif (is_numeric($srow['used'])) {
                    $usedCnt = (int) $srow['used'];
                }
            } elseif (isset($srow['used_count']) && is_numeric($srow['used_count'])) {
                $usedCnt = (int) $srow['used_count'];
            }
            if ($usedCnt !== 0) {
                continue;
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
        $ordered = $hasFilter ? array_merge($prefer, $rest) : array_merge($prefer, $rest);
        if ($hasFilter && $prefer) {
            $ordered = $prefer;
        }
        usort($ordered, function ($a, $b) {
            $ua = isset($a['_unused_hint']) ? (int) $a['_unused_hint'] : 0;
            $ub = isset($b['_unused_hint']) ? (int) $b['_unused_hint'] : 0;
            $qa = 0;
            $qb = 0;
            if (isset($a['qty']) && is_numeric($a['qty'])) {
                $qa = (int) $a['qty'];
            } elseif (isset($a['quantity']) && is_numeric($a['quantity'])) {
                $qa = (int) $a['quantity'];
            }
            if (isset($b['qty']) && is_numeric($b['qty'])) {
                $qb = (int) $b['qty'];
            } elseif (isset($b['quantity']) && is_numeric($b['quantity'])) {
                $qb = (int) $b['quantity'];
            }
            $fa = ($qa > 0 && $ua === $qa) ? 0 : 1;
            $fb = ($qb > 0 && $ub === $qb) ? 0 : 1;
            if ($fa !== $fb) {
                return ($fa < $fb) ? -1 : 1;
            }
            if ($ua !== $ub) {
                return ($ua < $ub) ? -1 : 1;
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
            foreach (array('pins', 'cards', 'unused', 'items') as $nk) {
                if (isset($srow[$nk]) && is_array($srow[$nk]) && isset($srow[$nk][0])) {
                    $nested = $srow[$nk];
                    break;
                }
            }
            if (!$nested && ($sid > 0 || $scode !== '') && $seriesTried < 8) {
                $seriesTried++;
                $nested = $this->sasCardPinsFromSeries($sid, $sPid > 0 ? $sPid : $profileId, $scode);
            }
            $taken = 0;
            $limit = isset($srow['_unused_hint']) ? (int) $srow['_unused_hint'] : 0;
            foreach ($nested as $pinRow) {
                if (!is_array($pinRow) || $this->sasCardPinValue($pinRow) === '' || $this->sasCardIsUsed($pinRow)) {
                    continue;
                }
                if ($limit > 0 && $taken >= $limit) {
                    break;
                }
                if (empty($pinRow['profile_name']) && $sName !== '') {
                    $pinRow['profile_name'] = $sName;
                }
                if ((empty($pinRow['profile_id']) || !is_numeric($pinRow['profile_id'])) && $sPid > 0) {
                    $pinRow['profile_id'] = $sPid;
                }
                $pinRow['_from_matched_series'] = 1;
                $rows[] = $pinRow;
                $taken++;
            }
        }

        $out = array();
        $seen = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['qty']) || !empty($row['quantity'])) {
                continue;
            }
            $pin = $this->sasCardPinValue($row);
            if ($pin === '' || $this->sasCardIsUsed($row)) {
                continue;
            }
            $fromMatched = !empty($row['_from_matched_series']);
            if ($hasFilter && !$fromMatched && !$this->sasSeriesMatchesProfile($row, $profileId, $profileName)) {
                $rowPid = $this->sasCardSeriesProfileId($row);
                if ($rowPid > 0 && $profileId > 0 && $rowPid !== $profileId) {
                    continue;
                }
                if ($rowPid > 0 || $this->sasCardSeriesName($row) !== '') {
                    continue;
                }
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

    public function listOnlineUsers()
    {
        if (!$this->token && !$this->login()) {
            return array();
        }
        $payload = array(
            'page' => 1,
            'count' => 500,
            'sortBy' => 'username',
            'direction' => 'asc',
            'search' => '',
        );
        $rows = array();
        foreach (array('index/online', 'index/onlineUser', 'index/session') as $route) {
            $full = $this->decodeApiBody($this->post($route, $payload, true), false);
            if (isset($full['__http_error']) || isset($full['__auth_error']) || isset($full['__curl_error'])) {
                continue;
            }
            $rows = $this->normalizeUserList($full);
            if ($rows) {
                break;
            }
        }
        return is_array($rows) ? $rows : array();
    }

    public function activateUserCard($username, $pin, $userId = 0, $cardId = 0)
    {
        if (!$this->token && !$this->login()) {
            return array('__auth_error' => true, 'message' => 'SAS login failed');
        }
        $pin = trim((string) $pin);
        $username = trim((string) $username);
        $cardId = (int) $cardId;
        $tries = array(
            array('user/activate/card', array('username' => $username, 'pin' => $pin, 'serial' => $pin)),
            array('user/activate/pin', array('username' => $username, 'pin' => $pin)),
            array('user/activate/card', array('user_id' => (int) $userId, 'card_id' => ($cardId > 0 ? $cardId : $pin), 'pin' => $pin)),
        );
        if ($cardId > 0) {
            $tries[] = array('user/activate/card', array('username' => $username, 'card_id' => $cardId, 'pin' => $pin));
        }
        $last = array();
        foreach ($tries as $t) {
            $last = $this->parseApiResponse($this->post($t[0], $t[1], true));
            if ($this->isActivateOk($last)) {
                return $last;
            }
        }
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
            if (is_numeric($st)) {
                $n = (int) $st;
                if ($n >= 100 && $n !== 200) {
                    return false;
                }
            } elseif (in_array(strtolower((string) $st), array('error', 'fail', 'failed'), true)) {
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
