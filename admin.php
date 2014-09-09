<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Filesystem\File;
use Grav\Common\Grav;
use Grav\Common\Uri;

class AdminPlugin extends Plugin
{
    /**
     * @var bool
     */
    protected $active = false;

    /**
     * @var string
     */
    protected $template;

    /**
     * @var  string
     */
    protected $theme;

    /**
     * @var string
     */
    protected $route;

    /**
     * @var Uri
     */
    protected $uri;

    /**
     * @var Admin
     */
    protected $admin;

    protected $popularity;

    /**
     * @return array
     */
    public static function getSubscribedEvents() {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 1000],
            'onShutdown' => ['onShutdown', 1000]
        ];
    }

    /**
     * Initialize administration plugin if admin path matches.
     *
     * Disables system cache.
     */
    public function onPluginsInitialized()
    {

        // Check for Pro version and disable this plugin if found
        // if (file_exists(PLUGINS_DIR . 'admin_pro/admin_pro.php')) {
        //     $this->enabled = false;
        //     return;
        // }

        // echo "<h1>Admin Free</h1>";
        //
        require_once PLUGINS_DIR . 'admin/classes/popularity.php';
        $this->popularity = new Popularity();

        $this->initializeAdmin();
    }

    /**
     * Sets longer path to the home page allowing us to have list of pages when we enter to pages section.
     */
    public function onPagesInitialized()
    {
        // Set original route for the home page.
        $home = '/' . trim($this->config->get('system.home.alias'), '/');

        /** @var Pages $pages */
        $pages = $this->grav['pages'];

        $this->grav['admin']->routes = $pages->routes();

        $pages->dispatch('/', true)->route($home);
    }

    /**
     * Main administration controller.
     */
    public function onPageInitialized()
    {
        // Set page if user hasn't been authorised.
        if (!$this->admin->authorise()) {
            $this->template = $this->admin->user ? 'denied' : 'login';
        }

        // Make local copy of POST.
        $post = !empty($_POST) ? $_POST : array();

        // Handle tasks.
        $task = !empty($post['task']) ? $post['task'] : $this->uri->param('task');
        if ($task) {
            require_once __DIR__ . '/classes/controller.php';
            $controller = new AdminController($this->grav, $this->template, $task, $this->route, $post);
            $success = $controller->execute();
            $controller->redirect();
        } elseif ($this->template == 'logs' && $this->route) {
            // Display RAW error message.
            echo $this->admin->logEntry();
            exit();
        }

        /** @var Grav $grav */
        $grav = $this->grav;

        // Finally create admin page.
        $page = new Page;
        $page->init(new \SplFileInfo(__DIR__ . "/pages/admin/{$this->template}.md"));
        $page->slug(basename($this->template));

        unset($grav['page']);
        $grav['page'] = $page;
    }

    /**
     * Add twig paths to plugin templates.
     */
    public function onTwigTemplatePaths()
    {
        $this->theme = $this->config->get('plugins.admin.theme', 'grav');
        $this->grav['twig']->twig_paths = array(__DIR__ . '/themes/'.$this->theme.'/templates');
    }

    /**
     * Set all twig variables for generating output.
     */
    public function onTwigSiteVariables()
    {
        // TODO: use real plugin name instead
        $theme_url = $this->config->get('system.base_url_relative') . '/user/plugins/admin/themes/'.$this->theme;
        $twig = $this->grav['twig'];

        $twig->template = $this->template . '.html.twig';
        $twig->twig_vars['location'] = $this->template;
        $twig->twig_vars['base_url_relative'] .=
            ($twig->twig_vars['base_url_relative'] != '/' ? '/' : '') . trim($this->config->get('plugins.admin.route'), '/');
        $twig->twig_vars['theme_url'] = $theme_url;
        $twig->twig_vars['base_url'] = $twig->twig_vars['base_url_relative'];
        $twig->twig_vars['admin'] = $this->admin;

        // fake grav update
        $twig->twig_vars['grav_update'] = array('current'=>'0.9.1', 'available'=>'0.9.2');

        switch ($this->template) {
            case 'dashboard':
                $twig->twig_vars['popularity'] = $this->popularity;
                break;
            case 'pages':
                $twig->twig_vars['file'] = File\General::instance($this->admin->page(true)->filePath());
                break;
        }
    }

    public function onShutdown()
    {
        echo '<span style="color:red">system.debugger.shutdown.close_connection = false</span>';
        if ($this->config->get('plugins.admin.popularity.enabled')) {

            // Only track non-admin
            if (!$this->active) {
                $this->popularity->trackHit();
            }
        }
    }

    protected function initializeAdmin()
    {
        $this->route = $this->config->get('plugins.admin.route');

        if (!$this->route) {
            return;
        }

        $this->uri = $this->grav['uri'];
        $base = '/' . trim($this->route, '/');

        // Only activate admin if we're inside the admin path.
        if (substr($this->uri->route(), 0, strlen($base)) == $base) {
            $this->active = true;
            $this->enable([
                'onPagesInitialized' => ['onPagesInitialized', 1000],
                'onPageInitialized' => ['onPageInitialized', 1000],
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 1000],
                'onTwigSiteVariables' => ['onTwigSiteVariables', 1000]
            ]);

            // Disable system caching.
            $this->config->set('system.cache.enabled', false);

            // Decide admin template and route.
            $path = trim(substr($this->uri->route(), strlen($base)), '/');
            $this->template = 'dashboard';

            if ($path) {
                $array = explode('/', $path, 2);
                $this->template = array_shift($array);
                $this->route = array_shift($array);

                // Set path for new page.
                if ($this->uri->param('new')) {
                    $this->route .= '/new';
                }
            }

            // Initialize admin class.
            require_once PLUGINS_DIR . 'admin/classes/admin.php';
            $this->admin = new Admin($this->grav, $base, $this->template, $this->route);



            // And store the class into DI container.
            $this->grav['admin'] = $this->admin;

        }
    }
}
