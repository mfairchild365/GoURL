<?php
use UNL\Templates\Templates;
use Endroid\QrCode\QrCode;

require_once __DIR__ . '/../config.inc.php';

$lilurl = new lilURL(MYSQL_HOST, MYSQL_USER, MYSQL_PASS, MYSQL_DB);
$lilurl->setAllowedProtocols($allowed_protocols);
$lilurl->setAllowedDomains($allowed_domains);

session_name('gourl');
$route = '';
$pathInfo = $lilurl->getRequestPath();
phpCAS::client(CAS_VERSION_2_0, 'login.unl.edu', 443, '/cas');
phpCAS::setCasServerCACert(CAS_CA_FILE);
phpCAS::handleLogoutRequests();

// do predispatch actions

if (isset($_GET['login']) || 'a/login' === $pathInfo) {
    phpCAS::forceAuthentication();
    header('Location: ' . $lilurl->getBaseUrl('a/links'));
    exit;
}

if (isset($_GET['logout']) || 'a/logout' === $pathInfo) {
    phpCAS::logout();
    header('Location: ' . $lilurl->getBaseUrl());
    exit;
}

if (!phpCAS::isAuthenticated() && isset($_COOKIE['unl_sso'])) {
    phpCAS::checkAuthentication();
}

if (isset($_GET['manage']) || in_array($pathInfo, array('a/', 'a/links'))) {
    $route = 'manage';

    if (!phpCAS::isAuthenticated()) {
        header('Location: ' . $lilurl->getBaseUrl('a/login'));
        exit;
    }
}

// route

if ('api/' === $pathInfo) {
    $route = 'api';
} elseif (preg_match('#^([^/]+)\.qr$#', $pathInfo, $matches)) {
    $route = 'qr';
    $id = $matches[1];
}


if (!$route && $pathInfo) {
    $route = 'redirect';
}

// dispatch

if (!$route || 'api' === $route) {
    if (isset($_GET['url']) && $_GET['url'] === 'referer' && isset($_SERVER['HTTP_REFERER'])) {
        $_POST['theURL'] = urldecode($_SERVER['HTTP_REFERER']);
    }

    if (isset($_POST['theURL'])) {
        $user = $alias = null;

        if (phpCAS::isAuthenticated()) {
            $user = phpCAS::getUser();

            if (!empty($_POST['theAlias'])) {
                $alias = $_POST['theAlias'];
            }
        }

        try {
            $url = $lilurl->handlePOST($alias, $user);
            $_SESSION['gourlFlashBag'] = array(
                'msg' => '<p class="title">You have a Go URL!</p><input type="text" onclick="this.select(); return false;" value="'.$url.'" />',
                'type' => 'success',
                'url' => $url,
            );
        } catch (Exception $e) {
            switch ($e->getCode()) {
                case lilurl::ERR_INVALID_PROTOCOL:
                    $_SESSION['gourlFlashBag'] = array(
                        'msg' => '<p class="title">Whoops, Something Broke</p><p>Your URL must begin with <code>http://</code>, <code>https://</code>.</p>',
                    );
                    break;
                case lilurl::ERR_INVALID_DOMAIN:
                    $_SESSION['gourlFlashBag'] = array(
                        'msg' => '<p class="title">Whoops, Something Broke</p><p>You must sign in to create a URL for this domain: '.parse_url($_POST['theURL'], PHP_URL_HOST).'</p>',
                    );
                    break;
                case lilurl::ERR_INVALID_ALIAS:
                    $_SESSION['gourlFlashBag'] = array(
                        'msg' => '<p class="title">Whoops, Something Broke</p><p>The custom Alias you provided should only contain letters, numbers, underscores (_), and dashes (-).</p>',
                    );
                    break;
                default:
                    $_SESSION['gourlFlashBag'] = array(
                        'msg' => '<p class="title">Whoops, Something Broke</p><p>There was an error submitting your url. Check your steps.</p>',
                    );
            }

            $_SESSION['gourlFlashBag']['type'] = 'error';
        }

        if ('api' === $route) {
            unset($_SESSION['gourlFlashBag']);

            if (!empty($url)) {
                echo $url;
                exit;
            }

            header('HTTP/1.1 404 Not Found');
            echo 'There was an error. ';
            exit;
        }

        header('Location: ' . $lilurl->getBaseUrl(), true, 303);
        exit;
    } elseif ('api' === $route) {
        header('HTTP/1.1 404 Not Found');
        echo 'You need a URL!';
        exit;
    }
} elseif ('redirect' === $route) {
    $id = $pathInfo;

    if (!$lilurl->handleRedirect($id)) {
        header('HTTP/1.1 404 Not Found');
        include __DIR__ . '/templates/404.php';
        exit;
    }
} elseif ('manage' === $route) {
    if (isset($_POST, $_POST['urlID'])) {
        $lilurl->deleteURL($_POST['urlID'], phpCAS::getUser());
        $_SESSION['gourlFlashBag'] = array(
            'msg' => '<p class="title">Delete Successful</p><p>Your Go URL has been deleted</p>',
            'type' => 'success',
        );
        header('Location: ' . $lilurl->getBaseUrl('a/links'));
        exit;
    }
} elseif ('qr' === $route) {
    if (!$lilurl->getURL($id)) {
        header('HTTP/1.1 404 Not Found');
        exit;
    }

    $shortURL = $lilurl->getShortURL($id);
    $pngPrefix = __DIR__ . '/../data/qr/';
    $qrCache = $pngPrefix . 'cache/' . sha1($shortURL) . '.png';

    if (!file_exists($qrCache)) {
        $qrCode = new QrCode();
        $qrCode->setText($shortURL)
            ->setSize(1080)
            ->setPadding(36)
            ->save($qrCache);
    }

    $out = imagecreatefrompng($qrCache);
    $n = imagecreatefrompng($pngPrefix . 'unl_qr_235.png');

    imagecopy($out, $n, 422, 428, 0, 0, 235, 225);
    imagedestroy($n);
    header('Content-Type: image/png');
    imagepng($out);
    imagedestroy($out);
    exit;
}

