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

/**
* solve JSON_ERROR_UTF8 error in php json_encode
* esta funcionsita me corrije un error que habia al tratar de hacerle json encode aun array con tildes
* en algunos textos
* @param  array $mixed El erray que se decia corregir
* @return array        Regresa el mismo array pero corrigiendo errores en la codificacion
*/
function utf8ize($mixed) {
   if (is_array($mixed)) {
       foreach ($mixed as $key => $value) {
           $mixed[$key] = utf8ize($value);
       }
   } else if (is_string ($mixed)) {
       return utf8_encode($mixed);
   }
   return $mixed;
}

function updateProducts($logger, $bdName){

    $dbClient = new \GuzzleHttp\Client([
        'base_uri' => 'https://www.gatortyres.com:6984/',
        'headers' => [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json'
        ],
        'auth' => ['admin', 'admin']
    ]);

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
    $file_data = "codigo;descripcion;precio1;cantInventario;_delete" . PHP_EOL;
    $file_data .= file_get_contents('onlyModifiedProds.csv');
    file_put_contents('onlyModifiedProds.csv', $file_data);

    /*
    * Elimino los archivos viejos que hayan en la carpeta de comparacion
    */
    $command = "rm -r old-files/oldProds.csv";
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
                "imagen"      => "https://www.igbcolombia.com/img_app/{$record['codigo']}.jpg",
                "categoria"   => null,
                "marcas"      => $marcaUnd[0],
                "unidad"      => $marcaUnd[1],
                "existencias" => intval($record['cantInventario']),
                "precio"      => intval($record['precio1']),
                "_deleted"    => ( $record['_delete'] == 'true' )
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
            $prodsToMod = $dbClient->post("{$bdName}/_all_docs", [
                //'query' => ['include_docs' => 'true'],
                'json' => [
                    'keys' => array_column($productos, '_id')
                ]
            ]);
            /**
             * saco los productos de la respuesta de guzzle desde la bd
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
                 * con los datos a modificar y al tributo _rev.
                 * si el producto local no esta en couchdb, entonces couchdb
                 * me devuelve un error, lo capturo y simplemente mando el producto
                 * sin rev, de esta manera se crea automaticamente
                 */
                foreach ($prodsToMod->rows as $k => $prodCouchdb) {
                    if($prodCouchdb->key == $prod["_id"]){
                        if(isset($prodCouchdb->error) && $prodCouchdb->error == "not_found"){
                            return $prod;
                        }else{

                            /**
                             * Esta revision la hago, por que si el producto/documento fue eliminado
                             * anteriormente y entonces se desea volver a crear, cuando yo haga una
                             * consulta a couchdb preguntando por el producto asi este eliminado
                             * couchdb me lo va a traer con el valor de "deleted" igual a verdero
                             * si yo le asigno la revision que ya tenia, digamos la 10, entonces el me
                             * va a tirar un error de que updateConflict, lo que hay que hacer es mandar
                             * el producto sin la revision
                             */

                            if( isset($prodCouchdb->value->deleted) && $prodCouchdb->value->deleted ){
                                return $prod;
                            }
                            $prod['updated_at'] = round(microtime(true) * 1000);
                            $prod['origen'] = 'sap';
                            $prod['_rev'] = $prodCouchdb->value->rev;
                            return $prod;
                        }
                    }
                }

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

            $resImportCouch = $dbClient->post("{$bdName}/_bulk_docs", [
                'json' => [
                    'docs' => utf8ize($productosRev)
                ]
            ]);

            $resImportCouch = json_decode( $resImportCouch->getBody()->getContents(), true );
            $logger->warn('Informacion del volcado de datos: '. json_encode($resImportCouch));
            var_dump( $resImportCouch );
        }


    } catch (Throwable $e) {
        $logger->error($e->getMessage()." ".$e->getLine());
    }

}

function updateClients($dbClient, $logger){
    $clientes = [];
    echo "se modificaron los clientes perro hpta".PHP_EOL;

    $command = "git diff --no-index --color=always old-files/oldClients.csv observados/client.txt |perl -wlne 'print $1 if /^\e\[32m\+\e\[m\e\[32m(.*)\e\[m$/' > onlyModifiedClients.csv ";
    $output = shell_exec($command);


    $file_data = "_id;asesor;ciudad;direccion;nombre_cliente;transportadora;asesor_nombre;telefono" . PHP_EOL;
    $file_data .= file_get_contents('onlyModifiedClients.csv');
    file_put_contents('onlyModifiedClients.csv', $file_data);


    $command = "rm -r old-files/oldClients.csv";
    shell_exec($command);


    $command = "cp observados/client.txt old-files/oldClients.csv";
    $output = shell_exec($command);

    try {


        $csv = Reader::createFromPath(__DIR__.'/onlyModifiedClients.csv', 'r');
        $csv->setDelimiter(';');
        $csv->setHeaderOffset(0); //set the CSV header offset
        $records = $csv->getRecords();

        foreach ($records as $offset => $record) {
            $clientes[] = $record;
        }

        if(count($clientes)>0){

            $clientesToMod = $dbClient->post('clientes/_all_docs', [
                'json' => [
                    'keys' => array_column($clientes, '_id')
                ]
            ]);

            $clientesToMod = json_decode( $clientesToMod->getBody()->getContents() );


            $clientesRev = array_map(function($cliente) use ($clientesToMod){

                foreach ($clientesToMod->rows as $k => $clienteCouchdb) {
                    if($clienteCouchdb->key == $cliente["_id"]){

                        if(isset($clienteCouchdb->error) && $clienteCouchdb->error == "not_found"){
                            return $cliente;
                        }else{

                            if( isset($clienteCouchdb->value->deleted) && $clienteCouchdb->value->deleted ){
                                return $cliente;
                            }

                            $cliente['_rev'] = $clienteCouchdb->value->rev;
                            return $cliente;
                        }
                    }
                }

            }, $clientes);

            $resImportCouch = $dbClient->post('clientes/_bulk_docs', [
                'json' => [
                    'docs' => utf8ize($clientesRev)
                ]
            ]);

            $resImportCouch = json_decode( $resImportCouch->getBody()->getContents(), true );
            $logger->warn('Informacion del volcado de datos: '. json_encode($resImportCouch));
            var_dump( $resImportCouch );
        }


    } catch (Throwable $e) {
        $logger->error($e->getMessage()." ".$e->getLine());
    }
}

