<?php

namespace Bangpound\Drupal;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Process\PhpProcess;

class Kernel extends HttpKernel
{
    private $rootDir;
    private $phpCgiBin;

    /**
     * Constructor
     *
     * @param EventDispatcherInterface    $dispatcher An EventDispatcherInterface instance
     * @param ControllerResolverInterface $resolver   A ControllerResolverInterface instance
     *
     * @api
     */
    public function __construct(EventDispatcherInterface $dispatcher, ControllerResolverInterface $resolver)
    {
        $this->dispatcher = $dispatcher;
        $this->resolver = $resolver;
    }

    public function setRootDir($rootDir)
    {
        $this->rootDir = realpath($rootDir);
    }

    public function setPhpCgiBin($phpCgiBin)
    {
        $this->phpCgiBin = $phpCgiBin ?: 'php-cgi';
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
    public function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
    {
        $drupal = $request->duplicate();
        if (count($drupal->files)) {
            $boundary = $this->getMimeBoundary();
            $drupal->headers->set('Content-Type', 'multipart/form-data; boundary='.$boundary);
        }

        $drupal->query->set('q', ltrim($drupal->getPathInfo(), '/'));
        $drupal->server->set('REQUEST_URI', $drupal->getPathInfo());
        $drupal->server->set('HTTP_HOST', 'localhost');
        $drupal->server->set('SCRIPT_NAME', '/index.php');
        $drupal->server->set('SERVER_SOFTWARE', 'Symfony');

        $processOutput = $this->doRequestInProcess($drupal);

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
     * Makes a request in another process.
     *
     * @param object $request An origin request instance
     *
     * @return object An origin response instance
     *
     * @throws \RuntimeException When processing returns exit code
     * @see \Symfony\Component\BrowserKit\Client
     * @see \Symfony\Component\HttpKernel\Client
     */
    protected function doRequestInProcess($request)
    {
        // We set the TMPDIR (for Macs) and TEMP (for Windows), because on these platforms the temp directory changes based on the user.
        $process = new PhpProcess($this->getScript($request), $this->rootDir, array('TMPDIR' => sys_get_temp_dir(), 'TEMP' => sys_get_temp_dir()));
        $process->setPhpBinary('php-cgi');
        $process->run();

        // I think the second hcheck validates that the response is serialized, but that's not what we want here.
        //if (!$process->isSuccessful() || !preg_match('/^O\:\d+\:/', $process->getOutput())) {
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('OUTPUT: %s ERROR OUTPUT: %s', $process->getOutput(), $process->getErrorOutput()));
        }

        return $process->getOutput();
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
        $request = str_replace("'", "\\'", serialize($request));

        $r = new \ReflectionClass('\\Symfony\\Component\\ClassLoader\\ClassLoader');
        $requirePath = str_replace("'", "\\'", $r->getFileName());
        $symfonyPath = str_replace("'", "\\'", realpath(dirname($r->getFileName()) .'/../../../'));

        $code = <<<EOF
<?php

require_once '$requirePath';

\$loader = new Symfony\Component\ClassLoader\ClassLoader();
\$loader->addPrefix('Symfony', '$symfonyPath');
\$loader->register();

\$request = unserialize('$request');
\$request->overrideGlobals();
EOF;

        return $code.$this->getBootstrapScript().$this->getHandleScript();
    }

    protected function getBootstrapScript()
    {
        return <<<'EOF'
define('DRUPAL_ROOT', getcwd());

require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
EOF;
    }

    protected function getHandleScript()
    {
        return <<<'EOF'
menu_execute_active_handler(null, true);
EOF;
    }
}
