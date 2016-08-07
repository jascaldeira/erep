<?php

namespace App\Controllers;

use App\System\App;
use App\System\AppException;
use App\System\Controller;

class Home extends Controller
{
    /**
     * Show home page
     * @return mixed
     */
    public function showHomepage ()
    {
        if (!$this->isLogged) {
            App::redirect($this->app->getContainer()->get('router')->pathFor('home'));
            exit;
        }

        $me = App::session()->getUser();
        $me["money"] = App::session()->getMoney();

        return $this->render('home.html.twig',[
            "my" => $me
        ]);
    }
}