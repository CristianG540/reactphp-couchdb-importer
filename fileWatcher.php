#!/usr/bin/php
<?php
require __DIR__.'/vendor/autoload.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

use League\Csv\Exception;
use League\Csv\Reader;
use League\Csv\Statement;

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

$inotify->on(IN_CLOSE_WRITE, function ($path) use($logger, $dbClient) {
    $logger->info('***********************************************************************************');
    $logger->info('File closed after writing: '.$path.PHP_EOL);

    if($path == "observados/product.txt"){
        $productos = [];
        echo "se modificaron los productos perro hpta".PHP_EOL;
        /*
        * Con este comando uso git diff para comparar los archivos csv y sacar solo los
        * productos que se modificaron
        */
        $command = "git diff --no-index --color=always old-files/oldProds.csv observados/product.txt |perl -wlne 'print $1 if /^\e\[32m\+\e\[m\e\[32m(.*)\e\[m$/' > onlyModifiedProds.csv ";
        $output = shell_exec($command);

        /*
        * Como el resultado del comando anterior no me trae el encabezado del csv entonces
        * lo agrego con las sgtes lineas
        * mas info aqui * https://stackoverflow.com/questions/1760525/need-to-write-at-beginning-of-file-with-php
        */
        $file_data = "codigo;descripcion;precio1;cantInventario" . PHP_EOL;
        $file_data .= file_get_contents('onlyModifiedProds.csv');
        file_put_contents('onlyModifiedProds.csv', $file_data);

        /*
        * Elimino los archivos viejos que hayan en la carpeta de comparacion
        */
        $command = "rm -r old-files/*";
        shell_exec($command);

        /*
        * Copio el archivo csv con los productos nuevos a la carpeta de comparacion para
        * compararlos la proxima vez que se ejecute el cron
        */
        $command = "cp observados/product.txt old-files/oldProds.csv";
        $output = shell_exec($command);

        try {

            /**
             * Leo el archivo csv que contiene solo los productos por modificar
             * mediante la libreria csv de phpleague
             */
            $csv = Reader::createFromPath(__DIR__.'/onlyModifiedProds.csv', 'r');
            $csv->setDelimiter(';');
            $csv->setHeaderOffset(0); //set the CSV header offset
            $records = $csv->getRecords();

            /**
             * recorro los productos que tenia el archivo
             */
            foreach ($records as $offset => $record) {
                /**
                 * De sap la descripcion trae el nombre la aplicacion la marca
                 * y la unidad entones aqui extraigo dichos datos
                 */
                $tituloApli = explode(".", $record['descripcion']);
                $aplMarca = explode("/", $tituloApli[1]);
                $marcaUnd = explode("_", $aplMarca[1]);

                /**
                 * mapeo los productos con el formato adecuado para la bd en
                 * couchdb
                 */
                $productos[] = [
                    "_id"         => $record['codigo'],
                    "titulo"      => $tituloApli[0],
                    "aplicacion"  => $aplMarca[0],
                    "imagen"      => "https://www.igbcolombia.com/sites/default/files/{$record['codigo']}.jpg",
                    "categoria"   => null,
                    "marcas"      => $marcaUnd[0],
                    "unidad"      => $marcaUnd[1],
                    "existencias" => intval($record['cantInventario']),
                    "precio"      => intval($record['precio1'])
                ];
            }

            if(count($productos)>0){
                /**
                 * Para poder modificar los datos en couchdb se debe mandar el
                 * atributo "_rev" del dato que se quiera modificar, lo que yo hago
                 * aqui es consultar en couchdb para que me traiga los "_rev" de cada
                 * producto, suena un poco redundante pero es la forma en couchdb funciona
                 * hago entonces una consulta a couch usando el api de all_docs, le paso
                 * los ids de los productos que necesito y el medevuleve el "_rev"
                 */
                $prodsToMod = $dbClient->post('productos/_all_docs', [
                    //'query' => ['include_docs' => 'true'],
                    'json' => [
                        'keys' => array_column($productos, '_id')
                    ]
                ]);
                /**
                 * saco los productos de la respuesta de guzzle desde la b
                 */
                $prodsToMod = json_decode( $prodsToMod->getBody()->getContents() );

                /**
                 * una vez tenga los productos desde couchdb con su atributo "_rev"
                 * respectivo, recorro los productos que tengo en local, con los datos
                 * correctos a modificar para insertarle el atributo "_rev"
                 */
                $productosRev = array_map(function($prod) use ($prodsToMod){

                    /**
                     * por cada producto local que tengo que modificar hago una
                     * busqueda en los productos que traje de couch para insertarle
                     * el atributo "_rev" correspondiente y asi devolver el producto
                     * con los datos a modificar y al tributo _rev
                     */
                    $prodRev = array_filter($prodsToMod->rows, function($v) use ($prod){
                       return $v->id == $prod["_id"];
                    })[0];
                    $prod['_rev'] = $prodRev->value->rev;
                    return $prod;

                }, $productos);

                /**
                 * Al final el array con los productos qyedaria en un formato asi como este
                 *
                 * [
                 *   "_id"         => "PE0900",
                 *   "titulo"      => "EMPAQUE CILINDRO GRAFITADO",
                 *   "aplicacion"  => "c90,cd 100,eco 100",
                 *   "imagen"      => "https://www.igbcolombia.com/sites/default/files/PE0900_0.jpg",
                 *   "categoria"   => null,
                 *   "marcas"      => "APPLE",
                 *   "unidad"      => "UND",
                 *   "existencias" => 129,
                 *   "precio"      => 82300,
                 *   "_rev"        => "104-53267aba466835375e021cb82d3a5e98"
                 *  ]
                 *
                 */

                $resImportCouch = $dbClient->post('productos/_bulk_docs', [
                    'json' => [
                        'docs' => $productosRev
                    ]
                ]);

                $resImportCouch = json_decode( $resImportCouch->getBody()->getContents(), true );
                $logger->warn('Informacion del bolcado de datos: '. json_encode($resImportCouch));
                var_dump( $resImportCouch );
            }


        } catch (Exception $e) {
            $logger->error($e->getMessage());
        }

    }

    $logger->info('***********************************************************************************');
});

$inotify->on(IN_CREATE, function ($path) use($logger) {
    $logger->info('File created: '.$path.PHP_EOL);
});

$inotify->on(IN_DELETE, function ($path) use($logger) {
    $logger->info('File deleted: '.$path.PHP_EOL);
});

$loop->run();