function updateCartera($logger){
    $facturas = [];
    $dbClient = new \GuzzleHttp\Client([
        'base_uri' => 'https://www.gatortyres.com:6984/',
        'headers' => [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json'
        ],
        'auth' => ['admin', 'admin']
    ]);

    echo "se modifico la cartera perro hpta".PHP_EOL;

    $command = "git diff --no-index --color=always old-files/oldCartera.csv observados/invoice.txt |perl -wlne 'print $1 if /^\e\[32m\+\e\[m\e\[32m(.*)\e\[m$/' > onlyModifiedCartera.csv ";
    $output = shell_exec($command);


    $file_data = "factura;valorFac;ValorTotalFac;codCliente;codVendedor;fecha;fechaVencimiento" . PHP_EOL;
    $file_data .= file_get_contents('onlyModifiedCartera.csv');
    file_put_contents('onlyModifiedCartera.csv', $file_data);


    $command = "rm -r old-files/oldCartera.csv";
    shell_exec($command);


    $command = "cp observados/invoice.txt old-files/oldCartera.csv";
    $output = shell_exec($command);

    try {


        $csv = Reader::createFromPath(__DIR__.'/onlyModifiedCartera.csv', 'r');
        $csv->setDelimiter(';');
        $csv->setHeaderOffset(0); //set the CSV header offset
        $records = $csv->getRecords();

        foreach ($records as $offset => $record) {
            $facturas[] = [
                "_id"               => $record['factura'],
                "valor"             => $record['valorFac'],
                "valor_total"       => $record['ValorTotalFac'],
                "cod_cliente"       => $record['codCliente'],
                "cod_vendedor"      => $record['codVendedor'],
                "fecha_emision"     => $record['fecha'],
                "fecha_vencimiento" => $record['fechaVencimiento'],
            ];
        }

        if(count($facturas) > 0){

            $facturasToMod = $dbClient->post('cartera/_all_docs', [
                'json' => [
                    'keys' => array_column($facturas, '_id')
                ]
            ]);

            $facturasToMod = json_decode( $facturasToMod->getBody()->getContents() );

            $facturasRev = array_map(function($factura) use ($facturasToMod){

                foreach ($facturasToMod->rows as $k => $facturaCouchdb) {
                    if($facturaCouchdb->key == $factura["_id"]){

                        if(isset($facturaCouchdb->error) && $facturaCouchdb->error == "not_found"){
                            return $factura;
                        }else{

                            if( isset($facturaCouchdb->value->deleted) && $facturaCouchdb->value->deleted ){
                                return $factura;
                            }

                            $factura['_rev'] = $facturaCouchdb->value->rev;
                            return $factura;
                        }
                    }
                }

            }, $facturas);

            $resImportCouch = $dbClient->post('cartera/_bulk_docs', [
                'json' => [
                    'docs' => utf8ize($facturasRev)
                ]
            ]);

            $resImportCouch = json_decode( $resImportCouch->getBody()->getContents(), true );
            $logger->warn('Informacion del volcado de datos: '. json_encode($resImportCouch));
            var_dump( $resImportCouch );
        }


    } catch (Throwable $e) {
        $logger->error($e->getMessage()." ".$e->getLine());
    }
}


$loop = React\EventLoop\Factory::create();
$inotify = new MKraemer\ReactInotify\Inotify($loop);

$inotify->add('observados/', IN_CLOSE_WRITE | IN_CREATE | IN_DELETE);
//$inotify->add('/var/log/', IN_CLOSE_WRITE | IN_CREATE | IN_DELETE);

$inotify->on(IN_CLOSE_WRITE, function ($path) use($logger, $dbClient) {
    $logger->info('***********************************************************************************');
    $logger->info('File closed after writing: '.$path.PHP_EOL);

    if($path == "observados/product.txt"){
        updateProducts($logger, 'producto');
        updateProducts($logger, 'producto_1');
    }

    if($path == "observados/client.txt"){
        updateClients($dbClient, $logger);
    }

    if($path == "observados/invoice.txt"){
        updateCartera($logger);
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
