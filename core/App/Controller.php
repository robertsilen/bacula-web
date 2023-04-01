<?php

declare(strict_types=1);

/**
 * Copyright (C) 2010-2023 Davide Franco
 *
 * This file is part of Bacula-Web.
 *
 * Bacula-Web is free software: you can redistribute it and/or modify it under the terms of the GNU
 * General Public License as published by the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * Bacula-Web is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with Bacula-Web. If not, see
 * <https://www.gnu.org/licenses/>.
 */

namespace Core\App;

use App\Libs\FileConfig;
use Core\Helpers\Sanitizer;
use Core\Exception\ConfigFileException;
use Core\i18n\CTranslation;
use SmartyException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class Controller
{
    /**
     * @var Request
     */
    protected Request $request;

    /**
     * @var View
     */
    protected View $view;

    /**
     * @var string
     */
    protected string $userAlert = '';

    /**
     * @var string
     */
    protected string $userAlertType = '';

    /**
     * @var Session|callable|SessionInterface
     */
    protected Session $session;

    /**
     * @param Request $request
     * @param View $view
     * @throws ConfigFileException
     */
    public function __construct(Request $request, View $view)
    {
        $this->request = $request;
        $this->view = $view;
        $this->session = new Session();

        /**
         * Initialize smarty gettext function
         */
        $language = FileConfig::get_Value('language');
        if ($language == null) {
            throw new ConfigFileException('<b>Config error:</b> $config[\'language\'] not set correctly, please check configuration file');
        }

        /**
         * TODO: For separation of concerne sake, Locale setup should not be part of Controller constructor
         */
        $translate = new CTranslation($language);
        $translate->setLanguage();
        $this->setVar('language', $language);
    }

    /**
     * @param string $alert
     * @return void
     */
    public function setAlert(string $alert)
    {
        $this->userAlert = $alert;
    }

    /**
     * @param string $type
     * @return void
     */
    public function setAlertType(string $type)
    {
        $this->userAlertType = $type;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setVar(string $key, $value)
    {
        $this->view->getRenderer()->assign($key, $value);
    }

    /**
     * @param string $type
     * @param string $message
     * @return void
     */
    public function setFlash(string $type, string $message): void
    {
        $flash = $this->session->getFlashBag();
        $flash->add($type, $message);
    }

    /**
     * @param string $templateName
     * @return string
     * @throws SmartyException
     * @throws ConfigFileException
     */
    public function render(string $templateName): string
    {
        /**
         * Build breadcrumb navigation bar
         *
         * Whe the user is on the home page (Dashboard), the breadcrumb nav bar is not displayed
         */
        $routes = WebApplication::getRoutes();
        $route = $routes[$this->request->attributes->get('page', 'home')];
        $pagename = $route['name'];

        $breadcrumb = '<div class="container-fluid">
                         <div class="row">
                           <div class="col-xs-12">
                             <ol class="breadcrumb">';
        if ($pagename !== 'Dashboard') {
            $breadcrumb .= '<li> <a href="index.php" title="' . _("Back to Dashboard") . '"><i class="fa fa-home fa-fw"></i> Dashboard</a> </li>';
            $breadcrumb .= '<li class="active">' . $pagename . '</li>';
            $breadcrumb .= '</ol> </div> </div> </div>';
            $this->setVar('breadcrumb', $breadcrumb);
        } else {
            $this->setVar('breadcrumb', '');
        }

        /**
         * Show flash message to user
         * TODO: This needs to be moved somewhere, for separation of concern sake
         */
        $this->setVar('userAlert', $this->userAlert);
        $this->setVar('userAlertType', $this->userAlertType);

        $this->setVar('user_authenticated', $this->session->get('user_authenticated'));
        $this->setVar('username', $this->session->get('username'));
        $this->setVar('enable_users_auth', $this->session->get('enable_users_auth'));

        FileConfig::open(CONFIG_FILE);
        $catalog_current_id = 0;

        // Get catalog_id from http $_GET request
        if ($this->request->query->has('catalog_id')) {
            if (FileConfig::catalogExist($this->request->request->getInt('catalog_id'))) {
                $catalog_current_id = $this->request->query->getInt('catalog_id');
                $this->session->set('catalog_id', $catalog_current_id);
            } else {
                $this->session->set('catalog_id', 0);
                $catalog_current_id = 0;
                // TODO: It should redirect to home with catalog_id = 0 and display a flash message to the user
                throw new ConfigFileException('The catalog_id value provided does not correspond to a valid catalog, please verify the config.php file');
            }
        } elseif ($this->session->has('catalog_id')) {
            // Stick with previously selected catalog_id
            $catalog_current_id = $this->session->get('catalog_id');
        } else {
            $this->session->set('catalog_id', $catalog_current_id);
        }

        // Define catalog id and catalog label
        $this->setVar('catalog_current_id', $catalog_current_id);
        $this->setVar('catalog_label', FileConfig::get_Value('label', $catalog_current_id));
        $this->setVar('catalogs', FileConfig::get_Catalogs());

        // Set web app name and version
        $this->setVar('app_name', WebApplication::getName());
        $this->setVar('app_version', WebApplication::getVersion());

        // Set flash message
        $flash = $this->session->getFlashBag()->all();

        if (!empty($flash)) {
            $type = array_keys($flash);
            $this->setVar('userAlertType', $type[0]);
            $this->setVar('userAlert', $flash[$type[0]][0]);
        }

        return $this->view->getRenderer()->fetch($templateName);
    }

    /**
     * @param string $parameter
     * @param mixed $default
     * @return mixed|null
     */
    protected function getParameter(string $parameter, $default)
    {
        if ($this->request->getMethod() === 'GET') {
            if ($this->request->query->has($parameter)) {
                return Sanitizer::sanitize($this->request->query->get($parameter));
            }
        } elseif ($this->request->getMethod() === 'POST') {
            if ($this->request->request->has($parameter)) {
                return Sanitizer::sanitize($this->request->request->get($parameter));
            }
        }

        return $default;
    }
}