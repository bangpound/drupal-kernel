<?php
/**
 * Created by PhpStorm.
 * User: bjd
 * Date: 10/8/13
 * Time: 9:18 AM
 */

namespace Bangpound\Drupal;

use Symfony\Component\HttpFoundation\Request;

class DrupalController
{
    public function contentAction(Request $request)
    {
        $request->overrideGlobals();
        drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
        $response = menu_execute_active_handler(null, false);

        return render($response);
    }
}