// no actions to be done, time to render a UNL page

$error = false;
$msg = '';

if (isset($_SESSION['gourlFlashBag'])) {
    $msg = $_SESSION['gourlFlashBag']['msg'];

    if ('error' === $_SESSION['gourlFlashBag']['type']) {
        $error = true;
    }

    if (isset($_SESSION['gourlFlashBag']['url'])) {
        $url = $_SESSION['gourlFlashBag']['url'];
    }

    unset($_SESSION['gourlFlashBag']);
}

$page = Templates::factory('Local', Templates::VERSION_4_1);

if (file_exists(__DIR__ . '/wdn/templates_4.1')) {
    $page->setLocalIncludePath(__DIR__);
}

$page->setParam('class', 'terminal');
$page->affiliation = '';
$page->titlegraphic = "Go URL";
$page->pagetitle = '';
$page->doctitle = '<title>Go URL, a short URL service | University of Nebraska-Lincoln</title>';
$page->addStyleDeclaration(<<<EOD
.go-urls .actions > * {
    margin: .25em;
}
.wdn_notice .message input { color: #333; width: 100% }
EOD
);
$page->addHeadLink($lilurl->getBaseUrl(), 'home');
$page->addScriptDeclaration(sprintf(<<<EOD
require(['wdn'], function(WDN) {
    WDN.setPluginParam('idm', 'login', '%s');
    WDN.setPluginParam('idm', 'logout', '%s');
});
EOD
, $lilurl->getBaseUrl('a/login'), $lilurl->getBaseUrl('a/logout')));

ob_start();
include __DIR__ . '/templates/flashBag.php';

if ('manage' === $route) {
    // Show the url management screen
    include __DIR__ . '/templates/manage.php';
} else {
    // Show the submission interface
    include __DIR__ . '/templates/index.php';
}
$page->maincontentarea = ob_get_clean();

ob_start();
include __DIR__ . '/templates/static/local-footer.php';
$page->contactinfo = ob_get_clean();

echo $page;
