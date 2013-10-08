DrupalKernel and DrupalController
=================================

These two experimental classes wrap Drupal 7 in a Symfony HTTP kernel.

The kernel serializes the Symfony request object in customized front
controller for Drupal. The front controller runs in an isolated PHP
process. It requires the Composer autoloader so that it can access
Symfony classes.

Before Drupal is bootstrapped, the injected request is unserialized
and those values replace the globals in the Drupal process. The
Drupal process is operated by something resembling a controller
(DrupalController). The Drupal request is handled and the response
is echoed as PHP CGI output.

The kernel which opened the process captures the output, parses the
headers and returns a Symfony response object.

Inspiration, code and fair warning are taken from Igor Wiedler's
[CgiHttpKernel][1] and [The HttpKernelInterface is a lie][2].

This is not a way to run Drupal.

[1]: https://github.com/igorw/CgiHttpKernel
[2]: https://speakerdeck.com/igorw/the-httpkernelinterface-is-a-lie-london

Usage
-----

Just add `bangpound/drupal-kernel` to your `composer.json` file.

````json
{
    "require": "bangpound/drupal-kernel"
}
````

Then create a front controller named index.php with this code in it:

````php
<?php

$loader = require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR .'vendor'. DIRECTORY_SEPARATOR .'autoload.php';

$app = new \Bangpound\Drupal\DrupalKernel($loader, __DIR__ .'/../vendor/drupal/drupal');

$request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
$response = $app->handle($request);
$response->send();
if ($app instanceof \Symfony\Component\HttpKernel\TerminableInterface) {
    $app->terminate($request, $response);
}
````

If you need to make changes to your Request object so that it provides
all the globals Drupal needs, change the request object before handing it
to the `handle()` method.
