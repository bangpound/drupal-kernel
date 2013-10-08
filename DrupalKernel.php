<?php

namespace Bangpound\Drupal;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Process\PhpProcess;

class DrupalKernel implements HttpKernelInterface
{
    private $rootDir;
    private $phpCgiBin;
    private $autoloadPath;

    public function __construct($loader, $rootDir, $phpCgiBin = null)
    {
        $this->rootDir = realpath($rootDir);
        $this->phpCgiBin = $phpCgiBin ?: 'php-cgi';
        $r = new \ReflectionClass($loader);
        $this->autoloadPath = realpath(dirname($r->getFileName()) .  DIRECTORY_SEPARATOR .'..' . DIRECTORY_SEPARATOR . 'autoload.php');
    }

    /**
     * Handles a Request to convert it to a Response.
     *
     * When $catch is true, the implementation must catch all exceptions
     * and do its best to convert them to a Response instance.
     *
     * @param Request $request A Request instance
     * @param integer $type    The type of the request
     *                          (one of HttpKernelInterface::MASTER_REQUEST or HttpKernelInterface::SUB_REQUEST)
     * @param Boolean $catch Whether to catch exceptions or not
     *
     * @return Response A Response instance
     *
     * @throws \Exception When an Exception occurs during processing
     *
     * @api
     */
    public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true)
    {
        $drupal_request = $request->duplicate();
        if (count($drupal_request->files)) {
            $boundary = $this->getMimeBoundary();
            $drupal_request->headers->set('Content-Type', 'multipart/form-data; boundary='.$boundary);
        }

        $drupal_request->query->set('q', ltrim($drupal_request->getPathInfo(), '/'));
        $drupal_request->server->set('REQUEST_URI', $drupal_request->getPathInfo());
        $drupal_request->server->set('HTTP_HOST', 'localhost');
        $drupal_request->server->set('SCRIPT_NAME', '/index.php');
        $script = $this->getScript($drupal_request);

        $process = new PhpProcess($script);
        $process->setPhpBinary('php-cgi');
        $process->setWorkingDirectory($this->rootDir);
        $process->start();
        $process->wait();

        $processOutput = $process->getOutput();

        list($headerList, $body) = explode("\r\n\r\n", $processOutput, 2);
        $headerMap = $this->getHeaderMap($headerList);
        $cookies = $this->getCookies($headerMap);

        $headers = $this->flattenHeaderMap($headerMap);
        unset($headers['Cookie']);
        $status = $this->getStatusCode($headers);

        $response = new Response($body, $status, $headers);
        foreach ($cookies as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    private function getStatusCode(array $headers)
    {
        if (isset($headers['Status'])) {
            list($code) = explode(' ', $headers['Status']);

            return (int) $code;
        }

        return 200;
    }

    private function getHeaderMap($headerListRaw)
    {
        if (0 === strlen($headerListRaw)) {
            return array();
        }

        $headerMap = array();

        $headerList  = preg_replace('~\r\n[\t ]~', ' ', $headerListRaw);
        $headerLines = explode("\r\n", $headerList);
        foreach ($headerLines as $headerLine) {
            if (false === strpos($headerLine, ':')) {
                throw new \RuntimeException('Unable to parse header line, name missing');
            }

            list($name, $value) = explode(':', $headerLine, 2);

            $name  = implode('-', array_map('ucwords', explode('-', $name)));
            $value = trim($value, "\t ");

            $headerMap[$name][] = $value;
        }

        return $headerMap;
    }

    private function flattenHeaderMap(array $headerMap)
    {
        $flatHeaderMap = array();
        foreach ($headerMap as $name => $values) {
            $flatHeaderMap[$name] = implode(', ', $values);
        }

        return $flatHeaderMap;
    }

    private function getCookies(array $headerMap)
    {
        if (!isset($headerMap['Set-Cookie'])) {
            return array();
        }

        return array_map(
            array($this, 'cookieFromResponseHeaderValue'),
            $headerMap['Set-Cookie']
        );
    }

    private function cookieFromResponseHeaderValue($value)
    {
        $cookieParts = preg_split('/;\s?/', $value);
        $cookieMap = array();
        foreach ($cookieParts as $part) {
            preg_match('/(\w+)(?:=(.*)|)/', $part, $capture);
            $name = $capture[1];
            $value = isset($capture[2]) ? $capture[2] : '';

            $cookieMap[$name] = $value;
        }

        $firstKey = key($cookieMap);

        $cookieMap = array_merge($cookieMap, array(
            'secure'    => isset($cookieMap['secure']),
            'httponly'  => isset($cookieMap['httponly']),
        ));

        $cookieMap = array_merge(array(
            'expires' => 0,
            'path' => '/',
            'domain' => null,
        ), $cookieMap);

        return new Cookie(
            $firstKey,
            $cookieMap[$firstKey],
            $cookieMap['expires'],
            $cookieMap['path'],
            $cookieMap['domain'],
            $cookieMap['secure'],
            $cookieMap['httponly']
        );
    }

    private function getMimeBoundary()
    {
        return md5('cgi-http-kernel');
    }

    /**
     * Returns the script to execute when the request must be insulated.
     *
     * @param Request $request A Request instance
     *
     * @return string
     */
    protected function getScript($request)
    {
        $serialized_request = str_replace("'", "\\'", serialize($request));
        $autoload = str_replace("'", "\\", $this->autoloadPath);

        return <<<EOF
<?php
require '$autoload';
\$request = unserialize('$serialized_request');

define('DRUPAL_ROOT', getcwd());
include_once DRUPAL_ROOT . '/includes/bootstrap.inc';

\$app = new \Bangpound\Drupal\DrupalController();
\$response = \$app->contentAction(\$request);
echo \$response;
EOF;
    }
}
