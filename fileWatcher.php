#!/usr/bin/php
<?php
require __DIR__.'/vendor/autoload.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

/**
 * Esta funcion se encarga de abrirme una conexion nueva cada vez que la cierro
 * con "ORM::set_db(null)"
 */
function getNewConnection() {
    ORM::configure([
        'connection_string' => 'mysql:host=localhost;dbname=prueba_mr_1',//'mysql:host=localhost;dbname=i3620810_ps2',
        'username' => 'webmaster_mr',//'i3620810_ps2',
        'password' => 'Webmaster2017#@'//'X^XnEwiJq~C8vbi1b*[16^*3'
    ]);
}

// create a log channel
$logger = new Logger('import');
$logger->pushHandler(new StreamHandler(__DIR__.'/logs/info.log', Logger::DEBUG));
$logger->info('Inicio Script');

/********************* GUZZLE ******************/
$dbClient = new \GuzzleHttp\Client([
    'base_uri' => 'http://45.77.74.23:5984/',
    'headers' => [
        'Accept'       => 'application/json',
        'Content-Type' => 'application/json'
    ],
    'auth' => ['admin', 'admin']
]);
/********************* END GUZZLE ******************/

$loop = React\EventLoop\Factory::create();
$inotify = new MKraemer\ReactInotify\Inotify($loop);

$inotify->add('observados/', IN_CLOSE_WRITE | IN_CREATE | IN_DELETE);
//$inotify->add('/var/log/', IN_CLOSE_WRITE | IN_CREATE | IN_DELETE);

$inotify->on(IN_CLOSE_WRITE, function ($path) use($logger) {
    $logger->info('***********************************************************************************');
    $logger->info('File closed after writing: '.$path.PHP_EOL);

    if($path == "observados/product.txt"){
        echo "se modificaron los productos perro hpta";
    }

    $logger->info('***********************************************************************************');
});

$inotify->on(IN_CREATE, function ($path) use($logger) {
    $logger->info('***********************************************************************************');
    $logger->info('File created: '.$path.PHP_EOL);
    $logger->info('***********************************************************************************');
});

$inotify->on(IN_DELETE, function ($path) use($logger) {
    $logger->info('File deleted: '.$path.PHP_EOL);
});

$loop->run();
