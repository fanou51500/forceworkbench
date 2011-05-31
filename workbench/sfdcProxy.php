<?php
require_once "context/WorkbenchContext.php";
require_once "session.php";

if (!WorkbenchContext::isEstablished()) {
    header('HTTP/1.0 401 Unauthorized');
    echo "SFDC Proxy only available if Workbench Context has been established.";
    exit;
}

$host = WorkbenchContext::get()->getHost();
$sessionId = WorkbenchContext::get()->getSessionId();
$_COOKIE['sid'] = $sessionId;
session_write_close();

$proxy = new PhpReverseProxy();
$proxy->host = $host;
//$proxy->host = "localhost";
//$proxy->port = "8080";
//$proxy->forward_path = "/dojo-jetty7-primer";
$proxy->connect();
$proxy->output();



class PhpReverseProxy {
    public $port, $host, $forward_path, $content, $content_type, $user_agent,
    $XFF, $request_method, $cookie;

    private $http_code, $version, $resultHeader;

    function __construct() {
        $this->version = "PHP Reverse Proxy (PRP) 1.0";
        $this->port = "";
        $this->host = "";
        $this->forward_path = "";
        $this->content = "";
        $this->path = "";
        $this->content_type = "";
        $this->user_agent = "";
        $this->http_code = "";
        $this->XFF = "";
        $this->request_method = "GET";
        $this->cookie = "";
    }

    function translateURL($serverName) {
        $this->path = $this->forward_path . str_replace(dirname($_SERVER['PHP_SELF']), "", $_SERVER['REQUEST_URI']);
        $server = $this->translateServer($serverName);
        $queryString = ($_SERVER['QUERY_STRING'] == "")
                ? ""
                : "?" . $_SERVER['QUERY_STRING'];

        return $server . $this->path . $queryString;
    }

    function translateServer($serverName) {
        $protocol = "http" .
                    ((isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on")
                        ? "s"
                        : "");

        $port = ($this->port == "") ? "" : ":" . $this->port;

        return $protocol . "://" . $serverName . $port;
    }

    function preConnect() {
        $this->user_agent = $_SERVER['HTTP_USER_AGENT'];
        $this->request_method = $_SERVER['REQUEST_METHOD'];
        $tempCookie = "";
        foreach ($_COOKIE as $cookieName => $cookieValue) {
            if ($cookieName == "PHPSESSID") continue;
            if ($cookieName == "XDEBUG_SESSION") continue;
            $tempCookie = $tempCookie . " $cookieName = $cookieValue;";
        }
        $this->cookie = $tempCookie;
        if (empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $this->XFF = $_SERVER['REMOTE_ADDR'];
        } else {
            $this->XFF = $_SERVER['HTTP_X_FORWARDED_FOR'] . ", " . $_SERVER['REMOTE_ADDR'];
        }
    }

    function connect() {
        $this->preConnect();
        $ch = curl_init();
        if ($this->request_method == "POST") {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents("php://input"));
        }
        curl_setopt($ch, CURLOPT_URL, $this->translateURL($this->host));

        $headers = array();
        foreach (getallheaders() as $key => $value) {
            if (in_array($key, array("Content-Type", "Accept"))) {
                $headers[] = "$key: $value";
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($this->cookie != "") {
            curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
        }
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);
        $info = curl_getinfo($ch);
        $this->postConnect($ch, $info, $output);
        curl_close($ch);
    }

    function postConnect($ch, $info, $output) {
        if (curl_error($ch) != null) {
            $this->resultHeader = "HTTP/1.0 502 Bad Gateway";
            $this->content = "Workbench encountered an error proxying the request. Error:\n" . curl_error($ch);
            return;
        }

        $this->content_type = $info["content_type"];
        $this->http_code = $info['http_code'];
        $this->resultHeader = substr($output, 0, $info['header_size']);
        $this->content = substr($output, $info['header_size']);
    }

    function output() {
        header_remove();

        $headerWhitelist = array("HTTP", "Date", "Content-Type", "Set-Cookie");
        foreach (explode("\r\n",$this->resultHeader) as $h) {
            foreach ($headerWhitelist as $whl) {
                if (stripos($h, $whl) > -1) {
                    if (stripos("Set-Cookie", $whl) > -1) {
                        $h = preg_replace("/path=([^;]*)/", "path=".dirname($_SERVER['PHP_SELF'])."$1", $h);
                    }

                    header($h, true);
                    continue;
                }
            }
        }
        echo $this->content ;
    }
}
?>